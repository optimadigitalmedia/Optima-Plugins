<?php
/**
 * The document <head> SEO output: title, meta description, canonical, Open
 * Graph, and Twitter cards, plus robots control through the core `wp_robots`
 * filter. Optima Schema owns the JSON-LD; this class owns everything else in the
 * head so the two never collide.
 *
 * @package Optima_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_SEO_Head {

	public static function init() {
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 20 );
		add_action( 'wp', array( __CLASS__, 'replace_core_canonical' ) );
		add_action( 'wp_head', array( __CLASS__, 'output' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/**
	 * We emit canonical for every context ourselves, so drop core's
	 * singular-only version to avoid a duplicate tag.
	 */
	public static function replace_core_canonical() {
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Use the per-page SEO title when set; otherwise leave WordPress's default.
	 */
	public static function document_title( $title ) {
		if ( is_singular() ) {
			$custom = get_post_meta( get_queried_object_id(), OPTIMA_SEO_META_TITLE, true );
			if ( '' !== $custom ) {
				return $custom;
			}
		}
		// The theme's per-URL title from optima-setup.json (seo.pages), used as written.
		// A title that uses %page% is applied once WordPress has built that part (Optima_SEO_Setup::format_title).
		// A title naming %title% (itself) is ignored: WordPress builds the title instead.
		$setup = (string) Optima_SEO_Setup::page( 'title' );
		if ( '' !== $setup && false === strpos( $setup, '%page%' ) && false === strpos( $setup, '%title%' ) ) {
			return Optima_SEO_Setup::tokens( $setup );
		}
		return $title;
	}

	/**
	 * Some Optima themes write their own title, description, canonical and share
	 * tags (Optima Digital's optima_head(), Recon's recon_head()). Printing ours too
	 * doubles every tag, and Facebook and LinkedIn read the first og:image, so
	 * the wrong share image wins. Step aside for them. A theme can also say so
	 * with add_theme_support( 'optima-seo-head' ), or a site with the
	 * optima_core_seo_head filter.
	 */
	public static function theme_handles_head(): bool {
		$theme = current_theme_supports( 'optima-seo-head' ) || function_exists( 'optima_head' ) || function_exists( 'recon_head' );
		return ! (bool) apply_filters( 'optima_core_seo_head', ! $theme );
	}

	public static function output() {
		if ( self::theme_handles_head() ) {
			return;
		}
		$description = self::description();
		$canonical   = self::canonical_url();
		$title       = wp_get_document_title();
		$site_name   = (string) Optima_SEO_Setup::site( 'site_name' );
		$site_name   = '' !== $site_name ? $site_name : get_bloginfo( 'name' );
		$image       = self::image();
		$is_article  = is_singular( 'post' );
		$og_type     = (string) Optima_SEO_Setup::page( 'og_type' );
		$og_type     = '' !== $og_type ? $og_type : (string) Optima_SEO_Setup::site( $is_article ? 'og_type_post' : 'og_type' );
		$og_type     = '' !== $og_type ? $og_type : ( $is_article ? 'article' : 'website' );
		// The theme's share title and text, unless an editor set this page's own in the SEO box.
		$box_id      = is_singular() ? get_queried_object_id() : 0;
		$og_title    = $box_id && '' !== (string) get_post_meta( $box_id, OPTIMA_SEO_META_TITLE, true ) ? '' : (string) Optima_SEO_Setup::page( 'og_title' );
		$og_title    = '' !== $og_title ? Optima_SEO_Setup::tokens( $og_title ) : $title;
		$og_desc     = $box_id && '' !== (string) get_post_meta( $box_id, OPTIMA_SEO_META_DESC, true ) ? '' : (string) Optima_SEO_Setup::page( 'og_description' );
		$og_desc     = '' !== $og_desc ? Optima_SEO_Setup::tokens( $og_desc ) : $description;
		$og_url      = $canonical;
		if ( Optima_SEO_Setup::page_has( 'og_url' ) ) {
			$given  = Optima_SEO_Setup::page( 'og_url' );
			$og_url = is_string( $given ) && '' !== $given ? Optima_SEO_Setup::resolve( Optima_SEO_Setup::tokens( $given ) ) : '';
		}
		$locale      = (string) Optima_SEO_Setup::site( 'locale' );
		$locale      = '' !== $locale ? $locale : get_locale();

		$tags = array();

		if ( '' !== $description ) {
			$tags[] = sprintf( '<meta name="description" content="%s" />', esc_attr( $description ) );
		}
		if ( $canonical ) {
			$tags[] = sprintf( '<link rel="canonical" href="%s" />', esc_url( $canonical ) );
		}

		// Open Graph.
		if ( Optima_SEO_Setup::prints( 'og_locale' ) ) {
			$tags[] = sprintf( '<meta property="og:locale" content="%s" />', esc_attr( $locale ) );
		}
		$tags[] = sprintf( '<meta property="og:type" content="%s" />', esc_attr( $og_type ) );
		$tags[] = sprintf( '<meta property="og:title" content="%s" />', esc_attr( $og_title ) );
		if ( '' !== $og_desc ) {
			$tags[] = sprintf( '<meta property="og:description" content="%s" />', esc_attr( $og_desc ) );
		}
		if ( $og_url ) {
			$tags[] = sprintf( '<meta property="og:url" content="%s" />', esc_url( $og_url ) );
		}
		$tags[] = sprintf( '<meta property="og:site_name" content="%s" />', esc_attr( $site_name ) );
		if ( $image ) {
			$tags[] = sprintf( '<meta property="og:image" content="%s" />', esc_url( $image['url'] ) );
			if ( ! empty( $image['width'] ) && ! empty( $image['height'] ) && false !== Optima_SEO_Setup::prints( 'og_image_size' ) ) {
				$tags[] = sprintf( '<meta property="og:image:width" content="%d" />', (int) $image['width'] );
				$tags[] = sprintf( '<meta property="og:image:height" content="%d" />', (int) $image['height'] );
			}
			if ( ! empty( $image['alt'] ) ) {
				$tags[] = sprintf( '<meta property="og:image:alt" content="%s" />', esc_attr( $image['alt'] ) );
			}
		}
		if ( $is_article && Optima_SEO_Setup::prints( 'article_times' ) ) {
			$id     = get_queried_object_id();
			$tags[] = sprintf( '<meta property="article:published_time" content="%s" />', esc_attr( get_the_date( 'c', $id ) ) );
			$tags[] = sprintf( '<meta property="article:modified_time" content="%s" />', esc_attr( get_the_modified_date( 'c', $id ) ) );
		}

		// Twitter.
		$card = (string) Optima_SEO_Setup::page( 'twitter' );
		$card = '' !== $card ? $card : (string) Optima_SEO_Setup::site( 'twitter' );
		$card = '' !== $card ? $card : ( $image ? 'summary_large_image' : 'summary' );
		$tags[] = sprintf( '<meta name="twitter:card" content="%s" />', esc_attr( $card ) );
		if ( Optima_SEO_Setup::prints( 'twitter_title' ) ) {
			$tags[] = sprintf( '<meta name="twitter:title" content="%s" />', esc_attr( $og_title ) );
		}
		if ( '' !== $og_desc && Optima_SEO_Setup::prints( 'twitter_description' ) ) {
			$tags[] = sprintf( '<meta name="twitter:description" content="%s" />', esc_attr( $og_desc ) );
		}
		if ( $image && Optima_SEO_Setup::prints( 'twitter_image' ) ) {
			$tags[] = sprintf( '<meta name="twitter:image" content="%s" />', esc_url( $image['url'] ) );
		}

		// Any other tags the theme printed (theme-color, twitter:site, fb:app_id), site-wide then per URL.
		$extra = array_merge( (array) Optima_SEO_Setup::site( 'meta' ), (array) Optima_SEO_Setup::page( 'meta' ) );
		foreach ( $extra as $meta ) {
			if ( ! is_array( $meta ) || ! isset( $meta['content'] ) ) {
				continue;
			}
			$attr = isset( $meta['property'] ) ? 'property' : 'name';
			$key  = (string) ( $meta[ $attr ] ?? '' );
			if ( '' !== $key ) {
				$tags[] = sprintf( '<meta %s="%s" content="%s" />', $attr, esc_attr( $key ), esc_attr( (string) $meta['content'] ) );
			}
		}

		echo "\n" . implode( "\n", $tags ) . "\n";
	}

	/**
	 * Flip the current page to noindex where appropriate. Filtering core's
	 * `wp_robots` keeps a single, correct robots meta tag.
	 */
	public static function robots( $robots ) {
		if ( is_search() || is_404() ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		// The theme's robots rules from optima-setup.json: site-wide, then this URL's.
		foreach ( array( (string) Optima_SEO_Setup::site( 'robots' ), (string) Optima_SEO_Setup::page( 'robots' ) ) as $rule ) {
			if ( '' !== $rule ) {
				$robots = Optima_SEO_Setup::apply_robots( (array) $robots, $rule );
			}
		}
		if ( is_singular() && get_post_meta( get_queried_object_id(), OPTIMA_SEO_META_NOINDEX, true ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		return Optima_SEO_Setup::finish_robots( (array) $robots );
	}

	private static function description() {
		if ( is_singular() ) {
			$id   = get_queried_object_id();
			$desc = get_post_meta( $id, OPTIMA_SEO_META_DESC, true );
			if ( '' !== $desc ) {
				return $desc;
			}
		}
		// The theme's per-URL description (optima-setup.json). On the homepage an
		// editor-set description (Optima Build -> SEO) still wins over it.
		$setup = (string) Optima_SEO_Setup::page( 'description' );
		if ( '' !== $setup ) {
			$home = is_front_page() && class_exists( 'Optima_Core_SEO_Settings' ) ? Optima_Core_SEO_Settings::value( 'home_description' ) : '';
			return '' !== $home ? $home : Optima_SEO_Setup::tokens( $setup );
		}
		// The theme said this URL has no description (false or "").
		if ( Optima_SEO_Setup::page_has( 'description' ) ) {
			return '';
		}
		if ( is_singular() ) {
			$id = get_queried_object_id();
			if ( has_excerpt( $id ) ) {
				return wp_strip_all_tags( get_the_excerpt( $id ) );
			}
			$post = get_post( $id );
			if ( $post ) {
				$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
				$content = trim( preg_replace( '/\s+/', ' ', $content ) );
				if ( '' !== $content ) {
					return wp_trim_words( $content, 30, '' );
				}
			}
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term_desc = wp_strip_all_tags( term_description() );
			if ( '' !== $term_desc ) {
				return $term_desc;
			}
		}
		if ( is_front_page() && class_exists( 'Optima_Core_SEO_Settings' ) ) {
			$home = Optima_Core_SEO_Settings::value( 'home_description' );
			if ( '' !== $home ) {
				return $home;
			}
		}
		// A theme that names default_description, even as "" or false, never falls back to the tagline.
		if ( Optima_SEO_Setup::site_has( 'default_description' ) ) {
			return Optima_SEO_Setup::tokens( (string) Optima_SEO_Setup::site( 'default_description' ) );
		}
		return get_bloginfo( 'description' );
	}

	/** The canonical address for this request ('' when the theme's data says there is none). */
	public static function canonical_url() {
		if ( is_singular() ) {
			$id       = get_queried_object_id();
			$override = get_post_meta( $id, OPTIMA_SEO_META_CANONICAL, true );
			if ( '' !== $override ) {
				return $override;
			}
		}
		if ( Optima_SEO_Setup::page_has( 'canonical' ) ) {
			$setup = Optima_SEO_Setup::page( 'canonical' );
			if ( false === $setup ) {
				return '';
			}
			// %link% is this very address, so a canonical naming it means the default below.
			// Resolving it here would call canonical_url() again, without end.
			$setup = (string) $setup;
			if ( '' !== $setup && false === strpos( $setup, '%link%' ) ) {
				return Optima_SEO_Setup::resolve( Optima_SEO_Setup::tokens( $setup ) );
			}
		}
		if ( is_singular() ) {
			return get_permalink( get_queried_object_id() );
		}
		if ( is_front_page() ) {
			return home_url( '/' );
		}
		if ( is_home() ) {
			$blog = (int) get_option( 'page_for_posts' );
			return $blog ? get_permalink( $blog ) : home_url( '/' );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object() );
			return is_wp_error( $link ) ? home_url( '/' ) : $link;
		}
		if ( is_post_type_archive() ) {
			$obj  = get_queried_object();
			$name = ( $obj instanceof WP_Post_Type ) ? $obj->name : '';
			$link = $name ? get_post_type_archive_link( $name ) : false;
			return $link ? $link : home_url( '/' );
		}
		return home_url( '/' );
	}

	/**
	 * The share image: the theme's per-URL image (optima-setup.json), the
	 * featured image, the editor's default (Optima Build -> SEO), the theme's
	 * default, then the site icon.
	 *
	 * @return array{url:string,width?:int,height?:int,alt?:string}|array{}
	 */
	private static function image() {
		$setup = (string) Optima_SEO_Setup::page( 'image' );
		if ( '' !== $setup ) {
			return self::setup_image( $setup, 'page' );
		}
		if ( is_singular() && has_post_thumbnail( get_queried_object_id() ) ) {
			$thumb = (int) get_post_thumbnail_id( get_queried_object_id() );
			$src   = wp_get_attachment_image_src( $thumb, 'full' );
			if ( $src ) {
				return array_filter(
					array(
						'url'    => (string) $src[0],
						'width'  => (int) $src[1],
						'height' => (int) $src[2],
						'alt'    => (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ),
					)
				);
			}
		}
		if ( class_exists( 'Optima_Core_SEO_Settings' ) ) {
			$default = Optima_Core_SEO_Settings::value( 'default_image' );
			if ( '' !== $default ) {
				return array( 'url' => $default );
			}
		}
		$setup = (string) Optima_SEO_Setup::site( 'default_image' );
		if ( '' !== $setup ) {
			return self::setup_image( $setup, 'site' );
		}
		$icon = get_site_icon_url();
		return $icon ? array( 'url' => $icon ) : array();
	}

	/** A share image named in optima-setup.json, with its size (given, or read from the theme file) and alt text. */
	private static function setup_image( string $value, string $level ): array {
		$get    = 'page' === $level ? array( 'Optima_SEO_Setup', 'page' ) : array( 'Optima_SEO_Setup', 'site' );
		$width  = (int) call_user_func( $get, 'image_width' );
		$height = (int) call_user_func( $get, 'image_height' );
		if ( ( ! $width || ! $height ) && 'auto' === Optima_SEO_Setup::prints( 'og_image_size' ) ) {
			$size = Optima_SEO_Setup::theme_image_size( $value );
			if ( $size ) {
				list( $width, $height ) = $size;
			}
		}
		return array_filter(
			array(
				'url'    => Optima_SEO_Setup::resolve( $value ),
				'width'  => $width,
				'height' => $height,
				'alt'    => (string) call_user_func( $get, 'image_alt' ),
			)
		);
	}
}
