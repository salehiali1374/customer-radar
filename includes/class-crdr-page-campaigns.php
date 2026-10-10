<?php
defined( 'ABSPATH' ) || exit;

class CRDR_Page_Campaigns {

	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['edit'] ) ) {
			return self::edit( sanitize_key( $_GET['edit'] ) );
		}
		if ( ! empty( $_GET['results'] ) ) {
			return self::results( sanitize_key( $_GET['results'] ) );
		}
		$focus = isset( $_GET['focus'] ) ? sanitize_key( $_GET['focus'] ) : '';
		// phpcs:enable
		CRDR_UI::open( 'کمپین‌ها', 'customer-radar-campaigns', 'فرآیندهای آماده‌ی بازاریابی؛ هر کدام را دستی یا خودکار اجرا کنید و اثر واقعی‌اش را اندازه بگیرید.' );
		if ( ! empty( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			CRDR_UI::notice( 'success', 'کمپین ذخیره شد.' );
		}
		if ( ! empty( $_GET['retried'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			CRDR_UI::notice( 'success', 'شماره‌های ردشده‌ی این کمپین در اجرای بعدی دوباره امتحان می‌شوند.' );
		}
		if ( ! empty( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			CRDR_UI::notice( 'success', 'کمپین حذف شد.' );
		}

		echo '<div class="crdr-card crdr-learn"><p><strong>چطور کار می‌کند؟</strong> هر کمپین یک «چه کسی، چه پیامی، چه پیشنهادی، چند نفر در روز» است که از قبل برایتان تنظیم شده. سه حالت دارد: <strong>غیرفعال</strong>، <strong>دستی</strong> (خودتان دکمه‌ی اجرا را می‌زنید) و <strong>خودکار</strong> (هر ساعت و فقط در ساعت‌های مجاز، دسته‌های کوچک ارسال می‌شود). همیشه بخشی از مخاطبان عمداً پیام نمی‌گیرند (گروه شاهد) تا معلوم شود پیام واقعاً فروش را بالا برده یا نه، یا مشتری خودش برمی‌گشت.</p>';
		echo '<p class="crdr-muted">سرویس پیامک: <strong>' . esc_html( CRDR_SMS::provider_label() ) . '</strong> · ساعت مجاز ارسال (خودکار و دستی): ' . esc_html( CRDR_UI::num( CRDR_Settings::get( 'send_from' ) ) ) . ' تا ' . esc_html( CRDR_UI::num( CRDR_Settings::get( 'send_to' ) ) ) . ' · ' . ( CRDR_Playbooks::in_send_window() ? 'الان در بازه‌ی مجاز است' : 'الان خارج از بازه‌ی مجاز است' ) . '</p></div>';

		echo '<p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="crdr-inline"><input type="hidden" name="action" value="crdr_pb_create">';
		wp_nonce_field( CRDR_Actions::NONCE );
		echo '<button class="button button-primary">ساخت کمپین دلخواه</button></form></p>';

		$modes = array( 'off' => 'غیرفعال', 'manual' => 'دستی', 'auto' => 'خودکار' );
		echo '<div class="crdr-cards">';
		foreach ( CRDR_Playbooks::all() as $id => $pb ) {
			$sum = CRDR_Playbooks::summary( $pb );
			$res = CRDR_Playbooks::results( $id );
			echo '<article class="crdr-card crdr-pb' . ( $id === $focus ? ' is-focus' : '' ) . '" id="pb-' . esc_attr( $id ) . '" data-id="' . esc_attr( $id ) . '">';
			echo '<div class="crdr-insight-head"><h3>' . esc_html( $pb['name'] ) . '</h3>' . ( $pb['builtin'] ? '' : '<span class="crdr-tag">سفارشی</span>' ) . '</div>';
			echo '<p class="crdr-body"><strong>هدف:</strong> ' . esc_html( $pb['goal'] ?: '—' ) . '</p>';
			if ( $pb['why'] ) {
				echo '<details class="crdr-why"><summary>چرا این کمپین؟</summary><p>' . esc_html( $pb['why'] ) . '</p></details>';
			}
			echo '<div class="crdr-seg" role="group" aria-label="حالت اجرا">';
			foreach ( $modes as $m => $label ) {
				echo '<button type="button" class="crdr-mode' . ( $pb['mode'] === $m ? ' is-on' : '' ) . '" data-mode="' . esc_attr( $m ) . '">' . esc_html( $label ) . '</button>';
			}
			echo '</div>';
			echo '<ul class="crdr-facts">';
			echo '<li>مخاطب فعلی: <strong>' . esc_html( CRDR_UI::num( $sum['n'] ) ) . ' نفر</strong>' . ( $sum['n'] ? ' (میانگین سبد ' . esc_html( CRDR_UI::money( $sum['aov'] ) ) . ')' : '' ) . '</li>';
			echo '<li>پیشنهاد: ' . ( 'percent' === $pb['offer']['type'] ? esc_html( CRDR_UI::num( $pb['offer']['percent'] ) ) . '٪ تخفیف، ' . esc_html( CRDR_UI::num( $pb['offer']['days'] ) ) . ' روزه' : 'بدون تخفیف' ) . '</li>';
			echo '<li>امروز در سقف حساب شده (پذیرفته‌شده توسط سرویس پیامک، در حال ارسال یا با نتیجه‌ی نامشخص): ' . esc_html( CRDR_UI::num( CRDR_Playbooks::sent_today( $id ) ) ) . ' از سقف ' . esc_html( CRDR_UI::num( $pb['daily_cap'] ) ) . ' · گروه شاهد: ' . esc_html( CRDR_UI::num( $pb['holdout_pct'] ) ) . '٪</li>';
			if ( $res && ( $res['treated']['n'] + $res['holdout']['n'] ) > 0 ) {
				$tr = $res['treated']['n'] ? $res['treated']['conv'] / $res['treated']['n'] : 0;
				$ho = $res['holdout']['n'] ? $res['holdout']['conv'] / $res['holdout']['n'] : 0;
				echo '<li>نتیجه تا امروز: کسانی که پیامکشان پذیرفته شد ' . esc_html( CRDR_UI::pct( $tr, 1 ) ) . ' خرید مجدد، گروه شاهد ' . esc_html( CRDR_UI::pct( $ho, 1 ) ) . '</li>';
			}
			echo CRDR_Health::card_item( $id, $pb ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
			$aside = CRDR_Playbooks::set_aside( $id );
			if ( $aside['count'] ) {
				echo '<li class="crdr-bad">' . esc_html( CRDR_UI::num( $aside['count'] ) ) . ' مشتری فعلاً کنار گذاشته شده‌اند، چون پیامشان ساخته نمی‌شود یا سرویس پیامک آن را نپذیرفته: ' . esc_html( $aside['reason'] ) . ' پس از اصلاح، با ذخیره‌ی کمپین یا تنظیمات (یا حداکثر ' . esc_html( CRDR_UI::num( (int) ( CRDR_Send_Rules::BUILD_BACKOFF / HOUR_IN_SECONDS ) ) ) . ' ساعت بعد) دوباره امتحان می‌شوند.</li>';
			}
			$rej = CRDR_Playbooks::set_aside( $id, 'rejected' );
			if ( $rej['count'] ) {
				echo '<li class="crdr-bad">' . esc_html( CRDR_UI::num( $rej['count'] ) ) . ' شماره از سوی سرویس پیامک رد شده و کنار گذاشته شده است (پیامی برایشان نرفته و «تماس‌گرفته‌شده» حساب نمی‌شوند): ' . esc_html( $rej['reason'] )
					. ' اگر شماره‌ی مشتری در سفارش اصلاح شود، مشتری جدیدی با همان شماره‌ی درست دیده می‌شود؛ در غیر این صورت ' . esc_html( CRDR_UI::num( (int) ( CRDR_Send_Rules::REJECT_BACKOFF / DAY_IN_SECONDS ) ) ) . ' روز بعد دوباره امتحان می‌شوند.'
					. ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="crdr-inline"><input type="hidden" name="action" value="crdr_retry_rejected"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
				wp_nonce_field( CRDR_Actions::NONCE );
				echo '<button class="button button-small">همین حالا دوباره امتحان شوند</button></form></li>';
			}
			echo '</ul>';
			echo '<p class="crdr-actions-row"><button type="button" class="button button-primary crdr-pb-open">پیش‌نمایش و اجرا</button> ';
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=customer-radar-campaigns&edit=' . $id ) ) . '">ویرایش</a> ';
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=customer-radar-campaigns&results=' . $id ) ) . '">نتایج</a></p>';
			echo '</article>';
		}
		echo '</div>';

		echo '<div id="crdr-modal" class="crdr-modal" hidden><div class="crdr-modal-box" role="dialog" aria-modal="true"><button type="button" class="crdr-modal-x" aria-label="بستن">×</button><div id="crdr-modal-body"></div></div></div>';
		CRDR_UI::close();
	}

	/* ------------------------------------------------------------------ edit */

	private static function edit( $id ) {
		$pb    = CRDR_Playbooks::get( $id );
		$draft = ( $pb && ! empty( $_GET['invalid'] ) ) ? CRDR_Playbooks::take_draft( $id ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $draft ) {
			$pb = array_replace( $pb, $draft['cfg'] ); // show what was typed, so nothing has to be retyped.
		}
		CRDR_UI::open( 'ویرایش کمپین', 'customer-radar-campaigns' );
		if ( ! $pb ) {
			CRDR_UI::empty_state( 'کمپین پیدا نشد.', 'بازگشت', admin_url( 'admin.php?page=customer-radar-campaigns' ) );
			CRDR_UI::close();
			return;
		}
		if ( $draft ) {
			CRDR_UI::notice( 'error', 'کمپین ذخیره نشد، چون متن پیام اشکال دارد. اشکال را اصلاح و دوباره ذخیره کنید:' );
			foreach ( $draft['errors'] as $e ) {
				CRDR_UI::notice( 'error', $e );
			}
		}
		$a = (array) $pb['audience'];
		$v = function ( $k ) use ( $a ) {
			return isset( $a[ $k ] ) ? esc_attr( $a[ $k ] ) : '';
		};
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="crdr-card crdr-form"><input type="hidden" name="action" value="crdr_pb_save"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( CRDR_Actions::NONCE );
		echo '<h2>' . esc_html( $pb['name'] ) . '</h2><table class="form-table"><tbody>';
		echo '<tr><th>نام کمپین</th><td><input type="text" class="regular-text" name="name" maxlength="60" value="' . esc_attr( $pb['name'] ) . '"></td></tr>';
		if ( ! $pb['builtin'] ) {
			echo '<tr><th>هدف (یک خط)</th><td><input type="text" class="large-text" name="goal" maxlength="160" value="' . esc_attr( $pb['goal'] ) . '"></td></tr>';
			echo '<tr><th>پیشنهاد محصول مکمل</th><td><label><input type="checkbox" name="cross_sell" value="1"' . checked( ! empty( $pb['cross_sell'] ), true, false ) . '> متن می‌تواند از {product} و {bought} استفاده کند (مشتریان بدون محصول مکمل مناسب رد می‌شوند)</label></td></tr>';
		}
		echo '<tr><th>حالت اجرا</th><td><select name="mode">';
		foreach ( array( 'off' => 'غیرفعال', 'manual' => 'دستی', 'auto' => 'خودکار (هر ساعت، در ساعت‌های مجاز)' ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $pb['mode'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr></tbody></table>';

		echo '<h3>مخاطب: چه کسانی پیام بگیرند؟</h3><div class="crdr-chips">';
		$sel = isset( $a['segments'] ) ? (array) $a['segments'] : array();
		foreach ( CRDR_RFM::segments() as $key => $label ) {
			echo '<label class="crdr-chip" style="--c:' . esc_attr( CRDR_UI::SEG_COLORS[ $key ] ) . '"><input type="checkbox" name="segments[]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $sel, true ), true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</div><p class="description">اگر هیچ بخشی انتخاب نشود، همه‌ی بخش‌ها (با سایر شرط‌ها) در نظر گرفته می‌شوند.</p><div class="crdr-grid-f">';
		$fields = array(
			array( 'min_orders', 'حداقل سفارش' ), array( 'max_orders', 'حداکثر سفارش' ),
			array( 'min_days', 'حداقل روز از آخرین خرید' ), array( 'max_days', 'حداکثر روز از آخرین خرید' ),
			array( 'min_ratio', 'حداقل «تأخیر نسبی» (روز از آخرین خرید ÷ فاصله‌ی معمول خود مشتری)' ), array( 'max_ratio', 'حداکثر تأخیر نسبی' ),
			array( 'min_spent', 'حداقل جمع خرید' ), array( 'max_spent', 'حداکثر جمع خرید' ),
		);
		foreach ( $fields as $f ) {
			echo '<label>' . esc_html( $f[1] ) . '<input type="number" min="0" step="' . ( false !== strpos( $f[0], 'ratio' ) ? '0.1' : '1' ) . '" name="' . esc_attr( $f[0] ) . '" value="' . $v( $f[0] ) . '"></label>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div><p class="description">تأخیر نسبی ۱ یعنی مشتری دقیقاً به موعد معمول خرید خودش رسیده؛ ۱٫۶ یعنی ۶۰٪ دیرتر از معمول.</p>';

		echo '<h3>پیشنهاد</h3><table class="form-table"><tbody><tr><th>نوع</th><td><select name="offer_type"><option value="none"' . selected( $pb['offer']['type'], 'none', false ) . '>بدون تخفیف</option><option value="percent"' . selected( $pb['offer']['type'], 'percent', false ) . '>کد تخفیف درصدی اختصاصی (یک‌بارمصرف)</option></select></td></tr>';
		echo '<tr><th>درصد تخفیف</th><td><input type="number" min="1" max="90" name="offer_percent" value="' . esc_attr( $pb['offer']['percent'] ?: 10 ) . '"></td></tr>';
		echo '<tr><th>اعتبار کد (روز)</th><td><input type="number" min="1" max="90" name="offer_days" value="' . esc_attr( $pb['offer']['days'] ?: 7 ) . '"></td></tr></tbody></table>';

		echo '<h3>متن پیامک</h3><textarea name="template" rows="4" class="large-text">' . esc_textarea( $pb['template'] ) . '</textarea>';
		echo '<p class="description">متغیرها: <code>{name}</code> <code>{store}</code> <code>{coupon}</code> <code>{discount}</code> <code>{expire}</code> <code>{product}</code> <code>{bought}</code> <code>{url}</code> <code>{optout}</code>. متن «لغو۱۱» را برای رعایت قوانین پیامک تبلیغاتی نگه دارید.</p>';

		echo '<h3>محدودیت‌ها و اندازه‌گیری</h3><table class="form-table"><tbody>';
		echo '<tr><th>سقف ارسال در روز</th><td><input type="number" min="1" max="500" name="daily_cap" value="' . esc_attr( $pb['daily_cap'] ) . '"></td></tr>';
		echo '<tr><th>گروه شاهد (٪ مخاطبان که عمداً پیام نمی‌گیرند)</th><td><input type="number" min="0" max="50" name="holdout_pct" value="' . esc_attr( $pb['holdout_pct'] ) . '"><p class="description">برای اندازه‌گیری اثر واقعی لازم است. مقدار ۱۰ تا ۲۰ پیشنهاد می‌شود.</p></td></tr>';
		echo '<tr><th>بازه‌ی سنجش خرید (روز)</th><td><input type="number" min="3" max="90" name="window_days" value="' . esc_attr( $pb['window_days'] ) . '"></td></tr>';
		echo '<tr><th>فاصله‌ی حداقلی تا پیام بعدی به همان مشتری (روز)</th><td><input type="number" min="0" max="365" name="cooldown" value="' . esc_attr( isset( $pb['cooldown'] ) ? $pb['cooldown'] : '' ) . '" placeholder="پیش‌فرض تنظیمات"></td></tr>';
		echo '</tbody></table>';
		submit_button( 'ذخیره کمپین' );
		echo '</form>';

		if ( ! $pb['builtin'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'این کمپین حذف شود؟\')"><input type="hidden" name="action" value="crdr_pb_delete"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( CRDR_Actions::NONCE );
			echo '<button class="button button-link-delete">حذف این کمپین</button></form>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=customer-radar-campaigns' ) ) . '">← بازگشت به کمپین‌ها</a></p>';
		CRDR_UI::close();
	}

	/* --------------------------------------------------------------- results */

	private static function results( $id ) {
		global $wpdb;
		$pb = CRDR_Playbooks::get( $id );
		CRDR_UI::open( 'نتایج کمپین', 'customer-radar-campaigns' );
		if ( ! $pb ) {
			CRDR_UI::empty_state( 'کمپین پیدا نشد.', 'بازگشت', admin_url( 'admin.php?page=customer-radar-campaigns' ) );
			CRDR_UI::close();
			return;
		}
		$r = CRDR_Playbooks::results( $id );
		echo '<h2>' . esc_html( $pb['name'] ) . '</h2>';
		echo '<p class="crdr-muted">«خرید مجدد» یعنی مشتری ظرف ' . esc_html( CRDR_UI::num( $r['window'] ) ) . ' روز بعد از پیام (یا بعد از زمان انتخابِ او برای گروه شاهد) سفارش ثبت کرده است. فقط پیام‌هایی شمرده می‌شوند که این بازه‌شان تمام شده؛ داده‌ها با هر «تحلیل سفارش‌ها» به‌روز می‌شود.</p>';

		$t = $r['treated'];
		$h = $r['holdout'];
		if ( $t['n'] + $h['n'] === 0 ) {
			CRDR_UI::empty_state( $r['sent_total'] > 0
				? 'پیامک‌های پذیرفته‌شده هنوز بازه‌ی سنجش را کامل نکرده‌اند (' . CRDR_UI::num( $r['immature'] ) . ' مورد در حال سنجش). چند روز بعد دوباره سر بزنید.'
				: 'هنوز پیامکی از این کمپین ارسال نشده است.', 'بازگشت به کمپین‌ها', admin_url( 'admin.php?page=customer-radar-campaigns' ) );
			CRDR_UI::close();
			return;
		}
		$rate = function ( $g ) {
			return $g['n'] ? $g['conv'] / $g['n'] : 0;
		};
		$rpc = function ( $g ) {
			return $g['n'] ? $g['rev'] / $g['n'] : 0;
		};
		echo '<div class="crdr-card"><div class="crdr-scroll"><table class="widefat striped crdr-table"><thead><tr><th>گروه</th><th>تعداد</th><th>خرید مجدد</th><th>نرخ</th><th>فروش بعد از پیام</th><th>فروش به‌ازای هر نفر</th></tr></thead><tbody>';
		foreach ( array( 'treated' => array( 'پیامکشان پذیرفته شد', $t ), 'holdout' => array( 'گروه شاهد (پیامی برایشان نرفت)', $h ) ) as $row ) {
			echo '<tr><td>' . esc_html( $row[0] ) . '</td><td>' . esc_html( CRDR_UI::num( $row[1]['n'] ) ) . '</td><td>' . esc_html( CRDR_UI::num( $row[1]['conv'] ) ) . '</td><td><strong>' . esc_html( CRDR_UI::pct( $rate( $row[1] ), 1 ) ) . '</strong></td><td>' . esc_html( CRDR_UI::money( $row[1]['rev'] ) ) . '</td><td>' . esc_html( CRDR_UI::money( $rpc( $row[1] ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		$x = $r['test'];
		echo '<h3>اثر واقعی پیام</h3>';
		if ( ! $x['enough'] ) {
			echo '<p>نمونه هنوز کافی نیست؛ برای قضاوت دست‌کم ۳۰ نفر در هر گروه لازم است. تا آن زمان عددها فقط یک نشانه‌اند.</p>';
		} else {
			$diff = CRDR_UI::pct( $x['diff'], 1 );
			echo '<p>تفاوت نرخ خرید مجدد: <strong>' . esc_html( $diff ) . '</strong> نقطه‌ی درصد (بازه‌ی اطمینان ۹۵٪: ' . esc_html( CRDR_UI::pct( $x['ci_low'], 1 ) . ' تا ' . CRDR_UI::pct( $x['ci_high'], 1 ) ) . ').</p>';
			if ( $x['p'] < 0.05 && $x['diff'] > 0 ) {
				echo '<p class="crdr-good">نتیجه: اثر مثبت و از نظر آماری معنادار است؛ پیام واقعاً خرید را بالا برده.</p>';
				$extra = ( $rpc( $t ) - $rpc( $h ) ) * $t['n'];
				echo '<p>فروش اضافه‌ی تقریبی نسبت به حالت بدون پیام: <strong>' . esc_html( CRDR_UI::money( $extra ) ) . '</strong> (' . esc_html( CRDR_UI::num( $r['sent_total'] ) ) . ' پیامک پذیرفته‌شده توسط سرویس پیامک در کل).</p>';
			} elseif ( $x['p'] < 0.05 && $x['diff'] < 0 ) {
				echo '<p class="crdr-bad">نتیجه: گروهی که پیامکشان پذیرفته شد کمتر از گروه شاهد خرید کرده است. متن یا زمان‌بندی را بازبینی کنید.</p>';
			} else {
				echo '<p>نتیجه: تفاوت معناداری دیده نشد؛ ممکن است اثر پیام کوچک باشد یا مشتریان خودشان برمی‌گشتند. قبل از افزایش بودجه، متن یا پیشنهاد را عوض و دوباره آزمایش کنید.</p>';
			}
		}
		if ( $r['codes'] > 0 ) {
			echo '<p>کدهای تخفیف: ' . esc_html( CRDR_UI::num( $r['codes'] ) ) . ' کد ساخته شد، ' . esc_html( CRDR_UI::num( $r['redeemed'] ) ) . ' کد استفاده شد (' . esc_html( CRDR_UI::pct( $r['redeemed'] / max( 1, $r['codes'] ), 1 ) ) . ').</p>';
		}
		if ( $r['unknown'] > 0 ) {
			echo '<p class="crdr-muted">' . esc_html( CRDR_UI::num( $r['unknown'] ) ) . ' پیامک «نتیجه‌ی نامشخص» دارد (ممکن است رفته باشد و ممکن است نرفته باشد)؛ برای اینکه نتیجه را خراب نکند، در این مقایسه نیامده است.</p>';
		}
		if ( $r['immature'] > 0 ) {
			echo '<p class="crdr-muted">' . esc_html( CRDR_UI::num( $r['immature'] ) ) . ' پیام اخیر هنوز در بازه‌ی سنجش‌اند و در این عددها نیامده‌اند.</p>';
		}
		echo '</div>';

		$k    = CRDR_DB::contacts();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.*, cu.name FROM {$k} c LEFT JOIN " . CRDR_DB::customers() . " cu ON cu.ckey = c.ckey WHERE c.playbook = %s AND c.kind = 'sms' ORDER BY c.id DESC LIMIT 20", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $rows ) {
			echo '<div class="crdr-card"><h3>آخرین ارسال‌ها</h3><div class="crdr-scroll"><table class="widefat striped crdr-table"><thead><tr><th>زمان</th><th>مشتری</th><th>گروه</th><th>وضعیت</th><th>پیام</th></tr></thead><tbody>';
			foreach ( $rows as $c ) {
				echo '<tr><td>' . esc_html( CRDR_Jalali::date( $c['created_ts'] ) . ' ' . CRDR_Jalali::digits( wp_date( 'H:i', $c['created_ts'] ) ) ) . '</td><td>' . esc_html( $c['name'] ?: '—' ) . '</td><td>' . ( 'holdout' === $c['grp'] ? 'شاهد' : 'پیام' ) . '</td><td>' . '<span title="' . esc_attr( CRDR_Send_Rules::outcome( $c['status'] )['help'] ) . '">' . esc_html( CRDR_Send_Rules::outcome( $c['status'] )['label'] ) . '</span>' . ( $c['error'] ? ' — ' . esc_html( $c['error'] ) : '' ) . '</td><td class="crdr-muted">' . esc_html( $c['message'] ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo '<p class="crdr-muted">«پذیرفته‌شده توسط سرویس پیامک» یعنی سرویس پیامک پیامک را پذیرفت و در صف ارسال گذاشت؛ این افزونه رسید تحویل ندارد، پس رسیدن آن به گوشی مشتری تأیید نشده است. «نتیجه نامشخص» یعنی پاسخ سرویس پیامک نرسید و ممکن است پیامک رفته باشد؛ دوباره فرستاده نمی‌شود. «ارسال نشد»، «شماره رد شد» و «پیام ساخته یا پذیرفته نشد» یعنی پیامکی نرفته است.</p></div>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=customer-radar-campaigns' ) ) . '">← بازگشت به کمپین‌ها</a></p>';
		CRDR_UI::close();
	}
}
