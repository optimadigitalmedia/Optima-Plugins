<?php
/**
 * Breadcrumbs: Home > parent pages > this page, from the page tree.
 *
 * One trail feeds both the BreadcrumbList node in the schema (every page but
 * the home page) and the visible breadcrumb in the theme's `breadcrumb` slot,
 * so the two always agree. Posts sit under the posts page when the site has
 * one; other post types under their archive; category and tag archives under
 * the posts page. Search results, 404s, author and date archives get none.
 *
 * A password-protected page shows "Private page" in its crumb, never its name.
 * Filter `optima_core_breadcrumbs` changes the trail (a list of name, url).
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Breadcrumbs {

	public static function init() {
		add_filter( 'optima_schema_graph', array( __CLASS__, 'graph' ), 16 );
		add_action( 'wp', array( __CLASS__, 'hook_slot' ) );
	}

	/** @var array|null The trail, built once per request. */
	private static $trail = null;

	/**
	 * The trail for this request, or an empty list (the home page, search, 404).
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	public static function trail() {
		if ( null !== self::$trail ) {
			return self::$trail;
		}
		$items = array();
		if ( ! is_front_page() && ! is_search() && ! is_404() && ! is_author() && ! is_date() ) {
			$items = self::build();
		}
		if ( $items ) {
			array_unshift( $items, array( 'name' => 'Home', 'url' => home_url( '/' ) ) );
		}
		$items = (array) apply_filters( 'optima_core_breadcrumbs', $items );
		self::$trail = count( $items ) > 1 ? array_values( $items ) : array();
		return self::$trail;
	}

	/** The crumbs after Home. */
	private static function build() {
		$items = array();
		$blog  = (int) get_option( 'page_for_posts' );
		$front = (int) get_option( 'page_on_front' );
		$blog  = ( $blog && $blog !== $front && 'publish' === get_post_status( $blog ) ) ? $blog : 0;

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof WP_Post ) {
				return array();
			}
			if ( is_post_type_hierarchical( $post->post_type ) ) {
				foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
					if ( (int) $ancestor !== $front && 'publish' === get_post_status( $ancestor ) ) {
						$items[] = self::post_item( (int) $ancestor );
					}
				}
			} elseif ( 'post' === $post->post_type ) {
				if ( $blog ) {
					$items[] = self::post_item( $blog );
				}
			} else {
				$archive = get_post_type_archive_link( $post->post_type );
				$object  = get_post_type_object( $post->post_type );
				if ( $archive && $object ) {
					$items[] = array( 'name' => wp_strip_all_tags( $object->labels->name ), 'url' => $archive );
				}
			}
			$items[] = self::post_item( (int) $post->ID );
			return $items;
		}
		if ( is_home() && $blog ) {
			return array( self::post_item( $blog ) );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term ) {
				return array();
			}
			if ( $blog && in_array( $term->taxonomy, array( 'category', 'post_tag' ), true ) ) {
				$items[] = self::post_item( $blog );
			}
			foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $parent ) {
				$p = get_term( $parent, $term->taxonomy );
				if ( $p instanceof WP_Term ) {
					$items[] = array( 'name' => $p->name, 'url' => (string) get_term_link( $p ) );
				}
			}
			$items[] = array( 'name' => $term->name, 'url' => (string) get_term_link( $term ) );
			return $items;
		}
		if ( is_post_type_archive() ) {
			$object = get_queried_object();
			if ( $object instanceof WP_Post_Type ) {
				return array( array( 'name' => wp_strip_all_tags( $object->labels->name ), 'url' => (string) get_post_type_archive_link( $object->name ) ) );
			}
		}
		return array();
	}

	private static function post_item( $id ) {
		$private = class_exists( 'Optima_SEO_Head' ) && Optima_SEO_Head::is_private( $id );
		return array(
			'name' => $private ? Optima_SEO_Head::private_title() : wp_strip_all_tags( get_the_title( $id ) ),
			'url'  => (string) get_permalink( $id ),
		);
	}

	/** The address the BreadcrumbList is named after (the last crumb's page). */
	public static function node_id() {
		$trail = self::trail();
		return $trail ? $trail[ count( $trail ) - 1 ]['url'] . '#breadcrumb' : '';
	}

	/**
	 * Add the BreadcrumbList, unless the theme's schema already has one or the
	 * theme lists every node itself (pages mode).
	 */
	public static function graph( $graph ) {
		if ( ! is_array( $graph ) || ! self::trail() ) {
			return $graph;
		}
		if ( class_exists( 'Optima_SEO_Setup' ) && Optima_SEO_Setup::exact() ) {
			return $graph;
		}
		foreach ( $graph as $node ) {
			if ( is_array( $node ) && in_array( 'BreadcrumbList', (array) ( $node['@type'] ?? array() ), true ) ) {
				return $graph;
			}
		}
		$list = array();
		foreach ( self::trail() as $i => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				// Plain text in the schema: no &#8217; or &amp; (upstream 1.10.5).
				'name'     => class_exists( 'Optima_Schema' ) ? Optima_Schema::plain( $item['name'] ) : $item['name'],
				'item'     => $item['url'],
			);
		}
		$graph['breadcrumb'] = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => self::node_id(),
			'itemListElement' => $list,
		);
		return $graph;
	}

	/** Fill the slot only when there is a trail, so optima_slot_has() is false on the home page. */
	public static function hook_slot() {
		if ( ! is_admin() && self::trail() ) {
			add_action( 'optima_slot_breadcrumb', array( __CLASS__, 'slot' ) );
		}
	}

	public static function slot() {
		$trail = self::trail();
		if ( ! $trail ) {
			return;
		}
		wp_enqueue_style( 'optima-core' );
		$last = count( $trail ) - 1;
		echo '<nav class="optima-breadcrumb" aria-label="Breadcrumb"><ol>';
		foreach ( $trail as $i => $item ) {
			if ( $i === $last ) {
				echo '<li><span aria-current="page">' . esc_html( $item['name'] ) . '</span></li>';
			} else {
				echo '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a></li>';
			}
		}
		echo '</ol></nav>';
	}
}
