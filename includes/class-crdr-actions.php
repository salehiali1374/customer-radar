<?php
defined( 'ABSPATH' ) || exit;

/**
 * AJAX and admin-post handlers: sync, manual contact, campaigns, export, settings, bundle coupon.
 */
class CRDR_Actions {

	const NONCE = 'crdr_nonce';
	const CAP   = 'manage_woocommerce';

	/** The CSV export's last column: filled for customers who asked not to be messaged (never use such a row for SMS). */
	const EXPORT_OPTOUT_HEADER = 'عدم تمایل به پیام (نباید پیامک شود)';
	const EXPORT_OPTOUT_YES    = 'بله';

	public static function init() {
		$ajax = array( 'sync_start', 'sync_step', 'preview', 'contact', 'pb_preview', 'pb_run', 'pb_mode' );
		foreach ( $ajax as $a ) {
			add_action( 'wp_ajax_crdr_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		$post = array( 'export', 'save_settings', 'pb_save', 'pb_create', 'pb_delete', 'bundle_coupon', 'retry_rejected' );
		foreach ( $post as $a ) {
			add_action( 'admin_post_crdr_' . $a, array( __CLASS__, 'post_' . $a ) );
		}
	}

	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی کافی ندارید.' ), 403 );
		}
	}

	/**
	 * Customer data is being rebuilt (or a rebuild stopped half way): answer an AJAX call with the reason and stop. Called by
	 * every handler that reads the customer table or can send an SMS; the browser is never trusted to hide a button.
	 */
	private static function sync_block_ajax() {
		$sync = CRDR_Sync::guard();
		if ( $sync['blocked'] ) {
			wp_send_json_error( array( 'message' => $sync['message'], 'code' => $sync['code'] ), 409 );
		}
	}

	/** SMS paths only (recording an opt-out, a call or "not today" must keep working): no update is waiting for its first rebuild. */
	private static function send_block_ajax() {
		$sync = CRDR_Sync::send_guard();
		if ( $sync['blocked'] ) {
			wp_send_json_error( array( 'message' => $sync['message'], 'code' => $sync['code'] ), 409 );
		}
	}

