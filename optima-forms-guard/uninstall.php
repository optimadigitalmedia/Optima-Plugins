<?php
/**
 * Optima Forms Guard uninstall: the monthly blocked count.
 *
 * @package Optima_Forms_Guard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'optima_forms_guard_blocked' );
