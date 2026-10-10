<?php
defined( 'ABSPATH' ) || exit;

/**
 * Turns the stored analytics into plain-language growth suggestions with transparent scenarios.
 *
 * Every number is either measured from the store's own orders or an explicit scenario assumption
 * (clearly labelled). Nothing here is a promise: once a campaign has run with a holdout group,
 * the measured effect is shown next to the scenario.
 */
class CRDR_Insights {

	const WEEKDAYS = array( 'شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه' );

	private static function n( $v, $d = 0 ) {
		return CRDR_Jalali::number( $v, $d );
	}

	private static function pct( $x, $d = 0 ) {
		return CRDR_Jalali::number( 100 * $x, $d ) . '٪';
	}

	private static function money( $v ) {
		return CRDR_Jalali::number( round( $v ) ) . ' ' . get_woocommerce_currency_symbol();
	}

	/** Scenario block for a playbook audience. */
	private static function playbook_impact( $pb_id ) {
		$pb = CRDR_Playbooks::get( $pb_id );
		if ( ! $pb ) {
			return null;
		}
		$sum  = CRDR_Playbooks::summary( $pb );
		$take = min( $sum['n'], (int) $pb['daily_cap'] * 7 ); // what one week of the playbook can reach.
		if ( $take <= 0 ) {
			return null;
		}
		$sc = CRDR_Stats::scenario( $take, $sum['aov'], (array) $pb['scenario'] );
		$m  = null;
		$r  = CRDR_Playbooks::results( $pb_id );
		if ( $r && $r['test']['enough'] ) {
			$m = $r['test'];
		}
		return array(
			'audience' => $sum['n'],
			'reach'    => $take,
			'aov'      => $sum['aov'],
			'pts'      => (array) $pb['scenario'],
			'low'      => $sc[0], 'mid' => $sc[1], 'high' => $sc[2],
			'measured' => $m,
			'pb_id'    => $pb_id,
		);
	}