	private static function guard_post() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'دسترسی کافی ندارید.', 403 );
		}
		check_admin_referer( self::NONCE );
	}

	private static function back( $page, array $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ----------------------------------------------------------------- sync */

	public static function ajax_sync_start() {
		self::guard();
		wp_send_json_success( CRDR_Sync::start() );
	}

	public static function ajax_sync_step() {
		self::guard();
		wp_send_json_success( CRDR_Sync::step() );
	}

	/* ------------------------------------------------------------ templates */

	public static function customer( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CRDR_DB::customers() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * A stable, non-secret-looking token for "this customer" that survives a rebuild (the row id does not: every rebuild hands the
	 * ids out again). It is derived from the customer key (phone / e-mail / user / order), keyed with the site's salt so that the
	 * page does not carry a phone number or an e-mail in an attribute, and so that nobody can compute it without the salt.
	 */
	public static function identity( $ckey ) {
		return substr( hash_hmac( 'sha256', 'crdr-customer|' . (string) $ckey, wp_salt( 'auth' ) ), 0, 24 );
	}

	/** The attributes a customer row carries in the page: the (transient) row id and the stable identity. */
	public static function row_attrs( array $customer ) {
		return ' data-id="' . (int) $customer['id'] . '" data-k="' . esc_attr( self::identity( $customer['ckey'] ) ) . '"';
	}

	/**
	 * The page that sent this request was built from a customer list that may be older than the current one. The row id alone
	 * cannot be trusted: after a rebuild it may belong to somebody else. The browser sends the identity it was given with the id
	 * (POST k); it is compared here with the identity of the row that id points to NOW. Anything but an exact match is refused,
	 * before anything is claimed, created or sent. The client's value is never used to find or choose a customer.
	 *
	 * @param array|null $cust the row found by id (or null)
	 */
	private static function require_same_customer( $cust ) {
		$sent = isset( $_POST['k'] ) ? sanitize_text_field( wp_unslash( $_POST['k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard()
		if ( ! $cust || '' === $sent || ! hash_equals( self::identity( $cust['ckey'] ), $sent ) ) {
			wp_send_json_error( array(
				'message' => 'فهرست مشتریان از زمانی که این صفحه را باز کرده‌اید تغییر کرده است؛ برای جلوگیری از اقدام روی مشتری اشتباه، کاری انجام نشد. صفحه را دوباره بارگذاری کنید.',
				'code'    => 'stale_customer',
			), 409 );
		}
	}

	public static function customer_by_key( $ckey ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $ckey ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Contacted recently (sms / call / skip) or opted out. Returns 'optout', 'dberror' (the database could not answer), true (cooldown) or false. */
	public static function in_cooldown( $ckey ) {
		$why = CRDR_DB::blocked_reason( $ckey );
		return in_array( $why, array( 'optout', 'dberror' ), true ) ? $why : ( '' !== $why );
	}

	/**
	 * Fill a template for one customer and report whether every placeholder got a value.
	 * Use this wherever a message is about to be SENT: render['ok'] false means do not send.
	 */
	public static function render_checked( $tpl, array $customer, array $extra = array() ) {
		$name = trim( (string) $customer['name'] );
		$vars = array_merge( array(
			'{name}'   => '' !== $name ? $name : 'مشتری',
			'{store}'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{optout}' => (string) CRDR_Settings::get( 'optout_text' ),
		), $extra );
		// Every message this plugin sends is promotional: an empty opt-out phrase is "a value that could not be built", not "optional".
		return CRDR_Template::render( $tpl, $vars, array() );
	}

	/** Text only (kept for callers that just display a preview); never use it to decide what to send. */
	public static function render_template( $tpl, array $customer, array $extra = array() ) {
		$r = self::render_checked( $tpl, $customer, $extra );
		return $r['text'];
	}

	/** Create a single-use percentage coupon for this customer. Returns [code, expire_ts]. */
	public static function make_coupon( array $customer, $percent = null, $days = null ) {
		do {
			$code = 'RD' . strtoupper( wp_generate_password( 6, false, false ) );
		} while ( wc_get_coupon_id_by_code( $code ) );

		$percent = null === $percent ? (int) CRDR_Settings::get( 'coupon_percent' ) : (int) $percent;
		$days    = null === $days ? (int) CRDR_Settings::get( 'coupon_days' ) : (int) $days;
		$expire  = time() + $days * DAY_IN_SECONDS;
		$c       = new WC_Coupon();
		$c->set_code( $code );
		$c->set_discount_type( 'percent' );
		$c->set_amount( $percent );
		$c->set_date_expires( $expire );
		$c->set_usage_limit( 1 );
		$c->set_usage_limit_per_user( 1 );
		$min = (float) CRDR_Settings::get( 'coupon_min' );
		if ( $min > 0 ) {
			$c->set_minimum_amount( $min );
		}
		if ( ! empty( $customer['email'] ) ) {
			$c->set_email_restrictions( array( $customer['email'] ) );
		}
		$c->set_description( 'ساخته‌شده توسط رادار مشتری برای ' . $customer['ckey'] );
		$c->update_meta_data( '_crdr_ckey', $customer['ckey'] );
		if ( ! $c->save() ) {
			throw new RuntimeException( 'کد تخفیف ذخیره نشد.' ); // callers treat this as "nothing sent".
		}
		return array( $code, $expire );
	}

	/**
	 * Delete a discount code that was created for a message that was never sent. Only a code this plugin made (it carries the customer
	 * key it was made for), for that very customer, and never one that has been used.
	 *
	 * @return bool whether a coupon was deleted
	 */
	public static function discard_coupon( $code, $ckey ) {
		if ( ! class_exists( 'WC_Coupon' ) || '' === (string) $code ) {
			return false;
		}
		$id = wc_get_coupon_id_by_code( $code );
		if ( ! $id ) {
			return false;
		}
		$c = new WC_Coupon( $id );
		if ( (string) $c->get_meta( '_crdr_ckey' ) !== (string) $ckey || $c->get_usage_count() > 0 ) {
			return false;
		}
		return (bool) $c->delete( true );
	}

	/* -------------------------------------------------------- manual contact */

	/**
	 * Persian reason why a one-off promotional SMS may not be sent right now (outside the sending hours), or ''.
	 * Decided on the server with the same hours as campaigns (Settings); the browser only shows the answer.
	 */
	private static function one_off_window_block() {
		$gate = CRDR_Send_Rules::gate_one_off( CRDR_Playbooks::in_send_window() );
		if ( $gate['allowed'] ) {
			return '';
		}
		$from = (int) CRDR_Settings::get( 'send_from' );
		$to   = (int) CRDR_Settings::get( 'send_to' );
		$msg  = CRDR_Send_Rules::gate_message( $gate['code'] ) . ' ساعت مجاز فعلی: ' . CRDR_Jalali::digits( (string) $from ) . ' تا ' . CRDR_Jalali::digits( (string) $to ) . '.';
		if ( $from >= $to ) { // a window that wraps midnight is not supported, and then no hour is allowed.
			$msg .= ' توجه: ساعت شروع باید کوچک‌تر از ساعت پایان باشد (بازه‌ی شبانه مثل ۲۲ تا ۶ پشتیبانی نمی‌شود).';
		}
		return $msg;
	}

	/** What a site acknowledgement for one customer is bound to: the customer's identity token, not the row id (a rebuild hands ids out again). */
	private static function ack_scope( array $cust ) {
		return 'c:' . self::identity( $cust['ckey'] );
	}

	/** The acknowledgement token the browser sent (32 hex characters or ''). */
	private static function posted_ack() {
		return CRDR_Site::clean_token( isset( $_POST['ack'] ) ? wp_unslash( $_POST['ack'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	}

	public static function ajax_preview() {
		self::guard();
		self::sync_block_ajax();
		$cust = self::customer( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		self::require_same_customer( $cust );
		self::send_block_ajax(); // the preview is only the step before sending
		$closed = self::one_off_window_block(); // the preview is only the step before sending: say so now.
		if ( '' !== $closed ) {
			wp_send_json_error( array( 'message' => $closed ) );
		}
		$gone = CRDR_Settings::optout_errors( CRDR_Settings::all() );
		if ( $gone ) {
			wp_send_json_error( array( 'message' => $gone[0] ) );
		}
		$with_coupon = ! empty( $_POST['coupon'] );
		$tpl         = CRDR_Settings::get( $with_coupon ? 'sms_template_coupon' : 'sms_template' );
		$problems    = CRDR_Template::validate( $tpl, array( 'offer' => $with_coupon, 'require_optout' => true ) );
		if ( $problems ) {
			wp_send_json_error( array( 'message' => 'متن پیامک در «تنظیمات» معتبر نیست: ' . $problems[0] ) );
		}
		$extra = array();
		if ( $with_coupon ) {
			$extra = array(
				'{coupon}'   => 'XXXXXXXX',
				'{discount}' => CRDR_Jalali::digits( (string) CRDR_Settings::get( 'coupon_percent' ) ),
				'{expire}'   => CRDR_Jalali::date( time() + (int) CRDR_Settings::get( 'coupon_days' ) * DAY_IN_SECONDS ),
			);
		}
		// On a site that is not the recorded one the send needs an Administrator AND the one-time token issued here (CRDR_Site).
		$site = CRDR_Site::preview_ack( self::ack_scope( $cust ) );
		if ( '' !== $site['blocked'] ) {
			wp_send_json_error( array( 'message' => $site['blocked'], 'code' => 'site_unconfirmed' ), 403 );
		}
		wp_send_json_success( array(
			'message'  => self::render_template( $tpl, $cust, $extra ),
			'phone'    => $cust['phone'],
			'provider' => CRDR_SMS::provider_label(),
			'site_warning' => CRDR_Site::warning(),
			'site_ack' => $site['ack'],
		) );
	}

	public static function ajax_contact() {
		self::guard();
		// All types, not only SMS: the customer is found by row id, and the ids are handed out again by every rebuild, so
		// during one an id may point to somebody else. (Opting somebody out is retried a few minutes later.)
		self::sync_block_ajax();
		$cust = self::customer( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		self::require_same_customer( $cust ); // the row id may have been handed to somebody else by a rebuild since the page was drawn
		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';

		if ( 'called' === $type || 'skip' === $type ) {
			$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
			if ( ! CRDR_DB::add_contact( $cust['ckey'], $type, array( 'playbook' => 'manual', 'message' => $note ) ) ) {
				wp_send_json_error( array( 'message' => 'ثبت نشد (خطای پایگاه‌داده)؛ دوباره امتحان کنید.' ), 500 );
			}
			wp_send_json_success( array( 'message' => 'ثبت شد.' ) );
		}
		if ( 'optout' === $type ) {
			$failed = ! CRDR_DB::add_contact( $cust['ckey'], 'optout', array( 'playbook' => 'manual', 'message' => 'عدم تمایل به دریافت پیام' ) );
			// An opt-out belongs to the PERSON: also write it for every other key this customer was joined from (CRDR_Identity), so it
			// holds even if that key becomes a customer of its own again (a join repaired, or the newest key gone).
			$group = CRDR_Identity::group_of( $cust['ckey'], (array) get_option( CRDR_Sync::LINKS_OPTION, array() ) );
			$have  = CRDR_DB::optout_keys( $group );
			foreach ( $group as $k ) {
				if ( ! isset( $have[ $k ] ) && ! CRDR_DB::add_contact( $k, 'optout', array( 'playbook' => 'manual', 'message' => CRDR_DB::OPTOUT_COPY_NOTE ) ) ) {
					$failed = true;
				}
			}
			if ( $failed ) { // never answer "saved" for an opt-out that was not written (asking again only adds what is missing)
				wp_send_json_error( array( 'message' => 'ثبت کامل نشد (خطای پایگاه‌داده)؛ دوباره «پیام نده» را بزنید.' ), 500 );
			}
			wp_send_json_success( array( 'message' => 'از این پس برای این مشتری پیامی ارسال نمی‌شود.' ) );
		}
		if ( 'sms' !== $type && 'sms_coupon' !== $type ) {
			wp_send_json_error( array( 'message' => 'نوع اقدام نامعتبر است.' ) );
		}
		self::send_block_ajax();
		// A site that is not the recorded one (a copy, a staging site, a restored backup elsewhere): only an Administrator holding the token the
		// preview issued for THIS customer may send. A browser confirm() proves nothing; this is checked here (CRDR_Site).
		$site = CRDR_Site::manual_block( self::ack_scope( $cust ), self::posted_ack() );
		if ( '' !== $site ) {
			wp_send_json_error( array( 'message' => $site, 'code' => 'site_unconfirmed' ), 403 );
		}
		// Promotional SMS only (see CRDR_Send_Rules::gate_one_off): sending hours first, before anything is claimed or created.
		$closed = self::one_off_window_block();
		if ( '' !== $closed ) {
			wp_send_json_error( array( 'message' => $closed ) );
		}
		$cool = self::in_cooldown( $cust['ckey'] );
		if ( 'optout' === $cool ) {
			wp_send_json_error( array( 'message' => 'این مشتری درخواست نکردن پیام داده است.' ) );
		}
		if ( 'dberror' === $cool ) {
			wp_send_json_error( array( 'message' => CRDR_Health::message( 'db_error' ) ) );
		}
		if ( $cool ) {
			wp_send_json_error( array( 'message' => 'با این مشتری به‌تازگی تماس گرفته شده است.' ) );
		}

		$gone = CRDR_Settings::optout_errors( CRDR_Settings::all() );
		if ( $gone ) { // nothing is claimed, no coupon is made
			wp_send_json_error( array( 'message' => $gone[0] . ' پیامکی فرستاده نشد.' ) );
		}
		$with_coupon = 'sms_coupon' === $type;
		$tpl         = CRDR_Settings::get( $with_coupon ? 'sms_template_coupon' : 'sms_template' );
		$problems    = CRDR_Template::validate( $tpl, array( 'offer' => $with_coupon, 'require_optout' => true ) );
		if ( $problems ) { // nothing is claimed, no coupon is created, nothing is sent.
			wp_send_json_error( array( 'message' => 'متن پیامک در «تنظیمات» معتبر نیست: ' . $problems[0] ) );
		}

		// Every other check has passed: spend the token (one DELETE; of two requests with the same token only one continues).
		if ( ! CRDR_Site::consume_ack( self::ack_scope( $cust ), self::posted_ack() ) ) {
			wp_send_json_error( array( 'message' => CRDR_Site::manual_block( self::ack_scope( $cust ), self::posted_ack() ) ?: 'تأییدیه‌ی ارسال معتبر نیست؛ پیامکی فرستاده نشد.', 'code' => 'site_unconfirmed' ), 403 );
		}
		// Claim the customer first: a double click or a campaign running at the same moment cannot send twice.
		$claim = CRDR_Send::claim( $cust['ckey'], 'manual', bin2hex( random_bytes( 8 ) ) );
		if ( isset( $claim['skip'] ) ) {
			$why = array(
				'busy'     => 'ارسال برای این مشتری هم‌اکنون در حال انجام است.',
				'syncing'  => 'داده‌های مشتریان در حال به‌روزرسانی است؛ کمی بعد دوباره امتحان کنید.',
				'optout'   => 'این مشتری درخواست نکردن پیام داده است.',
				'cooldown' => 'با این مشتری به‌تازگی تماس گرفته شده است.',
				'error'    => 'ثبت وضعیت ارسال ممکن نشد؛ برای جلوگیری از پیام تکراری، پیامکی فرستاده نشد.',
				'schema'   => 'جدول‌های افزونه در پایگاه‌داده کامل نیستند؛ پیامکی فرستاده نشد. افزونه را غیرفعال و دوباره فعال کنید و اگر ادامه داشت با میزبان تماس بگیرید.',
			);
			wp_send_json_error( array( 'message' => $why[ $claim['skip'] ] ) );
		}
		$row   = $claim['id'];
		$extra = array();
		$code  = '';
		try {
			if ( $with_coupon ) {
				list( $code, $expire ) = self::make_coupon( $cust );
				$extra = array(
					'{coupon}'   => $code,
					'{discount}' => CRDR_Jalali::digits( (string) CRDR_Settings::get( 'coupon_percent' ) ),
					'{expire}'   => CRDR_Jalali::date( $expire ),
				);
			}
		} catch ( \Throwable $e ) {
			CRDR_Send::finalize( $row, 'failed', 'ساخت کد تخفیف ناموفق بود.' );
			wp_send_json_error( array( 'message' => 'ساخت کد تخفیف ناموفق بود؛ پیامکی فرستاده نشد.' ) );
		}
		$render = self::render_checked( $tpl, $cust, $extra );
		if ( ! $render['ok'] ) {
			$why = CRDR_Template::explain( $render );
			CRDR_Send::finalize( $row, 'failed', $why );
			wp_send_json_error( array( 'message' => $why . ' پیامکی فرستاده نشد.' ) );
		}
		$out = CRDR_Send::send_claimed( $row, $cust['phone'], $render['text'], $code );
		if ( 'sent' !== $out['state'] ) {
			wp_send_json_error( array( 'message' => $out['error'] ) );
		}
		wp_send_json_success( array( 'message' => 'پیامک به سرویس پیامک سپرده و پذیرفته شد. (رسیدن آن به گوشی مشتری تأیید نمی‌شود.)' ) );
	}

	/* ------------------------------------------------------------- campaigns */

	public static function ajax_pb_preview() {
		self::guard();
		$id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
		$p  = CRDR_Playbooks::preview( $id );
		if ( ! $p ) {
			wp_send_json_error( array( 'message' => 'کمپین پیدا نشد.' ) );
		}
		// A site that is not the recorded one: a non-administrator cannot run it at all; an administrator gets the one-time token for this campaign.
		$site = CRDR_Site::preview_ack( 'pb:' . $id );
		if ( '' !== $site['blocked'] && '' === (string) $p['blocked'] ) {
			$p['blocked']         = 'site_unconfirmed';
			$p['blocked_message'] = $site['blocked'];
			$p['can_override']    = false;
		}
		$p['site_ack']     = $site['ack'];
		// WooCommerce returns the symbol as an HTML entity (the Iranian toman / rial are "&#x062A;&#x0648;..."); the browser script puts these strings in as plain text, so decode here.
		$symbol            = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$p['aov_fmt']      = CRDR_Jalali::number( round( $p['aov'] ) ) . ' ' . $symbol;
		$p['scenario_fmt'] = array_map( function ( $v ) use ( $symbol ) {
			return CRDR_Jalali::number( round( $v ) ) . ' ' . $symbol;
		}, $p['scenario'] );
		wp_send_json_success( $p );
	}

	public static function ajax_pb_run() {
		self::guard();
		$id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
		// The browser only ASKS to override the sending hours; run() checks the capability and records it.
		$res = CRDR_Playbooks::run( $id, 20, array(
			'trigger'         => 'manual',
			'override_window' => ! empty( $_POST['override'] ),
			'site_ack'        => self::posted_ack(), // only matters on a site that is not the recorded one (CRDR_Site)
		) );
		// The browser runs a campaign in steps of 20; each step spends one token and is handed the next one by the server.
		if ( '' === (string) $res['blocked'] && '' === (string) $res['error'] ) {
			$res['site_ack'] = CRDR_Site::issue_ack( 'pb:' . $id );
		}
		wp_send_json_success( $res );
	}

	public static function ajax_pb_mode() {
		self::guard();
		$id   = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'off';
		$r    = CRDR_Playbooks::set_mode( $id, $mode );
		if ( is_array( $r ) ) {
			wp_send_json_error( array( 'message' => 'قبل از فعال‌سازی، متن پیام کمپین را اصلاح کنید: ' . $r[0] ) );
		}
		if ( ! $r ) {
			wp_send_json_error( array( 'message' => 'کمپین پیدا نشد.' ) );
		}
		wp_send_json_success( array( 'mode' => $mode ) );
	}

	public static function post_pb_save() {
		self::guard_post();
		$id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
		$r  = CRDR_Playbooks::save( $id, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_array( $r ) ) { // invalid message text: nothing was saved, show the form again with the problems.
			self::back( 'customer-radar-campaigns', array( 'edit' => $id, 'invalid' => 1 ) );
		}
		self::back( 'customer-radar-campaigns', array( 'saved' => $id ) );
	}

	public static function post_pb_create() {
		self::guard_post();
		$aud = CRDR_Audience::normalize( array_merge( wp_unslash( $_REQUEST ), array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$aud = array_intersect_key( $aud, array_flip( array( 'segments', 'min_orders', 'max_orders', 'min_days', 'max_days', 'min_ratio', 'max_ratio', 'min_spent', 'max_spent', 'product' ) ) );
		$id  = CRDR_Playbooks::create_custom( $aud );
		self::back( 'customer-radar-campaigns', array( 'edit' => $id ) );
	}

	public static function post_pb_delete() {
		self::guard_post();
		$id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
		CRDR_Playbooks::delete_custom( $id );
		self::back( 'customer-radar-campaigns', array( 'deleted' => 1 ) );
	}

	/* ------------------------------------------------------------------- csv */

	public static function post_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'دسترسی کافی ندارید.', 403 );
		}
		check_admin_referer( self::NONCE );
		$sync = CRDR_Sync::guard();
		if ( $sync['blocked'] ) { // an export taken from a half-built table would look complete and is not
			wp_die( esc_html( $sync['message'] ), '', array( 'response' => 409, 'back_link' => true ) );
		}
		global $wpdb;
		$f    = CRDR_Audience::normalize( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$rows = $wpdb->get_results( 'SELECT * FROM ' . CRDR_DB::customers() . ' c ' . CRDR_Audience::where( $f ) . ' ORDER BY priority DESC, last_ts DESC LIMIT 50000', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( $wpdb->last_error ) { // an empty file would look like "no customers"
			wp_die( esc_html( CRDR_Health::message( 'db_error' ) ), '', array( 'response' => 500, 'back_link' => true ) );
		}
		$optout = CRDR_DB::optout_keys( wp_list_pluck( $rows, 'ckey' ), $optout_ok );
		if ( ! $optout_ok ) { // a file that hides opt-outs because the lookup failed is worse than no file
			wp_die( esc_html( CRDR_Health::message( 'db_error' ) ), '', array( 'response' => 500, 'back_link' => true ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=customer-radar-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		self::csv_write( $out, $rows, $optout );
		fclose( $out );
		exit;
	}

	/**
	 * Write the customer file. Every text column passes through csv_safe(); the last column marks customers who asked not to be messaged.
	 *
	 * @param resource $out    stream
	 * @param array    $rows   customer rows
	 * @param array    $optout customer key => true for the customers who opted out
	 */
	public static function csv_write( $out, array $rows, array $optout ) {
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel reads UTF-8.
		fputcsv( $out, array( 'نام', 'موبایل', 'ایمیل', 'تعداد سفارش', 'جمع خرید', 'میانگین سبد', 'اولین خرید', 'آخرین خرید', 'روز از آخرین خرید', 'فاصله معمول (روز)', 'بخش', 'اولویت', self::EXPORT_OPTOUT_HEADER ) );
		$segments = CRDR_RFM::segments();
		$now      = time();
		foreach ( $rows as $r ) {
			$orders = max( 1, (int) $r['orders_count'] );
			fputcsv( $out, array(
				self::csv_safe( $r['name'] ),
				self::csv_safe( $r['phone'] ),
				self::csv_safe( $r['email'] ),
				$r['orders_count'],
				$r['total_spent'],
				round( $r['total_spent'] / $orders ),
				CRDR_Jalali::date( $r['first_ts'], false ),
				CRDR_Jalali::date( $r['last_ts'], false ),
				(int) floor( ( $now - $r['last_ts'] ) / DAY_IN_SECONDS ),
				$r['avg_gap_days'],
				self::csv_safe( isset( $segments[ $r['segment'] ] ) ? $segments[ $r['segment'] ] : $r['segment'] ),
				$r['priority'],
				isset( $optout[ $r['ckey'] ] ) ? self::EXPORT_OPTOUT_YES : '',
			) );
		}
	}

	/** Defuse spreadsheet formula injection in exported text (every text column goes through it, whatever its source). */
	public static function csv_safe( $v ) {
		$v = (string) $v;
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
	}

	/* ------------------------------------------------------------- settings */

	/** "Try the rejected numbers of this campaign again": a person's explicit decision, e.g. after the provider fixed something. */
	public static function post_retry_rejected() {
		self::guard_post();
		$id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
		if ( CRDR_Playbooks::get( $id ) ) {
			CRDR_Send::reject_epoch_bump( $id );
		}
		self::back( 'customer-radar-campaigns', array( 'retried' => $id ) );
	}

	public static function post_save_settings() {
		self::guard_post();
		$in     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$clean  = CRDR_Settings::sanitize( $in );
		$errors = array_merge( CRDR_Settings::template_errors( $clean ), CRDR_Settings::window_errors( $clean ) );
		if ( $errors ) { // nothing is saved; the submitted values come back so the person can fix them.
			set_transient( CRDR_Settings::draft_key(), array( 'values' => CRDR_Settings::sanitize( $in ), 'errors' => $errors ), 15 * MINUTE_IN_SECONDS );
			self::back( 'customer-radar-settings', array( 'invalid' => 1 ) );
		}
		CRDR_Settings::save( $in );
		self::back( 'customer-radar-settings', array( 'saved' => 1 ) );
	}

	/* --------------------------------------------------------- bundle coupon */

	/** A percentage coupon limited to two products, to promote them together. */
	public static function post_bundle_coupon() {
		self::guard_post();
		$a   = isset( $_POST['a'] ) ? absint( $_POST['a'] ) : 0;
		$b   = isset( $_POST['b'] ) ? absint( $_POST['b'] ) : 0;
		$pct = isset( $_POST['percent'] ) ? max( 1, min( 50, (int) $_POST['percent'] ) ) : (int) CRDR_Settings::get( 'bundle_discount' );
		if ( ! $a || ! $b || ! wc_get_product( $a ) || ! wc_get_product( $b ) ) {
			self::back( 'customer-radar-products', array( 'error' => 1 ) );
		}
		do {
			$code = 'PK' . strtoupper( wp_generate_password( 5, false, false ) );
		} while ( wc_get_coupon_id_by_code( $code ) );
		$c = new WC_Coupon();
		$c->set_code( $code );
		$c->set_discount_type( 'percent' );
		$c->set_amount( $pct );
		$c->set_product_ids( array( $a, $b ) );
		$c->set_date_expires( time() + 30 * DAY_IN_SECONDS );
		$c->set_description( 'بسته‌ی پیشنهادی رادار مشتری: ' . CRDR_Analytics::product_name( $a ) . ' + ' . CRDR_Analytics::product_name( $b ) );
		$c->update_meta_data( '_crdr_bundle', $a . '-' . $b );
		$c->save();
		self::back( 'customer-radar-products', array( 'coupon' => $code ) );
	}
}
