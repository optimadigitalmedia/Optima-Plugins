<?php
/**
 * The team content model: the optima_team post type, the one profile meta
 * array per person, the site settings, and the trust checklist.
 *
 * Optima Core (1.1.x) has no person type, so this plugin registers its own.
 * Each person is a post: the title is the name, the featured image is the
 * photo, the editor is an optional full bio, and everything else lives in one
 * protected meta value (_optima_team) so a client theme can never drift from it.
 *
 * @package Optima_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Team {

	const POST_TYPE = 'optima_team';
	const META      = '_optima_team';
	const OPTION    = 'optima_team';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
		// One public address per person: a profile whose bio lives on a theme page
		// forwards there, links point there, and the sitemap skips the copy.
		add_action( 'template_redirect', array( __CLASS__, 'forward_to_bio' ), 1 );
		add_filter( 'post_type_link', array( __CLASS__, 'bio_link' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_skip' ), 20, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 20 );
	}

	public static function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'                  => __( 'Profiles', 'optima-team' ),
					'singular_name'         => __( 'Profile', 'optima-team' ),
					'menu_name'             => __( 'Profiles', 'optima-team' ),
					'add_new'               => __( 'Add a person', 'optima-team' ),
					'add_new_item'          => __( 'Add a person', 'optima-team' ),
					'edit_item'             => __( 'Profile', 'optima-team' ),
					'new_item'              => __( 'New profile', 'optima-team' ),
					'view_item'             => __( 'View profile', 'optima-team' ),
					'view_items'            => __( 'View team', 'optima-team' ),
					'search_items'          => __( 'Search team', 'optima-team' ),
					'all_items'             => __( 'Profiles', 'optima-team' ),
					'not_found'             => __( 'No profiles yet.', 'optima-team' ),
					'not_found_in_trash'    => __( 'No profiles in the trash.', 'optima-team' ),
					'featured_image'        => __( 'Photo', 'optima-team' ),
					'set_featured_image'    => __( 'Set photo', 'optima-team' ),
					'remove_featured_image' => __( 'Remove photo', 'optima-team' ),
					'use_featured_image'    => __( 'Use as photo', 'optima-team' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-groups',
				'menu_position' => 26,
				'has_archive'  => 'team',
				'rewrite'      => array(
					'slug'       => 'team',
					'with_front' => false,
				),
				'supports'     => array( 'title', 'editor', 'thumbnail', 'page-attributes', 'revisions' ),
			)
		);
	}

	/* ---------- settings ---------- */

	public static function defaults(): array {
		return array(
			'law'     => 'auto',
			'contact' => 0,
			'columns' => 3,
			'bylines' => 1,
			'consult' => '', // Release 2: the free consultation page (a path on this site); '' = the firm offers none.
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Is this a law firm site? "auto" reads the Optima Core schema type.
	 */
	public static function is_law(): bool {
		$mode = self::settings()['law'];
		if ( 'on' === $mode ) {
			return true;
		}
		if ( 'off' === $mode ) {
			return false;
		}
		if ( function_exists( 'optima_core_business' ) ) {
			$type = (string) ( optima_core_business()['business_type'] ?? '' );
			if ( preg_match( '/legal|attorney|lawyer|law ?firm/i', $type ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- the profile ---------- */

	/** Military branches offered in the editor. */
	public static function branches(): array {
		return array(
			''             => __( 'None', 'optima-team' ),
			'army'         => __( 'U.S. Army', 'optima-team' ),
			'navy'         => __( 'U.S. Navy', 'optima-team' ),
			'air_force'    => __( 'U.S. Air Force', 'optima-team' ),
			'marine_corps' => __( 'U.S. Marine Corps', 'optima-team' ),
			'coast_guard'  => __( 'U.S. Coast Guard', 'optima-team' ),
			'space_force'  => __( 'U.S. Space Force', 'optima-team' ),
			'guard'        => __( 'National Guard', 'optima-team' ),
			'reserve'      => __( 'Reserves', 'optima-team' ),
		);
	}

	/** Columns in each repeatable group. A row needs its first column. */
	public static function groups(): array {
		return array(
			'bar'            => array( 'state', 'year', 'number', 'url' ),
			'education'      => array( 'school', 'degree', 'year' ),
			// Release 2: how the award is worded, the issuer's own page on how it is
			// given, and whether a fee was paid for it (a paid award never prints on a law site).
			'awards'         => array( 'name', 'year', 'issuer', 'wording', 'standards_url', 'fee_paid' ),
			// Release 2: board certification by a named certifying body.
			'certifications' => array( 'name', 'body', 'year', 'url' ),
		);
	}

	/** How an award is worded on the page: "Named to", "Included in", "Selected for". */
	public static function award_wordings(): array {
		return array(
			'named'    => __( 'Named to', 'optima-team' ),
			'included' => __( 'Included in', 'optima-team' ),
			'selected' => __( 'Selected for', 'optima-team' ),
		);
	}

	/**
	 * The team groups, in the order the team grid lists them: attorneys first,
	 * then the people who are not lawyers, grouped by what they do.
	 */
	public static function team_groups(): array {
		return array(
			'attorney'        => __( 'Attorneys', 'optima-team' ),
			'intake'          => __( 'Intake', 'optima-team' ),
			'case_management' => __( 'Case management', 'optima-team' ),
			'paralegal'       => __( 'Paralegals', 'optima-team' ),
			'client_care'     => __( 'Client care', 'optima-team' ),
		);
	}

	/**
	 * A person's group: the one saved on the profile, else attorney for anyone
	 * marked as an attorney, else '' (no group).
	 *
	 * @param array $p A profile.
	 */
	public static function group_of( array $p ): string {
		$group = (string) ( $p['group'] ?? '' );
		if ( isset( self::team_groups()[ $group ] ) ) {
			return $group;
		}
		return ! empty( $p['attorney'] ) ? 'attorney' : '';
	}

	/** Is this person an attorney (the attorney flag, or the attorney group)? */
	public static function is_attorney( array $p ): bool {
		return ! empty( $p['attorney'] ) || 'attorney' === (string) ( $p['group'] ?? '' );
	}

	public static function empty_profile(): array {
		return array(
			'role'            => '',
			'bio'             => '',
			'email'           => '',
			'phone'           => '',
			'user'            => 0,
			'attorney'        => 0,
			'military_branch' => '',
			'military_years'  => '',
			'languages'       => '',
			'memberships'     => array(),
			'same_as'         => array(),
			'bar'             => array(),
			'education'       => array(),
			'awards'          => array(),
			'certifications'  => array(),
			'practice_areas'  => array(),
			'bio_url'         => '',
			'schema_id'       => '',
			// Release 2.
			'bar_name'        => '', // The name as it appears on the bar roll (from the setup file).
			'group'           => '', // A team_groups() key, or '' (attorney when the attorney flag is on).
			'offices'         => array(), // Office ids from the setup file's offices[].
		);
	}

	/**
	 * The stored profile, always in the full shape.
	 *
	 * @param int $post_id Team member post ID.
	 */
	public static function profile( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META, true );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::empty_profile() );
	}

	/**
	 * Clean a submitted profile.
	 *
	 * @param array $in Raw (unslashed) input.
	 */
	public static function sanitize_profile( array $in ): array {
		$out                    = self::empty_profile();
		$out['role']            = sanitize_text_field( (string) ( $in['role'] ?? '' ) );
		$out['bio']             = sanitize_textarea_field( (string) ( $in['bio'] ?? '' ) );
		$out['email']           = sanitize_email( (string) ( $in['email'] ?? '' ) );
		$out['phone']           = preg_replace( '/[^0-9+().\-\s]/', '', sanitize_text_field( (string) ( $in['phone'] ?? '' ) ) );
		$out['user']            = absint( $in['user'] ?? 0 );
		$out['attorney']        = ! empty( $in['attorney'] ) ? 1 : 0;
		$branch                 = sanitize_key( (string) ( $in['military_branch'] ?? '' ) );
		$out['military_branch'] = array_key_exists( $branch, self::branches() ) ? $branch : '';
		$out['military_years']  = sanitize_text_field( (string) ( $in['military_years'] ?? '' ) );
		$out['languages']       = sanitize_text_field( (string) ( $in['languages'] ?? '' ) );
		$out['memberships']     = self::lines( (string) ( $in['memberships'] ?? '' ) );
		$out['practice_areas']  = self::clean_practices( $in['practice_areas'] ?? array(), (string) ( $in['practice_other'] ?? '' ) );
		$out['bio_url']         = self::site_path( (string) ( $in['bio_url'] ?? '' ) );
		$out['schema_id']       = self::site_path( (string) ( $in['schema_id'] ?? '' ), true );
		$out['bar_name']        = sanitize_text_field( (string) ( $in['bar_name'] ?? '' ) );
		$group                  = sanitize_key( (string) ( $in['group'] ?? '' ) );
		$out['group']           = isset( self::team_groups()[ $group ] ) ? $group : '';
		$out['offices']         = self::clean_offices( $in['offices'] ?? array() );

		$urls = array();
		foreach ( self::lines( (string) ( $in['same_as'] ?? '' ) ) as $line ) {
			$url = esc_url_raw( $line, array( 'https', 'http' ) );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		$out['same_as'] = array_values( array_unique( $urls ) );

		foreach ( self::groups() as $group => $cols ) {
			$rows = array();
			foreach ( (array) ( $in[ $group ] ?? array() ) as $row ) {
				$row   = (array) $row;
				$clean = array();
				foreach ( $cols as $col ) {
					$value = $row[ $col ] ?? '';
					$value = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value;
					if ( 'url' === $col || 'standards_url' === $col ) {
						$clean[ $col ] = esc_url_raw( trim( $value ), array( 'https', 'http' ) );
					} elseif ( 'year' === $col ) {
						$clean[ $col ] = preg_match( '/^\d{4}$/', trim( $value ) ) ? trim( $value ) : '';
					} elseif ( 'wording' === $col ) {
						$clean[ $col ] = self::wording_key( $value );
					} elseif ( 'fee_paid' === $col ) {
						$yes           = array( 'yes', 'y', 'true', '1' );
						$no            = array( 'no', 'n', 'false', '0' );
						$flag          = strtolower( trim( $value ) );
						$clean[ $col ] = in_array( $flag, $yes, true ) ? 'yes' : ( in_array( $flag, $no, true ) ? 'no' : '' );
					} else {
						$clean[ $col ] = sanitize_text_field( $value );
					}
				}
				// A row needs its first column (state, school, award name).
				if ( '' !== $clean[ $cols[0] ] ) {
					$rows[] = $clean;
				}
			}
			$out[ $group ] = array_slice( $rows, 0, 30 );
		}
		return $out;
	}

	/** Split text into trimmed, non-empty lines. */
	public static function lines( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = sanitize_text_field( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/** An award wording key from a key or the words themselves ("Included in"). */
	public static function wording_key( string $value ): string {
		$value = strtolower( trim( sanitize_text_field( $value ) ) );
		if ( isset( self::award_wordings()[ $value ] ) ) {
			return $value;
		}
		foreach ( self::award_wordings() as $key => $words ) {
			if ( strtolower( $words ) === $value || 0 === strpos( $value, $key ) ) {
				return $key;
			}
		}
		return '';
	}

	/* ---------- awards and certifications ---------- */

	/**
	 * May this award print? On a law site an award the firm or the lawyer paid
	 * a fee for is never printed (page or schema); the profile screen says why.
	 *
	 * @param array $row An award row.
	 */
	public static function award_printable( array $row ): bool {
		return ! ( self::is_law() && 'yes' === (string) ( $row['fee_paid'] ?? '' ) );
	}

	/** The awards that may print, in their saved order. */
	public static function printable_awards( array $p ): array {
		return array_values( array_filter( (array) $p['awards'], array( __CLASS__, 'award_printable' ) ) );
	}

	/**
	 * One award as a line: "Included in Best Lawyers, Issuer, 2025", or the
	 * old "Name, Issuer, 2025" when no wording is set.
	 *
	 * @param array $row An award row.
	 */
	public static function award_line( array $row ): string {
		$name    = (string) ( $row['name'] ?? '' );
		$wording = self::award_wordings()[ (string) ( $row['wording'] ?? '' ) ] ?? '';
		if ( '' !== $wording ) {
			$name = $wording . ' ' . $name;
		}
		return implode( ', ', array_filter( array( $name, (string) ( $row['issuer'] ?? '' ), (string) ( $row['year'] ?? '' ) ), 'strlen' ) );
	}

	/**
	 * The honor the at-a-glance card leads with: the first printable award
	 * that carries a year (the setup file's order is the firm's order).
	 */
	public static function headline_award( array $p ): array {
		foreach ( self::printable_awards( $p ) as $row ) {
			if ( '' !== (string) ( $row['year'] ?? '' ) ) {
				return $row;
			}
		}
		return array();
	}

	/**
	 * One certification as a line: "Board certified in Civil Trial Law by the
	 * Florida Bar, 2019". With no body it reads "Certified in X".
	 *
	 * @param array $row A certification row.
	 */
	public static function certification_line( array $row ): string {
		$name = (string) ( $row['name'] ?? '' );
		$body = (string) ( $row['body'] ?? '' );
		$line = '' !== $body
			/* translators: 1: what the person is certified in, 2: the certifying body. */
			? sprintf( __( 'Board certified in %1$s by %2$s', 'optima-team' ), $name, $body )
			/* translators: %s: what the person is certified in. */
			: sprintf( __( 'Certified in %s', 'optima-team' ), $name );
		return '' !== (string) ( $row['year'] ?? '' ) ? $line . ', ' . $row['year'] : $line;
	}

	/* ---------- offices ---------- */

	/** Office ids: a list, or a comma separated string from the setup file. */
	public static function clean_offices( $list ): array {
		$items = is_array( $list ) ? $list : preg_split( '/[,\s]+/', (string) $list );
		$out   = array();
		foreach ( (array) $items as $item ) {
			$id = sanitize_key( (string) $item );
			if ( '' !== $id && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * The firm's offices: id => office row. Read from the `optima_offices`
	 * filter when a plugin provides one (Optima Core's Offices tab), else from
	 * the setup file's offices[] section. Empty on a one-office site.
	 *
	 * @return array<string, array>
	 */
	public static function offices(): array {
		static $offices = null;
		if ( null !== $offices ) {
			return $offices;
		}
		// Optima Core's office list (filled from the setup file by Optima Build), else the setup file itself.
		$rows = get_option( 'optima_core_offices', array() );
		$rows = is_array( $rows ) ? $rows : array();
		if ( ! $rows && class_exists( 'Optima_Hub_Setup' ) ) {
			$rows = (array) ( Optima_Hub_Setup::data()['offices'] ?? array() );
		}
		/** Filter the firm's offices (rows shaped like the setup file's offices[]). */
		$rows    = (array) apply_filters( 'optima_offices', $rows );
		$offices = array();
		foreach ( $rows as $key => $row ) {
			$row = (array) $row;
			$id  = sanitize_key( (string) ( $row['id'] ?? ( is_string( $key ) ? $key : '' ) ) );
			if ( '' !== $id ) {
				$offices[ $id ] = $row;
			}
		}
		return $offices;
	}

	/** An office's name, or ''. */
	public static function office_name( string $id ): string {
		$row = self::offices()[ $id ] ?? array();
		if ( ! $row ) {
			return '';
		}
		$name = (string) ( $row['name'] ?? '' );
		return '' !== $name ? $name : (string) ( $row['city'] ?? '' );
	}

	/* ---------- practice areas ---------- */

	/**
	 * The shared practice-area list (law-site blueprint, "The shared
	 * practice-area list"): one slug per case type, the same on every site,
	 * matching the practice page address and the case results area.
	 *
	 * @return array<string, string> slug => name on the site.
	 */
	public static function practices(): array {
		return array(
			'car-accidents'        => __( 'Car accidents', 'optima-team' ),
			'truck-accidents'      => __( 'Truck accidents', 'optima-team' ),
			'motorcycle-accidents' => __( 'Motorcycle accidents', 'optima-team' ),
			'pedestrian-accidents' => __( 'Pedestrian accidents', 'optima-team' ),
			'bicycle-accidents'    => __( 'Bicycle accidents', 'optima-team' ),
			'rideshare-accidents'  => __( 'Uber and Lyft accidents', 'optima-team' ),
			'bus-accidents'        => __( 'Bus accidents', 'optima-team' ),
			'wrongful-death'       => __( 'Wrongful death', 'optima-team' ),
			'brain-injury'         => __( 'Brain injury', 'optima-team' ),
			'spinal-cord-injury'   => __( 'Spinal cord injury', 'optima-team' ),
			'burn-injury'          => __( 'Burn injury', 'optima-team' ),
			'catastrophic-injury'  => __( 'Catastrophic injury', 'optima-team' ),
			'premises-liability'   => __( 'Slip and fall and unsafe property', 'optima-team' ),
			'dog-bites'            => __( 'Dog bites', 'optima-team' ),
			'medical-malpractice'  => __( 'Medical malpractice', 'optima-team' ),
			'nursing-home-abuse'   => __( 'Nursing home abuse', 'optima-team' ),
			'product-liability'    => __( 'Defective products', 'optima-team' ),
			'workers-compensation' => __( 'Workers\' compensation', 'optima-team' ),
			'maritime-injury'      => __( 'Maritime and boating injury', 'optima-team' ),
		);
	}

	/** A slug from the list or shaped like one ("truck-accidents/underride-crashes"). */
	public static function is_slug( string $value ): bool {
		return (bool) preg_match( '#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#', $value );
	}

	/**
	 * Clean the practice areas: picks from the shared list (an array, or a
	 * comma or line separated string from the setup file) plus free entries,
	 * one per line. A free entry that names a listed case type becomes its slug.
	 *
	 * @param array|string $list  Slugs or names.
	 * @param string       $other Free entries, one per line.
	 */
	public static function clean_practices( $list, string $other = '' ): array {
		$items = is_array( $list ) ? $list : preg_split( '/[,\r\n]+/', (string) $list );
		$items = array_merge( (array) $items, preg_split( '/\r\n|\r|\n/', $other ) );
		$known = self::practices();
		$names = array_flip( array_map( 'strtolower', $known ) ); // name => slug
		$out   = array();
		foreach ( $items as $item ) {
			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' === $item ) {
				continue;
			}
			$lower = strtolower( $item );
			if ( isset( $names[ $lower ] ) ) {
				$item = (string) $names[ $lower ];
			} elseif ( isset( $known[ sanitize_title( $item ) ] ) ) {
				$item = sanitize_title( $item );
			}
			if ( ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
		}
		return array_slice( $out, 0, 30 );
	}

	/**
	 * The person's practice areas for the page and the schema: a name for each,
	 * and the address of the practice page when the site has a published page
	 * at that slug.
	 *
	 * @param array $p A profile.
	 * @return array<int, array{name:string, url:string}>
	 */
	public static function practice_links( array $p ): array {
		$known = self::practices();
		$out   = array();
		foreach ( (array) $p['practice_areas'] as $item ) {
			$item = (string) $item;
			$slug = self::is_slug( $item ) ? $item : sanitize_title( $item );
			$page = '' !== $slug ? self::practice_page( $slug ) : null;
			$url  = $page ? (string) get_permalink( $page ) : '';
			if ( isset( $known[ $item ] ) ) {
				$name = $known[ $item ];
			} elseif ( self::is_slug( $item ) ) {
				$name = '' !== $url ? wp_strip_all_tags( get_the_title( $page ) ) : ucfirst( str_replace( array( '-', '/' ), array( ' ', ', ' ), $item ) );
			} else {
				$name = $item;
			}
			$out[] = array(
				'name' => html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ),
				'url'  => $url,
			);
		}
		return $out;
	}

	/**
	 * The published practice page for a slug: the page at that address
	 * ("truck-accidents" or "truck-accidents/underride-crashes"), else a page
	 * whose `_optima_practice` field names the slug, else the one page with
	 * that last address part under any parent. Null when there is none.
	 *
	 * @param string $slug A practice slug.
	 */
	public static function practice_page( string $slug ): ?WP_Post {
		static $cache = array();
		$slug = trim( $slug, '/' );
		if ( '' === $slug || ! self::is_slug( $slug ) ) {
			return null;
		}
		if ( array_key_exists( $slug, $cache ) ) {
			return $cache[ $slug ];
		}
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! ( $page instanceof WP_Post && 'publish' === $page->post_status ) ) {
			$page  = null;
			$found = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'meta_key'       => '_optima_practice', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( $found ) {
				$page = $found[0];
			} else {
				$base  = basename( $slug );
				$found = get_posts(
					array(
						'post_type'      => 'page',
						'post_status'    => 'publish',
						'name'           => $base,
						'posts_per_page' => 2,
						'no_found_rows'  => true,
					)
				);
				$page  = 1 === count( $found ) ? $found[0] : null;
			}
		}
		$cache[ $slug ] = $page;
		return $page;
	}

	/** The practice page address for a slug, or ''. */
	public static function practice_url( string $slug ): string {
		$page = self::practice_page( $slug );
		return $page ? (string) get_permalink( $page ) : '';
	}

	/**
	 * Does a person handle this practice? The slug itself, a sub-practice of
	 * an area they hold ("truck-accidents/underride-crashes" for
	 * "truck-accidents"), or the parent of one they hold.
	 *
	 * @param array  $p    A profile.
	 * @param string $slug A practice slug.
	 */
	public static function handles( array $p, string $slug ): bool {
		$slug = trim( $slug, '/' );
		foreach ( (array) $p['practice_areas'] as $item ) {
			$item = (string) $item;
			$item = self::is_slug( $item ) ? $item : sanitize_title( $item );
			if ( $item === $slug || 0 === strpos( $slug, $item . '/' ) || 0 === strpos( $item, $slug . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- one public address per person ---------- */

	/**
	 * A site address as a path (with an optional #fragment), so it moves with
	 * the site from staging to live. Takes "/about/", "/about/#jane" or a full
	 * address on this site; anything on another site is dropped.
	 *
	 * @param string $value     What was typed or read from the setup file.
	 * @param bool   $fragment  Keep the #fragment (for a schema id).
	 */
	public static function site_path( string $value, bool $fragment = false ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		if ( isset( $parts['host'] ) ) {
			// This site, or its live address (Optima Core's production_url) when typed on staging.
			$hosts = array( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			if ( class_exists( 'Optima_SEO_Setup' ) && method_exists( 'Optima_SEO_Setup', 'site' ) ) {
				$hosts[] = (string) wp_parse_url( (string) Optima_SEO_Setup::site( 'production_url' ), PHP_URL_HOST );
			}
			$bare  = static fn( $h ) => preg_replace( '/^www\./', '', strtolower( (string) $h ) );
			if ( ! in_array( $bare( $parts['host'] ), array_filter( array_map( $bare, $hosts ) ), true ) ) {
				return '';
			}
		} elseif ( '/' !== substr( $value, 0, 1 ) && ! ( $fragment && '#' === substr( $value, 0, 1 ) ) ) {
			return '';
		}
		$path = (string) ( $parts['path'] ?? '/' );
		$path = '/' . ltrim( preg_replace( '#[^A-Za-z0-9/_.~%-]#', '', $path ), '/' );
		$hash = $fragment ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $parts['fragment'] ?? '' ) ) : '';
		return $path . ( '' !== $hash ? '#' . $hash : '' );
	}

	/** A stored site path as a full address on this site. */
	public static function site_address( string $path ): string {
		if ( '' === $path ) {
			return '';
		}
		$hash = '';
		if ( false !== strpos( $path, '#' ) ) {
			list( $path, $hash ) = explode( '#', $path, 2 );
		}
		return home_url( '' !== $path ? $path : '/' ) . ( '' !== $hash ? '#' . $hash : '' );
	}

	/**
	 * The person's one public page: the theme's bio page when the profile names
	 * one, else the profile page.
	 *
	 * @param int $post_id Profile post ID.
	 */
	public static function public_url( int $post_id ): string {
		$bio = (string) self::profile( $post_id )['bio_url'];
		if ( '' !== $bio ) {
			return self::site_address( $bio );
		}
		return (string) get_permalink( $post_id );
	}

	/** Profiles that live on a theme bio page: post ID => bio path. */
	public static function bio_pages(): array {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map = array();
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		foreach ( $ids as $id ) {
			$bio = (string) self::profile( (int) $id )['bio_url'];
			if ( '' !== $bio ) {
				$map[ (int) $id ] = $bio;
			}
		}
		return $map;
	}

	/** On the site, links to a profile with a bio page go to that page. */
	public static function bio_link( $link, $post ) {
		if ( is_admin() || ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return $link;
		}
		$bio = (string) self::profile( (int) $post->ID )['bio_url'];
		return '' !== $bio ? self::site_address( $bio ) : $link;
	}

	/** The profile page of someone whose bio lives on a theme page forwards there (301). */
	public static function forward_to_bio(): void {
		if ( ! is_singular( self::POST_TYPE ) || is_preview() ) {
			return;
		}
		$bio = (string) self::profile( (int) get_queried_object_id() )['bio_url'];
		if ( '' === $bio ) {
			return;
		}
		$to = strtok( self::site_address( $bio ), '#' );
		if ( $to && wp_safe_redirect( $to, 301, 'Optima Team' ) ) {
			exit;
		}
	}

	/** Profiles that forward to a bio page stay out of the sitemap. */
	public static function sitemap_skip( $args, $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return $args;
		}
		$skip = array_keys( self::bio_pages() );
		if ( $skip ) {
			$args['post__not_in'] = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $args['post__not_in'] ?? array() ) ), $skip ) ) );
		}
		return $args;
	}

	/**
	 * The /team/ list (the post type archive) is a list of links, not a page of
	 * its own: noindex, follow, so search engines read each person once, on
	 * their bio. Same for a forwarded profile seen in a preview.
	 */
	public static function robots( $robots ) {
		$copy = is_singular( self::POST_TYPE ) && '' !== (string) self::profile( (int) get_queried_object_id() )['bio_url'];
		if ( is_post_type_archive( self::POST_TYPE ) || $copy ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	}

	/** Languages as a list. */
	public static function languages( array $p ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $p['languages'] ) ) ) );
	}

	/** "U.S. Navy, 2004 to 2012" or ''. */
	public static function military_line( array $p ): string {
		if ( '' === $p['military_branch'] ) {
			return '';
		}
		$branch = self::branches()[ $p['military_branch'] ] ?? '';
		return '' !== $p['military_years'] ? $branch . ', ' . $p['military_years'] : $branch;
	}

	/** Team member linked to a WordPress user, for bylines. */
	public static function member_for_user( int $user_id ): int {
		if ( ! $user_id ) {
			return 0;
		}
		static $map = null;
		if ( null === $map ) {
			$map = array();
			$ids = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				$uid = (int) self::profile( (int) $id )['user'];
				if ( $uid && ! isset( $map[ $uid ] ) ) {
					$map[ $uid ] = (int) $id;
				}
			}
		}
		return $map[ $user_id ] ?? 0;
	}

	/**
	 * What a visitor (and Google) looks for to trust a real person. Each check
	 * is key => [ label, note, done ].
	 *
	 * @param int $post_id Team member post ID.
	 */
	public static function checks( int $post_id ): array {
		$p      = self::profile( $post_id );
		$checks = array(
			'photo'     => array( __( 'Real photo', 'optima-team' ), __( 'A photo of the person, never stock', 'optima-team' ), has_post_thumbnail( $post_id ) ),
			'bio'       => array( __( 'Bio and role', 'optima-team' ), __( 'A role and a short bio in plain language', 'optima-team' ), '' !== $p['role'] && '' !== $p['bio'] ),
			'byline'    => array( __( 'Byline on posts', 'optima-team' ), __( 'Their posts link back to this profile', 'optima-team' ), $p['user'] > 0 ),
			'education' => array( __( 'Education', 'optima-team' ), __( 'At least one school', 'optima-team' ), ! empty( $p['education'] ) ),
		);
		if ( self::is_law() && $p['attorney'] ) {
			$checks['bar'] = array( __( 'Bar admissions', 'optima-team' ), __( 'Each state, with the year admitted', 'optima-team' ), ! empty( $p['bar'] ) );
		}
		$checks['profiles'] = array( __( 'Profile links', 'optima-team' ), __( 'LinkedIn, a state bar page or another public profile', 'optima-team' ), ! empty( $p['same_as'] ) );
		return $checks;
	}
}
