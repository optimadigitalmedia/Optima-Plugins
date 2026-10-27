<?php
/**
 * Remove the settings on delete. Saved chat questions are kept: they are the
 * client's records of what visitors asked. Delete them from Chat questions
 * first if they should go too.
 *
 * @package Optima_Chat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'optima_chat' );
