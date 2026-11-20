<?php
/**
 * Global SEO defaults, stored as one option `optima_seo_defaults`. Per-page SEO
 * still lives in the Optima SEO box; these are the site-wide fallbacks:
 *  - default_image: the share image used when a page has no featured image.
 *  - home_description: meta description for the front page.
 *  - alternate_name: other names the site goes by (a DBA, a short name), one per
 *    line, printed as the WebSite schema's alternateName only when set.
 *  - google_verify, bing_verify: the Search Console and Bing Webmaster Tools
 *    verification codes, printed as meta tags on the front page (upstream
 *    1.8.0). Paste the code or the whole tag; only the code is kept.
 * Keys this screen does not show (purge_on_uninstall) survive a save.
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
			'google_verify'    => 'Google Search Console verification code',
			'bing_verify'      => 'Bing Webmaster Tools verification code',
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
		// Keys this screen does not show (purge_on_uninstall) are kept as they were
		// (upstream 1.8.0: a save used to wipe them).
		$saved = get_option( self::OPTION, array() );
		$out   = is_array( $saved ) ? $saved : array();
		$out['default_image']    = isset( $input['default_image'] ) ? esc_url_raw( $input['default_image'] ) : '';
		$out['home_description'] = isset( $input['home_description'] ) ? sanitize_textarea_field( $input['home_description'] ) : '';
		// A save from an older form that did not show a field keeps the saved value.
		$out['alternate_name']   = isset( $input['alternate_name'] ) ? sanitize_textarea_field( $input['alternate_name'] ) : (string) ( $out['alternate_name'] ?? '' );
		$out['google_verify']    = isset( $input['google_verify'] ) ? self::code( $input['google_verify'] ) : (string) ( $out['google_verify'] ?? '' );
		$out['bing_verify']      = isset( $input['bing_verify'] ) ? self::code( $input['bing_verify'] ) : (string) ( $out['bing_verify'] ?? '' );
		return $out;
	}

	/**
	 * A verification code from whatever was pasted: the code itself, the whole
	 * meta tag, or Google's HTML file name (google1234abcd.html is a file
	 * check, not a code, so it is refused and the field stays empty).
	 */
	public static function code( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/content\s*=\s*["\']([^"\']+)["\']/i', $raw, $m ) ) {
			$raw = $m[1];
		}
		if ( preg_match( '/\.html?$/i', $raw ) ) {
			return '';
		}
		$raw = preg_replace( '/[^A-Za-z0-9_\-=.]/', '', $raw );
		return substr( (string) $raw, 0, 120 );
	}
}
