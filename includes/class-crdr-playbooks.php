<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ready-made marketing processes ("playbooks") with a safe runner and honest measurement.
 *
 * A playbook = who (audience filter) + what (offer + message) + how often (daily cap, cooldown) +
 * how to learn (holdout group that is deliberately NOT contacted, so the real effect can be measured).
 * Modes: off | manual (you press run) | auto (hourly, inside the allowed sending hours).
 */
class CRDR_Playbooks {

	const OPTION     = 'crdr_playbooks';
	const HOURLY     = 'crdr_hourly';
	const LOCK       = 'crdr_pb_lock';

	/* ---------------------------------------------------------------- catalog */

	public static function defaults() {
		return array(
			'second_purchase' => array(
				'name'     => 'خرید دوم',
				'goal'     => 'تبدیل مشتری تک‌خرید به مشتری تکرارشونده',
				'why'      => 'اولین قدم وفاداری، خرید دوم است. چند هفته بعد از اولین خرید، مشتری هنوز شما را یادش هست و هنوز عادت خرید از جای دیگر پیدا نکرده؛ بهترین زمان برای یک پیشنهاد کوچک همین‌جاست.',
				'audience' => array( 'min_orders' => 1, 'max_orders' => 1, 'min_days' => 10, 'max_days' => 45 ),
				'offer'    => array( 'type' => 'percent', 'percent' => 7, 'days' => 7 ),
				'template' => 'سلام {name} عزیز، از خریدتان از {store} ممنونیم! برای خرید دوم، {discount}٪ تخفیف با کد {coupon} تا {expire} برایتان فعال شد. {optout}',
				'daily_cap' => 40, 'holdout_pct' => 20, 'window_days' => 14, 'mode' => 'off',
				'scenario' => array( 0.02, 0.05, 0.08 ),
			),
			'reminder' => array(
				'name'     => 'یادآوری موعد خرید',
				'goal'     => 'یادآوری ملایم به مشتریان منظم، بدون تخفیف',
				'why'      => 'مشتری منظم را با تخفیف عادت ندهید. فقط وقتی به موعد خرید خودش نزدیک می‌شود یک یادآوری کوتاه کافی است و حاشیه‌ی سودتان را حفظ می‌کند.',
				'audience' => array( 'segments' => array( 'champion', 'loyal', 'need_attention', 'potential' ), 'min_orders' => 2, 'min_ratio' => 0.9, 'max_ratio' => 1.6 ),
				'offer'    => array( 'type' => 'none', 'percent' => 0, 'days' => 0 ),
				'template' => 'سلام {name} عزیز، به‌زودی موعد خرید بعدی شماست. منتظر شما در {store} هستیم. {optout}',
				'daily_cap' => 60, 'holdout_pct' => 15, 'window_days' => 10, 'mode' => 'off',
				'scenario' => array( 0.04, 0.08, 0.12 ),
			),
			'winback_risk' => array(
				'name'     => 'بازگشت مشتری در خطر',
				'goal'     => 'برگرداندن مشتریان تکراری که دیرتر از معمول نخریده‌اند',
				'why'      => 'وقتی فاصله از آخرین خرید از ۱٫۶ برابرِ الگوی خود مشتری بیشتر شود، احتمال ریزش بالا می‌رود. از اینجا به بعد تخفیف توجیه دارد.',
				'audience' => array( 'segments' => array( 'at_risk', 'need_attention', 'loyal' ), 'min_orders' => 2, 'min_ratio' => 1.6, 'max_ratio' => 3.0 ),
				'offer'    => array( 'type' => 'percent', 'percent' => 10, 'days' => 7 ),
				'template' => 'سلام {name} عزیز، مدتی است در {store} ندیدیمتان. برای بازگشت، {discount}٪ تخفیف با کد {coupon} تا {expire} برایتان گذاشتیم. {optout}',
				'daily_cap' => 40, 'holdout_pct' => 20, 'window_days' => 14, 'mode' => 'off',
				'scenario' => array( 0.03, 0.06, 0.10 ),
			),
			'save_vip' => array(
				'name'     => 'نجات مشتری ارزشمند',
				'goal'     => 'پیام و تماس شخصی با پرخرج‌هایی که در حال رفتن‌اند',
				'why'      => 'این گروه کم‌شمار است ولی هر نفرشان ارزش چند مشتری معمولی را دارد. علاوه بر پیامک، بهتر است خودتان هم تماس بگیرید (از «فهرست تماس»).',
				'audience' => array( 'segments' => array( 'cant_lose' ), 'min_ratio' => 1.2 ),
				'offer'    => array( 'type' => 'percent', 'percent' => 15, 'days' => 7 ),
				'template' => 'سلام {name} عزیز، شما از مشتریان ارزشمند {store} هستید و دلتنگتان شده‌ایم. {discount}٪ تخفیف ویژه با کد {coupon} تا {expire} برای شماست. {optout}',
				'daily_cap' => 15, 'holdout_pct' => 10, 'window_days' => 21, 'mode' => 'off',
				'scenario' => array( 0.05, 0.10, 0.15 ),
			),
			'dormant' => array(
				'name'     => 'بیدار کردن خواب‌رفته‌ها',
				'goal'     => 'تلاش کم‌هزینه با پیشنهاد جدی‌تر',
				'why'      => 'نرخ بازگشت این گروه پایین است، پس تلاش را کوچک نگه دارید: تعداد محدود، یک پیشنهاد جدی و کوتاه‌مدت. اگر پاسخ نداد، دیگر مزاحم نشوید.',
				'audience' => array( 'segments' => array( 'hibernating' ) ),
				'offer'    => array( 'type' => 'percent', 'percent' => 20, 'days' => 5 ),
				'template' => 'سلام {name} عزیز، دلمان برایتان تنگ شده! {discount}٪ تخفیف با کد {coupon} فقط تا {expire} در {store}. {optout}',
				'daily_cap' => 50, 'holdout_pct' => 20, 'window_days' => 14, 'mode' => 'off', 'cooldown' => 60,
				'scenario' => array( 0.01, 0.02, 0.04 ),
			),
			'vip_thanks' => array(
				'name'     => 'تشکر از قهرمان‌ها',
				'goal'     => 'نگه‌داشتن بهترین مشتریان بدون تخفیف',
				'why'      => 'بهترین مشتری‌ها قبل از هر چیز دیده شدن می‌خواهند، نه تخفیف. تشکر و دسترسی زودتر به محصولات جدید، وفاداری را بدون خرج می‌سازد.',
				'audience' => array( 'segments' => array( 'champion' ), 'min_days' => 20, 'max_days' => 90 ),
				'offer'    => array( 'type' => 'none', 'percent' => 0, 'days' => 0 ),
				'template' => 'سلام {name} عزیز، شما از بهترین مشتریان {store} هستید و ممنونیم! محصولات جدید را زودتر از همه به شما نشان می‌دهیم. {optout}',
				'daily_cap' => 20, 'holdout_pct' => 10, 'window_days' => 30, 'mode' => 'off', 'cooldown' => 60,
				'scenario' => array( 0.02, 0.04, 0.07 ),
			),
			'cross_sell' => array(
				'name'     => 'پیشنهاد محصول مکمل',
				'goal'     => 'فروش محصول مرتبط به خریداران اخیر',
				'why'      => 'مشتری‌ای که تازه خرید کرده بیشترین آمادگی را برای یک محصول مکمل دارد. پیشنهاد از روی خرید مشتریان مشابه ساخته می‌شود، نه حدس.',
				'audience' => array( 'min_orders' => 1, 'min_days' => 7, 'max_days' => 45 ),
				'offer'    => array( 'type' => 'none', 'percent' => 0, 'days' => 0 ),
				'cross_sell' => true,
				'template' => 'سلام {name} عزیز، خریداران «{bought}» معمولاً «{product}» را هم می‌گیرند: {url} {optout}',
				'daily_cap' => 40, 'holdout_pct' => 20, 'window_days' => 14, 'mode' => 'off',
				'scenario' => array( 0.02, 0.04, 0.07 ),
			),
		);
	}

