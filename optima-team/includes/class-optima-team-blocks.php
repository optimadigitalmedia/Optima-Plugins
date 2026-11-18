<?php
/**
 * The page-layer blocks Optima Team fills (release 2 contract, section 3).
 * Themes place them with optima_slot( $name, $args ); each prints nothing when
 * it has nothing to show, and the theme's variant comes from layout.blocks in
 * optima-setup.json. Unknown variants fall back to the default.
 *
 * bio-glance          The at-a-glance card on a bio: the name as on the bar
 *                     roll, role, office, where licensed, board certification
 *                     by its body, the headline honor with its year,
 *                     languages, and two buttons (call, and a free
 *                     consultation when the firm offers one). Variants: card
 *                     (default), split (with the photo).
 * practice-attorneys  The attorneys who handle a practice area (args
 *                     practice => slug) or work at an office (office => id),
 *                     each linking to the person's one bio. Variants:
 *                     portrait (default), horizontal.
 *
 * Calls report as call_click (rule 50) with location bio_glance.
 *
 * @package Optima_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Team_Blocks {

	public static function boot(): void {
		add_action( 'optima_slot_bio-glance', array( __CLASS__, 'glance' ) );
		add_action( 'optima_slot_practice-attorneys', array( __CLASS__, 'attorneys' ) );
	}

	/**
	 * The profile this page is the bio of: the profile page itself, or the
	 * theme page a profile names as its bio. 0 when neither.
	 */
	public static function current_person(): int {
		if ( is_singular( Optima_Team::POST_TYPE ) ) {
			return (int) get_queried_object_id();
		}
		if ( ! is_singular() ) {
			return 0;
		}
		$here = untrailingslashit( (string) wp_parse_url( (string) get_permalink( get_queried_object_id() ), PHP_URL_PATH ) );
		foreach ( Optima_Team::bio_pages() as $member => $bio ) {
			$path = untrailingslashit( (string) wp_parse_url( strtok( Optima_Team::site_address( $bio ), '#' ), PHP_URL_PATH ) );
			if ( $path === $here ) {
				return (int) $member;
			}
		}
		return 0;
	}

	/** The number the Call button dials: the person's first office, else the firm's phone on Details. */
	private static function phone( array $p ): string {
		foreach ( (array) $p['offices'] as $id ) {
			$phone = (string) ( Optima_Team::offices()[ $id ]['phone'] ?? '' );
			if ( '' !== $phone ) {
				return $phone;
			}
		}
		return (string) ( Optima_Admin_UI::business()['phone'] ?? '' );
	}

	/* ---------- bio-glance ---------- */

	/**
	 * @param array $args { variant: card|split, person?: profile id }.
	 */
	public static function glance( $args = array() ): void {
		$args = is_array( $args ) ? $args : array();
		$id   = ! empty( $args['person'] ) ? absint( $args['person'] ) : self::current_person();
		if ( ! $id || Optima_Team::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return;
		}
		$variant = in_array( (string) ( $args['variant'] ?? '' ), array( 'card', 'split' ), true ) ? (string) $args['variant'] : 'card';
		$p       = Optima_Team::profile( $id );
		$s       = Optima_Team::settings();
		$law     = Optima_Team::is_law() && Optima_Team::is_attorney( $p );
		$name    = '' !== $p['bar_name'] && $law ? $p['bar_name'] : wp_strip_all_tags( get_the_title( $id ) );

		$rows = array();
		if ( '' !== $p['role'] ) {
			$rows[ __( 'Role', 'optima-team' ) ] = esc_html( $p['role'] );
		}
		$offices = array_filter( array_map( array( 'Optima_Team', 'office_name' ), (array) $p['offices'] ) );
		if ( $offices ) {
			$rows[ _n( 'Office', 'Offices', count( $offices ), 'optima-team' ) ] = esc_html( implode( ', ', $offices ) );
		}
		if ( $law && $p['bar'] ) {
			$states = array();
			foreach ( $p['bar'] as $row ) {
				$states[] = '' !== $row['year']
					/* translators: 1: state, 2: year admitted */
					? sprintf( __( '%1$s, since %2$s', 'optima-team' ), $row['state'], $row['year'] )
					: $row['state'];
			}
			$rows[ __( 'Licensed in', 'optima-team' ) ] = esc_html( implode( '; ', $states ) );
		}
		if ( $p['certifications'] ) {
			$rows[ __( 'Board certification', 'optima-team' ) ] = esc_html( implode( '; ', array_map( array( 'Optima_Team', 'certification_line' ), (array) $p['certifications'] ) ) );
		}
		$honor = Optima_Team::headline_award( $p );
		if ( $honor ) {
			$rows[ __( 'Honor', 'optima-team' ) ] = Optima_Team_Front::award_html( $honor );
		}
		$langs = Optima_Team::languages( $p );
		if ( $langs ) {
			$rows[ __( 'Languages', 'optima-team' ) ] = esc_html( implode( ', ', $langs ) );
		}

		$phone   = self::phone( $p );
		$consult = '' !== (string) $s['consult'] ? Optima_Team::site_address( (string) $s['consult'] ) : '';
		$photo   = 'split' === $variant && has_post_thumbnail( $id );

		Optima_Team_Front::$glance = array(
			'id'    => $id,
			'photo' => $photo,
		);

		$h  = Optima_Team_Front::style_tag();
		$h .= '<section class="optima-team-glance optima-team-glance--' . esc_attr( $variant ) . '" aria-label="' . esc_attr__( 'At a glance', 'optima-team' ) . '">';
		if ( $photo ) {
			$h .= '<div class="optima-team-glance__photo">' . get_the_post_thumbnail( $id, 'medium_large', array( 'alt' => esc_attr( wp_strip_all_tags( get_the_title( $id ) ) ), 'fetchpriority' => 'high', 'loading' => 'eager' ) ) . '</div>';
		}
		$h .= '<div class="optima-team-glance__body">';
		$h .= '<p class="optima-team-glance__name">' . esc_html( $name ) . '</p>';
		if ( $rows ) {
			$h .= '<dl class="optima-team-glance__facts">';
			foreach ( $rows as $label => $value ) {
				$h .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>';
			}
			$h .= '</dl>';
		}
		if ( '' !== $phone || '' !== $consult ) {
			$h .= '<p class="optima-team-glance__actions">';
			if ( '' !== $phone ) {
				/* translators: %s: phone number */
				$h .= '<a class="optima-team-cta optima-team-cta--primary" href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) ) . '" data-optima-track="call_click" data-optima-location="bio_glance" data-optima-label="' . esc_attr( wp_strip_all_tags( get_the_title( $id ) ) ) . '">' . esc_html( sprintf( __( 'Call %s', 'optima-team' ), $phone ) ) . '</a>';
			}
			if ( '' !== $consult ) {
				$h .= '<a class="optima-team-cta" href="' . esc_url( $consult ) . '">' . esc_html__( 'Free consultation', 'optima-team' ) . '</a>';
			}
			$h .= '</p>';
		}
		$h .= '</div></section>';
		echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}

	/* ---------- practice-attorneys ---------- */

	/**
	 * The people who match a practice area or an office. On a law site only
	 * attorneys; elsewhere everyone. Both empty: every attorney.
	 *
	 * @param string $practice A practice slug, or ''.
	 * @param string $office   An office id, or ''.
	 * @return WP_Post[]
	 */
	public static function people_for( string $practice, string $office ): array {
		$people = get_posts(
			array(
				'post_type'      => Optima_Team::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'  => true,
			)
		);
		$law = Optima_Team::is_law();
		$out = array();
		foreach ( $people as $person ) {
			$p = Optima_Team::profile( (int) $person->ID );
			if ( $law && ! Optima_Team::is_attorney( $p ) ) {
				continue;
			}
			if ( '' !== $practice && ! Optima_Team::handles( $p, $practice ) ) {
				continue;
			}
			if ( '' !== $office && ! in_array( $office, (array) $p['offices'], true ) ) {
				continue;
			}
			$out[] = $person;
		}
		return $out;
	}

	/**
	 * @param array $args { practice: slug|'', office: id|'', variant: portrait|horizontal, limit?: int }.
	 */
	public static function attorneys( $args = array() ): void {
		$args     = is_array( $args ) ? $args : array();
		$practice = trim( (string) ( $args['practice'] ?? '' ), '/' );
		$practice = Optima_Team::is_slug( $practice ) ? $practice : '';
		$office   = sanitize_key( (string) ( $args['office'] ?? '' ) );
		$variant  = in_array( (string) ( $args['variant'] ?? '' ), array( 'portrait', 'horizontal' ), true ) ? (string) $args['variant'] : 'portrait';
		$people   = self::people_for( $practice, $office );
		$limit    = absint( $args['limit'] ?? 0 );
		if ( $limit ) {
			$people = array_slice( $people, 0, $limit );
		}
		if ( ! $people ) {
			return;
		}
		$h = Optima_Team_Front::style_tag();
		$h .= '<ul class="optima-team-grid optima-team-attorneys optima-team-attorneys--' . esc_attr( $variant ) . '" style="--optima-team-cols:' . ( 'horizontal' === $variant ? 2 : 3 ) . '">';
		foreach ( $people as $person ) {
			$h .= Optima_Team_Front::card( $person, 'h3', 'horizontal' === $variant );
		}
		$h .= '</ul>';
		echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in card().
	}
}
