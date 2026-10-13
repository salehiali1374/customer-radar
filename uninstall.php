<?php
/**
 * Runs when the plugin is deleted from the Plugins screen. The rules (what stays, what goes, and how to erase everything on purpose)
 * are in includes/class-crdr-uninstall.php and docs/data-retention.md.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-crdr-uninstall.php';
CRDR_Uninstall::run();
