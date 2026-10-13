<?php
/**
 * What happened to the AUTOMATIC (cron) runs of each campaign: was the last one blocked, and why, or did it execute.
 *
 * Cron has nobody watching, so a blocked campaign (broken message text, no SMS provider, impossible sending hours,
 * a lock that never frees up) used to fail silently every hour. This keeps ONE small record per campaign:
 *
 *   blocked  : code, since, last, count     the reason the latest cron ticks did not run (absent when running fine)
 *   last_run : ts, sent, held_out, ...      counts of the latest cron run that actually executed
 *
 * A repeated block only raises count/last, so a campaign stuck for a month is still one record and one log entry;
 * the cron run log (CRDR_Send::log_run) gets an entry only when the state CHANGES (blocked, other reason, recovered).
 * The admin sees it as a persistent warning (admin_notices, driven by this stored state, not by a transient) and as a
 * line on the campaign card.
 *
 * Only codes and counters are stored: never message text, phone numbers, error text from the gateway or any key.
 * Being outside the allowed sending hours is the normal state at night and is NOT recorded.
 */
defined( 'ABSPATH' ) || defined( 'CRDR_TESTING' ) || exit;

class CRDR_Health {

	const OPTION = 'crdr_run_health';

	/** A single busy tick is normal (a manual run may be in progress); this many in a row is worth a warning. */
	const BUSY_TICKS = 3;

	/** How late the hourly run may be before it is a problem (see cron_overdue). */
	const CRON_LATE_HOST = 10800;  // 3 h: the host's own cron is expected to run it
	const CRON_LATE_WP   = 172800; // 48 h: WP-Cron needs a visit; a quiet shop is normally late for hours

	/* ------------------------------------------------------- pure state rules */

	public static function codes() {
		return array( 'template', 'no_provider', 'busy', 'window_invalid', 'not_auto', 'mode_off', 'schema', 'db_error', 'site_changed', 'other' );
	}

	/**
	 * Expected, or reported elsewhere: nothing is recorded per campaign for it. Being outside the sending hours is normal; a
	 * customer-data rebuild that is running, stalled or failed is one global condition with its own warning (notice_html).
	 */
	public static function is_recorded( $code ) {
		return ! in_array( $code, array( 'outside_window', 'sync_running', 'sync_stalled', 'sync_failed', 'rebuild_required' ), true );
	}

	/** A sending window must start before it ends; one that wraps midnight is not supported, so it never opens. */
	public static function window_valid( $from, $to ) {
		return (int) $from < (int) $to;
	}

	/**
	 * @param array  $st   the campaign's record
	 * @return array [ new record, bool changed ] ; changed = a new reason (the first or a different one)
	 */
	public static function apply_block( array $st, $code, $ts ) {
		$code = in_array( $code, self::codes(), true ) ? $code : 'other';
		$ts   = (int) $ts;
		$b    = isset( $st['blocked'] ) && is_array( $st['blocked'] ) ? $st['blocked'] : null;
		if ( $b && $code === $b['code'] ) {
			$st['blocked'] = array( 'code' => $code, 'since' => (int) $b['since'], 'last' => $ts, 'count' => (int) $b['count'] + 1 );
			return array( $st, false );
		}
		$st['blocked'] = array( 'code' => $code, 'since' => $ts, 'last' => $ts, 'count' => 1 );
		return array( $st, true );
	}

	/** A cron run executed (whatever it sent): remember its counts, and drop any block. @return array [ record, recovered ] */
	public static function apply_run( array $st, array $res, $ts ) {
		$recovered = isset( $st['blocked'] );
		unset( $st['blocked'] );
		$st['last_run'] = array( 'ts' => (int) $ts );
		foreach ( array( 'sent', 'held_out', 'skipped', 'failed', 'unknown' ) as $k ) {
			$st['last_run'][ $k ] = isset( $res[ $k ] ) ? (int) $res[ $k ] : 0;
		}
		return array( $st, $recovered );
	}

	/** Drop the block only if it is for this reason. @return array [ record, bool cleared ] */
	public static function clear_code( array $st, $code ) {
		if ( isset( $st['blocked']['code'] ) && $code === $st['blocked']['code'] ) {
			unset( $st['blocked'] );
			return array( $st, true );
		}
		return array( $st, false );
	}

	public static function needs_attention( array $blocked ) {
		if ( 'busy' === $blocked['code'] ) {
			return (int) $blocked['count'] >= self::BUSY_TICKS;
		}
		return true;
	}

