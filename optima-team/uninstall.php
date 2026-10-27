<?php
/**
 * Optima Team uninstall cleanup. Runs only when the plugin is deleted.
 *
 * Conservative on purpose, like Optima Core and Optima Reviews: profiles are
 * content someone typed in, so they stay unless the site defines
 * OPTIMA_PURGE_ON_UNINSTALL as true in wp-config.php first. The settings
 * always go. Photos always stay in the media library.
 *
 * @package Optima_Team
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'optima_team' );
delete_transient( 'optima_updater_optima-team' );

if ( ! defined( 'OPTIMA_PURGE_ON_UNINSTALL' ) || ! OPTIMA_PURGE_ON_UNINSTALL ) {
	return;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time cleanup on delete.
$optima_team_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'optima_team' ) );
foreach ( $optima_team_ids as $optima_team_id ) {
	wp_delete_post( (int) $optima_team_id, true );
}

// Any profile meta left behind (for example on revisions).
delete_metadata( 'post', 0, '_optima_team', '', true );
