<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database tables.
 *  - crdr_customers   : one aggregated row per customer (rebuilt from orders on every sync)
 *  - crdr_orders      : slim copy of counted orders (rebuilt on every sync); uid + email are what the order said about WHO bought
 *                       (usable email only), kept so that CRDR_Identity can join the same person after all orders are in
 *  - crdr_order_items : products per order, used for cross-sell analysis (rebuilt on every sync)
 *  - crdr_contacts    : every contact attempt (sms / call / skip / opt-out) incl. holdout rows;
 *                       keyed by customer key, so it survives rebuilds.
 *                       An SMS is first CLAIMED (status 'pending', claim_key set, UNIQUE) and only then sent;
 *                       claim_key is cleared when the outcome is recorded, so at most one send per customer
 *                       can be in flight. status: pending | ok | unknown | failed | retry | nobuild | rejected | holdout | norec.
 *                       'unknown' = the gateway may have accepted the SMS (timeout); it counts as contacted.
 */
class CRDR_DB {

	const DB_VERSION = '6'; // 6 (Phase 4.2): crdr_contacts.orphan_ts (retention sweep), crdr_orders.refund_gap (unreconciled refund amount); void orders have ckey ''; 5: crdr_orders gets uid + email (the evidence of CRDR_Identity); 4: data only (see minimize_stored)

	/**
	 * Option: an UPDATE from a schema older than REBUILD_BELOW happened and no rebuild has finished since. The value is the time of the update.
	 * While it is set nothing is SENT (CRDR_Sync::send_guard): the first rebuild after the update is what makes the opt-out list consistent
	 * with how people are now recognised (joined by e-mail / account, opt-outs copied to every key). Cleared by the first rebuild that STARTED after it.
	 */
	const REBUILD_OPTION = 'crdr_rebuild_required';
	const REBUILD_BELOW  = 5;

	/** Option: the last schema check that failed ( ts, problems ). Present only while the plugin's tables are not complete. */
	const SCHEMA_ERROR = 'crdr_schema_error';

	/** After a failed repair, do not try again (on every request) before this many seconds. */
	const SCHEMA_RETRY = 300;

	/** @var array|null result of schema_problems() cached for the rest of this request (null = not checked yet) */
	private static $schema_cache = null;

	/**
	 * What the plugin needs from its tables: table => columns. install() writes the schema version only when ALL of this exists, and a
	 * campaign / rebuild refuses to start without it. (tests/phase1-safety.php compares this list with the real tables, so it cannot drift.)
	 */
	public static function required_schema() {
		return array(
			'customers'   => array( 'id', 'ckey', 'user_id', 'name', 'phone', 'email', 'orders_count', 'total_spent', 'first_ts', 'last_ts', 'avg_gap_days', 'due_ts', 'r', 'f', 'm', 'segment', 'priority', 'updated_ts' ),
			'orders'      => array( 'order_id', 'ckey', 'ts', 'net', 'items', 'uid', 'email', 'refund_gap' ),
			'order_items' => array( 'id', 'order_id', 'product_id', 'qty', 'total' ),
			'contacts'    => array( 'id', 'ckey', 'kind', 'playbook', 'grp', 'coupon', 'message', 'status', 'error', 'created_ts', 'user_id', 'run_id', 'localid', 'claim_key', 'orphan_ts' ),
		);
	}

