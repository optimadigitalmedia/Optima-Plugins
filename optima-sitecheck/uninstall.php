<?php
/**
 * Remove everything on delete: the settings, the scheduled jobs, and every
 * saved site check with its details (name, email, the site checked, the
 * report). They are visitors' personal details, so they do not outlive the
 * plugin. Export anything that must be kept from Site checks before deleting.
 *
 * @package Optima_Sitecheck
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'optima_sitecheck' );
wp_unschedule_hook( 'optima_sitecheck_finish' );
wp_unschedule_hook( 'optima_sitecheck_purge' );

// Direct queries: the post type is not registered while uninstalling, and a site may hold thousands.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s", 'optima_sitecheck' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_type = %s", 'optima_sitecheck' ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery
