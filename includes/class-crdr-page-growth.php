<?php
defined( 'ABSPATH' ) || exit;

class CRDR_Page_Growth {

	public static function render() {
		CRDR_UI::open( 'رشد و پیشنهادها', 'customer-radar-growth', 'پیشنهادهایی که فقط از سفارش‌های خودِ فروشگاه شما ساخته شده‌اند، به ترتیب اهمیت.' );
		$cards = CRDR_Insights::generate();
		if ( ! $cards ) {
			CRDR_UI::empty_state( 'برای ساختن پیشنهاد، اول از داشبورد «تحلیل سفارش‌ها» را بزنید.', 'رفتن به داشبورد', admin_url( 'admin.php?page=customer-radar' ) );
			CRDR_UI::close();
			return;
		}
		echo '<div class="crdr-card crdr-learn"><p><strong>چطور این پیشنهادها را بخوانیم؟</strong> «اتفاق افتاده» یعنی از سفارش‌های واقعی شما اندازه‌گیری شده. «سناریو» یعنی اگر درصدی از مخاطبان خرید کنند چه می‌شود؛ <u>سناریو وعده نیست</u>. بعد از اجرای هر کمپین با گروه شاهد، اثر واقعی جایگزین سناریو می‌شود.</p></div>';

		echo '<div class="crdr-cards">';
		$tone_label = array( 'opportunity' => 'فرصت', 'warning' => 'هشدار', 'info' => 'اطلاعات' );
		foreach ( $cards as $c ) {
			echo '<article class="crdr-card crdr-insight crdr-tone-' . esc_attr( $c['tone'] ) . '">';
			echo '<div class="crdr-insight-head"><span class="crdr-tag">' . esc_html( $tone_label[ $c['tone'] ] ) . '</span><h3>' . esc_html( $c['title'] ) . '</h3></div>';
			echo '<p class="crdr-body">' . esc_html( $c['body'] ) . '</p>';
			if ( ! empty( $c['steps'] ) ) {
				echo '<ol class="crdr-steps">';
				foreach ( $c['steps'] as $s ) {
					echo '<li>' . esc_html( $s ) . '</li>';
				}
				echo '</ol>';
			}
			if ( ! empty( $c['impact'] ) && isset( $c['impact']['mid'] ) ) {
				self::impact( $c['impact'] );
			}
			if ( ! empty( $c['note'] ) ) {
				echo '<p class="crdr-muted">' . esc_html( $c['note'] ) . '</p>';
			}
			if ( ! empty( $c['cta'] ) ) {
				$url = ! empty( $c['cta']['playbook'] )
					? admin_url( 'admin.php?page=customer-radar-campaigns&focus=' . $c['cta']['playbook'] )
					: admin_url( 'admin.php?page=' . $c['cta']['page'] );
				echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html( $c['cta']['label'] ) . '</a></p>';
			}
			echo '</article>';
		}
		echo '</div>';
		CRDR_UI::close();
	}

	private static function impact( array $i ) {
		echo '<div class="crdr-impact"><div class="crdr-impact-t">سناریو (نه پیش‌بینی)</div>';
		if ( isset( $i['audience'] ) ) {
			echo '<div class="crdr-muted">مخاطب فعلی: ' . esc_html( CRDR_UI::num( $i['audience'] ) ) . ' نفر، میانگین سبد ' . esc_html( CRDR_UI::money( $i['aov'] ) ) . '؛ ظرفیت یک هفته‌ی کمپین: ' . esc_html( CRDR_UI::num( $i['reach'] ) ) . ' نفر.</div>';
		} elseif ( isset( $i['label'] ) ) {
			echo '<div class="crdr-muted">' . esc_html( $i['label'] ) . '؛ فرض: ' . esc_html( isset( $i['custom'] ) ? $i['custom'] : '' ) . '</div>';
		}
		echo '<div class="crdr-impact-row">';
		$names = array( 'low' => 'محتاطانه', 'mid' => 'میانی', 'high' => 'خوش‌بینانه' );
		foreach ( array( 'low', 'mid', 'high' ) as $k => $key ) {
			echo '<div><span class="crdr-impact-v">' . esc_html( CRDR_UI::money( $i[ $key ] ) ) . '</span><span class="crdr-muted">' . esc_html( $names[ $key ] ) . ' (اگر ' . esc_html( CRDR_UI::pct( $i['pts'][ $k ], 0 ) ) . ' بخرند)</span></div>';
		}
		echo '</div>';
		if ( ! empty( $i['measured'] ) ) {
			$m = $i['measured'];
			echo '<div class="crdr-measured">اثر اندازه‌گیری‌شده‌ی همین کمپین: ' . esc_html( CRDR_UI::pct( $m['diff'], 1 ) ) . ' نقطه (بازه‌ی ۹۵٪: ' . esc_html( CRDR_UI::pct( $m['ci_low'], 1 ) . ' تا ' . CRDR_UI::pct( $m['ci_high'], 1 ) ) . ')</div>';
		}
		echo '</div>';
	}
}
