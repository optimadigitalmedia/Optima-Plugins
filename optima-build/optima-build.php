<?php
/**
 * Plugin Name:       Optima Build
 * Plugin URI:        https://optimadigitalmedia.com
 * Description:       One screen for the site's features: see what is on, turn features on or off (site operators only), roll one back, request a change, and check the site's health at a glance.
 * Version:           0.7.4.5
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * Author URI:        https://optimadigitalmedia.com
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-build
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-build
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prefixed OPTIMA_HUB_ on purpose: the Optima Digital theme already defines a
// OPTIMA_BUILD_MENU constant for its own menu.
define( 'OPTIMA_HUB_VERSION', '0.7.4.5' );
define( 'OPTIMA_HUB_FILE', __FILE__ );
define( 'OPTIMA_HUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPTIMA_HUB_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-build.php', 'optima-build' );
	},
	0
);

require_once OPTIMA_HUB_DIR . 'includes/class-optima-admin-ui.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-access.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-catalog.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-features.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-requests.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-health.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-robots.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-setup.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-admin.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-mail.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-dashboard.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-themes.php';

Optima_Hub_Features::boot();
Optima_Hub_Requests::boot();
Optima_Hub_Admin::boot();
Optima_Hub_Health::boot();
Optima_Hub_Robots::boot();
Optima_Hub_Setup::boot();
Optima_Hub_Mail::boot();
Optima_Hub_Dashboard::boot();
Optima_Hub_Themes::boot();
