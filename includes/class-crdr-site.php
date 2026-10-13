<?php
defined( 'ABSPATH' ) || defined( 'CRDR_TESTING' ) || exit;

/**
 * Which site is this? A guard against a copy of the database running the real shop's automatic campaigns (Phase 4.2).
 *
 * The risk: a staging or local copy made from a backup carries the plugin's tables, its settings with the SMS provider's API key and an
 * "automatic" campaign, and WP-Cron on that copy would send real SMS to real customers every hour.
 *
 * What is stored: the site's own address, normalised (normalize()), in the option crdr_site_identity, with who/when. It is written
 *   - when the plugin is installed or updated on a site that has none (CRDR_DB::install()), and
 *   - when an administrator confirms a new address (confirm(), after a legitimate move).
 * It is never rewritten by anything else: not by a request, not by a mismatch, not by cron.
 *
 * What a mismatch does: automatic (cron) campaign runs are refused (CRDR_Playbooks::run / hourly) and an administrator notice says what happened
 * and offers the confirmation. An identity that was never recorded counts as a mismatch ("unconfirmed"): the safe side.
 *
 * MANUAL sends (a campaign's "run now" and the one-off SMS to one customer) on a site that is not the recorded one need three things, all checked
 * on the SERVER (a browser confirm() is not authorisation):
 *   1. the capability `manage_options` (an Administrator; a shop manager is refused),
 *   2. the plugin's AJAX nonce (CRDR_Actions::guard, as for every handler), and
 *   3. an ACKNOWLEDGEMENT TOKEN that the server issued to this user, for this one campaign or customer, when it showed them the preview that carries the
 *      warning (issue_ack()). The token is random, stored only on the server (a hash of it is the key), valid for ACK_TTL seconds, bound to the user,
 *      the target and the site address, and SINGLE USE: it is deleted by the request that spends it (consume_ack(), one DELETE that either removes
 *      the row or does not), so a replay is refused. Nothing in the browser can create one. Cron never accepts one.
 * Every other guard stays exactly as it was (opt-out, schema, rebuild, sending hours, daily cap, ...). Recording an opt-out, a call or "not today"
 * is not a send and needs none of this. There is deliberately NO way to reset or bypass the identity: confirming the address is still only the
 * administrator's admin-post action below.
 *
 * Limits: the check compares addresses. A copy that keeps the same address (a local copy reached through the same name in the hosts file), and a
 * copy whose option was edited by hand, are not detected. The API key is not shown anywhere by this class. Multisite is not supported by the plugin;
 * the identity is the current site's own address.
 */
class CRDR_Site {

	const OPTION = 'crdr_site_identity';
	const ACTION = 'crdr_confirm_site';

