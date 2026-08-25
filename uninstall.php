<?php
/**
 * Uninstall routine: removes the plugin's settings option only.
 *
 * Physical robots.txt / llms.txt files (and their backups under
 * uploads/presshangar-ai-citations-backups/) are deliberately left untouched on
 * uninstall — they are real, user-visible site files the site owner
 * explicitly chose to create, not plugin-private data.
 *
 * @package PressHangar AI Citations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress defines WP_UNINSTALL_PLUGIN when this file is loaded as part of
// a proper plugin uninstall; bail if accessed any other way.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'phcite_settings' );
delete_option( 'phcite_indexnow_queue' );
delete_option( 'phcite_indexnow_log' );

if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'phcite_indexnow_flush' );
}

// Known fixed-key transients this plugin sets (per-user transients like
// phcite_manual_copy_{user_id} / phcite_admin_error_{user_id} are intentionally
// left alone here — they're short-lived (5-10 minutes) and enumerating
// them would require a direct wp_options LIKE query, which isn't worth it
// for data this transient).
delete_transient( 'phcite_robots_sync_error' );
delete_transient( 'phcite_robots_live_preview' );
delete_transient( 'phcite_robots_seed_fallback' );
delete_transient( 'phcite_robots_virtual_malformed' );
delete_transient( 'phcite_llms_sync_error' );
delete_transient( 'phcite_schema_skip_notice' );
