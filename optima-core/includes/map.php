<?php
/**
 * Map: a Google map of the business that loads only when a visitor taps it
 * (upstream 1.15.0).
 *
 * `[optima_map]` prints a "Show the map" button over whatever the theme puts
 * inside the shortcode (a photo of the building, a drawn map, or nothing). The
 * Google map loads in place on the tap, so the page stays fast and Google's
 * cookies reach only visitors who asked for the map. Without JavaScript, or
 * before the tap, the button is a link that opens Google Maps.
 *
 * The place comes from Your business, Details (street, city, state, postal),
 * or the latitude and longitude when there is no street. With
 * `office="<id>"` it is that office from Your business, Offices. Nothing is
 * typed twice (plugin-first rule 45).
 *
 *   [optima_map]                          a button on a plain panel
 *   [optima_map]<img ...>[/optima_map]    the button over the theme's own picture
 *   [optima_map label="Show the map"]     the button's words
 *   [optima_map office="tampa"]           one office's map
 *
 * The look comes from DESIGN.md through the `--optima-*` variables (in
 * assets/optima-core.css); a theme can restyle `.optima-map` and
 * `.optima-map-show`. A tap sends `directions_click` (location `map`) to Optima
 * Analytics, one of the seven event names (plugin-first rule 50).
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Map {

	public static function init(): void {
		add_shortcode( 'optima_map', array( __CLASS__, 'shortcode' ) );
	}

	/** The place Google should show: the business name and address, "lat,lng" without a street, or ''. */
	public static function place( string $office = '' ): string {
		if ( '' !== $office && class_exists( 'Optima_Core_Offices' ) ) {
			$o = Optima_Core_Offices::get( $office );
			if ( is_array( $o ) ) {
				return trim( $o['name'] . ', ' . Optima_Core_Offices::address_line( $o ), ', ' );
			}
		}
		$b    = function_exists( 'optima_core_business' ) ? (array) optima_core_business() : array();
		$lat  = trim( (string) ( $b['latitude'] ?? '' ) );
		$lng  = trim( (string) ( $b['longitude'] ?? '' ) );
		$name = trim( (string) ( $b['name'] ?? '' ) );
		$addr = trim( implode( ', ', array_filter( array( $b['street'] ?? '', $b['city'] ?? '', trim( ( $b['region'] ?? '' ) . ' ' . ( $b['postal'] ?? '' ) ) ), 'strlen' ) ) );
		if ( '' !== $addr ) {
			return trim( $name . ', ' . $addr, ', ' );
		}
		return ( is_numeric( $lat ) && is_numeric( $lng ) ) ? $lat . ',' . $lng : $name;
	}

	/**
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Anything the theme wraps (a picture).
	 */
	public static function shortcode( $atts, $content = null ): string {
		$atts  = shortcode_atts( array( 'label' => 'Show the map', 'office' => '' ), (array) $atts, 'optima_map' );
		$place = self::place( sanitize_key( (string) $atts['office'] ) );
		$inner = null === $content ? '' : do_shortcode( (string) $content );
		if ( '' === $place ) {
			return $inner;
		}
		$q     = rawurlencode( $place );
		$embed = 'https://www.google.com/maps?q=' . $q . '&output=embed';
		$open  = 'https://www.google.com/maps/search/?api=1&query=' . $q;
		$b     = function_exists( 'optima_core_business' ) ? (array) optima_core_business() : array();
		$name  = '' !== trim( (string) ( $b['name'] ?? '' ) ) ? (string) $b['name'] : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$title = 'Map of ' . $name;

		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( 'optima-core' );
			wp_enqueue_script( 'optima-map', plugins_url( 'assets/optima-map.js', OPTIMA_CORE_FILE ), array(), OPTIMA_CORE_VERSION, true );
		}

		$html  = '<div class="optima-map' . ( '' === trim( $inner ) ? ' optima-map-plain' : '' ) . '" data-optima-map="' . esc_url( $embed ) . '" data-optima-map-title="' . esc_attr( $title ) . '">';
		$html .= $inner;
		$html .= '<a class="optima-map-show" href="' . esc_url( $open ) . '" data-optima-track="directions_click" data-optima-location="map" data-optima-label="' . esc_attr( $name ) . '" target="_blank" rel="noopener">' . esc_html( (string) $atts['label'] ) . '</a>';
		$html .= '</div>';
		return $html;
	}
}
