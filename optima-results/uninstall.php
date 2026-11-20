<?php
/**
 * Optima Results uninstall cleanup. Runs only when the plugin is deleted.
 *
 * Conservative on purpose, like Optima Core and Optima Reviews: case results
 * are content the firm approved, so they and their practice areas stay
 * unless a full wipe was asked for first: OPTIMA_PURGE_ON_UNINSTALL defined as
 * true in wp-config.php, or the "purge_on_uninstall" key in the optima_results
 * setting. The setting stays too without a full wipe: it holds the
 * disclaimer wording the firm approved and its approval record (who, when and
 * for which wording), which is the firm's bar-compliance record and must not
 * disappear because the plugin was deleted. The cached release lookup always
 * goes.
 *
 * @package Optima_Results
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_transient( 'optima_updater_optima-results' );
delete_transient( 'optima_updater_optima-results__test' );

$optima_results_settings = get_option( 'optima_results', array() );
$optima_results_purge    = ( is_array( $optima_results_settings ) && ! empty( $optima_results_settings['purge_on_uninstall'] ) )
	|| ( defined( 'OPTIMA_PURGE_ON_UNINSTALL' ) && OPTIMA_PURGE_ON_UNINSTALL );

if ( ! $optima_results_purge ) {
	return; // Default: the results, the approved disclaimer and its approval come back as they were.
}

delete_option( 'optima_results' );

$optima_results_ids = get_posts(
	array(
		'post_type'      => 'optima_result',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);
foreach ( $optima_results_ids as $optima_results_id ) {
	wp_delete_post( (int) $optima_results_id, true ); // Also removes its meta and revisions.
}

// The taxonomy is not registered while uninstalling, so register it briefly to delete its terms.
register_taxonomy( 'optima_result_area', 'optima_result' );
$optima_results_terms = get_terms(
	array(
		'taxonomy'   => 'optima_result_area',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
if ( ! is_wp_error( $optima_results_terms ) ) {
	foreach ( $optima_results_terms as $optima_results_term ) {
		wp_delete_term( (int) $optima_results_term, 'optima_result_area' );
	}
}
