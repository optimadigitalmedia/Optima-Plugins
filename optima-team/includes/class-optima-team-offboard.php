<?php
/**
 * Offboarding (plugin-first rule 48): a person leaves through four steps in
 * order, never a delete button.
 *
 *   1. Review   Everything on the site that names the person, and what happens
 *               to each: profile page, team page spot, posts under their
 *               byline, case results naming them, pages they reviewed, their
 *               digital card, their photo, their Person schema.
 *   2. Archive  A bundle on this server (profile, posts, results and the
 *               review as JSON and Markdown, plus the photo) in a folder web
 *               visitors cannot read, ready to download and put in the site
 *               repo under the former-people folder and in the firm's Drive
 *               under Former. A failed archive stops the flow here.
 *   3. Confirm  One link, by email, to every operator. Opening it signed in as
 *               an operator runs step 4. Where the site has two or more
 *               operators the confirmer must be someone other than the
 *               starter. The link lives a day; nothing happens if it expires.
 *   4. Remove   Everything about the person leaves the site: the profile and
 *               photo, posts under their byline, case results naming them, the
 *               card. Every old address gets a 301 through Optima Redirects.
 *               Reviewed pages keep their date, lose the name and show on the
 *               Inbox for a new reviewer. One line goes on the former-people
 *               record the People screen shows.
 *
 * The flow is a tab of the Team screen (page=optima-team&tab=offboard&person=ID):
 * no sidebar address of its own (rule 10).
 *
 * @package Optima_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Team_Offboard {

	const TAB     = 'offboard';
	const ACTION  = 'optima_team_offboard';
	const CONFIRM = 'optima_team_offboard_confirm';
	const META    = '_optima_team_offboarding';
	const FORMER  = 'optima_team_former';   // The record of people who left.
	const FOLDER  = 'optima-archive';       // Under wp-content, never under uploads.
	const KEEP    = DAY_IN_SECONDS;         // How long the confirmation link lives.

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::CONFIRM, array( __CLASS__, 'confirm' ) );
		add_action( 'admin_post_optima_team_archive', array( __CLASS__, 'download' ) );
	}

	public static function url( int $person ): string {
		return add_query_arg(
			array(
				'page'   => Optima_Team_Admin::SLUG,
				'tab'    => self::TAB,
				'person' => $person,
			),
			admin_url( 'admin.php' )
		);
	}

	public static function is_open(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
		return isset( $_GET['tab'] ) && self::TAB === sanitize_key( wp_unslash( $_GET['tab'] ) );
	}

	/* ---------- state ---------- */

	/**
	 * Where a person's offboarding stands, always in the full shape.
	 *
	 * @return array{step:int, started_by:int, started_at:int, reason:string, archive:string, token:string, expires:int, sent:int}
	 */
	public static function state( int $person ): array {
		$saved = get_post_meta( $person, self::META, true );
		return self::clean( is_array( $saved ) ? $saved : array() );
	}

	public static function clean( array $in ): array {
		$step = (int) ( $in['step'] ?? 0 );
		return array(
			'step'       => $step >= 0 && $step <= 4 ? $step : 0,
			'started_by' => absint( $in['started_by'] ?? 0 ),
			'started_at' => absint( $in['started_at'] ?? 0 ),
			'reason'     => sanitize_text_field( (string) ( $in['reason'] ?? '' ) ),
			'archive'    => sanitize_text_field( (string) ( $in['archive'] ?? '' ) ),
			'token'      => preg_replace( '/[^a-f0-9]/', '', (string) ( $in['token'] ?? '' ) ),
			'expires'    => absint( $in['expires'] ?? 0 ),
			'sent'       => absint( $in['sent'] ?? 0 ),
		);
	}

	private static function save( int $person, array $state ): void {
		update_post_meta( $person, self::META, self::clean( $state ) );
	}

	/* ---------- 1. review ---------- */

	/**
	 * Everything on the site that names the person, and what step 4 does to each.
	 *
	 * @return array<string, array{label:string, count:int, ids:int[], action:string}>
	 */
	public static function inventory( int $person ): array {
		$p     = Optima_Team::profile( $person );
		$posts = $p['user'] ? get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'author'         => (int) $p['user'],
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		) : array();
		$results = post_type_exists( 'optima_result' ) ? get_posts(
			array(
				'post_type'      => 'optima_result',
				'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => Optima_Team_Links::RESULT_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (string) $person, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		) : array();
		$reviewed = Optima_Team_Reviewed::pages_for( $person );
		$photo    = (int) get_post_thumbnail_id( $person );
		$card     = self::card_key( $person );
		$team     = (string) get_post_type_archive_link( Optima_Team::POST_TYPE );
		$blog     = self::blog_url();
		return array(
			'profile'  => array(
				'label'  => __( 'Profile page', 'optima-team' ),
				'count'  => 1,
				'ids'    => array( $person ),
				/* translators: %s: the team page address */
				'action' => sprintf( __( 'Deleted, 301 to %s', 'optima-team' ), wp_make_link_relative( $team ) ),
			),
			'team'     => array(
				'label'  => __( 'Team page spot', 'optima-team' ),
				'count'  => 1,
				'ids'    => array(),
				'action' => __( 'Removed', 'optima-team' ),
			),
			'posts'    => array(
				'label'  => __( 'Posts under their byline', 'optima-team' ),
				'count'  => count( $posts ),
				'ids'    => array_map( 'intval', $posts ),
				/* translators: %s: the blog address */
				'action' => sprintf( __( 'Deleted, each 301 to %s', 'optima-team' ), wp_make_link_relative( $blog ) ),
			),
			'results'  => array(
				'label'  => __( 'Case results naming them', 'optima-team' ),
				'count'  => count( $results ),
				'ids'    => array_map( 'intval', $results ),
				'action' => __( 'Deleted from the results grid', 'optima-team' ),
			),
			'reviewed' => array(
				'label'  => __( 'Pages they reviewed', 'optima-team' ),
				'count'  => count( $reviewed ),
				'ids'    => array_map( 'intval', $reviewed ),
				'action' => __( 'Keep the date, lose the name, go on the Inbox for a new reviewer', 'optima-team' ),
			),
			'card'     => array(
				'label'  => __( 'Digital card', 'optima-team' ),
				'count'  => '' !== $card ? 1 : 0,
				'ids'    => array(),
				'action' => __( 'Taken off the card page', 'optima-team' ),
			),
			'photo'    => array(
				'label'  => __( 'Photo', 'optima-team' ),
				'count'  => $photo ? 1 : 0,
				'ids'    => $photo ? array( $photo ) : array(),
				'action' => __( 'Removed from the media library (a copy is in the archive)', 'optima-team' ),
			),
			'schema'   => array(
				'label'  => __( 'Person schema', 'optima-team' ),
				'count'  => 1,
				'ids'    => array(),
				'action' => __( 'Gone with the profile', 'optima-team' ),
			),
		);
	}

	/** The person's key in Optima Cards' people list, or ''. Matched by name or email. */
	private static function card_key( int $person ): string {
		if ( ! class_exists( 'Optima_Cards_Settings' ) || ! method_exists( 'Optima_Cards_Settings', 'get' ) ) {
			return '';
		}
		$p     = Optima_Team::profile( $person );
		$name  = strtolower( trim( get_the_title( $person ) ) );
		$email = strtolower( trim( $p['email'] ) );
		foreach ( (array) ( Optima_Cards_Settings::get()['people'] ?? array() ) as $i => $card ) {
			$card = (array) $card;
			if ( strtolower( trim( (string) ( $card['name'] ?? '' ) ) ) === $name || ( '' !== $email && strtolower( trim( (string) ( $card['email'] ?? '' ) ) ) === $email ) ) {
				return (string) $i;
			}
		}
		return '';
	}

	private static function blog_url(): string {
		$page = (int) get_option( 'page_for_posts' );
		return $page ? (string) get_permalink( $page ) : home_url( '/' );
	}

	/* ---------- 2. archive ---------- */

	/** The archive root under wp-content, made once, closed to web visitors. */
	private static function root(): string {
		$dir = trailingslashit( WP_CONTENT_DIR ) . self::FOLDER;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a blank index.
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- closes the folder on Apache; nginx sites are covered by the download action only.
		}
		return $dir;
	}

	/**
	 * Write the bundle. Returns the folder name, or a WP_Error.
	 *
	 * @return string|WP_Error
	 */
	public static function archive( int $person, array $inventory, string $reason ) {
		$p    = Optima_Team::profile( $person );
		$name = get_the_title( $person );
		$slug = sanitize_title( $name ) ?: 'person-' . $person;
		$dir  = self::root() . '/' . $slug . '-' . gmdate( 'Y-m-d' );
		if ( is_dir( $dir ) ) {
			$dir .= '-' . gmdate( 'His' );
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'archive', __( 'The archive folder could not be made. Nothing was removed.', 'optima-team' ) );
		}
		$post = get_post( $person );
		$bundle = array(
			'person'     => array(
				'id'       => $person,
				'name'     => $name,
				'slug'     => $post ? $post->post_name : $slug,
				'url'      => get_permalink( $person ),
				'profile'  => $p,
				'long_bio' => $post ? $post->post_content : '',
				'order'    => $post ? $post->menu_order : 0,
				'status'   => $post ? $post->post_status : '',
			),
			'posts'      => array(),
			'results'    => array(),
			'reviewed'   => array(),
			'left'       => gmdate( 'c' ),
			'reason'     => $reason,
			'started_by' => wp_get_current_user()->display_name,
			'site'       => home_url( '/' ),
			'inventory'  => array_map( static fn( $row ) => array( 'label' => $row['label'], 'count' => $row['count'], 'action' => $row['action'] ), $inventory ),
		);
		foreach ( $inventory['posts']['ids'] as $id ) {
			$x = get_post( $id );
			if ( $x ) {
				$bundle['posts'][] = array(
					'id'       => $id,
					'title'    => $x->post_title,
					'url'      => get_permalink( $id ),
					'status'   => $x->post_status,
					'date'     => $x->post_date_gmt,
					'content'  => $x->post_content,
					'excerpt'  => $x->post_excerpt,
					'meta'     => array_map( static fn( $v ) => count( $v ) === 1 ? $v[0] : $v, (array) get_post_meta( $id ) ),
				);
			}
		}
		foreach ( $inventory['results']['ids'] as $id ) {
			$bundle['results'][] = class_exists( 'Optima_Results' ) ? Optima_Results::get( $id ) : array( 'id' => $id, 'title' => get_the_title( $id ) );
		}
		foreach ( $inventory['reviewed']['ids'] as $id ) {
			$bundle['reviewed'][] = array(
				'id'    => $id,
				'title' => get_the_title( $id ),
				'url'   => get_permalink( $id ),
				'on'    => (string) get_post_meta( $id, Optima_Team_Reviewed::META_WHEN, true ),
			);
		}
		$json = wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || false === file_put_contents( $dir . '/person.json', $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the archive.
			return new WP_Error( 'archive', __( 'The archive could not be written. Nothing was removed.', 'optima-team' ) );
		}
		file_put_contents( $dir . '/README.md', self::readme( $bundle ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the archive.
		foreach ( $bundle['posts'] as $i => $x ) {
			file_put_contents( $dir . '/post-' . ( $i + 1 ) . '-' . sanitize_title( $x['title'] ) . '.md', "# " . $x['title'] . "\n\n" . $x['url'] . "\n\n" . $x['content'] . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the archive.
		}
		foreach ( $inventory['photo']['ids'] as $photo ) {
			$file = get_attached_file( $photo );
			if ( $file && is_readable( $file ) ) {
				copy( $file, $dir . '/' . basename( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- the photo into the archive.
			}
		}
		return basename( $dir );
	}

	private static function readme( array $b ): string {
		$lines   = array();
		$lines[] = '# ' . $b['person']['name'] . ': former team member';
		$lines[] = '';
		$lines[] = 'Left: ' . $b['left'] . '. Offboarded by ' . $b['started_by'] . '. Site: ' . $b['site'] . '.';
		if ( '' !== $b['reason'] ) {
			$lines[] = 'Reason: ' . $b['reason'];
		}
		$lines[] = '';
		$lines[] = 'Put this folder in the site repo under content/people/former/ and in the firm\'s Drive under Team, Former. The site itself no longer holds any of it.';
		$lines[] = '';
		$lines[] = '## What was on the site, and what happened to it';
		$lines[] = '';
		foreach ( $b['inventory'] as $row ) {
			$lines[] = '- ' . $row['label'] . ': ' . $row['count'] . '. ' . $row['action'] . '.';
		}
		$lines[] = '';
		$lines[] = '## Files';
		$lines[] = '';
		$lines[] = '- person.json: the profile, every post, every case result and every reviewed page, with their meta.';
		$lines[] = '- post-*.md: each post as Markdown.';
		$lines[] = '- The photo file, when there was one.';
		return implode( "\n", $lines ) . "\n";
	}

	/* ---------- 3. confirm ---------- */

	/** Every operator account on the site. */
	public static function operators(): array {
		if ( ! class_exists( 'Optima_Hub_Access' ) ) {
			return array_filter( get_users( array( 'role' => 'administrator', 'number' => 50 ) ), static fn( $u ) => is_email( $u->user_email ) );
		}
		$out = array();
		foreach ( get_users( array( 'capability' => 'manage_options', 'number' => 200 ) ) as $u ) {
			if ( Optima_Hub_Access::is_operator( $u ) ) {
				$out[] = $u;
			}
		}
		return $out;
	}

	private static function send_link( int $person, array &$state ): bool {
		$token            = bin2hex( random_bytes( 16 ) );
		$state['token']   = hash( 'sha256', $token );
		$state['expires'] = time() + self::KEEP;
		$state['sent']    = time();
		$link = add_query_arg(
			array(
				'action' => self::CONFIRM,
				'person' => $person,
				'token'  => $token,
			),
			admin_url( 'admin-post.php' )
		);
		$name    = get_the_title( $person );
		$starter = get_userdata( $state['started_by'] );
		$to      = array_map( static fn( $u ) => $u->user_email, self::operators() );
		if ( ! $to ) {
			return false;
		}
		/* translators: 1: site name, 2: person's name */
		$subject = sprintf( __( '[%1$s] Confirm: remove %2$s from the site', 'optima-team' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $name );
		$body    = sprintf(
			/* translators: 1: person's name, 2: who started it, 3: the link, 4: hours */
			__( "%2\$s started offboarding %1\$s. The archive is saved. Nothing has been removed yet.\n\nOpen this link, signed in as an operator, to remove everything about %1\$s from the site:\n%3\$s\n\nThe link works for %4\$d hours. If nobody opens it, nothing happens.", 'optima-team' ),
			$name,
			$starter ? $starter->display_name : __( 'An operator', 'optima-team' ),
			$link,
			(int) ( self::KEEP / HOUR_IN_SECONDS )
		);
		return (bool) wp_mail( $to, $subject, $body );
	}

	/** The emailed link lands here. */
	public static function confirm(): void {
		$person = isset( $_GET['person'] ) ? absint( wp_unslash( $_GET['person'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the check.
		$token  = isset( $_GET['token'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( add_query_arg( array( 'action' => self::CONFIRM, 'person' => $person, 'token' => $token ), admin_url( 'admin-post.php' ) ) ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) || ( class_exists( 'Optima_Hub_Access' ) && ! Optima_Hub_Access::is_operator() ) ) {
			wp_die( esc_html__( 'Only an operator can confirm this.', 'optima-team' ) );
		}
		$state = self::state( $person );
		if ( 3 !== $state['step'] || '' === $token || ! hash_equals( $state['token'], hash( 'sha256', $token ) ) || time() > $state['expires'] ) {
			self::notice( 'error', __( 'That confirmation link is not valid any more. Start again from the person\'s profile if they still need to go.', 'optima-team' ) );
			wp_safe_redirect( Optima_Team_Admin::url() );
			exit;
		}
		if ( count( self::operators() ) > 1 && get_current_user_id() === $state['started_by'] ) {
			self::notice( 'error', __( 'A different operator has to confirm this one. The link went to every operator.', 'optima-team' ) );
			wp_safe_redirect( self::url( $person ) );
			exit;
		}
		$state['step'] = 4;
		self::save( $person, $state );
		$done = self::remove( $person, $state );
		self::notice( 'success', $done );
		wp_safe_redirect( Optima_Team_Admin::url() );
		exit;
	}

	/* ---------- 4. remove ---------- */

	/** Everything about the person leaves the site. Returns the one-line summary. */
	private static function remove( int $person, array $state ): string {
		$inv  = self::inventory( $person );
		$name = get_the_title( $person );
		$team = (string) get_post_type_archive_link( Optima_Team::POST_TYPE );
		$blog = self::blog_url();
		$n    = array( 'posts' => 0, 'results' => 0, 'reviewed' => 0, 'redirects' => 0 );

		// Posts under their byline: archive is written; delete and forward each.
		foreach ( $inv['posts']['ids'] as $id ) {
			$old = get_permalink( $id );
			if ( wp_delete_post( $id, true ) ) {
				++$n['posts'];
				$n['redirects'] += self::redirect( $old, $blog, $id ) ? 1 : 0;
			}
		}
		// Case results naming them (no public address of their own).
		foreach ( $inv['results']['ids'] as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				++$n['results'];
			}
		}
		// Reviewed pages keep their date, lose the name.
		$n['reviewed'] = Optima_Team_Reviewed::release( $person );
		// The card.
		self::drop_card( $person );
		// The photo.
		foreach ( $inv['photo']['ids'] as $photo ) {
			wp_delete_attachment( $photo, true );
		}
		// The profile, last, with its address forwarded to the team page.
		$old = get_permalink( $person );
		$post = get_post( $person );
		wp_delete_post( $person, true );
		$n['redirects'] += self::redirect( $old, $team, 0 ) ? 1 : 0;

		// The record.
		$former   = (array) get_option( self::FORMER, array() );
		$former[] = array(
			'name'      => $name,
			'slug'      => $post ? $post->post_name : '',
			'left'      => time(),
			'by'        => (int) get_current_user_id(),
			'started'   => $state['started_by'],
			'reason'    => $state['reason'],
			'archive'   => $state['archive'],
			'removed'   => $n,
		);
		update_option( self::FORMER, array_slice( $former, -100 ), false );
		if ( class_exists( 'Optima_Hub_Features' ) && method_exists( 'Optima_Hub_Features', 'note' ) ) {
			/* translators: %s: person's name */
			Optima_Hub_Features::note( 'optima-team', 'offboard', sprintf( __( '%s removed from the site', 'optima-team' ), $name ) );
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		return sprintf(
			/* translators: 1: name, 2: posts, 3: results, 4: reviewed pages, 5: redirects */
			__( '%1$s is off the site: %2$d posts and %3$d results deleted, %4$d reviewed pages waiting for a new reviewer, %5$d addresses forwarded. The archive is under Former on the People screen.', 'optima-team' ),
			$name,
			$n['posts'],
			$n['results'],
			$n['reviewed'],
			$n['redirects']
		);
	}

	private static function redirect( string $from, string $to, int $post_id ): bool {
		if ( '' === $from || '' === $to || ! class_exists( 'Optima_Redirects' ) ) {
			return false;
		}
		$r = Optima_Redirects::add( $from, $to, 301, 'offboarding', $post_id );
		return ! is_wp_error( $r ) && false !== $r;
	}

	private static function drop_card( int $person ): void {
		$key = self::card_key( $person );
		if ( '' === $key || ! class_exists( 'Optima_Cards_Settings' ) ) {
			return;
		}
		$s = Optima_Cards_Settings::get();
		if ( isset( $s['people'][ $key ] ) ) {
			unset( $s['people'][ $key ] );
			$s['people'] = array_values( (array) $s['people'] );
			update_option( Optima_Cards_Settings::OPTION, $s );
		}
	}

	/* ---------- the former-people record and the archive download ---------- */

	public static function former(): array {
		return array_reverse( (array) get_option( self::FORMER, array() ) );
	}

	public static function download_url( string $archive ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'optima_team_archive', 'archive' => $archive ), admin_url( 'admin-post.php' ) ), 'optima_team_archive_' . $archive );
	}

	/** Zip the archive folder and send it to the operator. */
	public static function download(): void {
		$archive = isset( $_GET['archive'] ) ? sanitize_file_name( wp_unslash( $_GET['archive'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next line.
		if ( '' === $archive || ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'optima_team_archive_' . $archive ) ) {
			wp_die( esc_html__( 'Not allowed.', 'optima-team' ) );
		}
		$dir = self::root() . '/' . $archive;
		if ( ! is_dir( $dir ) || ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'That archive is not here, or this server cannot make a zip. The folder is wp-content/optima-archive on the server.', 'optima-team' ) );
		}
		$tmp = wp_tempnam( $archive . '.zip' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'The zip could not be made.', 'optima-team' ) );
		}
		foreach ( scandir( $dir ) ?: array() as $f ) {
			if ( '.' !== $f[0] && is_file( $dir . '/' . $f ) ) {
				$zip->addFile( $dir . '/' . $f, $archive . '/' . $f );
			}
		}
		$zip->close();
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $archive . '.zip"' );
		header( 'Content-Length: ' . (string) filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- sending the zip.
		wp_delete_file( $tmp );
		exit;
	}

	/* ---------- the form handler ---------- */

	private static function notice( string $type, string $message ): void {
		set_transient( 'optima_team_add_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) || ( class_exists( 'Optima_Hub_Access' ) && ! Optima_Hub_Access::is_operator() ) ) {
			wp_die( esc_html__( 'Only an operator can do this.', 'optima-team' ) );
		}
		check_admin_referer( self::ACTION );
		$person = isset( $_POST['person'] ) ? absint( wp_unslash( $_POST['person'] ) ) : 0;
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( ! $person || Optima_Team::POST_TYPE !== get_post_type( $person ) ) {
			wp_safe_redirect( Optima_Team_Admin::url() );
			exit;
		}
		$state = self::state( $person );

		if ( 'cancel' === $do ) {
			delete_post_meta( $person, self::META );
			/* translators: %s: person's name */
			self::notice( 'success', sprintf( __( 'Offboarding stopped. %s stays on the site. The archive folder, if one was written, is still on the server.', 'optima-team' ), get_the_title( $person ) ) );
			wp_safe_redirect( (string) get_edit_post_link( $person, 'raw' ) );
			exit;
		}

		if ( 'start' === $do ) {
			$state = self::clean(
				array(
					'step'       => 1,
					'started_by' => get_current_user_id(),
					'started_at' => time(),
					'reason'     => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '',
				)
			);
			self::save( $person, $state );
			wp_safe_redirect( self::url( $person ) );
			exit;
		}

		if ( 'archive' === $do && 1 === $state['step'] ) {
			$state['reason'] = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : $state['reason'];
			$made            = self::archive( $person, self::inventory( $person ), $state['reason'] );
			if ( is_wp_error( $made ) ) {
				self::notice( 'error', $made->get_error_message() );
				wp_safe_redirect( self::url( $person ) );
				exit;
			}
			$state['archive'] = $made;
			$state['step']    = 2;
			self::save( $person, $state );
			wp_safe_redirect( self::url( $person ) );
			exit;
		}

		if ( 'send' === $do && 2 === $state['step'] ) {
			if ( ! self::send_link( $person, $state ) ) {
				self::notice( 'error', __( 'The confirmation email could not be sent. Check Build, Email, then try again.', 'optima-team' ) );
				wp_safe_redirect( self::url( $person ) );
				exit;
			}
			$state['step'] = 3;
			self::save( $person, $state );
			wp_safe_redirect( self::url( $person ) );
			exit;
		}

		if ( 'resend' === $do && 3 === $state['step'] ) {
			if ( self::send_link( $person, $state ) ) {
				self::save( $person, $state );
				self::notice( 'success', __( 'A new link went to every operator. The old one no longer works.', 'optima-team' ) );
			} else {
				self::notice( 'error', __( 'The confirmation email could not be sent. Check Build, Email, then try again.', 'optima-team' ) );
			}
			wp_safe_redirect( self::url( $person ) );
			exit;
		}

		wp_safe_redirect( self::url( $person ) );
		exit;
	}

	/* ---------- the screen ---------- */

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$person = isset( $_GET['person'] ) ? absint( wp_unslash( $_GET['person'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
		if ( ! $person || Optima_Team::POST_TYPE !== get_post_type( $person ) ) {
			Optima_Admin_UI::open( __( 'Offboarding', 'optima-team' ), __( 'Open a person under People and choose Start offboarding on their profile.', 'optima-team' ), '', Optima_Team_Admin::SLUG );
			Optima_Admin_UI::close();
			return;
		}
		$state = self::state( $person );
		$name  = get_the_title( $person );
		Optima_Admin_UI::open(
			/* translators: %s: person's name */
			sprintf( __( 'Remove %s from the site', 'optima-team' ), $name ),
			__( 'Four steps, in order. Nothing leaves the site until the archive is saved and an operator confirms by email. Stop at any step before the last one.', 'optima-team' ),
			'',
			Optima_Team_Admin::SLUG
		);
		Optima_Team_Add::print_notice();
		echo '<p class="sh-note"><a href="' . esc_url( admin_url( 'admin.php?page=' . Optima_Team_Admin::SLUG ) ) . '">&lsaquo; ' . esc_html__( 'Back to People', 'optima-team' ) . '</a></p>';
		self::stepper( max( 1, $state['step'] ) );
		if ( 0 === $state['step'] ) {
			self::step_start( $person );
		} elseif ( 1 === $state['step'] ) {
			self::step_review( $person, $state );
		} elseif ( 2 === $state['step'] ) {
			self::step_archived( $person, $state );
		} elseif ( 3 === $state['step'] ) {
			self::step_waiting( $person, $state );
		}
		Optima_Admin_UI::close();
	}

	private static function stepper( int $current ): void {
		$steps = array(
			1 => __( 'Review', 'optima-team' ),
			2 => __( 'Archive', 'optima-team' ),
			3 => __( 'Confirm by email', 'optima-team' ),
			4 => __( 'Remove from site', 'optima-team' ),
		);
		echo '<ol class="st-add-steps st-add-steps-4">';
		foreach ( $steps as $n => $title ) {
			$class = $n < $current ? 'is-done' : ( $n === $current ? 'is-current' : '' );
			printf( '<li class="%1$s"%2$s><span class="st-add-n" aria-hidden="true">%3$d</span><span>%4$s</span></li>', esc_attr( $class ), $n === $current ? ' aria-current="step"' : '', (int) $n, esc_html( $title ) );
		}
		echo '</ol>';
	}

	private static function form_open( int $person, string $do ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sh-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		echo '<input type="hidden" name="person" value="' . esc_attr( (string) $person ) . '">';
		wp_nonce_field( self::ACTION );
	}

	private static function cancel_button(): void {
		echo '<button type="submit" name="do" value="cancel" class="button-link sh-quiet" formnovalidate>' . esc_html__( 'Stop offboarding', 'optima-team' ) . '</button>';
	}

	private static function inventory_table( array $inv ): void {
		echo '<table class="sh-table">';
		foreach ( $inv as $row ) {
			if ( 0 === $row['count'] ) {
				continue;
			}
			echo '<tr><td>' . esc_html( $row['label'] ) . '</td><td><strong>' . esc_html( (string) $row['count'] ) . '</strong> · ' . esc_html( $row['action'] ) . '</td></tr>';
		}
		echo '</table>';
	}

	private static function step_start( int $person ): void {
		self::form_open( $person, 'start' );
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Start offboarding', 'optima-team' ); ?></h2>
			<p><?php esc_html_e( 'Everything about this person leaves the site: profile, photo, posts under their byline, case results naming them, their card. Pages they reviewed keep the date and wait for a new reviewer. It is all archived first.', 'optima-team' ); ?></p>
			<p>
				<label for="st-off-reason"><?php esc_html_e( 'Reason, for the record (optional)', 'optima-team' ); ?></label><br>
				<input type="text" id="st-off-reason" name="reason" class="regular-text" placeholder="<?php esc_attr_e( 'Left the firm, per the firm\'s email of Nov 4', 'optima-team' ); ?>">
			</p>
			<p class="sh-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Review what leaves', 'optima-team' ); ?></button> <a class="button sh-quiet" href="<?php echo esc_url( (string) get_edit_post_link( $person, 'raw' ) ); ?>"><?php esc_html_e( 'Back to the profile', 'optima-team' ); ?></a></p>
		</section>
		</form>
		<?php
	}

	private static function step_review( int $person, array $state ): void {
		$inv = self::inventory( $person );
		self::form_open( $person, 'archive' );
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( '1 · What leaves the site', 'optima-team' ); ?></h2>
			<?php self::inventory_table( $inv ); ?>
			<p class="sh-note"><?php esc_html_e( 'Posts that rank are deleted like the rest and forwarded to the blog. If any should stay under the firm\'s name, stop here and change their author first.', 'optima-team' ); ?></p>
			<input type="hidden" name="reason" value="<?php echo esc_attr( $state['reason'] ); ?>">
			<p class="sh-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Archive it all, then continue', 'optima-team' ); ?></button> <?php self::cancel_button(); ?></p>
		</section>
		</form>
		<?php
	}

	private static function step_archived( int $person, array $state ): void {
		$ops = self::operators();
		self::form_open( $person, 'send' );
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( '2 · Archived', 'optima-team' ); ?></h2>
			<p>
				<?php
				/* translators: %s: folder name */
				echo esc_html( sprintf( __( 'Saved on this server as %s, closed to visitors.', 'optima-team' ), $state['archive'] ) );
				?>
				<a href="<?php echo esc_url( self::download_url( $state['archive'] ) ); ?>"><?php esc_html_e( 'Download the zip', 'optima-team' ); ?></a>
			</p>
			<p class="sh-note"><?php esc_html_e( 'Put it in the site repo under content/people/former/ and in the firm\'s Drive under Team, Former, and move their Knowledge Hub row to Former with the leave date. The site itself will hold none of it after step 4.', 'optima-team' ); ?></p>
		</section>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( '3 · Confirm by email', 'optima-team' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: operators */
						_n( 'One link goes to the site\'s %d operator. Opening it, signed in, removes everything in step 4. It works for a day.', 'One link goes to the site\'s %d operators. Someone other than you has to open it, signed in; that removes everything in step 4. It works for a day.', count( $ops ), 'optima-team' ),
						count( $ops )
					)
				);
				?>
			</p>
			<?php if ( ! $ops ) : ?>
				<p class="sh-note"><strong><?php esc_html_e( 'No operator account with an email address was found, so the link has nowhere to go.', 'optima-team' ); ?></strong></p>
			<?php endif; ?>
			<p class="sh-actions"><button type="submit" class="button button-primary" <?php disabled( ! $ops ); ?>><?php esc_html_e( 'Send the confirmation link', 'optima-team' ); ?></button> <?php self::cancel_button(); ?></p>
		</section>
		</form>
		<?php
	}

	private static function step_waiting( int $person, array $state ): void {
		$left = max( 0, $state['expires'] - time() );
		self::form_open( $person, 'resend' );
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( '3 · Waiting for the link to be opened', 'optima-team' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					$left
						/* translators: %s: time left */
						? sprintf( __( 'Sent %1$s ago. It works for another %2$s. Nothing happens if nobody opens it.', 'optima-team' ), human_time_diff( $state['sent'] ), human_time_diff( time(), $state['expires'] ) )
						: __( 'The link has expired. Send a new one, or stop.', 'optima-team' )
				);
				?>
			</p>
			<p class="sh-note"><?php esc_html_e( 'Step 4 runs the moment an operator opens the link: the profile, photo, posts, results and card are deleted, every old address is forwarded, and reviewed pages go on the Inbox for a new reviewer.', 'optima-team' ); ?></p>
			<p class="sh-actions"><button type="submit" class="button"><?php esc_html_e( 'Send a new link', 'optima-team' ); ?></button> <?php self::cancel_button(); ?></p>
		</section>
		</form>
		<?php
	}

	/** The former-people record, on the People screen. */
	public static function former_box(): void {
		$former = self::former();
		if ( ! $former ) {
			return;
		}
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Former', 'optima-team' ); ?></h2>
			<p><?php esc_html_e( 'People removed from this site, with their archive. The archive is the only copy left here; the repo and Drive hold the rest.', 'optima-team' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'optima-team' ); ?></th><th scope="col"><?php esc_html_e( 'Left', 'optima-team' ); ?></th><th scope="col"><?php esc_html_e( 'Removed', 'optima-team' ); ?></th><th scope="col"><?php esc_html_e( 'Archive', 'optima-team' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $former as $row ) : ?>
					<?php $by = get_userdata( (int) $row['by'] ); ?>
					<tr>
						<td><strong><?php echo esc_html( (string) $row['name'] ); ?></strong><?php echo '' !== (string) $row['reason'] ? '<br><span class="description">' . esc_html( (string) $row['reason'] ) . '</span>' : ''; ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), (int) $row['left'] ) . ( $by ? ' · ' . $by->display_name : '' ) ); ?></td>
						<td>
							<?php
							$n = (array) $row['removed'];
							/* translators: 1: posts, 2: results, 3: reviewed pages, 4: redirects */
							echo esc_html( sprintf( __( '%1$d posts, %2$d results, %3$d reviewed pages released, %4$d redirects', 'optima-team' ), (int) ( $n['posts'] ?? 0 ), (int) ( $n['results'] ?? 0 ), (int) ( $n['reviewed'] ?? 0 ), (int) ( $n['redirects'] ?? 0 ) ) );
							?>
						</td>
						<td><?php echo '' !== (string) $row['archive'] ? '<a href="' . esc_url( self::download_url( (string) $row['archive'] ) ) . '">' . esc_html( (string) $row['archive'] ) . '.zip</a>' : esc_html__( 'None', 'optima-team' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}
}
