<?php
/**
 * Pages due for an attorney's review, on a schedule (every 6 months by default).
 *
 * The "Reviewed by" record itself belongs to Optima Team (People): the
 * reviewer, the date, the version of the words the review covers, the line on
 * the page and reviewedBy / lastReviewed in the schema all come from
 * Optima_Team_Reviewed (plugin-first rule 47). This class builds no second
 * one. It only reads Team's record and lists the live pages whose last
 * complete review is older than the review period, or that have none, so the
 * firm reads them again. Without Optima Team it lists nothing.
 *
 * It also makes sure a reviewed page has a WebPage node for Team to stamp:
 * Optima Core adds its WebPage node after the optima_schema_graph filter, so
 * on a plain page Team's reviewedBy would have no node to sit on. Law calls
 * Core's own builder (Optima_Schema::add_webpage(), which steps aside when a
 * page node is already there) just before Team's filter runs.
 *
 * @package Optima_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Law_Reviews {

	const OPTION = 'optima_law_reviews';
	const MONTHS = array( 3, 6, 12 );

	public static function boot(): void {
		// Before Optima Team's reviewedBy filter (priority 20).
		add_filter( 'optima_schema_graph', array( __CLASS__, 'schema' ), 15 );
	}

	/* ---- Settings ----------------------------------------------------- */

	/** @return array{types:string[],months:int} */
	public static function settings(): array {
		$saved  = get_option( self::OPTION, array() );
		$saved  = is_array( $saved ) ? $saved : array();
		$months = (int) ( $saved['months'] ?? 6 );
		return array(
			'types'  => isset( $saved['types'] ) ? array_values( array_map( 'strval', (array) $saved['types'] ) ) : array( 'page' ),
			'months' => in_array( $months, self::MONTHS, true ) ? $months : 6,
		);
	}

	/** The kinds of content an attorney reviews on a schedule. Pages to start (practice and service pages). */
	public static function types(): array {
		/** Filter the post types that come due for an attorney's review. */
		return array_values( array_map( 'strval', (array) apply_filters( 'optima_law_review_types', self::settings()['types'] ) ) );
	}

	/** Optima Team is on, so there is a Reviewed by record to read. */
	public static function available(): bool {
		return class_exists( 'Optima_Team_Reviewed' ) && class_exists( 'Optima_Team' );
	}

	/* ---- Due dates (pure) ------------------------------------------------ */

	/**
	 * Whether a review is due: never reviewed, or the last one is older than
	 * the review period. Pure, so it can be tested without WordPress.
	 */
	public static function is_due( int $at, int $months, int $now ): bool {
		if ( $at <= 0 ) {
			return true;
		}
		return $at < self::cutoff( $months, $now );
	}

	/** The time before which a review counts as out of date. */
	public static function cutoff( int $months, int $now ): int {
		return (int) strtotime( '-' . max( 1, $months ) . ' months', $now );
	}

	/* ---- Reading Team's record ----------------------------------------- */

	/**
	 * When the page's current review happened, as a timestamp, or 0 when Team
	 * has no complete review of the words as they are now (none, incomplete,
	 * or the page changed after the review).
	 */
	public static function reviewed_at( int $post_id ): int {
		if ( ! self::available() || 'complete' !== Optima_Team_Reviewed::status( $post_id ) ) {
			return 0;
		}
		$when = (string) Optima_Team_Reviewed::get( $post_id )['when'];
		$at   = '' !== $when ? strtotime( $when . ' 12:00:00' ) : false;
		return false !== $at ? (int) $at : 0;
	}

	/** Who did the current review (a People profile id), or 0. */
	public static function reviewer( int $post_id ): int {
		return self::available() ? (int) Optima_Team_Reviewed::printable( $post_id )['who'] : 0;
	}

	/**
	 * People who can sign a review on a law site: published profiles marked as
	 * an attorney.
	 *
	 * @return array<int,string> profile ID => name.
	 */
	public static function attorneys(): array {
		if ( ! self::available() ) {
			return array();
		}
		$out = array();
		foreach ( get_posts( array( 'post_type' => Optima_Team::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'menu_order title', 'order' => 'ASC' ) ) as $person ) {
			if ( Optima_Team::is_attorney( Optima_Team::profile( (int) $person->ID ) ) ) {
				$out[ (int) $person->ID ] = (string) get_the_title( $person );
			}
		}
		return $out;
	}

	/**
	 * Live pages that need an attorney's review: never reviewed, review out of
	 * date, or not reviewed within the review period. Oldest first. Pages whose
	 * reviewer left the firm are left out: Optima's Inbox lists those already
	 * ("Pages waiting for a reviewer").
	 *
	 * @return int[]
	 */
	public static function due(): array {
		if ( ! self::available() || ! self::types() ) {
			return array();
		}
		$months  = self::settings()['months'];
		$now     = time();
		$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
		$orphans = array_map( 'intval', Optima_Team_Reviewed::orphaned() );
		$out     = array();
		foreach ( get_posts( array( 'post_type' => self::types(), 'post_status' => 'publish', 'numberposts' => 300, 'orderby' => 'modified', 'order' => 'ASC', 'no_found_rows' => true ) ) as $post ) {
			$id = (int) $post->ID;
			if ( $id === $privacy || in_array( $id, $orphans, true ) ) {
				continue; // The privacy page has its own yearly review; orphans are on the Inbox.
			}
			if ( self::is_due( self::reviewed_at( $id ), $months, $now ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/* ---- Schema --------------------------------------------------------- */

	/** Give a reviewed page Core's WebPage node before Team stamps reviewedBy on it. */
	public static function schema( $graph ) {
		if ( ! is_array( $graph ) || ! is_singular() || ! self::available() || ! class_exists( 'Optima_Schema' ) || ! method_exists( 'Optima_Schema', 'add_webpage' ) ) {
			return $graph;
		}
		// The theme's exact page graph lists its own nodes; Core adds nothing there either.
		if ( class_exists( 'Optima_SEO_Setup' ) && method_exists( 'Optima_SEO_Setup', 'exact' ) && Optima_SEO_Setup::exact() ) {
			return $graph;
		}
		if ( ! self::reviewer( (int) get_queried_object_id() ) ) {
			return $graph;
		}
		return Optima_Schema::add_webpage( $graph );
	}
}
