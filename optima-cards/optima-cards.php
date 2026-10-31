<?php
/**
 * Plugin Name:       Optima Cards
 * Description:       Digital business cards and a social "link in bio" page, built into the client's own site: a team card, a card per person, Save contact (vCard with photo), share mode with a big QR code, a home-screen icon, and /links/. Reads the business identity from Optima Core; edited in Optima -> Cards & Links.
 * Version:           1.0.21.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-cards
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-cards
 *
 * @package Optima_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_CARDS_VERSION', '1.0.21.4' );
define( 'OPTIMA_CARDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPTIMA_CARDS_URL', plugin_dir_url( __FILE__ ) );
define( 'OPTIMA_CARDS_FILE', __FILE__ );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-cards.php', 'optima-cards' );
	},
	0
);

require_once OPTIMA_CARDS_DIR . 'includes/class-optima-admin-ui.php';
require_once OPTIMA_CARDS_DIR . 'includes/settings.php';
require_once OPTIMA_CARDS_DIR . 'includes/routes.php';
require_once OPTIMA_CARDS_DIR . 'includes/vcard.php';
require_once OPTIMA_CARDS_DIR . 'includes/render.php';
require_once OPTIMA_CARDS_DIR . 'includes/admin.php';

/**
 * True when the active theme already makes the cards and links pages itself
 * (the Optima Digital theme does). Then this plugin stays out of the way: no
 * routes, no menu, nothing that could take over the theme's /card/ and
 * /links/ addresses. Filter `optima_cards_theme_has_cards` to decide.
 */
function optima_cards_theme_has_cards() {
	return (bool) apply_filters( 'optima_cards_theme_has_cards', function_exists( 'optima_cards_admin_page' ) );
}

// After the theme has loaded, so the check above can see it.
add_action(
	'after_setup_theme',
	static function () {
		if ( optima_cards_theme_has_cards() ) {
			add_action( 'admin_notices', 'optima_cards_resting_notice' );
			return;
		}
		Optima_Cards_Routes::init();
		Optima_Cards_Admin::init();
	},
	20
);

/** On the Plugins screen only: say why the plugin is doing nothing. */
function optima_cards_resting_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-info"><p>' . esc_html__( 'This theme already makes the cards and links pages, so Optima Cards is standing down and changes nothing. You can deactivate it.', 'optima-cards' ) . '</p></div>';
}

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! optima_cards_theme_has_cards() ) {
			Optima_Cards_Routes::activate();
		}
	}
);
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