	public static function generate() {
		global $wpdb;
		$st = CRDR_Analytics::get();
		if ( empty( $st['orders_total'] ) ) {
			return array();
		}
		$c      = CRDR_DB::customers();
		$cards  = array();
		$ncust  = (int) $st['customers_total'];
		$small  = $ncust < 100 ? 'تعداد مشتریان فروشگاه هنوز کم است و عددها فقط یک نشانه‌اند، نه قطعیت.' : '';

		/* 1. one-time buyers -> second purchase */
		$one    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE orders_count = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sec    = $st['repeat']['second_median_days'];
		$impact = self::playbook_impact( 'second_purchase' );
		if ( $one > 0 && $impact ) {
			$cards[] = array(
				'id' => 'second', 'tone' => 'opportunity', 'impact' => $impact, 'note' => $small,
				'title' => 'بیشتر مشتریانتان فقط یک‌بار خرید کرده‌اند',
				'body'  => self::pct( $one / max( 1, $ncust ) ) . ' مشتریان (' . self::n( $one ) . ' نفر) فقط یک سفارش دارند.' .
					( null !== $sec ? ' نیمی از مشتریانی که برگشته‌اند، خرید دومشان را ظرف ' . self::n( round( $sec ) ) . ' روز انجام داده‌اند.' : '' ),
				'steps' => array(
					'«خرید دوم» را فعال کنید تا چند هفته بعد از اولین خرید یک پیشنهاد کوچک و کوتاه‌مدت برای مشتری برود.',
					'زمان پیام را نزدیک بازه‌ی ' . ( null !== $sec ? self::n( max( 7, round( $sec * 0.7 ) ) ) . ' تا ' . self::n( round( $sec * 1.5 ) ) . ' روز' : 'دو تا شش هفته' ) . ' بعد از خرید نگه دارید.',
				),
				'cta' => array( 'label' => 'مشاهده کمپین «خرید دوم»', 'playbook' => 'second_purchase' ),
			);
		}

		/* 2. customers who are drifting away */
		$risk = self::playbook_impact( 'winback_risk' );
		$vipr = self::playbook_impact( 'save_vip' );
		if ( $risk && $risk['audience'] > 0 ) {
			$cards[] = array(
				'id' => 'risk', 'tone' => 'warning', 'impact' => $risk, 'note' => $small,
				'title' => 'مشتریانی که دیرتر از معمول نخریده‌اند',
				'body'  => self::n( $risk['audience'] ) . ' مشتری تکراری حالا بیش از ۱٫۶ برابرِ فاصله‌ی معمولِ خودشان است که نخریده‌اند. میانگین سبدشان ' . self::money( $risk['aov'] ) . ' است.' .
					( $vipr && $vipr['audience'] > 0 ? ' از این میان ' . self::n( $vipr['audience'] ) . ' نفر پرخرج‌اند (گروه «ارزشمند در حال ریزش»).' : '' ),
				'steps' => array(
					'«بازگشت مشتری در خطر» را با پیشنهاد ۱۰٪ و مهلت کوتاه اجرا کنید.',
					'برای گروه «ارزشمند در حال ریزش» علاوه بر پیامک، از «فهرست تماس» تماس شخصی بگیرید.',
				),
				'cta' => array( 'label' => 'مشاهده کمپین «بازگشت مشتری در خطر»', 'playbook' => 'winback_risk' ),
			);
		}

		/* 3. concentration of revenue */
		if ( ! empty( $st['pareto'] ) && $st['pareto']['top10'] > 0.3 ) {
			$vip = self::playbook_impact( 'vip_thanks' );
			$cards[] = array(
				'id' => 'pareto', 'tone' => 'info', 'impact' => $vip, 'note' => $small,
				'title' => 'کمتر از ۱۰٪ مشتریان، بخش بزرگی از فروش را می‌سازند',
				'body'  => 'بهترین ' . self::n( $st['pareto']['top10_count'] ) . ' مشتری شما ' . self::pct( $st['pareto']['top10'] ) . ' فروش و بهترین ۲۰٪ ' . self::pct( $st['pareto']['top20'] ) . ' فروش را ساخته‌اند.',
				'steps' => array(
					'این گروه را با تخفیف عادت ندهید؛ تشکر، دسترسی زودتر به محصول جدید و پشتیبانی سریع‌تر کافی است («تشکر از قهرمان‌ها»).',
					'اگر یکی از آن‌ها چند ماه نخرد، فوراً در «فهرست تماس» دیده می‌شود.',
				),
				'cta' => array( 'label' => 'مشاهده کمپین «تشکر از قهرمان‌ها»', 'playbook' => 'vip_thanks' ),
			);
		}

		/* 4. timing of reminders */
		if ( ! empty( $st['gap_median'] ) && $st['gap_hist']['n'] >= 30 ) {
			$med = round( $st['gap_median'] );
			$rem = self::playbook_impact( 'reminder' );
			$cards[] = array(
				'id' => 'timing', 'tone' => 'opportunity', 'impact' => $rem, 'note' => $small,
				'title' => 'بهترین زمان یادآوری خرید',
				'body'  => 'نیمی از خریدهای تکراری ظرف ' . self::n( $med ) . ' روز بعد از خرید قبلی اتفاق می‌افتد. یادآوری قبل از این زمان بی‌اثر و بعد از آن دیر است.',
				'steps' => array(
					'«یادآوری موعد خرید» برای هر مشتری، زمان را بر اساس الگوی خودش (نه یک عدد ثابت) تنظیم می‌کند.',
					'برای این کمپین تخفیف نگذارید؛ مشتری منظم خودش می‌خرد و فقط به یادآوری نیاز دارد.',
				),
				'cta' => array( 'label' => 'مشاهده کمپین «یادآوری موعد خرید»', 'playbook' => 'reminder' ),
			);
		}

		/* 5. cross-sell / bundle */
		if ( ! empty( $st['pairs'] ) ) {
			$p     = $st['pairs'][0];
			$na    = CRDR_Analytics::product_name( $p['a'] );
			$nb    = CRDR_Analytics::product_name( $p['b'] );
			$xs    = self::playbook_impact( 'cross_sell' );
			$cards[] = array(
				'id' => 'cross', 'tone' => 'opportunity', 'impact' => $xs, 'note' => $small,
				'title' => 'محصولاتی که مشتریان با هم می‌خرند',
				'body'  => 'از هر ' . self::n( 100 ) . ' نفری که «' . $na . '» می‌خرند، ' . self::n( round( 100 * $p['conf_ab'] ) ) . ' نفر «' . $nb . '» را هم در همان سفارش می‌گیرند؛ این ' . self::n( $p['lift'], 1 ) . ' برابر حالتی است که محصولات تصادفی کنار هم خریده شوند.',
				'steps' => array(
					'روی صفحه‌ی هر یک از این دو محصول، دیگری را «پیشنهاد مکمل» نشان دهید.',
					'یک «بسته‌ی ' . $na . ' + ' . $nb . '» بسازید (جزئیات سود در صفحه‌ی «محصولات و مکمل»).',
					'«پیشنهاد محصول مکمل» را فعال کنید تا خریداران اخیر پیام مناسب بگیرند.',
				),
				'cta' => array( 'label' => 'مشاهده محصولات و بسته‌ها', 'page' => 'customer-radar-products' ),
			);
		}

		/* 6. basket threshold */
		if ( ! empty( $st['threshold'] ) ) {
			$t       = $st['threshold'];
			$gain    = 0.125 * $t['threshold'];
			$per100  = 100 * $t['nudge_share'];
			$cards[] = array(
				'id' => 'threshold', 'tone' => 'opportunity', 'note' => $small,
				'impact' => array(
					'label' => 'به‌ازای هر ۱۰۰ سفارش',
					'low' => $per100 * 0.10 * $gain, 'mid' => $per100 * 0.18 * $gain, 'high' => $per100 * 0.25 * $gain,
					'pts' => array( 0.10, 0.18, 0.25 ), 'custom' => 'نسبت سفارش‌هایی که با تشویق به آستانه می‌رسند',
				),
				'title' => 'آستانه‌ی ارسال رایگان یا هدیه',
				'body'  => 'میانه‌ی سفارش‌های شما ' . self::money( $t['median'] ) . ' است. اگر آستانه‌ی ارسال رایگان (یا هدیه) را روی ' . self::money( $t['threshold'] ) . ' بگذارید، ' . self::pct( $t['nudge_share'] ) . ' سفارش‌ها دقیقاً ۲۵٪ پایین‌تر از آن هستند؛ یعنی با کمی تشویق می‌توانند سبدشان را بزرگ‌تر کنند.',
				'steps' => array(
					'در سبد خرید پیام «فقط X تومان تا ارسال رایگان» نشان دهید.',
					'محصولات ارزان و مکمل را کنار سبد پیشنهاد کنید تا رسیدن به آستانه آسان شود.',
					'قبل از اعلام آستانه، مطمئن شوید هزینه‌ی ارسال رایگان از حاشیه‌ی سود آن سفارش کمتر است.',
				),
			);
		}

		/* 7. when to send */
		$wd = $st['weekday'];
		$hr = $st['hour'];
		if ( array_sum( $wd ) >= 50 ) {
			$wmax = array_search( max( $wd ), $wd, true );
			$hmax = array_search( max( $hr ), $hr, true );
			$cards[] = array(
				'id' => 'sendtime', 'tone' => 'info', 'note' => $small,
				'title' => 'بهترین زمان برای ارسال پیام',
				'body'  => 'بیشترین سفارش‌ها روزهای «' . self::WEEKDAYS[ $wmax ] . '» (' . self::pct( $wd[ $wmax ] / max( 1, array_sum( $wd ) ) ) . ' سفارش‌ها) و حدود ساعت ' . self::n( $hmax ) . ' ثبت می‌شود.',
				'steps' => array(
					'پیامک کمپین‌ها را حدود یک تا دو ساعت قبل از اوج بفرستید، نه همزمان با آن.',
					'ساعت مجاز ارسال را در «تنظیمات» طوری بگذارید که پیام نیمه‌شب نرود.',
				),
			);
		}

		/* 8. seasonality (needs a long enough history) */
		if ( count( $st['monthly'] ) >= 10 ) {
			$best = $st['monthly'];
			usort( $best, function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			} );
			$avg = array_sum( array_column( $st['monthly'], 'revenue' ) ) / count( $st['monthly'] );
			if ( $avg > 0 && $best[0]['revenue'] > 1.3 * $avg ) {
				$cards[] = array(
					'id' => 'season', 'tone' => 'info', 'note' => 'با کمتر از دو سال داده، نمی‌شود فصل‌بودن را قطعی دانست.',
					'title' => 'ماه‌های پرفروش',
					'body'  => 'پرفروش‌ترین ماه شما «' . $best[0]['label'] . '» بوده است (' . self::n( round( 100 * $best[0]['revenue'] / $avg ) ) . '٪ میانگین ماهانه).',
					'steps' => array( 'اگر این الگو هر سال تکرار می‌شود، کمپین‌ها و موجودی را حدود دو هفته قبل از این ماه آماده کنید.' ),
				);
			}
		}

		/* 9. reachable customers */
		$nophone = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE phone = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $nophone > 0 && $nophone / max( 1, $ncust ) > 0.05 ) {
			$cards[] = array(
				'id' => 'phones', 'tone' => 'warning', 'note' => '',
				'title' => 'بخشی از مشتریان را نمی‌شود پیامک زد',
				'body'  => self::n( $nophone ) . ' مشتری (' . self::pct( $nophone / max( 1, $ncust ) ) . ') شماره‌ی موبایل معتبر ندارند.',
				'steps' => array( 'در فرم تسویه‌حساب، شماره‌ی موبایل را اجباری کنید تا همه‌ی مشتریان آینده قابل پیامک باشند.' ),
			);
		}

		// Biggest estimated opportunity first; informational cards last.
		usort( $cards, function ( $a, $b ) {
			$va = isset( $a['impact']['mid'] ) ? $a['impact']['mid'] : -1;
			$vb = isset( $b['impact']['mid'] ) ? $b['impact']['mid'] : -1;
			return $vb <=> $va;
		} );
		return $cards;
	}
}
