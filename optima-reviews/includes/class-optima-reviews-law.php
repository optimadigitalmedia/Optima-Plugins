<?php
/**
 * Law-firm rules for reviews (release 1, 2026-11-13).
 *
 * On a law site every review shown has a person's approval, the star filter
 * never hides lower ratings, and the testimonial disclaimer is required and
 * prints before the list. The North Carolina rule set also keeps reviews that
 * mention a dollar amount or a settlement, verdict or award figure off the
 * site, and prints the disclaimer before and after any quote about an outcome.
 *
 * A law site is found the way the other plugins find it: the business type on
 * Your business, Details (LegalService, Attorney), Optima Team's law mode, or a
 * law rule set (this plugin's own, or the one saved under Case results).
 *
 * No disclaimer wording is written here: a law site without saved wording
 * prints a placeholder marked [VERIFY: counsel] and the dashboard shows it as
 * not approved until counsel's wording is saved with an approver's name.
 *
 * @package Optima_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Reviews_Law {

	/** The marker a placeholder carries until counsel replaces it. */
	const VERIFY = '[VERIFY: counsel]';

	/** Set on a published review that the old auto-publish option put on the site without a person. */
	const META_AUTO = '_optima_review_auto_added';

	/** This request's law answer, worked out once. */
	private static $law = null;

	/** Set when this request held a review back on the North Carolina rule set. */
	private static $held = false;

	public static function boot(): void {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'gate' ), 11, 2 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'redirect' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Is this a law site, and which state?                                */
	/* ------------------------------------------------------------------ */

	/** The state rule sets, the same list Optima Case results uses. */
	public static function states(): array {
		return array(
			'nc'    => __( 'North Carolina', 'optima-reviews' ),
			'va'    => __( 'Virginia', 'optima-reviews' ),
			'fl'    => __( 'Florida', 'optima-reviews' ),
			'la'    => __( 'Louisiana', 'optima-reviews' ),
			'dc'    => __( 'District of Columbia', 'optima-reviews' ),
			'md'    => __( 'Maryland', 'optima-reviews' ),
			'sc'    => __( 'South Carolina', 'optima-reviews' ),
			'other' => __( 'Another state', 'optima-reviews' ),
		);
	}

	/**
	 * The site's state rule set: the one saved here, else the one saved under
	 * Case results, else '' (none).
	 */
	public static function state(): string {
		$own = (string) ( Optima_Reviews_Settings::get()['state'] ?? '' );
		if ( isset( self::states()[ $own ] ) ) {
			return $own;
		}
		$results = get_option( 'optima_results', array() );
		$theirs  = is_array( $results ) ? (string) ( $results['state'] ?? '' ) : '';
		return isset( self::states()[ $theirs ] ) ? $theirs : '';
	}

	/** Is this a law firm's site? */
	public static function is_law(): bool {
		if ( null !== self::$law ) {
			return self::$law;
		}
		$law = '' !== self::state();
		if ( ! $law ) {
			$biz  = get_option( 'optima_business', array() );
			$type = is_array( $biz ) ? (string) ( $biz['business_type'] ?? '' ) : '';
			if ( '' === $type && function_exists( 'optima_core_business' ) ) {
				$type = (string) ( optima_core_business()['business_type'] ?? '' );
			}
			$law = in_array( $type, array( 'LegalService', 'Attorney' ), true ) || (bool) preg_match( '/legal|attorney|lawyer|law ?firm/i', $type );
		}
		if ( ! $law && class_exists( 'Optima_Team' ) && method_exists( 'Optima_Team', 'is_law' ) ) {
			$law = (bool) Optima_Team::is_law();
		}
		/** Filter whether Optima Reviews treats this site as a law firm's. */
		self::$law = (bool) apply_filters( 'optima_reviews_is_law', $law );
		return self::$law;
	}

	/** Forget the cached answer (after a settings save). */
	public static function reset(): void {
		self::$law = null;
	}

	public static function is_nc(): bool {
		return self::is_law() && 'nc' === self::state();
	}

	/* ------------------------------------------------------------------ */
	/* The testimonial disclaimer                                          */
	/* ------------------------------------------------------------------ */

	/** The placeholder a law site prints until counsel's wording is saved. */
	public static function placeholder(): string {
		return self::VERIFY . ' ' . __( 'Past results do not guarantee a similar outcome.', 'optima-reviews' );
	}

	/** The wording the site prints for this saved text: the text, or on a law site the placeholder. */
	public static function wording( string $saved ): string {
		$saved = trim( $saved );
		if ( '' !== $saved ) {
			return $saved;
		}
		return self::is_law() ? self::placeholder() : '';
	}

	/** The disclaimer the site prints now ('' on a site that is not a law firm's and has none). */
	public static function disclaimer(): string {
		return self::wording( (string) Optima_Reviews_Settings::get()['disclaimer'] );
	}

	public static function approval_hash( string $state, string $wording ): string {
		return md5( $state . '|' . $wording );
	}

	/**
	 * Has a named approver saved the wording the site prints now? A new state
	 * or new wording after the approval, or wording still marked for counsel,
	 * reads as not approved.
	 */
	public static function disclaimer_approved(): bool {
		$s       = Optima_Reviews_Settings::get();
		$wording = self::disclaimer();
		if ( '' === $wording || '' === trim( (string) $s['disclaimer_approver'] ) || false !== strpos( $wording, self::VERIFY ) ) {
			return false;
		}
		return hash_equals( self::approval_hash( self::state(), $wording ), (string) $s['disclaimer_approved_for'] );
	}

	/* ------------------------------------------------------------------ */
	/* Money and outcomes in a review                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Does the review mention a dollar amount, or a number near "settle",
	 * "verdict" or "award" (either side, a few words apart)?
	 */
	public static function money_flag( string $text ): bool {
		$text = wp_strip_all_tags( $text );
		if ( false !== strpos( $text, '$' ) ) {
			return true;
		}
		$near = '/\d.{0,40}?\b(settle|settled|settlement|settlements|verdict|verdicts|award|awards|awarded)\b|\b(settle|settled|settlement|settlements|verdict|verdicts|award|awards|awarded)\b.{0,40}?\d/isu';
		$hit  = (bool) preg_match( $near, $text );
		/** Filter whether a review's text is flagged for a dollar amount or an outcome figure. */
		return (bool) apply_filters( 'optima_reviews_money_flag', $hit, $text );
	}

	/** Is the review a quote about an outcome (a settlement, verdict, award or recovery)? */
	public static function outcome_quote( string $text ): bool {
		$hit = (bool) preg_match( '/\b(settle\w*|verdict\w*|award\w*|recover\w*|compensat\w*|won (?:my|our|the) case|jury)\b/iu', wp_strip_all_tags( $text ) );
		/** Filter whether a review reads as a quote about an outcome. */
		return (bool) apply_filters( 'optima_reviews_outcome_quote', $hit, $text );
	}

	/** Why this review cannot be approved on this site, or '' when it can. */
	public static function block_reason( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || ! self::is_nc() || ! self::money_flag( (string) $post->post_content ) ) {
			return '';
		}
		return __( 'This review mentions a dollar amount or a settlement, verdict or award figure. On a North Carolina site a review like this cannot be approved or shown. Skip it, or keep it as a hidden draft.', 'optima-reviews' );
	}

	/** The disclaimer printed before and after one review on the site, or '' (North Carolina, outcome quotes only). */
	public static function outcome_disclaimer( string $text ): string {
		return self::is_nc() && self::outcome_quote( $text ) ? self::disclaimer() : '';
	}

	/* ------------------------------------------------------------------ */
	/* The gate                                                             */
	/* ------------------------------------------------------------------ */

	/** A flagged review stays a draft on a North Carolina site, however it is published. */
	public static function gate( array $data, array $postarr ): array {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! $id || Optima_Reviews_Post_Type::TYPE !== ( $data['post_type'] ?? '' ) || ! in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ) {
			return $data;
		}
		if ( self::is_nc() && self::money_flag( wp_unslash( (string) ( $data['post_content'] ?? '' ) ) ) ) {
			$data['post_status'] = 'draft';
			self::$held          = true;
		}
		return $data;
	}

	public static function redirect( string $location, int $post_id ): string {
		if ( self::$held && Optima_Reviews_Post_Type::TYPE === get_post_type( $post_id ) ) {
			$location = add_query_arg( 'optima_review_held', 'nc', $location );
		}
		return $location;
	}

	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Optima_Reviews_Post_Type::TYPE !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}
		global $post;
		$reason = $post instanceof WP_Post ? self::block_reason( (int) $post->ID ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( '' !== $reason || ( isset( $_GET['optima_review_held'] ) && 'nc' === $_GET['optima_review_held'] ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Not approved. This review is not on the site.', 'optima-reviews' ) . '</strong> ' . esc_html( '' !== $reason ? $reason : __( 'On a North Carolina site a review that mentions a dollar amount or a settlement, verdict or award figure cannot be approved or shown.', 'optima-reviews' ) ) . '</p></div>';
		}
		if ( $post instanceof WP_Post && self::is_law() && get_post_meta( $post->ID, self::META_AUTO, true ) && 'publish' === $post->post_status ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'This review was added to the site automatically, without a person approving it. On a law site it stays hidden until someone reads it and presses Update.', 'optima-reviews' ) . '</p></div>';
		}
	}

	/** How many published reviews were added without a person, and are hidden on this law site. */
	public static function auto_added_count(): int {
		if ( ! self::is_law() ) {
			return 0;
		}
		return count(
			get_posts(
				array(
					'post_type'      => Optima_Reviews_Post_Type::TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => self::META_AUTO, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}
}
