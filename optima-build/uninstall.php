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
delete_option( 'optima_hub_update_holds' );
delete_option( 'optima_hub_log' );
delete_option( 'optima_hub_answer_seen' );
delete_option( 'optima_hub_page' );
delete_option( 'optima_hub_hidden_alert_sent' );
wp_clear_scheduled_hook( 'optima_hub_daily_watch' );
delete_transient( 'optima_hub_catalog' );
delete_transient( 'optima_hub_releases' );
delete_transient( 'optima_updater_optima-build' );
