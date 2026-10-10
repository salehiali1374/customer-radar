<?php
/**
 * Removes the plugin's own data when it is deleted from the Plugins screen.
 * Coupons already sent to customers are left untouched on purpose.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}crdr_customers" ); // phpcs:ignore WordPress.DB.PreparedSQL
foreach ( array( 'crdr_orders', 'crdr_order_items', 'crdr_contacts', 'crdr_log' ) as $crdr_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$crdr_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}
delete_option( 'crdr_settings' );
delete_option( 'crdr_sync_state' );
delete_option( 'crdr_db_version' );
delete_option( 'crdr_stats' );
delete_option( 'crdr_playbooks' );
delete_option( 'crdr_run_log' );
delete_option( 'crdr_cron_log' );
delete_option( 'crdr_run_log_lost' );
delete_option( 'crdr_run_health' );
delete_transient( 'crdr_pb_lock' );
wp_clear_scheduled_hook( 'crdr_daily' );
wp_clear_scheduled_hook( 'crdr_sync_step' );
wp_clear_scheduled_hook( 'crdr_hourly' );
