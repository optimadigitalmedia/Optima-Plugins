<?php
/**
 * Optima Events uninstall cleanup. Runs only when the plugin is deleted.
 *
 * Deleting the plugin always removes its settings, its reminder job and its
 * cached lookups. Events, sessions, speakers and vendors are the
 * client's content and always stay in the database, so putting the plugin
 * back brings every page back as it was.
 *
 * The sign-up (RSVP) and volunteer tables hold visitors' names, emails and
 * phone numbers. They are dropped too when a full wipe was asked for first:
 * the "purge_on_uninstall" key in the optima_events setting, or
 * OPTIMA_PURGE_ON_UNINSTALL defined as true in wp-config.php (the suite's
 * switch, as in Optima Team and Optima Results). Otherwise they stay, like
 * the events they belong to. Export anything that must be kept from the
 * sign-up and volunteer screens before deleting.
 *
 * @package Optima_Events
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$optima_events_settings = get_option( 'optima_events', array() );
$optima_events_purge    = ( is_array( $optima_events_settings ) && ! empty( $optima_events_settings['purge_on_uninstall'] ) )
	|| ( defined( 'OPTIMA_PURGE_ON_UNINSTALL' ) && OPTIMA_PURGE_ON_UNINSTALL );

// Always: settings, the hourly reminder job and derived caches.
delete_option( 'optima_events' );
delete_option( 'optima_events_rewrite' );
wp_clear_scheduled_hook( 'optima_events_reminders' );
delete_transient( 'optima_updater_optima-events' );
delete_transient( 'optima_updater_optima-events__test' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time cleanup on delete; the import reports are per-user transients.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_optima_events_import_' ) . '%', $wpdb->esc_like( '_transient_timeout_optima_events_import_' ) . '%' ) );

if ( ! $optima_events_purge ) {
	return; // Default: sign-ups and volunteers stay with their events.
}

// The visitor tables, and the version stamps that would recreate them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own tables on delete.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optima_event_signups" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optima_event_volunteers" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the prefix.
// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option( 'optima_events_db' );
delete_option( 'optima_events_volunteers_db' );
