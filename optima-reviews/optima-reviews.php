<?php
/**
 * Plugin Name:       Optima Reviews
 * Plugin URI:        https://optimadigitalmedia.com
 * Description:       Reviews from Google, Facebook, Yelp, Clutch, and anywhere else, kept in one place and shown on the site in a custom design. Every review links back to where it was posted, and the rating summary uses the real totals from each platform.
 * Version:           0.5.5.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * Author URI:        https://optimadigitalmedia.com
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-reviews
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-reviews
 *
 * @package Optima_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_REVIEWS_VERSION', '0.5.5.4' );
define( 'OPTIMA_REVIEWS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPTIMA_REVIEWS_URL', plugin_dir_url( __FILE__ ) );
define( 'OPTIMA_REVIEWS_FILE', __FILE__ );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-reviews.php', 'optima-reviews' );
	},
	0
);

require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-admin-ui.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-sources.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-post-type.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-settings.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-query.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-render.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-google.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-google-sync.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-google-admin.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-topics.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-inbox.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-brand.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-requests.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-notes.php';
require_once OPTIMA_REVIEWS_DIR . 'includes/class-optima-reviews-menu.php';

add_action( 'init', array( 'Optima_Reviews_Post_Type', 'register' ) );
add_action( 'init', array( 'Optima_Reviews_Render', 'register' ) );
add_action( 'admin_init', array( 'Optima_Reviews_Settings', 'register' ) );
add_action( 'admin_menu', array( 'Optima_Reviews_Settings', 'menu' ), Optima_Reviews_Menu::PRIORITY + 3 );

Optima_Reviews_Google_Sync::boot();
Optima_Reviews_Topics::boot();
Optima_Reviews_Requests::boot();
Optima_Reviews_Notes::boot();

if ( is_admin() ) {
	Optima_Reviews_Menu::boot();
	Optima_Reviews_Post_Type::admin_hooks();
	Optima_Reviews_Google_Admin::boot();
	Optima_Reviews_Inbox::boot();
}

// The daily Google sync stops with the plugin and resumes when it comes back.
register_deactivation_hook( __FILE__, array( 'Optima_Reviews_Google_Sync', 'unschedule' ) );
register_activation_hook(
	__FILE__,
	static function () {
		if ( Optima_Reviews_Google::is_ready() ) {
			Optima_Reviews_Google_Sync::schedule();
		}
		delete_option( Optima_Reviews_Requests::ROUTES ); // Re-add the /review/ address on the next load.
	}
);

// The Optima admin look on the Review settings screen.
add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( false !== strpos( (string) $hook, Optima_Reviews_Settings::PAGE ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_REVIEWS_URL . 'assets/optima-admin.css' );
		}
	}
);
