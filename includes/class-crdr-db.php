<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database tables.
 *  - crdr_customers   : one aggregated row per customer (rebuilt from orders on every sync)
 *  - crdr_orders      : slim copy of counted orders (rebuilt on every sync)
 *  - crdr_order_items : products per order, used for cross-sell analysis (rebuilt on every sync)
 *  - crdr_contacts    : every contact attempt (sms / call / skip / opt-out) incl. holdout rows;
 *                       keyed by customer key, so it survives rebuilds.
 *                       An SMS is first CLAIMED (status 'pending', claim_key set, UNIQUE) and only then sent;
 *                       claim_key is cleared when the outcome is recorded, so at most one send per customer
 *                       can be in flight. status: pending | ok | unknown | failed | retry | nobuild | rejected | holdout | norec.
 *                       'unknown' = the gateway may have accepted the SMS (timeout); it counts as contacted.
 */
class CRDR_DB {

	const DB_VERSION = '4'; // 4: data only (see minimize_stored): no schema change since 3

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

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
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
			PRIMARY KEY  (id),
			UNIQUE KEY claim_key (claim_key),
			KEY ckey_kind (ckey, kind, created_ts),
			KEY pb (playbook, created_ts)
		) {$charset};" );

		// Version 1 kept a simpler log table; nothing was ever released with it.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'crdr_log' ); // phpcs:ignore WordPress.DB.PreparedSQL

		self::minimize_stored();

		update_option( 'crdr_db_version', self::DB_VERSION );
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
		if ( get_option( 'crdr_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Record a contact event.
	 *
	 * @param string $ckey
	 * @param string $kind sms|called|skip|optout
	 * @param array  $a    playbook, grp, coupon, message, status, error
	 */
	public static function add_contact( $ckey, $kind, array $a = array() ) {
		global $wpdb;
		$wpdb->insert(
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
	 * @return string '' when the customer may be messaged, otherwise 'optout' or 'cooldown'
	 */
	public static function blocked_reason( $ckey, $exclude_id = 0, $cooldown_days = null ) {
		global $wpdb;
		$k = self::contacts();
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$k} WHERE ckey = %s AND kind = 'optout' LIMIT 1", $ckey ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
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
		return $hit ? 'cooldown' : '';
	}
}
