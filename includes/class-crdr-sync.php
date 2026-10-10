<?php
defined( 'ABSPATH' ) || exit;

/**
 * Builds the customer table from WooCommerce orders.
 *
 * The work is split into small steps so it never hits PHP time limits:
 *   start()  -> resets the table and counts orders
 *   step()   -> imports one page of orders (200) or, when all pages are done, scores everybody
 * The admin screen calls step() over AJAX until it is done; the nightly job chains steps with
 * Action Scheduler (bundled with WooCommerce), falling back to WP-Cron.
 */
class CRDR_Sync {

	const STATE_OPTION = 'crdr_sync_state';
	const PAGE_SIZE    = 200;
	const STEP_HOOK    = 'crdr_sync_step';
	const DAILY_HOOK   = 'crdr_daily';

	public static function init() {
		add_action( self::STEP_HOOK, array( __CLASS__, 'background_step' ) );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'start_background' ) );
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'daily', self::DAILY_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_clear_scheduled_hook( self::STEP_HOOK );
	}

	/* ---------------------------------------------------------------- state */

	public static function state() {
		$s = get_option( self::STATE_OPTION );
		return is_array( $s ) ? $s : array( 'phase' => 'idle' );
	}

	private static function save_state( array $s ) {
		update_option( self::STATE_OPTION, $s, false );
		return $s;
	}

	private static function order_args( $extra = array() ) {
		$statuses = array_map( function ( $s ) {
			return 'wc-' . $s;
		}, (array) CRDR_Settings::get( 'statuses' ) );
		return array_merge(
			array(
				'type'    => 'shop_order',
				'status'  => $statuses,
				'orderby' => 'date',
				'order'   => 'ASC',
			),
			$extra
		);
	}

	/* ------------------------------------------------------------- lifecycle */

	public static function start() {
		global $wpdb;
		foreach ( array( CRDR_DB::customers(), CRDR_DB::orders(), CRDR_DB::items() ) as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$res   = wc_get_orders( self::order_args( array( 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) ) );
		$total = is_object( $res ) ? (int) $res->total : 0;
		return self::save_state( array(
			'phase'     => $total > 0 ? 'orders' : 'finalize',
			'page'      => 1,
			'total'     => $total,
			'done'      => 0,
			'started'   => time(),
			'finished'  => 0,
			'customers' => 0,
		) );
	}

	/** Do one unit of work and return the new state. */
	public static function step() {
		$s = self::state();
		if ( empty( $s['phase'] ) || 'idle' === $s['phase'] || 'done' === $s['phase'] ) {
			return $s;
		}
		if ( 'orders' === $s['phase'] ) {
			$n          = self::import_page( (int) $s['page'] );
			$s['done'] += $n;
			$s['page']++;
			if ( $n < self::PAGE_SIZE ) {
				$s['phase'] = 'finalize';
			}
			return self::save_state( $s );
		}
		if ( 'finalize' === $s['phase'] ) {
			$s['customers'] = self::finalize();
			$s['phase']     = 'stats';
			return self::save_state( $s );
		}
		// stats
		CRDR_Analytics::build();
		$s['phase']    = 'done';
		$s['finished'] = time();
		return self::save_state( $s );
	}

	/* ------------------------------------------------------------ background */

	public static function start_background() {
		self::start();
		self::enqueue_next();
	}

	public static function background_step() {
		$s = self::step();
		if ( ! in_array( $s['phase'], array( 'done', 'idle' ), true ) ) {
			self::enqueue_next();
		}
	}

	private static function enqueue_next() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::STEP_HOOK, array(), 'customer-radar' );
		} else {
			wp_schedule_single_event( time() + 5, self::STEP_HOOK );
		}
	}

	/* ----------------------------------------------------------------- import */

	/**
	 * Import one page of orders into the aggregate table.
	 *
	 * @return int number of orders read from this page
	 */
	private static function import_page( $page ) {
		global $wpdb;
		$orders = wc_get_orders( self::order_args( array(
			'limit'  => self::PAGE_SIZE,
			'paged'  => $page,
			'return' => 'objects',
		) ) );
		$table = CRDR_DB::customers();
		$now   = time();

		$orders_table = CRDR_DB::orders();
		$items_table  = CRDR_DB::items();
		foreach ( $orders as $order ) {
			$row = self::extract( $order );

			$item_values = array();
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$pid = (int) $item->get_product_id();
				if ( $pid > 0 ) {
					$item_values[] = $wpdb->prepare( '(%d,%d,%d,%f)', $order->get_id(), $pid, max( 1, (int) $item->get_quantity() ), (float) $item->get_total() );
				}
			}
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$orders_table} (order_id,ckey,ts,net,items) VALUES (%d,%s,%d,%f,%d)", // phpcs:ignore WordPress.DB.PreparedSQL
				$order->get_id(), $row['ckey'], $row['ts'], $row['net'], count( $item_values )
			) );
			if ( $item_values ) {
				$wpdb->query( "INSERT INTO {$items_table} (order_id,product_id,qty,total) VALUES " . implode( ',', $item_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}

			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table} (ckey,user_id,name,phone,email,orders_count,total_spent,first_ts,last_ts,updated_ts)
				 VALUES (%s,%d,%s,%s,%s,1,%f,%d,%d,%d)
				 ON DUPLICATE KEY UPDATE
				   orders_count = orders_count + 1,
				   total_spent  = total_spent + VALUES(total_spent),
				   first_ts     = LEAST(first_ts, VALUES(first_ts)),
				   name  = IF(VALUES(last_ts) >= last_ts AND VALUES(name)  <> '', VALUES(name),  name),
				   phone = IF(VALUES(last_ts) >= last_ts AND VALUES(phone) <> '', VALUES(phone), phone),
				   email = IF(VALUES(last_ts) >= last_ts AND VALUES(email) <> '', VALUES(email), email),
				   user_id = IF(VALUES(user_id) > 0, VALUES(user_id), user_id),
				   last_ts = GREATEST(last_ts, VALUES(last_ts)),
				   updated_ts = VALUES(updated_ts)",
				$row['ckey'], $row['user_id'], $row['name'], $row['phone'], $row['email'], $row['net'], $row['ts'], $row['ts'], $now
			) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return count( $orders );
	}

	/** Pull the fields we need out of a WC_Order. */
	public static function extract( $order ) {
		$phone = self::normalize_phone( $order->get_billing_phone() );
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		$uid   = (int) $order->get_customer_id();
		if ( '' === $phone && $uid ) {
			$phone = self::normalize_phone( get_user_meta( $uid, 'billing_phone', true ) );
		}
		if ( '' !== $phone ) {
			$ckey = 'p:' . $phone;
		} elseif ( '' !== $email && is_email( $email ) ) {
			$ckey = 'e:' . $email;
		} elseif ( $uid ) {
			$ckey = 'u:' . $uid;
		} else {
			$ckey = 'o:' . $order->get_id();
		}
		$name = trim( (string) $order->get_formatted_billing_full_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		}
		$created = $order->get_date_created();
		$net     = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
		return array(
			'ckey'    => $ckey,
			'user_id' => $uid,
			'name'    => mb_substr( $name, 0, 190 ),
			'phone'   => $phone,
			'email'   => mb_substr( $email, 0, 190 ),
			'net'     => $net,
			'ts'      => $created ? (int) $created->getTimestamp() : time(),
		);
	}

	/**
	 * Normalise an Iranian mobile number to 09xxxxxxxxx. Returns '' if it is not a mobile number.
	 */
	public static function normalize_phone( $raw ) {
		$s = CRDR_Jalali::latin_digits( (string) $raw );
		$s = preg_replace( '/\D+/', '', $s );
		if ( 0 === strpos( $s, '0098' ) ) {
			$s = '0' . substr( $s, 4 );
		} elseif ( 0 === strpos( $s, '98' ) && 12 === strlen( $s ) ) {
			$s = '0' . substr( $s, 2 );
		} elseif ( 10 === strlen( $s ) && 0 === strpos( $s, '9' ) ) {
			$s = '0' . $s;
		}
		return preg_match( '/^09\d{9}$/', $s ) ? $s : '';
	}

	/* --------------------------------------------------------------- scoring */

	/**
	 * Score every customer and write RFM, segment, gap and priority back.
	 *
	 * @return int number of customers
	 */
	private static function finalize() {
		global $wpdb;
		$table = CRDR_DB::customers();
		$rows  = $wpdb->get_results( "SELECT id, ckey, orders_count, total_spent, first_ts, last_ts FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $rows ) {
			return 0;
		}
		$set = CRDR_Settings::all();
		$res = CRDR_RFM::score_all( $rows, time(), array(
			'new_days'    => $set['new_days'],
			'lost_days'   => $set['lost_days'],
			'min_ratio'   => $set['min_ratio'],
			'default_gap' => $set['default_gap'],
		) );

		$now = time();
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $row ) {
				$x        = $res[ $row['id'] ];
				$values[] = $wpdb->prepare(
					'(%d,%s,%d,%d,%d,%s,%f,%d,%f,%d)',
					$row['id'], $row['ckey'], $x['r'], $x['f'], $x['m'], $x['segment'], $x['avg_gap_days'], $x['due_ts'], $x['priority'], $now
				);
			}
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
				"INSERT INTO {$table} (id,ckey,r,f,m,segment,avg_gap_days,due_ts,priority,updated_ts) VALUES " . implode( ',', $values ) .
				' ON DUPLICATE KEY UPDATE r=VALUES(r), f=VALUES(f), m=VALUES(m), segment=VALUES(segment), avg_gap_days=VALUES(avg_gap_days), due_ts=VALUES(due_ts), priority=VALUES(priority), updated_ts=VALUES(updated_ts)'
			);
		}
		return count( $rows );
	}
}
