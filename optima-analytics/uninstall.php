<?php
/**
 * Remove the settings on delete. Visitors' saved choices live in their own
 * browsers and expire by themselves.
 *
 * @package Optima_Analytics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'optima_analytics' );
