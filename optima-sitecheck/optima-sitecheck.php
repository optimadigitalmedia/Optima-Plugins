<?php
/**
 * Plugin Name:       Optima Site Check
 * Description:       A free website check visitors can run from your site. It checks speed, security, page titles, business info for Google, phone layout, AI crawlers, the sitemap and photo sizes, shows the result on the page, emails the full report, and saves every request to an inbox.
 * Version:           0.2.2.3
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-sitecheck
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-sitecheck
 *
 * @package Optima_Sitecheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_SITECHECK_VERSION', '0.2.2.3' );
define( 'OPTIMA_SITECHECK_FILE', __FILE__ );
define( 'OPTIMA_SITECHECK_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-sitecheck.php', 'optima-sitecheck' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-sitecheck.php';
require_once __DIR__ . '/includes/class-optima-sitecheck-checks.php';
require_once __DIR__ . '/includes/class-optima-sitecheck-report.php';
require_once __DIR__ . '/includes/class-optima-sitecheck-admin.php';

Optima_Sitecheck::boot();
Optima_Sitecheck_Admin::boot();