	/**
	 * What is missing from the plugin's tables right now: 'table' (the table does not exist), 'table.column', or 'contacts.claim_key (unique)'
	 * (without that index two processes could claim the same customer, so it is part of the schema, not a nicety).
	 *
	 * @return string[] empty when the schema is complete
	 */
	public static function schema_problems() {
		global $wpdb;
		$out  = array();
		$prev = $wpdb->suppress_errors( true ); // a missing table is the answer here, not something to print or log
		foreach ( self::required_schema() as $short => $cols ) {
			$table = $wpdb->prefix . 'crdr_' . $short;
			$have  = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $have || $wpdb->last_error ) {
				$out[] = $short;
				continue;
			}
			$have = array_map( 'strtolower', $have );
			foreach ( $cols as $c ) {
				if ( ! in_array( $c, $have, true ) ) {
					$out[] = $short . '.' . $c;
				}
			}
		}
		if ( ! in_array( 'contacts', $out, true ) ) {
			$idx = $wpdb->get_results( 'SHOW INDEX FROM ' . self::contacts() . " WHERE Key_name = 'claim_key'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $idx || (int) $idx[0]['Non_unique'] !== 0 ) {
				$out[] = 'contacts.claim_key (unique)';
			}
		}
		$wpdb->suppress_errors( $prev );
		return $out;
	}

	/** The tables are complete (checked once per request; ensure_schema() and install() refresh it). Cheap enough to ask before every claim. */
	public static function schema_ok() {
		if ( null === self::$schema_cache ) {
			self::$schema_cache = self::schema_problems();
		}
		return ! self::$schema_cache;
	}

	/**
	 * Make sure the tables are complete, repairing them once (dbDelta) when they are not. A repair that fails is remembered in
	 * SCHEMA_ERROR and not retried for SCHEMA_RETRY seconds, so a host that refuses ALTER is not hammered on every request.
	 *
	 * @return string[] what is still missing ( empty = fine )
	 */
	public static function ensure_schema() {
		$problems = self::schema_problems();
		if ( $problems ) {
			$err = get_option( self::SCHEMA_ERROR );
			if ( self::history_table_lost( $problems ) ) {
				// NEVER recreate the contact table on its own: a new, empty one would silently forget every opt-out. A person decides
				// (restore a backup, or deactivate and activate the plugin knowing that the list starts empty).
				update_option( self::SCHEMA_ERROR, array( 'ts' => time(), 'problems' => array_slice( $problems, 0, 20 ) ), false );
			} elseif ( ! is_array( $err ) || empty( $err['ts'] ) || time() - (int) $err['ts'] >= self::SCHEMA_RETRY ) {
				self::install(); // writes SCHEMA_ERROR itself when it cannot complete the schema
				$problems = self::schema_problems();
			}
		}
		self::$schema_cache = $problems;
		if ( ! $problems ) {
			delete_option( self::SCHEMA_ERROR );
		}
		return $problems;
	}

	/** Upgrade path: like ensure_schema(), but the version is recorded by install() once the schema is complete. */
	private static function ensure_schema_for_upgrade() {
		$problems = self::schema_problems();
		if ( self::history_table_lost( $problems ) ) {
			update_option( self::SCHEMA_ERROR, array( 'ts' => time(), 'problems' => array_slice( $problems, 0, 20 ) ), false );
			self::$schema_cache = $problems;
			return;
		}
		self::install();
	}

	/** The contact table (opt-outs, cooldowns) is gone although the plugin was installed before: re-creating it empty would lose every opt-out. */
	public static function history_table_lost( array $problems ) {
		return in_array( 'contacts', $problems, true ) && false !== get_option( 'crdr_db_version', false );
	}

	/** Owner-facing sentence for an incomplete schema (names of tables / columns only, no SQL). Persian. */
	public static function schema_message( array $problems ) {
		if ( self::history_table_lost( $problems ) ) {
			return 'جدول سابقه‌ی تماس افزونه (شامل فهرست «پیام نده» و زمان آخرین پیام هر مشتری) در پایگاه‌داده وجود ندارد. تا برطرف شدن آن هیچ پیامکی ارسال نمی‌شود. آن را از پشتیبان پایگاه‌داده برگردانید. اگر پشتیبان ندارید، غیرفعال و دوباره فعال کردن افزونه جدول را خالی می‌سازد و فهرست «پیام نده» از دست رفته است؛ در این حالت پیش از هر ارسال، مشتریانی را که پیام نخواسته‌اند خودتان دوباره ثبت کنید.';
		}
		return 'جدول‌های افزونه در پایگاه‌داده کامل نیستند (کمبود: ' . implode( '، ', array_slice( $problems, 0, 6 ) ) . ( count( $problems ) > 6 ? ' …' : '' ) . '). تا برطرف شدن آن، ارسال پیامک و به‌روزرسانی داده‌ها متوقف است. افزونه را غیرفعال و دوباره فعال کنید؛ اگر ادامه داشت، از میزبان بخواهید به کاربر پایگاه‌داده‌ی سایت اجازه‌ی ساخت و تغییر جدول (CREATE و ALTER) بدهد.';
	}

	/** Forget the cached check (tests, and after install()). */
	public static function forget_schema_check() {
		self::$schema_cache = null;
	}

	public static function customers() {
		global $wpdb;
		return $wpdb->prefix . 'crdr_customers';
	}

	public static function orders() {
		global $wpdb;
		return $wpdb->prefix . 'crdr_orders';
	}

	public static function items() {
		global $wpdb;
		return $wpdb->prefix . 'crdr_order_items';
	}

	public static function contacts() {
		global $wpdb;
		return $wpdb->prefix . 'crdr_contacts';
	}

	/** @return bool the schema is complete and its version was recorded */
	public static function install() {
		global $wpdb;
		$prev = get_option( 'crdr_db_version', false ); // what was installed before this call (false = a first installation)
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$charset = $wpdb->get_charset_collate();
		$c       = self::customers();
		$o       = self::orders();
		$i       = self::items();
		$k       = self::contacts();

		dbDelta( "CREATE TABLE {$c} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ckey varchar(100) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL DEFAULT '',
			phone varchar(20) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			orders_count int(10) unsigned NOT NULL DEFAULT 0,
			total_spent decimal(20,2) NOT NULL DEFAULT 0,
			first_ts int(10) unsigned NOT NULL DEFAULT 0,
			last_ts int(10) unsigned NOT NULL DEFAULT 0,
			avg_gap_days float NOT NULL DEFAULT 0,
			due_ts int(10) unsigned NOT NULL DEFAULT 0,
			r tinyint(3) unsigned NOT NULL DEFAULT 0,
			f tinyint(3) unsigned NOT NULL DEFAULT 0,
			m tinyint(3) unsigned NOT NULL DEFAULT 0,
			segment varchar(20) NOT NULL DEFAULT '',
			priority decimal(20,2) NOT NULL DEFAULT 0,
			updated_ts int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY ckey (ckey),
			KEY segment (segment),
			KEY priority (priority),
			KEY last_ts (last_ts)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$o} (
			order_id bigint(20) unsigned NOT NULL,
			ckey varchar(100) NOT NULL,
			ts int(10) unsigned NOT NULL DEFAULT 0,
			net decimal(20,2) NOT NULL DEFAULT 0,
			items int(10) unsigned NOT NULL DEFAULT 0,
			uid bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			refund_gap decimal(20,2) NOT NULL DEFAULT 0,
			PRIMARY KEY  (order_id),
			KEY ckey_ts (ckey, ts),
			KEY ts (ts)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$i} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			qty int(10) unsigned NOT NULL DEFAULT 1,
			total decimal(20,2) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY product_id (product_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$k} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ckey varchar(100) NOT NULL,
			kind varchar(12) NOT NULL,
			playbook varchar(40) NOT NULL DEFAULT '',
			grp varchar(10) NOT NULL DEFAULT 'treated',
			coupon varchar(20) NOT NULL DEFAULT '',
			message text,
			status varchar(10) NOT NULL DEFAULT 'ok',
			error varchar(255) NOT NULL DEFAULT '',
			created_ts int(10) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			run_id varchar(32) NOT NULL DEFAULT '',
			localid varchar(24) NOT NULL DEFAULT '',
			claim_key varchar(100) DEFAULT NULL,
			orphan_ts int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY claim_key (claim_key),
			KEY ckey_kind (ckey, kind, created_ts),
			KEY pb (playbook, created_ts),
			KEY orphan (orphan_ts)
		) {$charset};" );

		// Version 1 kept a simpler log table; nothing was ever released with it.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'crdr_log' ); // phpcs:ignore WordPress.DB.PreparedSQL

		// The version is written ONLY when every table and column the plugin needs really exists. dbDelta() reports nothing when an
		// ALTER is refused (no privilege, a lock timeout), and a version written anyway would hide the problem forever.
		self::forget_schema_check();
		$problems = self::schema_problems();
		self::$schema_cache = $problems;
		if ( $problems ) {
			update_option( self::SCHEMA_ERROR, array( 'ts' => time(), 'problems' => array_slice( $problems, 0, 20 ) ), false );
			return false;
		}
		delete_option( self::SCHEMA_ERROR );
		self::minimize_stored();
		update_option( 'crdr_db_version', self::DB_VERSION );
		if ( false !== $prev && (int) $prev < self::REBUILD_BELOW ) { // an update of existing data, not a fresh install
			update_option( self::REBUILD_OPTION, time(), false );
		}
		CRDR_Site::record_if_absent(); // which site this is (a copy of the database is recognised by its address); never replaces an existing one
		return true;
	}

	/**
	 * Data minimisation for rows written by earlier versions (docs/data-retention.md). Idempotent, data only:
	 *  - the text of an SMS that was never sent (failed / retry / nobuild / rejected) is cleared; ok, unknown and
	 *    pending rows are not touched;
	 *  - error texts (the 5,000 newest that have one) lose raw provider wording, API keys and phone numbers.
	 */
	public static function minimize_stored() {
		global $wpdb;
		$k   = self::contacts();
		$not = self::not_contact();
		$wpdb->query( "UPDATE {$k} SET message = '' WHERE status IN ( {$not} ) AND message IS NOT NULL AND message <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$secrets = array( CRDR_Settings::get( 'kavenegar_key' ) );
		$rows    = $wpdb->get_results( "SELECT id, error FROM {$k} WHERE error <> '' ORDER BY id DESC LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( (array) $rows as $r ) {
			$clean = CRDR_Send_Rules::clean_error( $r['error'], $secrets );
			if ( $clean !== $r['error'] ) {
				$wpdb->update( $k, array( 'error' => $clean ), array( 'id' => (int) $r['id'] ), array( '%s' ), array( '%d' ) );
			}
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'crdr_db_version' ) === self::DB_VERSION ) {
			return;
		}
		$err = get_option( self::SCHEMA_ERROR );
		if ( is_array( $err ) && ! empty( $err['ts'] ) && time() - (int) $err['ts'] < self::SCHEMA_RETRY ) {
			return; // the last attempt could not complete the schema; try again in a few minutes, not on every request
		}
		if ( false !== get_option( 'crdr_db_version', false ) ) { // an update, not a first install: do not re-create a lost contact table empty
			self::ensure_schema_for_upgrade();
			return;
		}
		self::install();
	}

	/**
	 * Record a contact event.
	 *
	 * @param string $ckey
	 * @param string $kind sms|called|skip|optout
	 * @param array  $a    playbook, grp, coupon, message, status, error
	 * @return int|false 1 when the row was written, false on a database error (callers that record an opt-out must not say "saved" then)
	 */
	public static function add_contact( $ckey, $kind, array $a = array() ) {
		global $wpdb;
		return $wpdb->insert(
			self::contacts(),
			array(
				'ckey'       => $ckey,
				'kind'       => $kind,
				'playbook'   => isset( $a['playbook'] ) ? $a['playbook'] : '',
				'grp'        => isset( $a['grp'] ) ? $a['grp'] : 'treated',
				'coupon'     => isset( $a['coupon'] ) ? $a['coupon'] : '',
				'message'    => isset( $a['message'] ) ? $a['message'] : '',
				'status'     => isset( $a['status'] ) ? $a['status'] : 'ok',
				'error'      => isset( $a['error'] ) ? mb_substr( $a['error'], 0, 255 ) : '',
				'created_ts' => time(),
				'user_id'    => get_current_user_id(),
				'run_id'     => isset( $a['run_id'] ) ? substr( (string) $a['run_id'], 0, 32 ) : '',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/** Text of an opt-out row that was copied to another key of the SAME person (see CRDR_Sync::resolve_identities). */
	const OPTOUT_COPY_NOTE = 'عدم تمایل به دریافت پیام (همین مشتری با شماره یا ایمیل دیگر)';

	/**
	 * Has this customer key an opt-out row, according to the database right now?
	 *
	 * @return bool|null null when the database could not answer (the caller must treat that as "do not send")
	 */
	public static function is_opted_out( $ckey ) {
		global $wpdb;
		$r = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::contacts() . " WHERE ckey = %s AND kind = 'optout' LIMIT 1", $ckey ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $wpdb->last_error ) {
			return null;
		}
		return (bool) $r;
	}

	/**
	 * Which of these customer keys have an opt-out row.
	 *
	 * @param array $keys customer keys
	 * @param bool  $ok   set to false when the database failed (the result may then miss opt-outs)
	 * @return array key => true
	 */
	public static function optout_keys( array $keys, &$ok = null ) {
		global $wpdb;
		$out = array();
		$ok  = true;
		foreach ( array_chunk( array_values( array_unique( array_map( 'strval', $keys ) ) ), 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT ckey FROM ' . self::contacts() . " WHERE kind = 'optout' AND ckey IN ({$in})", $chunk ) ) as $k ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$out[ (string) $k ] = true;
			}
			if ( $wpdb->last_error ) {
				$ok = false; // the answer may be incomplete: callers that must not miss an opt-out check this
			}
		}
		return $out;
	}

	/** SQL fragments (already prepared) that hide recently contacted and opted-out customers. */
	/** SQL list of the statuses that are not a contact: 'failed','nobuild'. */
	public static function not_contact() {
		return "'" . implode( "','", CRDR_Send_Rules::NOT_CONTACT ) . "'";
	}

	public static function exclusions_sql( $alias = 'c', $cooldown_days = null ) {
		global $wpdb;
		$k    = self::contacts();
		$days = null === $cooldown_days ? (int) CRDR_Settings::get( 'cooldown_days' ) : (int) $cooldown_days;
		$out  = array( "NOT EXISTS (SELECT 1 FROM {$k} x WHERE x.ckey = {$alias}.ckey AND x.kind = 'optout')" );
		if ( $days > 0 ) {
			$out[] = $wpdb->prepare(
				"NOT EXISTS (SELECT 1 FROM {$k} y WHERE y.ckey = {$alias}.ckey AND y.kind IN ('sms','called','skip') AND y.status NOT IN ( " . self::not_contact() . " ) AND y.created_ts > %d)", // phpcs:ignore WordPress.DB.PreparedSQL
				time() - $days * DAY_IN_SECONDS
			);
		} else {
			// "0 days" must not mean "message the same people again on every run": keep a fixed safety floor for SMS.
			$out[] = $wpdb->prepare(
				"NOT EXISTS (SELECT 1 FROM {$k} y WHERE y.ckey = {$alias}.ckey AND y.kind = 'sms' AND y.status NOT IN ( " . self::not_contact() . " ) AND y.created_ts > %d)", // phpcs:ignore WordPress.DB.PreparedSQL
				time() - CRDR_Send_Rules::MIN_RESEND
			);
		}
		return $out;
	}

	/**
	 * The same rules as exclusions_sql(), asked about one customer. Used right after an SMS is claimed,
	 * because the audience may have been selected a while earlier.
	 *
	 * @param int $exclude_id contact row to ignore (the claim being checked)
	 * @return string '' when the customer may be messaged, otherwise 'optout', 'cooldown' or 'dberror' (the database could not answer)
	 */
	public static function blocked_reason( $ckey, $exclude_id = 0, $cooldown_days = null ) {
		global $wpdb;
		$k = self::contacts();
		$opted = self::is_opted_out( $ckey );
		if ( null === $opted ) {
			return 'dberror'; // the database could not answer: not "no opt-out"
		}
		if ( $opted ) {
			return 'optout';
		}
		$days  = null === $cooldown_days ? (int) CRDR_Settings::get( 'cooldown_days' ) : (int) $cooldown_days;
		$kinds = $days > 0 ? "'sms','called','skip'" : "'sms'";
		$since = time() - ( $days > 0 ? $days * DAY_IN_SECONDS : CRDR_Send_Rules::MIN_RESEND );
		$not   = self::not_contact();
		$hit   = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$k} WHERE ckey = %s AND kind IN ({$kinds}) AND status NOT IN ( {$not} ) AND id <> %d AND created_ts > %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			$ckey,
			(int) $exclude_id,
			$since
		) );
		if ( $wpdb->last_error ) {
			return 'dberror';
		}
		return $hit ? 'cooldown' : '';
	}
}
