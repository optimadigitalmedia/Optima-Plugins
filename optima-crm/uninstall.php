<?php
/**
 * Optima CRM uninstall cleanup. Runs only when the plugin is deleted.
 *
 * Optima CRM keeps no records of its own: every contact, tag and status it
 * wrote lives in FluentCRM, which owns them, so deleting this plugin never
 * touches them (purge setting or not; remove contacts in FluentCRM itself).
 * What it does keep, the backfill result notes and the cached release lookup,
 * is derived data and always goes.
 *
 * @package Optima_CRM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_transient( 'optima_updater_optima-crm' );
delete_transient( 'optima_updater_optima-crm__test' );

// The per-user "backfill finished" notes (ten-minute transients).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time cleanup on delete.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_optima_crm_backfill_' ) . '%', $wpdb->esc_like( '_transient_timeout_optima_crm_backfill_' ) . '%' ) );
