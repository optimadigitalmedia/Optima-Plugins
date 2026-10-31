<?php
/**
 * Optima Forms Guard uninstall: the monthly blocked count and the hidden upload list.
 *
 * @package Optima_Forms_Guard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'optima_forms_guard_blocked' );
delete_transient( 'optima_forms_guard_hidden_uploads' );
