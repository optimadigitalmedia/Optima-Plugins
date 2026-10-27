<?php
/**
 * Plugin Name:       Optima Optimize
 * Plugin URI:        https://optimadigitalmedia.com
 * Description:       Optima-owned media optimization. Compresses, resizes, and reformats images on upload, with batch optimization for existing libraries. Part of the Optima plugin suite.
 * Version:           0.9.1.2
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Optima Digital
 * Author URI:        https://optimadigitalmedia.com
 * License:           Proprietary - Optima client sites only. Not for redistribution.
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-optimize
 * Text Domain:       optima-optimize
 *
 * @package Optima_Optimize
 */

// Direct access hardening.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_OPTIMIZE_VERSION', '0.9.1.2' );
define( 'OPTIMA_OPTIMIZE_FILE', __FILE__ );
define( 'OPTIMA_OPTIMIZE_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPTIMA_OPTIMIZE_URL', plugin_dir_url( __FILE__ ) );

/**
 * The capability that gates every Optima Optimize admin surface.
 *
 * Granted on activation to the administrator role by default; per the vision
 * doc (§3.7) the Stage F hardening pass may narrow this to a Optima-only role
 * so client administrators never see the controls.
 */
define( 'OPTIMA_OPTIMIZE_CAP', 'manage_optima_optimize' );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-optimize.php', 'optima-optimize' );
	},
	0
);

require_once OPTIMA_OPTIMIZE_DIR . 'includes/class-optima-admin-ui.php';
require_once OPTIMA_OPTIMIZE_DIR . 'includes/class-optima-optimize.php';
require_once OPTIMA_OPTIMIZE_DIR . 'includes/class-optima-optimize-speed.php';

register_activation_hook( __FILE__, array( 'Optima_Optimize', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Optima_Optimize', 'deactivate' ) );

/**
 * Boot the plugin after all plugins are loaded (suite-friendly ordering).
 */
add_action( 'plugins_loaded', array( 'Optima_Optimize', 'instance' ) );

// Forms and jQuery stop holding up the first paint (includes/class-optima-optimize-speed.php).
add_action( 'plugins_loaded', array( 'Optima_Optimize_Speed', 'boot' ) );

// The Optima admin look on the optimizer's two screens.
add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( false !== strpos( (string) $hook, 'optima-optimize' ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_OPTIMIZE_URL . 'assets/optima-admin.css' );
		}
	}
);
