<?php
defined( 'ABSPATH' ) || exit;

/**
 * Duplicate-safe sending, shared by scheduled runs, manual campaign runs and one-off SMS.
 *
 * Order of events for every SMS:
 *   1. claim()         insert a 'pending' contact row carrying claim_key = customer key. claim_key is UNIQUE, so
 *                      the database lets exactly one process claim a customer; the loser is told "busy".
 *                      Right after winning, the opt-out / cooldown rules are checked again (the audience may
 *                      have been selected earlier, and another run may have finished since).
 *   2. send_claimed()  store message + localid on the row, THEN call the gateway, then record the outcome.
 *   3. finalize()      status ok | unknown | a failed kind, claim_key cleared. A message that was NOT sent loses its text.
 *
 * Nothing is ever re-sent automatically. A row left 'pending' by a crashed process is turned into 'unknown'
 * by recover_stale(); 'unknown' counts as contacted (cooldown + daily cap), so the customer is not messaged again.
 *
 * On top of that, a campaign takes a non-blocking MySQL named lock while it runs (run_lock), so cron and a manual
 * click cannot interleave. The lock is released by the server if the PHP process dies. It is an optimisation:
 * correctness comes from the unique claim, which also works where GET_LOCK is unavailable.
 */
class CRDR_Send {

	const STALE_AFTER = 600; // seconds a 'pending' claim may live before it is treated as unknown.
	const LOG_OPTION      = 'crdr_run_log';      // runs started by people (the audit trail of overrides lives here)
	const CRON_LOG_OPTION = 'crdr_cron_log';     // runs started by the scheduler
	const LOST_OPTION     = 'crdr_run_log_lost'; // how many override entries had to be dropped at the cap (normally 0)
	const EPOCH_OPTION    = 'crdr_build_epoch';  // time of the last change of settings/campaigns: lifts every "nobuild"/"retry" set-aside
	const REJECT_EPOCH_OPTION = 'crdr_reject_epochs'; // campaign id => time a person asked to try its rejected numbers again

	/* ------------------------------------------------------------------- run lock */

	private static function lock_name( $playbook_id ) {
		global $wpdb;
		return 'crdr_' . substr( md5( $wpdb->prefix . DB_NAME ), 0, 12 ) . '_' . substr( md5( (string) $playbook_id ), 0, 12 );
	}

