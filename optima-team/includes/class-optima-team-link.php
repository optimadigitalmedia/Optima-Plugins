<?php
/**
 * Send them a link: a one-time page where a person fills in their own words
 * for their profile. An operator makes the link (on the People screen or the
 * profile) and sends it themselves. Nothing is emailed.
 *
 * - The link is home_url( '/?optima_people=<token>' ). The token is 48 random
 *   hex characters. Only a keyed hash of it is stored, on the person
 *   (_optima_team_link_hash), with when it ends (_optima_team_link). It ends
 *   after 14 days, works once, and allows a few tries an hour.
 * - The page has no login. It takes their name, title, short bio and a photo,
 *   and with A business card on, their card intro, "why talk to them" and
 *   tags. Never email, phone, card phone or card email. Credentials and
 *   profile links are never typed here either: they come from the setup file
 *   (the client's Knowledge Hub), plugin-first rule 47. The page lists them
 *   read-only and says to send a correction to the firm.
 * - What they send waits on the person as pending changes (_optima_team_pending).
 *   The live profile, the public pages and the schema never read it. It goes
 *   live only when someone approves it on the People screen. On a law site
 *   whose team bios need the firm's approval (Optima Law), Approve asks for the
 *   attorney who approved the words and records it in Optima Law's approval
 *   log with the exact version; without that name nothing changes.
 *
 * @package Optima_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Team_Link {

	/** The address part that carries the token: /?optima_people=<token>. */
	const QUERY = 'optima_people';

	/** On the person: the hash of their current link's token. */
	const HASH = '_optima_team_link_hash';

	/** On the person: [ created, expires, by, used ] for their latest link. */
	const META = '_optima_team_link';

	/** How long a link works. */
	const DAYS = 14;

	/** Form sends allowed per link in an hour. */
	const TRIES = 8;

	/** The largest photo the page takes, in bytes (5 MB). */
	const MAX_PHOTO = 5242880;

	/** Photo types the page takes. */
	const TYPES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/** The admin-post actions (operator side). */
	const ACTION_LINK    = 'optima_team_link';
	const ACTION_PENDING = 'optima_team_pending';

	public static function boot(): void {
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 0 );
		add_action( 'admin_post_' . self::ACTION_LINK, array( __CLASS__, 'handle_link' ) );
		add_action( 'admin_post_' . self::ACTION_PENDING, array( __CLASS__, 'handle_pending' ) );
		add_action( 'add_meta_boxes_' . Optima_Team::POST_TYPE, array( __CLASS__, 'boxes' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/* ---------- the token ---------- */

	/** A new random token: 48 hex characters. */
	public static function new_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	/** The stored form of a token. Keyed with the site's salt, so a copy of the database alone cannot rebuild a link. */
	public static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/** Is this the shape of a token? */
	public static function well_formed( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{48}$/', $token );
	}

	/** The page address for a token. */
	public static function url( string $token ): string {
		return add_query_arg( self::QUERY, $token, home_url( '/' ) );
	}

	/**
	 * Whether a token opens a person's link, without touching the database.
	 *
	 * @param array  $link   The person's link record (created, expires, used).
	 * @param string $stored The stored hash, '' when there is none.
	 * @param string $token  The token from the address.
	 * @param int    $now    The time to check against.
	 * @return string ok, wrong, used or expired.
	 */
	public static function state( array $link, string $stored, string $token, int $now ): string {
		if ( ! self::well_formed( $token ) || '' === $stored || ! hash_equals( $stored, self::hash( $token ) ) ) {
			return ! empty( $link['used'] ) ? 'used' : 'wrong';
		}
		if ( ! empty( $link['used'] ) ) {
			return 'used';
		}
		if ( (int) ( $link['expires'] ?? 0 ) <= $now ) {
			return 'expired';
		}
		return 'ok';
	}

	/** The person's link record. */
	public static function record( int $post_id ): array {
		$link = get_post_meta( $post_id, self::META, true );
		return is_array( $link ) ? $link : array();
	}

	/**
	 * Does this token open this person's link right now?
	 *
	 * @return string ok, wrong, used or expired.
	 */
	public static function verify( int $post_id, string $token, int $now = 0 ): string {
		return self::state( self::record( $post_id ), (string) get_post_meta( $post_id, self::HASH, true ), $token, $now ? $now : time() );
	}

	/**
	 * Make a new link for a person. Any earlier link stops working. Returns the
	 * address; only its hash is kept.
	 *
	 * @param int $post_id Person post ID.
	 */
	public static function create( int $post_id ): string {
		$token = self::new_token();
		$now   = time();
		update_post_meta( $post_id, self::HASH, self::hash( $token ) );
		update_post_meta(
			$post_id,
			self::META,
			array(
				'created' => $now,
				'expires' => $now + self::DAYS * DAY_IN_SECONDS,
				'by'      => (int) get_current_user_id(),
				'used'    => 0,
			)
		);
		$url = self::url( $token );
		// Shown once to the person who made it, on the next screen, then gone.
		set_transient( self::show_key( $post_id ), $url, 10 * MINUTE_IN_SECONDS );
		return $url;
	}

	/** Use the link up: it works once. */
	public static function consume( int $post_id ): void {
		$link         = self::record( $post_id );
		$link['used'] = time();
		update_post_meta( $post_id, self::META, $link );
		delete_post_meta( $post_id, self::HASH );
	}

	/** Turn a link off. */
	public static function revoke( int $post_id ): void {
		delete_post_meta( $post_id, self::HASH );
		delete_post_meta( $post_id, self::META );
	}

	/** Is a link out and still working? */
	public static function active( int $post_id ): bool {
		$link = self::record( $post_id );
		return $link && empty( $link['used'] ) && (int) ( $link['expires'] ?? 0 ) > time() && '' !== (string) get_post_meta( $post_id, self::HASH, true );
	}

	/** The transient that holds a new link for the one screen that shows it. */
	private static function show_key( int $post_id ): string {
		return 'optima_team_link_' . (int) get_current_user_id() . '_' . $post_id;
	}

	/** The new link made a moment ago, once, for the screen that shows it. '' when there is none. */
	public static function take_new_link( int $post_id ): string {
		$key = self::show_key( $post_id );
		$url = (string) get_transient( $key );
		if ( '' !== $url ) {
			delete_transient( $key );
		}
		return $url;
	}

	/** The person a token belongs to, or 0. */
	public static function find( string $token ): int {
		if ( ! self::well_formed( $token ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => Optima_Team::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::HASH, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => self::hash( $token ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Count one form send on a link. False once it has had too many this hour.
	 *
	 * @param string $token The token.
	 */
	public static function allow_try( string $token ): bool {
		$key   = 'optima_team_tries_' . substr( self::hash( $token ), 0, 32 );
		$tries = (int) get_transient( $key );
		if ( $tries >= self::TRIES ) {
			return false;
		}
		set_transient( $key, $tries + 1, HOUR_IN_SECONDS );
		return true;
	}

	/* ---------- what the page takes ---------- */

	/**
	 * The fields the page takes. Everything else on a profile (email, phone,
	 * card phone, card email, the login, where they show, every credential and
	 * profile link) stays with the operator and the setup file.
	 */
	public static function fields(): array {
		return array( 'name', 'role', 'bio', 'photo', 'intro', 'why', 'tags' );
	}

	/** The card's words on the page: form name => profile key. */
	public static function card_words(): array {
		return array(
			'intro' => 'card_intro',
			'why'   => 'card_why',
			'tags'  => 'card_tags',
		);
	}

	/** Does the page offer the card's words for this person? Only with A business card on. */
	public static function takes_card( array $p ): bool {
		return Optima_Team::shows( $p, 'card' );
	}

	/**
	 * Clean what the page sent. Only the page's own fields survive; anything
	 * else that was sent (credentials, links, contact) is dropped.
	 *
	 * @param array $in   Raw (unslashed) input.
	 * @param bool  $card The card's words are on the page.
	 */
	public static function clean( array $in, bool $card = false ): array {
		$text = static fn( $v ): string => is_scalar( $v ) ? (string) $v : '';
		$out  = array(
			'name' => self::cut( sanitize_text_field( $text( $in['name'] ?? '' ) ), 120 ),
			'role' => self::cut( sanitize_text_field( $text( $in['role'] ?? '' ) ), 120 ),
			'bio'  => self::cut( sanitize_textarea_field( $text( $in['bio'] ?? '' ) ), 1200 ),
		);
		if ( $card ) {
			$out['card_intro'] = self::cut( sanitize_textarea_field( $text( $in['intro'] ?? '' ) ), 400 );
			$out['card_why']   = self::cut( sanitize_textarea_field( $text( $in['why'] ?? '' ) ), 160 );
			$out['card_tags']  = self::cut( sanitize_text_field( $text( $in['tags'] ?? '' ) ), 160 );
		}
		return $out;
	}

	/** Shorten text to a length, on a character boundary. */
	private static function cut( string $text, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text ) > $max ? trim( mb_substr( $text, 0, $max ) ) : $text;
		}
		return strlen( $text ) > $max ? trim( substr( $text, 0, $max ) ) : $text;
	}

	/**
	 * The profile with what they sent laid over it. An empty title, bio or card
	 * line keeps what is there. Nothing else changes.
	 *
	 * @param array $live    The stored profile, full shape.
	 * @param array $pending What they sent, from clean().
	 */
	public static function merge( array $live, array $pending ): array {
		$out = $live;
		foreach ( array( 'role', 'bio', 'card_intro', 'card_why', 'card_tags' ) as $key ) {
			if ( '' !== trim( (string) ( $pending[ $key ] ?? '' ) ) ) {
				$out[ $key ] = (string) $pending[ $key ];
			}
		}
		return $out;
	}

	/** What they sent and are waiting on, or an empty array. */
	public static function pending( int $post_id ): array {
		$pending = get_post_meta( $post_id, Optima_Team::PENDING, true );
		return is_array( $pending ) ? $pending : array();
	}

	/**
	 * Keep what they sent as pending changes. The live profile is not touched.
	 *
	 * @param int   $post_id Person post ID.
	 * @param array $clean   From clean().
	 * @param int   $photo   An uploaded photo's attachment ID, or 0.
	 */
	public static function save_pending( int $post_id, array $clean, int $photo = 0 ): void {
		$old = self::pending( $post_id );
		if ( ! empty( $old['photo'] ) && (int) $old['photo'] !== $photo ) {
			self::drop_photo( $post_id, (int) $old['photo'] );
		}
		$clean['photo'] = $photo;
		$clean['sent']  = time();
		update_post_meta( $post_id, Optima_Team::PENDING, $clean );
	}

	/* ---------- the firm's approval (Optima Law) ---------- */

	/** Do team bios need the firm's approval on this site (Optima Law, with People among its kinds)? */
	public static function law_gated(): bool {
		return class_exists( 'Optima_Law_Approvals' )
			&& method_exists( 'Optima_Law_Approvals', 'gated' )
			&& method_exists( 'Optima_Law_Approvals', 'record' )
			&& Optima_Law_Approvals::gated( Optima_Team::POST_TYPE );
	}

	/**
	 * What Approve needs before it may change anything: the attorney's name and
	 * the tick, when the firm's approval is on. '' when it may go ahead.
	 *
	 * @param bool   $gated The firm's approval is on.
	 * @param string $by    The attorney who approved it.
	 * @param bool   $ok    The "the firm approved these words" tick.
	 */
	public static function approval_problem( bool $gated, string $by, bool $ok ): string {
		if ( ! $gated ) {
			return '';
		}
		return ( '' !== trim( $by ) && $ok ) ? '' : 'need_by';
	}

	/**
	 * Copy what they sent into the profile: name, title, bio, the card's words
	 * and photo. Then the pending changes are gone. With the firm's approval on,
	 * nothing changes without the approving attorney's name, and the approval is
	 * recorded in Optima Law's log against the version now live.
	 *
	 * @param int    $post_id Person post ID.
	 * @param string $by      The attorney who approved the words ('' when the firm's approval is off).
	 * @param bool   $ok      The "the firm approved these words" tick.
	 * @return string approved, need_by or none.
	 */
	public static function approve( int $post_id, string $by = '', bool $ok = false ): string {
		$pending = self::pending( $post_id );
		if ( ! $pending ) {
			return 'none';
		}
		$gated   = self::law_gated();
		$problem = self::approval_problem( $gated, $by, $ok );
		if ( '' !== $problem ) {
			return $problem;
		}
		update_post_meta( $post_id, Optima_Team::META, Optima_Team::shape( self::merge( Optima_Team::profile( $post_id ), $pending ) ) );
		$name = trim( (string) ( $pending['name'] ?? '' ) );
		if ( '' !== $name && get_the_title( $post_id ) !== $name ) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $name,
				)
			);
		}
		$photo = (int) ( $pending['photo'] ?? 0 );
		if ( $photo && wp_attachment_is_image( $photo ) ) {
			set_post_thumbnail( $post_id, $photo );
			if ( '' === trim( (string) get_post_meta( $photo, '_wp_attachment_image_alt', true ) ) ) {
				update_post_meta( $photo, '_wp_attachment_image_alt', '' !== $name ? $name : wp_strip_all_tags( (string) get_the_title( $post_id ) ) );
			}
		}
		delete_post_meta( $post_id, Optima_Team::PENDING );
		if ( $gated ) {
			// The firm's advertising record: who approved it, when, and the exact version.
			Optima_Law_Approvals::record( $post_id, sanitize_text_field( $by ) );
		}
		return 'approved';
	}

	/** Throw away what they sent, and the photo they uploaded with it. */
	public static function discard( int $post_id ): bool {
		$pending = self::pending( $post_id );
		if ( ! $pending ) {
			return false;
		}
		if ( ! empty( $pending['photo'] ) ) {
			self::drop_photo( $post_id, (int) $pending['photo'] );
		}
		delete_post_meta( $post_id, Optima_Team::PENDING );
		return true;
	}

	/** Delete a photo uploaded through the page, unless it has become the person's photo. */
	private static function drop_photo( int $post_id, int $photo ): void {
		if ( $photo && (int) get_post_thumbnail_id( $post_id ) !== $photo && (int) wp_get_post_parent_id( $photo ) === $post_id ) {
			wp_delete_attachment( $photo, true );
		}
	}

	/**
	 * What is wrong with an uploaded photo, in plain words, or '' when it is fine.
	 *
	 * @param array $file One entry of $_FILES.
	 */
	public static function photo_problem( array $file ): string {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error || (int) ( $file['size'] ?? 0 ) > self::MAX_PHOTO ) {
			return __( 'The photo is over 5 MB. Try a smaller one.', 'optima-team' );
		}
		if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return __( 'The photo did not upload. Try again.', 'optima-team' );
		}
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) ( $file['name'] ?? '' ) );
		$size  = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( (string) $file['tmp_name'] ) : false;
		if ( ! in_array( (string) ( $check['type'] ?? '' ), self::TYPES, true ) || ! $size ) {
			return __( 'Use a JPG, PNG or WebP photo.', 'optima-team' );
		}
		return '';
	}

	/**
	 * Put an uploaded photo in the media library, attached to the person.
	 *
	 * @param string $field   The $_FILES key.
	 * @param int    $post_id Person post ID.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function upload( string $field, int $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		return media_handle_upload( $field, $post_id, array(), array( 'test_form' => false ) );
	}

	/* ---------- the page they fill in ---------- */

	/** The values the page starts with: what they sent before, else what is there now. */
	public static function prefill( int $post_id ): array {
		$live    = Optima_Team::profile( $post_id );
		$pending = self::pending( $post_id );
		$p       = $pending ? self::merge( $live, $pending ) : $live;
		$name    = $pending && '' !== trim( (string) ( $pending['name'] ?? '' ) ) ? (string) $pending['name'] : wp_strip_all_tags( (string) get_the_title( $post_id ) );
		$out     = array(
			'name' => $name,
			'role' => (string) $p['role'],
			'bio'  => (string) $p['bio'],
		);
		foreach ( self::card_words() as $field => $key ) {
			$out[ $field ] = (string) $p[ $key ];
		}
		return $out;
	}

	/**
	 * Handle the address: the link page, the thank-you page, or an ended link.
	 * Runs before anything else on the front of the site and only when the
	 * address carries ?optima_people=.
	 */
	public static function route(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token is the key; the form checks its nonce.
		if ( is_admin() || ! isset( $_GET[ self::QUERY ] ) || ! is_string( $_GET[ self::QUERY ] ) ) {
			return;
		}
		$raw = strtolower( sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ) );
		// phpcs:enable
		if ( 'sent' === $raw ) {
			self::page(
				__( 'Thank you', 'optima-team' ),
				'<h1>' . esc_html__( 'Thank you', 'optima-team' ) . '</h1><p>' . esc_html__( 'Your details are in. They show on the site once they are approved.', 'optima-team' ) . '</p>',
				200
			);
		}
		$token = self::well_formed( $raw ) ? $raw : '';
		$id    = '' !== $token ? self::find( $token ) : 0;
		if ( ! $id || 'ok' !== self::verify( $id, $token ) ) {
			self::page(
				__( 'This link has ended', 'optima-team' ),
				'<h1>' . esc_html__( 'This link has ended', 'optima-team' ) . '</h1><p>' . esc_html__( 'It may have expired or already been used. Ask the person who sent it for a new one.', 'optima-team' ) . '</p>',
				404
			);
		}
		$values = self::prefill( $id );
		$errors = array();
		if ( 'POST' === strtoupper( sanitize_text_field( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) ) {
			list( $values, $errors ) = self::submit( $id, $token, $values );
		}
		self::page( __( 'Your profile', 'optima-team' ), self::form_html( $id, $values, $errors, $token, self::record( $id ) ), 200 );
	}

	/**
	 * Take a form send. On success: save as pending, use up the link and go to
	 * the thank-you page. Otherwise give back what they typed and what to fix.
	 *
	 * @param int    $post_id Person post ID.
	 * @param string $token   The token.
	 * @param array  $values  The values the page started with.
	 * @return array [ values, errors ]
	 */
	private static function submit( int $post_id, string $token, array $values ): array {
		$nonce = isset( $_POST['_otd_details'] ) ? sanitize_text_field( wp_unslash( $_POST['_otd_details'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'optima_team_details_' . $post_id ) ) {
			return array( $values, array( __( 'The page sat open too long. Send it again.', 'optima-team' ) ) );
		}
		// A filled hidden field means a bot. Say thanks and keep nothing.
		if ( ! empty( $_POST['otd_website'] ) ) {
			wp_safe_redirect( self::url( 'sent' ) );
			exit;
		}
		if ( ! self::allow_try( $token ) ) {
			return array( $values, array( __( 'Too many tries on this link. Wait an hour and try again.', 'optima-team' ) ) );
		}
		$card = self::takes_card( Optima_Team::profile( $post_id ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is cleaned in clean().
		$in     = isset( $_POST['otd'] ) && is_array( $_POST['otd'] ) ? (array) wp_unslash( $_POST['otd'] ) : array();
		$clean  = self::clean( $in, $card );
		$values = array_merge( $values, $clean );
		foreach ( self::card_words() as $field => $key ) {
			if ( isset( $clean[ $key ] ) ) {
				$values[ $field ] = $clean[ $key ];
			}
		}
		$errors = array();
		if ( '' === $clean['name'] ) {
			$errors[] = __( 'Add your name.', 'optima-team' );
		}
		$photo = 0;
		if ( ! $errors && ! empty( $_FILES['otd_photo']['name'] ) ) {
			$problem = self::photo_problem( (array) $_FILES['otd_photo'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked here, then handed to WordPress's own upload checks.
			if ( '' === $problem ) {
				$made = self::upload( 'otd_photo', $post_id );
				if ( is_wp_error( $made ) ) {
					$problem = __( 'The photo did not upload. Try again.', 'optima-team' );
				} else {
					$photo = (int) $made;
				}
			}
			if ( '' !== $problem ) {
				$errors[] = $problem;
			}
		}
		if ( $errors ) {
			return array( $values, $errors );
		}
		self::save_pending( $post_id, $clean, $photo );
		self::consume( $post_id );
		wp_safe_redirect( self::url( 'sent' ) );
		exit;
	}

	/**
	 * Their credentials and profile links as the site has them, read-only, so
	 * they can spot a mistake and tell the firm.
	 *
	 * @param int $post_id Person post ID.
	 */
	private static function facts_html( int $post_id ): string {
		$p     = Optima_Team::public_profile( Optima_Team::profile( $post_id ) );
		$lines = array();
		foreach ( $p['education'] as $r ) {
			$lines[] = implode( ', ', array_filter( array( $r['degree'], $r['school'], $r['year'] ) ) );
		}
		if ( Optima_Team::is_law() && Optima_Team::is_attorney( $p ) ) {
			foreach ( $p['bar'] as $r ) {
				$lines[] = implode( ', ', array_filter( array( $r['state'], $r['year'] ) ) );
			}
		}
		foreach ( $p['certifications'] as $r ) {
			$lines[] = Optima_Team::certification_line( $r );
		}
		foreach ( Optima_Team::printable_awards( $p ) as $r ) {
			$lines[] = Optima_Team::award_line( $r );
		}
		foreach ( (array) $p['memberships'] as $m ) {
			$lines[] = (string) $m;
		}
		foreach ( (array) $p['same_as'] as $url ) {
			$lines[] = (string) $url;
		}
		$lines = array_values( array_filter( $lines, 'strlen' ) );
		$h     = '<fieldset><legend>' . esc_html__( 'Your credentials', 'optima-team' ) . '</legend>';
		$h    .= '<p class="optima-team-details__note">' . esc_html__( 'These come from the firm\'s records, each checked against a source, so they are not changed here. If something is missing or wrong, tell the person who sent you this link.', 'optima-team' ) . '</p>';
		if ( $lines ) {
			$h .= '<ul>';
			foreach ( $lines as $line ) {
				$h .= '<li>' . esc_html( $line ) . '</li>';
			}
			$h .= '</ul>';
		} else {
			$h .= '<p class="optima-team-details__help">' . esc_html__( 'None on the site yet.', 'optima-team' ) . '</p>';
		}
		return $h . '</fieldset>';
	}

	/**
	 * The form, as plain markup. Name, title, bio, photo, and the card's words
	 * when A business card is on. Never email, phone, card contact,
	 * credentials or links.
	 *
	 * @param int    $post_id Person post ID.
	 * @param array  $values  Values to fill in.
	 * @param array  $errors  What to fix, in plain words.
	 * @param string $token   The token.
	 * @param array  $link    The link record, for the end date.
	 */
	public static function form_html( int $post_id, array $values, array $errors, string $token, array $link = array() ): string {
		$site = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$h    = '<h1>' . esc_html__( 'Your profile', 'optima-team' ) . '</h1>';
		/* translators: %s: the site's name. */
		$h .= '<p class="optima-team-details__lede">' . esc_html( sprintf( __( 'Fill in what you want on your profile on %s. It shows on the site once it is approved.', 'optima-team' ), $site ) ) . '</p>';
		if ( $errors ) {
			$h .= '<div class="optima-team-details__errors" role="alert"><p><strong>' . esc_html__( 'Fix these, then send it again:', 'optima-team' ) . '</strong></p><ul>';
			foreach ( $errors as $error ) {
				$h .= '<li>' . esc_html( $error ) . '</li>';
			}
			$h .= '</ul></div>';
		}
		$h .= '<form class="optima-team-details__form" method="post" action="' . esc_url( self::url( $token ) ) . '" enctype="multipart/form-data">';
		$h .= wp_nonce_field( 'optima_team_details_' . $post_id, '_otd_details', false, false );
		$h .= '<p class="optima-team-details__hp" aria-hidden="true"><label for="otd-website">' . esc_html__( 'Leave this empty', 'optima-team' ) . '</label><input type="text" id="otd-website" name="otd_website" value="" tabindex="-1" autocomplete="off"></p>';

		$h .= '<fieldset><legend>' . esc_html__( 'About you', 'optima-team' ) . '</legend>';
		$h .= self::input( 'name', __( 'Your name', 'optima-team' ), (string) ( $values['name'] ?? '' ), 120, true );
		$h .= self::input( 'role', __( 'Your title or role', 'optima-team' ), (string) ( $values['role'] ?? '' ), 120 );
		$h .= '<p class="optima-team-details__field"><label for="otd-bio">' . esc_html__( 'Short bio', 'optima-team' ) . '</label>'
			. '<textarea id="otd-bio" name="otd[bio]" rows="4" maxlength="1200" aria-describedby="otd-bio-help">' . esc_textarea( (string) ( $values['bio'] ?? '' ) ) . '</textarea>'
			. '<span class="optima-team-details__help" id="otd-bio-help">' . esc_html__( 'Two or three plain sentences about you and your work.', 'optima-team' ) . '</span></p>';
		$h .= '</fieldset>';

		$h .= '<fieldset><legend>' . esc_html__( 'Photo', 'optima-team' ) . '</legend>';
		$h .= '<p class="optima-team-details__field"><label for="otd-photo">' . esc_html__( 'A photo of you (optional)', 'optima-team' ) . '</label>'
			. '<input type="file" id="otd-photo" name="otd_photo" accept="image/jpeg,image/png,image/webp" aria-describedby="otd-photo-help">'
			. '<span class="optima-team-details__help" id="otd-photo-help">' . esc_html__( 'JPG, PNG or WebP, up to 5 MB. A clear, recent photo of your face works best.', 'optima-team' ) . '</span></p>';
		$h .= '</fieldset>';

		if ( self::takes_card( Optima_Team::profile( $post_id ) ) ) {
			$h .= '<fieldset><legend>' . esc_html__( 'Your business card', 'optima-team' ) . '</legend>';
			$h .= '<p class="optima-team-details__field"><label for="otd-intro">' . esc_html__( 'Intro (optional)', 'optima-team' ) . '</label>'
				. '<textarea id="otd-intro" name="otd[intro]" rows="3" maxlength="400" aria-describedby="otd-intro-help">' . esc_textarea( (string) ( $values['intro'] ?? '' ) ) . '</textarea>'
				. '<span class="optima-team-details__help" id="otd-intro-help">' . esc_html__( 'One or two sentences in your own voice, on your card.', 'optima-team' ) . '</span></p>';
			$h .= self::input( 'why', __( 'Why talk to you (optional)', 'optima-team' ), (string) ( $values['why'] ?? '' ), 160, false, __( 'One line under your name on the main card.', 'optima-team' ) );
			$h .= self::input( 'tags', __( 'Tags (optional)', 'optima-team' ), (string) ( $values['tags'] ?? '' ), 160, false, __( 'Two to four short topics, with commas between.', 'optima-team' ) );
			$h .= '</fieldset>';
		}

		$h .= self::facts_html( $post_id );

		$h .= '<p><button type="submit" class="optima-team-details__send wp-element-button">' . esc_html__( 'Send my details', 'optima-team' ) . '</button></p>';
		if ( ! empty( $link['expires'] ) ) {
			/* translators: %s: the date the link ends. */
			$h .= '<p class="optima-team-details__help">' . esc_html( sprintf( __( 'This link works once and ends on %s.', 'optima-team' ), wp_date( (string) get_option( 'date_format' ), (int) $link['expires'] ) ) ) . '</p>';
		}
		$h .= '</form>';
		return $h;
	}

	/** One labelled text input named otd[$key]. */
	private static function input( string $key, string $label, string $value, int $max, bool $required = false, string $help = '' ): string {
		$id   = 'otd-' . $key;
		$desc = '' !== $help ? ' aria-describedby="' . esc_attr( $id . '-help' ) . '"' : '';
		return '<p class="optima-team-details__field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
			. '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( 'otd[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . ' maxlength="' . (int) $max . '"' . $desc . '>'
			. ( '' !== $help ? '<span class="optima-team-details__help" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $help ) . '</span>' : '' ) . '</p>';
	}

	/**
	 * Print a page in the theme's frame and stop. Never listed by search
	 * engines, never cached, no referrer, and the site's analytics tag stays off
	 * so the link never leaves the site.
	 *
	 * @param string $title  The page title.
	 * @param string $body   Escaped HTML.
	 * @param int    $status HTTP status.
	 */
	private static function page( string $title, string $body, int $status ): void {
		status_header( $status );
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Referrer-Policy: no-referrer', true );
		}
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'optima_analytics_is_live', '__return_false', 99 );
		add_filter(
			'pre_get_document_title',
			static function () use ( $title ) {
				return $title;
			}
		);
		add_action(
			'wp_enqueue_scripts',
			static function () {
				wp_enqueue_style( 'optima-team-details', OPTIMA_TEAM_URL . 'assets/optima-team-details.css', array(), OPTIMA_TEAM_VERSION );
			}
		);
		$main = '<main id="optima-team-details" class="optima-team-details">' . $body . '</main>';
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'optima-team-details-page' ); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
<?php
			if ( function_exists( 'block_template_part' ) ) {
				block_template_part( 'header' );
			}
			echo $main; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			if ( function_exists( 'block_template_part' ) ) {
				block_template_part( 'footer' );
			}
			?>
</div>
<?php wp_footer(); ?>
</body>
</html>
			<?php
		} else {
			get_header();
			echo $main; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			get_footer();
		}
		exit;
	}

	/* ---------- the operator's side ---------- */

	/** Who may make links and approve what people send: operators (site settings), as on the People screen. */
	public static function can_manage( int $post_id ): bool {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		return ! class_exists( 'Optima_Hub_Access' ) || ! method_exists( 'Optima_Hub_Access', 'is_operator' ) || Optima_Hub_Access::is_operator();
	}

	/** A signed address that makes a new link (do=new) or turns it off (do=off). */
	public static function action_url( int $post_id, string $what = 'new', string $back = 'edit' ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_LINK . '&do=' . rawurlencode( $what ) . '&post=' . $post_id . '&back=' . rawurlencode( $back ) ), self::ACTION_LINK . '_' . $post_id );
	}

	/** Where Approve and Discard live: the People screen, where the forms can ask for the firm's approval. */
	public static function review_url( int $post_id ): string {
		$base = class_exists( 'Optima_Team_Admin' ) ? Optima_Team_Admin::url() : admin_url( 'admin.php?page=optima-team' );
		return $base . '#otd-pending-' . $post_id;
	}

	/** Back to the edit screen, or the People screen, with a note to show. */
	private static function back( int $post_id, string $note, string $where = 'edit' ): void {
		$to = 'people' === $where && class_exists( 'Optima_Team_Admin' ) ? Optima_Team_Admin::url() : (string) get_edit_post_link( $post_id, 'raw' );
		wp_safe_redirect( add_query_arg( array( self::QUERY => $note, 'person' => $post_id ), $to ) );
		exit;
	}

	/** The person this request is about, after the nonce and the operator check. */
	private static function checked_post( string $action, string $id_key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification -- checked on the next line.
		$id = isset( $_REQUEST[ $id_key ] ) ? absint( $_REQUEST[ $id_key ] ) : 0;
		check_admin_referer( $action . '_' . $id );
		if ( ! $id || Optima_Team::POST_TYPE !== get_post_type( $id ) || ! self::can_manage( $id ) ) {
			wp_die( esc_html__( 'You cannot change this person.', 'optima-team' ), 403 );
		}
		return $id;
	}

	public static function handle_link(): void {
		$id   = self::checked_post( self::ACTION_LINK, 'post' );
		$what = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : 'new'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked in checked_post().
		$back = isset( $_GET['back'] ) && 'people' === sanitize_key( wp_unslash( $_GET['back'] ) ) ? 'people' : 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked in checked_post().
		if ( 'off' === $what ) {
			self::revoke( $id );
			self::back( $id, 'off', $back );
		}
		self::create( $id );
		self::back( $id, 'link', $back );
	}

	/** Approve or Discard, from the People screen's form. */
	public static function handle_pending(): void {
		$id   = self::checked_post( self::ACTION_PENDING, 'person' );
		$what = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in checked_post().
		if ( 'approve' === $what ) {
			$by = isset( $_POST['by'] ) ? sanitize_text_field( wp_unslash( $_POST['by'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in checked_post().
			$ok = ! empty( $_POST['firm_ok'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in checked_post().
			self::back( $id, self::approve( $id, $by, $ok ), 'people' );
		}
		self::back( $id, self::discard( $id ) ? 'discarded' : 'none', 'people' );
	}

	/** The edit screen: what they sent at the top, the link box on the side. */
	public static function boxes( $post ): void {
		if ( $post instanceof WP_Post && self::pending( (int) $post->ID ) ) {
			add_meta_box( 'optima-team-pending', __( 'Sent their details', 'optima-team' ), array( __CLASS__, 'render_pending' ), Optima_Team::POST_TYPE, 'optima_team_top', 'high' );
		}
		if ( $post instanceof WP_Post && self::can_manage( (int) $post->ID ) ) {
			add_meta_box( 'optima-team-link', __( 'Send them a link', 'optima-team' ), array( __CLASS__, 'render_link' ), Optima_Team::POST_TYPE, 'side', 'default' );
		}
	}

	/**
	 * The fields that would change: label => [ now, they sent ]. Text only.
	 *
	 * @param int $post_id Person post ID.
	 */
	public static function changes( int $post_id ): array {
		$pending = self::pending( $post_id );
		$live    = Optima_Team::profile( $post_id );
		$name    = wp_strip_all_tags( (string) get_the_title( $post_id ) );
		return self::diff( $live, $pending, $name );
	}

	/**
	 * What would change, pure: label => [ now, they sent ].
	 *
	 * @param array  $live    The stored profile, full shape.
	 * @param array  $pending What they sent.
	 * @param string $name    The name now.
	 */
	public static function diff( array $live, array $pending, string $name ): array {
		$next = self::merge( $live, $pending );
		$out  = array();
		if ( '' !== trim( (string) ( $pending['name'] ?? '' ) ) && $name !== $pending['name'] ) {
			$out[ __( 'Name', 'optima-team' ) ] = array( $name, (string) $pending['name'] );
		}
		$labels = array(
			'role'       => __( 'Title', 'optima-team' ),
			'bio'        => __( 'Short bio', 'optima-team' ),
			'card_intro' => __( 'Card intro', 'optima-team' ),
			'card_why'   => __( 'Why talk to them', 'optima-team' ),
			'card_tags'  => __( 'Card tags', 'optima-team' ),
		);
		foreach ( $labels as $key => $label ) {
			if ( (string) ( $live[ $key ] ?? '' ) !== (string) ( $next[ $key ] ?? '' ) ) {
				$out[ $label ] = array( (string) ( $live[ $key ] ?? '' ), (string) $next[ $key ] );
			}
		}
		return $out;
	}

	/**
	 * The table of what they sent against what is there now. Shared by the
	 * edit screen and the People screen.
	 *
	 * @param int $id Person post ID.
	 */
	public static function diff_table( int $id ): string {
		$pending = self::pending( $id );
		$photo   = (int) ( $pending['photo'] ?? 0 );
		$changes = self::changes( $id );
		if ( ! $changes && ! $photo ) {
			return '<p>' . esc_html__( 'They sent it back with no changes.', 'optima-team' ) . '</p>';
		}
		$h = '<div class="sh-scroll"><table class="widefat sh-table st-diff"><thead><tr><th scope="col">' . esc_html__( 'Field', 'optima-team' ) . '</th><th scope="col">' . esc_html__( 'Now', 'optima-team' ) . '</th><th scope="col">' . esc_html__( 'They sent', 'optima-team' ) . '</th></tr></thead><tbody>';
		if ( $photo ) {
			$now = get_the_post_thumbnail( $id, array( 96, 96 ), array( 'alt' => '' ) );
			$new = wp_get_attachment_image( $photo, array( 96, 96 ), false, array( 'alt' => '' ) );
			$h  .= '<tr><th scope="row">' . esc_html__( 'Photo', 'optima-team' ) . '</th><td>' . ( $now ? $now : esc_html__( 'None', 'optima-team' ) ) . '</td><td>' . ( $new ? $new : esc_html__( 'Not found', 'optima-team' ) ) . '</td></tr>';
		}
		foreach ( $changes as $label => $pair ) {
			$h .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( '' !== $pair[0] ? $pair[0] : __( 'Empty', 'optima-team' ) ) ) . '</td><td>' . nl2br( esc_html( '' !== $pair[1] ? $pair[1] : __( 'Empty', 'optima-team' ) ) ) . '</td></tr>';
		}
		return $h . '</tbody></table></div>';
	}

	/** The edit screen box: what they sent, and where to approve it. */
	public static function render_pending( $post ): void {
		$id      = (int) $post->ID;
		$pending = self::pending( $id );
		$sent    = ! empty( $pending['sent'] ) ? wp_date( (string) get_option( 'date_format' ), (int) $pending['sent'] ) : '';
		echo '<div class="sh optima-team-form st-pending">';
		echo '<p class="sh-status is-warn"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html__( 'Waiting for your OK.', 'optima-team' ) . '</strong> <span>';
		/* translators: %s: date they sent it. */
		echo esc_html( '' !== $sent ? sprintf( __( 'Sent on %s. Nothing changes on the site until it is approved.', 'optima-team' ), $sent ) : __( 'Nothing changes on the site until it is approved.', 'optima-team' ) );
		echo '</span></p>';
		echo self::diff_table( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in diff_table().
		if ( self::can_manage( $id ) ) {
			echo '<p class="sh-actions"><a class="button" href="' . esc_url( self::review_url( $id ) ) . '">' . esc_html__( 'Approve or discard on People', 'optima-team' ) . ' &rsaquo;</a></p>';
			echo '<p class="description">' . esc_html( self::law_gated() ? __( 'Team bios need the firm\'s approval on this site, so Approve asks for the attorney who approved the words.', 'optima-team' ) : __( 'Approve copies these into the profile. Save your other edits first.', 'optima-team' ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The Approve and Discard form for one person, on the People screen. With
	 * the firm's approval on, Approve needs the attorney's name and the tick.
	 *
	 * @param int $id Person post ID.
	 */
	public static function review_form( int $id ): string {
		$gated = self::law_gated();
		$h     = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sh-form">';
		$h    .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_PENDING ) . '">';
		$h    .= '<input type="hidden" name="person" value="' . esc_attr( (string) $id ) . '">';
		$h    .= wp_nonce_field( self::ACTION_PENDING . '_' . $id, '_wpnonce', true, false );
		if ( $gated ) {
			$h .= '<p class="sh-note">' . esc_html__( 'Team bios need the firm\'s approval on this site. Record the attorney who approved these words; they go live with that approval on the firm\'s record.', 'optima-team' ) . '</p>';
			$h .= '<p><label for="otd-by-' . (int) $id . '">' . esc_html__( 'Attorney who approved it', 'optima-team' ) . '</label><br><input type="text" class="regular-text" id="otd-by-' . (int) $id . '" name="by"></p>';
			$h .= '<p><label><input type="checkbox" name="firm_ok" value="1"> ' . esc_html__( 'The firm approved these words', 'optima-team' ) . '</label></p>';
		}
		$h .= '<p class="sh-actions"><button type="submit" name="do" value="approve" class="button button-primary">' . esc_html__( 'Approve', 'optima-team' ) . '</button> ';
		$h .= '<button type="submit" name="do" value="discard" class="button sh-quiet sh-danger" formnovalidate>' . esc_html__( 'Discard', 'optima-team' ) . '</button></p>';
		return $h . '</form>';
	}

	public static function render_link( $post ): void {
		$id = (int) $post->ID;
		echo '<div class="sh optima-team-form st-link">';
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Save the person first. Then you can send them a link to fill in their own words.', 'optima-team' ) . '</p></div>';
			return;
		}
		echo self::link_html( $id, 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in link_html().
		echo '</div>';
	}

	/**
	 * Where a person's link stands, with its buttons. A new link shows once,
	 * with a Copy button.
	 *
	 * @param int    $id   Person post ID.
	 * @param string $back edit or people: where the buttons come back to.
	 */
	public static function link_html( int $id, string $back = 'edit' ): string {
		$url  = self::take_new_link( $id );
		$link = self::record( $id );
		$date = static fn( int $t ): string => wp_date( (string) get_option( 'date_format' ), $t );
		$h    = '';
		if ( '' !== $url ) {
			$fid = 'otd-link-url-' . $id;
			$h  .= '<p class="st-field"><label for="' . esc_attr( $fid ) . '">' . esc_html__( 'Their link', 'optima-team' ) . '</label>';
			$h  .= '<input id="' . esc_attr( $fid ) . '" type="text" class="large-text" readonly value="' . esc_attr( $url ) . '"></p>';
			$h  .= '<p><button type="button" class="button" data-st-copy="' . esc_attr( $fid ) . '" data-st-done="' . esc_attr__( 'Copied', 'optima-team' ) . '">' . esc_html__( 'Copy the link', 'optima-team' ) . '</button></p>';
			/* translators: %s: the date the link ends. */
			$h .= '<p class="description">' . esc_html( sprintf( __( 'Send it yourself, by text or email. It works once and ends on %s. You see it only now.', 'optima-team' ), $date( (int) ( $link['expires'] ?? 0 ) ) ) ) . '</p>';
		} elseif ( self::active( $id ) ) {
			/* translators: %s: the date the link ends. */
			$h .= '<p>' . esc_html( sprintf( __( 'A link is out. It ends on %s.', 'optima-team' ), $date( (int) $link['expires'] ) ) ) . '</p>';
			$h .= '<p class="sh-actions"><a class="button" href="' . esc_url( self::action_url( $id, 'new', $back ) ) . '">' . esc_html__( 'Make a new link', 'optima-team' ) . '</a> ';
			$h .= '<a class="button sh-quiet sh-danger" href="' . esc_url( self::action_url( $id, 'off', $back ) ) . '">' . esc_html__( 'Turn off the link', 'optima-team' ) . '</a></p>';
			$h .= '<p class="description">' . esc_html__( 'A new link turns off the old one.', 'optima-team' ) . '</p>';
		} else {
			if ( ! empty( $link['used'] ) ) {
				/* translators: %s: date. */
				$h .= '<p>' . esc_html( sprintf( __( 'They used their link on %s.', 'optima-team' ), $date( (int) $link['used'] ) ) ) . '</p>';
			} elseif ( ! empty( $link['expires'] ) ) {
				/* translators: %s: date. */
				$h .= '<p>' . esc_html( sprintf( __( 'Their last link ended on %s.', 'optima-team' ), $date( (int) $link['expires'] ) ) ) . '</p>';
			}
			$h .= '<p class="description">' . esc_html__( 'They fill in their own name, title, short bio, photo and card words. Credentials stay with the setup file. Nothing goes live until it is approved.', 'optima-team' ) . '</p>';
			$h .= '<p><a class="button" href="' . esc_url( self::action_url( $id, 'new', $back ) ) . '">' . esc_html__( 'Send them a link', 'optima-team' ) . '</a></p>';
		}
		return $h;
	}

	/** The words for a note after an action. */
	public static function notes(): array {
		return array(
			'link'      => __( 'Link made. Copy it and send it yourself.', 'optima-team' ),
			'off'       => __( 'The link is off.', 'optima-team' ),
			'approved'  => __( 'Approved. Their details are in the profile.', 'optima-team' ),
			'need_by'   => __( 'Nothing changed. Team bios need the firm\'s approval here: add the attorney who approved the words and tick that the firm approved them.', 'optima-team' ),
			'discarded' => __( 'Discarded. Nothing on the profile changed.', 'optima-team' ),
			'none'      => __( 'There was nothing waiting.', 'optima-team' ),
		);
	}

	/** One line after an action, on the edit screen or the People screen. */
	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$note = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ) : '';
		if ( '' === $note || ! $screen || ( Optima_Team::POST_TYPE !== $screen->post_type && false === strpos( (string) $screen->id, Optima_Team_Admin::SLUG ) ) ) {
			return;
		}
		$notes = self::notes();
		if ( isset( $notes[ $note ] ) ) {
			$type = 'need_by' === $note ? 'warning' : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notes[ $note ] ) . '</p></div>';
		}
	}

	/** People who sent their details and wait for an OK. */
	public static function waiting(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Optima_Team::POST_TYPE,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => Optima_Team::PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}

	/* ---------- privacy tools ---------- */

	public static function exporter( array $exporters ): array {
		$exporters['optima-team-details'] = array(
			'exporter_friendly_name' => __( 'People: details sent by link', 'optima-team' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function eraser( array $erasers ): array {
		$erasers['optima-team-details'] = array(
			'eraser_friendly_name' => __( 'People: details sent by link', 'optima-team' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/**
	 * People whose details are waiting and who match an email: the profile's
	 * email, its card email, or the email of the login it writes posts as.
	 * The link page asks for no email, so this is how a request finds them.
	 */
	private static function ids_for( string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return array();
		}
		$user = get_user_by( 'email', $email );
		$out  = array();
		foreach ( self::waiting() as $id ) {
			$p = Optima_Team::profile( $id );
			if ( strtolower( (string) $p['email'] ) === $email || strtolower( (string) $p['card_email'] ) === $email || ( $user && (int) $p['user'] === (int) $user->ID ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	public static function export_data( string $email, int $page = 1 ): array {
		unset( $page );
		$data = array();
		foreach ( self::ids_for( $email ) as $id ) {
			$pending = self::pending( $id );
			$rows    = array();
			$labels  = array(
				'name'       => __( 'Name', 'optima-team' ),
				'role'       => __( 'Title', 'optima-team' ),
				'bio'        => __( 'Short bio', 'optima-team' ),
				'card_intro' => __( 'Card intro', 'optima-team' ),
				'card_why'   => __( 'Why talk to them', 'optima-team' ),
				'card_tags'  => __( 'Card tags', 'optima-team' ),
			);
			foreach ( $labels as $key => $label ) {
				if ( '' !== trim( (string) ( $pending[ $key ] ?? '' ) ) ) {
					$rows[] = array( 'name' => $label, 'value' => (string) $pending[ $key ] );
				}
			}
			if ( ! empty( $pending['photo'] ) ) {
				$rows[] = array( 'name' => __( 'Photo', 'optima-team' ), 'value' => (string) wp_get_attachment_url( (int) $pending['photo'] ) );
			}
			if ( ! empty( $pending['sent'] ) ) {
				$rows[] = array( 'name' => __( 'Sent', 'optima-team' ), 'value' => wp_date( 'Y-m-d H:i', (int) $pending['sent'] ) );
			}
			$data[] = array(
				'group_id'    => 'optima-team-details',
				'group_label' => __( 'People: details sent by link', 'optima-team' ),
				'item_id'     => 'person-' . $id,
				'data'        => $rows,
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function erase_data( string $email, int $page = 1 ): array {
		unset( $page );
		$ids = self::ids_for( $email );
		foreach ( $ids as $id ) {
			self::discard( $id );
			self::revoke( $id );
		}
		return array(
			'items_removed'  => count( $ids ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
