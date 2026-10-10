<?php
defined( 'ABSPATH' ) || exit;

/**
 * AJAX and admin-post handlers: sync, manual contact, campaigns, export, settings, bundle coupon.
 */
class CRDR_Actions {

	const NONCE = 'crdr_nonce';
	const CAP   = 'manage_woocommerce';

	public static function init() {
		$ajax = array( 'sync_start', 'sync_step', 'preview', 'contact', 'pb_preview', 'pb_run', 'pb_mode' );
		foreach ( $ajax as $a ) {
			add_action( 'wp_ajax_crdr_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		$post = array( 'export', 'save_settings', 'pb_save', 'pb_create', 'pb_delete', 'bundle_coupon' );
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

	public static function customer_by_key( $ckey ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CRDR_DB::customers() . ' WHERE ckey = %s', $ckey ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Contacted recently (sms / call / skip) or opted out. Returns 'optout', true (cooldown) or false. */
	public static function in_cooldown( $ckey ) {
		$why = CRDR_DB::blocked_reason( $ckey );
		return 'optout' === $why ? 'optout' : ( '' !== $why );
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
		return CRDR_Template::render( $tpl, $vars );
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

	public static function ajax_preview() {
		self::guard();
		$cust = self::customer( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		if ( ! $cust ) {
			wp_send_json_error( array( 'message' => 'مشتری پیدا نشد.' ) );
		}
		$closed = self::one_off_window_block(); // the preview is only the step before sending: say so now.
		if ( '' !== $closed ) {
			wp_send_json_error( array( 'message' => $closed ) );
		}
		$with_coupon = ! empty( $_POST['coupon'] );
		$tpl         = CRDR_Settings::get( $with_coupon ? 'sms_template_coupon' : 'sms_template' );
		$problems    = CRDR_Template::validate( $tpl, array( 'offer' => $with_coupon ) );
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
		wp_send_json_success( array(
			'message'  => self::render_template( $tpl, $cust, $extra ),
			'phone'    => $cust['phone'],
			'provider' => CRDR_SMS::provider_label(),
		) );
	}

	public static function ajax_contact() {
		self::guard();
		$cust = self::customer( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		if ( ! $cust ) {
			wp_send_json_error( array( 'message' => 'مشتری پیدا نشد.' ) );
		}
		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';

		if ( 'called' === $type || 'skip' === $type ) {
			$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
			CRDR_DB::add_contact( $cust['ckey'], $type, array( 'playbook' => 'manual', 'message' => $note ) );
			wp_send_json_success( array( 'message' => 'ثبت شد.' ) );
		}
		if ( 'optout' === $type ) {
			CRDR_DB::add_contact( $cust['ckey'], 'optout', array( 'playbook' => 'manual', 'message' => 'عدم تمایل به دریافت پیام' ) );
			wp_send_json_success( array( 'message' => 'از این پس برای این مشتری پیامی ارسال نمی‌شود.' ) );
		}
		if ( 'sms' !== $type && 'sms_coupon' !== $type ) {
			wp_send_json_error( array( 'message' => 'نوع اقدام نامعتبر است.' ) );
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
		if ( $cool ) {
			wp_send_json_error( array( 'message' => 'با این مشتری به‌تازگی تماس گرفته شده است.' ) );
		}

		$with_coupon = 'sms_coupon' === $type;
		$tpl         = CRDR_Settings::get( $with_coupon ? 'sms_template_coupon' : 'sms_template' );
		$problems    = CRDR_Template::validate( $tpl, array( 'offer' => $with_coupon ) );
		if ( $problems ) { // nothing is claimed, no coupon is created, nothing is sent.
			wp_send_json_error( array( 'message' => 'متن پیامک در «تنظیمات» معتبر نیست: ' . $problems[0] ) );
		}

		// Claim the customer first: a double click or a campaign running at the same moment cannot send twice.
		$claim = CRDR_Send::claim( $cust['ckey'], 'manual', bin2hex( random_bytes( 8 ) ) );
		if ( isset( $claim['skip'] ) ) {
			$why = array(
				'busy'     => 'ارسال برای این مشتری هم‌اکنون در حال انجام است.',
				'optout'   => 'این مشتری درخواست نکردن پیام داده است.',
				'cooldown' => 'با این مشتری به‌تازگی تماس گرفته شده است.',
				'error'    => 'ثبت وضعیت ارسال ممکن نشد؛ برای جلوگیری از پیام تکراری، پیامکی فرستاده نشد.',
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
		wp_send_json_success( array( 'message' => 'پیامک ارسال شد.' ) );
	}

	/* ------------------------------------------------------------- campaigns */

	public static function ajax_pb_preview() {
		self::guard();
		$id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
		$p  = CRDR_Playbooks::preview( $id );
		if ( ! $p ) {
			wp_send_json_error( array( 'message' => 'کمپین پیدا نشد.' ) );
		}
		$p['aov_fmt']      = CRDR_Jalali::number( round( $p['aov'] ) ) . ' ' . get_woocommerce_currency_symbol();
		$p['scenario_fmt'] = array_map( function ( $v ) {
			return CRDR_Jalali::number( round( $v ) ) . ' ' . get_woocommerce_currency_symbol();
		}, $p['scenario'] );
		wp_send_json_success( $p );
	}

	public static function ajax_pb_run() {
		self::guard();
		$id = isset( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
		// The browser only ASKS to override the sending hours; run() checks the capability and records it.
		wp_send_json_success( CRDR_Playbooks::run( $id, 20, array(
			'trigger'         => 'manual',
			'override_window' => ! empty( $_POST['override'] ),
		) ) );
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
		global $wpdb;
		$f    = CRDR_Audience::normalize( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$rows = $wpdb->get_results( 'SELECT * FROM ' . CRDR_DB::customers() . ' c ' . CRDR_Audience::where( $f ) . ' ORDER BY priority DESC, last_ts DESC LIMIT 50000', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=customer-radar-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel reads UTF-8.
		fputcsv( $out, array( 'نام', 'موبایل', 'ایمیل', 'تعداد سفارش', 'جمع خرید', 'میانگین سبد', 'اولین خرید', 'آخرین خرید', 'روز از آخرین خرید', 'فاصله معمول (روز)', 'بخش', 'اولویت' ) );
		$segments = CRDR_RFM::segments();
		$now      = time();
		foreach ( $rows as $r ) {
			$orders = max( 1, (int) $r['orders_count'] );
			fputcsv( $out, array(
				self::csv_safe( $r['name'] ),
				$r['phone'],
				$r['email'],
				$r['orders_count'],
				$r['total_spent'],
				round( $r['total_spent'] / $orders ),
				CRDR_Jalali::date( $r['first_ts'], false ),
				CRDR_Jalali::date( $r['last_ts'], false ),
				(int) floor( ( $now - $r['last_ts'] ) / DAY_IN_SECONDS ),
				$r['avg_gap_days'],
				isset( $segments[ $r['segment'] ] ) ? $segments[ $r['segment'] ] : $r['segment'],
				$r['priority'],
			) );
		}
		fclose( $out );
		exit;
	}

	/** Defuse spreadsheet formula injection in exported text. */
	private static function csv_safe( $v ) {
		$v = (string) $v;
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
	}

	/* ------------------------------------------------------------- settings */

	public static function post_save_settings() {
		self::guard_post();
		$in     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$errors = CRDR_Settings::template_errors( CRDR_Settings::sanitize( $in ) );
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