	/** @return string got | busy | unsupported */
	public static function run_lock( $playbook_id ) {
		global $wpdb;
		$r = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name( $playbook_id ) ) );
		if ( null === $r ) {
			return 'unsupported';
		}
		return '1' === (string) $r ? 'got' : 'busy';
	}

	public static function run_unlock( $playbook_id ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $playbook_id ) ) );
	}

	/* ---------------------------------------------------------------------- claim */

	/**
	 * Try to claim one customer for sending.
	 *
	 * @param string   $grp           treated | holdout
	 * @param int|null $cooldown_days campaign override of the "days between messages" setting
	 * @return array [ 'id' => row id ] when claimed, otherwise [ 'skip' => busy | optout | cooldown | error ]
	 */
	public static function claim( $ckey, $playbook, $run_id, $grp = 'treated', $cooldown_days = null ) {
		global $wpdb;
		$prev = $wpdb->suppress_errors( true ); // a lost race is an expected "Duplicate entry", not something to print.
		$ok   = $wpdb->insert(
			CRDR_DB::contacts(),
			array(
				'ckey'       => $ckey,
				'kind'       => 'sms',
				'playbook'   => $playbook,
				'grp'        => $grp,
				'status'     => 'pending',
				'claim_key'  => $ckey,
				'run_id'     => substr( (string) $run_id, 0, 32 ),
				'created_ts' => time(),
				'user_id'    => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);
		$dup = ( false === $ok ) && self::is_duplicate_key_error();
		$wpdb->suppress_errors( $prev );
		if ( ! $ok ) {
			return array( 'skip' => $dup ? 'busy' : 'error' );
		}
		$id  = (int) $wpdb->insert_id;
		$why = CRDR_DB::blocked_reason( $ckey, $id, $cooldown_days );
		if ( '' !== $why ) {
			self::release( $id );
			return array( 'skip' => $why );
		}
		// A new attempt supersedes an earlier "set aside" record (could not build / provider refused / recipient
		// rejected): if it fails again a fresh row says so, if it works the customer is no longer listed as set aside.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CRDR_DB::contacts() . " WHERE ckey = %s AND playbook = %s AND status IN ('nobuild','retry','rejected')", $ckey, $playbook ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( 'id' => $id );
	}

	private static function is_duplicate_key_error() {
		global $wpdb;
		if ( ! empty( $wpdb->use_mysqli ) && $wpdb->dbh instanceof mysqli ) {
			return 1062 === (int) $wpdb->dbh->errno;
		}
		return false !== stripos( (string) $wpdb->last_error, 'Duplicate entry' );
	}

	/** Give a claim back without leaving a trace (nothing was sent). */
	public static function release( $row_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CRDR_DB::contacts() . " WHERE id = %d AND status = 'pending'", (int) $row_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Record the outcome and free the claim. A late answer may still upgrade a row that recover_stale() already
	 * turned into 'unknown'.
	 *
	 * @param string $status ok | failed | unknown | holdout
	 * @return bool whether a row was updated
	 */
	public static function finalize( $row_id, $status, $error = '' ) {
		global $wpdb;
		// Nothing was sent: the text of that message is not kept (the reason is). ok / unknown / holdout keep what they have.
		$clear = in_array( $status, CRDR_Send_Rules::NOT_CONTACT, true ) ? ", message = ''" : '';
		$n     = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . CRDR_DB::contacts() . " SET status = %s, error = %s{$clear}, claim_key = NULL WHERE id = %d AND status IN ('pending','unknown')", // phpcs:ignore WordPress.DB.PreparedSQL
			$status,
			mb_substr( (string) $error, 0, 255 ),
			(int) $row_id
		) );
		return $n > 0;
	}

	/**
	 * The message for this claimed customer cannot be built: nothing is sent, nothing counts as a contact, and the
	 * reason is kept (readable, no personal data). The customer is then left alone by this campaign for
	 * CRDR_Send_Rules::BUILD_BACKOFF, or until settings/campaigns are saved. claim() removes the previous
	 * "nobuild" row of the same customer and campaign, so there is one row per pair and retrying never grows the table.
	 */
	public static function mark_unbuildable( $row_id, $reason ) {
		self::finalize( $row_id, 'nobuild', $reason );
	}

	/** Something that may have caused "nobuild" was changed: let those customers be tried again. */
	public static function build_epoch_bump() {
		update_option( self::EPOCH_OPTION, time(), false );
	}

	/** Time a person last pressed "try the rejected numbers again" for this campaign (0 = never). */
	public static function reject_epoch( $playbook ) {
		$all = get_option( self::REJECT_EPOCH_OPTION, array() );
		return ( is_array( $all ) && isset( $all[ $playbook ] ) ) ? (int) $all[ $playbook ] : 0;
	}

	public static function reject_epoch_bump( $playbook ) {
		$all = get_option( self::REJECT_EPOCH_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$all[ (string) $playbook ] = time();
		update_option( self::REJECT_EPOCH_OPTION, $all, false );
	}

	/** Since when "set aside because of the text / an unexplained failure" still counts (nobuild, retry). */
	public static function build_since() {
		return CRDR_Send_Rules::build_backoff_since( time(), (int) get_option( self::EPOCH_OPTION, 0 ) );
	}

	/** Since when "recipient rejected by the provider" still counts for this campaign. */
	public static function reject_since( $playbook ) {
		return CRDR_Send_Rules::reject_backoff_since( time(), self::reject_epoch( $playbook ) );
	}

	/** SQL condition (on customers alias c): this campaign has not set the customer aside recently. */
	public static function not_set_aside_sql( $playbook, $alias = 'c' ) {
		global $wpdb;
		return $wpdb->prepare(
			'NOT EXISTS (SELECT 1 FROM ' . CRDR_DB::contacts() . " z WHERE z.ckey = {$alias}.ckey AND z.playbook = %s AND ( (z.status IN ('nobuild','retry') AND z.created_ts > %d) OR (z.status = 'rejected' AND z.created_ts > %d) ))", // phpcs:ignore WordPress.DB.PreparedSQL
			$playbook,
			self::build_since(),
			self::reject_since( $playbook )
		);
	}

	/** Persist what is about to be sent. If this fails nothing is sent. */
	private static function annotate( $row_id, $message, $coupon, $localid ) {
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . CRDR_DB::contacts() . " SET message = %s, coupon = %s, localid = %s WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL
			$message,
			mb_substr( (string) $coupon, 0, 20 ),
			$localid,
			(int) $row_id
		) );
		return false !== $n && $n > 0;
	}

	/**
	 * Send the SMS for a claimed row and record what happened.
	 *
	 * @return array state (sent|failed|unknown), error
	 */
	public static function send_claimed( $row_id, $phone, $message, $coupon = '' ) {
		if ( '' === trim( (string) $message ) ) {
			self::finalize( $row_id, CRDR_Send_Rules::failure_status( 'message' ), 'متن پیامک خالی است.' );
			return array( 'state' => 'failed', 'error' => 'متن پیامک خالی است.', 'scope' => 'message' );
		}
		$localid = CRDR_Send_Rules::localid();
		if ( ! self::annotate( $row_id, $message, $coupon, $localid ) ) {
			self::finalize( $row_id, 'failed', 'ثبت پیام در پایگاه‌داده ممکن نشد؛ برای جلوگیری از ارسال تکراری، پیامکی فرستاده نشد.' );
			return array( 'state' => 'failed', 'error' => 'ثبت پیام در پایگاه‌داده ممکن نشد؛ پیامکی فرستاده نشد.', 'scope' => 'gateway' );
		}
		$r = CRDR_SMS::deliver( $phone, $message, $localid );
		if ( 'sent' === $r['state'] ) {
			$status = 'ok';
		} elseif ( 'failed' === $r['state'] ) {
			$status = CRDR_Send_Rules::failure_status( $r['scope'] ); // who is at fault decides how the customer is treated
		} else {
			$status = 'unknown'; // possibly sent: never retried
		}
		self::finalize( $row_id, $status, $r['error'] );
		return $r;
	}

	/**
	 * Claims that were never completed (the process died or timed out mid-send) become 'unknown'. We cannot tell
	 * whether the SMS left, so the customer stays counted as contacted and is NOT retried.
	 *
	 * @return int rows changed
	 */
	public static function recover_stale( $max_age = null ) {
		global $wpdb;
		$max_age = null === $max_age ? self::STALE_AFTER : (int) $max_age;
		$cut     = time() - $max_age;
		$k       = CRDR_DB::contacts();
		// 1. The text and the localid are recorded BEFORE the gateway is called (send_claimed). A claim without a localid
		//    therefore never reached the gateway: nothing was sent, so it is not a contact and the customer may be tried
		//    again. If the original process is merely slow, its later "record the text" step fails (the row is no longer
		//    pending) and it sends nothing.
		$never = $wpdb->query( $wpdb->prepare(
			"UPDATE {$k} SET status = 'failed', claim_key = NULL, error = %s WHERE status = 'pending' AND localid = '' AND created_ts < %d", // phpcs:ignore WordPress.DB.PreparedSQL
			'اجرا پیش از ارسال متوقف شد؛ پیامکی نرفت و دوباره امتحان می‌شود.',
			$cut
		) );
		// 2. Everything else may have gone out (the process died or timed out during or after the call): unknown, never retried.
		$maybe = $wpdb->query( $wpdb->prepare(
			"UPDATE {$k} SET status = 'unknown', claim_key = NULL, error = %s WHERE status = 'pending' AND created_ts < %d", // phpcs:ignore WordPress.DB.PreparedSQL
			'اجرا نیمه‌کاره ماند و نتیجه‌ی ارسال معلوم نیست؛ دوباره ارسال نمی‌شود.',
			$cut
		) );
		return (int) $never + (int) $maybe;
	}

	/* ------------------------------------------------------------ explicit override */

	/** Who may send outside the allowed hours. Deliberately stricter than running a campaign. */
	public static function can_override_window() {
		return (bool) apply_filters( 'crdr_can_override_send_window', current_user_can( 'manage_options' ) );
	}

	/* ------------------------------------------------------------------- audit log */

	/**
	 * Record a run (who/when/why/how many; never phone numbers, message text or keys: see CRDR_Send_Rules::log_clean).
	 * Retention is per class (CRDR_Send_Rules::log_caps), so routine cron ticks cannot push out an override of the
	 * sending hours. If the audit class itself ever overflows, the oldest entries go and LOST_OPTION counts them.
	 */
	public static function log_run( array $entry ) {
		$entry = CRDR_Send_Rules::log_clean( $entry );
		$opt   = 'run' === CRDR_Send_Rules::log_store( CRDR_Send_Rules::log_class( $entry ) ) ? self::LOG_OPTION : self::CRON_LOG_OPTION;
		$log   = get_option( $opt, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = $entry;
		$trim  = CRDR_Send_Rules::log_trim( $log );
		update_option( $opt, $trim['log'], false );
		if ( ! empty( $trim['evicted']['override'] ) ) {
			update_option( self::LOST_OPTION, (int) get_option( self::LOST_OPTION, 0 ) + (int) $trim['evicted']['override'], false );
		}
	}

	/** Both logs as one list, oldest first. */
	public static function log_entries() {
		$a = get_option( self::LOG_OPTION, array() );
		$b = get_option( self::CRON_LOG_OPTION, array() );
		return CRDR_Send_Rules::log_merge( is_array( $a ) ? $a : array(), is_array( $b ) ? $b : array() );
	}
}
