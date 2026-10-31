<?php
/**
 * Plugin Name:       Optima Partners
 * Description:       Partners and sponsors by level (such as Founding, National, Regional), each with a logo, a link, the regions they back and the places on the site they appear. Change a partner once and every placement follows, with a count of clicks each month to show them.
 * Version:           0.1.2.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-partners
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-partners
 *
 * @package Optima_Partners
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_PARTNERS_VERSION', '0.1.2.4' );
define( 'OPTIMA_PARTNERS_FILE', __FILE__ );
define( 'OPTIMA_PARTNERS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-partners.php', 'optima-partners' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-partners.php';
require_once __DIR__ . '/includes/class-optima-partners-front.php';
require_once __DIR__ . '/includes/class-optima-partners-admin.php';

Optima_Partners::boot();
Optima_Partners_Front::boot();
Optima_Partners_Admin::boot();
