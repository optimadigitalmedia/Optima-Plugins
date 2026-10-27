<?php
/**
 * Template helpers for themes and other Optima features.
 *
 * @package Optima_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'optima_hours_html' ) ) {
	/**
	 * The hours block as HTML, the same as the [optima_hours] shortcode.
	 *
	 *   <?php echo optima_hours_html(); ?>
	 *   <?php echo optima_hours_html( array( 'show' => 'status' ) ); ?>
	 *
	 * Already escaped. Empty until the hours are confirmed.
	 *
	 * @param array{show?:string} $args show: 'both' (default), 'table' or 'status'.
	 */
	function optima_hours_html( array $args = array() ): string {
		return Optima_Hours_Front::html( $args );
	}
}

if ( ! function_exists( 'optima_hours_status' ) ) {
	/**
	 * The live status for other features (a chat answer, a header badge).
	 * Null until the hours are confirmed.
	 *
	 * @return array{open:bool, text:string}|null
	 */
	function optima_hours_status(): ?array {
		return Optima_Hours::is_public() && Optima_Hours::has_hours() ? Optima_Hours::status() : null;
	}
}

if ( ! function_exists( 'optima_hours_data' ) ) {
	/**
	 * The confirmed weekly hours and upcoming closures as plain data, for
	 * other features. Null until the hours are confirmed.
	 *
	 * @return array{days:array, closures:array}|null
	 */
	function optima_hours_data(): ?array {
		if ( ! Optima_Hours::is_public() ) {
			return null;
		}
		return array(
			'days'     => Optima_Hours::settings()['days'],
			'closures' => Optima_Hours::upcoming_closures(),
		);
	}
}
