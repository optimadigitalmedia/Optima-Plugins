<?php
/**
 * Deleting the plugin removes its settings only. Member accounts and their
 * records stay, because they are the client's people: putting the plugin back
 * brings every membership back as it was. Use the WordPress privacy tools to
 * erase one person's membership record on request.
 *
 * @package Optima_Members
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'optima_members' );
delete_option( 'optima_members_questions' );
delete_option( 'optima_members_next_step' );
