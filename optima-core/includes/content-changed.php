<?php
/**
 * One "content last changed" date per post.
 *
 * WordPress moves a post's modified date on every save, including a save that
 * changes nothing a reader sees (a meta box, a status change, a Site Sync apply
 * that finds the words already in place). This date moves only when the title
 * or the content actually changes, and it is the one date the site uses for
 * the visible "Last updated" line (optima_content_changed()), the schema's
 * dateModified, the article:modified_time tag and the sitemap's lastmod.
 *
 * Stored as post meta `_optima_content_changed` (GMT, "Y-m-d H:i:s"). A post
 * saved before this existed has none yet and falls back to WordPress's
 * modified date, exactly what the site printed before, until its words change.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Content_Changed {

	const META = '_optima_content_changed';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 6 );
		add_action( 'post_updated', array( __CLASS__, 'updated' ), 10, 3 );
		add_action( 'wp_insert_post', array( __CLASS__, 'inserted' ), 10, 3 );
	}

	public static function register_meta() {
		register_meta(
			'post',
			self::META,
			array(
				'single'            => true,
				'type'              => 'string',
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'clean' ),
				'auth_callback'     => '__return_false',
			)
		);
	}

	/** "Y-m-d H:i:s" or ''. */
	public static function clean( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : '';
	}

	/** Posts this applies to: anything a visitor can open, never revisions or menu items. */
	private static function tracked( $post ) {
		return $post instanceof WP_Post
			&& ! wp_is_post_revision( $post )
			&& ! wp_is_post_autosave( $post )
			&& 'auto-draft' !== $post->post_status
			&& is_post_type_viewable( $post->post_type )
			&& 'attachment' !== $post->post_type;
	}

	/** Title and content as a reader sees them, for comparing (line endings and outer spaces do not count). */
	private static function words( WP_Post $post ) {
		$norm = static function ( $text ) {
			return trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
		};
		return $norm( $post->post_title ) . "\x1F" . $norm( $post->post_content );
	}

	/**
	 * A saved post: move the date only when the title or content changed. A
	 * first publish never leaves the date earlier than the publish date.
	 */
	public static function updated( $post_id, $after, $before ) {
		if ( ! self::tracked( $after ) || ! $before instanceof WP_Post ) {
			return;
		}
		$saved = self::clean( get_post_meta( $post_id, self::META, true ) );
		$value = $saved;
		if ( self::words( $after ) !== self::words( $before ) ) {
			$value = current_time( 'mysql', true );
		}
		if ( 'publish' === $after->post_status ) {
			$published = (string) $after->post_date_gmt;
			if ( '' === $value && '' === $saved && self::words( $after ) === self::words( $before ) ) {
				// Nothing recorded and nothing changed: leave the fallback (the modified date) alone.
				return;
			}
			if ( '0000-00-00 00:00:00' !== $published && '' !== $published && $value < $published ) {
				$value = $published;
			}
		}
		if ( '' !== $value && $value !== $saved ) {
			update_post_meta( $post_id, self::META, $value );
		}
	}

	/** A new post inserted with words already in it (an import, a Site Sync create). */
	public static function inserted( $post_id, $post, $update ) {
		if ( $update || ! self::tracked( $post ) ) {
			return;
		}
		if ( '' === trim( (string) $post->post_title ) && '' === trim( (string) $post->post_content ) ) {
			return;
		}
		$value = current_time( 'mysql', true );
		$date  = (string) $post->post_date_gmt;
		// An imported post keeps its own date: never later than now, never earlier than published.
		if ( 'publish' === $post->post_status && '' !== $date && '0000-00-00 00:00:00' !== $date ) {
			$value = $date;
		}
		update_post_meta( $post_id, self::META, $value );
	}

	/**
	 * The date the words last changed, in GMT ("Y-m-d H:i:s"), or ''. Falls back
	 * to WordPress's modified date, then the published date.
	 */
	public static function gmt( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		foreach ( array( get_post_meta( $post->ID, self::META, true ), $post->post_modified_gmt, $post->post_date_gmt ) as $value ) {
			$value = self::clean( $value );
			if ( '' !== $value && '0000-00-00 00:00:00' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * The date formatted in the site's time zone.
	 *
	 * @param int    $post_id
	 * @param string $format A PHP date format; '' for the site's date format (Settings, General); 'c' for ISO 8601.
	 */
	public static function formatted( $post_id, $format = '' ) {
		$gmt = self::gmt( $post_id );
		if ( '' === $gmt ) {
			return '';
		}
		$time = strtotime( $gmt . ' UTC' );
		if ( false === $time ) {
			return '';
		}
		$format = '' !== $format ? $format : (string) get_option( 'date_format', 'F j, Y' );
		return (string) wp_date( $format, $time );
	}
}

if ( ! function_exists( 'optima_content_changed' ) ) {
	/**
	 * When a post's words last changed, for the visible "Last updated" line:
	 *
	 *   Last updated <?php echo esc_html( optima_content_changed( get_the_ID() ) ); ?>
	 *
	 * The same date feeds the schema's dateModified and the sitemap's lastmod.
	 *
	 * @param int    $post_id Defaults to the current post.
	 * @param string $format  A PHP date format; '' for the site's date format; 'c' for ISO 8601.
	 * @return string '' when there is no such post.
	 */
	function optima_content_changed( $post_id = 0, $format = '' ) {
		$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
		return $post_id ? Optima_Core_Content_Changed::formatted( $post_id, $format ) : '';
	}
}
