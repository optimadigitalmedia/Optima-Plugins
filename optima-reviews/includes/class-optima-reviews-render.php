<?php
/**
 * Front-end output: the `[optima_reviews]` and `[optima_review_summary]`
 * shortcodes and the `optima/reviews` block, all drawn from the same templates.
 *
 * Themes restyle with CSS or replace markup outright: a file at
 * `{theme}/optima-reviews/{template}.php` wins over the plugin's copy in
 * `templates/`. Templates: section, summary, card.
 *
 * @package Optima_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Reviews_Render {

	const STYLE = 'optima-reviews';

	/** The shortcodes that print reviews. The block is optima/reviews. */
	const SHORTCODES = array( 'optima_reviews', 'optima_review_summary' );

	/**
	 * The few layout rules printed inline when reviews render after <head>
	 * has gone out, so the cards and stars take their size on the first
	 * paint and nothing moves when the stylesheet arrives. Layout only: no
	 * color, font or radius, those come from the stylesheet and the theme's
	 * --optima-* tokens.
	 */
	const CRITICAL = '.optima-reviews{display:flex;flex-direction:column;gap:28px}'
		. '.optima-reviews-summary,.optima-reviews-summary__item{display:flex;flex-wrap:wrap;align-items:center}'
		. '.optima-reviews__list{display:grid;gap:20px;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr))}'
		. '.optima-reviews--list .optima-reviews__list{grid-template-columns:1fr;max-width:760px}'
		. '.optima-reviews--row .optima-reviews__list{display:flex;overflow-x:auto}'
		. '.optima-reviews--row .optima-review{flex:0 0 min(86%,360px)}'
		. '.optima-review{display:flex;flex-direction:column;gap:16px;margin:0;padding:24px}'
		. '.optima-stars{display:inline-block;flex:none;width:92px;height:16px}'
		. '.optima-review__avatar{width:40px;height:40px}'
		. '.optima-reviews .screen-reader-text{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}';

	/** Set once the stylesheet has been printed beside the reviews on this request. */
	private static $printed = false;

	/** This request's answer to page_uses(), worked out once. */
	private static $uses = null;

	public static function register(): void {
		wp_register_style( self::STYLE, OPTIMA_REVIEWS_URL . 'assets/optima-reviews.css', array(), OPTIMA_REVIEWS_VERSION );

		add_shortcode( 'optima_reviews', array( __CLASS__, 'shortcode_reviews' ) );
		add_shortcode( 'optima_review_summary', array( __CLASS__, 'shortcode_summary' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_early' ) );

		register_block_type(
			OPTIMA_REVIEWS_DIR . 'assets/block',
			array( 'render_callback' => array( __CLASS__, 'block' ) )
		);
	}

	/**
	 * Normalize options from a shortcode or block into one shape.
	 */
	public static function options( array $raw ): array {
		$bool = static function ( $value ): bool {
			return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
		};

		$layout = strtolower( (string) ( $raw['layout'] ?? 'grid' ) );
		$source = sanitize_key( (string) ( $raw['source'] ?? '' ) );

		return array(
			'layout'     => in_array( $layout, array( 'grid', 'row', 'list' ), true ) ? $layout : 'grid',
			'count'      => max( 0, (int) ( $raw['count'] ?? 0 ) ),
			'source'     => Optima_Reviews_Sources::exists( $source ) ? $source : '',
			'featured'   => $bool( $raw['featured'] ?? false ),
			'min_rating' => max( 0, min( 5, (int) ( $raw['min_rating'] ?? 0 ) ) ),
			'summary'    => $bool( $raw['summary'] ?? true ),
			'topic'      => sanitize_title( (string) ( $raw['topic'] ?? '' ) ),
			// When a topic has no reviews yet, show featured ones instead of nothing.
			'fallback'   => 'none' === strtolower( (string) ( $raw['fallback'] ?? 'featured' ) ) ? 'none' : 'featured',
		);
	}

	public static function shortcode_reviews( $atts ): string {
		return self::section( self::options( is_array( $atts ) ? $atts : array() ) );
	}

	public static function shortcode_summary( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$opts = self::options( $atts );
		return self::style_tag() . self::template( 'summary', array( 'totals' => Optima_Reviews_Query::totals( $opts['source'] ) ) );
	}

	public static function block( array $attributes ): string {
		$opts = self::options(
			array(
				'layout'     => $attributes['layout'] ?? 'grid',
				'count'      => $attributes['count'] ?? 0,
				'source'     => $attributes['source'] ?? '',
				'featured'   => ! empty( $attributes['featured'] ) ? '1' : '0',
				'min_rating' => $attributes['minRating'] ?? 0,
				'summary'    => ! isset( $attributes['showSummary'] ) || $attributes['showSummary'] ? '1' : '0',
				'topic'      => $attributes['topic'] ?? '',
			)
		);
		$html = self::section( $opts );
		if ( '' === $html ) {
			return '';
		}
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * Summary + cards + disclaimer. Empty string when there is nothing to show,
	 * so an empty section never leaves a blank gap on the page.
	 */
	public static function section( array $opts ): string {
		$query = array(
			'count'      => $opts['count'],
			'source'     => $opts['source'],
			'featured'   => $opts['featured'],
			'min_rating' => $opts['min_rating'],
			'topic'      => $opts['topic'] ?? '',
		);
		$reviews = Optima_Reviews_Query::reviews( $query );
		if ( ! $reviews && '' !== $query['topic'] && 'featured' === ( $opts['fallback'] ?? 'featured' ) ) {
			$query['topic']    = '';
			$query['featured'] = true;
			$reviews           = Optima_Reviews_Query::reviews( $query );
		}
		$totals = $opts['summary'] ? Optima_Reviews_Query::totals( $opts['source'] ) : array();

		if ( ! $reviews && ! $totals ) {
			return '';
		}

		$law = Optima_Reviews_Law::is_law();
		// North Carolina: the disclaimer before and after any quote about an outcome.
		foreach ( $reviews as $i => $review ) {
			$reviews[ $i ]['outcome_disclaimer'] = Optima_Reviews_Law::outcome_disclaimer( (string) $review['text'] );
		}

		// A hand-picked or capped list says so, with the way to every review (16 CFR 465.7).
		$curated = ! empty( $query['featured'] ) || '' !== (string) $query['topic'] || (int) $query['count'] > 0;
		$google  = Optima_Reviews_Settings::profile( 'google' );

		return self::style_tag() . self::template(
			'section',
			array(
				'reviews'          => $reviews,
				'totals'           => $totals,
				'layout'           => $opts['layout'],
				'disclaimer'       => $law ? Optima_Reviews_Law::disclaimer() : (string) Optima_Reviews_Settings::get()['disclaimer'],
				'disclaimer_first' => $law, // Law sites: required, and printed before the list.
				'curated'          => $curated && (bool) $reviews,
				/** Filter the label printed above a hand-picked or capped list of reviews. */
				'curated_label'    => (string) apply_filters( 'optima_reviews_curated_label', __( 'Selected client reviews', 'optima-reviews' ) ),
				'all_url'          => $google['url'],
			)
		);
	}

	/**
	 * The "as of" date for a platform's totals: Google's last sync when it
	 * syncs, else the day the totals were last saved. '' when unknown.
	 */
	public static function as_of( string $source ): string {
		$ts = 0;
		if ( 'google' === $source && Optima_Reviews_Google::is_ready() ) {
			$g  = Optima_Reviews_Google::get();
			$ts = '' === (string) ( $g['last_error'] ?? '' ) ? (int) ( $g['last_sync'] ?? 0 ) : 0;
		}
		if ( ! $ts ) {
			$ts = (int) get_option( Optima_Reviews_Settings::STAMP, 0 );
		}
		return $ts > 0 ? wp_date( (string) get_option( 'date_format' ), $ts ) : '';
	}

	/**
	 * Put the stylesheet in <head> when the page will print reviews, so the
	 * reviews never restyle after they paint. Looks at the page's own content
	 * (shortcode or block), the synced patterns it uses, its Elementor data
	 * (shortcode and text widgets, embedded templates, global widgets) and the
	 * Elementor theme templates that apply to it. Anything this misses (a
	 * theme slot, a sidebar widget) still gets its styles beside the output
	 * (see style_tag()). Theme slots can also call
	 * wp_enqueue_style( 'optima-reviews' ) before the head.
	 */
	public static function enqueue_early(): void {
		if ( self::page_uses() ) {
			self::enqueue();
		}
	}

	/** Will this page print reviews? */
	public static function page_uses(): bool {
		if ( null !== self::$uses ) {
			return self::$uses;
		}
		$obj  = get_queried_object();
		$ids  = $obj instanceof WP_Post ? array( (int) $obj->ID ) : array();
		$ids  = array_merge( $ids, self::elementor_location_ids() );
		$seen = array();
		$uses = false;
		foreach ( $ids as $id ) {
			if ( self::post_uses( (int) $id, $seen ) ) {
				$uses = true;
				break;
			}
		}
		/** Filter whether this page loads the reviews stylesheet in the head (a theme template that always prints reviews can say yes). */
		self::$uses = (bool) apply_filters( 'optima_reviews_load_in_head', $uses, $obj );
		return self::$uses;
	}

	/** Does this post (or anything it embeds, a few levels deep) use the reviews shortcode or block? */
	private static function post_uses( int $id, array &$seen, int $depth = 0 ): bool {
		if ( $id <= 0 || isset( $seen[ $id ] ) || $depth > 3 ) {
			return false;
		}
		$seen[ $id ] = true;
		$post        = get_post( $id );
		if ( ! $post ) {
			return false;
		}
		$content = (string) $post->post_content;
		if ( has_block( 'optima/reviews', $content ) ) {
			return true;
		}
		foreach ( self::SHORTCODES as $tag ) {
			if ( has_shortcode( $content, $tag ) ) {
				return true;
			}
		}
		// Synced patterns: <!-- wp:block {"ref":12} /-->.
		if ( false !== strpos( $content, 'wp:block' ) && preg_match_all( '/<!--\s+wp:block\s+\{[^}]*"ref":(\d+)/', $content, $m ) ) {
			foreach ( $m[1] as $ref ) {
				if ( self::post_uses( (int) $ref, $seen, $depth + 1 ) ) {
					return true;
				}
			}
		}
		// Elementor keeps a page's widgets as JSON in _elementor_data.
		$data = get_post_meta( $id, '_elementor_data', true );
		if ( is_array( $data ) ) {
			$data = (string) wp_json_encode( $data );
		}
		if ( ! is_string( $data ) || '' === $data ) {
			return false;
		}
		foreach ( self::SHORTCODES as $tag ) {
			if ( false !== strpos( $data, '[' . $tag ) ) {
				return true;
			}
		}
		if ( false !== strpos( $data, 'wp:optima\\/reviews' ) || false !== strpos( $data, 'wp:optima/reviews' ) ) {
			return true;
		}
		// Templates placed inside the page: the Template widget, global widgets and [elementor-template id="12"].
		if ( preg_match_all( '/"(?:template_id|templateID)":"?(\d+)|elementor-template\s+id=\\\\?["\']?(\d+)/', $data, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$ref = (int) ( '' !== ( $hit[1] ?? '' ) ? $hit[1] : ( $hit[2] ?? 0 ) );
				if ( self::post_uses( $ref, $seen, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** The Elementor theme templates (header, footer, single, archive) that apply to this page, when Elementor Pro is on. */
	private static function elementor_location_ids(): array {
		if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			return array();
		}
		try {
			$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
			$mgr    = method_exists( $module, 'get_conditions_manager' ) ? $module->get_conditions_manager() : null;
			if ( ! $mgr || ! method_exists( $mgr, 'get_documents_for_location' ) ) {
				return array();
			}
			$ids = array();
			foreach ( array( 'header', 'footer', 'single', 'archive' ) as $location ) {
				foreach ( (array) $mgr->get_documents_for_location( $location ) as $id => $doc ) {
					$ids[] = is_object( $doc ) && method_exists( $doc, 'get_main_id' ) ? (int) $doc->get_main_id() : (int) $id;
				}
			}
			return $ids;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/** Is the stylesheet on? (Reviews, Settings; a theme that styles reviews itself turns it off.) */
	public static function styles_on(): bool {
		return (bool) apply_filters( 'optima_reviews_load_styles', (bool) Optima_Reviews_Settings::get()['load_styles'] );
	}

	public static function enqueue(): void {
		if ( self::styles_on() ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	/**
	 * The stylesheet for reviews that render after <head> has gone out
	 * (Elementor widgets and templates, theme slots, sidebar widgets, cached
	 * widget output): the inline layout rules, then the stylesheet itself,
	 * printed once beside the first reviews on the page. A stylesheet in the
	 * body holds back the content after it until it loads, so nothing
	 * restyles after it paints, and it rides along when Elementor caches the
	 * widget's HTML. Empty when the head already carried the stylesheet or
	 * the stylesheet is off. Before the head is out (or on a dashboard
	 * screen) it simply enqueues.
	 */
	public static function style_tag(): string {
		if ( self::$printed || ! self::styles_on() || wp_style_is( self::STYLE, 'done' ) ) {
			return '';
		}
		$late = did_action( 'wp_head' ) || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( ! $late || ( is_admin() && ! wp_doing_ajax() ) || ! wp_style_is( self::STYLE, 'registered' ) ) {
			self::enqueue();
			return '';
		}
		$styles = wp_styles();
		wp_dequeue_style( self::STYLE );
		ob_start();
		$styles->do_item( self::STYLE );
		$link           = (string) ob_get_clean();
		$styles->done[] = self::STYLE;
		self::$printed  = true;
		return '<style id="optima-reviews-critical">' . self::CRITICAL . '</style>' . $link;
	}

	/**
	 * Render a template with $vars in scope. Theme copy wins.
	 */
	public static function template( string $name, array $vars = array() ): string {
		$name = sanitize_key( $name );
		$file = locate_template( 'optima-reviews/' . $name . '.php' );
		if ( ! $file ) {
			$file = OPTIMA_REVIEWS_DIR . 'templates/' . $name . '.php';
		}
		if ( ! is_readable( $file ) ) {
			return '';
		}
		ob_start();
		( static function ( $__file, $__vars ) {
			extract( $__vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract -- template scope.
			include $__file;
		} )( $file, $vars );
		return (string) ob_get_clean();
	}

	/* ---- Helpers the templates use ---- */

	/**
	 * Five stars, filled to the rating (supports halves for platform totals).
	 */
	public static function stars( float $rating ): string {
		$label = sprintf(
			/* translators: %s: rating such as 4.9. */
			__( 'Rated %s out of 5', 'optima-reviews' ),
			rtrim( rtrim( number_format_i18n( $rating, 1 ), '0' ), '.,' )
		);
		$pct = max( 0, min( 100, ( $rating / 5 ) * 100 ) );

		// One element: a star-shaped mask over a two-stop fill. Partial
		// ratings (4.9) fill to the exact fraction.
		return '<span class="optima-stars" role="img" aria-label="' . esc_attr( $label ) . '" style="--sr-pct:' . esc_attr( (string) round( $pct, 1 ) ) . '%"></span>';
	}

	/**
	 * "March 2026": the month is enough for trust and ages better than a full date.
	 */
	public static function date( string $ymd ): string {
		if ( ! $ymd ) {
			return '';
		}
		$ts = strtotime( $ymd . ' 12:00:00' );
		return $ts ? wp_date( 'F Y', $ts ) : '';
	}

	/**
	 * First letter of the reviewer's name, for the avatar when there is no photo.
	 */
	public static function initial( string $name ): string {
		$name = trim( $name );
		return '' === $name ? '?' : mb_strtoupper( mb_substr( $name, 0, 1 ) );
	}
}

/**
 * Theme API: show the reviews that belong on this spot.
 *
 * Call it where reviews should appear in a template. It prints nothing when
 * there are no reviews to show (and nothing at all if the plugin is off, as
 * long as the call is wrapped in function_exists), so it can never leave an
 * empty section behind.
 *
 *     <?php if ( function_exists( 'optima_reviews_slot' ) ) optima_reviews_slot( array( 'topic' => 'law-firms' ) ); ?>
 *
 * @param array $args {
 *     @type string $topic    Topic slug. Default: the current page's slug ("home" on the front page).
 *     @type string $label    Name for the topic if it does not exist yet. Default: the page title.
 *     @type int    $count    How many reviews. Default 3.
 *     @type string $layout   grid | row | list. Default grid.
 *     @type bool   $summary  Show the platform rating line. Default true.
 *     @type string $fallback "featured" (default) shows featured reviews when the topic has none; "none" shows nothing.
 *     @type string $before   HTML printed before the reviews, only when there are reviews (the theme's section opener and heading).
 *     @type string $after    HTML printed after, only when there are reviews.
 * }
 */
function optima_reviews_slot( array $args = array() ): void {
	echo optima_reviews_get_slot( $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped templates; before/after are theme-authored markup.
}

/**
 * Same as optima_reviews_slot(), returned instead of printed.
 */
function optima_reviews_get_slot( array $args = array() ): string {
	$page  = get_queried_object();
	$topic = (string) ( $args['topic'] ?? '' );
	$label = (string) ( $args['label'] ?? '' );

	if ( '' === $topic ) {
		if ( is_front_page() ) {
			$topic = 'home';
			$label = $label ? $label : __( 'Home page', 'optima-reviews' );
		} elseif ( $page instanceof WP_Post ) {
			$topic = $page->post_name;
			$label = $label ? $label : get_the_title( $page );
		}
	}
	if ( '' !== $topic ) {
		Optima_Reviews_Topics::remember( $topic, $label ? $label : ucwords( str_replace( '-', ' ', $topic ) ) );
	}

	$opts = Optima_Reviews_Render::options(
		array(
			'topic'    => $topic,
			'count'    => $args['count'] ?? 3,
			'layout'   => $args['layout'] ?? 'grid',
			'summary'  => ( $args['summary'] ?? true ) ? '1' : '0',
			'fallback' => $args['fallback'] ?? 'featured',
		)
	);

	$html = Optima_Reviews_Render::section( $opts );
	if ( '' === $html ) {
		return '';
	}
	return (string) ( $args['before'] ?? '' ) . $html . (string) ( $args['after'] ?? '' );
}
