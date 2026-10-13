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

	/** What sent_today() answers when the database could not count: more than any daily cap (max 500), so nothing more is sent. */
	const CAP_UNKNOWN = 1000000000;

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

	/** Which placeholders a campaign's template may use, given its offer and type. */
	public static function template_context( array $pb ) {
		return array(
			'offer' => isset( $pb['offer']['type'] ) && 'percent' === $pb['offer']['type'],
			'reco'  => ! empty( $pb['cross_sell'] ),
			'require_optout' => true, // campaigns are promotional: the opt-out phrase is mandatory
		);
	}

	/** Persian problems with a campaign's message text; empty = valid. */
	public static function template_errors( array $pb ) {
		return CRDR_Template::validate( isset( $pb['template'] ) ? $pb['template'] : '', self::template_context( $pb ) );
	}

	/**
	 * Validate and store the editable fields of one playbook.
	 *
	 * @return bool|string[] true when saved, false when the campaign does not exist, or a list of Persian
	 *                       validation errors (nothing is stored; the submitted values are kept as a short-lived
	 *                       draft so the form can be shown again).
	 */
	public static function save( $id, array $in ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$cur   = self::get( $id );
		if ( ! $cur ) {
			return false;
		}
		$cfg    = self::build_config( $cur, $in );
		$errors = self::template_errors( $cfg );
		if ( $errors ) {
			set_transient( self::draft_key( $id ), array( 'cfg' => $cfg, 'errors' => $errors ), 15 * MINUTE_IN_SECONDS );
			return $errors;
		}
		delete_transient( self::draft_key( $id ) );
		$saved[ $id ] = $cfg;
		// Built-in audience overrides replace the default audience entirely.
		update_option( self::OPTION, $saved, false );
		CRDR_Send::build_epoch_bump(); // a fixed text/offer: customers whose message could not be built are tried again.
		return true;
	}

	private static function draft_key( $id ) {
		return 'crdr_draft_' . get_current_user_id() . '_' . substr( md5( (string) $id ), 0, 12 );
	}

	/** The rejected form values and their errors (one-shot), or null. */
	public static function take_draft( $id ) {
		$d = get_transient( self::draft_key( $id ) );
		delete_transient( self::draft_key( $id ) );
		return is_array( $d ) ? $d : null;
	}

	/** Turn submitted form values into a campaign configuration (not validated against the template rules). */
	private static function build_config( array $cur, array $in ) {
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
			// Not cut to length here: a silent cut could slice a {placeholder} in half. validate() rejects texts that are too long.
			'template'    => isset( $in['template'] ) ? sanitize_textarea_field( $in['template'] ) : $cur['template'],
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
		return $cfg;
	}

	/**
	 * Switch a campaign's mode. Turning it on (manual/auto) is refused while its message text is invalid.
	 *
	 * @return bool|string[] true, false (unknown campaign / mode), or the Persian template errors
	 */
	public static function set_mode( $id, $mode ) {
		if ( ! in_array( $mode, array( 'off', 'manual', 'auto' ), true ) || ! self::get( $id ) ) {
			return false;
		}
		if ( 'off' !== $mode ) {
			$errors = self::template_errors( self::get( $id ) );
			if ( $errors ) {
				return $errors;
			}
		}
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$saved[ $id ] = isset( $saved[ $id ] ) && is_array( $saved[ $id ] ) ? $saved[ $id ] : array();
		$saved[ $id ]['mode'] = $mode;
		update_option( self::OPTION, $saved, false );
		CRDR_Send::build_epoch_bump();
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

	/** Inside the allowed sending hours (site timezone)? Same rule as before: from <= hour < to. */
	public static function in_send_window( $ts = null ) {
		// The filter only lets a site (or a test) pin the clock; by default this is the real time.
		$ts = null === $ts ? (int) apply_filters( 'crdr_send_window_now', time() ) : (int) $ts;
		return CRDR_Send_Rules::in_window(
			CRDR_Send_Rules::hour_in_tz( $ts, wp_timezone() ),
			CRDR_Settings::get( 'send_from' ),
			CRDR_Settings::get( 'send_to' )
		);
	}

	/**
	 * SMS counted against today's cap: sent ones plus those still being sent or whose outcome is unknown
	 * (an unknown SMS may well have gone out, so it must use up the cap).
	 */
	public static function sent_today( $id ) {
		global $wpdb;
		$start = ( new DateTime( 'today', wp_timezone() ) )->getTimestamp();
		$n     = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . CRDR_DB::contacts() . " WHERE playbook = %s AND kind = 'sms' AND grp = 'treated' AND status IN ('ok','pending','unknown') AND created_ts >= %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$id,
			$start
		) );
		// A count that could not be read is NOT zero: zero would hand out the whole daily cap again. It fails closed (CAP_UNKNOWN is far above any cap).
		if ( null === $n || $wpdb->last_error ) {
			return self::CAP_UNKNOWN;
		}
		return (int) $n;
	}

	/** What to show as "sent today": the count, or 0 when it could not be read (the callers then block; see sent_today()). */
	public static function today_shown( $id ) {
		$n = self::sent_today( $id );
		return self::CAP_UNKNOWN === $n ? 0 : $n;
	}

	public static function cap_left( $id, array $pb ) {
		return max( 0, (int) $pb['daily_cap'] - self::sent_today( $id ) );
	}

	/**
	 * The campaign WAVE a moment belongs to: the calendar month in the site's timezone ("2026-10"). Filterable (crdr_campaign_wave).
	 * Inside one wave a customer's holdout assignment never changes; a new wave draws again.
	 */
	public static function wave( $ts = null ) {
		return (string) apply_filters( 'crdr_campaign_wave', null === $ts ? wp_date( 'Y-m' ) : wp_date( 'Y-m', (int) $ts ), $ts );
	}

	/** Deterministic holdout assignment, stable within a wave (and rotating between waves, so that nobody is held out for ever). */
	public static function is_holdout( $ckey, $id, $pct ) {
		if ( $pct <= 0 ) {
			return false;
		}
		return ( crc32( $ckey . '|' . $id . '|' . self::wave() ) % 100 ) < $pct;
	}

	/**
	 * Was this customer ASSIGNED to the holdout of this campaign less than `window_days` ago (counted from the first time)? Then the customer stays out
	 * of the campaign until that measurement window is over, whatever the new wave's draw says: a held-out customer who got a message inside their own
	 * measurement window would be a contaminated control. After the window the customer can be drawn again (and then counts from that first row only).
	 *
	 * @return bool|null null when the database could not answer (the caller must not message: see run_claimed)
	 */
	public static function held_out_recently( $ckey, $id, $window_days ) {
		global $wpdb;
		$first = $wpdb->get_var( $wpdb->prepare(
			'SELECT MIN(created_ts) FROM ' . CRDR_DB::contacts() . " WHERE playbook = %s AND ckey = %s AND kind = 'sms' AND grp = 'holdout' AND status = 'holdout'", // phpcs:ignore WordPress.DB.PreparedSQL
			$id,
			$ckey
		) );
		if ( $wpdb->last_error ) {
			return null;
		}
		return null !== $first && (int) $first >= time() - (int) $window_days * DAY_IN_SECONDS;
	}

	/** Number of SMS parts a Persian text uses (70 chars single, 67 per part when split). */
	public static function sms_parts( $text ) {
		$len = mb_strlen( $text );
		return $len <= 70 ? 1 : (int) ceil( $len / 67 );
	}

	/**
	 * Compose the message for one customer.
	 *
	 * @return array [ message, coupon_code|'', render ]; render['ok'] is false when a placeholder could not be
	 *               filled or the template is malformed. Such a message must NOT be sent.
	 */
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
		$render = CRDR_Actions::render_checked( $pb['template'], $cust, $extra );
		$msg    = trim( preg_replace( '/\s+/u', ' ', $render['text'] ) );
		return array( $msg, 'XXXXXXXX' === $code ? '' : $code, $render );
	}

	/**
	 * Customers this campaign currently leaves alone, by kind.
	 *   build    the message could not be built / was refused / the provider failed without a reason (nobuild, retry)
	 *   rejected the provider says the number is invalid
	 *
	 * @return array count (int), reason (the latest reason, '' when none)
	 */
	public static function set_aside( $id, $kind = 'build' ) {
		global $wpdb;
		$k = CRDR_DB::contacts();
		if ( 'rejected' === $kind ) {
			$in    = "status = 'rejected'";
			$since = CRDR_Send::reject_since( $id );
		} else {
			$in    = "status IN ('nobuild','retry')";
			$since = CRDR_Send::build_since();
		}
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ckey) FROM {$k} WHERE playbook = %s AND {$in} AND created_ts > %d", $id, $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $n ) {
			return array( 'count' => 0, 'reason' => '' );
		}
		$why = (string) $wpdb->get_var( $wpdb->prepare( "SELECT error FROM {$k} WHERE playbook = %s AND {$in} AND created_ts > %d ORDER BY id DESC LIMIT 1", $id, $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( 'count' => $n, 'reason' => $why );
	}

	/* ---------------------------------------------------------------- preview */

	public static function preview( $id ) {
		global $wpdb;
		$pb = self::get( $id );
		if ( ! $pb ) {
			return null;
		}
		$sync = CRDR_Sync::send_guard();
		if ( $sync['blocked'] ) { // same shape as the real answer, but the customer table is not read at all
			return array(
				'audience'        => 0,
				'aov'             => 0.0,
				'cap'             => (int) $pb['daily_cap'],
				'today'           => self::today_shown( $id ),
				'holdout'         => (int) $pb['holdout_pct'],
				'samples'         => array(),
				'scenario'        => CRDR_Stats::scenario( 0, 0, (array) $pb['scenario'] ),
				'in_window'       => self::in_send_window(),
				'provider'        => CRDR_SMS::provider_label(),
				'mode'            => $pb['mode'],
				'template_errors' => self::template_errors( $pb ),
				'blocked'         => $sync['code'],
				'blocked_message' => $sync['message'],
				'can_override'    => false,
				'site_warning'    => CRDR_Site::warning(),
			);
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
		$take      = min( $sum['n'], (int) $pb['daily_cap'] );
		$in_window = self::in_send_window();
		// What would a manual click do right now (without the explicit override)? The server decides again on every run.
		$gate = CRDR_Send_Rules::gate( $pb['mode'], 'manual', $in_window, false, false );
		$today_known = self::CAP_UNKNOWN !== self::sent_today( $id );
		return array(
			'audience'        => $sum['n'],
			'aov'             => $sum['aov'],
			'cap'             => (int) $pb['daily_cap'],
			'today'           => self::today_shown( $id ),
			'holdout'         => (int) $pb['holdout_pct'],
			'samples'         => $samples,
			'scenario'        => CRDR_Stats::scenario( $take, $sum['aov'], (array) $pb['scenario'] ),
			'in_window'       => $in_window,
			'provider'        => CRDR_SMS::provider_label(),
			'mode'            => $pb['mode'],
			'template_errors' => self::template_errors( $pb ),
			'blocked'         => ! $today_known ? 'db_error' : ( $gate['allowed'] ? '' : $gate['code'] ),
			'blocked_message' => ! $today_known ? CRDR_Health::message( 'db_error' ) : ( $gate['allowed'] ? '' : CRDR_Send_Rules::gate_message( $gate['code'] ) ),
			'can_override'    => $today_known && ( 'outside_window' === $gate['code'] ) && CRDR_Send::can_override_window(),
			'site_warning'    => CRDR_Site::warning(),
		);
	}

	/* -------------------------------------------------------------------- run */

	/**
	 * Contact up to $limit customers (respecting the daily cap). Safe to call repeatedly and from several
	 * processes at once: every customer is claimed atomically before an SMS is sent (see CRDR_Send).
	 *
	 * The server decides whether the run is allowed (campaign mode + sending hours); the UI only mirrors that.
	 *
	 * @param array $opts trigger: 'manual' (default, a person pressed run) | 'cron';
 *                    site_ack: the one-time token CRDR_Site issued with the preview (manual runs on a site that is not the recorded one only);
	 *                    override_window: ask to send outside the allowed hours (manual only, needs the
	 *                    'manage_options' capability, and is recorded in the run log)
	 * @return array sent, held_out, skipped, failed, unknown, left (remaining daily cap), error, blocked (reason code)
	 */
	public static function run( $id, $limit = 20, array $opts = array() ) {
		$opts = array_merge( array( 'trigger' => 'manual', 'override_window' => false ), $opts );
		$res  = array( 'sent' => 0, 'held_out' => 0, 'skipped' => 0, 'failed' => 0, 'unknown' => 0, 'left' => 0, 'error' => '', 'blocked' => '' );
		$pb   = self::get( $id );
		if ( ! $pb ) {
			$res['error'] = 'کمپین پیدا نشد.';
			return $res;
		}
		$in_window = self::in_send_window();
		$gate      = CRDR_Send_Rules::gate( $pb['mode'], $opts['trigger'], $in_window, ! empty( $opts['override_window'] ), CRDR_Send::can_override_window() );
		$entry     = array(
			'run'       => bin2hex( random_bytes( 8 ) ),
			'ts'        => time(),
			'playbook'  => $id,
			'trigger'   => $opts['trigger'],
			'user'      => get_current_user_id(),
			'mode'      => $pb['mode'],
			'in_window' => $in_window,
			'override'  => $gate['override'], // true = the sending hours were deliberately bypassed by that user.
		);
		$block = function ( $code, $message ) use ( &$res, &$entry, $id ) {
			$res['error']   = $message;
			$res['blocked'] = $code;
			if ( 'manual' === $entry['trigger'] ) { // a refused click is worth keeping (the person also sees the answer).
				CRDR_Send::log_run( $entry + array( 'result' => 'blocked:' . $code ) );
			} else { // nobody watches cron: one state record per campaign (no log entry per tick), see CRDR_Health.
				CRDR_Health::record_block( $id, $code );
			}
			return $res;
		};
		// A copy of the shop (staging, local, a restored backup on another address) must not send the real shop's campaigns by itself.
		//  - Anything that is not a person pressing the button (cron, an unknown trigger) is refused outright and never accepts a token.
		//  - A manual run on such a site needs an Administrator holding the one-time token the preview issued for this campaign (CRDR_Site);
		//    that is checked here, on the server, and spent below once every other guard has passed. Every other guard still applies.
		$ack_scope = 'pb:' . $id;
		if ( 'manual' !== $opts['trigger'] ) {
			$site = CRDR_Site::status();
			if ( ! $site['ok'] ) {
				return $block( 'site_changed', CRDR_Site::message( $site ) );
			}
		} else {
			$why = CRDR_Site::manual_block( $ack_scope, isset( $opts['site_ack'] ) ? $opts['site_ack'] : '' );
			if ( '' !== $why ) {
				return $block( 'site_unconfirmed', $why );
			}
		}
		// Customer data being rebuilt, or a rebuild that stopped half way: no campaign may select or message anybody.
		$sync = CRDR_Sync::send_guard();
		if ( $sync['blocked'] ) {
			return $block( $sync['code'], $sync['message'] );
		}
		if ( ! $gate['allowed'] ) {
			return $block( $gate['code'], CRDR_Send_Rules::gate_message( $gate['code'] ) );
		}
		if ( 'none' === CRDR_SMS::provider() ) {
			return $block( 'no_provider', 'سرویس پیامک تنظیم نشده است.' );
		}
		$problems = self::template_errors( $pb );
		if ( $problems ) {
			return $block( 'template', 'متن پیام این کمپین معتبر نیست و پیامی ارسال نشد: ' . $problems[0] );
		}
		$problems = CRDR_Settings::optout_errors( CRDR_Settings::all() ); // the phrase {optout} becomes: empty means "no way to stop the messages"
		if ( $problems ) {
			return $block( 'template', $problems[0] . ' پیامی ارسال نشد.' );
		}
		// The plugin's tables must be complete (a missing column or the unique claim index would make "no duplicate send" untrue).
		$schema = CRDR_DB::ensure_schema();
		if ( $schema ) {
			return $block( 'schema', CRDR_DB::schema_message( $schema ) );
		}
		// Everything else allows the run: spend the site acknowledgement now (no-op when the site is the recorded one).
		if ( 'manual' === $opts['trigger'] && ! CRDR_Site::consume_ack( $ack_scope, isset( $opts['site_ack'] ) ? $opts['site_ack'] : '' ) ) {
			return $block( 'site_unconfirmed', 'تأییدیه‌ی ارسال از این سایت معتبر نیست یا هم‌اکنون استفاده شده است. پیش‌نمایش را دوباره باز کنید. پیامکی فرستاده نشد.' );
		}
		$lock = CRDR_Send::run_lock( $id );
		if ( 'busy' === $lock ) {
			return $block( 'busy', 'این کمپین هم‌اکنون در حال اجراست (اجرای خودکار یا دستی دیگر). چند دقیقه بعد دوباره تلاش کنید.' );
		}
		try {
			$res = self::run_claimed( $id, $pb, (int) $limit, $entry['run'], $res );
		} finally {
			if ( 'got' === $lock ) {
				CRDR_Send::run_unlock( $id );
			}
		}
		if ( in_array( $res['blocked'], array( 'db_error', 'schema' ), true ) ) { // the database failed while the run was going: not "0 customers", a problem to show
			if ( 'manual' === $entry['trigger'] ) {
				CRDR_Send::log_run( $entry + array( 'result' => 'blocked:' . $res['blocked'], 'counts' => array_intersect_key( $res, array_flip( array( 'sent', 'held_out', 'skipped', 'failed', 'unknown' ) ) ) ) );
			} else {
				CRDR_Health::record_block( $id, $res['blocked'] );
			}
			return $res;
		}
		CRDR_Send::log_run( $entry + array(
			'result' => 'ran',
			'counts' => array_intersect_key( $res, array_flip( array( 'sent', 'held_out', 'skipped', 'failed', 'unknown' ) ) ),
		) );
		if ( 'cron' === $opts['trigger'] ) { // an executed run, as opposed to a blocked one.
			CRDR_Health::record_run( $id, $res );
		}
		return $res;
	}

	/** The sending loop; only called while this campaign's run lock is held (or unsupported). */
	private static function run_claimed( $id, array $pb, $limit, $run_id, array $res ) {
		global $wpdb;
		CRDR_Send::recover_stale();
		if ( self::CAP_UNKNOWN === self::sent_today( $id ) ) { // the daily count could not be read: that is not "0 sent today"
			$res['error']   = CRDR_Health::message( 'db_error' );
			$res['blocked'] = 'db_error';
			return $res;
		}
		$left        = self::cap_left( $id, $pb );
		$res['left'] = $left;
		if ( $left <= 0 ) {
			return $res;
		}
		$take     = min( $limit, 50 );
		$cooldown = isset( $pb['cooldown'] ) ? (int) $pb['cooldown'] : null;
		$rows     = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . CRDR_DB::customers() . ' c ' . CRDR_Audience::where( self::filter( $pb ) ) . ' AND ' . CRDR_Send::not_set_aside_sql( $id ) . ' ORDER BY c.priority DESC, c.total_spent DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
			$take * 2
		), ARRAY_A );
		if ( $wpdb->last_error ) { // an unreadable audience is NOT an empty audience: say so instead of "nobody to message"
			$res['error']   = CRDR_Health::message( 'db_error' );
			$res['blocked'] = 'db_error';
			return $res;
		}
		$streak   = 0; // build failures / refused texts in a row: probably a general cause
		$gw_fails = 0; // failures in a row that say nothing about the customer (network, unexplained): the circuit breaker

		foreach ( $rows as $cust ) {
			if ( $res['sent'] >= $left || $res['sent'] + $res['held_out'] + $res['skipped'] + $res['failed'] >= $take ) {
				break;
			}
			$reco = null;
			if ( ! empty( $pb['cross_sell'] ) ) {
				$reco = CRDR_Recommend::for_customer( $cust['ckey'] );
				if ( ! $reco ) {
					CRDR_DB::add_contact( $cust['ckey'], 'skip', array( 'playbook' => $id, 'status' => 'norec', 'message' => 'محصول مکمل مناسبی پیدا نشد', 'run_id' => $run_id ) );
					$res['skipped']++;
					continue;
				}
			}
			$holdout = self::is_holdout( $cust['ckey'], $id, (int) $pb['holdout_pct'] );
			if ( ! $holdout && (int) $pb['holdout_pct'] > 0 ) { // a control stays a control for the length of their measurement window
				$sticky = self::held_out_recently( $cust['ckey'], $id, (int) $pb['window_days'] );
				if ( null === $sticky ) { // cannot tell: do not message (a contaminated control is worse than a late message)
					$res['error']   = CRDR_Health::message( 'db_error' );
					$res['blocked'] = 'db_error';
					break;
				}
				$holdout = $sticky;
			}
			$claim   = CRDR_Send::claim( $cust['ckey'], $id, $run_id, $holdout ? 'holdout' : 'treated', $cooldown );
			if ( isset( $claim['skip'] ) ) {
				if ( 'syncing' === $claim['skip'] ) { // a rebuild started while this run was going: stop at once.
					$sync           = CRDR_Sync::send_guard();
					$res['error']   = $sync['message'];
					$res['blocked'] = $sync['code'] ? $sync['code'] : 'sync_running';
					break;
				}
				if ( 'error' === $claim['skip'] ) { // cannot record the claim -> cannot guarantee "no duplicate": stop.
					$res['error']   = 'ثبت وضعیت ارسال ممکن نشد؛ برای جلوگیری از پیام تکراری، اجرا متوقف شد. ' . CRDR_Health::message( 'db_error' );
					$res['blocked'] = 'db_error';
					break;
				}
				if ( 'schema' === $claim['skip'] ) {
					$res['error']   = CRDR_DB::schema_message( CRDR_DB::ensure_schema() ?: array( '?' ) );
					$res['blocked'] = 'schema';
					break;
				}
				$res['skipped']++; // taken by a parallel run, or contacted/opted out since the audience was read.
				continue;
			}
			$row = $claim['id'];
			if ( $holdout ) {
				CRDR_Send::finalize( $row, 'holdout' );
				$res['held_out']++;
				continue;
			}
			$today = self::sent_today( $id ); // includes this claim
			if ( self::CAP_UNKNOWN === $today ) { // could not count: stop, nothing more is sent
				CRDR_Send::release( $row );
				$res['error']   = CRDR_Health::message( 'db_error' );
				$res['blocked'] = 'db_error';
				break;
			}
			if ( $today > (int) $pb['daily_cap'] ) {
				CRDR_Send::release( $row );
				break;
			}
			// Can the message be built at all? Check with a placeholder code first, so that no discount code is
			// created (and left unused) for a customer whose message turns out to be unbuildable.
			$why = '';
			try {
				list( , , $dry ) = self::compose( $pb, $cust, false, $reco );
				if ( $dry['ok'] ) {
					list( $msg, $code, $render ) = self::compose( $pb, $cust, true, $reco );
					$why = $render['ok'] ? ( '' === $msg ? 'متن پیامک پس از جایگزینی متغیرها خالی است.' : '' ) : CRDR_Template::explain( $render );
				} else {
					$why = CRDR_Template::explain( $dry );
				}
			} catch ( \Throwable $e ) {
				$why = 'ساخت کد تخفیف یا پیام ناموفق بود (' . get_class( $e ) . ').';
			}
			if ( '' !== $why ) {
				// Nothing was sent and nothing counts as contact: set the customer aside instead of retrying on every run.
				CRDR_Send::mark_unbuildable( $row, $why );
				$res['failed']++;
				$res['error'] = $why;
				if ( ++$streak >= CRDR_Send_Rules::BUILD_FAIL_STREAK ) { // probably a general cause: stop here.
					break;
				}
				continue;
			}
			$out = CRDR_Send::send_claimed( $row, $cust['phone'], $msg, $code );
			if ( 'sent' === $out['state'] ) {
				$res['sent']++;
				$streak   = 0; // building and sending work, so a failure further up was about that customer, not general.
				$gw_fails = 0;
			} elseif ( 'unknown' === $out['state'] ) {
				// The SMS may have left. Do not retry it and do not carry on blindly: stop and let the owner look.
				$res['unknown']++;
				$res['error'] = $out['error'];
				break;
			} else {
				// Nothing was sent. Who is at fault decides what happens next (CRDR_SMS::deliver, CRDR_Send_Rules::code_scope):
				$res['failed']++;
				$res['error'] = $out['error'];
				$scope        = isset( $out['scope'] ) ? $out['scope'] : 'gateway';
				if ( 'sync' === $scope ) { // refused at the last gate because a rebuild started: nobody is at fault, stop the run.
					$res['blocked'] = isset( $out['code'] ) ? $out['code'] : 'sync_running';
					break;
				}
				if ( 'optout' === $scope ) { // opted out after being claimed: not a failure, the customer is simply left alone
					$res['failed']--;
					$res['skipped']++;
					$res['error'] = '';
					continue;
				}
				if ( 'db' === $scope ) { // the database could not answer the last opt-out check: stop, send nothing more
					$res['blocked'] = 'db_error';
					break;
				}
				if ( 'recipient' === $scope ) {
					continue; // this number only: it is set aside ("rejected") and says nothing about the gateway.
				}
				if ( 'message' === $scope ) { // this text: set aside like an unbuildable message.
					if ( ++$streak >= CRDR_Send_Rules::BUILD_FAIL_STREAK ) {
						break;
					}
					continue;
				}
				if ( 'fatal' === $scope || 'rate' === $scope ) { // account, key, credit, request, IP: it will fail for everybody (or the provider is throttling us), stop now.
					break;
				}
				if ( 'claim' === $scope ) { // the claim was lost before the gateway (recovered by another process): this customer only, nothing was sent
					continue;
				}
				if ( ++$gw_fails >= 3 ) { // network trouble or an unexplained failure three times in a row: do not hammer.
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
		$all  = self::all();
		$auto = array_keys( array_filter( $all, function ( $pb ) {
			return 'auto' === $pb['mode'];
		} ) );
		CRDR_Health::prune( array_keys( $all ) );
		// Not this site's address (a copy of the database): say so for each automatic campaign and send nothing.
		if ( ! CRDR_Site::automatic_allowed() ) {
			foreach ( $auto as $id ) {
				CRDR_Health::record_block( $id, 'site_changed' );
			}
			return;
		}
		CRDR_Health::clear_code_all( 'site_changed' );
		// Being outside the hours is normal at night and stays silent. A window that can never open is a mistake:
		// say so (the rule itself is unchanged, nothing is sent).
		if ( ! CRDR_Health::window_valid( CRDR_Settings::get( 'send_from' ), CRDR_Settings::get( 'send_to' ) ) ) {
			foreach ( $auto as $id ) {
				CRDR_Health::record_block( $id, 'window_invalid' );
			}
			return;
		}
		CRDR_Health::clear_code_all( 'window_invalid' );
		// A tick that overlaps the previous one (its 5-minute lock is still held) just leaves the work to it.
		if ( ! self::in_send_window() || get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		try {
			CRDR_Send::recover_stale();
			foreach ( self::all() as $id => $pb ) {
				if ( 'auto' === $pb['mode'] ) {
					self::run( $id, 25, array( 'trigger' => 'cron' ) );
				}
			}
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/* ---------------------------------------------------------------- results */

	/**
	 * Measure a playbook: did the customers chosen for the message order again within the window, compared with the holdout group that was deliberately
	 * not messaged?
	 *
	 * DEFINITIONS (Phase 4.2). Written down because every denominator is a choice:
	 *  - The UNIT is the customer, once. A customer is counted in the group of their FIRST assignment in this campaign: the earliest row of kind 'sms'
	 *    with any assignment status (everything except 'norec'). Later rows (a second wave, a retry, a customer who was held out one month and drawn
	 *    for the message the next) never count again and never move the customer to the other group.
	 *  - The window starts at that first assignment's time and lasts window_days. Only customers whose window is over are counted (mature); the others
	 *    are reported as `immature`.
	 *  - PRIMARY comparison, intention to treat: `treated` = every customer first assigned to receive the message, whatever happened to the message
	 *    afterwards (accepted, failed, number rejected, unknown, text could not be built); `holdout` = every customer first assigned to the control.
	 *    Both groups were drawn the same way, so this comparison keeps the randomisation. Conversion = at least one order in the window; `rev` = their net sales.
	 *  - SECONDARY, accepted only: `accepted` = the treated customers for whom the SMS service accepted at least one message (status ok), against the same
	 *    holdout. It says how well the message works when it is delivered, but unreachable numbers are removed from one side only: read it next to the primary.
	 *  - 'unknown' (the SMS may have gone out) is inside the primary treated group by assignment, and is counted in `unknown` for information.
	 *
	 * @return array treated, holdout (n, conv, rev), test (primary), accepted (n, conv, rev), test_accepted, immature, sent_total, unknown, codes, redeemed, window, basis
	 */
	public static function results( $id ) {
		global $wpdb;
		$pb = self::get( $id );
		if ( ! $pb ) {
			return null;
		}
		$w             = (int) $pb['window_days'];
		$k             = CRDR_DB::contacts();
		$o             = CRDR_DB::orders();
		$mature_before = time() - $w * DAY_IN_SECONDS;
		$assigned      = "'" . implode( "','", CRDR_Send_Rules::ASSIGNED ) . "'"; // the same list outcome()['measured'] is derived from
		$first         = "SELECT ckey, MIN(id) mid, MAX(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) acc FROM {$k} WHERE playbook = %s AND kind = 'sms' AND status IN ({$assigned}) GROUP BY ckey";

		$sql = $wpdb->prepare(
			"SELECT c.grp, COUNT(*) n, SUM(CASE WHEN c.cnt > 0 THEN 1 ELSE 0 END) conv, COALESCE(SUM(c.rev), 0) rev,
			        SUM(c.acc) acc_n, SUM(CASE WHEN c.acc = 1 AND c.cnt > 0 THEN 1 ELSE 0 END) acc_conv, COALESCE(SUM(CASE WHEN c.acc = 1 THEN c.rev ELSE 0 END), 0) acc_rev
			 FROM (
			   SELECT t.grp grp, m.acc acc, COUNT(od.order_id) cnt, COALESCE(SUM(od.net), 0) rev
			   FROM {$k} t JOIN ( {$first} ) m ON m.mid = t.id
			   LEFT JOIN {$o} od ON od.ckey = t.ckey AND od.ts > t.created_ts AND od.ts <= t.created_ts + %d
			   WHERE t.created_ts <= %d
			   GROUP BY t.id, t.grp, m.acc
			 ) c GROUP BY c.grp", // phpcs:ignore WordPress.DB.PreparedSQL
			$id, $w * DAY_IN_SECONDS, $mature_before
		);
		$g = array(
			'treated' => array( 'n' => 0, 'conv' => 0, 'rev' => 0.0 ),
			'holdout' => array( 'n' => 0, 'conv' => 0, 'rev' => 0.0 ),
		);
		$acc = array( 'n' => 0, 'conv' => 0, 'rev' => 0.0 );
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( isset( $g[ $r['grp'] ] ) ) {
				$g[ $r['grp'] ] = array( 'n' => (int) $r['n'], 'conv' => (int) $r['conv'], 'rev' => (float) $r['rev'] );
				if ( 'treated' === $r['grp'] ) {
					$acc = array( 'n' => isset( $r['acc_n'] ) ? (int) $r['acc_n'] : 0, 'conv' => isset( $r['acc_conv'] ) ? (int) $r['acc_conv'] : 0, 'rev' => isset( $r['acc_rev'] ) ? (float) $r['acc_rev'] : 0.0 );
				}
			}
		}
		// customers whose first assignment is still inside its measurement window
		$immature = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$k} t JOIN ( {$first} ) m ON m.mid = t.id WHERE t.created_ts > %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$id, $mature_before
		) );
		$sent_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$k} WHERE playbook = %s AND kind = 'sms' AND grp = 'treated' AND status = 'ok'", $id // phpcs:ignore WordPress.DB.PreparedSQL
		) );

		// Messages whose outcome is unknown may or may not have gone out; they are inside the primary treated group (by assignment). Counted for information.
		$unknown = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$k} WHERE playbook = %s AND kind = 'sms' AND grp = 'treated' AND status = 'unknown'", $id // phpcs:ignore WordPress.DB.PreparedSQL
		) );

		$test          = CRDR_Stats::two_prop( $g['treated']['conv'], $g['treated']['n'], $g['holdout']['conv'], $g['holdout']['n'] );
		$test_accepted = CRDR_Stats::two_prop( $acc['conv'], $acc['n'], $g['holdout']['conv'], $g['holdout']['n'] );

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
			'basis'         => 'itt',       // intention to treat: see the definitions above
			'treated'       => $g['treated'],
			'holdout'       => $g['holdout'],
			'test'          => $test,
			'accepted'      => $acc,        // secondary: treated customers with at least one message accepted by the SMS service
			'test_accepted' => $test_accepted,
			'immature'      => $immature,
			'sent_total'    => $sent_total, // accepted by the SMS service (not "delivered": nothing confirms that)
			'unknown'       => $unknown,
			'codes'         => count( $codes ),
			'redeemed'      => $redeemed,
			'window'        => $w,
		);
	}
}
