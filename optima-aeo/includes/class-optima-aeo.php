<?php
/**
 * The site-wide part: who may crawl the site for AI search, and the plain-text
 * guide at /llms.txt.
 *
 * Two kinds of AI crawler, two switches:
 * - Search and answer crawlers (OAI-SearchBot, ChatGPT-User, Claude-SearchBot,
 *   PerplexityBot...) fetch pages to answer someone's question right now, and
 *   are how a site gets cited. On by default.
 * - Training crawlers (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended,
 *   CCBot) collect pages to train future models. Also on by default, because
 *   being known to the model helps the business get named; an owner who
 *   prefers otherwise can switch it off without losing AI search.
 *
 * Nothing here runs on staging: robots.txt and /llms.txt are only changed on
 * the live, public site (see is_live()).
 *
 * @package Optima_AEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_AEO {

	const OPTION = 'optima_aeo';

	const SEARCH_BOTS = array(
		'OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User',
		'PerplexityBot', 'Perplexity-User', 'DuckAssistBot',
	);

	const TRAINING_BOTS = array(
		'GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot',
	);

	public static function boot(): void {
		add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 20, 2 );
		// Last of all, so every group (WordPress's, an SEO plugin's, ours) is in place first.
		add_filter( 'robots_txt', array( __CLASS__, 'content_signal' ), PHP_INT_MAX, 2 );
		add_action( 'init', array( __CLASS__, 'serve_llms' ), 1 );
	}

	/** @return array{search:bool, training:bool, llms:bool, indexnow:bool, intro:string, pages:int[], posts:int} */
	public static function settings(): array {
		$s = (array) get_option( self::OPTION, array() );
		return array(
			'search'   => ! isset( $s['search'] ) || ! empty( $s['search'] ),
			'training' => ! isset( $s['training'] ) || ! empty( $s['training'] ),
			'llms'     => ! isset( $s['llms'] ) || ! empty( $s['llms'] ),
			'indexnow' => ! isset( $s['indexnow'] ) || ! empty( $s['indexnow'] ),
			'intro'    => (string) ( $s['intro'] ?? '' ),
			'pages'    => array_values( array_filter( array_map( 'absint', (array) ( $s['pages'] ?? array() ) ) ) ),
			'posts'    => isset( $s['posts'] ) ? max( 0, min( 50, (int) $s['posts'] ) ) : 20,
		);
	}

	/**
	 * The live, public site: search engines allowed, not a staging or local
	 * host. Filter `optima_aeo_is_live` to override.
	 */
	public static function is_live(): bool {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$live = '1' === (string) get_option( 'blog_public' )
			&& in_array( wp_get_environment_type(), array( 'production' ), true )
			&& ! preg_match( '/(wpengine\.com|wpenginepowered\.com|staging|\.local$|\.test$|localhost|^127\.|^10\.|^192\.168\.)/i', $host );
		return (bool) apply_filters( 'optima_aeo_is_live', $live );
	}

	/**
	 * Add a group per AI crawler. A crawler that finds its own group ignores
	 * the "*" group, so each group repeats the admin rules.
	 */
	public static function robots( $output, $public ) {
		if ( ! $public || ! self::is_live() ) {
			return $output;
		}
		$s     = self::settings();
		$admin = "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$out   = rtrim( (string) $output ) . "\n";

		$groups = array(
			array( self::SEARCH_BOTS, $s['search'], 'AI search and answer engines' ),
			array( self::TRAINING_BOTS, $s['training'], 'AI model training' ),
		);
		foreach ( $groups as list( $bots, $allowed, $label ) ) {
			$out .= "\n# " . $label . ': ' . ( $allowed ? 'allowed' : 'not allowed' ) . "\n";
			foreach ( $bots as $bot ) {
				$out .= 'User-agent: ' . $bot . "\n";
			}
			$out .= $allowed ? $admin . "Allow: /\n" : "Disallow: /\n";
		}
		if ( $s['llms'] ) {
			$out .= "\n# Plain-text guide for AI tools: " . home_url( '/llms.txt' ) . "\n";
		}
		return $out;
	}

	/**
	 * The Content-Signal line (contentsignals.org): how the site's pages may be
	 * used once a crawler has them. search = a search index, ai-input = quoted in
	 * an AI answer, ai-train = training a model. Values follow the two switches.
	 *
	 * A crawler only reads the most specific group that names it, so the line
	 * goes into every group, not only "*". If any Content-Signal line is already
	 * there (a file, another plugin), it is left alone.
	 */
	public static function content_signal( $output, $public ) {
		$output = (string) $output;
		if ( ! $public || ! self::is_live() || preg_match( '/^\s*content-signal\s*:/im', $output ) ) {
			return $output;
		}
		$s     = self::settings();
		$value = (string) apply_filters(
			'optima_aeo_content_signal',
			'search=yes, ai-input=' . ( $s['search'] ? 'yes' : 'no' ) . ', ai-train=' . ( $s['training'] ? 'yes' : 'no' )
		);
		return '' === $value ? $output : self::add_signal( $output, $value );
	}

	/** Put "Content-Signal: $value" after each run of User-agent lines. Pure, for tests. */
	public static function add_signal( string $output, string $value ): string {
		$line    = 'Content-Signal: ' . $value . "\n";
		$count   = 0;
		$patched = preg_replace( '/((?:^[ \t]*user-agent[ \t]*:[^\r\n]*\r?\n)+)/im', '$1' . $line, $output, -1, $count );
		if ( null !== $patched && $count > 0 ) {
			return $patched;
		}
		return ( '' === trim( $output ) ? '' : rtrim( $output ) . "\n\n" ) . "User-agent: *\n" . $line;
	}

	public static function serve_llms(): void {
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( ( '' === $home ? '' : $home . '/' ) . 'llms.txt' !== $path || ! self::settings()['llms'] ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		if ( ! self::is_live() ) {
			header( 'X-Robots-Tag: noindex' );
		}
		echo self::llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text.
		exit;
	}

	/** Business details from Optima Core, when it is running. */
	private static function business(): array {
		return class_exists( 'Optima_Core_Business' ) ? (array) Optima_Core_Business::get() : array();
	}

	/** The short description AI tools read first. */
	public static function summary(): string {
		$s = self::settings();
		if ( '' !== trim( $s['intro'] ) ) {
			return trim( $s['intro'] );
		}
		$b     = self::business();
		$name  = wp_specialchars_decode( (string) ( $b['name'] ?? '' ) ?: get_bloginfo( 'name' ), ENT_QUOTES );
		$where = trim( implode( ', ', array_filter( array( $b['city'] ?? '', $b['region'] ?? '' ) ) ) );
		$tag   = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );
		$line  = $name . ( $where ? ' is based in ' . $where . '.' : '.' );
		return trim( $line . ( $tag ? ' ' . $tag . ( preg_match( '/[.!?]$/', $tag ) ? '' : '.' ) : '' ) );
	}

	private static function noindexed( int $id ): bool {
		return defined( 'OPTIMA_SEO_META_NOINDEX' ) && '1' === (string) get_post_meta( $id, OPTIMA_SEO_META_NOINDEX, true );
	}

	/** A path below the site's home, with a trailing slash ("/", "/about/"). */
	private static function site_path( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		$path = '/' . ltrim( $path, '/' );
		return '/' === $path ? '/' : trailingslashit( $path );
	}

	/**
	 * The theme's seo section entry (Optima Core) for this address: its exact
	 * path entry, or the longest "/prefix/*" pattern. Null when there is none.
	 */
	private static function setup_entry( string $url ): ?array {
		if ( ! class_exists( 'Optima_SEO_Setup' ) || ! method_exists( 'Optima_SEO_Setup', 'active' ) || ! Optima_SEO_Setup::active() ) {
			return null;
		}
		$pages = (array) ( Optima_SEO_Setup::data()['pages'] ?? array() );
		$path  = self::site_path( $url );
		$entry = isset( $pages[ $path ] ) && is_array( $pages[ $path ] ) ? $pages[ $path ] : null;
		if ( null === $entry ) {
			$best = -1;
			foreach ( $pages as $key => $value ) {
				$key = (string) $key;
				if ( '*' !== substr( $key, -1 ) || ! is_array( $value ) ) {
					continue;
				}
				$prefix = substr( $key, 0, -1 );
				if ( 0 === strpos( $path, $prefix ) && strlen( $prefix ) > $best && $path !== $prefix ) {
					$best  = strlen( $prefix );
					$entry = $value;
				}
			}
		}
		return is_array( $entry ) ? $entry : null;
	}

	/** True when the theme's seo section marks this address noindex. */
	private static function setup_noindex( string $url ): bool {
		$entry  = self::setup_entry( $url );
		$robots = is_array( $entry ) ? strtolower( (string) ( $entry['robots'] ?? '' ) ) : '';
		return '' !== $robots && (bool) preg_match( '/(^|[\s,])noindex([\s,]|$)/', $robots );
	}

	/**
	 * Leftover pages a site move carries that are never worth listing: a page
	 * kept as a 404 ("404", "404-2", "page-not-found"), a copy ("about-2",
	 * "contact-copy"), a second "home" that is not the front page, and a page
	 * under a trashed parent. Filter `optima_aeo_llms_leftover` to change it.
	 */
	public static function leftover( WP_Post $p ): bool {
		$slug  = (string) $p->post_name;
		$front = (int) get_option( 'page_on_front' );
		$hit   = (bool) preg_match( '/^(404|page-not-found|not-found)(-|$)/', $slug )
			|| (bool) preg_match( '/-(2|copy(-\d+)?)$/', $slug )
			|| ( 'page' === $p->post_type && 'home' === $slug && (int) $p->ID !== $front );
		if ( ! $hit && $p->post_parent ) {
			foreach ( get_post_ancestors( $p ) as $parent ) {
				if ( 'trash' === get_post_status( $parent ) ) {
					$hit = true;
					break;
				}
			}
		}
		return (bool) apply_filters( 'optima_aeo_llms_leftover', $hit, $p );
	}

	/**
	 * True when Optima Redirects holds a stored redirect for this address, so a
	 * visitor asking for it is sent somewhere else. Reads the stored rules only;
	 * no request is made.
	 */
	private static function redirected( string $url ): bool {
		if ( ! class_exists( 'Optima_Redirects' ) || ! class_exists( 'Optima_Redirects_Store' )
			|| ! method_exists( 'Optima_Redirects', 'normalize_source' ) || ! method_exists( 'Optima_Redirects_Store', 'find' ) ) {
			return false;
		}
		if ( method_exists( 'Optima_Redirects_Store', 'count' ) && Optima_Redirects_Store::count() < 1 ) {
			return false;
		}
		$source = Optima_Redirects::normalize_source( $url );
		if ( '' === $source || '/' === $source ) {
			return false;
		}
		$row = Optima_Redirects_Store::find( $source );
		if ( ! $row ) {
			return false;
		}
		// A rule that points back at the same address does not send anyone away.
		$target = (string) ( $row->target ?? '' );
		return ! ( '' !== $target && '/' === $target[0] && Optima_Redirects::normalize_source( $target ) === $source );
	}

	/**
	 * The address /llms.txt lists for a post, or '' when it should not be
	 * listed: not published, password protected, not public, a leftover page
	 * (see leftover()), noindexed (the post's own switch or the theme's seo
	 * section), canonical to another address, or redirected by Optima
	 * Redirects. Only stored settings are read.
	 *
	 * Filter `optima_aeo_llms_url` to drop ('') or change an address, for
	 * example when a theme redirects a page on its own.
	 */
	public static function listed_url( $post ): string {
		$p = get_post( $post );
		if ( ! $p instanceof WP_Post || 'publish' !== $p->post_status || '' !== (string) $p->post_password ) {
			return '';
		}
		if ( function_exists( 'is_post_publicly_viewable' ) && ! is_post_publicly_viewable( $p ) ) {
			return '';
		}
		if ( self::leftover( $p ) ) {
			return '';
		}
		$url = (string) get_permalink( $p );
		if ( '' === $url || self::noindexed( (int) $p->ID ) || self::setup_noindex( $url ) ) {
			$url = '';
		}
		if ( '' !== $url && defined( 'OPTIMA_SEO_META_CANONICAL' ) ) {
			$canonical = trim( (string) get_post_meta( (int) $p->ID, OPTIMA_SEO_META_CANONICAL, true ) );
			$canonical = '' !== $canonical && '/' === $canonical[0] && '/' !== ( $canonical[1] ?? '' ) ? home_url( $canonical ) : $canonical;
			if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( $url ) ) {
				$url = '';
			}
		}
		if ( '' !== $url && self::redirected( $url ) ) {
			$url = '';
		}
		return (string) apply_filters( 'optima_aeo_llms_url', $url, $p );
	}

	/**
	 * The pages listed under "Pages": the chosen ones, or by default the home
	 * page, then the pages in the site's menus (main menu first, in menu order),
	 * then every other top-level page by page order and title, each followed by
	 * its children. Pages that are not listed (leftovers, noindexed, forwarded)
	 * are dropped before the cut to 30, so a page like /404-2/ never pushes a
	 * real page out or shows ahead of one.
	 */
	public static function featured_pages(): array {
		$s = self::settings();
		if ( $s['pages'] ) {
			$pages = get_posts( array( 'post_type' => 'page', 'post__in' => $s['pages'], 'orderby' => 'post__in', 'numberposts' => 30, 'post_status' => 'publish' ) );
		} else {
			$all   = get_pages( array( 'sort_column' => 'menu_order,post_title' ) );
			$front = (int) get_option( 'page_on_front' );
			$skip  = '/^(privacy|terms|cookie|disclaimer|sample-page|thank-you|thanks|cart|checkout|my-account)/';
			$keep  = array();
			foreach ( (array) $all as $p ) {
				if ( $p instanceof WP_Post && ! preg_match( $skip, $p->post_name ) && '' !== self::listed_url( $p ) ) {
					$keep[ (int) $p->ID ] = $p;
				}
			}
			$order = isset( $keep[ $front ] ) ? array( $front ) : array();
			foreach ( self::menu_page_ids() as $id ) {
				if ( isset( $keep[ $id ] ) ) {
					$order[] = $id;
				}
			}
			foreach ( $keep as $id => $p ) {
				if ( $p->post_parent || $id === $front ) {
					continue;
				}
				$order[] = $id;
				foreach ( $keep as $cid => $c ) {
					if ( (int) $c->post_parent === $id ) {
						$order[] = $cid;
					}
				}
			}
			$order = array_slice( array_values( array_unique( $order ) ), 0, 30 );
			$pages = array_map( static fn( $id ) => $keep[ $id ], $order );
		}
		$skip_ids = array( (int) get_option( 'wp_page_for_privacy_policy' ) );
		return array_values( array_filter( (array) apply_filters( 'optima_aeo_llms_pages', $pages ), static fn( $p ) => $p instanceof WP_Post && ! in_array( (int) $p->ID, $skip_ids, true ) && '' !== self::listed_url( $p ) ) );
	}

	/**
	 * Page IDs in the menus the theme shows (its menu locations), the main menu
	 * first ("primary", "main", "header"), footer menus last, each in menu order.
	 *
	 * @return int[]
	 */
	private static function menu_page_ids(): array {
		if ( ! function_exists( 'get_nav_menu_locations' ) ) {
			return array();
		}
		$locations = array_filter( array_map( 'intval', (array) get_nav_menu_locations() ) );
		$rank      = static fn( $loc ) => preg_match( '/primary|main|header/i', (string) $loc ) ? 0 : ( preg_match( '/footer/i', (string) $loc ) ? 2 : 1 );
		uksort( $locations, static fn( $a, $b ) => $rank( $a ) <=> $rank( $b ) );
		$ids = array();
		foreach ( array_unique( $locations ) as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
				if ( is_object( $item ) && 'post_type' === ( $item->type ?? '' ) && 'page' === ( $item->object ?? '' ) ) {
					$ids[] = (int) $item->object_id;
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/** Text without HTML entities (WordPress curls quotes into &#8220; and friends). */
	private static function plain( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * The words after a page's link, from what the page says today, in the order
	 * Optima Core picks its meta description: the SEO box's description, the
	 * homepage description (Search and AI, SEO) on the front page, the theme's
	 * description for that address (optima-setup.json seo.pages), the excerpt,
	 * then the opening of the page's own text. An imported excerpt is only used
	 * when no description is set.
	 */
	private static function describe( WP_Post $p ): string {
		$text = defined( 'OPTIMA_SEO_META_DESC' ) ? (string) get_post_meta( (int) $p->ID, OPTIMA_SEO_META_DESC, true ) : '';
		if ( '' === trim( $text ) && (int) $p->ID === (int) get_option( 'page_on_front' ) && class_exists( 'Optima_Core_SEO_Settings' ) ) {
			$text = (string) Optima_Core_SEO_Settings::value( 'home_description' );
		}
		if ( '' === trim( $text ) ) {
			$text = self::setup_description( $p );
		}
		if ( '' === trim( $text ) && has_excerpt( $p ) ) {
			$text = (string) get_the_excerpt( $p );
		}
		if ( '' === trim( $text ) ) {
			$content = (string) $p->post_content;
			$content = function_exists( 'excerpt_remove_blocks' ) ? excerpt_remove_blocks( $content ) : $content;
			$text    = wp_strip_all_tags( strip_shortcodes( $content ) );
		}
		$text = trim( (string) preg_replace( '/\s+/', ' ', self::plain( wp_strip_all_tags( $text ) ) ) );
		return $text ? ': ' . wp_trim_words( $text, 30, '...' ) : '';
	}

	/** The theme's description for this page's address (seo.pages), '' when none or when it needs page tokens. */
	private static function setup_description( WP_Post $p ): string {
		$entry = self::setup_entry( (string) get_permalink( $p ) );
		$desc  = is_array( $entry ) && is_string( $entry['description'] ?? null ) ? $entry['description'] : '';
		if ( '' === $desc ) {
			return '';
		}
		$desc = strtr(
			$desc,
			array(
				'%title%'   => wp_strip_all_tags( get_the_title( $p ) ),
				'%site%'    => (string) get_bloginfo( 'name' ),
				'%tagline%' => (string) get_bloginfo( 'description' ),
			)
		);
		return preg_match( '/%[a-z_]+%/', $desc ) ? '' : $desc;
	}

	public static function llms_txt(): string {
		$b     = self::business();
		$name  = wp_specialchars_decode( (string) ( $b['name'] ?? '' ) ?: get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array( '# ' . $name, '', '> ' . self::summary(), '' );

		$contact = array_filter(
			array(
				$b['phone'] ?? '',
				$b['email'] ?? '',
				trim( implode( ', ', array_filter( array( $b['city'] ?? '', trim( ( $b['region'] ?? '' ) . ' ' . ( $b['postal'] ?? '' ) ) ) ) ) ),
			)
		);
		if ( $contact ) {
			$lines[] = 'Contact: ' . implode( ', ', $contact ) . '.';
			$lines[] = '';
		}

		$pages = self::featured_pages();
		if ( $pages ) {
			$lines[] = '## Pages';
			foreach ( $pages as $p ) {
				$lines[] = '- [' . self::plain( get_the_title( $p ) ) . '](' . self::listed_url( $p ) . ')' . self::describe( $p );
			}
			$lines[] = '';
		}

		$count = self::settings()['posts'];
		$posts = $count ? get_posts( array( 'numberposts' => $count, 'post_status' => 'publish', 'has_password' => false ) ) : array();
		$posts = array_filter( $posts, static fn( $p ) => '' !== self::listed_url( $p ) );
		if ( $posts ) {
			$lines[] = '## Articles';
			foreach ( $posts as $p ) {
				$lines[] = '- [' . self::plain( get_the_title( $p ) ) . '](' . self::listed_url( $p ) . ')' . self::describe( $p );
			}
			$lines[] = '';
		}

		$faq = array();
		foreach ( Optima_AEO_FAQ::pages_with_answers( 30 ) as $id ) {
			$url = self::listed_url( $id );
			if ( '' !== $url ) {
				$faq[ $id ] = $url;
			}
		}
		if ( $faq ) {
			$lines[] = '## Questions answered on this site';
			foreach ( $faq as $id => $url ) {
				foreach ( Optima_AEO_FAQ::get( $id ) as $qa ) {
					$lines[] = '- [' . $qa['q'] . '](' . $url . '#answers)';
				}
			}
			$lines[] = '';
		}

		return (string) apply_filters( 'optima_aeo_llms_txt', implode( "\n", $lines ) );
	}
}
