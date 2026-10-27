<?php
/**
 * Uninstall routine for Optima Optimize.
 *
 * Removes plugin options and the gate capability. DELIBERATELY does not touch
 * any media files: optimized images, WebP twins, and preserved originals all
 * remain on disk. Destroying media on uninstall would violate the project's
 * one-way-door invariant (vision doc §3.4) — a site owner uninstalling the
 * plugin must never lose images.
 *
 * @package Optima_Optimize
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'optima_optimize_version' );
delete_option( 'optima_optimize_settings' );

$optima_optimize_role = get_role( 'administrator' );
if ( $optima_optimize_role ) {
	$optima_optimize_role->remove_cap( 'manage_optima_optimize' );
}
