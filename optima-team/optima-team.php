<?php
/**
 * Plugin Name:       Optima Team
 * Description:       Team profiles with real credentials: role, photo, short bio, bar admissions, education, memberships, awards, military service, languages and profile links. Each person gets a profile page and Person schema, and a grid shortcode shows the whole team.
 * Version:           0.1.9.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-team
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-team
 *
 * @package Optima_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_TEAM_VERSION', '0.1.9.4' );
define( 'OPTIMA_TEAM_FILE', __FILE__ );
define( 'OPTIMA_TEAM_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-team.php', 'optima-team' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-team.php';
require_once __DIR__ . '/includes/class-optima-team-editor.php';
require_once __DIR__ . '/includes/class-optima-team-front.php';
require_once __DIR__ . '/includes/class-optima-team-schema.php';
require_once __DIR__ . '/includes/class-optima-team-admin.php';

Optima_Team::boot();
Optima_Team_Editor::boot();
Optima_Team_Front::boot();
Optima_Team_Schema::boot();
Optima_Team_Admin::boot();

register_activation_hook(
	__FILE__,
	static function () {
		Optima_Team::register_post_type();
		flush_rewrite_rules();
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		unregister_post_type( Optima_Team::POST_TYPE );
		flush_rewrite_rules();
	}
);
