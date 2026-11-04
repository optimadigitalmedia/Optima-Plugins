<?php
/**
 * SEO and schema facts that come from the theme's optima-setup.json.
 *
 * When a theme hands its SEO head to Optima Core (plugin first, job `seo` and
 * `schema`), the titles, descriptions, share images and extra JSON-LD nodes it
 * used to print move into the `seo` section of its optima-setup.json. Optima
 * Build's setup filler copies that section into the option below every time
 * the theme changes (and on "Fill from theme again"); this class reads it.
 *
 * Order for every value: what an editor set in the Optima SEO box (or on
 * Optima Build -> SEO) wins, then the theme's per-URL value from this data,
 * then Core's generic fallbacks, then this data's site-wide defaults.
 *
 * The full format is in docs/plugin-first.md, section 3a.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_SEO_Setup {

	/** The stored copy of the theme's `seo` section. Written only by Optima Build's setup filler. */
	const OPTION = 'optima_core_seo_setup';

	/** Schema keys whose values are addresses: relative ones resolve to the site or the theme. */
	const URL_KEYS = array( 'url', 'image', 'logo', 'contentUrl', 'thumbnailUrl', 'embedUrl', 'mainEntityOfPage', 'sameAs', 'hasMap' );

	/** @var array|null The option, read once per request. */
	private static $data = null;

	/** @var string The page part of the title WordPress built, for title_format. */
	private static $page_part = '';

	public static function init(): void {
		add_filter( 'document_title_parts', array( __CLASS__, 'capture_parts' ), 99 );
		add_filter( 'document_title', array( __CLASS__, 'format_title' ), 20 );
		add_filter( 'optima_schema_graph', array( __CLASS__, 'schema_graph' ), 5 );
	}

	/** The whole stored section, or an empty array. */
	public static function data(): array {
		if ( null === self::$data ) {
			$raw        = get_option( self::OPTION, array() );
			self::$data = is_array( $raw ) ? $raw : array();
		}
		return self::$data;
	}

	/** Forget the cached copy (after the filler writes a new one). */
	public static function flush(): void {
		self::$data = null;
	}

	/** True when this data applies: Core prints the head and the theme carried an seo section. */
	public static function active(): bool {
		return self::data() && class_exists( 'Optima_SEO_Head' ) && ! Optima_SEO_Head::theme_handles_head();
	}

	/** One site-wide value from seo.site. */
	public static function site( string $key ) {
		if ( ! self::active() ) {
			return '';
		}
		$site = (array) ( self::data()['site'] ?? array() );
		return $site[ $key ] ?? '';
	}

	/** One value for the current URL from seo.pages ('' when unset). */
	public static function page( string $key ) {
		$page = self::current_page();
		return $page[ $key ] ?? '';
	}

	/**
	 * The seo.pages entry for the current request. Exact path first ("/about/"),
	 * then the longest prefix pattern ending in "*" ("/blog/*").
	 */
	public static function current_page(): array {
		static $cache = array();
		if ( ! self::active() ) {
			return array();
		}
		$path = self::current_path();
		if ( isset( $cache[ $path ] ) ) {
			return $cache[ $path ];
		}
		$pages = (array) ( self::data()['pages'] ?? array() );
		$found = array();
		if ( isset( $pages[ $path ] ) && is_array( $pages[ $path ] ) ) {
			$found = $pages[ $path ];
		} else {
			$best = -1;
			foreach ( $pages as $key => $entry ) {
				$key = (string) $key;
				if ( '*' !== substr( $key, -1 ) || ! is_array( $entry ) ) {
					continue;
				}
				$prefix = substr( $key, 0, -1 );
				if ( 0 === strpos( $path, $prefix ) && strlen( $prefix ) > $best && $path !== $prefix ) {
					$best  = strlen( $prefix );
					$found = $entry;
				}
			}
		}
		$cache[ $path ] = $found;
		return $found;
	}

	/** The current request as a path below the site's home ("/", "/about/"), with a trailing slash. */
	public static function current_path(): string {
		$url = '';
		if ( is_front_page() ) {
			return '/';
		}
		if ( is_singular() ) {
			$url = (string) get_permalink( get_queried_object_id() );
		} elseif ( is_home() && (int) get_option( 'page_for_posts' ) ) {
			$url = (string) get_permalink( (int) get_option( 'page_for_posts' ) );
		}
		$path = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path only, compared as a key.
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $base && '/' !== $base && 0 === strpos( $path, rtrim( $base, '/' ) ) ) {
			$path = substr( $path, strlen( rtrim( $base, '/' ) ) );
		}
		return self::normalize_path( $path );
	}

	/** "/about" and "about/" both become "/about/". Patterns ending in "*" keep it. */
	public static function normalize_path( string $path ): string {
		$path = '/' . ltrim( trim( $path ), '/' );
		if ( '*' === substr( $path, -1 ) ) {
			return $path;
		}
		return '/' === $path ? '/' : trailingslashit( $path );
	}

	/**
	 * An address from the setup data as a full URL.
	 *   https://...         used as is
	 *   /about/ or #person  on this site (home_url)
	 *   assets/og/home.png  a file in the theme (child theme first)
	 */
	public static function resolve( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || preg_match( '#^(https?:)?//#i', $value ) || preg_match( '#^(mailto|tel):#i', $value ) ) {
			return $value;
		}
		if ( '#' === $value[0] ) {
			return home_url( '/' . $value );
		}
		if ( '/' === $value[0] ) {
			return home_url( $value );
		}
		$path = ltrim( str_replace( '..', '', $value ), '/' );
		if ( is_readable( trailingslashit( get_stylesheet_directory() ) . $path ) ) {
			return trailingslashit( get_stylesheet_directory_uri() ) . $path;
		}
		return trailingslashit( get_template_directory_uri() ) . $path;
	}

	/** Width and height of a theme image, for og:image:width/height. Empty for remote files. */
	public static function theme_image_size( string $value ): array {
		if ( '' === $value || preg_match( '#^(https?:)?//#i', $value ) || '/' === $value[0] ) {
			return array();
		}
		$path = ltrim( str_replace( '..', '', $value ), '/' );
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			$file = trailingslashit( $dir ) . $path;
			if ( is_readable( $file ) ) {
				$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $file ) : @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a broken file just gives no size.
				return $size ? array( (int) $size[0], (int) $size[1] ) : array();
			}
		}
		return array();
	}

	/* ---- Title ---------------------------------------------------------- */

	public static function capture_parts( $parts ) {
		self::$page_part = isset( $parts['title'] ) ? (string) $parts['title'] : '';
		return $parts;
	}

	/**
	 * Apply seo.site.title_format ("%page% | %site%") to titles WordPress built
	 * itself. Runs only when neither the SEO box nor seo.pages set the title
	 * (those come back from pre_get_document_title and skip this filter).
	 */
	public static function format_title( $title ) {
		$format = (string) self::site( 'title_format' );
		if ( '' === $format || is_front_page() || '' === self::$page_part ) {
			return $title;
		}
		$site = (string) self::site( 'site_name' );
		return strtr(
			$format,
			array(
				'%page%'    => self::$page_part,
				'%site%'    => '' !== $site ? $site : get_bloginfo( 'name', 'display' ),
				'%tagline%' => get_bloginfo( 'description', 'display' ),
			)
		);
	}

	/* ---- Robots --------------------------------------------------------- */

	/**
	 * Apply a robots string ("noindex, follow", "max-snippet:-1") to core's
	 * wp_robots array.
	 */
	public static function apply_robots( array $robots, string $value ): array {
		foreach ( array_filter( array_map( 'trim', explode( ',', strtolower( $value ) ) ) ) as $token ) {
			$pair = array_map( 'trim', explode( ':', $token, 2 ) );
			$key  = preg_replace( '/[^a-z0-9\-]/', '', $pair[0] );
			if ( '' === $key ) {
				continue;
			}
			$opposite = array(
				'index'    => 'noindex',
				'noindex'  => 'index',
				'follow'   => 'nofollow',
				'nofollow' => 'follow',
			);
			if ( isset( $opposite[ $key ] ) ) {
				unset( $robots[ $opposite[ $key ] ] );
			}
			$robots[ $key ] = isset( $pair[1] ) && '' !== $pair[1] ? $pair[1] : true;
		}
		return $robots;
	}

	/* ---- Schema --------------------------------------------------------- */

	/**
	 * Merge the theme's schema facts into Core's graph:
	 *   schema.organization  fields laid over the #business node (the theme's facts win;
	 *                        Business info fills what the theme leaves out; @id stays #business)
	 *   schema.website       fields laid over the #website node (site.site_name names it first)
	 *   schema.same_as       added to the business sameAs list
	 *   schema.people        Person nodes on every page (worksFor #business by default)
	 *   schema.nodes         any other site-wide nodes
	 *   pages[url].schema    nodes for that one URL
	 *
	 * @param array<string,array> $graph
	 * @return array<string,array>
	 */
	public static function schema_graph( $graph ) {
		$graph = (array) $graph;
		if ( ! self::active() ) {
			return $graph;
		}
		$schema = (array) ( self::data()['schema'] ?? array() );

		if ( ! empty( $schema['organization'] ) && is_array( $schema['organization'] ) && isset( $graph['business'] ) ) {
			$org = self::resolve_node( $schema['organization'] );
			unset( $org['@id'], $org['@context'] );
			$same            = array_merge( (array) ( $graph['business']['sameAs'] ?? array() ), (array) ( $org['sameAs'] ?? array() ) );
			$graph['business'] = array_merge( $graph['business'], $org );
			if ( $same ) {
				$graph['business']['sameAs'] = $same;
			}
		}
		if ( ! empty( $schema['same_as'] ) && isset( $graph['business'] ) ) {
			$graph['business']['sameAs'] = array_merge( (array) ( $graph['business']['sameAs'] ?? array() ), array_map( array( __CLASS__, 'resolve' ), array_map( 'strval', (array) $schema['same_as'] ) ) );
		}
		if ( isset( $graph['business']['sameAs'] ) ) {
			$graph['business']['sameAs'] = array_values( array_unique( array_filter( (array) $graph['business']['sameAs'] ) ) );
		}
		$site_name = (string) self::site( 'site_name' );
		if ( '' !== $site_name && isset( $graph['website'] ) ) {
			$graph['website']['name'] = $site_name;
		}
		if ( ! empty( $schema['website'] ) && is_array( $schema['website'] ) && isset( $graph['website'] ) ) {
			$site = self::resolve_node( $schema['website'] );
			unset( $site['@id'], $site['@context'] );
			$graph['website'] = array_merge( $graph['website'], $site );
		}

		foreach ( (array) ( $schema['people'] ?? array() ) as $i => $person ) {
			if ( ! is_array( $person ) || empty( $person['name'] ) ) {
				continue;
			}
			$person = self::resolve_node( $person );
			$slug   = sanitize_title( (string) $person['name'] );
			$person = array_merge(
				array(
					'@type'    => 'Person',
					'@id'      => home_url( '/#person-' . $slug ),
					'worksFor' => array( '@id' => home_url( '/#business' ) ),
				),
				$person
			);
			$graph[ 'setup_person_' . ( '' !== $slug ? $slug : $i ) ] = $person;
		}
		foreach ( (array) ( $schema['nodes'] ?? array() ) as $i => $node ) {
			if ( is_array( $node ) && ! empty( $node['@type'] ) ) {
				$graph[ 'setup_node_' . $i ] = self::resolve_node( $node );
			}
		}
		foreach ( (array) ( self::current_page()['schema'] ?? array() ) as $i => $node ) {
			if ( is_array( $node ) && ! empty( $node['@type'] ) ) {
				$graph[ 'setup_page_' . $i ] = self::resolve_node( $node );
			}
		}
		return $graph;
	}

	/** Resolve relative addresses and "#id" references inside a node, at any depth. */
	public static function resolve_node( array $node ): array {
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( in_array( $key, self::URL_KEYS, true ) && array_keys( $value ) === range( 0, count( $value ) - 1 ) && ! array_filter( $value, 'is_array' ) ) {
					$node[ $key ] = array_map( array( __CLASS__, 'resolve' ), array_map( 'strval', $value ) );
				} else {
					$node[ $key ] = self::resolve_node( $value );
				}
			} elseif ( is_string( $value ) && ( '@id' === $key || in_array( $key, self::URL_KEYS, true ) ) ) {
				$node[ $key ] = self::resolve( $value );
			}
		}
		return $node;
	}
}
