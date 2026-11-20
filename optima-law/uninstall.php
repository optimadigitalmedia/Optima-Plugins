<?php
/**
 * Remove the settings on delete. The approval records
 * (optima_law_approval_log, optima_law_content_log, and the approval fields on
 * each page or post) stay: they are the firm's advertising record, and bar
 * rules ask firms to keep those. Delete them by hand only when the firm says
 * it no longer needs them. "Reviewed by" records belong to Optima Team and are
 * not touched.
 *
 * @package Optima_Law
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'optima_law_disclaimers' );
delete_option( 'optima_law_approvals' );
delete_option( 'optima_law_reviews' );
delete_option( 'optima_law_entries' );
delete_option( 'optima_law_entries_state' );
delete_option( 'optima_law_entries_log' );
wp_clear_scheduled_hook( 'optima_law_entries_nightly' );
