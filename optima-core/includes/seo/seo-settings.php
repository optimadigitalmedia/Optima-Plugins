<?php
/**
 * Global SEO defaults, stored as one option `optima_seo_defaults`. Per-page SEO
 * still lives in the Optima SEO box; these are the site-wide fallbacks:
 *  - default_image: the share image used when a page has no featured image.
 *  - home_description: meta description for the front page.
 *  - alternate_name: other names the site goes by (a DBA, a short name), one per
 *    line, printed as the WebSite schema's alternateName only when set.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_SEO_Settings {

	const OPTION = 'optima_seo_defaults';

	/** @return array<string,string> */
	public static function fields() {
		return array(
			'default_image'    => 'Default share image URL (used when a page has no featured image)',
			'home_description' => 'Homepage meta description',
			'alternate_name'   => 'Other names people search for',
		);
	}

	/** @return array<string,string> */
	public static function get() {
		$defaults = array_fill_keys( array_keys( self::fields() ), '' );
		return wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
	}

	public static function value( $key ) {
		$all = self::get();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function register_settings() {
		register_setting(
			'optima_seo_group',
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	public static function sanitize( $input ) {
		$input = (array) $input;
		return array(
			'default_image'    => isset( $input['default_image'] ) ? esc_url_raw( $input['default_image'] ) : '',
			'home_description' => isset( $input['home_description'] ) ? sanitize_textarea_field( $input['home_description'] ) : '',
			// A save from an older form that did not show the field keeps the saved value.
			'alternate_name'   => isset( $input['alternate_name'] ) ? sanitize_textarea_field( $input['alternate_name'] ) : (string) self::value( 'alternate_name' ),
		);
	}
}
