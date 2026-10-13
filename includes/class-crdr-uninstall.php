<?php
defined( 'ABSPATH' ) || exit;

/**
 * What happens to the plugin's data when the plugin is DELETED from the Plugins screen (uninstall.php calls run()).
 *
 * Deleting a plugin is not a request to forget people. The default therefore keeps the one thing that protects them: the people who asked
 * not to be messaged (the rows of `crdr_contacts` with kind = 'optout'), so that deleting and re-installing the plugin (the usual way
 * to "update" by uploading a new ZIP) cannot make an opted-out customer messageable again. Everything else goes.
 *
 *   default (setting "erase on uninstall" off)
 *     removed : customer, order and item tables; every other row of the contact table (SMS history, cooldowns, calls, "not today");
 *               all options (settings including the Kavenegar key, remembered joins, logs, health, sync state); transients; scheduled work
 *     kept    : the opt-out rows, reduced to who (the customer key: p:<phone>, e:<email>, ...) and when; no text, no staff id, no coupon, no error
 *     untouched: WooCommerce coupons this plugin created (a coupon is a WooCommerce object that customers may still hold)
 *
 *   explicit full erasure (the box "erase everything when the plugin is deleted" ticked in Settings BEFORE deleting)
 *     removed : everything above INCLUDING the opt-out rows and the contact table itself
 *     scrubbed: the plugin's coupons lose the description and the hidden customer key (phone / e-mail); code, amount, expiry and the e-mail
 *               restriction stay (they are what makes the code work)
 *
 * Not supported: multisite. This runs for the current site only; the plugin is not tested network-activated.
 * The same function is what tests/release-safety.php runs.
 */
class CRDR_Uninstall {

	/** Key in the `crdr_settings` option. */
	const FLAG = 'erase_on_uninstall';

	/** Same value as CRDR_Site::ACK_PREFIX (this file is loaded alone by uninstall.php; a test compares the two). */
	const ACK_PREFIX = 'crdr_ack_';

	/** Every option the plugin ever creates (kept in step with the code by tests/release-safety.php). */
	public static function options() {
		return array(
			'crdr_settings', 'crdr_sync_state', 'crdr_sync_lock', 'crdr_identity_links', 'crdr_identity_apart', 'crdr_db_version', 'crdr_schema_error', 'crdr_rebuild_required', 'crdr_retention_state', 'crdr_site_identity',
			'crdr_stats', 'crdr_playbooks', 'crdr_run_log', 'crdr_cron_log', 'crdr_run_log_lost', 'crdr_build_epoch', 'crdr_reject_epochs', 'crdr_run_health'
		);
	}

	/** Did the administrator tick "erase everything" in the settings? */
	public static function erase_requested() {
		$s = get_option( 'crdr_settings', array() );
		return is_array( $s ) && ! empty( $s[ self::FLAG ] );
	}

	/**
	 * @param bool|null $erase_all null = what the setting says
	 * @return array what was done: erase_all, optouts_kept
	 */
	public static function run( $erase_all = null ) {
		global $wpdb;
		$erase = null === $erase_all ? self::erase_requested() : (bool) $erase_all;
		$p     = $wpdb->prefix;

		// scheduled work: nothing of this plugin may run after it is gone
		wp_clear_scheduled_hook( 'crdr_daily' );
		wp_clear_scheduled_hook( 'crdr_sync_step' );
		wp_clear_scheduled_hook( 'crdr_hourly' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'crdr_sync_step' );
		}

		$kept = 0;
		if ( ! $erase ) {
			$k = $p . 'crdr_contacts';
			if ( $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $k ) . "'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$wpdb->query( "DELETE FROM {$k} WHERE kind <> 'optout'" ); // phpcs:ignore WordPress.DB.PreparedSQL
				// Only WHO and WHEN are kept: no text, no staff id, no coupon, no error, no run / claim bookkeeping.
				$wpdb->query( "UPDATE {$k} SET playbook = '', grp = 'treated', coupon = '', message = '', status = 'ok', error = '', user_id = 0, run_id = '', localid = '', claim_key = NULL WHERE kind = 'optout'" ); // phpcs:ignore WordPress.DB.PreparedSQL
				$kept = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$k} WHERE kind = 'optout'" ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			foreach ( array( 'crdr_customers', 'crdr_orders', 'crdr_order_items', 'crdr_log' ) as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$p}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		} else {
			self::scrub_coupons();
			foreach ( array( 'crdr_customers', 'crdr_orders', 'crdr_order_items', 'crdr_contacts', 'crdr_log' ) as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$p}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}

		foreach ( self::options() as $option ) {
			delete_option( $option );
		}
		// transients of this plugin (rejected-form drafts of every user, the campaign lock, the pairs cache) and their timeouts, and the one-time send acknowledgements (CRDR_Site)
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_crdr_' ) . '%', $wpdb->esc_like( '_transient_timeout_crdr_' ) . '%', $wpdb->esc_like( self::ACK_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array( 'erase_all' => $erase, 'optouts_kept' => $kept );
	}

	/** Full erasure: the plugin's coupons stop carrying the customer's phone / e-mail in their description and hidden key. */
	private static function scrub_coupons() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_crdr_ckey' AND p.post_type = 'shop_coupon'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( array_chunk( array_map( 'intval', (array) $ids ), 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			$wpdb->query( "UPDATE {$wpdb->posts} SET post_excerpt = '' WHERE ID IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_crdr_ckey' AND post_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $chunk as $id ) {
				clean_post_cache( $id );
			}
		}
	}
}
