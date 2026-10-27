<?php
/**
 * Remove the settings on delete. Questions and answers stay on their pages,
 * because they are the client's content.
 *
 * @package Optima_AEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'optima_aeo' );
delete_option( 'optima_aeo_indexnow_key' );
