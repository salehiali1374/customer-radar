<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shared presentation helpers: page frame with tabs, cards, KPI tiles, chart containers, filters.
 */
class CRDR_UI {

	const SEG_COLORS = array(
		'champion'       => '#16a34a',
		'loyal'          => '#2563eb',
		'new'            => '#0ea5e9',
		'potential'      => '#7c3aed',
		'need_attention' => '#d97706',
		'at_risk'        => '#ea580c',
		'cant_lose'      => '#dc2626',
		'hibernating'    => '#6b7280',
		'lost'           => '#374151',
	);

	public static function tabs() {
		return array(
			'customer-radar'            => 'داشبورد',
			'customer-radar-growth'     => 'رشد و پیشنهادها',
			'customer-radar-campaigns'  => 'کمپین‌ها',
			'customer-radar-calls'      => 'فهرست تماس',
			'customer-radar-customers'  => 'مشتریان',
			'customer-radar-products'   => 'محصولات و مکمل',
			'customer-radar-settings'   => 'تنظیمات',
			'customer-radar-guide'      => 'آموزش',
		);
	}

	/**
	 * Persian name of a WooCommerce order status, for display only (the stored value, the slug, never changes). WooCommerce shows English
	 * names unless its own translation is installed; a status this plugin does not know (a custom one) keeps the name WooCommerce gives it.
	 */
	public static function status_label( $slug, $fallback = '' ) {
		$map = array(
			'pending'        => 'در انتظار پرداخت',
			'processing'     => 'در حال انجام',
			'on-hold'        => 'در انتظار بررسی',
			'completed'      => 'تکمیل‌شده',
			'cancelled'      => 'لغوشده',
			'refunded'       => 'مسترد‌شده',
			'failed'         => 'ناموفق',
			'checkout-draft' => 'پیش‌نویس سفارش',
		);
		return isset( $map[ $slug ] ) ? $map[ $slug ] : ( '' !== (string) $fallback ? $fallback : (string) $slug );
	}

	/**
	 * What each SMS variable means, as the code fills it (CRDR_Actions::render_checked, CRDR_Playbooks::compose, CRDR_Recommend).
	 * name => array( meaning, example )
	 */
	public static function variable_catalog() {
		return array(
			'name'     => array( 'نام مشتری. اگر نامی ثبت نشده باشد، «مشتری» می‌آید.', 'علی' ),
			'store'    => array( 'نام فروشگاه (عنوان سایت در تنظیمات وردپرس).', 'فروشگاه نمونه' ),
			'optout'   => array( 'عبارت لغو عضویت که در «تنظیمات» نوشته‌اید. در هر پیامک تبلیغاتی الزامی است.', 'لغو۱۱' ),
			'coupon'   => array( 'کد تخفیف یک‌بارمصرفِ همان مشتری؛ هنگام ارسال ساخته می‌شود (در پیش‌نمایش «XXXXXXXX» می‌بینید).', 'RD7K2M9Q' ),
			'discount' => array( 'درصد تخفیف، فقط عدد (علامت ٪ را خودتان بعدش بنویسید).', '۱۰' ),
			'expire'   => array( 'تاریخ پایان اعتبار کد، شمسی.', '۱۴۰۵/۰۸/۰۳' ),
			'product'  => array( 'نام محصول مکملِ پیشنهادی: محصولی که خریداران محصولات او معمولاً می‌خرند و خودش هنوز نخریده است.', 'ضدآفتاب SPF50' ),
			'bought'   => array( 'نام محصولی که مشتری قبلاً خریده و پیشنهاد بر پایه‌ی آن است.', 'سرم ویتامین سی' ),
			'url'      => array( 'نشانی صفحه‌ی محصول پیشنهادی. بعضی سرویس‌های پیامک لینک را برای حساب‌ها محدود می‌کنند (کاوه‌نگار کد ۴۲۰)؛ در آن صورت لینک را بردارید.', 'https://…' ),
		);
	}

	/**
	 * A compact "which variables can I write here" box under an SMS text field.
	 *
	 * @param string[] $names variable names (without braces) to show; the rest are not available in that text
	 * @param string   $note  an extra sentence ('' = none)
	 */
	public static function variable_help( array $names, $note = '' ) {
		$cat = self::variable_catalog();
		echo '<details class="crdr-vars" open><summary>متغیرها: چیزهایی که می‌توانید در متن بنویسید</summary><dl>';
		foreach ( $names as $n ) {
			if ( ! isset( $cat[ $n ] ) ) {
				continue;
			}
			echo '<dt><code>{' . esc_html( $n ) . '}</code></dt><dd>' . esc_html( $cat[ $n ][0] ) . ' <span class="crdr-muted">مثال: ' . esc_html( $cat[ $n ][1] ) . '</span></dd>';
		}
		echo '</dl><p class="crdr-muted">' . esc_html( 'هر متغیر را دقیقاً با آکولاد و به همین شکل بنویسید. اگر برای مشتری‌ای پر نشود، پیامی برایش ارسال نمی‌شود (به‌جای فرستادن متن ناقص). هر ۷۰ نویسه یک بخش پیامک حساب می‌شود و بیش از آن، هر ۶۷ نویسه یک بخش. ' . $note ) . '</p></details>';
	}