	const ACK_PREFIX = 'crdr_ack_'; // option_name prefix of the one-time acknowledgement rows (removed by CRDR_Uninstall)
	const ACK_TTL    = 600;         // seconds
	const ACK_MAX    = 30;          // rows kept at most (a preview that is never followed by a send leaves one behind until it expires)

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_confirm' ) );
	}

	/**
	 * A site address reduced to what identifies the site: host (lower case, without "www."), non-default port, path (without a trailing slash).
	 * The scheme is ignored on purpose (switching a site to https is not a move); query and fragment are dropped.
	 *
	 * @return string e.g. "example.com/shop"; '' when there is nothing usable
	 */
	public static function normalize( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( false === strpos( $url, '://' ) ) {
			$url = 'http://' . ltrim( $url, '/' );
		}
		$p = parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return strtolower( rtrim( preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', $url ), '/' ) );
		}
		$host = strtolower( rtrim( $p['host'], '.' ) );
		if ( function_exists( 'idn_to_ascii' ) && preg_match( '/[^\x00-\x7F]/', $host ) ) {
			$ascii = @idn_to_ascii( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$host  = $ascii ? $ascii : $host;
		}
		$host = preg_replace( '/^www\./', '', $host );
		$port = isset( $p['port'] ) && ! in_array( (int) $p['port'], array( 80, 443 ), true ) ? ':' . (int) $p['port'] : '';
		$path = isset( $p['path'] ) ? preg_replace( '#/+#', '/', $p['path'] ) : '';
		$path = rtrim( $path, '/' );
		return $host . $port . $path;
	}

	/** This site's address right now, normalised. */
	public static function current() {
		return self::normalize( home_url() );
	}

	/** @return array|null the stored identity (url, set, by, how), or null when none was recorded */
	public static function stored() {
		$s = get_option( self::OPTION, false );
		return ( is_array( $s ) && isset( $s['url'] ) && is_string( $s['url'] ) && '' !== $s['url'] ) ? $s : null;
	}

	/**
	 * @return array ok (bool), code (ok | changed | unconfirmed), stored (string), current (string)
	 */
	public static function status() {
		$cur = self::current();
		$s   = self::stored();
		if ( null === $s ) {
			return array( 'ok' => false, 'code' => 'unconfirmed', 'stored' => '', 'current' => $cur );
		}
		if ( '' === $cur || $cur !== $s['url'] ) {
			return array( 'ok' => false, 'code' => 'changed', 'stored' => $s['url'], 'current' => $cur );
		}
		return array( 'ok' => true, 'code' => 'ok', 'stored' => $s['url'], 'current' => $cur );
	}

	/** May AUTOMATIC campaigns run on this site? */
	public static function automatic_allowed() {
		$st = self::status();
		return $st['ok'];
	}

	/**
	 * Record the identity if there is none. Called by the installer. Never replaces an existing identity.
	 *
	 * @return bool whether it was written
	 */
	public static function record_if_absent() {
		if ( null !== self::stored() ) {
			return false;
		}
		$cur = self::current();
		if ( '' === $cur ) {
			return false;
		}
		update_option( self::OPTION, array( 'url' => $cur, 'set' => time(), 'by' => (int) get_current_user_id(), 'how' => 'install' ), false );
		return true;
	}

	/** The administrator's deliberate act: this site now IS the recorded one. */
	public static function confirm( $user_id ) {
		$cur = self::current();
		if ( '' === $cur ) {
			return false;
		}
		$old = self::stored();
		update_option( self::OPTION, array( 'url' => $cur, 'set' => time(), 'by' => (int) $user_id, 'how' => 'confirmed', 'was' => $old ? $old['url'] : '' ), false );
		CRDR_Health::clear_code_all( 'site_changed' );
		return true;
	}

	/* ------------------------------------------------ acknowledgement of a manual send from an unconfirmed site */

	private static function ack_name( $token ) {
		return self::ACK_PREFIX . substr( hash( 'sha256', (string) $token ), 0, 40 );
	}

	/** A token as the browser may send it: 32 hex characters or nothing. */
	public static function clean_token( $raw ) {
		$raw = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		return preg_match( '/^[a-f0-9]{32}$/', $raw ) ? $raw : '';
	}

	/**
	 * Hand the current user a one-time token for $scope ('pb:<campaign>' or 'c:<customer identity>'), or '' when none is needed or allowed:
	 * the site is the recorded one (nothing to acknowledge), or the user is not an Administrator, or the token could not be stored.
	 */
	public static function issue_ack( $scope ) {
		global $wpdb;
		if ( self::status()['ok'] || ! current_user_can( 'manage_options' ) || '' === (string) $scope ) {
			return '';
		}
		$uid = (int) get_current_user_id();
		if ( $uid < 1 ) {
			return '';
		}
		// housekeeping first: expired rows, this user's earlier token for the same target, and a hard ceiling
		$like = $wpdb->esc_like( self::ACK_PREFIX ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$keep = array();
		foreach ( (array) $rows as $r ) {
			$v = json_decode( (string) $r['option_value'], true );
			if ( ! is_array( $v ) || ! isset( $v['e'] ) || (int) $v['e'] < time() || ( (int) $v['u'] === $uid && (string) $v['s'] === (string) $scope ) ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $r['option_name'] ) );
			} else {
				$keep[] = $r['option_name'];
			}
		}
		while ( count( $keep ) >= self::ACK_MAX ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => array_shift( $keep ) ) );
		}
		$token = bin2hex( random_bytes( 16 ) );
		$ok    = $wpdb->insert( $wpdb->options, array(
			'option_name'  => self::ack_name( $token ),
			'option_value' => wp_json_encode( array( 'u' => $uid, 's' => (string) $scope, 'a' => self::current(), 'e' => time() + self::ACK_TTL ) ),
			'autoload'     => 'no',
		) );
		return $ok ? $token : '';
	}

	/** Is $token (not spent, not expired) this user's acknowledgement for $scope on this address? Reads only. */
	private static function ack_row( $scope, $token ) {
		global $wpdb;
		$token = self::clean_token( $token );
		if ( '' === $token || '' === (string) $scope ) {
			return false;
		}
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::ack_name( $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$v   = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $v ) || ! isset( $v['u'], $v['s'], $v['a'], $v['e'] ) ) {
			return false;
		}
		$uid = (int) get_current_user_id();
		return $uid > 0 && (int) $v['u'] === $uid && (string) $v['s'] === (string) $scope && (string) $v['a'] === self::current() && (int) $v['e'] >= time();
	}

	/**
	 * May the current user send a MANUAL SMS from this site, for $scope, with $token? Reads only (nothing is spent), so a send that is refused
	 * for another reason later (sending hours, opt-out, ...) does not use the token up. Spend it with consume_ack() right before sending.
	 *
	 * @return string '' = allowed; otherwise the Persian reason
	 */
	public static function manual_block( $scope, $token ) {
		if ( self::status()['ok'] ) {
			return '';
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return 'نشانی این سایت با سایت اصلیِ ثبت‌شده‌ی رادار مشتری یکی نیست (یا هنوز تأیید نشده است)، پس ارسال پیامک از اینجا فقط برای «مدیر سایت» (Administrator) ممکن است. از مدیر سایت بخواهید نشانی را تأیید کند یا ارسال را انجام دهد.';
		}
		if ( ! self::ack_row( $scope, $token ) ) {
			return 'تأییدیه‌ی ارسال از این سایت معتبر نیست، منقضی شده یا قبلاً استفاده شده است. پیش‌نمایش را دوباره باز کنید و هشدار نشانی سایت را تأیید کنید. پیامکی فرستاده نشد.';
		}
		return '';
	}

	/**
	 * Spend the token: true when the site is the recorded one (no token is needed) or when THIS call removed the row. Of two requests that carry
	 * the same token at the same moment exactly one gets true (one DELETE statement).
	 */
	public static function consume_ack( $scope, $token ) {
		global $wpdb;
		if ( self::status()['ok'] ) {
			return true;
		}
		if ( '' !== self::manual_block( $scope, $token ) ) {
			return false;
		}
		return 1 === (int) $wpdb->delete( $wpdb->options, array( 'option_name' => self::ack_name( self::clean_token( $token ) ) ) );
	}

	/**
	 * For a preview screen: why a manual send is not offered at all, and the token that lets an Administrator go on.
	 *
	 * @return array blocked (string, '' = none), ack (string token, '' = none)
	 */
	public static function preview_ack( $scope ) {
		if ( self::status()['ok'] ) {
			return array( 'blocked' => '', 'ack' => '' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'blocked' => self::manual_block( $scope, '' ), 'ack' => '' );
		}
		$t = self::issue_ack( $scope );
		return array( 'blocked' => '' === $t ? 'تأییدیه‌ی ارسال ساخته نشد؛ دوباره امتحان کنید.' : '', 'ack' => $t );
	}

	/** Persian explanation for a status that is not ok. Addresses only; never a key or any other setting. */
	public static function message( array $st ) {
		if ( 'unconfirmed' === $st['code'] ) {
			return 'نشانی این سایت هنوز در رادار مشتری ثبت و تأیید نشده است؛ برای جلوگیری از ارسال خودکار پیامک از یک نسخه‌ی کپی، اجرای خودکار کمپین‌ها متوقف است.';
		}
		return 'نشانی این سایت (' . $st['current'] . ') با نشانی ثبت‌شده‌ی رادار مشتری (' . $st['stored'] . ') یکی نیست؛ ممکن است این سایت کپی یا محیط آزمایشی سایت اصلی باشد یا سایت منتقل شده باشد. برای جلوگیری از ارسال خودکار پیامک به مشتریان واقعی، اجرای خودکار کمپین‌ها متوقف است.';
	}

	/** One line for the manual-run screens ('' when the site is the recorded one). */
	public static function warning() {
		$st = self::status();
		return $st['ok'] ? '' : self::message( $st ) . ( current_user_can( 'manage_options' ) ? ' (ارسال دستی فقط با تأیید شما ممکن است؛ مطمئن شوید که قصد ارسال از این سایت را دارید.)' : ' (ارسال پیامک از این سایت فقط برای مدیر سایت ممکن است.)' );
	}

	/** The administrator notice (with the confirmation form for administrators). '' when there is nothing to say. */
	public static function notice_html() {
		$st = self::status();
		if ( $st['ok'] || ! current_user_can( CRDR_Actions::CAP ) ) {
			return '';
		}
		$html = '<p><strong>' . esc_html( self::message( $st ) ) . '</strong></p>';
		if ( current_user_can( 'manage_options' ) ) {
			$html .= '<p>' . esc_html( 'اگر این همان سایت اصلی است که به نشانی جدید منتقل شده، آن را تأیید کنید. اگر این یک کپی یا سایت آزمایشی است، هیچ کاری نکنید: اجرای خودکار متوقف می‌ماند. با تأیید، از نشانی ' . $st['current'] . ' به‌عنوان سایت اصلی استفاده می‌شود و اجرای خودکار کمپین‌ها دوباره فعال می‌شود.' ) . '</p>';
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
				. wp_nonce_field( self::ACTION, '_wpnonce', true, false )
				. '<p><button type="submit" class="button button-primary">' . esc_html( 'تأیید: این سایت اصلی است' ) . '</button></p></form>';
		} else {
			$html .= '<p>' . esc_html( 'تأیید نشانی فقط برای «مدیر سایت» (Administrator) ممکن است.' ) . '</p>';
		}
		return '<div class="notice notice-error">' . $html . '</div>';
	}

	/** admin-post handler: POST, logged in, administrator, valid nonce. */
	public static function handle_confirm() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( 'درخواست نامعتبر است.', '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'تأیید نشانی سایت فقط برای مدیر سایت ممکن است.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$ok = self::confirm( get_current_user_id() );
		wp_safe_redirect( add_query_arg( array( 'page' => 'customer-radar', 'site' => $ok ? 'confirmed' : 'failed' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
