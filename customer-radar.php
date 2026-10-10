<?php
/**
 * Plugin Name:       رادار مشتری | Customer Radar
 * Description:       تحلیل RFM مشتریان ووکامرس، بخش‌بندی خودکار و «فهرست تماس امروز» برای بازگرداندن مشتریان قدیمی، با ارسال پیامک و کد تخفیف اختصاصی.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            YOUR_NAME
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       customer-radar
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'CRDR_VERSION', '1.0.0' );
define( 'CRDR_FILE', __FILE__ );
define( 'CRDR_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRDR_URL', plugin_dir_url( __FILE__ ) );

foreach ( array(
	'jalali', 'rfm', 'stats', 'template', 'send-rules', 'db', 'settings', 'analytics', 'audience', 'sms', 'send', 'health', 'sync', 'recommend',
	'playbooks', 'insights', 'actions', 'ui',
	'page-dashboard', 'page-growth', 'page-campaigns', 'page-customers', 'page-products', 'page-settings', 'admin',
) as $crdr_file ) {
	require_once CRDR_DIR . 'includes/class-crdr-' . $crdr_file . '.php';
}

// Compatible with High-Performance Order Storage.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CRDR_FILE, true );
	}
} );

register_activation_hook( CRDR_FILE, array( 'CRDR_DB', 'install' ) );
register_deactivation_hook( CRDR_FILE, function () {
	CRDR_Sync::unschedule();
	CRDR_Playbooks::unschedule();
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>افزونه «رادار مشتری» برای کار کردن به ووکامرس نیاز دارد.</p></div>';
		} );
		return;
	}
	CRDR_DB::maybe_upgrade();
	CRDR_Sync::init();
	CRDR_Playbooks::init();
	CRDR_Actions::init();
	CRDR_Admin::init();
} );
