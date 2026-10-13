<?php
defined( 'ABSPATH' ) || exit;

/** This process no longer owns the sync lock (it expired and somebody took it over): stop at once, change nothing. */
class CRDR_Sync_Lost extends RuntimeException {
}

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
	const LOCK_OPTION  = 'crdr_sync_lock';

	/** Joined people written per batch by the identity step (a handful of statements per batch, not per person). */
	const JOIN_BATCH = 200;

	/** Joins of the same person that earlier rebuilds applied (member key => canonical key). They stay joined: see CRDR_Identity. */
	const LINKS_OPTION = 'crdr_identity_links';

	/** Customer keys the owner separated on purpose (CRDR_Sync::unjoin): no rule and no remembered join ever joins them again. */
	const APART_OPTION = 'crdr_identity_apart';

	/**
	 * A lock that was not renewed for this many seconds belongs to a step that died, and may be taken over. A live step renews it
	 * (checkpoint()) at least every LOCK_RENEW seconds, so only a step that is dead, or stuck for more than LOCK_TTL - LOCK_RENEW
	 * seconds inside ONE unit of work (one order, one scoring chunk, the analytics build), can lose it.
	 */
	const LOCK_TTL = 600;

	/** A running step renews its lock when it is this old. */
	const LOCK_RENEW = 60;

	/** No progress for this long: the sync is "stalled" (it still blocks sending; the owner is told to run it again). */
	const STALL_AFTER = 1800;

	/**
	 * Phases in which the customer / order / item tables are being rebuilt and may be incomplete:
	 *   starting  start() has recorded that it is about to empty the tables (and may have done so)
	 *   orders    importing pages of orders
	 *   finalize  scoring the customers
	 *   stats     building the analytics
	 * plus 'failed': a sync that stopped half way. Only 'idle' (never run) and 'done' are safe for sending.
	 * The guard (guard()) is on for every phase except those two, for the whole rebuild, until the LAST step has succeeded.
	 */
	const WORK = array( 'starting', 'orders', 'finalize', 'stats' );

	/** The lock this process holds: token (ours alone), val (what the row holds right now) and at (when we last wrote it). */
	private static $held = null;

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

	/**
	 * @param bool $fresh read the option from the database even if this request already has a copy (another process may have
	 *                    changed it; the guard needs the truth, not what this request saw a minute ago)
	 */
	public static function state( $fresh = false ) {
		if ( $fresh ) {
			wp_cache_delete( self::STATE_OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' ); // an option saved as autoloaded by an older version lives here
		}
		$s = get_option( self::STATE_OPTION );
		if ( false === $s ) {
			return array( 'phase' => 'idle' );
		}
		// Something is stored but it is not a state we wrote: do not guess that it is safe.
		return is_array( $s ) && isset( $s['phase'] ) ? $s : array( 'phase' => 'failed', 'error' => 'unreadable_state' );
	}

	/**
	 * May the customer data be used right now (campaigns, SMS, customer lists, exports)? Server-side single source of truth.
	 *
	 * @return array blocked (bool), code (sync_running | sync_stalled | sync_failed | ''), message (Persian, for the owner)
	 */
	public static function guard( $fresh = true ) {
		$s     = self::state( $fresh );
		$phase = isset( $s['phase'] ) ? $s['phase'] : 'idle';
		if ( 'idle' === $phase || 'done' === $phase ) {
			return array( 'blocked' => false, 'code' => '', 'message' => '' );
		}
		if ( in_array( $phase, self::WORK, true ) ) {
			if ( time() - (int) ( isset( $s['updated'] ) ? $s['updated'] : 0 ) > self::STALL_AFTER ) {
				return array( 'blocked' => true, 'code' => 'sync_stalled', 'message' => 'به‌روزرسانی داده‌های مشتریان بیش از نیم‌ساعت است که پیش نرفته. تا تکمیل آن، ارسال پیامک، کمپین‌ها و فهرست‌های مشتریان متوقف است؛ در «داشبورد» دکمه‌ی «تحلیل سفارش‌ها» را بزنید.' );
			}
			return array( 'blocked' => true, 'code' => 'sync_running', 'message' => 'داده‌های مشتریان هم‌اکنون در حال به‌روزرسانی (تحلیل سفارش‌ها) است. تا پایان آن، که چند دقیقه طول می‌کشد، ارسال پیامک، کمپین‌ها و فهرست‌های مشتریان در دسترس نیست؛ کمی بعد دوباره امتحان کنید.' );
		}
		// 'failed', or a phase this version does not know: unsafe until a sync has completed.
		if ( isset( $s['error'] ) && 'schema' === $s['error'] ) {
			return array( 'blocked' => true, 'code' => 'sync_failed', 'message' => 'به‌روزرسانی داده‌ها شروع نشد، چون جدول‌های افزونه در پایگاه‌داده کامل نیستند (غیرفعال و دوباره فعال کردن افزونه معمولاً آن را برطرف می‌کند؛ اگر ادامه داشت از میزبان بخواهید اجازه‌ی ساخت و تغییر جدول را به کاربر پایگاه‌داده بدهد). تا آن موقع ارسال پیامک، کمپین‌ها و فهرست‌های مشتریان متوقف است؛ سپس «تحلیل سفارش‌ها» را بزنید.' );
		}
		return array( 'blocked' => true, 'code' => 'sync_failed', 'message' => 'به‌روزرسانی داده‌های مشتریان نیمه‌کاره ماند. تا تکمیل آن، ارسال پیامک، کمپین‌ها و فهرست‌های مشتریان متوقف است؛ در «داشبورد» دکمه‌ی «تحلیل سفارش‌ها» را دوباره بزنید.' );
	}

	/**
	 * May an SMS be SENT (or a send be prepared) right now? guard() plus: no update is waiting for its first rebuild (CRDR_DB::REBUILD_OPTION).
	 * Everything that sends goes through here; reading lists, recording an opt-out or a call does not need it.
	 *
	 * @return array blocked (bool), code (... | rebuild_required | ''), message
	 */
	public static function send_guard( $fresh = true ) {
		$g = self::guard( $fresh );
		if ( $g['blocked'] ) {
			return $g;
		}
		if ( $fresh ) {
			wp_cache_delete( CRDR_DB::REBUILD_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		if ( false !== get_option( CRDR_DB::REBUILD_OPTION, false ) ) {
			return array( 'blocked' => true, 'code' => 'rebuild_required', 'message' => self::rebuild_required_message() );
		}
		return $g;
	}

	/** What to do after an update that needs the first rebuild, in Persian. */
	public static function rebuild_required_message() {
		return 'رادار مشتری به‌روزرسانی شده است. برای اینکه فهرست «پیام نده» و مشتریانِ تکراری (یک نفر با دو شماره یا ایمیل) درست یکی شوند، یک‌بار در «داشبورد» دکمه‌ی «تحلیل سفارش‌ها» را بزنید (یا تا اجرای خودکار ساعت ۳ بامداد صبر کنید). تا آن موقع ارسال پیامک و کمپین‌ها متوقف است؛ ثبت «پیام نده»، «تماس گرفتم» و فهرست‌ها در دسترس‌اند.';
	}

	/**
	 * Run a write of the rebuild. A database error (deadlock, lock wait timeout, disk full ...) makes $wpdb->query() return false; it
	 * must stop the step (the rebuild becomes "failed", sending stays blocked) and not be skipped, or half of a join would be
	 * applied and the rebuild would still end "done".
	 *
	 * @return int|bool rows affected
	 */
	private static function exec( $sql ) {
		global $wpdb;
		$r = $wpdb->query( $sql );
		if ( false === $r ) {
			throw new RuntimeException( 'database_error' ); // code only: no SQL, no data
		}
		return $r;
	}

	/**
	 * Write an option, but only while this process still owns the lock. The ownership test and the write are ONE statement, so the
	 * database, not PHP, decides: if the lock was taken over in the meantime, no row is written and CRDR_Sync_Lost is thrown.
	 */
	private static function fenced_write( $option, $value ) {
		global $wpdb;
		self::checkpoint(); // also keeps the lock alive
		$h = self::$held;
		$n = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			 SELECT %s, %s, 'no' FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s
			 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", // phpcs:ignore WordPress.DB.PreparedSQL
			$option, maybe_serialize( $value ), self::LOCK_OPTION, $h['val']
		) );
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( ! $n ) {
			self::$held = null;
			throw new CRDR_Sync_Lost( 'not_written:' . $option );
		}
	}

	/**
	 * Write the state, but only while this process still owns the lock: a step that lost its lock can never mark a rebuild done,
	 * nor undo the 'failed' the new owner recorded.
	 */
	private static function save_state( array $s ) {
		$s['updated'] = time(); // heartbeat: guard() calls a sync "stalled" when this stops moving
		$s['rev']     = bin2hex( random_bytes( 4 ) ); // the stored value always changes, so "0 rows" can only mean "not the owner"
		self::fenced_write( self::STATE_OPTION, $s );
		return $s;
	}

	/** The sync stopped half way: stay unsafe (guard stays on), say why in codes only, never retry on our own. */
	private static function fail( array $s, $why, $e = null ) {
		$s['failed_phase'] = isset( $s['phase'] ) ? $s['phase'] : '';
		$s['phase']        = 'failed';
		$s['error']        = $why . ( $e ? ':' . get_class( $e ) : '' );
		$s['failed_at']    = time();
		return self::save_state( $s );
	}

	/* ------------------------------------------------------------------ lock */

	/**
	 * One sync step (or start) at a time. Not GET_LOCK (on old MySQL a second named lock silently drops the first):
	 * the way WordPress core locks its own upgrader: a row in the options table, created with INSERT IGNORE, so the
	 * unique option_name decides the winner in the database, not PHP.
	 *
	 * The row holds "time:token". The token belongs to this one acquisition, so every later statement ("renew", "release", "write
	 * the state") names it and can only ever touch OUR lock. The time is a lease: it is rewritten by checkpoint() while we work,
	 * and a lock whose time is older than LOCK_TTL is a dead step's and may be taken over (compare-and-swap on the exact old value).
	 *
	 * @return string|false the lock value to pass to unlock(), or false when somebody else holds it
	 */
	private static function lock() {
		global $wpdb;
		$token = bin2hex( random_bytes( 8 ) );
		$val   = time() . ':' . $token;
		$won   = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $val ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( 1 !== (int) $won ) {
			$cur = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( '' === $cur || time() - (int) $cur <= self::LOCK_TTL ) {
				return false; // held by a live step (or just released: the caller tries again later)
			}
			// Held by a step that died long ago: take it over, but only if nobody else did in the meantime.
			$took = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $val, self::LOCK_OPTION, $cur ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( 1 !== (int) $took ) {
				return false;
			}
		}
		self::$held = array( 'token' => $token, 'val' => $val, 'at' => time() );
		register_shutdown_function( array( __CLASS__, 'unlock' ), $val ); // also runs after a fatal error
		return $val;
	}

	/**
	 * Called between small units of work: renews the lease when it is LOCK_RENEW seconds old, and proves we still own it.
	 * The renewal is a compare-and-swap on the exact value we last wrote; if it changes nothing, somebody took the lock over
	 * and CRDR_Sync_Lost stops this process before it touches another table.
	 *
	 * @throws CRDR_Sync_Lost
	 */
	private static function checkpoint() {
		global $wpdb;
		$h = self::$held;
		if ( ! $h ) {
			throw new CRDR_Sync_Lost( 'no_lock' );
		}
		if ( time() - $h['at'] < self::LOCK_RENEW ) {
			return; // a taker needs the lock to be LOCK_TTL old: nobody can have taken it yet
		}
		$new = time() . ':' . $h['token'];
		$n   = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, self::LOCK_OPTION, $h['val'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( 1 !== (int) $n ) {
			self::$held = null;
			throw new CRDR_Sync_Lost( 'lock_taken_over' );
		}
		self::$held = array( 'token' => $h['token'], 'val' => $new, 'at' => time() );
	}

	/** Release the lock if (and only if) it is still ours. Safe to call twice. */
	public static function unlock( $val ) {
		global $wpdb;
		$pos = strrpos( (string) $val, ':' );
		$tok = false === $pos ? '' : substr( $val, $pos + 1 );
		if ( self::$held && '' !== $tok && self::$held['token'] === $tok ) {
			$val        = self::$held['val']; // the value we wrote last (a renewal changes it)
			self::$held = null;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $val ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	private static function order_args( $extra = array() ) {
		$statuses = array_map( function ( $s ) {
			return 'wc-' . $s;
		}, (array) CRDR_Settings::get( 'statuses' ) );
		return array_merge(
			array(
				'type'    => 'shop_order',
				'status'  => $statuses,
				// ID, not date: orders created in the same second share a date, and with equal dates MySQL may order them
				// differently on each page query, so one order was read twice and another not at all (seen with 205 orders).
				'orderby' => 'ID',
				'order'   => 'ASC',
			),
			$extra
		);
	}

	/* ------------------------------------------------------------- lifecycle */

	/**
	 * Begin a full rebuild from scratch. The "unsafe" state is recorded BEFORE anything is emptied, so there is no moment at
	 * which the tables are being destroyed while the state still says they are fine.
	 */
	public static function start() {
		$lock = self::lock();
		if ( false === $lock ) {
			return array_merge( self::state( true ), array( 'busy' => true ) ); // another step is running right now
		}
		try {
			return self::start_locked();
		} catch ( CRDR_Sync_Lost $e ) {
			return array_merge( self::state( true ), array( 'busy' => true ) ); // not ours any more: the new owner decides what happens
		} finally {
			self::unlock( $lock );
		}
	}

	private static function start_locked() {
		global $wpdb;
		$s = array();
		try {
			// Never empty tables that cannot be refilled: with an incomplete schema every order insert would fail half way.
			$missing = CRDR_DB::ensure_schema();
			if ( $missing ) {
				return self::fail( array( 'phase' => 'starting' ), 'schema' );
			}
			$s = self::save_state( array(
				'phase'     => 'starting',
				'page'      => 1,
				'total'     => 0,
				'done'      => 0,
				'started'   => time(),
				'finished'  => 0,
				'customers' => 0,
				'inflight'  => 0,
				'run'       => bin2hex( random_bytes( 6 ) ),
			) );
			foreach ( array( CRDR_DB::customers(), CRDR_DB::orders(), CRDR_DB::items() ) as $table ) {
				self::checkpoint(); // never empty a table without proving first that the lock is still ours
				self::exec( 'TRUNCATE TABLE ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			$res        = wc_get_orders( self::order_args( array( 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) ) );
			$total      = is_object( $res ) ? (int) $res->total : 0;
			$s['total'] = $total;
			$s['phase'] = $total > 0 ? 'orders' : 'finalize';
			return self::save_state( $s );
		} catch ( CRDR_Sync_Lost $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			return self::fail( $s, 'start', $e );
		}
	}

	/**
	 * Do one unit of work and return the new state ('busy' => true when another step is running: nothing was done).
	 *
	 * Never marks an incomplete sync as complete: 'done' is only ever written after the last phase has succeeded. A step that
	 * throws, or that is found half applied later, turns the sync into 'failed' (still unsafe) instead of being retried
	 * blindly, because re-importing a half-imported page would count its orders twice. Run start() again to rebuild.
	 */
	public static function step() {
		$lock = self::lock();
		if ( false === $lock ) {
			return array_merge( self::state( true ), array( 'busy' => true ) );
		}
		try {
			return self::step_locked();
		} catch ( CRDR_Sync_Lost $e ) {
			return array_merge( self::state( true ), array( 'busy' => true ) ); // not ours any more: nothing was written after the loss
		} finally {
			self::unlock( $lock );
		}
	}

	private static function step_locked() {
		$s     = self::state( true );
		$phase = isset( $s['phase'] ) ? $s['phase'] : 'idle';
		if ( ! in_array( $phase, self::WORK, true ) ) {
			return $s; // idle, done or failed: nothing to do
		}
		try {
			if ( 'starting' === $phase ) {
				return self::fail( $s, 'interrupted_start' ); // start() died before it finished: the tables may be half emptied
			}
			if ( 'orders' === $phase ) {
				if ( ! empty( $s['inflight'] ) ) {
					return self::fail( $s, 'interrupted_page' ); // an earlier step died in the middle of a page: it is half applied
				}
				$s['inflight'] = (int) $s['page'];
				self::save_state( $s ); // from here until the line below, a page may be half applied
				$n             = self::import_page( (int) $s['page'] );
				$s['inflight'] = 0;
				$s['done']    += $n;
				$s['page']++;
				if ( $n < self::PAGE_SIZE ) {
					$s['phase'] = 'finalize';
				}
				return self::save_state( $s );
			}
			if ( 'finalize' === $phase ) {
				if ( ! empty( $s['inflight'] ) ) {
					return self::fail( $s, 'interrupted_page' ); // a reconcile step died in the middle of importing orders
				}
				// First make sure every eligible order is in (see reconcile()); only then score. Normally this reads two lists and
				// changes nothing. Scoring itself is idempotent: safe to run again if it was interrupted.
				$rec = self::reconcile( $s );
				if ( $rec['again'] ) {
					return self::save_state( $rec['state'] ); // still importing missing orders: stay in this phase, guard on
				}
				$s              = self::resolve_identities( $rec['state'] ); // join the same person's provisional customers (CRDR_Identity)
				$s['customers'] = self::finalize();
				$s['phase']     = 'stats';
				return self::save_state( $s );
			}
			self::checkpoint();
			CRDR_Analytics::build(); // 'stats': idempotent too (and it only writes the derived statistics, not the customer tables)
			$s['phase']    = 'done';
			$s['finished'] = time();
			unset( $s['error'], $s['failed_phase'], $s['failed_at'] );
			$s = self::save_state( $s );
			// The update that needed this rebuild is over, but only if the rebuild STARTED after it (one that began under the old version
			// imported its orders without the identity evidence).
			$need = (int) get_option( CRDR_DB::REBUILD_OPTION, 0 );
			if ( $need && (int) $s['started'] >= $need ) {
				delete_option( CRDR_DB::REBUILD_OPTION );
			}
			// Retention of contact rows whose customer is gone (CRDR_Retention). Here and nowhere else: this line is reached only by a
			// rebuild that finished every phase and whose "done" state was accepted by the database (a lost lock throws before it).
			CRDR_Retention::run();
			return $s;
		} catch ( CRDR_Sync_Lost $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			return self::fail( $s, 'step', $e );
		}
	}

	/* ------------------------------------------------------------ background */

	public static function start_background() {
		$s = self::start();
		if ( empty( $s['busy'] ) && in_array( $s['phase'], self::WORK, true ) ) {
			self::enqueue_next();
		}
	}

	public static function background_step() {
		$s = self::step();
		if ( ! empty( $s['busy'] ) ) {
			wp_schedule_single_event( time() + 30, self::STEP_HOOK ); // another step is running: look again shortly
			return;
		}
		if ( in_array( $s['phase'], self::WORK, true ) ) { // 'failed' and 'done' end the chain
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
		$orders = wc_get_orders( self::order_args( array(
			'limit'  => self::PAGE_SIZE,
			'paged'  => $page,
			'return' => 'objects',
		) ) );
		foreach ( $orders as $order ) {
			self::checkpoint(); // before each order: a step that lost its lock stops here, after at most the order it was in
			self::import_order( $order );
		}
		return count( $orders );
	}

	/**
	 * Add one order to the order table, its items, and its customer's totals.
	 *
	 * An order is counted only if it is NEW to the order table. If the list shifted while the import was running (an order
	 * changed status) the same order can come back on the next page: it must not be counted twice.
	 *
	 * @return bool true when the order was new (and was counted), false when it was already there
	 */
	private static function import_order( $order ) {
		global $wpdb;
		$table        = CRDR_DB::customers();
		$orders_table = CRDR_DB::orders();
		$items_table  = CRDR_DB::items();
		$now          = time();
		$row          = self::extract( $order );

		if ( $row['void'] ) {
			// A fully refunded (or free) order is not an order for anyone: no customer, no frequency, no repeat. It still gets a row, with an
			// empty customer key and no identity evidence, so that the completeness check (reconcile) sees it as imported and does not chase it.
			$new = self::exec( $wpdb->prepare(
				"INSERT IGNORE INTO {$orders_table} (order_id,ckey,ts,net,items,uid,email,refund_gap) VALUES (%d,'',%d,0,0,0,'',%f)", // phpcs:ignore WordPress.DB.PreparedSQL
				$order->get_id(), $row['ts'], $row['gap']
			) );
			return 1 === (int) $new;
		}
		$new = self::exec( $wpdb->prepare(
			"INSERT IGNORE INTO {$orders_table} (order_id,ckey,ts,net,items,uid,email,refund_gap) VALUES (%d,%s,%d,%f,%d,%d,%s,%f)", // phpcs:ignore WordPress.DB.PreparedSQL
			$order->get_id(), $row['ckey'], $row['ts'], $row['net'], count( $row['items'] ), $row['user_id'], $row['ident_email'], $row['gap']
		) );
		if ( 1 !== (int) $new ) {
			return false;
		}
		$item_values = array();
		foreach ( $row['items'] as $line ) {
			$item_values[] = $wpdb->prepare( '(%d,%d,%d,%f)', $order->get_id(), $line[0], $line[1], $line[2] ); // net of the line's refunds
		}
		if ( $item_values ) {
			self::exec( "INSERT INTO {$items_table} (order_id,product_id,qty,total) VALUES " . implode( ',', $item_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		self::exec( $wpdb->prepare(
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
		return true;
	}

	/* ------------------------------------------------------------ reconciliation */

	/** Most reconcile steps one rebuild may take: a store that keeps changing faster than this is reported, not chased forever. */
	const RECONCILE_MAX_ROUNDS = 25;

	/** Reconcile steps in a row that found missing orders but could not import any of them, before they are called "unresolved". */
	const RECONCILE_IDLE_ROUNDS = 2;

	/**
	 * Is every order that is eligible RIGHT NOW in the order table? Reads the whole list of eligible order ids in ONE query (ids
	 * only, so it is cheap and cannot shift under itself the way page-by-page reading does) and the list of imported ids, and imports
	 * what is missing. Called by the 'finalize' phase before anybody is scored.
	 *
	 * Why it is needed: the pages are read by offset, so when an order that is before the current position changes status (leaves
	 * or enters the counted statuses) while the import runs, everything after it moves one place and one order is never read.
	 *
	 * What it guarantees: when it reports nothing missing, every order that was eligible at the moment it read the list is in the
	 * table. What it cannot: orders that change after that moment, and orders that were imported and LATER left the counted statuses
	 * (those stay counted until the next rebuild). It does not claim a snapshot.
	 *
	 * @param array $s the sync state
	 * @return array [ 'again' => bool (run another step in this phase), 'state' => array ]
	 */
	private static function reconcile( array $s ) {
		global $wpdb;
		$eligible = array_map( 'intval', (array) wc_get_orders( self::order_args( array( 'limit' => -1, 'return' => 'ids' ) ) ) );
		$have     = array_flip( array_map( 'intval', (array) $wpdb->get_col( 'SELECT order_id FROM ' . CRDR_DB::orders() ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$missing  = array();
		foreach ( $eligible as $id ) {
			if ( ! isset( $have[ $id ] ) ) {
				$missing[] = $id;
			}
		}
		if ( ! $missing ) {
			unset( $s['unresolved'], $s['unverified'] );
			return array( 'again' => false, 'state' => $s );
		}
		$s['rounds'] = (int) ( isset( $s['rounds'] ) ? $s['rounds'] : 0 ) + 1;
		$s['inflight'] = 'reconcile'; // like a page: a step that dies in the middle leaves the marker, and the rebuild is failed
		$s             = self::save_state( $s );
		$added         = 0;
		$statuses      = (array) CRDR_Settings::get( 'statuses' );
		foreach ( array_slice( $missing, 0, self::PAGE_SIZE ) as $id ) {
			self::checkpoint();
			$order = wc_get_order( $id );
			if ( ! $order || ! in_array( $order->get_status(), $statuses, true ) ) {
				continue; // it changed again since the list was read: not eligible any more, or cannot be loaded
			}
			if ( self::import_order( $order ) ) {
				$added++;
			}
		}
		$s['inflight'] = 0;
		$s['added']    = (int) ( isset( $s['added'] ) ? $s['added'] : 0 ) + $added;
		$s['idle']     = $added > 0 ? 0 : (int) ( isset( $s['idle'] ) ? $s['idle'] : 0 ) + 1;
		$again         = true;
		if ( $s['idle'] >= self::RECONCILE_IDLE_ROUNDS ) {
			$s['unresolved'] = count( $missing ) - $added; // eligible orders that could not be added: said out loud, never hidden
			$again           = false;
		} elseif ( $s['rounds'] >= self::RECONCILE_MAX_ROUNDS ) {
			$s['unverified'] = 1; // orders kept appearing: the last batch was imported but the list was not checked again
			$again           = false;
		}
		return array( 'again' => $again, 'state' => $s );
	}

	/**
	 * Pull the fields we need out of a WC_Order. The key is PROVISIONAL (see CRDR_Identity): the same person may still be joined
	 * later, in the "finalize" phase, once every order is known.
	 */
	public static function extract( $order ) {
		$phone = CRDR_Identity::phone( $order->get_billing_phone() );
		$email = CRDR_Identity::email( $order->get_billing_email() ); // '' for a malformed address and for an anonymisation placeholder
		$uid   = (int) $order->get_customer_id();
		if ( '' === $phone && $uid ) {
			$phone = CRDR_Identity::phone( get_user_meta( $uid, 'billing_phone', true ) );
		}
		$ckey = CRDR_Identity::provisional_key( $phone, $email, $uid, $order->get_id() );
		$name = trim( (string) $order->get_formatted_billing_full_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		}
		$created = $order->get_date_created();
		// NET SALES (CRDR_Sales): the product lines after discounts, before tax and shipping, minus their refunds. A fully refunded or free
		// order has none: it is VOID and is not an order for frequency, repeat counts or customers.
		$sales   = CRDR_Sales::of_order( $order );
		$net     = $sales['net'];
		// What is shown / stored as the customer's email: the usable address; an erased order has none. (A malformed address
		// that is not a placeholder is still shown as typed, as before; it is just never used to recognise anybody.)
		$shown = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( CRDR_Identity::is_placeholder_email( $shown ) ) {
			$shown = '';
		}
		return array(
			'ckey'    => $ckey,
			'user_id' => $uid,
			'name'    => mb_substr( $name, 0, 190 ),
			'phone'   => $phone,
			'email'   => mb_substr( $shown, 0, 190 ),
			'ident_email' => mb_substr( $email, 0, 190 ),
			'net'     => $net,
			'void'    => $sales['void'],
			'gap'     => $sales['gap'],
			'items'   => $sales['items'],
			'ts'      => $created ? (int) $created->getTimestamp() : time(),
		);
	}

	/**
	 * Normalise an Iranian mobile number to 09xxxxxxxxx. Returns '' if it is not a mobile number.
	 */
	public static function normalize_phone( $raw ) {
		return CRDR_Identity::phone( $raw );
	}

	/* ------------------------------------------------------------- identity */

	/**
	 * Join provisional customers that are the same person (rules: CRDR_Identity). Runs once, after every order is in and before
	 * anybody is scored. It only ever JOINS: the customer that keeps the key (the canonical one) absorbs the others' order counts,
	 * spend and dates, their orders are re-keyed, and so is their CONTACT HISTORY (opt-outs, cooldowns, results), so that a person
	 * who opted out under an old phone number stays opted out under the new one. Nothing is written when nothing is joined.
	 *
	 * Like a page, the work is marked in flight: a step that dies in the middle leaves the marker and the rebuild is failed (a new
	 * rebuild recomputes everything; re-keying the contacts is idempotent).
	 *
	 * @param array $s the sync state
	 * @return array the state, with the identity report
	 */
	private static function resolve_identities( array $s ) {
		global $wpdb;
		$c     = CRDR_DB::customers();
		$o     = CRDR_DB::orders();
		$raw   = get_option( self::LINKS_OPTION, array() );
		$links = CRDR_Identity::clean_links( $raw );
		$apart = CRDR_Identity::clean_keys( get_option( self::APART_OPTION, array() ) );

		// Only customers that CAN be joined are loaded: those that share an email or an account with another customer, and those in
		// the joins remembered from earlier rebuilds. Everybody else has nothing to join with, so the answer is the same as with
		// all of them (tests/phase1-safety.php checks that on random data), but memory and time follow the number of joins, not the
		// number of customers. (Orders have no index on email or uid: each of these two queries is a scan of the order table.)
		// One scan each (a join against the grouped identifiers), not one scan per chunk of identifiers: email and uid have no index.
		$cand = array();
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT x.ckey FROM {$o} x JOIN (SELECT email FROM {$o} WHERE email <> '' GROUP BY email HAVING COUNT(DISTINCT ckey) > 1) d ON d.email = x.email" ) as $k ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$cand[ (string) $k ] = true;
		}
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT x.ckey FROM {$o} x JOIN (SELECT uid FROM {$o} WHERE uid > 0 GROUP BY uid HAVING COUNT(DISTINCT ckey) > 1) d ON d.uid = x.uid" ) as $k ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$cand[ (string) $k ] = true;
		}
		foreach ( $links['links'] as $a => $b ) {
			$cand[ (string) $a ] = true;
			$cand[ (string) $b ] = true;
		}
		$clusters = array();
		$evidence = array();
		foreach ( array_chunk( array_keys( $cand ), 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ckey, last_ts FROM {$c} WHERE ckey IN ({$in})", $chunk ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$clusters[ (string) $r['ckey'] ] = (int) $r['last_ts'];
			}
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ckey, uid, email FROM {$o} WHERE ckey IN ({$in}) GROUP BY ckey, uid, email", $chunk ), ARRAY_N ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$evidence[] = $r;
			}
		}
		unset( $cand );
		$res = CRDR_Identity::resolve( $clusters, $evidence, $links['links'], $apart );
		unset( $clusters, $evidence );
		$s['identity']                  = $res['report'];
		$s['identity']['unidentified']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE ckey LIKE 'o:%'" ); // phpcs:ignore WordPress.DB.PreparedSQL -- (the resolver only saw the joinable customers)
		$s['identity']['links_dropped'] = $links['dropped']; // dropped by clean_links() before resolve() saw the list
		$changed                        = $res['links'] !== $raw; // new joins, joins forgotten because their customers are gone, or entries that were not usable
		if ( ! $res['map'] && ! $links['links'] && ! $changed ) {
			return $s; // nobody was ever joined: nothing to write
		}
		$s['inflight'] = 'resolve';
		$s             = self::save_state( $s ); // (proves we own the lock)
		if ( $changed ) {
			self::fenced_write( self::LINKS_OPTION, $res['links'] ); // the decision is remembered BEFORE it is applied: a rebuild that dies half way repeats it
		}
		// 1. OPT-OUTS FIRST. An opt-out belongs to the person, so every key of a joined person gets one if any of them has one (the keys
		//    that were joined before as well as now). Rows are only ever ADDED here, so protection can only grow, whatever fails later,
		//    and the person stays opted out under each of their keys even if the joins are later lost, repaired or the newest key vanishes.
		self::propagate_optouts( CRDR_Identity::components( $links['links'], $res['links'] ) );
		// 2. then join, JOIN_BATCH people at a time: the customer rows, the orders, and the rest of the contact history (opt-out rows stay put)
		$groups = array();
		foreach ( $res['map'] as $alias => $canon ) {
			$groups[ $canon ][] = $alias;
		}
		ksort( $groups );
		foreach ( array_chunk( $groups, self::JOIN_BATCH, true ) as $batch ) {
			self::checkpoint();
			$members = array();
			foreach ( $batch as $canon => $aliases ) {
				$members[] = $canon;
				foreach ( $aliases as $a ) {
					$members[] = $a;
				}
			}
			$by_key = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$c} WHERE ckey IN (" . implode( ',', array_fill( 0, count( $members ), '%s' ) ) . ') ORDER BY last_ts DESC, ckey ASC', $members ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$by_key[ $r['ckey'] ] = $r;
			}
			$agg_by   = array(); // canonical key => the joined customer's row
			$alias_of = array(); // alias key => canonical key (only for customers that exist)
			foreach ( $batch as $canon => $aliases ) {
				$agg = array( 'orders_count' => 0, 'total_spent' => 0.0, 'first_ts' => PHP_INT_MAX, 'last_ts' => 0, 'name' => '', 'email' => '', 'user_id' => 0, 'phone' => '' );
				$any = false;
				$m   = array_merge( array( $canon ), $aliases );
				usort( $m, function ( $x, $y ) use ( $by_key ) { // newest first: the newest non-empty value wins, as when orders are imported one by one
					$tx = isset( $by_key[ $x ] ) ? (int) $by_key[ $x ]['last_ts'] : 0;
					$ty = isset( $by_key[ $y ] ) ? (int) $by_key[ $y ]['last_ts'] : 0;
					return $ty <=> $tx ?: strcmp( $x, $y );
				} );
				foreach ( $m as $k ) {
					if ( ! isset( $by_key[ $k ] ) ) {
						continue;
					}
					$r                    = $by_key[ $k ];
					$any                  = true;
					$agg['orders_count'] += (int) $r['orders_count'];
					$agg['total_spent']  += (float) $r['total_spent'];
					$agg['first_ts']      = min( $agg['first_ts'], (int) $r['first_ts'] );
					$agg['last_ts']       = max( $agg['last_ts'], (int) $r['last_ts'] );
					foreach ( array( 'name', 'email' ) as $f ) {
						if ( '' === $agg[ $f ] && '' !== (string) $r[ $f ] ) {
							$agg[ $f ] = $r[ $f ];
						}
					}
					if ( 0 === $agg['user_id'] && (int) $r['user_id'] > 0 ) {
						$agg['user_id'] = (int) $r['user_id'];
					}
					if ( $k === $canon ) {
						$agg['phone'] = (string) $r['phone']; // the canonical key is the strongest kind: a phone customer keeps its own number
					}
				}
				if ( ! $any ) {
					continue;
				}
				$agg_by[ $canon ] = $agg;
				foreach ( $aliases as $a ) {
					$alias_of[ $a ] = $canon;
				}
			}
			if ( ! $agg_by ) {
				continue;
			}
			// One statement per table for the whole batch (CASE ... WHEN), instead of four per person.
			$cols = array( 'orders_count' => '%d', 'total_spent' => '%f', 'first_ts' => '%d', 'last_ts' => '%d', 'name' => '%s', 'email' => '%s', 'user_id' => '%d', 'phone' => '%s' );
			$sets = array();
			foreach ( $cols as $col => $fmt ) {
				$when = '';
				foreach ( $agg_by as $canon => $agg ) {
					$when .= $wpdb->prepare( " WHEN %s THEN {$fmt}", $canon, $agg[ $col ] ); // phpcs:ignore WordPress.DB.PreparedSQL
				}
				$sets[] = "{$col} = CASE ckey{$when} END";
			}
			$canons = array_keys( $agg_by );
			self::exec( $wpdb->prepare( "UPDATE {$c} SET " . implode( ', ', $sets ) . ' WHERE ckey IN (' . implode( ',', array_fill( 0, count( $canons ), '%s' ) ) . ')', $canons ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $alias_of ) {
				$when = '';
				foreach ( $alias_of as $a => $canon ) {
					$when .= $wpdb->prepare( ' WHEN %s THEN %s', $a, $canon );
				}
				$aliases_all = array_keys( $alias_of );
				$in          = implode( ',', array_fill( 0, count( $aliases_all ), '%s' ) );
				self::exec( $wpdb->prepare( "UPDATE {$o} SET ckey = CASE ckey{$when} END WHERE ckey IN ({$in})", $aliases_all ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				self::exec( $wpdb->prepare( 'UPDATE ' . CRDR_DB::contacts() . " SET ckey = CASE ckey{$when} END WHERE kind <> 'optout' AND ckey IN ({$in})", $aliases_all ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				self::exec( $wpdb->prepare( "DELETE FROM {$c} WHERE ckey IN ({$in})", $aliases_all ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}
		$s['inflight'] = 0;
		return $s;
	}

	/**
	 * Give every key of a person the opt-out any of their keys has. Only inserts.
	 *
	 * @param array $people list of lists of keys (CRDR_Identity::components)
	 * @return int rows added
	 */
	private static function propagate_optouts( array $people ) {
		$universe = array();
		foreach ( $people as $keys ) {
			foreach ( $keys as $k ) {
				$universe[] = $k;
			}
		}
		$opted = $universe ? CRDR_DB::optout_keys( $universe ) : array();
		if ( ! $opted ) {
			return 0;
		}
		$added = 0;
		foreach ( $people as $keys ) {
			$has = false;
			foreach ( $keys as $k ) {
				if ( isset( $opted[ $k ] ) ) {
					$has = true;
					break;
				}
			}
			if ( ! $has ) {
				continue;
			}
			foreach ( $keys as $k ) {
				if ( ! isset( $opted[ $k ] ) ) {
					self::checkpoint();
					if ( ! CRDR_DB::add_contact( $k, 'optout', array( 'playbook' => 'manual', 'message' => CRDR_DB::OPTOUT_COPY_NOTE ) ) ) {
						throw new RuntimeException( 'database_error' ); // an opt-out that could not be written is a failure, never skipped
					}
					$added++;
				}
			}
		}
		return $added;
	}

	/**
	 * REPAIR of a wrong join: take these customer keys out of whatever they were joined with, and keep them apart from now on (no rule and
	 * no remembered join joins them again until rejoin()). Takes effect at the next rebuild. Safe by construction:
	 *  - every key of the people involved gets the opt-out any of them has BEFORE anything is removed (an opt-out is never dropped);
	 *  - the contact history that already moved to the joined customer (cooldowns, results) stays there: it is not split back;
	 *  - refuses while a rebuild is running, and while another step holds the sync lock.
	 * Use the key shown in the customer list (p:0912..., e:..., u:...), or an old key that was joined into it.
	 *
	 *   wp eval 'var_dump( CRDR_Sync::unjoin( array( "p:09121110001" ) ) );'
	 *
	 * @param array $keys customer keys
	 * @return array [ 'ok' => bool, 'reason' => string, 'apart' => keys now kept apart ]
	 */
	public static function unjoin( array $keys ) {
		$keys = CRDR_Identity::clean_keys( $keys );
		if ( ! $keys ) {
			return array( 'ok' => false, 'reason' => 'no_valid_key' );
		}
		$st = self::state( true );
		if ( in_array( $st['phase'], self::WORK, true ) ) {
			return array( 'ok' => false, 'reason' => 'rebuild_running' );
		}
		$lock = self::lock();
		if ( false === $lock ) {
			return array( 'ok' => false, 'reason' => 'busy' );
		}
		try {
			$raw   = get_option( self::LINKS_OPTION, array() );
			$links = CRDR_Identity::clean_links( $raw )['links'];
			$touch = array();
			foreach ( CRDR_Identity::components( $links ) as $group ) {
				if ( array_intersect( $group, $keys ) ) {
					$touch[] = $group;
				}
			}
			foreach ( $keys as $k ) { // a key that was never joined is simply kept apart
				$touch[] = array( $k );
			}
			self::propagate_optouts( $touch ); // before anything is removed
			foreach ( $keys as $k ) {
				$pointing = array();
				foreach ( $links as $a => $b ) {
					if ( $b === $k ) {
						$pointing[] = $a;
					}
				}
				unset( $links[ $k ] );
				foreach ( $pointing as $a ) {
					unset( $links[ $a ] );
				}
				if ( count( $pointing ) >= 2 ) { // the others stay joined with each other; one of them takes over as the key of the group
					$new = $pointing[0];
					foreach ( array_slice( $pointing, 1 ) as $a ) {
						$links[ $a ] = $new;
					}
				}
			}
			ksort( $links );
			$apart = CRDR_Identity::clean_keys( array_merge( (array) get_option( self::APART_OPTION, array() ), $keys ) );
			self::fenced_write( self::LINKS_OPTION, $links );
			self::fenced_write( self::APART_OPTION, $apart );
			return array( 'ok' => true, 'reason' => '', 'apart' => $apart );
		} catch ( CRDR_Sync_Lost $e ) {
			return array( 'ok' => false, 'reason' => 'lock_lost' );
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Run $fn while this process holds the sync lock and NO rebuild is running (the same two conditions unjoin() needs). For work that
	 * changes the customer / order / contact tables or the remembered joins outside a rebuild (the personal-data eraser).
	 *
	 * @param callable $fn function(): mixed  may call forget_keys_locked()
	 * @return array ok (bool), reason ('' | rebuild_running | busy | lock_lost), value (what $fn returned)
	 */
	public static function exclusive( $fn ) {
		$st = self::state( true );
		if ( in_array( $st['phase'], self::WORK, true ) ) {
			return array( 'ok' => false, 'reason' => 'rebuild_running', 'value' => null );
		}
		$lock = self::lock();
		if ( false === $lock ) {
			return array( 'ok' => false, 'reason' => 'busy', 'value' => null );
		}
		try {
			return array( 'ok' => true, 'reason' => '', 'value' => call_user_func( $fn ) );
		} catch ( CRDR_Sync_Lost $e ) {
			return array( 'ok' => false, 'reason' => 'lock_lost', 'value' => null );
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Take these customer keys out of the remembered joins and the kept-apart list (their person asked to be erased). Joins between the
	 * OTHER keys of a group survive (they are re-pointed to one of the remaining members). Opt-outs are copied to every key of the
	 * groups involved first, exactly as unjoin() does, so nobody who stays loses protection. Only inside exclusive().
	 *
	 * @return int number of remembered joins that were removed or changed
	 */
	public static function forget_keys_locked( array $keys ) {
		$keys  = CRDR_Identity::clean_keys( $keys );
		if ( ! $keys ) {
			return 0;
		}
		$links = CRDR_Identity::clean_links( get_option( self::LINKS_OPTION, array() ) )['links'];
		$apart = CRDR_Identity::clean_keys( get_option( self::APART_OPTION, array() ) );
		$touch = array();
		foreach ( CRDR_Identity::components( $links ) as $group ) {
			if ( array_intersect( $group, $keys ) ) {
				$touch[] = $group;
			}
		}
		self::propagate_optouts( $touch ); // before anything is removed
		$gone = array_flip( $keys );
		$new  = array();
		$heads = array(); // canonical key (erased) => remaining members that pointed to it
		foreach ( $links as $a => $b ) {
			if ( isset( $gone[ $a ] ) ) {
				continue;
			}
			if ( isset( $gone[ $b ] ) ) {
				$heads[ $b ][] = $a;
				continue;
			}
			$new[ $a ] = $b;
		}
		foreach ( $heads as $members ) {
			sort( $members, SORT_STRING );
			$first = array_shift( $members );
			foreach ( $members as $m ) {
				$new[ $m ] = $first; // the others stay joined with each other
			}
		}
		ksort( $new );
		if ( $new !== $links ) {
			self::fenced_write( self::LINKS_OPTION, $new );
		}
		$left = array_values( array_diff( $apart, $keys ) );
		if ( $left !== $apart ) {
			self::fenced_write( self::APART_OPTION, $left );
		}
		return count( array_diff_assoc( $links, $new ) ) + count( array_diff_assoc( $new, $links ) );
	}

	/** Undo unjoin(): the keys may be joined by the rules again at the next rebuild. */
	public static function rejoin( array $keys ) {
		$keys = CRDR_Identity::clean_keys( $keys );
		$st   = self::state( true );
		if ( ! $keys || in_array( $st['phase'], self::WORK, true ) ) {
			return array( 'ok' => false, 'reason' => $keys ? 'rebuild_running' : 'no_valid_key' );
		}
		$lock = self::lock();
		if ( false === $lock ) {
			return array( 'ok' => false, 'reason' => 'busy' );
		}
		try {
			$apart = array_values( array_diff( CRDR_Identity::clean_keys( get_option( self::APART_OPTION, array() ) ), $keys ) );
			self::fenced_write( self::APART_OPTION, $apart );
			return array( 'ok' => true, 'reason' => '', 'apart' => $apart );
		} catch ( CRDR_Sync_Lost $e ) {
			return array( 'ok' => false, 'reason' => 'lock_lost' );
		} finally {
			self::unlock( $lock );
		}
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
			self::checkpoint();
			$values = array();
			foreach ( $chunk as $row ) {
				$x        = $res[ $row['id'] ];
				$values[] = $wpdb->prepare(
					'(%d,%s,%d,%d,%d,%s,%f,%d,%f,%d)',
					$row['id'], $row['ckey'], $x['r'], $x['f'], $x['m'], $x['segment'], $x['avg_gap_days'], $x['due_ts'], $x['priority'], $now
				);
			}
			self::exec( // phpcs:ignore WordPress.DB.PreparedSQL
				"INSERT INTO {$table} (id,ckey,r,f,m,segment,avg_gap_days,due_ts,priority,updated_ts) VALUES " . implode( ',', $values ) .
				' ON DUPLICATE KEY UPDATE r=VALUES(r), f=VALUES(f), m=VALUES(m), segment=VALUES(segment), avg_gap_days=VALUES(avg_gap_days), due_ts=VALUES(due_ts), priority=VALUES(priority), updated_ts=VALUES(updated_ts)'
			);
		}
		return count( $rows );
	}
}
