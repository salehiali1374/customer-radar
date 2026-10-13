<?php
defined( 'ABSPATH' ) || exit;

/**
 * WordPress personal-data export and erasure (Tools > Export / Erase Personal Data) for what this plugin stores about a person.
 * It describes behaviour. It is not legal advice and does not claim that any law is met.
 *
 * WHO is "the person": the e-mail address WordPress passes in. From it the plugin finds
 *   - the customer keys the person's ORDERS carry (crdr_orders: usable billing e-mail = that address, or the registered user's id),
 *   - the keys that were joined to those by the identity rules (crdr_identity_links), the key e:<address> and u:<user id>.
 * A key is EXCLUSIVE when no other order (another e-mail, no account) was ever attributed to it. A shared key (a phone number that
 * somebody else also used, a household mailbox) is never touched: other people's records are not this person's to erase.
 *
 * ERASURE removes, for the exclusive keys: the customer row, the order rows and their item rows, every contact row except opt-outs
 * (SMS history with the message text, calls, "not today"), the remembered joins and the kept-apart entries, and the discount codes this
 * plugin made for that person (unused codes are deleted; used ones keep their accounting but lose the description, the hidden customer
 * key, the e-mail restriction and this person from the "used by" list).
 * It RETAINS, and says so in the answer WordPress shows: the opt-out rows (see keep_optout()), anything about shared keys, and a send
 * that is still in flight. WooCommerce's own eraser (separate, run by WordPress next to this one) decides what happens to the orders themselves;
 * if WooCommerce keeps the orders, the next rebuild recreates the customer from them (what WooCommerce keeps, this plugin mirrors).
 *
 * The opt-out rows are the one decision that is not technical: erasing them lets the number be messaged again. By default they are
 * kept and reduced to who and when (no text); `crdr_privacy_keep_optout` (filter, default true) turns that off. docs/data-retention.md.
 */
