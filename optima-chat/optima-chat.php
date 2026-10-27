<?php
/**
 * Plugin Name:       Optima Chat
 * Description:       A chat assistant in the corner of the site. Answers the common questions, books calls, takes change requests, and saves every visitor question to an inbox. Gives AI answers once it is connected to an AI server.
 * Version:           0.2.2.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Optima Digital
 * License:           GPL-2.0-or-later
 * Text Domain:       optima-chat
 * Update URI:        https://github.com/optimadigitalmedia/Optima-Plugins/optima-chat
 *
 * @package Optima_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIMA_CHAT_VERSION', '0.2.2.2' );
define( 'OPTIMA_CHAT_FILE', __FILE__ );
define( 'OPTIMA_CHAT_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Optima Plugins releases (see includes/class-optima-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Optima
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-optima-plugin-updater.php';
		Optima_Plugin_Updater::boot( __DIR__ . '/optima-chat.php', 'optima-chat' );
	},
	0
);

require_once __DIR__ . '/includes/class-optima-admin-ui.php';
require_once __DIR__ . '/includes/class-optima-chat.php';
require_once __DIR__ . '/includes/class-optima-chat-admin.php';

Optima_Chat::boot();
Optima_Chat_Admin::boot();
