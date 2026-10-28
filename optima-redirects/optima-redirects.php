<?php
/**
 * Plugin Name:       Optima Redirects
 * Description:       Forward old page addresses to new ones, see which redirects get used, and catch missing pages people ask for so each one can become a redirect in one click.
 * Version:           0.1.6.3
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-redirects
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-redirects
 *
 * @package Optima_Redirects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_REDIRECTS_VERSION', '0.1.6.3' );
define( 'OPTIMA_REDIRECTS_FILE', __FILE__ );
define( 'OPTIMA_REDIRECTS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-redirects.php', 'optima-redirects' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-redirects-store.php';
require_once __DIR__ . '/includes/class-optima-redirects.php';
require_once __DIR__ . '/includes/class-optima-redirects-admin.php';

register_activation_hook( __FILE__, array( 'Optima_Redirects_Store', 'install' ) );

Optima_Redirects::boot();
Optima_Redirects_Admin::boot();
