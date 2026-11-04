<?php
/**
 * Account names stay private (job: account-privacy).
 *
 * WordPress publishes each post author's login-derived name in four places:
 * the author archive (/author/<name>/, also reached as ?author=N), the public
 * users list in the REST API, the users sitemap, and the author link printed
 * by themes. Login names often are email addresses, so these can show which
 * accounts and which companies have access to a site.
 *
 * For visitors (never for signed-in people, so the editor keeps working):
 *  - author archives and ?author=N send a 301 to the site's About page, or
 *    the home page when there is none;
 *  - the REST users routes are removed;
 *  - the users sitemap is removed;
 *  - author links point to the same About page.
 * Bylines and schema keep the display name, so nothing visible changes.
 *
 * Filters: `optima_build_account_privacy` (bool, default true) switches it
 * off; `optima_build_author_target` (string URL) changes where author pages go.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Privacy {

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 1 );
	}

	public static function register(): void {
		if ( ! (bool) apply_filters( 'optima_build_account_privacy', true ) ) {
			return;
		}
		// Before redirect_canonical turns ?author=N into the /author/<name>/ address.
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 1 );
		add_filter( 'rest_endpoints', array( __CLASS__, 'rest' ) );
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'sitemap' ), 10, 2 );
		add_filter( 'author_link', array( __CLASS__, 'target' ), 99 );
	}

	/** Where author pages and links go: the About page if the site has one, else home. */
	public static function target(): string {
		$about = get_page_by_path( 'about' );
		$url   = ( $about instanceof WP_Post && 'publish' === $about->post_status ) ? get_permalink( $about ) : home_url( '/' );
		return (string) apply_filters( 'optima_build_author_target', $url );
	}

	public static function redirect(): void {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}
		if ( is_author() || isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of a public query var.
			wp_safe_redirect( self::target(), 301 );
			exit;
		}
	}

	/** @param array<string,mixed> $endpoints */
	public static function rest( $endpoints ) {
		if ( is_user_logged_in() || ! is_array( $endpoints ) ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( (string) $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/**
	 * @param mixed  $provider
	 * @param string $name
	 * @return mixed
	 */
	public static function sitemap( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}
}
