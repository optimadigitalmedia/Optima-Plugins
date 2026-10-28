<?php
/**
 * Plugin Name:       Optima Analytics
 * Description:       Google Tag Manager (or a GA4 tag) on the live site with Google consent mode set up first, a small cookie notice that lets visitors opt out, and one way for every Optima feature to report clicks.
 * Version:           0.1.13.3
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-analytics
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-analytics
 *
 * @package Optima_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_ANALYTICS_VERSION', '0.1.13.3' );
define( 'OPTIMA_ANALYTICS_FILE', __FILE__ );
define( 'OPTIMA_ANALYTICS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-analytics.php', 'optima-analytics' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-analytics.php';
require_once __DIR__ . '/includes/class-optima-analytics-admin.php';

Optima_Analytics::boot();
Optima_Analytics_Admin::boot();
