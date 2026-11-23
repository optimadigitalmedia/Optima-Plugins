<?php
/**
 * The team content model: the optima_team post type, the one profile meta
 * array per person, the site settings, the trust checklist, where each
 * person shows (Shows on), the sources behind each credential line, what the
 * public may see of a profile, and what a business card may show.
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

	/** Details a person sent through their link, waiting for an OK. Never read by the public side. */
	const PENDING = '_optima_team_pending';

	/**
	 * The source given to credential lines with no source field: lines saved
	 * before sources were tracked, and lines from a setup file that carries no
	 * source yet. They keep showing. A line whose source is there but empty is
	 * saved and stays hidden.
	 */
	const LEGACY_SOURCE = 'Added before sources were tracked';

	/** The data version, and the option that records it. */
	const DB_VERSION = 1;
	const DB_OPTION  = 'optima_team_db';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 20 );
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

	/**
	 * Columns in each repeatable credential list. A row needs its first column.
	 * Every line carries a source: where someone can check it (the Knowledge
	 * Hub's source column, through the setup file). The page and the schema show
	 * only lines with one. Memberships stay plain lines (the setup file fill
	 * hands them over as text), so they carry no source yet.
	 */
	public static function groups(): array {
		return array(
			'bar'            => array( 'state', 'year', 'number', 'url', 'source' ),
			'education'      => array( 'school', 'degree', 'year', 'source' ),
			// Release 2: how the award is worded, the issuer's own page on how it is
			// given, and whether a fee was paid for it (a paid award never prints on a law site).
			'awards'         => array( 'name', 'year', 'issuer', 'wording', 'standards_url', 'fee_paid', 'source' ),
			// Release 2: board certification by a named certifying body.
			'certifications' => array( 'name', 'body', 'year', 'url', 'source' ),
		);
	}

	/* ---------- Shows on ---------- */

	/**
	 * The places a person can show, in the order the editor lists them: their
	 * own profile page, the team page, a business card (Optima Cards) and a
	 * byline on posts.
	 */
	public static function show_keys(): array {
		return array( 'bio', 'team', 'card', 'byline' );
	}

	/**
	 * Where a person shows when nobody has chosen yet. These match how the site
	 * worked before Shows on existed: a profile page, a spot on the team page,
	 * and a byline (the Byline box names people with or without a login). A
	 * card only once it is switched on.
	 */
	public static function default_shows(): array {
		return array(
			'bio'    => true,
			'team'   => true,
			'card'   => false,
			'byline' => true,
		);
	}

	/**
	 * Does this person show in this place?
	 *
	 * @param array  $p   Profile.
	 * @param string $key bio, team, card or byline.
	 */
	public static function shows( array $p, string $key ): bool {
		return ! empty( $p['shows'][ $key ] );
	}

	/**
	 * Switch one place on or off for a person, keeping everything else. Used by
	 * Optima Cards' one-time move, so the card switch lives here only.
	 *
	 * @param int    $post_id Person post ID.
	 * @param string $key     A show_keys() key.
	 * @param bool   $on      On or off.
	 */
	public static function set_show( int $post_id, string $key, bool $on ): void {
		if ( ! in_array( $key, self::show_keys(), true ) || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		$p                 = self::profile( $post_id );
		$p['shows'][ $key ] = $on;
		update_post_meta( $post_id, self::META, $p );
	}

	/* ---------- the one-time move ---------- */

	/** Has the one-time move (Shows on, sources) run? */
	public static function upgraded(): bool {
		return (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION;
	}

	/**
	 * The one-time move: every stored profile is saved in the new shape, with
	 * Shows on set so live pages stay the same and every credential line
	 * already there marked as added before sources were tracked, so it keeps
	 * showing. Safe to run twice; it never removes a line.
	 */
	public static function maybe_upgrade(): void {
		if ( self::upgraded() || get_transient( 'optima_team_upgrading' ) ) {
			return;
		}
		set_transient( 'optima_team_upgrading', 1, 5 * MINUTE_IN_SECONDS );
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$stored = get_post_meta( (int) $id, self::META, true );
			if ( is_array( $stored ) ) {
				$moved = self::upgrade_profile( $stored );
				if ( $moved !== $stored ) {
					update_post_meta( (int) $id, self::META, $moved );
				}
			}
		}
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		delete_transient( 'optima_team_upgrading' );
	}

	/**
	 * Bring a stored profile from before Shows on and sources into the new
	 * shape, without changing what a visitor sees. Pure: used by the one-time
	 * move and on every read, so a profile saved by older code still works.
	 *
	 * - Shows on is added when missing (default_shows()).
	 * - A credential line with no source field at all predates sources, so it
	 *   gets LEGACY_SOURCE and keeps showing. A line that has the field but
	 *   leaves it empty stays hidden.
	 *
	 * @param array $p Stored profile.
	 */
	public static function upgrade_profile( array $p ): array {
		foreach ( array_keys( self::groups() ) as $group ) {
			if ( empty( $p[ $group ] ) || ! is_array( $p[ $group ] ) ) {
				continue;
			}
			foreach ( $p[ $group ] as $i => $row ) {
				if ( is_array( $row ) && ! array_key_exists( 'source', $row ) ) {
					$p[ $group ][ $i ]['source'] = self::LEGACY_SOURCE;
				}
			}
		}
		if ( ! isset( $p['shows'] ) || ! is_array( $p['shows'] ) ) {
			$p['shows'] = self::default_shows();
		}
		return $p;
	}

	/**
	 * Any profile array in the full shape: every key, every show switch, every
	 * credential line with all its columns.
	 *
	 * @param array $stored Stored or imported profile.
	 */
	public static function shape( array $stored ): array {
		$p     = wp_parse_args( self::upgrade_profile( $stored ), self::empty_profile() );
		$shows = self::default_shows();
		foreach ( self::show_keys() as $key ) {
			$shows[ $key ] = isset( $p['shows'][ $key ] ) ? (bool) $p['shows'][ $key ] : $shows[ $key ];
		}
		$p['shows'] = $shows;
		foreach ( self::groups() as $group => $cols ) {
			$rows = array();
			foreach ( (array) $p[ $group ] as $row ) {
				$row = (array) $row;
				$out = array();
				foreach ( $cols as $col ) {
					$out[ $col ] = (string) ( $row[ $col ] ?? '' );
				}
				$rows[] = $out;
			}
			$p[ $group ] = $rows;
		}
		return $p;
	}

	/* ---------- sources and what the public sees ---------- */

	/** Does a credential line have a source? */
	public static function sourced( array $row ): bool {
		return '' !== trim( (string) ( $row['source'] ?? '' ) );
	}

	/** How many credential lines have no source (saved, but hidden). */
	public static function unsourced( array $p ): int {
		$n = 0;
		foreach ( array_keys( self::groups() ) as $group ) {
			foreach ( (array) ( $p[ $group ] ?? array() ) as $row ) {
				if ( ! self::sourced( (array) $row ) ) {
					++$n;
				}
			}
		}
		return $n;
	}

	/** The profile fields that belong to the business card only. Never on the profile page, the team page, the schema or the REST API. */
	public static function card_keys(): array {
		return array( 'card_phone', 'card_email', 'card_intro', 'card_why', 'card_tags' );
	}

	/**
	 * What the public may see of a profile: the profile page, the team page,
	 * the bio blocks and the Person schema all read this, never the stored
	 * profile. The business card fields are taken out, and credential lines
	 * without a source are left out.
	 *
	 * @param array $p Profile in the full shape.
	 */
	public static function public_profile( array $p ): array {
		foreach ( self::card_keys() as $key ) {
			unset( $p[ $key ] );
		}
		foreach ( array_keys( self::groups() ) as $group ) {
			$p[ $group ] = array_values( array_filter( (array) ( $p[ $group ] ?? array() ), array( __CLASS__, 'sourced' ) ) );
		}
		return $p;
	}

	/* ---------- the business card ---------- */

	/**
	 * What a business card may show of a person, for Optima Cards. Null when
	 * the post is not a person. The card only phone, email, intro, "why talk to
	 * them" and tags are here, because they are for the card; credential lines
	 * are not. Role, bio and links come through public_profile(), so the card
	 * shows nothing the profile page would hide. 'on' is true when the person
	 * is live with A business card switched on.
	 *
	 * @param int $post_id Person post ID.
	 */
	public static function card( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$p     = self::profile( $post_id );
		$pub   = self::public_profile( $p );
		$thumb = (int) get_post_thumbnail_id( $post_id );
		return array(
			'id'         => (int) $post_id,
			'status'     => (string) $post->post_status,
			'on'         => 'publish' === $post->post_status && self::shows( $p, 'card' ),
			'name'       => html_entity_decode( wp_strip_all_tags( (string) $post->post_title ), ENT_QUOTES, 'UTF-8' ),
			'slug'       => (string) $post->post_name,
			'role'       => (string) $pub['role'],
			'bio'        => (string) $pub['bio'],
			'email'      => (string) $p['email'],
			'user'       => (int) $p['user'],
			'phone'      => (string) $p['card_phone'],
			'card_email' => (string) $p['card_email'],
			'intro'      => (string) $p['card_intro'],
			'why'        => (string) $p['card_why'],
			'tags'       => (string) $p['card_tags'],
			'links'      => array_values( (array) $pub['same_as'] ),
			'photo_id'   => $thumb,
			'alt'        => $thumb ? trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ) : '',
		);
	}

	/**
	 * People with A business card switched on, in team page order (Order
	 * number, then name).
	 *
	 * @param bool $live_only Only people who are live. False adds drafts and pending people.
	 */
	public static function card_ids( bool $live_only = true ): array {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => $live_only ? 'publish' : array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			if ( self::shows( self::profile( (int) $id ), 'card' ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/* ---------- needs a look ---------- */

	/**
	 * Why a person needs a look, in plain words. Empty when they are fine.
	 *
	 * @param array  $p       Profile in the full shape.
	 * @param bool   $photo   Has a photo (featured image).
	 * @param string $status  Post status.
	 * @param bool   $alt     The photo has alt text set in the media library.
	 * @param bool   $pending They sent their details through a link and those wait for an OK.
	 */
	public static function look_reasons( array $p, bool $photo, string $status, bool $alt = true, bool $pending = false ): array {
		$out = array();
		if ( $pending ) {
			$out['pending'] = __( 'Sent their details, waiting for your OK', 'optima-team' );
		}
		if ( ! $photo ) {
			$out['photo'] = __( 'No photo', 'optima-team' );
		} elseif ( ! $alt ) {
			$out['alt'] = __( 'Photo needs alt text', 'optima-team' );
		}
		$n = self::unsourced( $p );
		if ( $n ) {
			/* translators: %d: number of credential lines without a source. */
			$out['source'] = sprintf( _n( '%d line needs a source', '%d lines need a source', $n, 'optima-team' ), $n );
		}
		if ( in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			$out['draft'] = __( 'Not live yet', 'optima-team' );
		}
		return $out;
	}

	/**
	 * Why this person needs a look: details they sent waiting for an OK, no
	 * photo, a photo with no alt text, a credential line with no source, or
	 * not live yet.
	 *
	 * @param int $post_id Person post ID.
	 */
	public static function needs_look( int $post_id ): array {
		$thumb   = (int) get_post_thumbnail_id( $post_id );
		$alt     = $thumb && '' !== trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) );
		$pending = get_post_meta( $post_id, self::PENDING, true );
		return self::look_reasons( self::profile( $post_id ), $thumb > 0, (string) get_post_status( $post_id ), $alt, is_array( $pending ) && $pending );
	}

	/**
	 * Put people who need a look first, keeping the order inside each part
	 * (rule 52: a screen opens on what needs doing).
	 *
	 * @param int[] $ids  Person post IDs, in team page order.
	 * @param array $look id => reasons.
	 * @return int[]
	 */
	public static function look_first( array $ids, array $look ): array {
		$first = array();
		$rest  = array();
		foreach ( $ids as $id ) {
			if ( ! empty( $look[ $id ] ) ) {
				$first[] = (int) $id;
			} else {
				$rest[] = (int) $id;
			}
		}
		return array_merge( $first, $rest );
	}

	/* ---------- the team page and hidden profiles ---------- */

	/**
	 * Is this person on the team page? Live, with Team page switched on.
	 *
	 * @param int $post_id Person post ID.
	 */
	public static function on_team_page( int $post_id ): bool {
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return false;
		}
		return self::shows( self::profile( $post_id ), 'team' );
	}

	/**
	 * Live people whose profile page is switched off. Their page answers "not
	 * found" to visitors and stays out of the sitemap, the /team/ list and the
	 * site's own search.
	 */
	public static function hidden_profiles(): array {
		static $hidden = null;
		if ( null === $hidden ) {
			$hidden = array();
			$ids    = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				if ( ! self::shows( self::profile( (int) $id ), 'bio' ) ) {
					$hidden[] = (int) $id;
				}
			}
		}
		return $hidden;
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
			// The business card's own contact and words (never on the page, the schema or the REST API).
			'card_phone'      => '',
			'card_email'      => '',
			'card_intro'      => '',
			'card_why'        => '',
			'card_tags'       => '',
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
			'shows'           => self::default_shows(),
		);
	}

	/**
	 * The stored profile, always in the full shape.
	 *
	 * @param int $post_id Team member post ID.
	 */
	public static function profile( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META, true );
		return self::shape( is_array( $stored ) ? $stored : array() );
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
		$out['phone']           = self::clean_phone( (string) ( $in['phone'] ?? '' ) );
		$out['card_email']      = sanitize_email( (string) ( $in['card_email'] ?? '' ) );
		$out['card_phone']      = self::clean_phone( (string) ( $in['card_phone'] ?? '' ) );
		$out['card_intro']      = sanitize_textarea_field( (string) ( $in['card_intro'] ?? '' ) );
		$out['card_why']        = sanitize_textarea_field( (string) ( $in['card_why'] ?? '' ) );
		$out['card_tags']       = sanitize_text_field( (string) ( $in['card_tags'] ?? '' ) );
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

		// Shows on: the editor always sends the list, so an unticked box means off.
		// Without the list (the setup file fill, Add a person), keep what was sent or the defaults.
		if ( isset( $in['shows'] ) && is_array( $in['shows'] ) ) {
			foreach ( self::show_keys() as $key ) {
				$out['shows'][ $key ] = ! empty( $in['shows'][ $key ] );
			}
		}

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
				$row = (array) $row;
				// A line with no source field at all (an older setup file, or a line saved
				// before sources) keeps showing; one with an empty source stays hidden.
				if ( ! array_key_exists( 'source', $row ) ) {
					$row['source'] = self::LEGACY_SOURCE;
				}
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

	/** Digits and phone punctuation only. */
	private static function clean_phone( string $raw ): string {
		return (string) preg_replace( '/[^0-9+().\-\s]/', '', sanitize_text_field( $raw ) );
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

	/** Profiles that forward to a bio page, and profile pages switched off, stay out of the sitemap. */
	public static function sitemap_skip( $args, $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return $args;
		}
		$skip = array_merge( array_keys( self::bio_pages() ), self::hidden_profiles() );
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

	/** The person linked to a WordPress user, for bylines. Only people with Byline on. */
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
				$p   = self::profile( (int) $id );
				$uid = (int) $p['user'];
				if ( $uid && self::shows( $p, 'byline' ) && ! isset( $map[ $uid ] ) ) {
					$map[ $uid ] = (int) $id;
				}
			}
		}
		return $map[ $user_id ] ?? 0;
	}

	/* ---------- bylines ---------- */

	/**
	 * A clean list of profile IDs: whole numbers above zero, each once, in the
	 * order given, ten at most.
	 *
	 * @param mixed $raw Stored or sent value.
	 */
	public static function clean_ids( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id ) {
			$id = is_scalar( $id ) && is_numeric( $id ) ? (int) $id : 0;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return array_slice( $out, 0, 10 );
	}

	/**
	 * Can this person be credited on a byline right now? Live, with Byline on.
	 *
	 * @param int $member Person post ID.
	 */
	public static function byline_ready( int $member ): bool {
		return $member > 0
			&& self::POST_TYPE === get_post_type( $member )
			&& 'publish' === get_post_status( $member )
			&& self::shows( self::profile( $member ), 'byline' );
	}

	/**
	 * Who a post's byline credits, as profile IDs: the people picked in its
	 * Byline box (live, with Byline on), or else the profile linked to the
	 * post's WordPress author. Empty when neither. Other plugins read bylines
	 * from People through this.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function byline_people( $post_id ): array {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array();
		}
		$chosen = class_exists( 'Optima_Team_Byline' ) ? Optima_Team_Byline::people( $post_id ) : array();
		if ( $chosen ) {
			return $chosen;
		}
		$member = self::member_for_user( (int) get_post_field( 'post_author', $post_id ) );
		return $member ? array( $member ) : array();
	}

	/**
	 * A person's name as plain text.
	 *
	 * @param int $member Person post ID.
	 */
	public static function name( int $member ): string {
		return html_entity_decode( wp_strip_all_tags( (string) get_the_title( $member ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Names joined for a byline: "Dana", "Dana & Sam", "Dana, Sam & Lee".
	 *
	 * @param string[] $names Names, in order.
	 */
	public static function join_names( array $names ): string {
		$names = array_values( array_filter( array_map( 'strval', $names ), 'strlen' ) );
		if ( count( $names ) < 2 ) {
			return (string) ( $names[0] ?? '' );
		}
		$last = array_pop( $names );
		return implode( ', ', $names ) . ' & ' . $last;
	}

	/**
	 * A person's one public page (public_url()), or '' when their profile page
	 * is switched off.
	 *
	 * @param int $member Person post ID.
	 */
	public static function profile_url( int $member ): string {
		return self::shows( self::profile( $member ), 'bio' ) ? self::public_url( $member ) : '';
	}

	/**
	 * What a visitor (and Google) looks for to trust a real person. Each check
	 * is key => [ label, note, done ].
	 *
	 * @param int $post_id Team member post ID.
	 */
	public static function checks( int $post_id ): array {
		$p      = self::public_profile( self::profile( $post_id ) );
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
