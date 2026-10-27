<?php
/**
 * Plugin Name:       Optima Accessibility
 * Description:       A plain, honest accessibility statement page, a footer link to it, and weekly checks for missing image descriptions, skipped headings and unlabeled form fields, plus an at-launch checklist for the checks that need a person.
 * Version:           0.1.10.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-accessibility
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-accessibility
 *
 * @package Optima_Accessibility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_ACCESSIBILITY_VERSION', '0.1.10.2' );
define( 'OPTIMA_ACCESSIBILITY_FILE', __FILE__ );
define( 'OPTIMA_ACCESSIBILITY_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-accessibility.php', 'optima-accessibility' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-accessibility.php';
require_once __DIR__ . '/includes/class-optima-accessibility-checks.php';
require_once __DIR__ . '/includes/class-optima-accessibility-admin.php';

register_activation_hook( __FILE__, array( 'Optima_Accessibility_Checks', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'Optima_Accessibility_Checks', 'unschedule' ) );

Optima_Accessibility::boot();
Optima_Accessibility_Checks::boot();
Optima_Accessibility_Admin::boot();
