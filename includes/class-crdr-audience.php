<?php
defined( 'ABSPATH' ) || exit;

/**
 * One filter model shared by the customer list, call list, CSV export and campaigns.
 *
 * Filter keys (all optional):
 *   segments[]  min_orders max_orders  min_spent max_spent  min_days max_days (days since last order)
 *   min_ratio max_ratio (days since last order / the customer's usual gap)  product (bought product id)
 *   q (search)  only_callable (priority > 0)  need_phone  exclude_contacted  cooldown (days override)
 */
class CRDR_Audience {

	/** Whitelist and normalise a raw request array (GET/POST or stored config). */
	public static function normalize( array $src ) {
		$f = array();
		if ( ! empty( $src['segments'] ) ) {
			$seg = array_values( array_intersect( array_map( 'sanitize_key', (array) $src['segments'] ), array_keys( CRDR_RFM::segments() ) ) );
			if ( $seg ) {
				$f['segments'] = $seg;
			}
		} elseif ( ! empty( $src['segment'] ) && isset( CRDR_RFM::segments()[ sanitize_key( $src['segment'] ) ] ) ) {
			$f['segments'] = array( sanitize_key( $src['segment'] ) );
		}
		foreach ( array( 'min_orders', 'max_orders', 'min_days', 'max_days', 'product' ) as $k ) {
			if ( isset( $src[ $k ] ) && '' !== $src[ $k ] && is_numeric( CRDR_Jalali::latin_digits( $src[ $k ] ) ) ) {
				$f[ $k ] = max( 0, (int) CRDR_Jalali::latin_digits( $src[ $k ] ) );
			}
		}
		foreach ( array( 'min_spent', 'max_spent', 'min_ratio', 'max_ratio' ) as $k ) {
			if ( isset( $src[ $k ] ) && '' !== $src[ $k ] && is_numeric( CRDR_Jalali::latin_digits( $src[ $k ] ) ) ) {
				$f[ $k ] = max( 0, (float) CRDR_Jalali::latin_digits( $src[ $k ] ) );
			}
		}
		if ( ! empty( $src['q'] ) ) {
			$f['q'] = mb_substr( sanitize_text_field( wp_unslash( $src['q'] ) ), 0, 80 );
		} elseif ( ! empty( $src['s'] ) ) {
			$f['q'] = mb_substr( sanitize_text_field( wp_unslash( $src['s'] ) ), 0, 80 );
		}
		foreach ( array( 'only_callable', 'need_phone', 'exclude_contacted' ) as $k ) {
			if ( ! empty( $src[ $k ] ) ) {
				$f[ $k ] = 1;
			}
		}
		if ( isset( $src['view'] ) && 'calls' === $src['view'] ) { // backwards compatible with the call-list URL.
			$f['only_callable']     = 1;
			$f['need_phone']        = 1;
			$f['exclude_contacted'] = 1;
		}
		if ( isset( $src['cooldown'] ) && '' !== $src['cooldown'] ) {
			$f['cooldown'] = max( 0, (int) $src['cooldown'] );
		}
		return $f;
	}

	/** @return string SQL "WHERE ..." for the aliased customers table */
	public static function where( array $f, $alias = 'c' ) {
		global $wpdb;
		$a   = $alias;
		$now = time();
		$w   = array( '1=1' );

		if ( ! empty( $f['segments'] ) ) {
			$in  = implode( ',', array_fill( 0, count( $f['segments'] ), '%s' ) );
			$w[] = $wpdb->prepare( "{$a}.segment IN ({$in})", $f['segments'] ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( isset( $f['min_orders'] ) ) {
			$w[] = $wpdb->prepare( "{$a}.orders_count >= %d", $f['min_orders'] );
		}
		if ( isset( $f['max_orders'] ) ) {
			$w[] = $wpdb->prepare( "{$a}.orders_count <= %d", $f['max_orders'] );
		}
		if ( isset( $f['min_spent'] ) ) {
			$w[] = $wpdb->prepare( "{$a}.total_spent >= %f", $f['min_spent'] );
		}
		if ( isset( $f['max_spent'] ) ) {
			$w[] = $wpdb->prepare( "{$a}.total_spent <= %f", $f['max_spent'] );
		}
		if ( isset( $f['min_days'] ) ) {
			$w[] = $wpdb->prepare( "{$a}.last_ts <= %d", $now - $f['min_days'] * DAY_IN_SECONDS );
		}
		if ( isset( $f['max_days'] ) ) {
			$w[] = $wpdb->prepare( "{$a}.last_ts >= %d", $now - $f['max_days'] * DAY_IN_SECONDS );
		}
		if ( isset( $f['min_ratio'] ) ) {
			$w[] = $wpdb->prepare( "({$now} - {$a}.last_ts) >= %f * {$a}.avg_gap_days * 86400", $f['min_ratio'] ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( isset( $f['max_ratio'] ) ) {
			$w[] = $wpdb->prepare( "({$now} - {$a}.last_ts) <= %f * {$a}.avg_gap_days * 86400", $f['max_ratio'] ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( ! empty( $f['product'] ) ) {
			$w[] = $wpdb->prepare(
				'EXISTS (SELECT 1 FROM ' . CRDR_DB::orders() . ' po JOIN ' . CRDR_DB::items() . " pi ON pi.order_id = po.order_id WHERE po.ckey = {$a}.ckey AND pi.product_id = %d)", // phpcs:ignore WordPress.DB.PreparedSQL
				$f['product']
			);
		}
		if ( ! empty( $f['q'] ) ) {
			$like = '%' . $wpdb->esc_like( CRDR_Jalali::latin_digits( $f['q'] ) ) . '%';
			$w[]  = $wpdb->prepare( "({$a}.name LIKE %s OR {$a}.phone LIKE %s OR {$a}.email LIKE %s)", $like, $like, $like ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( ! empty( $f['only_callable'] ) ) {
			$w[] = "{$a}.priority > 0";
		}
		if ( ! empty( $f['need_phone'] ) ) {
			$w[] = "{$a}.phone <> ''";
		}
		if ( ! empty( $f['exclude_contacted'] ) ) {
			$w = array_merge( $w, CRDR_DB::exclusions_sql( $a, isset( $f['cooldown'] ) ? $f['cooldown'] : null ) );
		}
		return 'WHERE ' . implode( ' AND ', $w );
	}

	public static function count( array $f ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() . ' c ' . self::where( $f ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Count plus the average order value of the audience (for scenarios). */
	public static function summary( array $f ) {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT COUNT(*) n, SUM(total_spent) spent, SUM(orders_count) orders FROM ' . CRDR_DB::customers() . ' c ' . self::where( $f ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array(
			'n'   => (int) $row['n'],
			'aov' => $row['orders'] > 0 ? (float) $row['spent'] / (float) $row['orders'] : 0.0,
		);
	}

	/** Query string args that reproduce a filter on a list page. */
	public static function to_args( array $f ) {
		$out = array();
		foreach ( $f as $k => $v ) {
			if ( 'segments' === $k ) {
				$out['segments'] = $v;
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}
}
