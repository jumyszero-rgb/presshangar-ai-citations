<?php
/**
 * Plugin Name:       PressHangar AI Citations
 * Plugin URI:        https://presshangar.com/presshangar-ai-citations
 * Description:       Helps AI assistants like ChatGPT, Gemini, and Perplexity find, crawl, and cite your content — AI crawler controls, FAQ schema, and llms.txt in one place, by PressHangar.
 * Version:           0.3.6
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Musubiemu LLC
 * Author URI:        https://presshangar.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       presshangar-ai-citations
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'PHCITE_VERSION', '0.3.6' );


/* Load translations: bundled /languages first, then WordPress.org language packs. */
add_action( 'init', function () {
	load_plugin_textdomain( 'presshangar-ai-citations', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 0 );
/** Absolute path to the main plugin file. */
define( 'PHCITE_PLUGIN_FILE', __FILE__ );

/** Absolute path to the plugin directory, with trailing slash. */
define( 'PHCITE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** URL to the plugin directory, with trailing slash. */
define( 'PHCITE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Plugin basename, used for activation hooks and the settings link. */
define( 'PHCITE_BASENAME', plugin_basename( __FILE__ ) );

/** Option name holding the single settings array. */
define( 'PHCITE_OPTION_SETTINGS', 'phcite_settings' );

require_once PHCITE_PLUGIN_DIR . 'includes/class-phcite-filewriter.php';
require_once PHCITE_PLUGIN_DIR . 'includes/class-phcite-settings.php';
require_once PHCITE_PLUGIN_DIR . 'includes/class-phcite-robots.php';
require_once PHCITE_PLUGIN_DIR . 'includes/class-phcite-schema.php';
require_once PHCITE_PLUGIN_DIR . 'includes/class-phcite-llms-txt.php';
require_once PHCITE_PLUGIN_DIR . 'includes/class-phcite-indexnow.php';
require_once PHCITE_PLUGIN_DIR . 'includes/class-phcite-admin.php';

PHCITE_Settings::init();
PHCITE_Robots::init();
PHCITE_Schema::init();
PHCITE_Indexnow::init();
PHCITE_Admin::init();

/**
 * Plugin activation callback.
 *
 * Saves default settings without overwriting any existing settings. Never
 * touches robots.txt or llms.txt on activation — physical files are only
 * ever created through an explicit admin action.
 */
function phcite_activate() {
	if ( false === get_option( PHCITE_OPTION_SETTINGS ) ) {
		update_option( PHCITE_OPTION_SETTINGS, PHCITE_Settings::get_defaults() );
	}
}
register_activation_hook( PHCITE_PLUGIN_FILE, 'phcite_activate' );

/**
 * Plugin deactivation callback.
 *
 * Clears the pending IndexNow flush cron event so it doesn't fire (and
 * potentially error) after the plugin's classes are no longer loaded.
 * Settings, the queue, and the log are intentionally left in place —
 * they're only removed on uninstall.
 */
function phcite_deactivate() {
	wp_clear_scheduled_hook( 'phcite_indexnow_flush' );
}
register_deactivation_hook( PHCITE_PLUGIN_FILE, 'phcite_deactivate' );
