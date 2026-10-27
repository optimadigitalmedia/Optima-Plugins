<?php
/**
 * The case result post type, the practice area list, the disclaimer setting
 * and the rules every result has to pass before it can go live.
 *
 * @package Optima_Results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Results {

	const POST_TYPE = 'optima_result';
	const TAXONOMY  = 'optima_result_area';
	const OPTION    = 'optima_results';

	/** Meta keys, all private (leading underscore) so they never show in Custom Fields. */
	const META = array(
		'amount'      => '_optima_result_amount',
		'type'        => '_optima_result_type',
		'description' => '_optima_result_description',
		'date'        => '_optima_result_date',
		'approved'    => '_optima_result_approved',
		'approver'    => '_optima_result_approver',
		'approved_on' => '_optima_result_approved_on',
	);

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Case results', 'optima-results' ),
					'singular_name'      => __( 'Case result', 'optima-results' ),
					'menu_name'          => __( 'Case results', 'optima-results' ),
					'add_new'            => __( 'Add a result', 'optima-results' ),
					'add_new_item'       => __( 'Add a case result', 'optima-results' ),
					'edit_item'          => __( 'Edit case result', 'optima-results' ),
					'new_item'           => __( 'New case result', 'optima-results' ),
					'view_item'          => __( 'View case result', 'optima-results' ),
					'search_items'       => __( 'Search case results', 'optima-results' ),
					'not_found'          => __( 'No case results yet.', 'optima-results' ),
					'not_found_in_trash' => __( 'No case results in the trash.', 'optima-results' ),
					'all_items'          => __( 'All case results', 'optima-results' ),
				),
				// Results only appear through the grid, which always prints the
				// disclaimer. No single pages, archives or search hits that could
				// show a result without it.
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-portfolio',
				'supports'            => array( 'title', 'revisions' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Practice areas', 'optima-results' ),
					'singular_name' => __( 'Practice area', 'optima-results' ),
					'menu_name'     => __( 'Practice areas', 'optima-results' ),
					'add_new_item'  => __( 'Add a practice area', 'optima-results' ),
					'edit_item'     => __( 'Edit practice area', 'optima-results' ),
					'search_items'  => __( 'Search practice areas', 'optima-results' ),
					'not_found'     => __( 'No practice areas yet.', 'optima-results' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => false,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'rewrite'           => false,
				'query_var'         => false,
				'meta_box_cb'       => false, // Picked in our own box, one area per result.
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Settings: the state and the disclaimer                              */
	/* ------------------------------------------------------------------ */

	/** The states with their own starting wording. */
	public static function states(): array {
		return array(
			'nc'    => __( 'North Carolina', 'optima-results' ),
			'va'    => __( 'Virginia', 'optima-results' ),
			'fl'    => __( 'Florida', 'optima-results' ),
			'other' => __( 'Another state', 'optima-results' ),
		);
	}

	/**
	 * Starting wording per state. A sensible default the firm edits and
	 * approves, never a statement that it satisfies any bar rule.
	 */
	public static function default_disclaimer( string $state ): string {
		$text = array(
			'nc'    => __( 'Prior results do not guarantee a similar outcome. Every case is different and depends on its own facts.', 'optima-results' ),
			'va'    => __( 'Prior results do not guarantee a similar outcome. Each case depends on its own facts and circumstances.', 'optima-results' ),
			'fl'    => __( 'Prior results do not guarantee a similar outcome. Every case is different, and the result in any case depends on its own facts and the law that applies.', 'optima-results' ),
			'other' => __( 'Prior results do not guarantee a similar outcome.', 'optima-results' ),
		);
		$out = $text[ $state ] ?? $text['other'];
		/** Filter the starting disclaimer for a state. */
		return (string) apply_filters( 'optima_results_default_disclaimer', $out, $state );
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'state'      => isset( $s['state'], self::states()[ $s['state'] ] ) ? $s['state'] : 'nc',
			'disclaimer' => (string) ( $s['disclaimer'] ?? '' ),
		);
	}

	/** The disclaimer printed next to every result. Never empty. */
	public static function disclaimer(): string {
		$s    = self::settings();
		$text = trim( $s['disclaimer'] );
		if ( '' === $text ) {
			$text = self::default_disclaimer( $s['state'] );
		}
		/** Filter the disclaimer text. An empty return falls back to the state wording. */
		$filtered = trim( (string) apply_filters( 'optima_results_disclaimer', $text ) );
		return '' !== $filtered ? $filtered : self::default_disclaimer( $s['state'] );
	}

	/* ------------------------------------------------------------------ */
	/* A single result                                                     */
	/* ------------------------------------------------------------------ */

	public static function types(): array {
		return array(
			'verdict'    => __( 'Verdict', 'optima-results' ),
			'settlement' => __( 'Settlement', 'optima-results' ),
			'other'      => __( 'Other result', 'optima-results' ),
		);
	}

	/** The word shown above a result's headline. */
	public static function type_label( string $type ): string {
		return 'other' === $type ? __( 'Case result', 'optima-results' ) : ( self::types()[ $type ] ?? __( 'Case result', 'optima-results' ) );
	}

	/** Everything about one result, in one array. */
	public static function get( int $post_id ): array {
		$m = array();
		foreach ( self::META as $key => $meta_key ) {
			$m[ $key ] = (string) get_post_meta( $post_id, $meta_key, true );
		}
		$type  = isset( self::types()[ $m['type'] ] ) ? $m['type'] : 'other';
		$terms = get_the_terms( $post_id, self::TAXONOMY );
		$area  = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
		return array(
			'id'          => $post_id,
			'headline'    => get_the_title( $post_id ),
			'amount'      => $m['amount'],
			'type'        => $type,
			'type_label'  => self::type_label( $type ),
			'description' => $m['description'],
			'date'        => $m['date'],
			'area'        => $area ? $area->name : '',
			'area_slug'   => $area ? $area->slug : '',
			'area_id'     => $area ? (int) $area->term_id : 0,
			'approved'    => '1' === $m['approved'],
			'approver'    => $m['approver'],
			'approved_on' => $m['approved_on'],
		);
	}

	/** A result may go live only with the firm's approval and the approver's name. */
	public static function is_approved( bool $checked, string $approver ): bool {
		return $checked && '' !== trim( $approver );
	}

	/** "2026-03-01" as "March 2026", or '' when the date is not valid. */
	public static function format_date( string $ymd ): string {
		$ts = self::valid_date( $ymd ) ? strtotime( $ymd . ' 12:00:00' ) : false;
		return $ts ? date_i18n( 'F Y', $ts ) : '';
	}

	public static function valid_date( string $ymd ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $p ) ) {
			return false;
		}
		return checkdate( (int) $p[2], (int) $p[3], (int) $p[1] );
	}

	/* ------------------------------------------------------------------ */
	/* Wording check                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Words that tend to make a result read as a promise or a ranking, each
	 * with a plainer way to say it. The editor flags them as you type; the
	 * firm's approver makes the call.
	 */
	public static function flagged_words(): array {
		$words = array(
			'best'          => __( 'Describe what happened instead, for example "a fair settlement for the client".', 'optima-results' ),
			'#1'            => __( 'Leave rankings out unless the firm can point to the source.', 'optima-results' ),
			'number one'    => __( 'Leave rankings out unless the firm can point to the source.', 'optima-results' ),
			'top-rated'     => __( 'Leave ratings out of a case result.', 'optima-results' ),
			'guarantee'     => __( 'Take it out. A past result never promises a future one.', 'optima-results' ),
			'guaranteed'    => __( 'Take it out. A past result never promises a future one.', 'optima-results' ),
			'promise'       => __( 'Take it out. A past result never promises a future one.', 'optima-results' ),
			'always win'    => __( 'Describe this one case instead.', 'optima-results' ),
			'never lose'    => __( 'Describe this one case instead.', 'optima-results' ),
			'specialist'    => __( 'Try "focuses on" unless the lawyer holds a certification the state recognizes.', 'optima-results' ),
			'specialize'    => __( 'Try "focuses on" unless the lawyer holds a certification the state recognizes.', 'optima-results' ),
			'specializes'   => __( 'Try "focuses on" unless the lawyer holds a certification the state recognizes.', 'optima-results' ),
			'expert'        => __( 'Try "experienced in" unless the firm can support the claim.', 'optima-results' ),
			'record-breaking' => __( 'Leave it out unless the firm can cite the source.', 'optima-results' ),
			'largest'       => __( 'Leave it out unless the firm can cite the source.', 'optima-results' ),
		);
		/** Filter the words the editor flags, as word => suggestion. */
		return (array) apply_filters( 'optima_results_flagged_words', $words );
	}

	/** The flagged words found in a piece of text, as word => suggestion. */
	public static function find_flagged( string $text ): array {
		$found = array();
		$text  = wp_strip_all_tags( $text );
		foreach ( self::flagged_words() as $word => $tip ) {
			$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( (string) $word, '/' ) . '(?![\p{L}\p{N}])/iu';
			if ( preg_match( $pattern, $text ) ) {
				$found[ $word ] = $tip;
			}
		}
		return $found;
	}
}
