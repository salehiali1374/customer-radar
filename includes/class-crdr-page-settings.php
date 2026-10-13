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

		echo '<h2>کدام سفارش‌ها شمرده شوند؟</h2>';
		echo '<p class="description">' . esc_html( 'فقط سفارش‌هایی که وضعیتشان اینجا تیک خورده‌اند در تحلیل مشتریان و فروش می‌آیند. معمولاً «تکمیل‌شده» و «در حال انجام» درست است (پیش‌فرض). وضعیت‌هایی مثل «لغوشده»، «ناموفق» و «پیش‌نویس سفارش» فروش واقعی نیستند؛ تیکشان را نزنید. مبلغ مرجوعی از فروش کم می‌شود. بعد از تغییر، یک‌بار «تحلیل سفارش‌ها» را در داشبورد بزنید.' ) . '</p><p>';
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug = substr( $key, 3 );
			echo '<label class="crdr-check"><input type="checkbox" name="statuses[]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $s['statuses'], true ), true, false ) . '> ' . esc_html( CRDR_UI::status_label( $slug, $label ) ) . '</label> ';
		}
		echo '</p>';

		echo '<h2>آستانه‌ها (چه کسی «جدید»، «ازدست‌رفته» یا «موعد تماس» است)</h2><table class="form-table"><tbody>';
		self::num( 'new_days', 'مشتری «جدید» تا چند روز پس از اولین خرید', $s['new_days'], '1', 'مثال: با ۳۰، کسی که ۱۰ روز پیش اولین خریدش را کرده «جدید» است و برایش پیام تبلیغاتی نمی‌فرستید.' );
		self::num( 'lost_days', 'تک‌خرید بیش از چند روز قدیمی، «ازدست‌رفته» حساب شود', $s['lost_days'], '1', 'فقط برای کسانی که یک بار خریده‌اند. مثال: با ۳۶۵، تک‌خریدِ یک‌سال‌پیش در گروه «ازدست‌رفته» می‌رود.' );
		self::num( 'min_ratio', 'وارد فهرست تماس شود وقتی «روز از آخرین خرید ÷ فاصله‌ی معمول» به این عدد رسید', $s['min_ratio'], '0.1', 'مثال: مشتری‌ای که معمولاً هر ۶۰ روز می‌خرد، با ۰٫۹ از روز ۵۴ در فهرست تماس می‌آید. عدد کوچک‌تر یعنی زودتر، عدد بزرگ‌تر یعنی دیرتر.' );
		self::num( 'default_gap', 'فاصله‌ی پیش‌فرض بین دو خرید (روز) اگر فروشگاه هنوز مشتری تکراری ندارد', $s['default_gap'], '1', 'فقط وقتی به کار می‌آید که از روی سفارش‌ها نشود فاصله‌ی معمول را حساب کرد.' );
		self::num( 'cooldown_days', 'پس از هر پیام یا تماس، تا چند روز دوباره سراغ همان مشتری نروید', $s['cooldown_days'], '1', 'برای جلوگیری از پیام مکرر. تا پایان این مدت مشتری در فهرست تماس و کمپین‌ها نمی‌آید (هر کمپین می‌تواند مدت خودش را داشته باشد).' );
		echo '</tbody></table>';

		echo '<h2>پیامک</h2><table class="form-table"><tbody>';
		echo '<tr><th>سرویس ارسال</th><td><select name="sms_provider">';
		foreach ( array( 'auto' => 'خودکار (افزونه پیامک ووکامرس، وگرنه کاوه‌نگار)', 'pwsms' => 'افزونه پیامک ووکامرس', 'kavenegar' => 'کاوه‌نگار (مستقیم)', 'none' => 'غیرفعال (فقط فهرست و اکسل)' ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['sms_provider'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">در حال حاضر استفاده می‌شود: <strong>' . esc_html( CRDR_SMS::provider_label() ) . '</strong></p><p class="description">' . esc_html( 'اگر با افزونه‌ی «پیامک ووکامرس» می‌فرستید، آن افزونه شماره و متن هر پیامک را در بایگانی خودش نگه می‌دارد؛ رادار مشتری مدت نگهداری یا پاک‌کردن آن را کنترل نمی‌کند.' ) . '</p></td></tr>';
		echo '<tr><th>کلید API کاوه‌نگار</th><td><input type="text" class="regular-text crdr-ltr" name="kavenegar_key" value="' . esc_attr( $s['kavenegar_key'] ) . '" autocomplete="off"><p class="description">' . esc_html( 'فقط برای اتصال مستقیم به کاوه‌نگار. کلید را از پنل کاوه‌نگار بردارید و به کسی ندهید؛ هر کس آن را داشته باشد می‌تواند با اعتبار شما پیامک بفرستد.' ) . '</p></td></tr>';
		echo '<tr><th>شماره‌ی خط کاوه‌نگار</th><td><input type="text" class="regular-text crdr-ltr" name="kavenegar_from" value="' . esc_attr( $s['kavenegar_from'] ) . '"><p class="description"><strong>حتماً پر کنید:</strong> شماره‌ی خط اختصاصی خودتان در پنل کاوه‌نگار. اگر خالی بماند، کاوه‌نگار از خط عمومی استفاده می‌کند و ممکن است پیامک «فیلتر» شود و نرسد.</p></td></tr>';
		echo '<tr><th>متن پیامک ساده (فهرست تماس)</th><td><textarea name="sms_template" rows="3" class="large-text">' . esc_textarea( $s['sms_template'] ) . '</textarea>';
		CRDR_UI::variable_help( array( 'name', 'store', 'optout' ), 'مثال: «سلام {name} عزیز، مدتی است از شما در {store} خبری نداریم. {optout}»' );
		echo '</td></tr>';
		echo '<tr><th>متن پیامک با کد تخفیف (فهرست تماس)</th><td><textarea name="sms_template_coupon" rows="3" class="large-text">' . esc_textarea( $s['sms_template_coupon'] ) . '</textarea>';
		CRDR_UI::variable_help( array( 'name', 'store', 'coupon', 'discount', 'expire', 'optout' ), 'این متن فقط با دکمه‌ی «پیامک + کد تخفیف» در فهرست تماس به کار می‌رود.' );
		echo '</td></tr>';
		echo '<tr><th>عبارت لغو عضویت</th><td><input type="text" class="regular-text" name="optout_text" maxlength="30" value="' . esc_attr( $s['optout_text'] ) . '"><p class="description">در پایان پیامک‌های تبلیغاتی کمپین‌ها می‌آید. قوانین اپراتورها را با پنل پیامکی خودتان بررسی کنید؛ بیشتر پنل‌ها «لغو۱۱» را می‌خواهند.</p></td></tr>';
		self::num( 'send_from', 'ارسال پیامک‌های تبلیغاتی (کمپین خودکار، کمپین دستی و پیامک تکی فهرست تماس) فقط از ساعت (۰ تا ۲۳)', $s['send_from'], '1', 'خارج از این ساعت‌ها هیچ پیامک تبلیغاتی ارسال نمی‌شود (فقط مدیر سایت می‌تواند آگاهانه از این محدودیت عبور کند و نامش ثبت می‌شود). ساعت شروع باید کمتر از ساعت پایان باشد؛ بازه‌ی شبانه مثل ۲۲ تا ۶ پذیرفته نمی‌شود. مثال: ۹ تا ۲۱.' );
		self::num( 'send_to', 'تا ساعت (۱ تا ۲۴)', $s['send_to'] );
		echo '</tbody></table>';

		echo '<h2>کد تخفیف در فهرست تماس</h2><p class="description">' . esc_html( 'برای دکمه‌ی «پیامک + کد تخفیف» در فهرست تماس. هر بار که آن را بزنید یک کد واقعی در ووکامرس ساخته می‌شود: یک‌بارمصرف، مخصوص همان مشتری (با ایمیل او، اگر داشته باشد). کمپین‌ها پیشنهاد خودشان را در صفحه‌ی ویرایش کمپین دارند.' ) . '</p><table class="form-table"><tbody>';
		self::num( 'coupon_percent', 'درصد تخفیف', $s['coupon_percent'] );
		self::num( 'coupon_days', 'اعتبار کد (روز)', $s['coupon_days'] );
		self::num( 'coupon_min', 'حداقل مبلغ سفارش (۰ = بدون حداقل)', $s['coupon_min'] );
		echo '</tbody></table>';

		echo '<h2>بسته‌های محصول (صفحه‌ی «محصولات و مکمل»)</h2><p class="description">' . esc_html( 'فقط برای محاسبه‌ی «آیا ساختن بسته به صرفه است؟» در آن صفحه. روی ارسال پیامک و تحلیل مشتریان اثری ندارد.' ) . '</p><table class="form-table"><tbody>';
		self::num( 'margin_pct', 'حاشیه‌ی سود فرضی محصولات (٪)', $s['margin_pct'], '1', 'از هر ۱۰۰ تومان فروش چند تومان سود می‌کنید؟ اگر نمی‌دانید، یک عدد تقریبی بگذارید؛ نتیجه فقط به همین عدد وابسته است.' );
		self::num( 'bundle_discount', 'تخفیف پیشنهادی بسته (٪)', $s['bundle_discount'], '1', 'درصدی که روی کد تخفیف بسته گذاشته می‌شود. اگر از حاشیه‌ی سود بیشتر باشد بسته زیان‌ده است.' );
		echo '</tbody></table>';

		echo '<h2>حذف افزونه و داده‌ها</h2><table class="form-table"><tbody><tr><th>هنگام حذف افزونه</th><td>';
		echo '<p class="description" style="max-width:60em">' . esc_html( 'حذف افزونه از صفحه‌ی «افزونه‌ها» به‌طور پیش‌فرض همه‌ی داده‌ها را پاک می‌کند، به‌جز «فهرست پیام نده» (هر مشتری که پیام نخواسته؛ فقط شناسه‌اش و زمان ثبت، بدون متن و بدون نام): این فهرست می‌ماند تا اگر افزونه را دوباره نصب کردید (مثلاً برای به‌روزرسانی با بارگذاری فایل تازه) کسی که پیام نخواسته دوباره پیامک نگیرد. کدهای تخفیف ساخته‌شده در ووکامرس دست‌نخورده می‌مانند.' ) . '</p>';
		echo '<label class="crdr-check"><input type="checkbox" name="erase_on_uninstall" value="1"' . checked( ! empty( $s['erase_on_uninstall'] ), true, false ) . '> <strong>' . esc_html( 'با حذف افزونه، همه‌چیز از جمله «فهرست پیام نده» برای همیشه پاک شود' ) . '</strong></label>';
		echo '<p class="description" style="max-width:60em">' . esc_html( 'فقط اگر می‌خواهید هیچ اثری از افزونه نماند این را تیک بزنید و ذخیره کنید، بعد افزونه را حذف کنید. با این کار نمی‌توان فهرست «پیام نده» را بازگرداند و اگر بعداً افزونه را دوباره نصب کنید، کسانی که پیام نخواسته بودند دوباره پیامک می‌گیرند مگر اینکه خودتان دوباره ثبتشان کنید.' ) . '</p>';
		echo '</td></tr></tbody></table>';

		submit_button( 'ذخیره تنظیمات' );
		echo '</form>';
		CRDR_UI::close();
	}

	private static function num( $name, $label, $value, $step = '1', $help = '' ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="number" step="' . esc_attr( $step ) . '" class="small-text crdr-ltr" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">' . ( '' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>';
	}
}
