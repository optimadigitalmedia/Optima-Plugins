<?php
/**
 * Plugin Name:       Optima AI Search
 * Description:       Gets the site ready for AI search (ChatGPT, Google AI answers, Perplexity, Claude): lets the AI search crawlers in, serves a plain-text guide at /llms.txt, and adds question-and-answer sections with matching FAQ schema to any page.
 * Version:           0.2.2.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-aeo
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-aeo
 *
 * @package Optima_AEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_AEO_VERSION', '0.2.2.1' );
define( 'OPTIMA_AEO_FILE', __FILE__ );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-aeo.php', 'optima-aeo' );
	},
	0
);

require_once plugin_dir_path( __FILE__ ) . 'includes/class-optima-admin-ui.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-optima-aeo.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-optima-aeo-faq.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-optima-aeo-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-optima-aeo-indexnow.php';

Optima_AEO::boot();
Optima_AEO_FAQ::boot();
Optima_AEO_Admin::boot();
Optima_AEO_IndexNow::boot();