	/** Saved overrides + custom playbooks merged over the defaults. */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( self::defaults() as $id => $d ) {
			$d['builtin'] = true;
			// A saved playbook stores the full audience and offer, so a shallow replace is the right merge.
			$out[ $id ] = isset( $saved[ $id ] ) ? array_replace( $d, $saved[ $id ] ) : $d;
		}
		foreach ( $saved as $id => $cfg ) {
			if ( ! isset( $out[ $id ] ) && is_array( $cfg ) && ! empty( $cfg['custom'] ) ) {
				$cfg['builtin'] = false;
				$out[ $id ]     = array_replace( self::blank(), $cfg );
			}
		}
		return $out;
	}

	public static function blank() {
		return array(
			'name' => 'کمپین جدید', 'goal' => '', 'why' => '', 'audience' => array(),
			'offer' => array( 'type' => 'none', 'percent' => 10, 'days' => 7 ),
			'template' => 'سلام {name} عزیز، از طرف {store}. {optout}',
			'daily_cap' => 30, 'holdout_pct' => 15, 'window_days' => 14, 'mode' => 'off',
			'scenario' => array( 0.02, 0.05, 0.08 ), 'custom' => true,
		);
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** Validate and store the editable fields of one playbook. */
	public static function save( $id, array $in ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$cur   = self::get( $id );
		if ( ! $cur ) {
			return false;
		}
		$aud = CRDR_Audience::normalize( array_merge( (array) $in, array( 'segments' => isset( $in['segments'] ) ? (array) $in['segments'] : array() ) ) );
		// Only keep keys that make sense for an audience definition.
		$aud = array_intersect_key( $aud, array_flip( array( 'segments', 'min_orders', 'max_orders', 'min_days', 'max_days', 'min_ratio', 'max_ratio', 'min_spent', 'max_spent', 'product' ) ) );

		$mode = isset( $in['mode'] ) ? sanitize_key( $in['mode'] ) : 'off';
		$ofr  = isset( $in['offer_type'] ) && 'percent' === $in['offer_type'] ? 'percent' : 'none';
		$cfg  = array(
			'name'        => isset( $in['name'] ) ? mb_substr( sanitize_text_field( $in['name'] ), 0, 60 ) : $cur['name'],
			'mode'        => in_array( $mode, array( 'off', 'manual', 'auto' ), true ) ? $mode : 'off',
			'audience'    => $aud,
			'offer'       => array(
				'type'    => $ofr,
				'percent' => max( 1, min( 90, (int) ( $in['offer_percent'] ?? 10 ) ) ),
				'days'    => max( 1, min( 90, (int) ( $in['offer_days'] ?? 7 ) ) ),
			),
			'template'    => isset( $in['template'] ) ? mb_substr( sanitize_textarea_field( $in['template'] ), 0, 400 ) : $cur['template'],
			'daily_cap'   => max( 1, min( 500, (int) ( $in['daily_cap'] ?? $cur['daily_cap'] ) ) ),
			'holdout_pct' => max( 0, min( 50, (int) ( $in['holdout_pct'] ?? $cur['holdout_pct'] ) ) ),
			'window_days' => max( 3, min( 90, (int) ( $in['window_days'] ?? $cur['window_days'] ) ) ),
		);
		if ( isset( $in['cooldown'] ) && '' !== $in['cooldown'] ) {
			$cfg['cooldown'] = max( 0, min( 365, (int) $in['cooldown'] ) );
		}
		if ( ! $cur['builtin'] ) {
			$cfg['custom'] = true;
			$cfg['goal']   = isset( $in['goal'] ) ? mb_substr( sanitize_text_field( $in['goal'] ), 0, 160 ) : '';
		}
		if ( ! empty( $cur['cross_sell'] ) ) {
			$cfg['cross_sell'] = true;
		}
		if ( ! empty( $in['cross_sell'] ) && ! $cur['builtin'] ) {
			$cfg['cross_sell'] = true;
		}
		$saved[ $id ] = $cfg;
		// Built-in audience overrides replace the default audience entirely.
		update_option( self::OPTION, $saved, false );
		return true;
	}

	public static function set_mode( $id, $mode ) {
		if ( ! in_array( $mode, array( 'off', 'manual', 'auto' ), true ) || ! self::get( $id ) ) {
			return false;
		}
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$saved[ $id ] = isset( $saved[ $id ] ) && is_array( $saved[ $id ] ) ? $saved[ $id ] : array();
		$saved[ $id ]['mode'] = $mode;
		update_option( self::OPTION, $saved, false );
		return true;
	}

	public static function create_custom( array $audience = array() ) {
		$id    = 'c_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$blank = self::blank();
		$blank['audience'] = $audience;
		$saved[ $id ]      = $blank;
		update_option( self::OPTION, $saved, false );
		return $id;
	}

	public static function delete_custom( $id ) {
		$saved = get_option( self::OPTION, array() );
		if ( is_array( $saved ) && isset( $saved[ $id ] ) && ! empty( $saved[ $id ]['custom'] ) ) {
			unset( $saved[ $id ] );
			update_option( self::OPTION, $saved, false );
			return true;
		}
		return false;
	}

	/* -------------------------------------------------------------- audience */

	public static function filter( array $pb ) {
		$f                      = CRDR_Audience::normalize( (array) $pb['audience'] );
		$f['need_phone']        = 1;
		$f['exclude_contacted'] = 1;
		if ( isset( $pb['cooldown'] ) ) {
			$f['cooldown'] = (int) $pb['cooldown'];
		}
		return $f;
	}

	public static function summary( array $pb ) {
		return CRDR_Audience::summary( self::filter( $pb ) );
	}

	/* ----------------------------------------------------------- send rules */

	public static function in_send_window() {
		$h = (int) wp_date( 'G' );
		return $h >= (int) CRDR_Settings::get( 'send_from' ) && $h < (int) CRDR_Settings::get( 'send_to' );
	}

	public static function sent_today( $id ) {
		global $wpdb;
		$start = ( new DateTime( 'today', wp_timezone() ) )->getTimestamp();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . CRDR_DB::contacts() . " WHERE playbook = %s AND kind = 'sms' AND grp = 'treated' AND status = 'ok' AND created_ts >= %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$id,
			$start
		) );
	}

	public static function cap_left( $id, array $pb ) {
		return max( 0, (int) $pb['daily_cap'] - self::sent_today( $id ) );
	}

	/** Deterministic, monthly-rotating holdout assignment. */
	public static function is_holdout( $ckey, $id, $pct ) {
		if ( $pct <= 0 ) {
			return false;
		}
		return ( crc32( $ckey . '|' . $id . '|' . wp_date( 'Y-m' ) ) % 100 ) < $pct;
	}

	/** Number of SMS parts a Persian text uses (70 chars single, 67 per part when split). */
	public static function sms_parts( $text ) {
		$len = mb_strlen( $text );
		return $len <= 70 ? 1 : (int) ceil( $len / 67 );
	}

	/** Compose the message for one customer. @return array [message, extra(coupon vars), coupon_code|''] */
	public static function compose( array $pb, array $cust, $make_coupon, $reco = null ) {
		$extra = array( '{optout}' => (string) CRDR_Settings::get( 'optout_text' ) );
		$code  = '';
		if ( 'percent' === $pb['offer']['type'] ) {
			$days = (int) $pb['offer']['days'];
			if ( $make_coupon ) {
				list( $code, $expire ) = CRDR_Actions::make_coupon( $cust, (int) $pb['offer']['percent'], $days );
			} else {
				$code   = 'XXXXXXXX';
				$expire = time() + $days * DAY_IN_SECONDS;
			}
			$extra['{coupon}']   = $code;
			$extra['{discount}'] = CRDR_Jalali::digits( (string) (int) $pb['offer']['percent'] );
			$extra['{expire}']   = CRDR_Jalali::date( $expire );
		}
		if ( $reco ) {
			$extra['{product}'] = $reco['name'];
			$extra['{url}']     = $reco['url'];
			$extra['{bought}']  = $reco['because'];
		}
		$msg = trim( preg_replace( '/\s+/u', ' ', CRDR_Actions::render_template( $pb['template'], $cust, $extra ) ) );
		return array( $msg, 'XXXXXXXX' === $code ? '' : $code );
	}

	/* ---------------------------------------------------------------- preview */

	public static function preview( $id ) {
		global $wpdb;
		$pb = self::get( $id );
		if ( ! $pb ) {
			return null;
		}
		$f    = self::filter( $pb );
		$sum  = CRDR_Audience::summary( $f );
		$rows = $wpdb->get_results( 'SELECT * FROM ' . CRDR_DB::customers() . ' c ' . CRDR_Audience::where( $f ) . ' ORDER BY c.priority DESC, c.total_spent DESC LIMIT 3', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$samples = array();
		foreach ( $rows as $cust ) {
			$reco = ! empty( $pb['cross_sell'] ) ? CRDR_Recommend::for_customer( $cust['ckey'] ) : null;
			if ( ! empty( $pb['cross_sell'] ) && ! $reco ) {
				continue;
			}
			list( $msg ) = self::compose( $pb, $cust, false, $reco );
			$samples[]   = array( 'name' => $cust['name'], 'message' => $msg, 'parts' => self::sms_parts( $msg ) );
		}
		$take = min( $sum['n'], (int) $pb['daily_cap'] );
		return array(
			'audience'  => $sum['n'],
			'aov'       => $sum['aov'],
			'cap'       => (int) $pb['daily_cap'],
			'today'     => self::sent_today( $id ),
			'holdout'   => (int) $pb['holdout_pct'],
			'samples'   => $samples,
			'scenario'  => CRDR_Stats::scenario( $take, $sum['aov'], (array) $pb['scenario'] ),
			'in_window' => self::in_send_window(),
			'provider'  => CRDR_SMS::provider_label(),
		);
	}

	/* -------------------------------------------------------------------- run */

	/**
	 * Contact up to $limit customers (respecting the daily cap). Safe to call repeatedly:
	 * contacted customers leave the audience through the cooldown.
	 *
	 * @return array sent, held_out, skipped, failed, left (remaining daily cap)
	 */
	public static function run( $id, $limit = 20 ) {
		global $wpdb;
		$res = array( 'sent' => 0, 'held_out' => 0, 'skipped' => 0, 'failed' => 0, 'left' => 0, 'error' => '' );
		$pb  = self::get( $id );
		if ( ! $pb ) {
			$res['error'] = 'کمپین پیدا نشد.';
			return $res;
		}
		if ( 'none' === CRDR_SMS::provider() ) {
			$res['error'] = 'سرویس پیامک تنظیم نشده است.';
			return $res;
		}
		$left = self::cap_left( $id, $pb );
		$res['left'] = $left;
		if ( $left <= 0 ) {
			return $res;
		}
		$take = min( (int) $limit, 50 );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . CRDR_DB::customers() . ' c ' . CRDR_Audience::where( self::filter( $pb ) ) . ' ORDER BY c.priority DESC, c.total_spent DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
			$take * 2
		), ARRAY_A );

		foreach ( $rows as $cust ) {
			if ( $res['sent'] >= $left || $res['sent'] + $res['held_out'] + $res['skipped'] + $res['failed'] >= $take ) {
				break;
			}
			$reco = null;
			if ( ! empty( $pb['cross_sell'] ) ) {
				$reco = CRDR_Recommend::for_customer( $cust['ckey'] );
				if ( ! $reco ) {
					CRDR_DB::add_contact( $cust['ckey'], 'skip', array( 'playbook' => $id, 'status' => 'norec', 'message' => 'محصول مکمل مناسبی پیدا نشد' ) );
					$res['skipped']++;
					continue;
				}
			}
			if ( self::is_holdout( $cust['ckey'], $id, (int) $pb['holdout_pct'] ) ) {
				CRDR_DB::add_contact( $cust['ckey'], 'sms', array( 'playbook' => $id, 'grp' => 'holdout', 'status' => 'holdout' ) );
				$res['held_out']++;
				continue;
			}
			list( $msg, $code ) = self::compose( $pb, $cust, true, $reco );
			list( $ok, $err )   = CRDR_SMS::send( $cust['phone'], $msg );
			CRDR_DB::add_contact( $cust['ckey'], 'sms', array(
				'playbook' => $id, 'grp' => 'treated', 'coupon' => $code, 'message' => $msg,
				'status' => $ok ? 'ok' : 'failed', 'error' => $ok ? '' : $err,
			) );
			if ( $ok ) {
				$res['sent']++;
			} else {
				$res['failed']++;
				$res['error'] = $err;
				if ( $res['failed'] >= 3 ) { // do not hammer a broken gateway.
					break;
				}
			}
		}
		$res['left'] = self::cap_left( $id, $pb );
		return $res;
	}

	/* ------------------------------------------------------------- automation */

	public static function init() {
		add_action( self::HOURLY, array( __CLASS__, 'hourly' ) );
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOURLY );
	}

	/** Runs inside WP-Cron every hour: auto playbooks send a small batch, only inside the allowed hours. */
	public static function hourly() {
		if ( ! self::in_send_window() || get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		foreach ( self::all() as $id => $pb ) {
			if ( 'auto' === $pb['mode'] ) {
				self::run( $id, 25 );
			}
		}
		delete_transient( self::LOCK );
	}

	/* ---------------------------------------------------------------- results */

	/**
	 * Measure a playbook: share of contacted customers who ordered again within the window,
	 * compared with the holdout group that was not contacted.
	 *
	 * @return array treated/holdout stats, test, coupon redemptions, immature count
	 */
	public static function results( $id ) {
		global $wpdb;
		$pb = self::get( $id );
		if ( ! $pb ) {
			return null;
		}
		$w    = (int) $pb['window_days'];
		$k    = CRDR_DB::contacts();
		$o    = CRDR_DB::orders();
		$mature_before = time() - $w * DAY_IN_SECONDS;

		$sql = $wpdb->prepare(
			"SELECT t.grp, COUNT(*) n, SUM(CASE WHEN a.cnt > 0 THEN 1 ELSE 0 END) conv, COALESCE(SUM(a.rev), 0) rev
			 FROM {$k} t
			 LEFT JOIN (
			   SELECT ct.id, COUNT(od.order_id) cnt, SUM(od.net) rev
			   FROM {$k} ct JOIN {$o} od ON od.ckey = ct.ckey AND od.ts > ct.created_ts AND od.ts <= ct.created_ts + %d
			   WHERE ct.playbook = %s AND ct.kind = 'sms'
			   GROUP BY ct.id
			 ) a ON a.id = t.id
			 WHERE t.playbook = %s AND t.kind = 'sms' AND t.status IN ('ok','holdout') AND t.created_ts <= %d
			 GROUP BY t.grp", // phpcs:ignore WordPress.DB.PreparedSQL
			$w * DAY_IN_SECONDS, $id, $id, $mature_before
		);
		$g = array( 'treated' => array( 'n' => 0, 'conv' => 0, 'rev' => 0.0 ), 'holdout' => array( 'n' => 0, 'conv' => 0, 'rev' => 0.0 ) );
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( isset( $g[ $r['grp'] ] ) ) {
				$g[ $r['grp'] ] = array( 'n' => (int) $r['n'], 'conv' => (int) $r['conv'], 'rev' => (float) $r['rev'] );
			}
		}
		$immature = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$k} WHERE playbook = %s AND kind = 'sms' AND status IN ('ok','holdout') AND created_ts > %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$id, $mature_before
		) );
		$sent_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$k} WHERE playbook = %s AND kind = 'sms' AND grp = 'treated' AND status = 'ok'", $id // phpcs:ignore WordPress.DB.PreparedSQL
		) );

		$test = CRDR_Stats::two_prop( $g['treated']['conv'], $g['treated']['n'], $g['holdout']['conv'], $g['holdout']['n'] );

		// Coupon redemptions among the customers who received a code.
		$codes   = $wpdb->get_col( $wpdb->prepare( "SELECT coupon FROM {$k} WHERE playbook = %s AND coupon <> '' AND status = 'ok' LIMIT 1000", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$redeemed = 0;
		foreach ( $codes as $code ) {
			$cid = wc_get_coupon_id_by_code( $code );
			if ( $cid ) {
				$c = new WC_Coupon( $cid );
				if ( $c->get_usage_count() > 0 ) {
					$redeemed++;
				}
			}
		}

		return array(
			'treated'    => $g['treated'],
			'holdout'    => $g['holdout'],
			'test'       => $test,
			'immature'   => $immature,
			'sent_total' => $sent_total,
			'codes'      => count( $codes ),
			'redeemed'   => $redeemed,
			'window'     => $w,
		);
	}
}
