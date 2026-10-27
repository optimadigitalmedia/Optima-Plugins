<?php
/**
 * Plugin Name:       Optima Members
 * Description:       A membership the owner runs from the dashboard: people apply on the site, the owner approves each one, and members sign in to see members-only pages and events, and post invitations to each other. Works alongside Optima Events, which marks events as members only. Card billing for dues is planned for a later version.
 * Version:           0.3.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-members
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-members
 *
 * @package Optima_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_MEMBERS_VERSION', '0.3.1' );
define( 'OPTIMA_MEMBERS_FILE', __FILE__ );
define( 'OPTIMA_MEMBERS_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-members.php', 'optima-members' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-members.php';
require_once __DIR__ . '/includes/class-optima-members-form.php';
require_once __DIR__ . '/includes/class-optima-members-import.php';
require_once __DIR__ . '/includes/class-optima-members-front.php';
require_once __DIR__ . '/includes/class-optima-members-admin.php';
require_once __DIR__ . '/includes/class-optima-members-events.php';
require_once __DIR__ . '/includes/class-optima-members-invitations.php';

Optima_Members::boot();
Optima_Members_Front::boot();
Optima_Members_Admin::boot();
Optima_Members_Invitations::boot();
// Members-only events need Optima Events, which may load after this plugin.
add_action( 'plugins_loaded', array( 'Optima_Members_Events', 'boot' ), 20 );

register_activation_hook(
	__FILE__,
	static function () {
		Optima_Members::role();
	}
);
