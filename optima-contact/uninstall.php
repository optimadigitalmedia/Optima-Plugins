<?php
/**
 * Remove the settings on delete.
 *
 * @package Optima_Contact
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'optima_contact' );
