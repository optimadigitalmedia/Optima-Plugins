<?php
/**
 * Plugin Name:       Optima Call Buttons
 * Description:       A call, text and "Let's talk" bar pinned to the bottom of the screen on phones, with a sheet that offers calling, texting or booking a time.
 * Version:           0.1.9.3
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-contact
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-contact
 *
 * @package Optima_Contact
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_CONTACT_VERSION', '0.1.9.3' );
define( 'OPTIMA_CONTACT_FILE', __FILE__ );
define( 'OPTIMA_CONTACT_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-contact.php', 'optima-contact' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-contact.php';
require_once __DIR__ . '/includes/class-optima-contact-admin.php';

Optima_Contact::boot();
Optima_Contact_Admin::boot();
