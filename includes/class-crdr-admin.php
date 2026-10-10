<?php
defined( 'ABSPATH' ) || exit;

/**
 * Menu, assets and page routing. The screens themselves live in class-crdr-page-*.php.
 */
class CRDR_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( 'CRDR_Health', 'admin_notices' ) );
	}

	public static function menu() {
		$cap = CRDR_Actions::CAP;
		add_menu_page( 'رادار مشتری', 'رادار مشتری', $cap, 'customer-radar', array( 'CRDR_Page_Dashboard', 'render' ), 'dashicons-chart-area', 56 );
		add_submenu_page( 'customer-radar', 'داشبورد', 'داشبورد', $cap, 'customer-radar', array( 'CRDR_Page_Dashboard', 'render' ) );
		add_submenu_page( 'customer-radar', 'رشد و پیشنهادها', 'رشد و پیشنهادها', $cap, 'customer-radar-growth', array( 'CRDR_Page_Growth', 'render' ) );
		add_submenu_page( 'customer-radar', 'کمپین‌ها', 'کمپین‌ها', $cap, 'customer-radar-campaigns', array( 'CRDR_Page_Campaigns', 'render' ) );
		add_submenu_page( 'customer-radar', 'فهرست تماس امروز', 'فهرست تماس', $cap, 'customer-radar-calls', array( 'CRDR_Page_Customers', 'render_calls' ) );
		add_submenu_page( 'customer-radar', 'مشتریان', 'مشتریان', $cap, 'customer-radar-customers', array( 'CRDR_Page_Customers', 'render_list' ) );
		add_submenu_page( 'customer-radar', 'محصولات و مکمل', 'محصولات و مکمل', $cap, 'customer-radar-products', array( 'CRDR_Page_Products', 'render' ) );
		add_submenu_page( 'customer-radar', 'تنظیمات', 'تنظیمات', $cap, 'customer-radar-settings', array( 'CRDR_Page_Settings', 'render' ) );
		// Hidden page: one customer.
		add_submenu_page( 'options.php', 'مشتری', 'مشتری', $cap, 'customer-radar-customer', array( 'CRDR_Page_Customers', 'render_detail' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'customer-radar' ) ) {
			return;
		}
		wp_enqueue_style( 'crdr-admin', CRDR_URL . 'assets/admin.css', array(), CRDR_VERSION );
		wp_enqueue_script( 'crdr-charts', CRDR_URL . 'assets/charts.js', array(), CRDR_VERSION, true );
		wp_enqueue_script( 'crdr-admin', CRDR_URL . 'assets/admin.js', array( 'crdr-charts' ), CRDR_VERSION, true );
		wp_localize_script( 'crdr-admin', 'CRDR', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( CRDR_Actions::NONCE ),
		) );
	}
}
