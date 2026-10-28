<?php
/**
 * Plugin Name:       Optima Case Results
 * Description:       A law firm's case results with the firm's approval on every one and the state disclaimer printed next to every result, shown in a grid visitors can filter by practice area.
 * Version:           0.1.7.3
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-results
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-results
 *
 * @package Optima_Results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_RESULTS_VERSION', '0.1.7.3' );
define( 'OPTIMA_RESULTS_FILE', __FILE__ );
define( 'OPTIMA_RESULTS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-results.php', 'optima-results' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-results.php';
require_once __DIR__ . '/includes/class-optima-results-editor.php';
require_once __DIR__ . '/includes/class-optima-results-render.php';
require_once __DIR__ . '/includes/class-optima-results-admin.php';

Optima_Results::boot();
Optima_Results_Editor::boot();
Optima_Results_Render::boot();
Optima_Results_Admin::boot();
