<?php
defined( 'ABSPATH' ) || exit;

class CRDR_Page_Dashboard {

	public static function render() {
		global $wpdb;
		$t     = CRDR_DB::customers();
		$state = CRDR_Sync::state();
		$st    = CRDR_Analytics::get();
		CRDR_UI::open( 'رادار مشتری', 'customer-radar', 'ببینید چه کسانی وفادارند، چه کسانی در حال رفتن‌اند و امروز چه کاری بیشترین فروش را برمی‌گرداند.' );

		echo '<div class="crdr-card crdr-sync"><div><strong>آخرین به‌روزرسانی داده‌ها:</strong> ';
		if ( ! empty( $state['finished'] ) ) {
			echo esc_html( CRDR_Jalali::date( $state['finished'] ) . ' — ' . CRDR_Jalali::digits( wp_date( 'H:i', $state['finished'] ) ) );
		} else {
			echo 'هنوز انجام نشده';
		}
		echo '</div><div class="crdr-muted">سفارش‌ها هر شب خودکار تحلیل می‌شوند. برای دیدن نتیجه‌ی همین حالا دکمه را بزنید.</div>';
		echo '<p><button type="button" class="button button-primary" id="crdr-sync">تحلیل سفارش‌ها</button> <span id="crdr-sync-status" class="crdr-muted"></span></p>';
		echo '<div class="crdr-progress" id="crdr-progress" hidden><div></div></div></div>';

		if ( empty( $st['orders_total'] ) ) {
			CRDR_UI::empty_state( 'هنوز سفارشی تحلیل نشده است. دکمه‌ی «تحلیل سفارش‌ها» را بزنید. فقط سفارش‌های با وضعیت‌های انتخاب‌شده در تنظیمات (پیش‌فرض: تکمیل‌شده و در حال انجام) شمرده می‌شوند.' );
			CRDR_UI::close();
			return;
		}

		$total = (int) $st['customers_total'];
		$rep   = $st['repeat'];
		$call  = CRDR_Audience::summary( array( 'only_callable' => 1, 'need_phone' => 1, 'exclude_contacted' => 1 ) );

		/* ---- plain-language explanation using the store's own numbers */
		echo '<details class="crdr-card crdr-learn" open><summary>«مشتری وفادار» یعنی چه؟ و در فروشگاه شما چه وضعی دارد؟</summary>';
		echo '<p>مشتری وفادار فقط کسی نیست که زیاد خرید کرده؛ کسی است که <strong>دوباره و به‌موقع برمی‌گردد</strong>. به همین دلیل رادار مشتری برای هر نفر سه چیز را می‌سنجد: <em>چند وقت پیش خرید کرده</em>، <em>چند بار خرید کرده</em> و <em>چقدر خرج کرده</em>.</p>';
		echo '<p>در فروشگاه شما <strong>' . esc_html( CRDR_UI::pct( $rep['customers_pct'] ) ) . '</strong> مشتریان (' . esc_html( CRDR_UI::num( $rep['customers'] ) ) . ' نفر) بیش از یک‌بار خرید کرده‌اند و همین‌ها <strong>' . esc_html( CRDR_UI::pct( $rep['revenue_pct'] ) ) . '</strong> فروش را ساخته‌اند.</p>';
		echo '<ul class="crdr-steps"><li><strong>مشتری تازه:</strong> هدف خرید دوم است ← کمپین «خرید دوم».</li><li><strong>مشتری منظم:</strong> فقط یادآوری سر موعد، بدون تخفیف ← «یادآوری موعد خرید».</li><li><strong>مشتری در حال رفتن:</strong> پیشنهاد محدود یا تماس شخصی ← «بازگشت مشتری در خطر».</li></ul>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=customer-radar-growth' ) ) . '">دیدن پیشنهادهای رشد برای فروشگاه من</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=customer-radar-campaigns' ) ) . '">کمپین‌های آماده</a></p></details>';

		/* ---- KPIs */
		echo '<div class="crdr-kpis">';
		CRDR_UI::kpi( 'مشتریان', esc_html( CRDR_UI::num( $total ) ) );
		CRDR_UI::kpi( 'مشتریان تکرارشونده', esc_html( CRDR_UI::pct( $rep['customers_pct'] ) ), 'سهم از فروش: ' . CRDR_UI::pct( $rep['revenue_pct'] ) );
		CRDR_UI::kpi( 'فاصله‌ی معمول دو خرید', null !== $st['gap_median'] ? esc_html( CRDR_UI::num( round( $st['gap_median'] ) ) ) . ' روز' : '—', 'میانه‌ی خریدهای تکراری' );
		CRDR_UI::kpi( 'میانگین سفارش', esc_html( CRDR_UI::money( $st['aov']['mean'] ) ), 'میانه: ' . CRDR_UI::money( $st['aov']['p50'] ) );
		CRDR_UI::kpi( 'جمع فروش تحلیل‌شده', esc_html( CRDR_UI::money( array_sum( array_column( $st['monthly'], 'revenue' ) ) ) ), 'در ۲۴ ماه اخیر' );
		CRDR_UI::kpi( 'در فهرست تماس امروز', esc_html( CRDR_UI::num( $call['n'] ) ) . ' نفر', 'میانگین سبد: ' . CRDR_UI::money( $call['aov'] ) );
		echo '</div>';

		/* ---- charts */
		echo '<div class="crdr-grid">';

		$labels = array_column( $st['monthly'], 'label' );
		CRDR_UI::chart_card(
			'فروش ماهانه: مشتری جدید یا قدیمی؟',
			'اگر ستون «قدیمی» کوچک است، فروشگاه بیشتر به جذب مشتری تازه وابسته است و هر ماه باید دوباره هزینه‌ی تبلیغ بدهد. هدف: رشد تدریجی سهم مشتریان قدیمی.',
			array(
				'type' => 'stacked', 'labels' => $labels,
				'series' => array(
					array( 'name' => 'مشتری جدید', 'color' => '#2563eb', 'values' => array_map( 'floatval', array_column( $st['monthly'], 'new_rev' ) ) ),
					array( 'name' => 'مشتری قدیمی', 'color' => '#f59e0b', 'values' => array_map( 'floatval', array_column( $st['monthly'], 'ret_rev' ) ) ),
				),
			),
			true
		);

		$seg = $wpdb->get_results( "SELECT segment, COUNT(*) n, SUM(total_spent) rev FROM {$t} GROUP BY segment", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sl  = array(); $sn = array(); $sr = array(); $sc = array();
		foreach ( CRDR_RFM::segments() as $key => $label ) {
			if ( empty( $seg[ $key ] ) ) {
				continue;
			}
			$sl[] = $label; $sn[] = (int) $seg[ $key ]->n; $sr[] = (float) $seg[ $key ]->rev; $sc[] = CRDR_UI::SEG_COLORS[ $key ];
		}
		CRDR_UI::chart_card( 'مشتریان بر حسب بخش', 'هر بخش کار متفاوتی می‌خواهد؛ کمپین مناسب هر بخش در صفحه‌ی «کمپین‌ها» آماده است.', array( 'type' => 'donut', 'labels' => $sl, 'values' => $sn, 'colors' => $sc, 'center' => 'مشتری' ) );
		$revsum = array_sum( $sr ) ?: 1;
		CRDR_UI::chart_card( 'سهم هر بخش از فروش', 'ممکن است بخشی با تعداد کم، سهم بزرگی از فروش داشته باشد؛ مراقب آن گروه‌ها باشید.', array( 'type' => 'hbars', 'labels' => $sl, 'values' => array_map( function ( $v ) use ( $revsum ) {
			return round( 100 * $v / $revsum, 1 );
		}, $sr ), 'colors' => $sc, 'unit' => 'percent' ) );

		/* re-purchase curve */
		$sv_l = array(); $sv_v = array(); $sv_n = array();
		foreach ( $st['survival'] as $p ) {
			if ( $p['n'] < 10 ) {
				continue;
			}
			$sv_l[] = CRDR_Jalali::digits( (string) $p['d'] );
			$sv_v[] = round( 100 * $p['p'], 1 );
			$sv_n[] = $p['n'];
		}
		if ( $sv_l ) {
			CRDR_UI::chart_card(
				'اگر مشتری N روز نخرد، چقدر احتمال دارد برگردد؟',
				'محور افقی: روزهایی که از آخرین خرید گذشته. محور عمودی: درصدی که در ۳۰ روز بعد دوباره خریده‌اند (بر اساس تاریخچه‌ی خود فروشگاه). هرچه منحنی پایین‌تر برود، زمان طلایی بازگشت دارد از دست می‌رود.',
				array( 'type' => 'line', 'labels' => $sv_l, 'unit' => 'percent', 'max' => 100, 'sizes' => $sv_n, 'xlabel' => 'روز از آخرین خرید', 'series' => array( array( 'name' => 'احتمال خرید در ۳۰ روز بعد', 'color' => '#0d9488', 'values' => $sv_v ) ) )
			);
		}

		$gl = array( '≤ ۷', '۸–۱۴', '۱۵–۳۰', '۳۱–۴۵', '۴۶–۶۰', '۶۱–۹۰', '۹۱–۱۲۰', '۱۲۱–۱۸۰', '۱۸۰+' );
		CRDR_UI::chart_card( 'فاصله‌ی بین دو خرید (روز)', 'این نمودار الگوی طبیعی خرید در فروشگاه شماست. اوج آن، زمان مناسب یادآوری است. تعداد خریدهای تکراری: ' . CRDR_UI::num( $st['gap_hist']['n'] ), array( 'type' => 'bars', 'unit' => 'count', 'labels' => $gl, 'series' => array( array( 'name' => 'تعداد', 'color' => '#7c3aed', 'values' => $st['gap_hist']['counts'] ) ) ) );

		$rows = array(); $vals = array();
		for ( $r = 5; $r >= 1; $r-- ) {
			$rows[] = 'تازگی ' . CRDR_Jalali::digits( (string) $r ) . ( 5 === $r ? ' (تازه‌ترین)' : ( 1 === $r ? ' (قدیمی‌ترین)' : '' ) );
			$line   = array();
			for ( $f = 1; $f <= 5; $f++ ) {
				$line[] = (int) $st['rfm_grid'][ $r ][ $f ];
			}
			$vals[] = $line;
		}
		CRDR_UI::chart_card( 'نقشه‌ی تازگی و تعداد خرید', 'گوشه‌ی بالا-چپ (تازه و پرخرید) قهرمان‌هاست و گوشه‌ی پایین-راست (قدیمی و کم‌خرید) ازدست‌رفته‌ها. هرچه سلول تیره‌تر، مشتری بیشتر.', array( 'type' => 'heat', 'rows' => $rows, 'cols' => array( '۱ خرید', '۲ خرید', '۳ خرید', '۴–۵ خرید', '۶+ خرید' ), 'values' => $vals, 'unit' => 'count', 'labW' => 140 ) );

		/* revenue concentration */
		if ( ! empty( $st['pareto']['deciles'] ) ) {
			$dl = array(); $dv = array(); $eq = array();
			foreach ( $st['pareto']['deciles'] as $i => $v ) {
				$dl[] = CRDR_Jalali::digits( (string) ( ( $i + 1 ) * 10 ) ) . '٪';
				$dv[] = round( 100 * $v, 1 );
				$eq[] = ( $i + 1 ) * 10;
			}
			CRDR_UI::chart_card( 'تمرکز فروش روی بهترین مشتریان', 'بهترین ۱۰٪ مشتریان شما ' . CRDR_UI::pct( $st['pareto']['top10'] ) . ' فروش را ساخته‌اند. خط خاکستری حالتی است که همه‌ی مشتریان برابر خرید کنند.', array( 'type' => 'line', 'labels' => $dl, 'unit' => 'percent', 'max' => 100, 'xlabel' => 'سهم بهترین مشتریان', 'series' => array( array( 'name' => 'سهم از فروش', 'color' => '#dc2626', 'values' => $dv ), array( 'name' => 'حالت برابر', 'color' => '#9ca3af', 'values' => $eq ) ) ) );
		}

		/* cohorts */
		if ( ! empty( $st['cohorts'] ) ) {
			$cr = array(); $cv = array();
			foreach ( $st['cohorts'] as $mi => $c ) {
				if ( $c['size'] < 5 ) {
					continue;
				}
				$cr[] = CRDR_Jalali::month_label( $mi, true ) . ' (' . CRDR_Jalali::digits( (string) $c['size'] ) . ' نفر)';
				$cv[] = array_map( function ( $x ) {
					return null === $x ? null : round( $x, 1 );
				}, array_values( $c['ret'] ) );
			}
			if ( $cr ) {
				$cc = array();
				for ( $k = 0; $k <= 11; $k++ ) {
					$cc[] = 0 === $k ? 'ماه خرید' : '+' . CRDR_Jalali::digits( (string) $k );
				}
				CRDR_UI::chart_card( 'نگهداشت مشتریان هر ماه (کوهورت)', 'هر ردیف مشتریانی است که اولین خریدشان در آن ماه بوده؛ هر ستون چند درصدشان ماه‌های بعد دوباره خرید کرده‌اند. ردیف‌های روشن یعنی مشتریان آن ماه برنگشته‌اند.', array( 'type' => 'heat', 'rows' => $cr, 'cols' => $cc, 'values' => $cv, 'unit' => 'percent', 'max' => 50, 'labW' => 170, 'color' => array( 13, 148, 136 ) ), true );
			}
		}

		$wd = array_map( 'intval', $st['weekday'] );
		CRDR_UI::chart_card( 'سفارش‌ها در روزهای هفته', 'پیامک را یک یا دو ساعت قبل از روزهای پرسفارش بفرستید.', array( 'type' => 'bars', 'unit' => 'count', 'labels' => CRDR_Insights::WEEKDAYS, 'series' => array( array( 'name' => 'سفارش', 'color' => '#0ea5e9', 'values' => $wd ) ) ) );
		$hl = array();
		foreach ( range( 0, 23 ) as $h ) {
			$hl[] = CRDR_Jalali::digits( (string) $h );
		}
		CRDR_UI::chart_card( 'سفارش‌ها در ساعت‌های روز', 'ساعت‌های خلوت، فرصت مناسبی برای ارسال کمپین است، به شرط اینکه نیمه‌شب نباشد.', array( 'type' => 'bars', 'unit' => 'count', 'labels' => $hl, 'series' => array( array( 'name' => 'سفارش', 'color' => '#f59e0b', 'values' => array_map( 'intval', $st['hour'] ) ) ) ) );

		echo '</div>';

		/* ---- segment table */
		echo '<div class="crdr-card"><h2>بخش‌های مشتریان</h2><div class="crdr-scroll"><table class="widefat striped crdr-table"><thead><tr>';
		foreach ( array( 'بخش', 'توضیح', 'تعداد', 'سهم از مشتریان', 'سهم از فروش', 'میانگین سبد', 'میانگین روز از آخرین خرید' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$now  = (int) time();
		$rows = $wpdb->get_results( "SELECT segment, COUNT(*) AS n, SUM(total_spent) AS rev, SUM(orders_count) AS ord, AVG(({$now} - last_ts) / 86400) AS days FROM {$t} GROUP BY segment", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL
		$hints = CRDR_RFM::segment_hints();
		foreach ( CRDR_RFM::segments() as $key => $label ) {
			if ( empty( $rows[ $key ] ) ) {
				continue;
			}
			$r   = $rows[ $key ];
			$aov = $r->ord > 0 ? $r->rev / $r->ord : 0;
			$url = admin_url( 'admin.php?page=customer-radar-customers&segments[]=' . $key );
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . CRDR_UI::badge( $key ) . '</a></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td class="crdr-muted">' . esc_html( $hints[ $key ] ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::num( $r->n ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::pct( $r->n / $total, 1 ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::pct( $r->rev / $revsum, 1 ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::money( $aov ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::num( round( $r->days ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
		echo '<p class="crdr-muted">«اولویت» و «سناریوها» در این افزونه فرمول‌های شفاف و عددهای فرضی‌اند، نه پیش‌بینی قطعی. اثر واقعی هر کمپین را در بخش نتایج (با گروه شاهد) ببینید.</p>';
		CRDR_UI::close();
	}
}
