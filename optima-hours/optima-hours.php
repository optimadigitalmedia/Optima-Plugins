<?php
/**
 * Plugin Name:       Optima Hours
 * Description:       Weekly hours and holiday closures in one place: an hours table and a live "Open now" line for the site, opening hours in the schema Google reads, an optional notice bar on closure days, and a reminder to set the same hours on Google.
 * Version:           0.1.6.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-hours
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-hours
 *
 * @package Optima_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_HOURS_VERSION', '0.1.6.1' );
define( 'OPTIMA_HOURS_FILE', __FILE__ );
define( 'OPTIMA_HOURS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-hours.php', 'optima-hours' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-hours.php';
require_once __DIR__ . '/includes/class-optima-hours-schema.php';
require_once __DIR__ . '/includes/class-optima-hours-front.php';
require_once __DIR__ . '/includes/class-optima-hours-admin.php';
require_once __DIR__ . '/includes/functions.php';

Optima_Hours_Schema::boot();
Optima_Hours_Front::boot();
Optima_Hours_Admin::boot();