	/**
	 * Seconds the hourly run is overdue, or 0 when that is not a problem. WordPress keeps the time of the next scheduled run;
	 * if the scheduler does not run, that time stays in the past. How late is "too late" depends on who is expected to run it:
	 *  - the host's own cron (DISABLE_WP_CRON): it runs every few minutes, so 3 hours late means it is not configured or broken;
	 *  - WP-Cron: it only runs when somebody visits the site, so a quiet shop is legitimately late for hours (and the admin's
	 *    own visit runs it); only 48 hours late means visits do not trigger it either (blocked loopback requests, a caching
	 *    layer, ...).
	 *
	 * @param int  $next_ts  wp_next_scheduled(), 0 / false when nothing is scheduled (WordPress re-schedules on the next request)
	 */
	public static function cron_overdue( $next_ts, $now, $wp_cron_disabled ) {
		if ( ! $next_ts ) {
			return 0;
		}
		$late = (int) $now - (int) $next_ts;
		$max  = $wp_cron_disabled ? self::CRON_LATE_HOST : self::CRON_LATE_WP;
		return $late > $max ? $late : 0;
	}

	/** What the owner should do about a scheduler that does not run, in Persian. */
	public static function cron_message( $overdue, $wp_cron_disabled ) {
		$hours = CRDR_Jalali::digits( (string) max( 1, (int) floor( $overdue / 3600 ) ) );
		if ( $wp_cron_disabled ) {
			return 'اجرای خودکار هر ساعت حدود ' . $hours . ' ساعت است که انجام نشده. در این سایت WP-Cron خاموش شده (DISABLE_WP_CRON) و باید یک cron واقعی هاست هر چند دقیقه یک‌بار wp-cron.php را فراخوانی کند؛ آن را بررسی کنید. تا آن موقع کمپین‌های «خودکار» پیامکی نمی‌فرستند.';
		}
		return 'زمان‌بند وردپرس (WP-Cron) حدود ' . $hours . ' ساعت است که اجرا نشده؛ ممکن است سایت بازدیدی نداشته یا WP-Cron مسدود باشد. تا وقتی اجرا نشود، کمپین‌های «خودکار» پیامکی نمی‌فرستند. بهتر است روی هاست یک cron واقعی بسازید که هر ساعت wp-cron.php را فراخوانی کند.';
	}

