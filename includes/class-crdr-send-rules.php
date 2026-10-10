<?php
/**
 * Pure rules for sending safely: may a campaign run now, how to read what the SMS gateway answered,
 * and the numeric id Kavenegar uses to recognise a repeated request.
 * No database or WordPress calls, so every rule is unit testable.
 */
defined( 'ABSPATH' ) || defined( 'CRDR_TESTING' ) || exit;

class CRDR_Send_Rules {

	/**
	 * A customer is never sent two SMS within this many seconds, whatever the "days between messages"
	 * setting says (0 would otherwise let every run message the same people again).
	 */
	const MIN_RESEND = 86400;

	/* ------------------------------------------------------------- when to send */

	/** Hour (0-23) of a timestamp in the given timezone. */
	public static function hour_in_tz( $ts, DateTimeZone $tz ) {
		$d = new DateTime( '@' . (int) $ts );
		$d->setTimezone( $tz );
		return (int) $d->format( 'G' );
	}

	/** Same semantics as the original setting: from <= hour < to. A window that wraps midnight is not supported. */
	public static function in_window( $hour, $from, $to ) {
		return (int) $hour >= (int) $from && (int) $hour < (int) $to;
	}

	/**
	 * May this campaign run right now?
	 *
	 * @param string $mode               off | manual | auto
	 * @param string $trigger            cron | manual
	 * @param bool   $in_window          inside the allowed sending hours
	 * @param bool   $override_requested the person asked to send outside the hours
	 * @param bool   $override_allowed   that person is permitted to do so
	 * @return array allowed (bool), code (string), override (bool: the window was deliberately bypassed)
	 */
	public static function gate( $mode, $trigger, $in_window, $override_requested = false, $override_allowed = false ) {
		$deny = function ( $code ) {
			return array( 'allowed' => false, 'code' => $code, 'override' => false );
		};
		if ( ! in_array( $mode, array( 'manual', 'auto' ), true ) ) {
			return $deny( 'mode_off' );
		}
		if ( 'manual' !== $trigger ) {
			// Anything that is not a person pressing the button is treated like the scheduler: strictest rules.
			if ( 'auto' !== $mode ) {
				return $deny( 'not_auto' );
			}
			return $in_window ? array( 'allowed' => true, 'code' => 'ok', 'override' => false ) : $deny( 'outside_window' );
		}
		if ( $in_window ) {
			return array( 'allowed' => true, 'code' => 'ok', 'override' => false );
		}
		if ( ! $override_requested ) {
			return $deny( 'outside_window' );
		}
		if ( ! $override_allowed ) {
			return $deny( 'override_forbidden' );
		}
		return array( 'allowed' => true, 'code' => 'ok', 'override' => true );
	}

	/**
	 * May ONE promotional SMS to a single customer (call-list buttons) be sent right now?
	 *
	 * Same sending hours as campaigns, but there is no campaign mode to check and, by design, no override:
	 * the manual override exists for a deliberate bulk run, not for a single click. This is only for the plugin's
	 * own promotional texts (the two win-back templates in Settings); order/transactional SMS are sent by the
	 * WooCommerce SMS plugin and never pass through here, so they are not restricted by it.
	 *
	 * @return array allowed (bool), code (string: ok | outside_window), override (always false)
	 */
	public static function gate_one_off( $in_window ) {
		return self::gate( 'manual', 'manual', (bool) $in_window, false, false );
	}

	public static function gate_message( $code ) {
		$m = array(
			'mode_off'           => 'این کمپین غیرفعال است. برای اجرا، حالت آن را روی «دستی» یا «خودکار» بگذارید.',
			'not_auto'           => 'اجرای زمان‌بندی‌شده فقط برای کمپین‌های «خودکار» انجام می‌شود.',
			'outside_window'     => 'الان خارج از ساعت مجاز ارسال است (از «تنظیمات» قابل تغییر).',
			'override_forbidden' => 'ارسال خارج از ساعت مجاز فقط برای مدیر سایت امکان‌پذیر است.',
		);
		return isset( $m[ $code ] ) ? $m[ $code ] : '';
	}

	/* ------------------------------------------------- reading the gateway's answer */

	/** Kavenegar status codes that mean "nothing was sent" (documented at kavenegar.com/rest.html). */
	public static function definite_failure_codes() {
		return array( 400, 401, 402, 403, 411, 412, 413, 414, 417, 418, 424, 426, 428, 431, 432, 607 );
	}

	/**
	 * Did a failed HTTP call possibly reach the gateway?
	 *   definite  : the request never left (could not resolve/connect/TLS) -> safe to treat as not sent
	 *   ambiguous : it may have been delivered (timeout while waiting, connection dropped, ...) -> do NOT retry
	 *
	 * @param string $message WP_Error message
	 * @param string $code    WP_Error code
	 */
	public static function classify_transport_error( $message, $code = '' ) {
		if ( 'http_request_not_executed' === $code ) {
			return 'definite'; // blocked by WP_HTTP_BLOCK_EXTERNAL or a filter before any network I/O.
		}
		if ( preg_match( '/cURL error (\d+)/i', (string) $message, $m ) ) {
			$n = (int) $m[1];
			// 1 protocol, 3 URL, 5/6 proxy/host not resolved, 7 could not connect, 35 TLS handshake,
			// 51/58/59/60/64/66/77/83/90 TLS certificate or cipher problems: all happen before the request is sent.
			if ( in_array( $n, array( 1, 2, 3, 5, 6, 7, 35, 51, 58, 59, 60, 64, 66, 77, 83, 90 ), true ) ) {
				return 'definite';
			}
			if ( 28 === $n && preg_match( '/connection timed out|resolving timed out|ssl connection timeout/i', (string) $message ) && ! preg_match( '/operation timed out/i', (string) $message ) ) {
				return 'definite'; // timed out while still connecting.
			}
		}
		return 'ambiguous';
	}

	/**
	 * Interpret an HTTP answer from Kavenegar.
	 *
	 * @return array state (sent|failed|unknown), code (int), message (string)
	 */
	public static function classify_response( $http_code, $body ) {
		$json   = json_decode( (string) $body, true );
		$status = ( is_array( $json ) && isset( $json['return']['status'] ) ) ? (int) $json['return']['status'] : 0;
		$msg    = ( is_array( $json ) && isset( $json['return']['message'] ) ) ? (string) $json['return']['message'] : '';
		if ( 200 === (int) $http_code && 200 === $status ) {
			return array( 'state' => 'sent', 'code' => 200, 'message' => $msg );
		}
		if ( $status && in_array( $status, self::definite_failure_codes(), true ) ) {
			return array( 'state' => 'failed', 'code' => $status, 'message' => $msg );
		}
		// 5xx, redirects, unreadable body, an unknown status: the gateway may or may not have accepted it.
		return array( 'state' => 'unknown', 'code' => $status, 'message' => $msg );
	}

	/**
	 * Numeric id for Kavenegar's `localid`: milliseconds + 6 random digits (19 digits, fits a signed 64-bit
	 * integer). Kavenegar documents it as unique per ACCOUNT, so a per-site counter would collide when two
	 * shops share one account. Built as a string to stay safe on 32-bit PHP.
	 */
	public static function localid( $ms = null, $rand = null ) {
		$ms   = null === $ms ? (int) round( microtime( true ) * 1000 ) : (int) $ms;
		$rand = null === $rand ? random_int( 0, 999999 ) : (int) $rand;
		return (string) $ms . sprintf( '%06d', $rand );
	}
}
