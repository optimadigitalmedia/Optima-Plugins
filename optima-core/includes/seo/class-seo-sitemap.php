<?php
/**
 * Sitemap handling. WordPress core already generates a valid XML sitemap at
 * /wp-sitemap.xml and lists it in robots.txt, so we do not rebuild it. We tune
 * it (noindexed pages stay out, so what we tell crawlers to index and what we
 * submit always agree: the SEO box's noindex, and the robots rules in the
 * theme's optima-setup.json seo.pages, for pages, terms, whole archive kinds
 * and author archives) and guard it: if robots.txt ends up with no Sitemap
 * line (an SEO plugin switched core's off, or a leftover setting), we add the
 * one that works. Finding it happens in a daily background check, so serving
 * robots.txt never waits on a web request.
 *
 * @package Optima_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_SEO_Sitemap {

	const FOUND_OPTION = 'optima_seo_sitemap_found';
	const CRON         = 'optima_seo_sitemap_check';

	/** Where sitemaps usually live: Yoast, Rank Math / All in One SEO, WordPress core. */
	const CANDIDATES = array( '/sitemap_index.xml', '/sitemap.xml', '/wp-sitemap.xml' );

	public static function init() {
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'exclude_noindex' ), 10, 2 );
		// The theme's noindexed archives (seo.pages ":category", ":tag", ":author", ":archive", term paths).
		add_filter( 'wp_sitemaps_taxonomies', array( __CLASS__, 'drop_noindex_taxonomies' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'exclude_noindex_terms' ), 10, 2 );
		add_filter( 'wp_sitemaps_users_pre_max_num_pages', array( __CLASS__, 'drop_noindex_users' ) );
		add_filter( 'wp_sitemaps_users_pre_url_list', array( __CLASS__, 'drop_noindex_users_list' ) );
		// After every SEO plugin has had its say, just before AI Search's Content-Signal line.
		add_filter( 'robots_txt', array( __CLASS__, 'guard_robots' ), PHP_INT_MAX - 1, 2 );
		add_action( self::CRON, array( __CLASS__, 'find' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	/** Keep the daily check scheduled (activation hooks do not run on updates). */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 60, 'daily', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** Add the found sitemap when robots.txt names none. Never guesses. */
	public static function guard_robots( $output, $public ) {
		$output = (string) $output;
		if ( ! $public || preg_match( '/^\s*sitemap\s*:/im', $output ) ) {
			return $output;
		}
		$found = get_option( self::FOUND_OPTION, array() );
		if ( empty( $found['url'] ) ) {
			return $output;
		}
		return rtrim( $output ) . "\n\nSitemap: " . esc_url_raw( $found['url'] ) . "\n";
	}

	/**
	 * Ask the public site which sitemap answers, and remember the first.
	 *
	 * @return array{url:string, checked:int}
	 */
	public static function find() {
		$home  = untrailingslashit( home_url() );
		$found = array( 'url' => '', 'checked' => time() );
		foreach ( self::CANDIDATES as $path ) {
			$res = wp_remote_head( $home . $path, array( 'timeout' => 8, 'redirection' => 2 ) );
			if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
				$found['url'] = $home . $path;
				break;
			}
		}
		update_option( self::FOUND_OPTION, $found, false );
		return $found;
	}

	/**
	 * Drop any post flagged noindex from the core sitemap query.
	 */
	public static function exclude_noindex( $args, $post_type ) {
		$meta_query   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => OPTIMA_SEO_META_NOINDEX,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => OPTIMA_SEO_META_NOINDEX,
				'value'   => '1',
				'compare' => '!=',
			),
		);
		$args['meta_query'] = $meta_query;
		// And any page the theme's seo.pages robots rules noindex (path, pattern, ":posts").
		$skip = self::setup_noindex_posts( (string) $post_type );
		if ( $skip ) {
			$args['post__not_in'] = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $args['post__not_in'] ?? array() ) ), $skip ) ) );
		}
		return $args;
	}

	/* ---- The theme's robots rules (optima-setup.json seo.pages) ------------ */

	/** The special keys an archive of this taxonomy uses, most specific first (as Optima_SEO_Setup::special_keys()). */
	private static function taxonomy_keys( string $taxonomy ): array {
		$map = array(
			'category' => ':category',
			'post_tag' => ':tag',
		);
		return isset( $map[ $taxonomy ] ) ? array( $map[ $taxonomy ], ':archive' ) : array( ':archive' );
	}

	/** Drop a whole taxonomy whose archives the theme noindexes (":category", ":tag", ":archive"). */
	public static function drop_noindex_taxonomies( $taxonomies ) {
		if ( ! is_array( $taxonomies ) || ! Optima_SEO_Setup::active() ) {
			return $taxonomies;
		}
		foreach ( array_keys( $taxonomies ) as $taxonomy ) {
			if ( Optima_SEO_Setup::noindexed( Optima_SEO_Setup::entry_for( '', self::taxonomy_keys( (string) $taxonomy ) ) ) ) {
				unset( $taxonomies[ $taxonomy ] );
			}
		}
		return $taxonomies;
	}

	/** Leave out single terms the theme noindexes by path ("/category/news/", "/topics/*"). */
	public static function exclude_noindex_terms( $args, $taxonomy ) {
		if ( ! is_array( $args ) || ! Optima_SEO_Setup::has_page_noindex() ) {
			return $args;
		}
		$keys  = self::taxonomy_keys( (string) $taxonomy );
		$terms = get_terms(
			array(
				'taxonomy'               => (string) $taxonomy,
				'hide_empty'             => true,
				'update_term_meta_cache' => false,
			)
		);
		$skip  = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$path = Optima_SEO_Setup::path_below_home( (string) wp_parse_url( $link, PHP_URL_PATH ) );
			if ( Optima_SEO_Setup::noindexed( Optima_SEO_Setup::entry_for( $path, $keys ) ) ) {
				$skip[] = (int) $term->term_id;
			}
		}
		if ( $skip ) {
			$args['exclude'] = array_values( array_unique( array_merge( wp_parse_id_list( $args['exclude'] ?? array() ), $skip ) ) );
		}
		return $args;
	}

	/** True when the theme noindexes author archives (":author", ":archive"). */
	private static function users_noindexed(): bool {
		return Optima_SEO_Setup::active() && Optima_SEO_Setup::noindexed( Optima_SEO_Setup::entry_for( '', array( ':author', ':archive' ) ) );
	}

	/** No author sitemap in the index when author archives are noindexed. */
	public static function drop_noindex_users( $max ) {
		return self::users_noindexed() ? 0 : $max;
	}

	/** And none served (core answers 404 for an empty list). */
	public static function drop_noindex_users_list( $list ) {
		return self::users_noindexed() ? array() : $list;
	}

	/**
	 * IDs of published posts of one type that the theme's seo.pages robots rules
	 * noindex, matched by path as the head matches them (the front page is "/",
	 * the posts page also takes ":posts"). Only worked out when some rule can
	 * noindex a page at all.
	 *
	 * @return int[]
	 */
	private static function setup_noindex_posts( string $post_type ): array {
		static $cache = array();
		if ( isset( $cache[ $post_type ] ) ) {
			return $cache[ $post_type ];
		}
		$skip = array();
		if ( Optima_SEO_Setup::has_page_noindex() ) {
			$static     = 'page' === get_option( 'show_on_front' );
			$front      = $static ? (int) get_option( 'page_on_front' ) : 0;
			$posts_page = $static ? (int) get_option( 'page_for_posts' ) : 0;
			$posts      = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'has_password'           => false,
					'numberposts'            => -1,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'suppress_filters'       => true,
					'update_post_meta_cache' => false,
				)
			);
			foreach ( $posts as $post ) {
				$id   = (int) $post->ID;
				$path = $id === $front ? '/' : Optima_SEO_Setup::path_below_home( (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) );
				if ( Optima_SEO_Setup::noindexed( Optima_SEO_Setup::entry_for( $path, $id === $posts_page ? array( ':posts' ) : array() ) ) ) {
					$skip[] = $id;
				}
			}
		}
		$cache[ $post_type ] = $skip;
		return $skip;
	}
}