	/** DISABLE_WP_CRON is set: the host is expected to run the scheduler. (Filterable so that tests can pose both cases.) */
	public static function wp_cron_disabled() {
		return (bool) apply_filters( 'crdr_wp_cron_disabled', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/** What the owner should do, in Persian. */
	public static function message( $code ) {
		$m = array(
			'template'       => 'متن پیام این کمپین معتبر نیست؛ کمپین را ویرایش و متن را اصلاح کنید.',
			'no_provider'    => 'سرویس پیامک تنظیم نشده است؛ در «تنظیمات»، کاوه‌نگار را با کلید API وارد کنید یا افزونه پیامک ووکامرس را فعال کنید.',
			'busy'           => 'اجرای قبلی همین کمپین هنوز تمام نشده یا اجرای دستی در جریان است؛ اگر تکرار شد، چند دقیقه صبر کنید و در صورت ادامه با پشتیبانی هاست تماس بگیرید.',
			'window_invalid' => 'ساعت مجاز ارسال نامعتبر است (ساعت شروع باید کوچک‌تر از پایان باشد؛ بازه‌ی شبانه مثل ۲۲ تا ۶ پشتیبانی نمی‌شود)؛ در «تنظیمات» اصلاحش کنید.',
			'not_auto'       => 'حالت این کمپین «خودکار» نیست.',
			'mode_off'       => 'این کمپین غیرفعال است.',
			'schema'         => 'جدول‌های افزونه در پایگاه‌داده کامل نیستند و پیامکی فرستاده نمی‌شود؛ افزونه را غیرفعال و دوباره فعال کنید و اگر ادامه داشت از میزبان بخواهید اجازه‌ی ساخت و تغییر جدول (CREATE و ALTER) را به کاربر پایگاه‌داده بدهد.',
			'db_error'       => 'خطای پایگاه‌داده هنگام خواندن یا ثبت اطلاعات؛ هیچ پیامکی با اطمینان ارسال نشد و اجرا متوقف شد. وضعیت جدول‌های افزونه را بررسی کنید (غیرفعال و دوباره فعال کردن افزونه کمبودها را برمی‌گرداند)؛ اگر ادامه داشت، با میزبان تماس بگیرید.',
			'site_changed'   => 'نشانی این سایت با نشانی ثبت‌شده‌ی رادار مشتری یکی نیست (کپی، محیط آزمایشی یا انتقال سایت)؛ برای جلوگیری از ارسال خودکار پیامک به مشتریان واقعی، اجرای خودکار متوقف شد. اگر این سایت اصلی است، در داشبورد نشانی جدید را تأیید کنید.',
			'other'          => 'اجرای خودکار این کمپین انجام نشد؛ کمپین و تنظیمات را بررسی کنید.',
		);
		return isset( $m[ $code ] ) ? $m[ $code ] : $m['other'];
	}

	/* ------------------------------------------------------------- storage */

	public static function load() {
		$a = get_option( self::OPTION, array() );
		return is_array( $a ) ? $a : array();
	}

	private static function save( array $all ) {
		update_option( self::OPTION, $all, false );
	}

	private static function log( $id, $result ) {
		CRDR_Send::log_run( array(
			'run'       => bin2hex( random_bytes( 8 ) ),
			'ts'        => time(),
			'playbook'  => $id,
			'trigger'   => 'cron',
			'user'      => 0,
			'mode'      => 'auto',
			'in_window' => CRDR_Playbooks::in_send_window(),
			'override'  => false,
			'result'    => $result,
		) );
	}

	/** A cron tick did not run this campaign. */
	public static function record_block( $id, $code ) {
		if ( ! self::is_recorded( $code ) ) {
			return;
		}
		$all             = self::load();
		list( $st, $ch ) = self::apply_block( isset( $all[ $id ] ) ? $all[ $id ] : array(), $code, time() );
		$all[ $id ]      = $st;
		self::save( $all );
		if ( $ch ) {
			self::log( $id, 'blocked:' . $st['blocked']['code'] );
		}
	}

	/** A cron tick executed this campaign (it may have had nothing to send). */
	public static function record_run( $id, array $res ) {
		$all              = self::load();
		list( $st, $rec ) = self::apply_run( isset( $all[ $id ] ) ? $all[ $id ] : array(), $res, time() );
		$all[ $id ]       = $st;
		self::save( $all );
		if ( $rec ) {
			self::log( $id, 'recovered' );
		}
	}

	/** Forget a reason for every campaign (e.g. the sending window was fixed). */
	public static function clear_code_all( $code ) {
		$all     = self::load();
		$changed = false;
		foreach ( $all as $id => $st ) {
			list( $new, $cleared ) = self::clear_code( (array) $st, $code );
			if ( $cleared ) {
				$all[ $id ] = $new;
				$changed    = true;
				self::log( $id, 'recovered' );
			}
		}
		if ( $changed ) {
			self::save( $all );
		}
	}

	/** Drop the records of campaigns that no longer exist. */
	public static function prune( array $ids ) {
		$all  = self::load();
		$keep = array_intersect_key( $all, array_flip( $ids ) );
		if ( count( $keep ) !== count( $all ) ) {
			self::save( $keep );
		}
	}

	/* ------------------------------------------------------------ admin UI */

	private static function when( $ts ) {
		return CRDR_Jalali::date( $ts ) . ' ' . CRDR_Jalali::digits( wp_date( 'H:i', $ts ) );
	}

	/** Where the person can fix this reason. */
	private static function fix_url( $id, $code ) {
		if ( 'no_provider' === $code || 'window_invalid' === $code ) {
			return admin_url( 'admin.php?page=customer-radar-settings' );
		}
		if ( 'template' === $code ) {
			return admin_url( 'admin.php?page=customer-radar-campaigns&edit=' . rawurlencode( $id ) );
		}
		if ( 'site_changed' === $code ) {
			return admin_url( 'admin.php?page=customer-radar' );
		}
		return admin_url( 'admin.php?page=customer-radar-campaigns' );
	}

	/** Persistent warning about automatic campaigns that are blocked or cannot run. '' when there is nothing to say. */
	public static function notice_html() {
		if ( ! current_user_can( CRDR_Actions::CAP ) ) {
			return '';
		}
		$state    = self::load();
		$items    = '';
		$has_auto = false;
		foreach ( CRDR_Playbooks::all() as $id => $pb ) {
			if ( 'auto' !== $pb['mode'] ) {
				continue;
			}
			$has_auto = true;
			if ( empty( $state[ $id ]['blocked'] ) || ! self::needs_attention( $state[ $id ]['blocked'] ) ) {
				continue;
			}
			$b      = $state[ $id ]['blocked'];
			$items .= '<li><strong>' . esc_html( $pb['name'] ) . '</strong>: ' . esc_html( self::message( $b['code'] ) )
				. ' <span class="description">(از ' . esc_html( self::when( $b['since'] ) ) . ')</span> <a href="' . esc_url( self::fix_url( $id, $b['code'] ) ) . '">رفع مشکل</a></li>';
		}
		$cron    = '';
		$schema  = get_option( CRDR_DB::SCHEMA_ERROR );
		if ( is_array( $schema ) && ! empty( $schema['problems'] ) ) { // the tables are not complete: nothing can be sent, say so on the plugin's own screens
			$cron .= '<p><strong>' . esc_html( CRDR_DB::schema_message( (array) $schema['problems'] ) ) . '</strong></p>';
		}
		$overdue = $has_auto ? self::cron_overdue( (int) wp_next_scheduled( CRDR_Playbooks::HOURLY ), time(), self::wp_cron_disabled() ) : 0;
		if ( $overdue ) { // nothing here would otherwise tell the owner: a scheduler that does not run leaves no trace of its own
			$cron = '<p>' . esc_html( self::cron_message( $overdue, self::wp_cron_disabled() ) ) . '</p>';
		}
		// A rebuild that stalled or failed leaves no trace of its own (nobody is watching a background job) and blocks all
		// sending, so it is said here, once, to administrators. A rebuild that is simply running is shown on the pages it affects.
		$sync = CRDR_Sync::guard();
		if ( $sync['blocked'] && 'sync_running' !== $sync['code'] ) {
			$cron .= '<p><strong>' . esc_html( $sync['message'] ) . '</strong></p>';
		}
		// The last rebuild could not add some orders that were eligible (see CRDR_Sync::reconcile): the numbers are slightly low.
		if ( false !== get_option( CRDR_DB::REBUILD_OPTION, false ) ) { // an update is waiting for its first rebuild: sending is paused until then
			$cron .= '<p><strong>' . esc_html( CRDR_Sync::rebuild_required_message() ) . '</strong></p>';
		}
		$last = CRDR_Sync::state();
		if ( 'done' === $last['phase'] && ! empty( $last['unresolved'] ) ) {
			$cron .= '<p>' . esc_html( 'در آخرین به‌روزرسانی، ' . CRDR_Jalali::digits( (string) (int) $last['unresolved'] ) . ' سفارش که باید شمرده می‌شد خوانده نشد؛ آمار ممکن است کمی کمتر از واقعی باشد. «تحلیل سفارش‌ها» را دوباره بزنید.' ) . '</p>';
		} elseif ( 'done' === $last['phase'] && ! empty( $last['unverified'] ) ) {
			$cron .= '<p>' . esc_html( 'در آخرین به‌روزرسانی، سفارش‌ها آن‌قدر پشت‌سرهم تغییر می‌کردند که بررسی نهایی کامل نشد؛ آمار ممکن است کمی ناقص باشد. «تحلیل سفارش‌ها» را دوباره بزنید.' ) . '</p>';
		}
		$site = CRDR_Site::notice_html();
		if ( '' === $items && '' === $cron ) {
			return $site;
		}
		return '<div class="notice notice-warning">'
			. ( '' !== $items ? '<p><strong>رادار مشتری: ارسال خودکار بعضی کمپین‌ها متوقف شده و پیامکی نمی‌رود.</strong></p><ul style="list-style:disc;margin-inline-start:20px">' . $items . '</ul>' : '' )
			. $cron . '</div>' . $site;
	}

	/** admin_notices: the dashboard and this plugin's own screens (not every admin page). */
	public static function admin_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( 'dashboard' !== $screen->id && false === strpos( (string) $screen->id, 'customer-radar' ) ) ) {
			return;
		}
		echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
	}

