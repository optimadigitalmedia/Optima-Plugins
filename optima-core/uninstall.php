<?php
/**
 * Optima Core uninstall cleanup. Runs only when the plugin is deleted from
 * wp-admin, never on deactivate.
 *
 * Deliberately conservative. Deleting a plugin is routinely how someone
 * troubleshoots, migrates a site, or re-installs a broken copy, and none of
 * those should cost the client anything. Two kinds of data are at stake:
 *
 * - The business identity (`optima_business`): the NAP, hours, geo, and profile
 *   URLs. Typed in once, read by the schema graph and the head tags everywhere.
 * - The per-page SEO overrides (`_optima_seo_*`): every custom title, meta
 *   description, canonical, and noindex flag on the site.
 *
 * Losing either is invisible after the fact. The pages still exist, they just
 * quietly stop saying what they were set up to say, which is worse than an
 * obvious failure. So both are kept unless someone explicitly asks for a full
 * wipe by turning on the "remove all Optima data on delete" setting first.
 *
 * @package Optima_Core
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Always safe to drop: the cached release lookup is derived data, rebuilt on
// the next update check.
delete_transient( 'optima_updater_optima-core' );

$optima_core_settings = get_option( 'optima_seo_defaults', array() );
$optima_core_purge    = is_array( $optima_core_settings ) && ! empty( $optima_core_settings['purge_on_uninstall'] );

if ( ! $optima_core_purge ) {
	return; // Default: the client's identity and SEO work stay put.
}

delete_option( 'optima_business' );
delete_option( 'optima_seo_defaults' );
delete_option( 'optima_core_protection' );
delete_option( 'optima_seo_sitemap_found' );
wp_clear_scheduled_hook( 'optima_seo_sitemap_check' );

foreach ( array( '_optima_seo_title', '_optima_seo_desc', '_optima_seo_canonical', '_optima_seo_noindex' ) as $optima_core_meta_key ) {
	delete_post_meta_by_key( $optima_core_meta_key );
}

/*
 * Content types and their posts are never touched, purge or not. Deleting the
 * plugin must never delete the client's content.
 */
