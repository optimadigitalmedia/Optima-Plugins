<?php
/**
 * Plugin Name:       Optima Law Firm Tools
 * Description:       The pieces a law firm's website needs to stay careful: the firm's disclaimer lines set once and shown everywhere they belong, approval before publishing for chosen pages and posts, with every approval kept as the firm's advertising record, a list of pages due for an attorney's review, and (off until it is decided) form entries that delete themselves after 90 days.
 * Version:           0.4.5.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-law
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-law
 *
 * @package Optima_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_LAW_VERSION', '0.4.5.0' );
define( 'OPTIMA_LAW_FILE', __FILE__ );
define( 'OPTIMA_LAW_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-law.php', 'optima-law' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-law-disclaimers.php';
require_once __DIR__ . '/includes/class-optima-law-front.php';
require_once __DIR__ . '/includes/class-optima-law-admin.php';
require_once __DIR__ . '/includes/class-optima-law-approvals.php';
require_once __DIR__ . '/includes/class-optima-law-approvals-admin.php';
require_once __DIR__ . '/includes/class-optima-law-reviews.php';
require_once __DIR__ . '/includes/class-optima-law-reviews-admin.php';
require_once __DIR__ . '/includes/class-optima-law-entries.php';
require_once __DIR__ . '/includes/class-optima-law-entries-admin.php';
require_once __DIR__ . '/includes/class-optima-law-inbox.php';

Optima_Law_Front::boot();
Optima_Law_Admin::boot();
Optima_Law_Approvals::boot();
Optima_Law_Approvals_Admin::boot();
Optima_Law_Reviews::boot();
Optima_Law_Reviews_Admin::boot();
Optima_Law_Entries::boot();
Optima_Law_Entries_Admin::boot();

register_deactivation_hook( __FILE__, array( 'Optima_Law_Entries', 'unschedule' ) );
