<?php
/**
 * Remove everything this plugin stored: the settings, the check results, the
 * at-launch checklist and the weekly event. The Accessibility page is removed
 * only if it still holds nothing but the generated statement. A page someone
 * has edited is kept, so their words are never lost.
 *
 * @package Optima_Accessibility
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$optima_a11y = (array) get_option( 'optima_accessibility', array() );
$optima_page = isset( $optima_a11y['page_id'] ) ? get_post( (int) $optima_a11y['page_id'] ) : null;
if ( $optima_page instanceof WP_Post && 'page' === $optima_page->post_type ) {
	$optima_generated = "<!-- wp:shortcode -->\n[optima_accessibility_statement]\n<!-- /wp:shortcode -->";
	if ( trim( $optima_page->post_content ) === $optima_generated ) {
		wp_delete_post( $optima_page->ID, true );
	}
}

delete_option( 'optima_accessibility' );
delete_option( 'optima_accessibility_results' );
delete_option( 'optima_accessibility_launch' );
delete_option( 'optima_accessibility_link_seen' );
wp_clear_scheduled_hook( 'optima_accessibility_weekly' );
