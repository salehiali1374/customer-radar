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

	/**
	 * A customer whose message cannot be BUILT (a value is missing, the discount code could not be saved, the text
	 * is empty) gets status 'nobuild': nothing was sent and it is not a contact. The campaign leaves that customer
	 * alone for this long, so the same people do not sit at the top of every run and starve the rest. Saving the
	 * campaign or the settings (see CRDR_Send::build_epoch_bump) lifts it at once.
	 */
	const BUILD_BACKOFF = 21600;

	/** A run stops after this many build failures in a row (no success in between): the cause is probably general. */
	const BUILD_FAIL_STREAK = 10;

	/** Statuses that never count as "customer was contacted" (cooldown, daily cap, audience exclusion). */
	const NOT_CONTACT = array( 'failed', 'nobuild' );

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

	/** Set-aside rows newer than this still count. @param int $epoch time of the last settings/campaign change */
	public static function build_backoff_since( $now, $epoch ) {
		return max( (int) $now - self::BUILD_BACKOFF, (int) $epoch );
	}

	/* ------------------------------------------------------------ run log retention */

	/*
	 * The run log records who ran what. Its entries are not equally important, so each class has its own cap and
	 * routine entries can never push an important one out:
	 *   override : a person bypassed the sending hours (the security audit trail)
	 *   action   : any other manual click or run (who did what)
	 *   event    : an automatic campaign changed state (blocked, other reason, recovered)
	 *   routine  : an ordinary cron tick that ran
	 * People and cron write to different options (log_store), so one can never overwrite the other's entries.
	 */

	public static function log_caps() {
		return array( 'override' => 300, 'action' => 100, 'event' => 60, 'routine' => 50 );
	}

	public static function log_class( array $e ) {
		if ( ! empty( $e['override'] ) ) {
			return 'override';
		}
		$trigger = isset( $e['trigger'] ) ? $e['trigger'] : '';
		if ( 'cron' === $trigger ) {
			return ( isset( $e['result'] ) && 'ran' === $e['result'] ) ? 'routine' : 'event';
		}
		return 'action'; // manual, and anything unexpected: never treated as expendable.
	}

	/** Which option holds this class: 'run' (written by people) or 'cron' (written by the scheduler). */
	public static function log_store( $class ) {
		return in_array( $class, array( 'override', 'action' ), true ) ? 'run' : 'cron';
	}

	/** Keep only known fields: a log entry never carries phone numbers, message text, keys or other free text. */
	public static function log_clean( array $e ) {
		$out  = array();
		$text = array( 'run' => 32, 'playbook' => 64, 'trigger' => 10, 'mode' => 10, 'result' => 60 );
		foreach ( array( 'run', 'ts', 'playbook', 'trigger', 'user', 'mode', 'in_window', 'override', 'result', 'counts' ) as $k ) {
			if ( ! array_key_exists( $k, $e ) ) {
				continue;
			}
			if ( isset( $text[ $k ] ) ) {
				$out[ $k ] = substr( (string) $e[ $k ], 0, $text[ $k ] );
			} elseif ( 'ts' === $k || 'user' === $k ) {
				$out[ $k ] = (int) $e[ $k ];
			} elseif ( 'counts' === $k ) {
				$c = array();
				foreach ( array( 'sent', 'held_out', 'skipped', 'failed', 'unknown' ) as $ck ) {
					if ( is_array( $e['counts'] ) && isset( $e['counts'][ $ck ] ) ) {
						$c[ $ck ] = (int) $e['counts'][ $ck ];
					}
				}
				$out[ $k ] = $c;
			} else {
				$out[ $k ] = (bool) $e[ $k ];
			}
		}
		return $out;
	}

	/**
	 * Apply the per-class caps, oldest entries of a full class first. Order is preserved.
	 *
	 * @return array log (list), evicted (class => number of entries dropped)
	 */
	public static function log_trim( array $log ) {
		$caps    = self::log_caps();
		$seen    = array();
		$kept    = array();
		$evicted = array();
		for ( $i = count( $log ) - 1; $i >= 0; $i-- ) { // newest first
			if ( ! is_array( $log[ $i ] ) ) {
				continue;
			}
			$c          = self::log_class( $log[ $i ] );
			$seen[ $c ] = isset( $seen[ $c ] ) ? $seen[ $c ] + 1 : 1;
			if ( $seen[ $c ] > $caps[ $c ] ) {
				$evicted[ $c ] = isset( $evicted[ $c ] ) ? $evicted[ $c ] + 1 : 1;
				continue;
			}
			$kept[] = $log[ $i ];
		}
		return array( 'log' => array_reverse( $kept ), 'evicted' => $evicted );
	}

	/** Several stores read as one list, oldest first. */
	public static function log_merge( array $a, array $b ) {
		$all = array_merge( $a, $b );
		usort( $all, function ( $x, $y ) {
			return (int) ( isset( $x['ts'] ) ? $x['ts'] : 0 ) - (int) ( isset( $y['ts'] ) ? $y['ts'] : 0 );
		} );
		return $all;
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
