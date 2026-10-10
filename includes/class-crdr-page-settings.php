<?php
defined( 'ABSPATH' ) || exit;

class CRDR_Page_Settings {

	public static function render() {
		$s     = CRDR_Settings::all();
		$draft = ! empty( $_GET['invalid'] ) ? CRDR_Settings::take_draft() : null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $draft ) {
			$s = array_replace( $s, $draft['values'] ); // show what was typed, so nothing has to be retyped.
		}
		CRDR_UI::open( 'تنظیمات', 'customer-radar-settings' );
		if ( ! empty( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			CRDR_UI::notice( 'success', 'تنظیمات ذخیره شد. برای اعمال روی داده‌ها، یک‌بار «تحلیل سفارش‌ها» را از داشبورد بزنید.' );
		}
		if ( $draft ) {
			CRDR_UI::notice( 'error', 'تنظیمات ذخیره نشد، چون بعضی مقدارها اشکال دارند. اشکال را اصلاح و دوباره ذخیره کنید:' );
			foreach ( $draft['errors'] as $e ) {
				CRDR_UI::notice( 'error', $e );
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="crdr-card crdr-form">';
		echo '<input type="hidden" name="action" value="crdr_save_settings">';
		wp_nonce_field( CRDR_Actions::NONCE );

		echo '<h2>سفارش‌های مورد تحلیل</h2><p>';
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug = substr( $key, 3 );
			echo '<label class="crdr-check"><input type="checkbox" name="statuses[]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $s['statuses'], true ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
		}
		echo '</p>';

		echo '<h2>آستانه‌ها</h2><table class="form-table"><tbody>';
		self::num( 'new_days', 'مشتری «جدید» تا چند روز پس از اولین خرید', $s['new_days'] );
		self::num( 'lost_days', 'تک‌خرید بیش از چند روز قدیمی، «ازدست‌رفته» حساب شود', $s['lost_days'] );
		self::num( 'min_ratio', 'وارد فهرست تماس شود وقتی «روز از آخرین خرید ÷ فاصله‌ی معمول» به این عدد رسید', $s['min_ratio'], '0.1' );
		self::num( 'default_gap', 'فاصله‌ی پیش‌فرض بین دو خرید (روز) اگر فروشگاه هنوز مشتری تکراری ندارد', $s['default_gap'] );
		self::num( 'cooldown_days', 'پس از هر پیام یا تماس، تا چند روز دوباره سراغ همان مشتری نروید', $s['cooldown_days'] );
		echo '</tbody></table>';

		echo '<h2>پیامک</h2><table class="form-table"><tbody>';
		echo '<tr><th>سرویس ارسال</th><td><select name="sms_provider">';
		foreach ( array( 'auto' => 'خودکار (افزونه پیامک ووکامرس، وگرنه کاوه‌نگار)', 'pwsms' => 'افزونه پیامک ووکامرس', 'kavenegar' => 'کاوه‌نگار (مستقیم)', 'none' => 'غیرفعال (فقط فهرست و اکسل)' ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['sms_provider'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">در حال حاضر استفاده می‌شود: <strong>' . esc_html( CRDR_SMS::provider_label() ) . '</strong></p></td></tr>';
		echo '<tr><th>کلید API کاوه‌نگار</th><td><input type="text" class="regular-text crdr-ltr" name="kavenegar_key" value="' . esc_attr( $s['kavenegar_key'] ) . '" autocomplete="off"></td></tr>';
		echo '<tr><th>شماره‌ی خط کاوه‌نگار</th><td><input type="text" class="regular-text crdr-ltr" name="kavenegar_from" value="' . esc_attr( $s['kavenegar_from'] ) . '"><p class="description"><strong>حتماً پر کنید:</strong> شماره‌ی خط اختصاصی خودتان در پنل کاوه‌نگار. اگر خالی بماند، کاوه‌نگار از خط عمومی استفاده می‌کند و ممکن است پیامک «فیلتر» شود و نرسد.</p></td></tr>';
		echo '<tr><th>متن پیامک ساده (فهرست تماس)</th><td><textarea name="sms_template" rows="3" class="large-text">' . esc_textarea( $s['sms_template'] ) . '</textarea><p class="description">متغیرها: {name} {store} {optout}</p></td></tr>';
		echo '<tr><th>متن پیامک با کد تخفیف (فهرست تماس)</th><td><textarea name="sms_template_coupon" rows="3" class="large-text">' . esc_textarea( $s['sms_template_coupon'] ) . '</textarea><p class="description">متغیرها: {name} {store} {coupon} {discount} {expire} {optout}</p></td></tr>';
		echo '<tr><th>عبارت لغو عضویت</th><td><input type="text" class="regular-text" name="optout_text" maxlength="30" value="' . esc_attr( $s['optout_text'] ) . '"><p class="description">در پایان پیامک‌های تبلیغاتی کمپین‌ها می‌آید. قوانین اپراتورها را با پنل پیامکی خودتان بررسی کنید؛ بیشتر پنل‌ها «لغو۱۱» را می‌خواهند.</p></td></tr>';
		self::num( 'send_from', 'ارسال پیامک‌های تبلیغاتی (کمپین خودکار، کمپین دستی و پیامک تکی فهرست تماس) فقط از ساعت (۰ تا ۲۳)', $s['send_from'] );
		self::num( 'send_to', 'تا ساعت (۱ تا ۲۴)', $s['send_to'] );
		echo '</tbody></table>';

		echo '<h2>کد تخفیف در فهرست تماس</h2><table class="form-table"><tbody>';
		self::num( 'coupon_percent', 'درصد تخفیف', $s['coupon_percent'] );
		self::num( 'coupon_days', 'اعتبار کد (روز)', $s['coupon_days'] );
		self::num( 'coupon_min', 'حداقل مبلغ سفارش (۰ = بدون حداقل)', $s['coupon_min'] );
		echo '</tbody></table>';

		echo '<h2>اقتصاد بسته‌ها</h2><table class="form-table"><tbody>';
		self::num( 'margin_pct', 'حاشیه‌ی سود فرضی محصولات (٪)', $s['margin_pct'] );
		self::num( 'bundle_discount', 'تخفیف پیشنهادی بسته (٪)', $s['bundle_discount'] );
		echo '</tbody></table>';

		submit_button( 'ذخیره تنظیمات' );
		echo '</form>';
		CRDR_UI::close();
	}

	private static function num( $name, $label, $value, $step = '1' ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="number" step="' . esc_attr( $step ) . '" class="small-text crdr-ltr" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></td></tr>';
	}
}
