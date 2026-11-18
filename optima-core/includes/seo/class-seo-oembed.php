<?php
/**
 * What a page says about itself when another site embeds or previews it
 * (WordPress's oEmbed answer at /wp-json/oembed/1.0/embed and the /embed/ view).
 *
 * - The organization is the author: the business name (Your business, Details,
 *   else the site name) and the site's address. WordPress would name the user
 *   who created the page and link their author archive, which exposes a login
 *   name and a person who may not be public.
 * - A password-protected page gives nothing away: its answer is a plain link
 *   titled "Private page" (no embed code, no picture), its embed view shows the
 *   same title, and the page prints no oEmbed discovery links.
 *
 * Runs on every site, whichever plugin or theme prints the head.
 *
 * @package Optima_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_SEO_OEmbed {

	public static function init(): void {
		// After WordPress adds the embed code (priority 10), so a private page's code can be taken out.
		add_filter( 'oembed_response_data', array( __CLASS__, 'response' ), 20, 2 );
		add_filter( 'oembed_discovery_links', array( __CLASS__, 'discovery_links' ) );
		add_filter( 'the_title', array( __CLASS__, 'embed_title' ), 10, 2 );
	}

	/** The organization's public name: the business name, else the site name. */
	public static function organization_name(): string {
		$name = function_exists( 'optima_core_business' ) ? (string) ( ( (array) optima_core_business() )['name'] ?? '' ) : '';
		$name = '' !== $name ? $name : (string) get_bloginfo( 'name' );
		return wp_specialchars_decode( $name, ENT_QUOTES );
	}

	/**
	 * @param array   $data The oEmbed answer.
	 * @param WP_Post $post The page or post.
	 * @return array
	 */
	public static function response( $data, $post ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$data['author_name'] = self::organization_name();
		$data['author_url']  = home_url( '/' );
		if ( Optima_SEO_Head::is_private( $post ) ) {
			$data['type']  = 'link';
			$data['title'] = Optima_SEO_Head::private_title();
			unset( $data['html'], $data['width'], $data['height'], $data['thumbnail_url'], $data['thumbnail_width'], $data['thumbnail_height'] );
		}
		return $data;
	}

	/** No oEmbed discovery links on a password-protected page. */
	public static function discovery_links( $output ) {
		return Optima_SEO_Head::is_private() ? '' : $output;
	}

	/** The embed view of a password-protected page shows the generic title. */
	public static function embed_title( $title, $post_id = 0 ) {
		if ( ! $post_id || ! function_exists( 'is_embed' ) || ! is_embed() ) {
			return $title;
		}
		return Optima_SEO_Head::is_private( (int) $post_id ) ? esc_html( Optima_SEO_Head::private_title() ) : $title;
	}
}
