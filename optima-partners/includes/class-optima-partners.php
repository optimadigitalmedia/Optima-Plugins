<?php
/**
 * The partner model.
 *
 * A partner is a optima_partner post: the name is its title, the logo its
 * featured image, and _optima_partner holds the level, link, short line,
 * placements and the dates it runs. Levels and placements are the owner's own
 * lists (Partners, Settings), written in the order they rank, so a Founding
 * partner always comes before a Regional one.
 *
 * Regions come from Optima Events when it is active (the same optima_region tag
 * its events use), so a regional partner shows beside that region's events.
 *
 * Clicks are counted per month when someone follows a partner's link, so the
 * owner can show partners what their placement did. No visitor data is kept,
 * only the number.
 *
 * @package Optima_Partners
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Partners {

	const TYPE   = 'optima_partner';
	const META   = '_optima_partner';
	const CLICKS = '_optima_partner_clicks';
	const OPTION = 'optima_partners';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		// Optima Events registers regions on init at 6; partners join them after.
		add_action( 'init', array( __CLASS__, 'join_regions' ), 20 );
	}

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'          => array(
					'name'                  => __( 'Partners', 'optima-partners' ),
					'singular_name'         => __( 'Partner', 'optima-partners' ),
					'add_new'               => __( 'Add partner', 'optima-partners' ),
					'add_new_item'          => __( 'Add partner', 'optima-partners' ),
					'edit_item'             => __( 'Edit partner', 'optima-partners' ),
					'all_items'             => __( 'All partners', 'optima-partners' ),
					'search_items'          => __( 'Search partners', 'optima-partners' ),
					'not_found'             => __( 'No partners yet.', 'optima-partners' ),
					'featured_image'        => __( 'Logo', 'optima-partners' ),
					'set_featured_image'    => __( 'Set logo', 'optima-partners' ),
					'remove_featured_image' => __( 'Remove logo', 'optima-partners' ),
					'use_featured_image'    => __( 'Use as logo', 'optima-partners' ),
				),
				// Partners show where they are placed; they have no pages of their own.
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				// Editors and administrators, like pages: placements are paid for.
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'thumbnail', 'page-attributes' ),
			)
		);
	}

	public static function join_regions(): void {
		if ( taxonomy_exists( 'optima_region' ) ) {
			register_taxonomy_for_object_type( 'optima_region', self::TYPE );
		}
	}

	/* ---------- settings ---------- */

	/** @return array{levels:string,placements:string,on_events:bool} */
	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'levels'     => (string) ( $s['levels'] ?? "Founding\nNational\nRegional" ),
			'placements' => (string) ( $s['placements'] ?? "Home page\nSidebar\nFooter\nEvent pages" ),
			'on_events'  => ! empty( $s['on_events'] ),
		);
	}

	public static function save_settings( array $in ): void {
		update_option(
			self::OPTION,
			array(
				'levels'     => sanitize_textarea_field( (string) ( $in['levels'] ?? '' ) ),
				'placements' => sanitize_textarea_field( (string) ( $in['placements'] ?? '' ) ),
				'on_events'  => ! empty( $in['on_events'] ),
			),
			false
		);
	}

	/** One name per line, keyed by slug, in the order written. */
	private static function list_of( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[ sanitize_title( $line ) ] = $line;
			}
		}
		return $out;
	}

	/** @return array<string,string> slug => name, highest level first */
	public static function levels(): array {
		return self::list_of( self::settings()['levels'] );
	}

	/** @return array<string,string> slug => name */
	public static function placements(): array {
		return self::list_of( self::settings()['placements'] );
	}

	/* ---------- partners ---------- */

	/** @return array{level:string,url:string,line:string,placements:string[],starts:string,ends:string} */
	public static function partner( int $id ): array {
		$p = get_post_meta( $id, self::META, true );
		$p = is_array( $p ) ? $p : array();
		return array(
			'level'      => (string) ( $p['level'] ?? '' ),
			'url'        => (string) ( $p['url'] ?? '' ),
			'line'       => (string) ( $p['line'] ?? '' ),
			'placements' => array_values( array_map( 'strval', (array) ( $p['placements'] ?? array() ) ) ),
			'starts'     => (string) ( $p['starts'] ?? '' ),
			'ends'       => (string) ( $p['ends'] ?? '' ),
		);
	}

	public static function save( int $id, array $in ): void {
		update_post_meta(
			$id,
			self::META,
			array(
				'level'      => sanitize_title( (string) ( $in['level'] ?? '' ) ),
				'url'        => esc_url_raw( trim( (string) ( $in['url'] ?? '' ) ), array( 'http', 'https' ) ),
				'line'       => sanitize_text_field( (string) ( $in['line'] ?? '' ) ),
				'placements' => array_values( array_intersect( array_map( 'sanitize_title', (array) ( $in['placements'] ?? array() ) ), array_keys( self::placements() ) ) ),
				'starts'     => self::clean_date( (string) ( $in['starts'] ?? '' ) ),
				'ends'       => self::clean_date( (string) ( $in['ends'] ?? '' ) ),
			)
		);
	}

	/** Running today: published, and inside its dates when it has them. */
	public static function is_live( int $id ): bool {
		if ( 'publish' !== get_post_status( $id ) ) {
			return false;
		}
		$p     = self::partner( $id );
		$today = wp_date( 'Y-m-d' );
		return ( '' === $p['starts'] || $p['starts'] <= $today ) && ( '' === $p['ends'] || $p['ends'] >= $today );
	}

	/** @return array<string,string> slug => name */
	public static function regions( int $id ): array {
		if ( ! taxonomy_exists( 'optima_region' ) ) {
			return array();
		}
		$terms = get_the_terms( $id, 'optima_region' );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[ $t->slug ] = $t->name;
		}
		return $out;
	}

	/**
	 * Live partners, highest level first, then in the order set on the list.
	 *
	 * @param array{level?:string,placement?:string,region?:string,regions?:string[]} $args
	 *        regions: show partners of any of these regions, plus those with no region (national).
	 * @return int[]
	 */
	public static function ids( array $args = array() ): array {
		$ids    = array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'orderby'        => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
				)
			)
		);
		$rank   = array_flip( array_keys( self::levels() ) );
		$level  = sanitize_title( (string) ( $args['level'] ?? '' ) );
		$place  = sanitize_title( (string) ( $args['placement'] ?? '' ) );
		$region = sanitize_title( (string) ( $args['region'] ?? '' ) );
		$near   = array_map( 'sanitize_title', (array) ( $args['regions'] ?? array() ) );
		$ids    = array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $level, $place, $region, $near ) {
					if ( ! self::is_live( $id ) ) {
						return false;
					}
					$p    = self::partner( $id );
					$mine = self::regions( $id );
					if ( '' !== $level && $p['level'] !== $level ) {
						return false;
					}
					if ( '' !== $place && ! in_array( $place, $p['placements'], true ) ) {
						return false;
					}
					if ( '' !== $region && ! isset( $mine[ $region ] ) ) {
						return false;
					}
					// Beside a region's events: that region's partners and the national ones.
					return ! $near || ! $mine || array_intersect( $near, array_keys( $mine ) );
				}
			)
		);
		$pos = array_flip( $ids );
		usort(
			$ids,
			static function ( $a, $b ) use ( $rank, $pos ) {
				$ra = $rank[ self::partner( $a )['level'] ] ?? 99;
				$rb = $rank[ self::partner( $b )['level'] ] ?? 99;
				return $ra === $rb ? $pos[ $a ] <=> $pos[ $b ] : $ra <=> $rb;
			}
		);
		return $ids;
	}

	/* ---------- clicks ---------- */

	/** Each month's count lives in its own meta key, so one SQL statement can add to it. */
	const CLICK_MONTH = '_optima_partner_clicks_';

	/** Months of counts kept for the partner report. */
	const CLICK_MONTHS = 24;

	/**
	 * Count one click: at most once a day per visitor per partner (a salted
	 * hash of the IP address and browser, kept one day, so no visitor details
	 * are stored), added in a single UPDATE so two clicks at the same moment
	 * both count.
	 */
	public static function count_click( int $id ): void {
		if ( ! self::first_click_today( $id ) ) {
			return;
		}
		global $wpdb;
		$key = self::CLICK_MONTH . wp_date( 'Y-m' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic increment; the meta API reads then writes.
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s", $id, $key ) );
		if ( ! $done && ! add_post_meta( $id, $key, 1, true ) ) {
			// Another click made this month's row a moment ago: add to it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as above.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s", $id, $key ) );
		} elseif ( ! $done ) {
			self::prune_clicks( $id ); // A new month started: drop the oldest.
		}
		wp_cache_delete( $id, 'post_meta' );
	}

	/** True the first time today this visitor follows this partner's link. */
	private static function first_click_today( int $id ): bool {
		$ip    = function_exists( 'optima_client_ip' ) ? optima_client_ip() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never stored.
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never stored.
		$key   = 'optima_partner_seen_' . substr( hash_hmac( 'sha256', $id . '|' . wp_date( 'Y-m-d' ) . '|' . $ip . '|' . $agent, wp_salt( 'nonce' ) ), 0, 24 );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		return true;
	}

	/** Keep the newest CLICK_MONTHS months of counts. */
	private static function prune_clicks( int $id ): void {
		$keys = array();
		foreach ( array_keys( (array) get_post_meta( $id ) ) as $key ) {
			if ( 0 === strpos( (string) $key, self::CLICK_MONTH ) ) {
				$keys[] = (string) $key;
			}
		}
		rsort( $keys ); // 'YYYY-MM' sorts by date.
		foreach ( array_slice( $keys, self::CLICK_MONTHS ) as $key ) {
			delete_post_meta( $id, $key );
		}
	}

	/**
	 * @return array<string,int> 'YYYY-MM' => clicks, newest first. Counts from
	 * before this release (one array in _optima_partner_clicks) are added in.
	 */
	public static function clicks( int $id ): array {
		$c = get_post_meta( $id, self::CLICKS, true );
		$c = is_array( $c ) ? array_map( 'intval', $c ) : array();
		foreach ( (array) get_post_meta( $id ) as $key => $values ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, self::CLICK_MONTH ) ) {
				$month       = substr( $key, strlen( self::CLICK_MONTH ) );
				$c[ $month ] = (int) ( $c[ $month ] ?? 0 ) + (int) ( $values[0] ?? 0 );
			}
		}
		krsort( $c );
		return array_slice( $c, 0, self::CLICK_MONTHS, true );
	}

	public static function clean_date( string $d ): string {
		$d = trim( $d );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) ) ? $d : '';
	}
}
