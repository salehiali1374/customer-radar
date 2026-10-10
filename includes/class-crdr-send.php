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
 *   3. finalize()      status ok | failed | unknown, claim_key cleared.
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
	const LOG_OPTION  = 'crdr_run_log';
	const LOG_MAX     = 200;

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
		$n = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . CRDR_DB::contacts() . " SET status = %s, error = %s, claim_key = NULL WHERE id = %d AND status IN ('pending','unknown')", // phpcs:ignore WordPress.DB.PreparedSQL
			$status,
			mb_substr( (string) $error, 0, 255 ),
			(int) $row_id
		) );
		return $n > 0;
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
			self::finalize( $row_id, 'failed', 'متن پیامک خالی است.' );
			return array( 'state' => 'failed', 'error' => 'متن پیامک خالی است.' );
		}
		$localid = CRDR_Send_Rules::localid();
		if ( ! self::annotate( $row_id, $message, $coupon, $localid ) ) {
			self::finalize( $row_id, 'failed', 'ثبت پیام در پایگاه‌داده ممکن نشد؛ برای جلوگیری از ارسال تکراری، پیامکی فرستاده نشد.' );
			return array( 'state' => 'failed', 'error' => 'ثبت پیام در پایگاه‌داده ممکن نشد؛ پیامکی فرستاده نشد.' );
		}
		$r      = CRDR_SMS::deliver( $phone, $message, $localid );
		$status = 'sent' === $r['state'] ? 'ok' : ( 'failed' === $r['state'] ? 'failed' : 'unknown' );
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
		$n       = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . CRDR_DB::contacts() . " SET status = 'unknown', claim_key = NULL, error = %s WHERE status = 'pending' AND created_ts < %d", // phpcs:ignore WordPress.DB.PreparedSQL
			'اجرا نیمه‌کاره ماند و نتیجه‌ی ارسال معلوم نیست؛ دوباره ارسال نمی‌شود.',
			time() - $max_age
		) );
		return (int) $n;
	}

	/* ------------------------------------------------------------ explicit override */

	/** Who may send outside the allowed hours. Deliberately stricter than running a campaign. */
	public static function can_override_window() {
		return (bool) apply_filters( 'crdr_can_override_send_window', current_user_can( 'manage_options' ) );
	}

	/* ------------------------------------------------------------------- audit log */

	/** Keep the last runs (who/when/why/how many); an override of the sending hours is always recorded here. */
	public static function log_run( array $entry ) {
		$log   = get_option( self::LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = $entry;
		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, -self::LOG_MAX );
		}
		update_option( self::LOG_OPTION, $log, false );
	}
}
