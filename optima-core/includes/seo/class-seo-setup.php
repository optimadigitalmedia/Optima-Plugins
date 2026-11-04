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
 * Sites whose theme carries no seo section never reach any of this: every
 * method returns empty and Core prints what it always printed.
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
	const URL_KEYS = array( 'url', 'image', 'logo', 'contentUrl', 'thumbnailUrl', 'embedUrl', 'mainEntityOfPage', 'sameAs', 'hasMap', 'item', 'primaryImageOfPage', 'significantLink', 'relatedLink', 'downloadUrl', 'urlTemplate' );

	/** Page keys for requests that have no path of their own. */
	const SPECIAL_KEYS = array( ':search', ':404', ':posts', ':category', ':tag', ':date', ':author', ':archive' );

	/** Node types that describe the page itself (Core then adds no WebPage or BlogPosting of its own). */
	const PAGE_TYPES = array( 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'ProfilePage', 'ItemPage', 'FAQPage', 'QAPage', 'SearchResultsPage', 'CheckoutPage', 'MedicalWebPage', 'RealEstateListing', 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report' );

	/** @var array|null The option, read once per request. */
	private static $data = null;

	/** @var string The page part of the title WordPress built, for title_format. */
	private static $page_part = '';

	/** @var array<string,string> Old @id => new @id, when the theme names its organization or website. */
	private static $remap = array();

	public static function init(): void {
		add_filter( 'document_title_parts', array( __CLASS__, 'capture_parts' ), 99 );
		add_filter( 'document_title', array( __CLASS__, 'format_title' ), 20 );
		add_filter( 'optima_schema_graph', array( __CLASS__, 'schema_graph' ), 5 );
		add_filter( 'optima_schema_webpage', array( __CLASS__, 'allow_webpage' ) );
		add_action( 'wp_head', array( __CLASS__, 'strip_start' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'strip_end' ), PHP_INT_MAX );
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

	/** One site-wide value from seo.site ('' when unset). */
	public static function site( string $key ) {
		if ( ! self::active() ) {
			return '';
		}
		$site = (array) ( self::data()['site'] ?? array() );
		return $site[ $key ] ?? '';
	}

	/** True when seo.site names this key at all (even as "" or false). */
	public static function site_has( string $key ): bool {
		return self::active() && array_key_exists( $key, (array) ( self::data()['site'] ?? array() ) );
	}

	/** One value for the current URL from seo.pages ('' when unset). */
	public static function page( string $key ) {
		$page = self::current_page();
		return $page[ $key ] ?? '';
	}

	/** True when the current URL's entry names this key at all (even as false). */
	public static function page_has( string $key ): bool {
		return array_key_exists( $key, self::current_page() );
	}

	/**
	 * One tag switch from seo.site.print. Every switch is on when not given, so a
	 * theme only lists the tags it never printed: og_locale, twitter_title,
	 * twitter_description, twitter_image, article_times, search_action, and
	 * og_image_size ("auto" reads a theme file's size, "given" prints only sizes
	 * the data or the media library gives, false never).
	 *
	 * @return bool|string
	 */
	public static function prints( string $tag ) {
		$print = (array) self::site( 'print' );
		if ( ! array_key_exists( $tag, $print ) ) {
			return 'og_image_size' === $tag ? 'auto' : true;
		}
		return is_string( $print[ $tag ] ) ? $print[ $tag ] : (bool) $print[ $tag ];
	}

	/**
	 * The seo.pages entry for the current request.
	 *
	 * Paths: exact path first ("/about/"), then the longest prefix pattern ending
	 * in "*" ("/blog/*"). Requests without a path of their own use special keys:
	 * ":search" and ":404" (never a path, so a search is not read as the home
	 * page), and for the posts page and archives the path entry with ":posts" or
	 * ":category" / ":tag" / ":date" / ":author" / ":archive" laid over it.
	 */
	public static function current_page(): array {
		static $cache = array();
		if ( ! self::active() ) {
			return array();
		}
		$pages = (array) ( self::data()['pages'] ?? array() );
		$path  = self::current_path();
		$keys  = self::special_keys();
		$ckey  = $path . '|' . implode( ',', $keys );
		if ( isset( $cache[ $ckey ] ) ) {
			return $cache[ $ckey ];
		}
		$found = array();
		if ( ! is_search() && ! is_404() ) {
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
		}
		// The general key first, then the specific one, each laid over what came before.
		foreach ( array_reverse( $keys ) as $key ) {
			if ( isset( $pages[ $key ] ) && is_array( $pages[ $key ] ) ) {
				$found = array_merge( $found, $pages[ $key ] );
			}
		}
		$cache[ $ckey ] = $found;
		return $found;
	}

	/** The special page keys that apply to this request, most specific first. */
	public static function special_keys(): array {
		if ( is_search() ) {
			return array( ':search' );
		}
		if ( is_404() ) {
			return array( ':404' );
		}
		if ( is_home() && ! is_front_page() ) {
			return array( ':posts' );
		}
		if ( ! is_archive() ) {
			return array();
		}
		$keys = array();
		if ( is_category() ) {
			$keys[] = ':category';
		} elseif ( is_tag() ) {
			$keys[] = ':tag';
		} elseif ( is_date() ) {
			$keys[] = ':date';
		} elseif ( is_author() ) {
			$keys[] = ':author';
		}
		$keys[] = ':archive';
		return $keys;
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
	 *   https://...         used as is (then moved to this host off production)
	 *   /about/ or #person  on this site (home_url)
	 *   assets/og/home.png  a file in the theme (child theme first)
	 * Anything else (plain words) is returned unchanged.
	 */
	public static function resolve( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || preg_match( '#^(mailto|tel):#i', $value ) ) {
			return $value;
		}
		if ( preg_match( '#^(https?:)?//#i', $value ) ) {
			return self::localize( $value );
		}
		if ( '#' === $value[0] ) {
			return home_url( '/' . $value );
		}
		if ( '/' === $value[0] ) {
			return home_url( $value );
		}
		if ( ! preg_match( '#^[A-Za-z0-9._\-/]+\.[A-Za-z0-9]{2,5}$#', $value ) ) {
			return $value;
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

	/* ---- Production host -------------------------------------------------- */

	/** The live site's origin from seo.site.production_url ("https://example.com"), or ''. */
	public static function production_origin(): string {
		$url = (string) self::site( 'production_url' );
		if ( '' === $url || ! preg_match( '#^https?://[^/]+#i', $url, $m ) ) {
			return '';
		}
		return strtolower( $m[0] );
	}

	/** This site's own origin ("http://localhost:8080"). */
	public static function home_origin(): string {
		return preg_match( '#^https?://[^/]+#i', home_url( '/' ), $m ) ? strtolower( $m[0] ) : '';
	}

	/**
	 * False only when production_url is set and this site runs on another host
	 * (staging, a local copy). The live host and its subdomains count as production.
	 */
	public static function is_production(): bool {
		$origin = self::production_origin();
		if ( '' === $origin ) {
			return true;
		}
		$live = (string) wp_parse_url( $origin, PHP_URL_HOST );
		$here = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		return '' !== $live && (bool) preg_match( '/(^|\.)' . preg_quote( $live, '/' ) . '$/i', $here );
	}

	/** An address written with the production origin, moved to this site's origin (unchanged on production). */
	public static function localize( string $value ): string {
		$from = self::production_origin();
		$to   = self::home_origin();
		if ( '' === $from || '' === $to || $from === $to || 0 !== stripos( $value, $from ) ) {
			return $value;
		}
		$rest = substr( $value, strlen( $from ) );
		return ( '' === $rest || in_array( $rest[0], array( '/', '?', '#' ), true ) ) ? $to . $rest : $value;
	}

	/** localize() on every string inside schema data. */
	public static function localize_deep( $data ) {
		if ( is_array( $data ) ) {
			return array_map( array( __CLASS__, 'localize_deep' ), $data );
		}
		return is_string( $data ) ? self::localize( $data ) : $data;
	}

	/* ---- Tokens ------------------------------------------------------------ */

	/**
	 * Per-request values inside setup strings:
	 *   %title%      the document title as printed     %site%     the site name
	 *   %tagline%    the site tagline                  %query%    the search terms
	 *   %url%        this request's address            %link% the canonical address
	 *   %published%  the post's published date (ISO 8601)   %modified% its last modified date
	 */
	public static function tokens( string $value ): string {
		if ( false === strpos( $value, '%' ) ) {
			return $value;
		}
		$map = array();
		foreach ( array( '%site%', '%tagline%', '%query%', '%url%', '%link%', '%published%', '%modified%', '%title%' ) as $token ) {
			if ( false !== strpos( $value, $token ) ) {
				$map[ $token ] = self::token_value( $token );
			}
		}
		return strtr( $value, $map );
	}

	private static function token_value( string $token ): string {
		$id = is_singular() ? (int) get_queried_object_id() : 0;
		switch ( $token ) {
			case '%site%':
				$site = (string) self::site( 'site_name' );
				return '' !== $site ? $site : get_bloginfo( 'name', 'display' );
			case '%tagline%':
				return get_bloginfo( 'description', 'display' );
			case '%query%':
				return is_search() ? get_search_query() : '';
			case '%url%':
				return home_url( add_query_arg( array() ) );
			case '%link%':
				$canonical = class_exists( 'Optima_SEO_Head' ) ? Optima_SEO_Head::canonical_url() : '';
				return '' !== $canonical ? $canonical : home_url( add_query_arg( array() ) );
			case '%published%':
				return $id ? (string) get_the_date( 'c', $id ) : '';
			case '%modified%':
				return $id ? (string) get_the_modified_date( 'c', $id ) : '';
			case '%title%':
				$title = (string) self::page( 'title' );
				return ( '' !== $title && false === strpos( $title, '%' ) ) ? $title : wp_get_document_title();
		}
		return '';
	}

	/** tokens() on every string inside schema data. */
	public static function tokens_deep( $data ) {
		if ( is_array( $data ) ) {
			return array_map( array( __CLASS__, 'tokens_deep' ), $data );
		}
		return is_string( $data ) ? self::tokens( $data ) : $data;
	}

	/* ---- Title ------------------------------------------------------------- */

	public static function capture_parts( $parts ) {
		self::$page_part = isset( $parts['title'] ) ? (string) $parts['title'] : '';
		return $parts;
	}

	/**
	 * Apply seo.site.title_format ("%page% | %site%") to titles WordPress built
	 * itself. A per-URL title that uses %page% is applied here too (titles
	 * without it come back from pre_get_document_title and skip this filter).
	 */
	public static function format_title( $title ) {
		$own     = (string) self::page( 'title' );
		$use_own = false !== strpos( $own, '%page%' );
		$format  = $use_own ? $own : (string) self::site( 'title_format' );
		if ( '' === $format || ( ! $use_own && ( is_front_page() || '' === self::$page_part ) ) ) {
			return $title;
		}
		return self::tokens(
			strtr(
				$format,
				array(
					'%page%' => self::$page_part,
					'%site%' => self::token_value( '%site%' ),
				)
			)
		);
	}

	/* ---- Robots ------------------------------------------------------------ */

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

	/**
	 * The theme's last word on robots, after every other rule: off production
	 * (production_url set, another host) noindex and nofollow are added; with
	 * site.robots_exact, a noindex page drops WordPress's max-image-preview.
	 */
	public static function finish_robots( array $robots ): array {
		if ( ! self::active() ) {
			return $robots;
		}
		$noindex = ! empty( $robots['noindex'] );
		if ( ! self::is_production() ) {
			unset( $robots['index'] );
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			$noindex            = true;
		}
		if ( $noindex && ( self::site( 'robots_exact' ) || ! self::is_production() ) ) {
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	/* ---- Schema: shared ------------------------------------------------------ */

	/** True when seo.schema.mode is "pages": print only what each page lists. */
	public static function exact(): bool {
		return self::active() && 'pages' === (string) ( self::data()['schema']['mode'] ?? '' );
	}

	/** Is this node a description of the page itself? */
	public static function is_page_node( $node ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}
		if ( array_intersect( (array) ( $node['@type'] ?? array() ), self::PAGE_TYPES ) ) {
			return true;
		}
		$id = (string) ( $node['@id'] ?? '' );
		return '' !== $id && (bool) preg_match( '/#(page|webpage|home)$/i', $id );
	}

	/** The current page's own nodes from seo.pages[url].schema, resolved. */
	private static function page_nodes(): array {
		$out = array();
		foreach ( (array) ( self::current_page()['schema'] ?? array() ) as $node ) {
			if ( is_array( $node ) && ( ! empty( $node['@type'] ) || self::is_reference( $node ) ) ) {
				$out[] = $node;
			}
		}
		return $out;
	}

	/** A bare {"@id": "..."} entry: in pages mode it stands for that site-wide node, in place. */
	private static function is_reference( array $node ): bool {
		return array( '@id' ) === array_keys( $node );
	}

	/** True when the page's own schema already describes the page. */
	public static function page_has_page_node(): bool {
		foreach ( self::page_nodes() as $node ) {
			if ( self::is_page_node( $node ) ) {
				return true;
			}
		}
		return false;
	}

	/** Core adds its WebPage only when the page's own schema has no page node. */
	public static function allow_webpage( $allow ) {
		return ( self::active() && self::page_has_page_node() ) ? false : $allow;
	}

	/** The organization node as the theme gave it (resolved), defaults filled. */
	private static function organization(): array {
		$org = (array) ( self::data()['schema']['organization'] ?? array() );
		return $org ? self::resolve_node( $org ) : array();
	}

	/** The website node as the theme gave it (resolved); its name defaults to site.site_name. */
	private static function website(): array {
		$site = (array) ( self::data()['schema']['website'] ?? array() );
		if ( ! $site ) {
			return array();
		}
		$site = array_merge( array( '@type' => 'WebSite' ), self::resolve_node( $site ) );
		$name = (string) self::site( 'site_name' );
		if ( '' !== $name && ! isset( $site['name'] ) ) {
			$site['name'] = $name;
		}
		return $site;
	}

	/** The people nodes, with their defaults (Person, /#person-<name>, worksFor the organization). */
	private static function people(): array {
		$org_id = (string) ( self::organization()['@id'] ?? '' );
		$out    = array();
		foreach ( (array) ( self::data()['schema']['people'] ?? array() ) as $i => $person ) {
			if ( ! is_array( $person ) || empty( $person['name'] ) ) {
				continue;
			}
			$person = self::resolve_node( $person );
			$slug   = sanitize_title( (string) $person['name'] );
			$person = array_merge(
				array(
					'@type'    => 'Person',
					'@id'      => home_url( '/#person-' . $slug ),
					'worksFor' => array( '@id' => '' !== $org_id ? $org_id : home_url( '/#business' ) ),
				),
				$person
			);
			$out[ 'setup_person_' . ( '' !== $slug ? $slug : $i ) ] = $person;
		}
		return $out;
	}

	/** The other site-wide nodes. */
	private static function nodes(): array {
		$out = array();
		foreach ( (array) ( self::data()['schema']['nodes'] ?? array() ) as $i => $node ) {
			if ( is_array( $node ) && ! empty( $node['@type'] ) ) {
				$out[ 'setup_node_' . $i ] = self::resolve_node( $node );
			}
		}
		return $out;
	}

	/* ---- Schema: graph mode (default) --------------------------------------- */

	/**
	 * Merge the theme's schema facts into Core's graph:
	 *   schema.organization  fields laid over the #business node (the theme's facts win;
	 *                        Business info fills what the theme leaves out). A given @id
	 *                        is kept, and every reference to #business follows it.
	 *   schema.website       fields laid over the #website node (site.site_name names it first;
	 *                        a given @id is kept the same way)
	 *   schema.same_as       added to the business sameAs list
	 *   schema.people        Person nodes on every page (worksFor the organization by default)
	 *   schema.nodes         any other site-wide nodes
	 *   pages[url].schema    nodes for that one URL; a node with the same @id as a
	 *                        site-wide one replaces it, and a page node (WebPage,
	 *                        Article, "#page" ...) stops Core adding its own WebPage
	 *                        and BlogPosting
	 *
	 * In pages mode (schema.mode "pages") Optima_Schema asks page_graph() instead.
	 *
	 * @param array<string,array> $graph
	 * @return array<string,array>
	 */
	public static function schema_graph( $graph ) {
		$graph = (array) $graph;
		if ( ! self::active() || self::exact() ) {
			return $graph;
		}
		$schema       = (array) ( self::data()['schema'] ?? array() );
		self::$remap  = array();

		if ( ! empty( $schema['organization'] ) && is_array( $schema['organization'] ) && isset( $graph['business'] ) ) {
			$org = self::organization();
			if ( ! empty( $org['@id'] ) && is_string( $org['@id'] ) ) {
				self::$remap[ (string) $graph['business']['@id'] ] = $org['@id'];
			} else {
				unset( $org['@id'] );
			}
			unset( $org['@context'] );
			$same              = array_merge( (array) ( $graph['business']['sameAs'] ?? array() ), (array) ( $org['sameAs'] ?? array() ) );
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
			if ( ! empty( $site['@id'] ) && is_string( $site['@id'] ) ) {
				self::$remap[ (string) $graph['website']['@id'] ] = $site['@id'];
			} else {
				unset( $site['@id'] );
			}
			unset( $site['@context'] );
			$graph['website'] = array_merge( $graph['website'], $site );
		}
		if ( isset( $graph['website'] ) && ! self::prints( 'search_action' ) ) {
			unset( $graph['website']['potentialAction'] );
		}

		$graph = array_merge( $graph, self::people(), self::nodes() );

		$page = self::page_nodes();
		if ( $page ) {
			foreach ( $page as $node ) {
				if ( self::is_page_node( $node ) ) {
					unset( $graph['article'] );
					break;
				}
			}
		}
		foreach ( $page as $i => $node ) {
			if ( self::is_reference( $node ) ) {
				continue;
			}
			$node = self::faq_from_page( self::tokens_deep( self::resolve_node( $node ) ) );
			$id   = (string) ( $node['@id'] ?? '' );
			$hit  = '' !== $id ? self::key_for_id( $graph, $id ) : '';
			if ( '' !== $hit ) {
				$graph[ $hit ] = $node; // The page's own node wins over the site-wide copy.
			} else {
				$graph[ 'setup_page_' . $i ] = $node;
			}
		}
		return $graph;
	}

	/** The graph key whose node carries this @id (after any remap), or ''. */
	private static function key_for_id( array $graph, string $id ): string {
		foreach ( $graph as $key => $node ) {
			$nid = (string) ( $node['@id'] ?? '' );
			if ( '' !== $nid && ( $nid === $id || ( self::$remap[ $nid ] ?? '' ) === $id ) ) {
				return (string) $key;
			}
		}
		return '';
	}

	/**
	 * Last pass over the finished graph, after every companion plugin: references
	 * to a renamed organization or website follow the theme's @id, and addresses
	 * written with the production origin move to this host off production.
	 *
	 * @param array<string,array> $graph
	 * @return array<string,array>
	 */
	public static function finish_graph( array $graph ): array {
		if ( ! self::active() ) {
			return $graph;
		}
		if ( self::$remap ) {
			$graph = self::remap_ids( $graph );
		}
		return self::localize_deep( $graph );
	}

	private static function remap_ids( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::remap_ids( $value );
			} elseif ( '@id' === $key && is_string( $value ) && isset( self::$remap[ $value ] ) ) {
				$data[ $key ] = self::$remap[ $value ];
			}
		}
		return $data;
	}

	/* ---- Schema: pages mode ------------------------------------------------- */

	/**
	 * The nodes for this request when seo.schema.mode is "pages": exactly what
	 * the page lists, in its order, nothing site-wide unless seo.schema.print
	 * says so. Business info adds nothing. Keys: the organization's node is
	 * "business" and the website's "website", so companion plugins still find them.
	 *
	 *   schema.print     { "organization": true, "people": true, "website": ["/"] }:
	 *                    site-wide nodes printed before the page's own, in the order
	 *                    listed; true for every URL, a list of page keys for those only.
	 *   pages[].schema   the page's nodes. A bare { "@id": "/#firm" } stands for the
	 *                    site-wide node with that @id, printed whole in that place.
	 *   schema.fallback  a node (or list) for singular pages with no schema of their
	 *                    own; tokens allowed (%link%, %title%, %modified%).
	 *
	 * @return array<string,array>
	 */
	public static function page_graph(): array {
		$schema = (array) ( self::data()['schema'] ?? array() );
		$org    = self::organization();
		$site   = self::website();
		$pool   = array();
		if ( $org ) {
			$org                                            = array_merge( array( '@type' => 'Organization' ), $org );
			$pool[ (string) ( $org['@id'] ?? 'business' ) ] = array( 'business', $org );
		}
		if ( $site ) {
			$pool[ (string) ( $site['@id'] ?? 'website' ) ] = array( 'website', $site );
		}
		$people = self::people();
		foreach ( $people as $key => $node ) {
			$pool[ (string) $node['@id'] ] = array( $key, $node );
		}
		$extra = self::nodes();
		foreach ( $extra as $key => $node ) {
			$pool[ (string) ( $node['@id'] ?? $key ) ] = array( $key, $node );
		}

		$graph = array();
		$path  = self::current_path();
		$keys  = ( is_search() || is_404() ) ? self::special_keys() : array_merge( self::special_keys(), array( $path ) );
		foreach ( (array) ( $schema['print'] ?? array() ) as $what => $where ) {
			if ( true !== $where && ! ( is_array( $where ) && array_intersect( array_map( array( __CLASS__, 'print_key' ), $where ), $keys ) ) ) {
				continue;
			}
			if ( 'organization' === $what && $org ) {
				$graph['business'] = $org;
			} elseif ( 'website' === $what && $site ) {
				$graph['website'] = $site;
			} elseif ( 'people' === $what ) {
				$graph = array_merge( $graph, $people );
			} elseif ( 'nodes' === $what ) {
				$graph = array_merge( $graph, $extra );
			}
		}

		$page = self::page_nodes();
		if ( ! $page && is_singular() && ! empty( $schema['fallback'] ) && is_array( $schema['fallback'] ) ) {
			$page = isset( $schema['fallback']['@type'] ) ? array( $schema['fallback'] ) : array_values( $schema['fallback'] );
		}
		foreach ( $page as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( self::is_reference( $node ) ) {
				$ref = self::resolve( (string) $node['@id'] );
				if ( isset( $pool[ $ref ] ) ) {
					list( $key, $full ) = $pool[ $ref ];
					unset( $graph[ $key ] );
					$graph[ $key ] = $full;
					continue;
				}
			}
			$node = self::faq_from_page( self::tokens_deep( self::resolve_node( $node ) ) );
			$id   = (string) ( $node['@id'] ?? '' );
			$key  = 'setup_page_' . $i;
			if ( '' !== $id && isset( $pool[ $id ] ) ) {
				$key = $pool[ $id ][0];
				unset( $graph[ $key ] ); // The page's own copy wins, in the page's order.
			}
			$graph[ $key ] = $node;
		}
		return $graph;
	}

	private static function print_key( $key ): string {
		$key = (string) $key;
		return ':' === substr( $key, 0, 1 ) ? $key : self::normalize_path( $key );
	}

	/**
	 * The script blocks to print. pages[url].schema_blocks lays the nodes out the
	 * way the theme printed them: 0 is one node in its own block with its own
	 * @context, n is one @graph block of the next n nodes; nodes left over go in
	 * a last block. Without it, pages mode prints one node as a single block and
	 * several as one @graph; graph mode always prints one @graph.
	 *
	 * @param array<int|string,array> $graph
	 * @return array<int,array>
	 */
	public static function blocks( array $graph ): array {
		$nodes  = array_values( $graph );
		$layout = self::active() ? (array) self::page( 'schema_blocks' ) : array();
		$layout = array_values( array_filter( $layout, 'is_numeric' ) );
		if ( ! $layout ) {
			$layout = ( self::exact() && 1 === count( $nodes ) ) ? array( 0 ) : array( count( $nodes ) );
		}
		$blocks = array();
		$i      = 0;
		foreach ( $layout as $size ) {
			$size = (int) $size;
			if ( 0 === $size && isset( $nodes[ $i ] ) ) {
				$blocks[] = array_merge( array( '@context' => 'https://schema.org' ), $nodes[ $i ] );
				++$i;
			} elseif ( $size > 0 && $i < count( $nodes ) ) {
				$blocks[] = array(
					'@context' => 'https://schema.org',
					'@graph'   => array_slice( $nodes, $i, $size ),
				);
				$i       += $size;
			}
		}
		if ( $i < count( $nodes ) ) {
			$rest     = array_slice( $nodes, $i );
			$blocks[] = 1 === count( $rest ) ? array_merge( array( '@context' => 'https://schema.org' ), $rest[0] ) : array(
				'@context' => 'https://schema.org',
				'@graph'   => $rest,
			);
		}
		return $blocks;
	}

	/**
	 * FAQ follows page edits: a FAQPage node (at any depth) takes its questions
	 * from the page's editable content (optima_core_editable_faq()) when that
	 * has the same number of questions, the way the themes did.
	 */
	public static function faq_from_page( array $node ): array {
		if ( ! function_exists( 'optima_core_editable_faq' ) ) {
			return $node;
		}
		$types = (array) ( $node['@type'] ?? array() );
		if ( ! in_array( 'FAQPage', $types, true ) || empty( $node['mainEntity'] ) ) {
			foreach ( $node as $key => $value ) {
				if ( is_array( $value ) ) {
					$node[ $key ] = self::faq_from_page( $value );
				}
			}
			return $node;
		}
		$faq = optima_core_editable_faq();
		if ( $faq && count( $faq ) === count( (array) $node['mainEntity'] ) ) {
			$node['mainEntity'] = $faq;
		}
		return $node;
	}

	/* ---- Legacy JSON-LD in the head ------------------------------------------- */

	/** The attribute strings that mark old injected JSON-LD (seo.schema.strip_inline_markers). */
	private static function strip_markers(): array {
		if ( ! self::active() ) {
			return array();
		}
		$markers = self::data()['schema']['strip_inline_markers'] ?? array();
		return array_values( array_filter( array_map( 'strval', (array) $markers ) ) );
	}

	public static function strip_start(): void {
		if ( self::strip_markers() ) {
			ob_start();
			$GLOBALS['optima_core_strip_level'] = ob_get_level();
		}
	}

	/**
	 * Drop every ld+json script in the head whose tag carries all the markers
	 * (for example an old must-use plugin's blocks marked data-no-minify="1"
	 * data-cfasync="false"), so only Core's graph describes the site.
	 */
	public static function strip_end(): void {
		$level = (int) ( $GLOBALS['optima_core_strip_level'] ?? 0 );
		if ( ! $level || ob_get_level() !== $level ) {
			return;
		}
		unset( $GLOBALS['optima_core_strip_level'] );
		$head    = (string) ob_get_clean();
		$markers = self::strip_markers();
		$out     = preg_replace_callback(
			'#<script\b([^>]*)>.*?</script>\s*#is',
			static function ( $m ) use ( $markers ) {
				if ( false === stripos( $m[1], 'application/ld+json' ) ) {
					return $m[0];
				}
				foreach ( $markers as $marker ) {
					if ( false === strpos( $m[1], $marker ) ) {
						return $m[0];
					}
				}
				return '';
			},
			$head
		);
		echo null === $out ? $head : $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the head WordPress already built.
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
