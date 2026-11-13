<?php
/**
 * The front end: [optima_results] prints a grid of approved results with
 * practice-area filters, and [optima_result id="12"] prints one. Only results
 * the firm approved and that are not under the firm's threshold ever print
 * (Optima_Results::may_publish()). Every card carries the site's disclaimer,
 * and there is no way to switch it off.
 *
 * No structured data is printed. A case result is not a review or a rating,
 * and nothing here should suggest it is.
 *
 * @package Optima_Results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Results_Render {

	const STYLE = 'optima-results';

	/** The shortcodes that print results. */
	const SHORTCODES = array( 'optima_results', 'optima_result' );

	/**
	 * The few layout rules printed inline when results render after <head>
	 * has gone out, so the grid is laid out on the first paint and nothing
	 * moves when the stylesheet arrives. Layout only: no color, font or
	 * radius, those come from the stylesheet and the theme's --optima-* tokens.
	 */
	const CRITICAL = '.optima-results__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:var(--optima-results-gap,1.25rem)}'
		. '.optima-results__filters ul{display:flex;flex-wrap:wrap;gap:.5rem;margin:0 0 var(--optima-results-gap,1.25rem);padding:0;list-style:none}'
		. '.optima-results .optima-result{display:flex;flex-direction:column;gap:.6rem;min-width:0;margin:0;padding:1.25rem 1.35rem}'
		. '.optima-results [hidden],.optima-results .optima-result[hidden]{display:none}'
		. '.optima-results .screen-reader-text{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}';

	/** Set once the stylesheet has been printed beside the results on this request. */
	private static $printed = false;

	/** This request's answer to page_uses(), worked out once. */
	private static $uses = null;

	public static function boot(): void {
		// Registered on init so the handle exists on every request (REST and
		// Ajax renders included), not only on pages that run wp_enqueue_scripts.
		add_action( 'init', array( __CLASS__, 'register_assets' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_early' ) );
		add_shortcode( 'optima_results', array( __CLASS__, 'grid' ) );
		add_shortcode( 'optima_result', array( __CLASS__, 'single' ) );
	}

	public static function register_assets(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			wp_register_style( self::STYLE, OPTIMA_RESULTS_URL . 'assets/optima-results.css', array(), OPTIMA_RESULTS_VERSION );
		}
		if ( ! wp_script_is( 'optima-results', 'registered' ) ) {
			wp_register_script( 'optima-results', OPTIMA_RESULTS_URL . 'assets/optima-results.js', array(), OPTIMA_RESULTS_VERSION, true );
		}
	}

	/** Load the stylesheet in the head on pages that will print results, so nothing shifts. */
	public static function enqueue_early(): void {
		self::register_assets();
		if ( self::page_uses() ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	/**
	 * Will this page print results? Looks at the page's own content, the
	 * synced patterns it uses, its Elementor data (shortcode widgets, text
	 * widgets, embedded templates and global widgets) and the Elementor
	 * theme templates (header, footer, single, archive) that apply to it.
	 * Anything this misses still gets its styles beside the output (see
	 * style_tag()), so a miss costs a stylesheet in the body, never a broken
	 * layout.
	 */
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
		/** Filter whether this page loads the results stylesheet in the head (a theme template that always prints results can say yes). */
		self::$uses = (bool) apply_filters( 'optima_results_load_in_head', $uses, $obj );
		return self::$uses;
	}

	/** Does this post (or anything it embeds, a few levels deep) use a results shortcode? */
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

	/**
	 * The stylesheet for results that render after <head> has gone out
	 * (Elementor widgets and templates, theme slots, sidebar widgets, cached
	 * widget output): the inline layout rules, then the stylesheet itself,
	 * printed once beside the first results on the page. A stylesheet in the
	 * body holds back the content after it until it loads, so nothing
	 * restyles after it paints, and it rides along when Elementor caches the
	 * widget's HTML. Empty when the head already carried the stylesheet.
	 * Before the head is out (or on a dashboard screen) it simply enqueues.
	 */
	public static function style_tag(): string {
		if ( self::$printed || wp_style_is( self::STYLE, 'done' ) ) {
			return '';
		}
		$late = did_action( 'wp_head' ) || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( ! $late || ( is_admin() && ! wp_doing_ajax() ) ) {
			self::register_assets();
			wp_enqueue_style( self::STYLE );
			return '';
		}
		self::register_assets();
		$styles = wp_styles();
		wp_dequeue_style( self::STYLE );
		ob_start();
		$styles->do_item( self::STYLE );
		$link           = (string) ob_get_clean();
		$styles->done[] = self::STYLE;
		self::$printed  = true;
		return '<style id="optima-results-critical">' . self::CRITICAL . '</style>' . $link;
	}

	/**
	 * Approved results the site may show, newest result date first. A result
	 * the firm approved but that sits under the firm's threshold never comes
	 * back from here (Optima_Results::may_publish()).
	 */
	public static function query( array $args = array() ): array {
		$limit = max( 1, min( 200, (int) ( $args['limit'] ?? 100 ) ) );
		$q     = array(
			'post_type'              => Optima_Results::POST_TYPE,
			'post_status'            => 'publish',
			// Every approved result, so the threshold and the result-date order
			// are applied before the limit, not after it.
			'posts_per_page'         => 500,
			'no_found_rows'          => true,
			'update_post_term_cache' => true,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, per-site list.
				'approved' => array(
					'key'   => Optima_Results::META['approved'],
					'value' => '1',
				),
			),
			'orderby'                => 'date',
			'order'                  => 'DESC',
		);
		if ( ! empty( $args['area'] ) ) {
			$q['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => Optima_Results::TAXONOMY,
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', explode( ',', (string) $args['area'] ) ),
				),
			);
		}
		$out = array();
		foreach ( get_posts( $q ) as $post ) {
			$r = Optima_Results::get( (int) $post->ID );
			if ( Optima_Results::may_publish( $r ) ) {
				$out[] = $r;
			}
		}
		// Newest result date first; results without a date keep their order at the end.
		usort(
			$out,
			static function ( array $x, array $y ): int {
				return strcmp( $y['date'], $x['date'] );
			}
		);
		return array_slice( $out, 0, $limit );
	}

	/** The disclaimer as printed beside a result (every card, every state; Louisiana requires it on each result). */
	public static function disclaimer_html(): string {
		return '<p class="optima-result__disclaimer">' . esc_html( Optima_Results::disclaimer() ) . '</p>';
	}

	/** The disclaimer printed once above a grid, before the first amount, at body size. */
	public static function lead_disclaimer_html(): string {
		return '<p class="optima-results__disclaimer">' . esc_html( Optima_Results::disclaimer() ) . '</p>';
	}

	/**
	 * One result card. $preview marks the copy shown on the edit screen, which
	 * the editor script keeps in step with the fields. $disclaimer_first prints
	 * the card's disclaimer before its amount (a single result, where no grid
	 * line sits above it); otherwise it closes the card.
	 */
	public static function card( array $r, bool $preview = false, bool $disclaimer_first = false ): string {
		$date  = Optima_Results::format_date( (string) ( $r['date'] ?? '' ) );
		$attrs = $preview ? ' data-sr-preview' : ' data-area="' . esc_attr( (string) ( $r['area_slug'] ?? '' ) ) . '"';
		$h     = '<article class="optima-result"' . $attrs . '>';
		$h    .= '<p class="optima-result__type" data-sr="type">' . esc_html( $r['type_label'] ) . '</p>';
		$h    .= '<h3 class="optima-result__headline" data-sr="headline">' . esc_html( '' !== $r['headline'] ? $r['headline'] : ( $preview ? __( 'Your headline shows here', 'optima-results' ) : '' ) ) . '</h3>';
		if ( $disclaimer_first ) {
			$h .= self::disclaimer_html();
		}
		$h    .= '<p class="optima-result__amount" data-sr="amount"' . ( '' === $r['amount'] ? ' hidden' : '' ) . '>' . esc_html( $r['amount'] ) . '</p>';
		// The facts beside the amount: gross or net, the attorney's role, anything after the result.
		$facts = array(
			'basis' => Optima_Results::gross_net_text( $r ),
			'role'  => Optima_Results::role_text( $r ),
			'after' => Optima_Results::post_verdict_text( $r ),
		);
		$h    .= '<p class="optima-result__facts"' . ( array_filter( $facts, 'strlen' ) ? '' : ' hidden' ) . '>';
		foreach ( $facts as $key => $text ) {
			$h .= '<span class="optima-result__' . esc_attr( $key ) . '" data-sr="' . esc_attr( $key ) . '"' . ( '' === $text ? ' hidden' : '' ) . '>' . esc_html( $text ) . '</span>';
		}
		$h    .= '</p>';
		$h    .= '<p class="optima-result__description" data-sr="description"' . ( '' === $r['description'] ? ' hidden' : '' ) . '>' . esc_html( $r['description'] ) . '</p>';
		$h    .= '<p class="optima-result__meta"><span class="optima-result__area" data-sr="area"' . ( '' === $r['area'] ? ' hidden' : '' ) . '>' . esc_html( $r['area'] ) . '</span>';
		$h    .= '<span class="optima-result__date" data-sr="date"' . ( '' === $date ? ' hidden' : '' ) . '>' . esc_html( $date ) . '</span>';
		$venue = Optima_Results::venue_text( $r );
		$h    .= '<span class="optima-result__venue" data-sr="venue"' . ( '' === $venue ? ' hidden' : '' ) . '>' . esc_html( $venue ) . '</span>';
		// The attorney, from People: a link to the profile page while it is published.
		$attorney = (string) ( $r['attorney'] ?? '' );
		$link     = ! empty( $r['attorney_id'] ) ? get_permalink( (int) $r['attorney_id'] ) : '';
		$h       .= '<span class="optima-result__attorney" data-sr="attorney"' . ( '' === $attorney ? ' hidden' : '' ) . '>'
			/* translators: %s: attorney's name */
			. ( '' !== $link ? '<a href="' . esc_url( $link ) . '">' : '' ) . esc_html( '' !== $attorney ? sprintf( __( 'Attorney: %s', 'optima-results' ), $attorney ) : '' ) . ( '' !== $link ? '</a>' : '' )
			. '</span></p>';
		if ( ! $disclaimer_first ) {
			$h .= self::disclaimer_html();
		}
		$h    .= '</article>';
		return $h;
	}

	/**
	 * [optima_results area="car-accidents" limit="12" filters="yes" heading="Case results"]
	 */
	public static function grid( $atts ): string {
		$a = shortcode_atts(
			array(
				'area'    => '',
				'limit'   => 100,
				'filters' => 'yes',
				'heading' => '',
			),
			(array) $atts,
			'optima_results'
		);

		$results = self::query( $a );

		$uid = 'optima-results-' . wp_unique_id();
		$h   = self::style_tag() . '<section class="optima-results" id="' . esc_attr( $uid ) . '"' . ( '' !== $a['heading'] ? ' aria-labelledby="' . esc_attr( $uid ) . '-h"' : ' aria-label="' . esc_attr__( 'Case results', 'optima-results' ) . '"' ) . '>';
		if ( '' !== $a['heading'] ) {
			$h .= '<h2 class="optima-results__heading" id="' . esc_attr( $uid ) . '-h">' . esc_html( $a['heading'] ) . '</h2>';
		}

		if ( ! $results ) {
			$h .= '<p class="optima-results__empty">' . esc_html__( 'Case results will appear here once the firm has approved them.', 'optima-results' ) . '</p>';
			return $h . '</section>';
		}

		// The disclaimer before the first amount, at body size; every card keeps its own line too.
		$h .= self::lead_disclaimer_html();

		// Filter buttons for the practice areas that have results, in name order.
		$areas = array();
		foreach ( $results as $r ) {
			if ( '' !== $r['area_slug'] ) {
				$areas[ $r['area_slug'] ] = $r['area'];
			}
		}
		asort( $areas, SORT_NATURAL | SORT_FLAG_CASE );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public filter link, read only.
		$current = isset( $_GET['result_area'] ) ? sanitize_title( wp_unslash( (string) $_GET['result_area'] ) ) : '';
		$current = isset( $areas[ $current ] ) ? $current : '';

		if ( 'no' !== $a['filters'] && count( $areas ) > 1 ) {
			wp_enqueue_script( 'optima-results' );
			$h .= '<nav class="optima-results__filters" aria-label="' . esc_attr__( 'Filter results by practice area', 'optima-results' ) . '"><ul>';
			$h .= '<li><a class="optima-results__filter" href="' . esc_url( remove_query_arg( 'result_area' ) . '#' . $uid ) . '" data-filter=""' . ( '' === $current ? ' aria-current="true"' : '' ) . '>' . esc_html__( 'All practice areas', 'optima-results' ) . '</a></li>';
			foreach ( $areas as $slug => $name ) {
				$h .= '<li><a class="optima-results__filter" href="' . esc_url( add_query_arg( 'result_area', $slug ) . '#' . $uid ) . '" data-filter="' . esc_attr( $slug ) . '"' . ( $slug === $current ? ' aria-current="true"' : '' ) . '>' . esc_html( $name ) . '</a></li>';
			}
			$h .= '</ul></nav>';
			$h .= '<p class="optima-results__status screen-reader-text" role="status" aria-live="polite" data-one="' . esc_attr__( 'Showing 1 result', 'optima-results' ) . '" data-many="' . esc_attr__( 'Showing %d results', 'optima-results' ) . '"></p>';
		}

		$h .= '<div class="optima-results__grid">';
		foreach ( $results as $r ) {
			$card = self::card( $r );
			if ( '' !== $current && $r['area_slug'] !== $current ) {
				$card = preg_replace( '/^<article /', '<article hidden ', $card, 1 );
			}
			$h .= $card;
		}
		$h .= '</div>';
		return $h . '</section>';
	}

	/** [optima_result id="12"]: one approved result at or above the firm's threshold, with the disclaimer. */
	public static function single( $atts ): string {
		$a  = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'optima_result' );
		$id = absint( $a['id'] );
		if ( ! $id || Optima_Results::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		$r = Optima_Results::get( $id );
		if ( ! Optima_Results::may_publish( $r ) ) {
			return '';
		}
		// One result: its disclaimer prints before its amount, inside the card.
		return self::style_tag() . '<div class="optima-results optima-results--single">' . self::card( $r, false, true ) . '</div>';
	}
}
