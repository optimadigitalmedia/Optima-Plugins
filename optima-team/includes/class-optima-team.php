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

	/** Columns in each repeatable group. */
	public static function groups(): array {
		return array(
			'bar'       => array( 'state', 'year', 'number', 'url' ),
			'education' => array( 'school', 'degree', 'year' ),
			'awards'    => array( 'name', 'year', 'issuer' ),
		);
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
			'practice_areas'  => array(),
			'bio_url'         => '',
			'schema_id'       => '',
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
					$value = (string) ( $row[ $col ] ?? '' );
					if ( 'url' === $col ) {
						$clean[ $col ] = esc_url_raw( trim( $value ), array( 'https', 'http' ) );
					} elseif ( 'year' === $col ) {
						$clean[ $col ] = preg_match( '/^\d{4}$/', trim( $value ) ) ? trim( $value ) : '';
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
			$page = '' !== $slug ? get_page_by_path( $slug, OBJECT, 'page' ) : null;
			$url  = ( $page instanceof WP_Post && 'publish' === $page->post_status ) ? (string) get_permalink( $page ) : '';
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