	/** Open the page frame. Pair with close(). */
	public static function open( $title, $active, $subtitle = '' ) {
		echo '<div class="wrap crdr-wrap"><div class="crdr-top"><h1>' . esc_html( $title ) . '</h1>';
		if ( $subtitle ) {
			echo '<p class="crdr-sub">' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div><nav class="crdr-tabs" aria-label="رادار مشتری">';
		foreach ( self::tabs() as $slug => $label ) {
			$cls = $slug === $active ? ' class="is-active"' : '';
			echo '<a' . $cls . ' href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</nav>';
	}

	public static function close() {
		echo '</div>';
	}

	/* -------------------------------------------------------------- numbers */

	public static function num( $n, $d = 0 ) {
		return CRDR_Jalali::number( $n, $d );
	}

	public static function money( $n ) {
		return CRDR_Jalali::number( round( (float) $n ) ) . ' ' . get_woocommerce_currency_symbol();
	}

	public static function pct( $x, $d = 0 ) {
		return CRDR_Jalali::number( 100 * (float) $x, $d ) . '٪';
	}

	public static function seg_label( $key ) {
		$s = CRDR_RFM::segments();
		return isset( $s[ $key ] ) ? $s[ $key ] : $key;
	}

	public static function badge( $key ) {
		return '<span class="crdr-badge" style="--c:' . esc_attr( isset( self::SEG_COLORS[ $key ] ) ? self::SEG_COLORS[ $key ] : '#6b7280' ) . '">' . esc_html( self::seg_label( $key ) ) . '</span>';
	}

	/* --------------------------------------------------------------- blocks */

	public static function kpi( $label, $value, $hint = '' ) {
		echo '<div class="crdr-kpi"><div class="crdr-kpi-v">' . $value . '</div><div class="crdr-kpi-l">' . esc_html( $label ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $hint ) {
			echo '<div class="crdr-kpi-h">' . esc_html( $hint ) . '</div>';
		}
		echo '</div>';
	}

	/** A card holding one chart. $cfg is the chart definition (see assets/charts.js). */
	public static function chart_card( $title, $caption, array $cfg, $wide = false ) {
		$cfg['title'] = $title;
		echo '<section class="crdr-card' . ( $wide ? ' crdr-wide' : '' ) . '"><h3>' . esc_html( $title ) . '</h3>';
		echo '<div class="crdr-chart" data-chart="' . esc_attr( wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE ) ) . '"></div>';
		if ( $caption ) {
			echo '<p class="crdr-caption">' . esc_html( $caption ) . '</p>';
		}
		echo '</section>';
	}

	public static function empty_state( $message, $cta_label = '', $cta_url = '' ) {
		echo '<div class="crdr-card crdr-empty"><p>' . esc_html( $message ) . '</p>';
		if ( $cta_label ) {
			echo '<p><a class="button button-primary" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_label ) . '</a></p>';
		}
		echo '</div>';
	}

	/** A warning on pages that show numbers taken from the customer table, while that table is being rebuilt. */
	public static function sync_banner() {
		$sync = CRDR_Sync::guard();
		if ( $sync['blocked'] ) {
			self::notice( 'warning', $sync['message'] . ' عددهای این صفحه تا پایان به‌روزرسانی کامل نیستند.' );
		}
	}

	public static function notice( $type, $message ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/* -------------------------------------------------------- advanced filter */

	/**
	 * Advanced filter bar. Posts via GET to the same page; can also start a campaign from the filter.
	 *
	 * @param string $slug     page slug
	 * @param array  $f        normalised filter (CRDR_Audience::normalize)
	 * @param array  $hidden   extra hidden inputs (e.g. view=calls)
	 */
	public static function filters( $slug, array $f, array $hidden = array() ) {
		$products = array();
		$st       = CRDR_Analytics::get();
		foreach ( isset( $st['products'] ) ? $st['products'] : array() as $p ) {
			$products[ $p['id'] ] = CRDR_Analytics::product_name( $p['id'] );
		}
		$v = function ( $k ) use ( $f ) {
			return isset( $f[ $k ] ) ? esc_attr( $f[ $k ] ) : '';
		};
		$open = count( array_diff_key( $f, array_flip( array( 'q', 'segments', 'only_callable', 'need_phone', 'exclude_contacted' ) ) ) ) > 0 ? ' open' : '';
		echo '<form method="get" class="crdr-card crdr-filters"><input type="hidden" name="page" value="' . esc_attr( $slug ) . '">';
		foreach ( $hidden as $k => $val ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $val ) . '">';
		}
		echo '<div class="crdr-filter-row"><input type="search" name="q" placeholder="جستجوی نام، موبایل یا ایمیل" value="' . $v( 'q' ) . '"> ';
		echo '<button class="button button-primary">اعمال فیلتر</button> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . $slug . ( $hidden ? '&' . http_build_query( $hidden ) : '' ) ) ) . '">پاک کردن</a></div>';
		echo '<div class="crdr-chips" role="group" aria-label="بخش‌ها">';
		$sel = isset( $f['segments'] ) ? $f['segments'] : array();
		foreach ( CRDR_RFM::segments() as $key => $label ) {
			echo '<label class="crdr-chip" style="--c:' . esc_attr( self::SEG_COLORS[ $key ] ) . '"><input type="checkbox" name="segments[]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $sel, true ), true, false ) . '><span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</div>';
		echo '<details class="crdr-adv"' . $open . '><summary>فیلترهای پیشرفته</summary><div class="crdr-grid-f">';
		$pairs = array(
			array( 'min_orders', 'حداقل سفارش' ), array( 'max_orders', 'حداکثر سفارش' ),
			array( 'min_days', 'حداقل روز از آخرین خرید' ), array( 'max_days', 'حداکثر روز از آخرین خرید' ),
			array( 'min_spent', 'حداقل جمع خرید' ), array( 'max_spent', 'حداکثر جمع خرید' ),
		);
		foreach ( $pairs as $p ) {
			echo '<label>' . esc_html( $p[1] ) . '<input type="number" min="0" name="' . esc_attr( $p[0] ) . '" value="' . $v( $p[0] ) . '"></label>';
		}
		echo '<label>خریدار محصول<select name="product"><option value="">همه</option>';
		foreach ( $products as $id => $name ) {
			echo '<option value="' . (int) $id . '"' . selected( isset( $f['product'] ) ? (int) $f['product'] : 0, (int) $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></label>';
		echo '</div></details></form>';
	}

	/** Buttons that act on the current filter: export and "start a campaign from this filter". */
	public static function filter_actions( array $f, array $extra = array() ) {
		$args = array_merge( CRDR_Audience::to_args( $f ), $extra, array( 'action' => 'crdr_export' ) );
		echo '<div class="crdr-actions-bar">';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), CRDR_Actions::NONCE ) ) . '" title="' . esc_attr( 'ستون «' . CRDR_Actions::EXPORT_OPTOUT_HEADER . '» مشتریانی را که پیام نخواسته‌اند نشان می‌دهد؛ پیش از استفاده در پنل پیامک آن‌ها را حذف کنید.' ) . '">دریافت اکسل (CSV)</a> <span class="crdr-muted">' . esc_html( 'حداکثر ۵۰٬۰۰۰ مشتری در فایل می‌آید. مشتریانی که پیام نخواسته‌اند هم در فایل می‌آیند (فیلتری برای حذفشان نیست) و ستون آخر آن‌ها را مشخص می‌کند؛ پیش از استفاده در پنل پیامک حذفشان کنید.' ) . '</span> ';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="crdr-inline"><input type="hidden" name="action" value="crdr_pb_create">';
		wp_nonce_field( CRDR_Actions::NONCE );
		foreach ( CRDR_Audience::to_args( $f ) as $k => $val ) {
			if ( is_array( $val ) ) {
				foreach ( $val as $x ) {
					echo '<input type="hidden" name="' . esc_attr( $k ) . '[]" value="' . esc_attr( $x ) . '">';
				}
			} else {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $val ) . '">';
			}
		}
		echo '<button class="button">ساخت کمپین از این فیلتر</button></form></div>';
	}

	public static function pager( $total, $per, $paged, $slug, array $args ) {
		$pages = (int) ceil( $total / $per );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="crdr-pager"><span>' . esc_html( self::num( $total ) ) . ' مورد</span>';
		$base = add_query_arg( array_merge( $args, array( 'page' => $slug ) ), admin_url( 'admin.php' ) );
		for ( $i = max( 1, $paged - 3 ); $i <= min( $pages, $paged + 3 ); $i++ ) {
			echo '<a class="button' . ( $i === $paged ? ' button-primary' : '' ) . '" href="' . esc_url( add_query_arg( 'paged', $i, $base ) ) . '">' . esc_html( self::num( $i ) ) . '</a>';
		}
		echo '</div>';
	}
}