class CRDR_Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['customer-radar'] = array( 'exporter_friendly_name' => 'رادار مشتری', 'callback' => array( __CLASS__, 'export' ) );
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['customer-radar'] = array( 'eraser_friendly_name' => 'رادار مشتری', 'callback' => array( __CLASS__, 'erase' ) );
		return $erasers;
	}

	/** Suggested privacy-policy text (WordPress shows it under Settings > Privacy > Policy Guide). A starting point to review, not legal text. */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'رادار مشتری', wp_kses_post( '<p class="privacy-policy-tutorial">' . esc_html( 'متن پیشنهادی؛ پیش از استفاده بازبینی و با شرایط کسب‌وکار خودتان تطبیق دهید.' ) . '</p>'
			. '<p>' . esc_html( 'از سفارش‌ها نام، شماره‌ی موبایل و ایمیل مشتری و مبلغ و تاریخ خریدها در جدول‌های همین سایت ذخیره و تحلیل می‌شود. هر پیامک و تماس ثبت‌شده (با متن پیامک ارسال‌شده، کد تخفیف و زمان) در همین سایت نگهداری می‌شود، و فهرست کسانی که پیام نخواسته‌اند تا زمانی که مدیر سایت آن را پاک کند باقی می‌ماند.' ) . '</p>'
			. '<p>' . esc_html( 'برای ارسال پیامک، شماره‌ی گیرنده و متن پیامک به سرویس پیامک انتخاب‌شده در تنظیمات فرستاده می‌شود: کاوه‌نگار (اتصال مستقیم) یا هر سرویسی که افزونه‌ی «پیامک ووکامرس» به آن وصل است. غیر از این، افزونه داده‌ای به سرویس بیرونی نمی‌فرستد.' ) . '</p>'
			. '<p>' . esc_html( 'اگر پیامک‌ها را از طریق افزونه‌ی «پیامک ووکامرس» می‌فرستید، آن افزونه از هر پیامکی که خودش ارسال می‌کند، شماره‌ی گیرنده و متن را در جدول بایگانی خودش نگه می‌دارد. رادار مشتری آن بایگانی را نمی‌خواند، پاک نمی‌کند و مدت نگهداری‌اش را کنترل نمی‌کند؛ پاک‌کردن اطلاعات یک مشتری در رادار مشتری آن را پاک نمی‌کند. مدت نگهداری و پاک‌کردن آن با تنظیمات همان افزونه، سرویس پیامک و میزبان شماست.' ) . '</p>'
			. '<p>' . esc_html( 'برای کم‌کردن حجم داده، متن پیامک‌هایی که هرگز ارسال نشده‌اند پاک می‌شود و بعد از ۹۰ روز، متن و کد تخفیفِ سابقه‌ی مشتریانی که دیگر در سفارش‌ها نیستند هم پاک می‌شود. ولی ردیف سابقه‌ی آن‌ها (بدون متن) باقی می‌ماند و ممکن است شماره‌ی موبایل مشتری را نگه دارد؛ چون محدودیت «تماس اخیر» و سقف روزانه از همین ردیف‌ها حساب می‌شود. فهرست «پیام نده» هرگز خودکار پاک نمی‌شود.' ) . '</p>'
			. '<p>' . esc_html( 'پشتیبان‌گیری از پایگاه‌داده را خودتان انجام می‌دهید. اگر پشتیبانِ قدیمی‌تری را بازگردانید، کسانی که بعد از تاریخ آن پشتیبان «پیام نده» شده‌اند دوباره در فهرست پیام‌گیرندگان قرار می‌گیرند و تا زمانی که دوباره ثبتشان نکنید ممکن است پیامک بگیرند. افزونه نمی‌تواند این را خودش تشخیص دهد یا جبران کند.' ) . '</p>'
			. '<p>' . esc_html( 'خروجی اکسل (CSV) مشتریان حداکثر ۵۰٬۰۰۰ مشتری را در بر می‌گیرد و شامل مشتریانی که «پیام نده» شده‌اند هم می‌شود (ستون آخر فایل آن‌ها را علامت می‌زند و فیلتری برای حذفشان از فایل وجود ندارد). فایل را همان‌طور که اطلاعات شخصی را نگه می‌دارید نگه دارید و پیش از استفاده در پنل پیامک، ردیف‌های علامت‌خورده را حذف کنید.' ) . '</p>' ) );
	}

	/** Keep the opt-out rows of an erased person (default), so the number cannot be messaged again by accident. */
	public static function keep_optout() {
		return (bool) apply_filters( 'crdr_privacy_keep_optout', true );
	}

	/* ------------------------------------------------------------------ who */

	/**
	 * @return array email, uid, keys (all keys of the person), exclusive (erasable), shared (left alone), orders (order id => key of the person's orders)
	 */
	public static function person( $email ) {
		global $wpdb;
		$out   = array( 'email' => '', 'uid' => 0, 'keys' => array(), 'exclusive' => array(), 'shared' => array(), 'orders' => array() );
		$email = CRDR_Identity::email( $email );
		if ( '' === $email ) {
			return $out;
		}
		$user = get_user_by( 'email', $email );
		$uid  = $user ? (int) $user->ID : 0;
		$o    = CRDR_DB::orders();
		$cond = $wpdb->prepare( 'email = %s', $email );
		if ( $uid ) {
			$cond .= $wpdb->prepare( ' OR uid = %d', $uid );
		}
		$keys = array( 'e:' . $email => true );
		if ( $uid ) {
			$keys[ 'u:' . $uid ] = true;
		}
		foreach ( (array) $wpdb->get_results( "SELECT order_id, ckey FROM {$o} WHERE {$cond}", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$keys[ $r['ckey'] ]                 = true;
			$out['orders'][ (int) $r['order_id'] ] = $r['ckey'];
		}
		// A discount code this plugin made for this e-mail carries the customer key it was made for (hidden meta _crdr_ckey). It outlives the
		// orders: when WooCommerce anonymised them and a rebuild dropped the customer, this is the only link left from the e-mail to the phone key.
		foreach ( self::coupon_keys_for_email( $email ) as $ck ) {
			$keys[ $ck ] = true;
		}
		// the keys the identity rules joined to those (the same person by the plugin's own rules)
		$links = CRDR_Identity::clean_links( get_option( CRDR_Sync::LINKS_OPTION, array() ) )['links'];
		foreach ( CRDR_Identity::components( $links ) as $group ) {
			if ( array_intersect( $group, array_keys( $keys ) ) ) {
				foreach ( $group as $k ) {
					$keys[ $k ] = true;
				}
			}
		}
		foreach ( array_keys( $keys ) as $k ) {
			if ( ! CRDR_Identity::valid_key( $k ) ) {
				continue;
			}
			// another person's order attributed to the same key (a shared phone number): the key is not exclusively this person's
			$other = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$o} WHERE ckey = %s AND NOT ( email = %s" . ( $uid ? ' OR uid = ' . (int) $uid : '' ) . ' )', $k, $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$out['keys'][] = $k;
			if ( $other ) {
				$out['shared'][] = $k;
			} else {
				$out['exclusive'][] = $k;
			}
		}
		$out['email'] = $email;
		$out['uid']   = $uid;
		sort( $out['keys'], SORT_STRING );
		sort( $out['exclusive'], SORT_STRING );
		sort( $out['shared'], SORT_STRING );
		return $out;
	}

	/** Customer keys of the plugin's own coupons that are restricted to this e-mail address. */
	private static function coupon_keys_for_email( $email ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '"' . $email . '"' ) . '%';
		$out  = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT k.meta_value FROM {$wpdb->postmeta} e JOIN {$wpdb->postmeta} k ON k.post_id = e.post_id AND k.meta_key = '_crdr_ckey' WHERE e.meta_key = 'customer_email' AND LOWER(e.meta_value) LIKE %s", $like ) ) as $key ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( CRDR_Identity::valid_key( (string) $key ) ) {
				$out[ (string) $key ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * How many rows of each kind exist for these customer keys (what an erasure has to remove).
	 *
	 * @return array customers, orders, contacts (everything except opt-outs and sends in flight), optouts, pending, or false on a database error
	 */
	private static function count_rows( array $keys ) {
		global $wpdb;
		$out = array( 'customers' => 0, 'orders' => 0, 'contacts' => 0, 'optouts' => 0, 'pending' => 0 );
		if ( ! $keys ) {
			return $out;
		}
		$in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$k  = CRDR_DB::contacts();
		$q  = array(
			'customers' => 'SELECT COUNT(*) FROM ' . CRDR_DB::customers() . " WHERE ckey IN ({$in})",
			'orders'    => 'SELECT COUNT(*) FROM ' . CRDR_DB::orders() . " WHERE ckey IN ({$in})",
			'contacts'  => "SELECT COUNT(*) FROM {$k} WHERE ckey IN ({$in}) AND kind <> 'optout' AND status <> 'pending'",
			'optouts'   => "SELECT COUNT(*) FROM {$k} WHERE ckey IN ({$in}) AND kind = 'optout'",
			'pending'   => "SELECT COUNT(*) FROM {$k} WHERE ckey IN ({$in}) AND status = 'pending'",
		);
		foreach ( $q as $name => $sql ) {
			$n = $wpdb->get_var( $wpdb->prepare( $sql, $keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( null === $n || $wpdb->last_error ) {
				return false;
			}
			$out[ $name ] = (int) $n;
		}
		return $out;
	}

	/** Coupon ids this plugin made for the person: made for one of the keys, or restricted to the person's e-mail. */
	private static function coupon_ids( array $p ) {
		global $wpdb;
		$ids = array();
		if ( $p['keys'] ) {
			$in = implode( ',', array_fill( 0, count( $p['keys'] ), '%s' ) );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_crdr_ckey' AND meta_value IN ({$in})", $p['keys'] ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$ids[ (int) $id ] = true;
			}
		}
		if ( '' !== $p['email'] ) { // only coupons that carry this plugin's hidden key: a coupon the shop made by hand is not ours to touch
			$like = '%' . $wpdb->esc_like( '"' . $p['email'] . '"' ) . '%';
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT e.post_id FROM {$wpdb->postmeta} e JOIN {$wpdb->postmeta} k ON k.post_id = e.post_id AND k.meta_key = '_crdr_ckey' WHERE e.meta_key = 'customer_email' AND LOWER(e.meta_value) LIKE %s", $like ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$ids[ (int) $id ] = true;
			}
		}
		return array_keys( $ids );
	}

	/* --------------------------------------------------------------- export */

	/** WordPress exporter callback. */
	public static function export( $email, $page = 1 ) {
		global $wpdb;
		$p    = self::person( $email );
		$data = array();
		$add  = function ( $group, $label, $id, array $fields ) use ( &$data ) {
			$items = array();
			foreach ( $fields as $name => $value ) {
				if ( '' !== (string) $value ) {
					$items[] = array( 'name' => $name, 'value' => (string) $value );
				}
			}
			$data[] = array( 'group_id' => $group, 'group_label' => $label, 'item_id' => $id, 'data' => $items );
		};
		if ( ! $p['keys'] ) {
			return array( 'data' => array(), 'done' => true );
		}
		$in = implode( ',', array_fill( 0, count( $p['keys'] ), '%s' ) );
		$sync = CRDR_Sync::guard();
		if ( $sync['blocked'] ) {
			$add( 'crdr-note', 'رادار مشتری: توضیح', 'crdr-note-1', array( 'توضیح' => 'جدول‌های مشتریان و سفارش‌ها هم‌اکنون در حال به‌روزرسانی‌اند؛ این خروجی ممکن است ناقص باشد. چند دقیقه بعد دوباره درخواست دهید.' ) );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CRDR_DB::customers() . " WHERE ckey IN ({$in}) ORDER BY id", $p['keys'] ), ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$add( 'crdr-customer', 'رادار مشتری: مشتری', 'crdr-customer-' . (int) $c['id'], array(
				'نام' => $c['name'], 'موبایل' => $c['phone'], 'ایمیل' => $c['email'], 'تعداد سفارش' => $c['orders_count'], 'جمع خرید' => $c['total_spent'],
				'اولین خرید' => CRDR_Jalali::date( $c['first_ts'], false ), 'آخرین خرید' => CRDR_Jalali::date( $c['last_ts'], false ), 'بخش' => $c['segment'],
			) );
		}
		$cond = $wpdb->prepare( 'email = %s', $p['email'] ) . ( $p['uid'] ? $wpdb->prepare( ' OR uid = %d', $p['uid'] ) : '' );
		foreach ( (array) $wpdb->get_results( 'SELECT order_id, ts, net FROM ' . CRDR_DB::orders() . " WHERE {$cond} ORDER BY order_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$add( 'crdr-orders', 'رادار مشتری: نسخه‌ی تحلیلی سفارش‌ها', 'crdr-order-' . (int) $r['order_id'], array( 'شماره‌ی سفارش' => $r['order_id'], 'تاریخ' => CRDR_Jalali::date( $r['ts'], false ), 'مبلغ خالص' => $r['net'] ) );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CRDR_DB::contacts() . " WHERE ckey IN ({$in}) ORDER BY id", $p['keys'] ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$add( 'crdr-contacts', 'رادار مشتری: سابقه‌ی پیام و تماس', 'crdr-contact-' . (int) $r['id'], array(
				'نوع' => $r['kind'], 'وضعیت' => $r['status'], 'زمان' => CRDR_Jalali::date( $r['created_ts'] ) . ' ' . wp_date( 'H:i', (int) $r['created_ts'] ),
				'کمپین' => $r['playbook'], 'متن پیامک' => $r['message'], 'کد تخفیف' => $r['coupon'], 'کلید مشتری' => $r['ckey'],
			) );
		}
		if ( class_exists( 'WC_Coupon' ) ) {
			foreach ( self::coupon_ids( $p ) as $id ) {
				$c = new WC_Coupon( $id );
				$add( 'crdr-coupons', 'رادار مشتری: کدهای تخفیف', 'crdr-coupon-' . (int) $id, array(
					'کد' => $c->get_code(), 'توضیح' => $c->get_description(), 'محدود به ایمیل' => implode( '، ', (array) $c->get_email_restrictions() ), 'تعداد استفاده' => $c->get_usage_count(),
				) );
			}
		}
		$links = CRDR_Identity::clean_links( get_option( CRDR_Sync::LINKS_OPTION, array() ) )['links'];
		foreach ( $links as $a => $b ) {
			if ( in_array( $a, $p['keys'], true ) || in_array( $b, $p['keys'], true ) ) {
				$add( 'crdr-identity', 'رادار مشتری: ادغام شناسه‌ها', 'crdr-link-' . substr( md5( $a ), 0, 12 ), array( 'شناسه‌ی ادغام‌شده' => $a, 'ادغام با' => $b ) );
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	/* -------------------------------------------------------------- erasure */

	/**
	 * WordPress eraser callback.
	 *
	 * What it answers is what it did, counted from the database and then verified:
	 *  - items_removed : true only when rows or coupons it LOCATED for this person were really deleted or scrubbed;
	 *  - items_retained: true when something it located was kept on purpose (opt-outs, a send in flight, a key shared with another person),
	 *    when a row it tried to remove is still there afterwards, when a database statement failed, and when records exist that it could
	 *    not attribute to this e-mail address at all (see CRDR_Retention: contact rows whose customer is gone);
	 *  - messages say which.
	 * It never reports the removal of records it did not find.
	 *
	 * @return array items_removed, items_retained, messages, done
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$res = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		$p   = self::person( $email );
		if ( '' === $p['email'] ) {
			$res['messages'][] = 'نشانی ایمیل معتبر نیست؛ رادار مشتری چیزی را پاک نکرد.';
			return $res;
		}
		$ran = CRDR_Sync::exclusive( function () use ( $p, &$res, $wpdb ) {
			$E       = $p['exclusive'];
			$located = 0;  // things found for this person (rows, coupons)
			$failed  = false;
			if ( $E ) {
				$in     = implode( ',', array_fill( 0, count( $E ), '%s' ) );
				$k      = CRDR_DB::contacts();
				$o      = CRDR_DB::orders();
				$before = self::count_rows( $E );
				if ( false === $before ) {
					$res['items_retained'] = true;
					$res['messages'][]     = CRDR_Health::message( 'db_error' );
					return;
				}
				$located += $before['customers'] + $before['orders'] + $before['contacts'] + $before['optouts'] + $before['pending'];
				$gone     = 0;
				$del      = function ( $sql ) use ( $wpdb, &$gone, &$failed ) {
					$r = $wpdb->query( $sql );
					if ( false === $r ) {
						$failed = true;
						return;
					}
					$gone += (int) $r;
				};
				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$o} WHERE ckey IN ({$in})", $E ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				foreach ( array_chunk( $ids, 500 ) as $chunk ) {
					$del( 'DELETE FROM ' . CRDR_DB::items() . ' WHERE order_id IN (' . implode( ',', $chunk ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
				}
				$del( $wpdb->prepare( "DELETE FROM {$o} WHERE ckey IN ({$in})", $E ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				$del( $wpdb->prepare( 'DELETE FROM ' . CRDR_DB::customers() . " WHERE ckey IN ({$in})", $E ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				$del( $wpdb->prepare( "DELETE FROM {$k} WHERE ckey IN ({$in}) AND kind <> 'optout' AND status <> 'pending'", $E ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $before['pending'] ) {
					$res['items_retained'] = true;
					$res['messages'][]     = 'یک پیامک هنوز در حال ارسال است و دست‌نخورده ماند؛ چند دقیقه بعد دوباره پاک‌سازی را اجرا کنید.';
				}
				if ( $before['optouts'] ) {
					if ( self::keep_optout() ) {
						$r = $wpdb->query( $wpdb->prepare( "UPDATE {$k} SET message = '', error = '', coupon = '', user_id = 0, run_id = '', playbook = '' WHERE ckey IN ({$in}) AND kind = 'optout'", $E ) ); // phpcs:ignore WordPress.DB.PreparedSQL
						if ( false === $r ) {
							$failed = true;
						}
						$res['items_retained'] = true;
						$res['messages'][]     = 'فهرست «پیام نده» نگه داشته شد (فقط شناسه‌ی مشتری و زمان ثبت، بدون هیچ متن)، تا شماره‌ی این مشتری دوباره پیامک نگیرد. اگر می‌خواهید آن هم پاک شود، باید با تصمیم آگاهانه‌ی مدیر انجام شود.';
					} else {
						$del( $wpdb->prepare( "DELETE FROM {$k} WHERE ckey IN ({$in}) AND kind = 'optout'", $E ) ); // phpcs:ignore WordPress.DB.PreparedSQL
					}
				}
				$gone += CRDR_Sync::forget_keys_locked( $E );
				// VERIFY: what was counted must be gone (opt-outs and a send in flight are kept on purpose and checked separately above).
				$after = self::count_rows( $E );
				if ( false === $after ) {
					$failed = true;
				} else {
					$left = $after['customers'] + $after['orders'] + $after['contacts'] + ( self::keep_optout() ? 0 : $after['optouts'] );
					if ( $left > 0 ) {
						$failed                = true;
						$res['items_retained'] = true;
						$res['messages'][]     = 'پس از پاک‌سازی هنوز ' . CRDR_Jalali::digits( (string) $left ) . ' ردیف از داده‌های این مشتری در جدول‌های رادار مشتری باقی مانده است؛ دوباره اجرا کنید و اگر ماند با پشتیبانی تماس بگیرید.';
					}
				}
				if ( $gone > 0 ) {
					$res['items_removed'] = true; // rows really went (if not all of them, the message above says so)
				}
			}
			if ( $failed ) {
				$res['items_retained'] = true;
				$res['messages'][]     = CRDR_Health::message( 'db_error' );
			}
			$coupons = self::erase_coupons( $p );
			if ( $coupons['located'] ) {
				$located += $coupons['located'];
			}
			if ( $coupons['changed'] ) {
				$res['items_removed'] = true;
			}
			if ( $p['shared'] ) {
				$res['items_retained'] = true;
				$res['messages'][]     = 'بخشی از اطلاعات به شماره یا شناسه‌ای وصل است که سفارش‌های مشتری دیگری هم دارد؛ برای حفظ داده‌ی دیگران دست نخورد (با به‌روزرسانی بعدی داده‌ها، سفارش‌های پاک‌شده خودبه‌خود از آمار کم می‌شوند).';
				$located++;
			}
			// Nothing at all could be attributed to this address. If contact rows of customers who no longer exist are around (WooCommerce
			// anonymised their orders), say so: they may be this person's, and they are not erased by this request.
			if ( 0 === $located ) {
				$orphans = CRDR_Retention::unattributed_count();
				if ( $orphans > 0 ) {
					$res['items_retained'] = true;
					$res['messages'][]     = 'رادار مشتری هیچ سابقه‌ای را به این نشانی ایمیل نسبت نداد، ولی ' . CRDR_Jalali::digits( (string) $orphans ) . ' سابقه‌ی پیامک مربوط به مشتریانی که دیگر سفارشی ندارند (مثلاً سفارش‌هایشان را ووکامرس ناشناس کرده) هنوز وجود دارد و به هیچ ایمیلی قابل نسبت‌دادن نیست؛ ممکن است مال همین شخص باشد. متن این سابقه‌ها ' . CRDR_Jalali::digits( (string) CRDR_Retention::SWEEP_DAYS ) . ' روز پس از ناپدید شدن مشتری خودکار پاک می‌شود.';
				}
			}
		} );
		if ( ! $ran['ok'] ) {
			$res['items_retained'] = true;
			$res['messages'][]     = 'پاک‌سازی رادار مشتری انجام نشد، چون داده‌ها هم‌اکنون در حال به‌روزرسانی‌اند یا کار دیگری در جریان است؛ چند دقیقه بعد دوباره اجرا کنید.';
		}
		return $res;
	}

	/**
	 * Delete (unused) or scrub (used) the discount codes this plugin made for the person.
	 *
	 * @return array located (coupons found), changed (whether any was deleted or scrubbed)
	 */
	private static function erase_coupons( array $p ) {
		$out = array( 'located' => 0, 'changed' => false );
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return $out;
		}
		foreach ( self::coupon_ids( $p ) as $id ) {
			$c = new WC_Coupon( $id );
			if ( ! $c->get_id() ) {
				continue;
			}
			$out['located']++;
			if ( 0 === (int) $c->get_usage_count() ) {
				$out['changed'] = $c->delete( true ) || $out['changed'];
				continue;
			}
			// used: the accounting stays (code, amount, how many times); the person does not
			$c->set_description( '' );
			$c->delete_meta_data( '_crdr_ckey' );
			$c->set_email_restrictions( array_values( array_diff( array_map( 'strtolower', (array) $c->get_email_restrictions() ), array( $p['email'] ) ) ) );
			$c->save();
			// WooCommerce keeps one `_used_by` row per use (an e-mail or a user id): remove this person's rows, leave the others.
			delete_post_meta( $id, '_used_by', $p['email'] );
			if ( $p['uid'] ) {
				delete_post_meta( $id, '_used_by', (string) $p['uid'] );
			}
			$out['changed'] = true;
		}
		return $out;
	}
}
