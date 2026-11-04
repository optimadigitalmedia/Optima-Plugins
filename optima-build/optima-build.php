<?php
/**
 * Plugin Name:       Optima Build
 * Plugin URI:        https://optimadigitalmedia.com
 * Description:       The Optima menu on every site: an Overview that says whether the site is all right and what needs you, an Inbox with everything waiting on a person, Site health, and for operators the Build screens: features, every update in one place, and the Site log.
 * Version:           0.11.1.8
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
define( 'OPTIMA_HUB_VERSION', '0.11.1.8' );
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
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-owns.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-design.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-access.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-lock.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-catalog.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-features.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-requests.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-health.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-robots.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-privacy.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-optima.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-setup.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-admin.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-mail.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-dashboard.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-themes.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-rings.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-watch.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-log.php';
require_once OPTIMA_HUB_DIR . 'includes/class-optima-hub-log-api.php';

Optima_Hub_Design::boot();
Optima_Hub_Lock::boot();
Optima_Hub_Features::boot();
Optima_Hub_Requests::boot();
Optima_Hub_Admin::boot();
Optima_Hub_Health::boot();
Optima_Hub_Robots::boot();
Optima_Hub_Privacy::boot();
Optima_Hub_Optima::boot();
Optima_Hub_Setup::boot();
Optima_Hub_Mail::boot();
Optima_Hub_Dashboard::boot();
Optima_Hub_Themes::boot();
Optima_Hub_Rings::boot();
Optima_Hub_Watch::boot();
Optima_Hub_Log::boot();
Optima_Hub_Log_API::boot();

register_deactivation_hook( __FILE__, array( 'Optima_Hub_Watch', 'unschedule' ) );