	/** One <li> for a campaign card (automatic campaigns only): blocked, or the last executed run. */
	public static function card_item( $id, array $pb ) {
		if ( 'auto' !== $pb['mode'] ) {
			return '';
		}
		$st = self::load();
		$st = isset( $st[ $id ] ) ? $st[ $id ] : array();
		if ( ! empty( $st['blocked'] ) ) {
			$b = $st['blocked'];
			return '<li class="crdr-bad"><strong>اجرای خودکار متوقف است:</strong> ' . esc_html( self::message( $b['code'] ) ) . ' (از ' . esc_html( self::when( $b['since'] ) ) . '، ' . esc_html( CRDR_UI::num( $b['count'] ) ) . ' بار) <a href="' . esc_url( self::fix_url( $id, $b['code'] ) ) . '">رفع مشکل</a></li>';
		}
		if ( ! empty( $st['last_run'] ) ) {
			$r = $st['last_run'];
			return '<li>آخرین اجرای خودکار: ' . esc_html( self::when( $r['ts'] ) ) . ' — ارسال ' . esc_html( CRDR_UI::num( $r['sent'] ) ) . '، ناموفق ' . esc_html( CRDR_UI::num( $r['failed'] ) ) . ( $r['unknown'] ? '، نتیجه نامشخص ' . esc_html( CRDR_UI::num( $r['unknown'] ) ) : '' ) . '</li>';
		}
		return '<li>هنوز اجرای خودکاری انجام نشده است (فقط در ساعت‌های مجاز و هر ساعت یک‌بار).</li>';
	}
}
