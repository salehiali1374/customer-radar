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

	/**
	 * Statuses that never count as "customer was contacted" (cooldown, daily cap, audience exclusion). All of them mean
	 * "nothing was sent":
	 *   failed   nobody is at fault (network, credit, key): retried at the next run
	 *   nobuild  the message could not be built, or the provider rejected the text: set aside until saved / 6 h
	 *   retry    the provider failed for a reason it does not explain: set aside until saved / 6 h
	 *   rejected the provider says the RECIPIENT is invalid: set aside until a person asks again / 30 days
	 */
	const NOT_CONTACT = array( 'failed', 'nobuild', 'retry', 'rejected' );

	/** How long a recipient the provider rejected (411) is left alone by that campaign, unless someone lifts it earlier. */
	const REJECT_BACKOFF = 2592000;

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

	/**
	 * What a stored contact status MEANS, in words the data can back up. This is the one place the admin pages take their
	 * wording from, so no page can claim more than is known.
	 *
	 * The plugin has no delivery receipts (no status polling, no webhook), so no outcome is ever "delivered":
	 * 'ok' only means the SMS service ACCEPTED the request (for Kavenegar: status 200, queued for sending).
	 *
	 * @return array group    attempting | accepted | unknown | failed | rejected | control | skipped | other
	 *               label    short Persian name
	 *               help     one Persian sentence for a store owner
	 *               delivered always false (not established by anything we store)
	 *               contact  counts as "customer was contacted" (cooldown, audience exclusion)
	 *               cap      uses up the daily cap (the SMS may have gone out)
	 *               measured is part of the campaign result comparison
	 */
	public static function outcome( $status ) {
		$m = array(
			'pending'  => array( 'attempting', 'در حال ارسال', 'پیامک در حال سپردن به سرویس پیامک است و نتیجه‌اش هنوز ثبت نشده.' ),
			'ok'       => array( 'accepted', 'پذیرفته‌شده توسط سرویس پیامک', 'سرویس پیامک آن را پذیرفت و در صف ارسال گذاشت. این افزونه رسید تحویل ندارد، پس رسیدن آن به گوشی مشتری تأیید نشده است.' ),
			'unknown'  => array( 'unknown', 'نتیجه نامشخص', 'پاسخ سرویس پیامک نرسید؛ ممکن است پیامک رفته باشد و ممکن است نرفته باشد. برای جلوگیری از پیام تکراری دوباره فرستاده نمی‌شود و مشتری تا پایان دوره‌ی استراحت پیامی نمی‌گیرد. وضعیت را در پنل سرویس پیامک ببینید.' ),
			'failed'   => array( 'failed', 'ارسال نشد', 'پیامکی نرفت (مشکل شبکه یا حساب پیامک)؛ در اجرای بعدی دوباره امتحان می‌شود.' ),
			'retry'    => array( 'failed', 'ارسال نشد (علت نامشخص)', 'پیامکی نرفت و سرویس پیامک علتش را نگفت؛ چند ساعت بعد، یا پس از ذخیره‌ی کمپین یا تنظیمات، دوباره امتحان می‌شود.' ),
			'nobuild'  => array( 'failed', 'پیام ساخته یا پذیرفته نشد', 'پیامکی نرفت؛ متن برای این مشتری ساخته نشد یا سرویس پیامک متن را نپذیرفت.' ),
			'rejected' => array( 'rejected', 'شماره رد شد', 'سرویس پیامک شماره‌ی این مشتری را نپذیرفت و پیامکی نرفت.' ),
			'holdout'  => array( 'control', 'گروه شاهد', 'عمداً پیامی برای این مشتری فرستاده نشد تا اثر پیام سنجیده شود.' ),
			'norec'    => array( 'skipped', 'رد شد (محصول مکمل پیدا نشد)', 'برای این مشتری محصول مکمل مناسبی پیدا نشد و پیامی نرفت.' ),
		);
		$s = (string) $status;
		if ( ! isset( $m[ $s ] ) ) {
			return array( 'group' => 'other', 'label' => 'وضعیت نامشخص', 'help' => 'وضعیت این ردیف شناخته‌شده نیست.', 'delivered' => false, 'contact' => true, 'cap' => false, 'measured' => false );
		}
		return array(
			'group'     => $m[ $s ][0],
			'label'     => $m[ $s ][1],
			'help'      => $m[ $s ][2],
			'delivered' => false,
			'contact'   => ! in_array( $s, self::NOT_CONTACT, true ),
			'cap'       => in_array( $s, array( 'pending', 'ok', 'unknown' ), true ),
			'measured'  => in_array( $s, array( 'ok', 'holdout' ), true ),
		);
	}

	/** Rejected-recipient rows newer than this still count. @param int $epoch time someone pressed "try again" for the campaign */
	public static function reject_backoff_since( $now, $epoch ) {
		return max( (int) $now - self::REJECT_BACKOFF, (int) $epoch );
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

	/**
	 * WHO a Kavenegar status that means "nothing was sent" is about. Taken from the provider's own table
	 * (kavenegar.com/rest.html), not guessed:
	 *   recipient  411 the receptor number is not valid: this customer only, will not work next time either
	 *   message    413 text empty or too long, 422 unsuitable characters: this text, not the customer's number
	 *   fatal      account, key, credit, sender line, IP access, or a malformed request: it affects every customer
	 *   uncertain  402 "operation failed" without a reason: cannot tell whether it is about this customer
	 * '' = not in the table. 409 ("try later") is deliberately NOT here: it does not say the request was not
	 * processed, so it stays ambiguous (unknown = never retried), like any code that is not documented.
	 */
	public static function code_scope( $status ) {
		$map = array(
			411 => 'recipient',
			413 => 'message', 422 => 'message',
			400 => 'fatal', 401 => 'fatal', 403 => 'fatal', 404 => 'fatal', 405 => 'fatal', 406 => 'fatal', 407 => 'fatal',
			412 => 'fatal', 414 => 'fatal', 417 => 'fatal', 418 => 'fatal', 424 => 'fatal', 426 => 'fatal', 428 => 'fatal',
			431 => 'fatal', 432 => 'fatal', 607 => 'fatal',
			402 => 'uncertain',
		);
		return isset( $map[ (int) $status ] ) ? $map[ (int) $status ] : '';
	}

	/** Kavenegar status codes that mean "nothing was sent". */
	public static function definite_failure_codes() {
		return array( 400, 401, 402, 403, 404, 405, 406, 407, 411, 412, 413, 414, 417, 418, 422, 424, 426, 428, 431, 432, 607 );
	}

	/** The contact status that records a definite failure of this scope (see NOT_CONTACT). */
	public static function failure_status( $scope ) {
		$map = array( 'recipient' => 'rejected', 'message' => 'nobuild', 'uncertain' => 'retry' );
		return isset( $map[ $scope ] ) ? $map[ $scope ] : 'failed';
	}

	/**
	 * What to tell the owner about a definite failure. Our own wording plus the code: the provider's text is never
	 * stored or shown (it could repeat a phone number or other details).
	 */
	public static function failure_reason( $status ) {
		$status = (int) $status;
		$m      = array(
			411 => 'شماره‌ی گیرنده از نظر سرویس پیامک معتبر نیست',
			413 => 'متن پیامک خالی است یا از حد مجاز طولانی‌تر است',
			422 => 'متن پیامک نویسه‌ی ناپذیرفتنی دارد',
			401 => 'حساب سرویس پیامک غیرفعال است',
			403 => 'کلید API یا حساب سرویس پیامک معتبر نیست',
			412 => 'شماره‌ی خط فرستنده معتبر نیست',
			407 => 'دسترسی مجاز نیست (آی‌پی سرور را در تنظیمات امن سرویس پیامک بررسی کنید)',
			418 => 'اعتبار حساب سرویس پیامک کافی نیست',
			402 => 'سرویس پیامک عملیات را انجام نداد و علتش را نگفت',
		);
		$why = isset( $m[ $status ] ) ? $m[ $status ] : 'سرویس پیامک درخواست را نپذیرفت';
		return $why . ' (کاوه‌نگار، کد ' . $status . ').';
	}

	/**
	 * Remove what must never be stored or shown from a text that came from outside: secrets (the API key) and
	 * Iranian mobile numbers in their usual spellings (also with Persian / Arabic digits).
	 */
	public static function redact( $text, array $secrets = array() ) {
		$text = (string) $text;
		foreach ( $secrets as $secret ) {
			$secret = (string) $secret;
			if ( strlen( $secret ) >= 4 ) {
				$text = str_replace( array( $secret, rawurlencode( $secret ) ), '***', $text );
			}
		}
		$d    = '[0-9\x{06F0}-\x{06F9}\x{0660}-\x{0669}]';
		$out  = preg_replace( '/(?<!' . $d . ')(?:(?:\+|00)?[9٩۹][8٨۸]|[0٠۰])?[9٩۹]' . $d . '{9}(?!' . $d . ')/u', '[شماره]', $text );
		return null === $out ? $text : $out;
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
	 * @return array state (sent|failed|unknown), code (int), message (string: the provider's own text, never stored),
	 *               scope (for a definite failure: recipient|message|fatal|uncertain, see code_scope())
	 */
	public static function classify_response( $http_code, $body ) {
		$json   = json_decode( (string) $body, true );
		$status = ( is_array( $json ) && isset( $json['return']['status'] ) ) ? (int) $json['return']['status'] : 0;
		$msg    = ( is_array( $json ) && isset( $json['return']['message'] ) ) ? (string) $json['return']['message'] : '';
		if ( 200 === (int) $http_code && 200 === $status ) {
			return array( 'state' => 'sent', 'code' => 200, 'message' => $msg, 'scope' => '' );
		}
		if ( $status && in_array( $status, self::definite_failure_codes(), true ) ) {
			return array( 'state' => 'failed', 'code' => $status, 'message' => $msg, 'scope' => self::code_scope( $status ) );
		}
		// 5xx, redirects, unreadable body, a status that is not in the provider's table (or says only "try later"):
		// the gateway may or may not have accepted it.
		return array( 'state' => 'unknown', 'code' => $status, 'message' => $msg, 'scope' => '' );
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
