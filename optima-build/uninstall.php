<?php
/**
 * Optima Build uninstall: settings and caches only. Change requests are the
 * client's own record, so they stay.
 *
 * @package Optima_Build
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'optima_hub_plan' );
delete_option( 'optima_hub_channel' );
delete_option( 'optima_hub_update_holds' );
delete_option( 'optima_hub_log' );
delete_option( 'optima_hub_answer_seen' );
delete_option( 'optima_hub_page' );
delete_option( 'optima_hub_hidden_alert_sent' );
delete_option( 'optima_hub_secret_prints' );
delete_option( 'optima_hub_security_since' );
delete_option( 'optima_hub_wp_health' );
delete_option( 'optima_hub_form_mail' );
delete_option( 'optima_hub_form_test' );
delete_option( 'optima_hub_form_test_state' );
delete_option( 'optima_hub_form_test_lock' );
delete_metadata( 'user', 0, 'optima_hub_last_login', '', true );
wp_clear_scheduled_hook( 'optima_hub_daily_watch' );
wp_clear_scheduled_hook( 'optima_hub_form_test_run' );
wp_clear_scheduled_hook( 'optima_hub_wp_health_run' );
wp_clear_scheduled_hook( 'optima_hub_security_headers' );
delete_transient( 'optima_hub_security_headers' );
delete_transient( 'optima_hub_answer_scan_v3' );
delete_transient( 'optima_hub_catalog' );
delete_transient( 'optima_hub_catalog__test' );
delete_transient( 'optima_hub_releases' );
delete_transient( 'optima_hub_releases__test' );
delete_transient( 'optima_updater_optima-build' );
delete_transient( 'optima_updater_optima-build__test' );
