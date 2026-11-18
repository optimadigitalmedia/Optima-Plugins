<?php
/**
 * Optima Cards uninstall cleanup. Runs only when the plugin is deleted.
 *
 * Conservative on purpose, like Optima Core and Optima Team: the card and
 * link page settings are content someone typed in (people, links, wording),
 * so they stay unless a full wipe was asked for first: the
 * "purge_on_uninstall" key in the optima_cards_settings setting, or
 * OPTIMA_PURGE_ON_UNINSTALL defined as true in wp-config.php. Derived data
 * (the address stamp and the cached release lookup) always goes. Business
 * facts live on Your business, Details (Optima Core) and are never touched
 * here, and photos stay in the media library.
 *
 * @package Optima_Cards
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Always safe to drop: rebuilt on the next activation and update check.
delete_option( 'optima_cards_routes' );
delete_transient( 'optima_updater_optima-cards' );
delete_transient( 'optima_updater_optima-cards__test' );

$optima_cards_settings = get_option( 'optima_cards_settings', array() );
$optima_cards_purge    = ( is_array( $optima_cards_settings ) && ! empty( $optima_cards_settings['purge_on_uninstall'] ) )
	|| ( defined( 'OPTIMA_PURGE_ON_UNINSTALL' ) && OPTIMA_PURGE_ON_UNINSTALL );

if ( ! $optima_cards_purge ) {
	return; // Default: the cards come back as they were if the plugin is put back.
}

delete_option( 'optima_cards_settings' );
delete_option( 'optima_cards_r3' );
