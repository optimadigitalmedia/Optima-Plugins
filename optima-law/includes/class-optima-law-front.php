<?php
/**
 * Where the disclaimer lines show on the site: the footer of every page,
 * under every Gravity Form, next to case results (Optima Case results) and
 * under reviews (Optima Reviews). One wording, set once on the Disclaimers
 * screen, reaches every place.
 *
 * The footer lines render in the theme's footer slot (optima_slot( 'footer' ),
 * plugin-first rule 12). A theme without slots still gets them from wp_footer,
 * once per page. A theme that places them itself declares
 * add_theme_support( 'optima-law-footer' ) and calls optima_law_disclaimers( 'footer' ).
 *
 * @package Optima_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Law_Front {

	/** @var bool The footer lines already printed on this page. */
	private static $footer_done = false;

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'optima_slot_footer', array( __CLASS__, 'footer_slot' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 20 );
		add_shortcode( 'optima_disclaimers', array( __CLASS__, 'shortcode_place' ) );
		add_shortcode( 'optima_disclaimer', array( __CLASS__, 'shortcode_line' ) );
		add_filter( 'gform_get_form_filter', array( __CLASS__, 'form' ), 10, 2 );
		add_filter( 'optima_results_disclaimer', array( __CLASS__, 'results' ) );
		add_filter( 'option_optima_reviews_settings', array( __CLASS__, 'reviews' ) );
		add_filter( 'default_option_optima_reviews_settings', array( __CLASS__, 'reviews' ) ); // Review settings never saved yet.
	}

	/** A few quiet lines of CSS. No color, font or radius: the lines take the theme's. */
	public static function assets(): void {
		wp_enqueue_style( 'optima-law', OPTIMA_LAW_URL . 'assets/optima-law.css', array(), OPTIMA_LAW_VERSION );
	}

	/** The lines for one place, as HTML. Empty string when none show there. */
	public static function html( string $place ): string {
		$lines = Optima_Law_Disclaimers::in_place( $place );
		if ( ! $lines ) {
			return '';
		}
		$out = '';
		foreach ( $lines as $line ) {
			$out .= '<p class="optima-law-disclaimer optima-law-disclaimer--' . esc_attr( $line ) . '">' . esc_html( Optima_Law_Disclaimers::text( $line ) ) . '</p>';
		}
		return '<div class="optima-law-disclaimers optima-law-disclaimers--' . esc_attr( $place ) . '">' . $out . '</div>';
	}

	/** The theme's footer slot. */
	public static function footer_slot(): void {
		if ( self::$footer_done ) {
			return;
		}
		self::$footer_done = true;
		echo self::html( 'footer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in html().
	}

	/** The footer lines for a theme without the footer slot, unless the theme places them itself. */
	public static function footer(): void {
		if ( self::$footer_done || is_admin() || current_theme_supports( 'optima-law-footer' ) ) {
			return;
		}
		self::footer_slot();
	}

	/** [optima_disclaimers place="footer"] prints every line set to show in that place. */
	public static function shortcode_place( $atts ): string {
		$atts  = shortcode_atts( array( 'place' => 'footer' ), (array) $atts, 'optima_disclaimers' );
		$place = sanitize_key( (string) $atts['place'] );
		return isset( Optima_Law_Disclaimers::places()[ $place ] ) ? self::html( $place ) : '';
	}

	/** [optima_disclaimer line="relationship"] prints one line wherever it is placed. */
	public static function shortcode_line( $atts ): string {
		$atts = shortcode_atts( array( 'line' => 'relationship' ), (array) $atts, 'optima_disclaimer' );
		$line = sanitize_key( (string) $atts['line'] );
		if ( ! isset( Optima_Law_Disclaimers::lines()[ $line ] ) ) {
			return '';
		}
		return '<p class="optima-law-disclaimer optima-law-disclaimer--' . esc_attr( $line ) . '">' . esc_html( Optima_Law_Disclaimers::text( $line ) ) . '</p>';
	}

	/** Under every Gravity Form. */
	public static function form( $html, $form ) {
		unset( $form );
		return (string) $html . self::html( 'forms' );
	}

	/** Optima Case results prints this firm-wide wording next to every result (filter optima_results_disclaimer). */
	public static function results( $text ) {
		return in_array( 'results', Optima_Law_Disclaimers::places_for( 'results' ), true ) ? Optima_Law_Disclaimers::text( 'results' ) : $text;
	}

	/** Optima Reviews prints this wording under its reviews. */
	public static function reviews( $settings ) {
		if ( ! is_array( $settings ) || is_admin() ) {
			return $settings; // The Reviews screen keeps showing what was saved there.
		}
		if ( in_array( 'reviews', Optima_Law_Disclaimers::places_for( 'reviews' ), true ) ) {
			$settings['disclaimer'] = Optima_Law_Disclaimers::text( 'reviews' );
		}
		return $settings;
	}
}

if ( ! function_exists( 'optima_law_disclaimers' ) ) {
	/**
	 * Print the disclaimer lines for a place, for themes that place them
	 * themselves (add_theme_support( 'optima-law-footer' )).
	 *
	 * @param string $place footer, forms, results or reviews.
	 */
	function optima_law_disclaimers( string $place = 'footer' ): void {
		if ( 'footer' === $place ) {
			Optima_Law_Front::footer_slot(); // Once per page, whichever place reaches it first.
			return;
		}
		echo Optima_Law_Front::html( $place ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}
}
