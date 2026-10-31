<?php
/**
 * Optima Reviews uninstall cleanup. Runs only when the plugin is deleted from
 * wp-admin, never on deactivate.
 *
 * Conservative on purpose, like Optima Core: reviews are content someone typed
 * in by hand, so they stay unless "Remove all reviews and settings when the
 * plugin is deleted" was turned on first.
 *
 * @package Optima_Reviews
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Always safe to drop: the cached release lookup is rebuilt on the next check.
delete_transient( 'optima_updater_optima-reviews' );

// The Google sign-in is a credential, never content: it always goes.
wp_clear_scheduled_hook( 'optima_reviews_google_sync' );
delete_transient( 'optima_reviews_google_token' );
delete_option( 'optima_reviews_google' );
delete_option( 'optima_reviews_request_routes' );
delete_option( 'optima_reviews_build_parent' );

$optima_reviews_settings = get_option( 'optima_reviews_settings', array() );
if ( ! is_array( $optima_reviews_settings ) || empty( $optima_reviews_settings['purge_on_uninstall'] ) ) {
	return;
}

delete_option( 'optima_reviews_requests' );
delete_option( 'optima_reviews_request_stats' );

$optima_reviews_ids = get_posts(
	array(
		'post_type'      => 'optima_review',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $optima_reviews_ids as $optima_reviews_id ) {
	wp_delete_post( $optima_reviews_id, true );
}

// The plugin is not loaded during uninstall, so the taxonomy must be registered to be queried.
register_taxonomy( 'optima_review_topic', 'optima_review' );
$optima_reviews_terms = get_terms( array( 'taxonomy' => 'optima_review_topic', 'hide_empty' => false, 'fields' => 'ids' ) );
if ( is_array( $optima_reviews_terms ) ) {
	foreach ( $optima_reviews_terms as $optima_reviews_term ) {
		wp_delete_term( (int) $optima_reviews_term, 'optima_review_topic' );
	}
}

delete_option( 'optima_reviews_settings' );
delete_option( 'optima_reviews_totals_updated' );
delete_option( 'optima_reviews_seen_slots' );
