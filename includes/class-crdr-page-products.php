<?php
defined( 'ABSPATH' ) || exit;

/**
 * Best sellers, products that sell together, and the economics of a bundle.
 */
class CRDR_Page_Products {

	public static function render() {
		$st = CRDR_Analytics::get();
		CRDR_UI::open( 'محصولات و مکمل', 'customer-radar-products', 'کدام محصولات را مشتریان با هم می‌خرند و آیا ساختن بسته به صرفه است؟' );

		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['coupon'] ) ) {
			CRDR_UI::notice( 'success', 'کد تخفیف بسته ساخته شد: ' . sanitize_text_field( wp_unslash( $_GET['coupon'] ) ) . ' (۳۰ روز اعتبار، فقط روی این دو محصول). آن را در صفحه‌ی محصول یا بنر فروشگاه معرفی کنید.' );
		}
		if ( ! empty( $_GET['error'] ) ) {
			CRDR_UI::notice( 'error', 'یکی از محصولات پیدا نشد.' );
		}
		// phpcs:enable

		if ( empty( $st['orders_total'] ) ) {
			CRDR_UI::empty_state( 'اول از داشبورد «تحلیل سفارش‌ها» را بزنید.', 'رفتن به داشبورد', admin_url( 'admin.php?page=customer-radar' ) );
			CRDR_UI::close();
			return;
		}
		if ( empty( $st['products'] ) ) {
			CRDR_UI::empty_state( 'در سفارش‌های تحلیل‌شده اقلام محصول ثبت نشده است؛ تحلیل محصولات و مکمل به اقلام سفارش نیاز دارد.' );
			CRDR_UI::close();
			return;
		}

		/* top products */
		$total = array_sum( array_column( $st['products'], 'revenue' ) ) ?: 1;
		$labels = array(); $vals = array();
		foreach ( array_slice( $st['products'], 0, 10 ) as $p ) {
			$labels[] = mb_substr( CRDR_Analytics::product_name( $p['id'] ), 0, 28 );
			$vals[]   = round( 100 * $p['revenue'] / $total, 1 );
		}
		echo '<div class="crdr-grid">';
		CRDR_UI::chart_card( 'پرفروش‌ترین محصولات (سهم از فروش)', 'درصد سهم هر محصول از فروش ۴۰ محصول برتر.', array( 'type' => 'hbars', 'labels' => $labels, 'values' => $vals, 'unit' => 'percent', 'colors' => array_fill( 0, count( $vals ), '#2563eb' ) ) );
		echo '<section class="crdr-card"><h3>چند نکته برای فروش بیشتر</h3><ul class="crdr-steps">';
		if ( ! empty( $st['multi_item_pct'] ) || isset( $st['multi_item_pct'] ) ) {
			echo '<li>' . esc_html( CRDR_UI::pct( $st['multi_item_pct'] ) ) . ' سفارش‌ها بیش از یک محصول دارند. هر چه این عدد بالاتر برود، میانگین سبد بیشتر می‌شود.</li>';
		}
		echo '<li>برای هر محصول پرفروش، یک محصول مکمل نزدیکش نشان دهید («پیشنهاد مکمل» در همین صفحه).</li><li>قبل از تخفیف روی بسته، سود هر بسته را با حاشیه‌ی سود خودتان بسنجید؛ در جدول زیر محاسبه شده است.</li></ul></section>';
		echo '</div>';

		/* pairs */
		$pairs = isset( $st['pairs'] ) ? $st['pairs'] : array();
		$m     = (int) CRDR_Settings::get( 'margin_pct' );
		$d     = (int) CRDR_Settings::get( 'bundle_discount' );
		echo '<div class="crdr-card"><h2>محصولاتی که با هم خریده می‌شوند</h2>';
		echo '<p class="crdr-muted">حاشیه‌ی سود فرضی: ' . esc_html( CRDR_UI::num( $m ) ) . '٪ · تخفیف پیشنهادی بسته: ' . esc_html( CRDR_UI::num( $d ) ) . '٪ (از «تنظیمات» قابل تغییر). «لیفت» یعنی این دو محصول چند برابرِ حالت تصادفی با هم خریده شده‌اند.</p>';
		if ( ! $pairs ) {
			echo '<p>هنوز جفت محصول معناداری پیدا نشد (دست‌کم چند سفارش مشترک و لیفت بالاتر از ۱٫۲ لازم است).</p></div>';
			CRDR_UI::close();
			return;
		}
		echo '<div class="crdr-scroll"><table class="widefat striped crdr-table"><thead><tr><th>بسته</th><th>سفارش مشترک</th><th>احتمال خرید دومی</th><th>لیفت</th><th>سود بسته</th><th>شرط صرفه</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $pairs, 0, 15 ) as $p ) {
			$pa = wc_get_product( $p['a'] );
			$pb = wc_get_product( $p['b'] );
			if ( ! $pa || ! $pb ) {
				continue;
			}
			$sum    = (float) $pa->get_price() + (float) $pb->get_price();
			$profit0 = $sum * $m / 100;
			$profit1 = $sum * ( $m - $d ) / 100;
			if ( $d >= $m ) {
				$verdict = 'تخفیف از حاشیه‌ی سود بیشتر است؛ بسته زیان‌ده می‌شود.';
			} else {
				$verdict = 'فقط اگر فروش این دو محصول دست‌کم ' . CRDR_UI::pct( $d / ( $m - $d ), 0 ) . ' بیشتر شود.';
			}
			echo '<tr><td><strong>' . esc_html( CRDR_Analytics::product_name( $p['a'] ) ) . '</strong> + <strong>' . esc_html( CRDR_Analytics::product_name( $p['b'] ) ) . '</strong><br><span class="crdr-muted">مجموع قیمت: ' . esc_html( CRDR_UI::money( $sum ) ) . '</span></td>';
			echo '<td>' . esc_html( CRDR_UI::num( $p['support'] ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::pct( $p['conf_ab'] ) ) . ' / ' . esc_html( CRDR_UI::pct( $p['conf_ba'] ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::num( $p['lift'], 1 ) ) . '×</td>';
			echo '<td>' . esc_html( CRDR_UI::money( $profit1 ) ) . '<br><span class="crdr-muted">بدون تخفیف: ' . esc_html( CRDR_UI::money( $profit0 ) ) . '</span></td>';
			echo '<td class="crdr-muted">' . esc_html( $verdict ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="crdr_bundle_coupon"><input type="hidden" name="a" value="' . (int) $p['a'] . '"><input type="hidden" name="b" value="' . (int) $p['b'] . '"><input type="hidden" name="percent" value="' . (int) $d . '">';
			wp_nonce_field( CRDR_Actions::NONCE );
			echo '<button class="button button-small">ساخت کد تخفیف بسته</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="crdr-muted">کد تخفیف بسته روی هر دو محصول اعمال می‌شود (ووکامرس شرط «هر دو در سبد» را به‌صورت پیش‌فرض اجباری نمی‌کند). برای تبلیغ، متن بنر را بر پایه‌ی خرید مشترک بنویسید.</p></div>';
		CRDR_UI::close();
	}
}
