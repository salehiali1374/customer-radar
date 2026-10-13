<?php
defined( 'ABSPATH' ) || exit;

/**
 * Call list, customer list (both with the shared filter) and the single-customer page.
 */
class CRDR_Page_Customers {

	/* -------------------------------------------------------------- call list */

	/**
	 * While the customer data is being rebuilt (or a rebuild stopped half way) the lists are incomplete and their buttons
	 * carry row ids that the rebuild hands out again: show why instead of the list.
	 *
	 * @return bool true when the page was answered (the caller returns)
	 */
	private static function sync_blocked( $title, $active ) {
		$sync = CRDR_Sync::guard();
		if ( ! $sync['blocked'] ) {
			return false;
		}
		CRDR_UI::open( $title, $active );
		CRDR_UI::empty_state( $sync['message'], 'رفتن به داشبورد', admin_url( 'admin.php?page=customer-radar' ) );
		CRDR_UI::close();
		return true;
	}

	public static function render_calls() {
		global $wpdb;
		if ( self::sync_blocked( 'فهرست تماس امروز', 'customer-radar-calls' ) ) {
			return;
		}
		$f    = CRDR_Audience::normalize( array_merge( wp_unslash( $_GET ), array( 'view' => 'calls' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$per  = 50;
		$page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$t    = CRDR_DB::customers();
		$w    = CRDR_Audience::where( $f );
		$tot  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} c {$w}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} c {$w} ORDER BY c.priority DESC, c.last_ts ASC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		CRDR_UI::open( 'فهرست تماس امروز', 'customer-radar-calls', 'مشتریانی که موعد خرید بعدی‌شان رسیده یا گذشته، به ترتیب اولویت.' );
		echo '<p class="crdr-muted">مشتریانی که ' . esc_html( CRDR_UI::num( CRDR_Settings::get( 'cooldown_days' ) ) ) . ' روز اخیر با آن‌ها تماس گرفته شده یا گفته‌اند پیام نفرستید، پنهان‌اند. سرویس پیامک: <strong>' . esc_html( CRDR_SMS::provider_label() ) . '</strong></p>';
		CRDR_UI::filters( 'customer-radar-calls', $f, array( 'view' => 'calls' ) );
		CRDR_UI::filter_actions( $f, array( 'view' => 'calls' ) );

		if ( ! $rows ) {
			CRDR_UI::empty_state( 'مشتری‌ای با این فیلتر در فهرست نیست. اگر هنوز داده‌ها را تحلیل نکرده‌اید، از داشبورد «تحلیل سفارش‌ها» را بزنید.' );
			CRDR_UI::close();
			return;
		}
		echo '<div class="crdr-scroll"><table class="widefat striped crdr-table" id="crdr-calls"><thead><tr>';
		foreach ( array( 'مشتری', 'موبایل', 'بخش', 'سفارش‌ها', 'میانگین سبد', 'آخرین خرید', 'فاصله‌ی معمول', 'تأخیر', 'اولویت', 'اقدام' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$aov   = $r['orders_count'] > 0 ? $r['total_spent'] / $r['orders_count'] : 0;
			$since = max( 0, ( time() - $r['last_ts'] ) / DAY_IN_SECONDS );
			$late  = round( $since - $r['avg_gap_days'] );
			echo '<tr' . CRDR_Actions::row_attrs( $r ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td><a href="' . esc_url( self::detail_url( $r['id'] ) ) . '"><strong>' . esc_html( $r['name'] ?: '—' ) . '</strong></a></td>';
			echo '<td class="crdr-ltr">' . esc_html( $r['phone'] ) . '</td>';
			echo '<td>' . CRDR_UI::badge( $r['segment'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( CRDR_UI::num( $r['orders_count'] ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::money( $aov ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_Jalali::date( $r['last_ts'] ) ) . '<br><span class="crdr-muted">' . esc_html( CRDR_UI::num( floor( $since ) ) ) . ' روز پیش</span></td>';
			echo '<td>هر ' . esc_html( CRDR_UI::num( round( $r['avg_gap_days'] ) ) ) . ' روز</td>';
			echo '<td>' . ( $late > 0 ? esc_html( CRDR_UI::num( $late ) ) . ' روز دیرتر' : 'موعدش همین حالا است' ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::num( round( $r['priority'] ) ) ) . '</td>';
			echo '<td class="crdr-actions">' . self::action_buttons() . '<div class="crdr-row-msg"></div></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
		CRDR_UI::pager( $tot, $per, $page, 'customer-radar-calls', CRDR_Audience::to_args( $f ) );
		CRDR_UI::close();
	}

	private static function action_buttons() {
		return '<button type="button" class="button button-small" data-act="sms">پیامک</button> ' .
			'<button type="button" class="button button-small button-primary" data-act="sms_coupon">پیامک + کد تخفیف</button> ' .
			'<button type="button" class="button button-small" data-act="called">تماس گرفتم</button> ' .
			'<button type="button" class="button button-small" data-act="skip">امروز نه</button> ' .
			'<button type="button" class="button button-small button-link-delete" data-act="optout" title="دیگر برای این مشتری پیامی ارسال نشود">پیام نده</button>';
	}

	private static function detail_url( $id ) {
		return admin_url( 'admin.php?page=customer-radar-customer&id=' . (int) $id );
	}

	/* --------------------------------------------------------------- customers */

	public static function render_list() {
		global $wpdb;
		if ( self::sync_blocked( 'مشتریان', 'customer-radar-customers' ) ) {
			return;
		}
		$f       = CRDR_Audience::normalize( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$per     = 50;
		$page    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'last_ts'; // phpcs:ignore WordPress.Security.NonceVerification
		$orderby = in_array( $orderby, array( 'last_ts', 'total_spent', 'orders_count', 'priority' ), true ) ? $orderby : 'last_ts';
		$t       = CRDR_DB::customers();
		$w       = CRDR_Audience::where( $f );
		$tot     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} c {$w}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} c {$w} ORDER BY c.{$orderby} DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		CRDR_UI::open( 'مشتریان', 'customer-radar-customers', 'همه‌ی مشتریان با فیلتر پیشرفته؛ از هر فیلتر می‌توانید خروجی اکسل یا یک کمپین بسازید.' );
		CRDR_UI::filters( 'customer-radar-customers', $f );
		CRDR_UI::filter_actions( $f );
		echo '<p class="crdr-muted">' . esc_html( CRDR_UI::num( $tot ) ) . ' مشتری با این فیلتر پیدا شد.</p>';

		$base = CRDR_Audience::to_args( $f );
		echo '<div class="crdr-scroll"><table class="widefat striped crdr-table"><thead><tr><th>مشتری</th><th>موبایل</th><th>بخش</th><th title="R = تازگی خرید، F = تعداد خرید، M = مبلغ خرید؛ هر کدام از ۱ تا ۵ و عدد بیشتر بهتر است">امتیاز R / F / M</th>';
		foreach ( array( 'orders_count' => 'سفارش‌ها', 'total_spent' => 'جمع خرید', 'last_ts' => 'آخرین خرید', 'priority' => 'اولویت' ) as $col => $label ) {
			$url = add_query_arg( array_merge( $base, array( 'page' => 'customer-radar-customers', 'orderby' => $col ) ), admin_url( 'admin.php' ) );
			echo '<th><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . ( $orderby === $col ? ' ▾' : '' ) . '</a></th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">موردی پیدا نشد.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td><a href="' . esc_url( self::detail_url( $r['id'] ) ) . '"><strong>' . esc_html( $r['name'] ?: '—' ) . '</strong></a></td>';
			echo '<td class="crdr-ltr">' . esc_html( $r['phone'] ?: '—' ) . '</td>';
			echo '<td>' . CRDR_UI::badge( $r['segment'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td class="crdr-ltr">' . esc_html( CRDR_Jalali::digits( $r['r'] . ' / ' . $r['f'] . ' / ' . $r['m'] ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::num( $r['orders_count'] ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::money( $r['total_spent'] ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_Jalali::date( $r['last_ts'] ) ) . '</td>';
			echo '<td>' . esc_html( CRDR_UI::num( round( $r['priority'] ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		CRDR_UI::pager( $tot, $per, $page, 'customer-radar-customers', array_merge( $base, array( 'orderby' => $orderby ) ) );
		CRDR_UI::close();
	}

	/* ------------------------------------------------------------------ detail */

	public static function render_detail() {
		global $wpdb;
		if ( self::sync_blocked( 'مشتری', 'customer-radar-customers' ) ) {
			return;
		}
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$c    = CRDR_Actions::customer( $id );
		CRDR_UI::open( $c ? ( $c['name'] ?: 'مشتری' ) : 'مشتری', 'customer-radar-customers' );
		if ( ! $c ) {
			CRDR_UI::empty_state( 'مشتری پیدا نشد.', 'بازگشت به مشتریان', admin_url( 'admin.php?page=customer-radar-customers' ) );
			CRDR_UI::close();
			return;
		}
		$since = max( 0, floor( ( time() - $c['last_ts'] ) / DAY_IN_SECONDS ) );
		echo '<div class="crdr-card"' . CRDR_Actions::row_attrs( $c ) . ' id="crdr-customer"><div class="crdr-insight-head"><h2>' . esc_html( $c['name'] ?: '—' ) . '</h2>' . CRDR_UI::badge( $c['segment'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="crdr-muted"><span class="crdr-ltr">' . esc_html( $c['phone'] ?: 'بدون شماره' ) . '</span> · ' . esc_html( $c['email'] ?: 'بدون ایمیل' ) . '</p>';
		echo '<div class="crdr-kpis">';
		CRDR_UI::kpi( 'سفارش‌ها', esc_html( CRDR_UI::num( $c['orders_count'] ) ) );
		CRDR_UI::kpi( 'جمع خرید', esc_html( CRDR_UI::money( $c['total_spent'] ) ) );
		CRDR_UI::kpi( 'میانگین سبد', esc_html( CRDR_UI::money( $c['orders_count'] ? $c['total_spent'] / $c['orders_count'] : 0 ) ) );
		CRDR_UI::kpi( 'آخرین خرید', esc_html( CRDR_Jalali::date( $c['last_ts'] ) ), CRDR_UI::num( $since ) . ' روز پیش' );
		CRDR_UI::kpi( 'فاصله‌ی معمول', 'هر ' . esc_html( CRDR_UI::num( round( $c['avg_gap_days'] ) ) ) . ' روز', 'موعد بعدی: ' . CRDR_Jalali::date( $c['due_ts'] ) );
		CRDR_UI::kpi( 'امتیاز R / F / M', '<span class="crdr-ltr">' . esc_html( CRDR_Jalali::digits( $c['r'] . ' / ' . $c['f'] . ' / ' . $c['m'] ) ) . '</span>' );
		echo '</div>';

		$reco = CRDR_Recommend::for_customer( $c['ckey'] );
		if ( $reco ) {
			echo '<p><strong>پیشنهاد محصول مکمل:</strong> «' . esc_html( $reco['name'] ) . '» (چون «' . esc_html( $reco['because'] ) . '» خریده است)</p>';
		}
		if ( $c['phone'] ) {
			echo '<div class="crdr-actions">' . self::action_buttons() . '<div class="crdr-row-msg"></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';

		/* orders timeline */
		$orders = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CRDR_DB::orders() . ' WHERE ckey = %s ORDER BY ts DESC LIMIT 50', $c['ckey'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		echo '<div class="crdr-card"><h3>سفارش‌ها</h3><div class="crdr-scroll"><table class="widefat striped crdr-table"><thead><tr><th>تاریخ</th><th>سفارش</th><th>مبلغ</th><th>محصولات</th></tr></thead><tbody>';
		foreach ( $orders as $o ) {
			$items = $wpdb->get_results( $wpdb->prepare( 'SELECT product_id, qty FROM ' . CRDR_DB::items() . ' WHERE order_id = %d', $o['order_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$names = array();
			foreach ( $items as $it ) {
				$names[] = CRDR_Analytics::product_name( $it['product_id'] ) . ( $it['qty'] > 1 ? ' ×' . CRDR_UI::num( $it['qty'] ) : '' );
			}
			$edit = get_edit_post_link( $o['order_id'] );
			$edit = $edit ? $edit : admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $o['order_id'] );
			echo '<tr><td>' . esc_html( CRDR_Jalali::date( $o['ts'] ) ) . '</td><td><a href="' . esc_url( $edit ) . '">#' . esc_html( CRDR_UI::num( $o['order_id'] ) ) . '</a></td><td>' . esc_html( CRDR_UI::money( $o['net'] ) ) . '</td><td class="crdr-muted">' . esc_html( $names ? implode( '، ', $names ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';

		/* contact history */
		$log = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CRDR_DB::contacts() . ' WHERE ckey = %s ORDER BY id DESC LIMIT 30', $c['ckey'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		echo '<div class="crdr-card"><h3>سابقه‌ی تماس و پیام</h3>';
		if ( ! $log ) {
			echo '<p class="crdr-muted">تا امروز پیام یا تماسی ثبت نشده است.</p>';
		} else {
			$kinds = array( 'sms' => 'پیامک', 'called' => 'تماس', 'skip' => 'امروز نه', 'optout' => 'عدم تمایل به پیام' );
			echo '<ul class="crdr-log">';
			foreach ( $log as $l ) {
				$o     = CRDR_Send_Rules::outcome( $l['status'] );
				$state = '';
				if ( 'sms' === $l['kind'] ) { // what the data can say about this SMS (never more than "accepted by the SMS service")
					$state = ' — <span title="' . esc_attr( $o['help'] ) . '">' . esc_html( $o['label'] ) . '</span>' . ( $l['error'] ? ': ' . esc_html( $l['error'] ) : '' );
				}
				echo '<li><strong>' . esc_html( CRDR_Jalali::date( $l['created_ts'] ) ) . '</strong> — ' . esc_html( isset( $kinds[ $l['kind'] ] ) ? $kinds[ $l['kind'] ] : $l['kind'] ) . $state . ( $l['playbook'] && 'manual' !== $l['playbook'] ? ' · کمپین: ' . esc_html( $l['playbook'] ) : '' ) . ( $l['message'] ? '<div class="crdr-muted">' . esc_html( $l['message'] ) . '</div>' : '' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul>';
		}
		echo '</div>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=customer-radar-customers' ) ) . '">← بازگشت به مشتریان</a></p>';
		CRDR_UI::close();
	}
}
