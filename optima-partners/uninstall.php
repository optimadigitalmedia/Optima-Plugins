<?php
/**
 * Optima Partners uninstall cleanup. Runs only when the plugin is deleted.
 *
 * Conservative on purpose, like Optima Core and Optima Team: partners are
 * content someone typed in, so they (with their levels, links, dates and
 * click counts) and the level and placement lists stay unless a full wipe was
 * asked for first: the "purge_on_uninstall" key in the optima_partners
 * setting, or OPTIMA_PURGE_ON_UNINSTALL defined as true in wp-config.php.
 * The cached release lookup always goes. Logos stay in the media library.
 *
 * @package Optima_Partners
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_transient( 'optima_updater_optima-partners' );
delete_transient( 'optima_updater_optima-partners__test' );

$optima_partners_settings = get_option( 'optima_partners', array() );
$optima_partners_purge    = ( is_array( $optima_partners_settings ) && ! empty( $optima_partners_settings['purge_on_uninstall'] ) )
	|| ( defined( 'OPTIMA_PURGE_ON_UNINSTALL' ) && OPTIMA_PURGE_ON_UNINSTALL );

if ( ! $optima_partners_purge ) {
	return; // Default: the partners come back as they were if the plugin is put back.
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time cleanup on delete; the post type is not registered while uninstalling.
$optima_partners_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'optima_partner' ) );
foreach ( $optima_partners_ids as $optima_partners_id ) {
	wp_delete_post( (int) $optima_partners_id, true );
}

// Any partner meta left behind (for example on revisions).
delete_metadata( 'post', 0, '_optima_partner', '', true );
delete_metadata( 'post', 0, '_optima_partner_clicks', '', true );
delete_option( 'optima_partners' );
