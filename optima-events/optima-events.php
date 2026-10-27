<?php
/**
 * Plugin Name:       Optima Events
 * Description:       Events, sessions and speakers the owner edits in plain forms: event pages with dates, venue, ticket button and what to know, a day-by-day schedule, speaker pages, and Event schema so the event can appear in Google. Works with any ticket link today and is built to sit in front of a WooCommerce ticket engine later.
 * Version:           0.14.1.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-events
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-events
 *
 * @package Optima_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_EVENTS_VERSION', '0.14.1.2' );
define( 'OPTIMA_EVENTS_FILE', __FILE__ );
define( 'OPTIMA_EVENTS_URL', plugin_dir_url( __FILE__ ) );
define( 'OPTIMA_EVENTS_DIR', plugin_dir_path( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-events.php', 'optima-events' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-events.php';
require_once __DIR__ . '/includes/class-optima-events-editor.php';
require_once __DIR__ . '/includes/class-optima-events-front.php';
require_once __DIR__ . '/includes/class-optima-events-schema.php';
require_once __DIR__ . '/includes/class-optima-events-import.php';
require_once __DIR__ . '/includes/class-optima-events-presenters.php';
require_once __DIR__ . '/includes/class-optima-events-rsvp.php';
require_once __DIR__ . '/includes/class-optima-events-calendar.php';
require_once __DIR__ . '/includes/class-optima-events-vendors.php';
require_once __DIR__ . '/includes/class-optima-events-volunteers.php';
require_once __DIR__ . '/includes/class-optima-events-photos.php';
require_once __DIR__ . '/includes/class-optima-events-directory.php';
require_once __DIR__ . '/includes/class-optima-events-regions.php';
require_once __DIR__ . '/includes/class-optima-events-topics.php';
require_once __DIR__ . '/includes/class-optima-events-woo.php';
require_once __DIR__ . '/includes/class-optima-events-rsvp-admin.php';
require_once __DIR__ . '/includes/class-optima-events-admin.php';
require_once __DIR__ . '/includes/functions.php';

Optima_Events::boot();
Optima_Events_Editor::boot();
Optima_Events_Front::boot();
Optima_Events_Schema::boot();
Optima_Events_Admin::boot();
Optima_Events_Presenters::boot();
Optima_Events_RSVP::boot();
Optima_Events_Calendar::boot();
Optima_Events_Vendors::boot();
Optima_Events_Volunteers::boot();
Optima_Events_Photos::boot();
Optima_Events_Directory::boot();
Optima_Events_Regions::boot();
Optima_Events_Topics::boot();
Optima_Events_RSVP_Admin::boot();
// The shop's tickets need WooCommerce, which loads after Optima Events.
add_action( 'plugins_loaded', array( 'Optima_Events_Woo', 'boot' ), 20 );

register_activation_hook(
	__FILE__,
	static function () {
		Optima_Events::register_post_types();
		Optima_Events_Presenters::role();
		Optima_Events_RSVP::install();
		Optima_Events_Volunteers::install();
		flush_rewrite_rules();
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		foreach ( Optima_Events::post_types() as $type ) {
			unregister_post_type( $type );
		}
		Optima_Events_RSVP::unschedule();
		flush_rewrite_rules();
	}
);
