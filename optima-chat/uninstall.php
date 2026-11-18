<?php
/**
 * Remove everything on delete: the settings, the daily clean-up job, and every
 * saved conversation with its details (name, email, phone, the transcript).
 * They are visitors' personal details, so they do not outlive the plugin.
 * Export anything the firm must keep from Chat questions before deleting.
 *
 * @package Optima_Chat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'optima_chat' );
wp_unschedule_hook( 'optima_chat_purge' );

// Direct queries: the post type is not registered while uninstalling, and a site may hold thousands.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s", 'optima_chat_q' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_type = %s", 'optima_chat_q' ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery
