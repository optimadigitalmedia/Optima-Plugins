<?php
/**
 * Reviewed by: a page names the person from People who reviewed it and the
 * date (plugin-first rule 47). The page prints one line at the before-close
 * slot and carries reviewedBy and lastReviewed in its schema. When the person
 * is offboarded the name comes off and the page goes on the Inbox for a new
 * reviewer.
 *
 * The line and the schema print only for a complete record: the reviewer, the
 * review date, and the version of the page the review covers (a fingerprint
 * of its title, words and excerpt, taken when the review is recorded). When
 * the page changes after that, nothing prints and People lists the page as
 * "Review out of date" until the reviewer signs off on the new words. A record
 * from before versions were kept covers the page while it has not been
 * changed after the review date. A date is never filled in for anyone.
 *
 * @package Optima_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Team_Reviewed {

	const META_WHO  = '_optima_reviewed_by';
	const META_WHEN = '_optima_reviewed_on';
	const META_VER  = '_optima_reviewed_version'; // Fingerprint of the words the review covers.
	const META_AT   = '_optima_reviewed_at';      // The page's modified time (GMT) when that version was recorded.
	const NONCE     = 'optima_team_reviewed';

	/** Post types that can carry a reviewer: every public type except People itself. */
	public static function types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types[ Optima_Team::POST_TYPE ], $types['attachment'] );
		return (array) apply_filters( 'optima_team_reviewed_types', array_values( $types ) );
	}

	public static function boot(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'box' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'optima_schema_graph', array( __CLASS__, 'schema' ), 20 );
		add_action( 'optima_slot_before-close', array( __CLASS__, 'line' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list' ) );
	}

	/**
	 * Who reviewed a page, and when. Nobody when the profile is gone or unpublished.
	 *
	 * @return array{who:int, when:string}
	 */
	public static function get( int $post_id ): array {
		$who  = (int) get_post_meta( $post_id, self::META_WHO, true );
		$when = (string) get_post_meta( $post_id, self::META_WHEN, true );
		if ( $who && ( Optima_Team::POST_TYPE !== get_post_type( $who ) || 'publish' !== get_post_status( $who ) ) ) {
			$who = 0;
		}
		return array(
			'who'  => $who,
			'when' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $when ) ? $when : '',
		);
	}

	/** A fingerprint of the words on a page: title, content and excerpt. */
	public static function fingerprint( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		return md5( $post->post_title . "\n" . $post->post_content . "\n" . $post->post_excerpt );
	}

	/**
	 * Where a page's review record stands.
	 *
	 * complete: reviewer, date and version are all there and the page has not
	 * changed since. incomplete: something is missing, so nothing prints.
	 * stale: the page changed after the review, so nothing prints and People
	 * flags it as "Review out of date". none: no review recorded.
	 */
	public static function status( int $post_id ): string {
		$r = self::get( $post_id );
		if ( ! $r['who'] && '' === $r['when'] && '' === (string) get_post_meta( $post_id, self::META_WHO, true ) ) {
			return 'none';
		}
		if ( ! $r['who'] || '' === $r['when'] ) {
			return 'incomplete';
		}
		$ver = (string) get_post_meta( $post_id, self::META_VER, true );
		if ( '' !== $ver ) {
			return hash_equals( $ver, self::fingerprint( $post_id ) ) ? 'complete' : 'stale';
		}
		// A record from before versions were kept: it covers the page only while the
		// page has not been changed after the review date.
		$modified = substr( (string) get_post_field( 'post_modified', $post_id ), 0, 10 );
		return ( '' !== $modified && $modified > $r['when'] ) ? 'stale' : 'complete';
	}

	/** The review to print, or who 0 when the record is not complete and current. */
	public static function printable( int $post_id ): array {
		$r = self::get( $post_id );
		return 'complete' === self::status( $post_id ) ? $r : array( 'who' => 0, 'when' => '' );
	}

	/**
	 * Pages whose review needs someone: out of date or incomplete. Reviewer-less
	 * pages after offboarding are on the Inbox already and are left out here.
	 *
	 * @return array<int, string> post ID => status.
	 */
	public static function needs_attention(): array {
		$ids = get_posts(
			array(
				'post_type'      => self::types(),
				'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META_WHO, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare'   => '!=', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$status = self::status( (int) $id );
			if ( 'stale' === $status || 'incomplete' === $status ) {
				$out[ (int) $id ] = $status;
			}
		}
		return $out;
	}

	/** Pages a person reviewed, any status. */
	public static function pages_for( int $person_id ): array {
		return get_posts(
			array(
				'post_type'      => self::types(),
				'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META_WHO, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (string) $person_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	/** Pages whose reviewer was removed: they keep the date and wait for a new name. */
	public static function orphaned(): array {
		$ids = get_posts(
			array(
				'post_type'      => self::types(),
				'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META_WHO, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return array_values( array_filter( $ids, static fn( $id ) => '' !== get_post_meta( (int) $id, self::META_WHEN, true ) ) );
	}

	/** Offboarding: take the name off every page the person reviewed; keep the date. */
	public static function release( int $person_id ): int {
		$n = 0;
		foreach ( self::pages_for( $person_id ) as $id ) {
			update_post_meta( (int) $id, self::META_WHO, '0' );
			++$n;
		}
		return $n;
	}

	/* ---------- the box ---------- */

	public static function box( string $post_type, $post ): void {
		if ( ! in_array( $post_type, self::types(), true ) ) {
			return;
		}
		add_meta_box( 'optima-team-reviewed', __( 'Reviewed by', 'optima-team' ), array( __CLASS__, 'render_box' ), $post_type, 'side', 'default' );
	}

	public static function render_box( WP_Post $post ): void {
		$r      = self::get( $post->ID );
		$people = get_posts(
			array(
				'post_type'      => Optima_Team::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		wp_nonce_field( self::NONCE, '_optima_team_reviewed_nonce' );
		$stale  = 0 === $r['who'] && '' !== $r['when'];
		$status = self::status( $post->ID );
		?>
		<p>
			<label for="st-reviewed-who" class="screen-reader-text"><?php esc_html_e( 'Reviewed by', 'optima-team' ); ?></label>
			<select id="st-reviewed-who" name="optima_reviewed[who]" style="width:100%">
				<option value="0"><?php esc_html_e( 'Nobody yet', 'optima-team' ); ?></option>
				<?php foreach ( $people as $person ) : ?>
					<option value="<?php echo esc_attr( (string) $person->ID ); ?>" <?php selected( $r['who'], (int) $person->ID ); ?>><?php echo esc_html( $person->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="st-reviewed-when"><?php esc_html_e( 'Reviewed on', 'optima-team' ); ?></label>
			<input type="date" id="st-reviewed-when" name="optima_reviewed[when]" value="<?php echo esc_attr( $r['when'] ); ?>" style="width:100%">
		</p>
		<?php if ( $stale ) : ?>
			<p class="description"><strong><?php esc_html_e( 'The reviewer left the firm. Pick a new one.', 'optima-team' ); ?></strong></p>
		<?php elseif ( 'stale' === $status ) : ?>
			<p class="description"><strong><?php esc_html_e( 'Review out of date: the page changed after the review, so the line is not showing.', 'optima-team' ); ?></strong></p>
			<p><label><input type="checkbox" name="optima_reviewed[confirm]" value="1"> <?php esc_html_e( 'The reviewer read the page as it is now, on the date above', 'optima-team' ); ?></label></p>
		<?php elseif ( 'incomplete' === $status ) : ?>
			<p class="description"><strong><?php esc_html_e( 'Not showing yet: add both the reviewer and the date of the review.', 'optima-team' ); ?></strong></p>
		<?php elseif ( 'complete' === $status ) : ?>
			<p class="description"><?php esc_html_e( 'Showing on the page. If the words change, the line comes off until the reviewer signs off again.', 'optima-team' ); ?></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Prints "Reviewed by" with the date at the end of the page and in its schema. On a law-firm site this is the attorney who signed off on the words.', 'optima-team' ); ?></p>
		<?php
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['_optima_team_reviewed_nonce'], $_POST['optima_reviewed'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_optima_team_reviewed_nonce'] ) ), self::NONCE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in   = (array) wp_unslash( $_POST['optima_reviewed'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$who  = absint( $in['who'] ?? 0 );
		$when = sanitize_text_field( (string) ( $in['when'] ?? '' ) );
		if ( $who && Optima_Team::POST_TYPE !== get_post_type( $who ) ) {
			$who = 0;
		}
		// No date is made up: a review without its date stays incomplete and prints nothing.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $when ) ) {
			$when = '';
		}
		$was_who  = (string) get_post_meta( $post_id, self::META_WHO, true );
		$was_when = (string) get_post_meta( $post_id, self::META_WHEN, true );
		if ( $who || '' !== $when ) {
			update_post_meta( $post_id, self::META_WHO, (string) $who );
			update_post_meta( $post_id, self::META_WHEN, $when );
		} else {
			delete_post_meta( $post_id, self::META_WHO );
			delete_post_meta( $post_id, self::META_WHEN );
			delete_post_meta( $post_id, self::META_VER );
			delete_post_meta( $post_id, self::META_AT );
			return;
		}
		// The version the review covers is recorded when a review is entered or
		// changed, or when the operator confirms the reviewer read the page as it is now.
		// Saving the page for any other reason keeps the old version, so changed words show as out of date.
		$changed = (string) $who !== $was_who || $when !== $was_when || ! empty( $in['confirm'] );
		if ( ! $changed ) {
			return;
		}
		if ( $who && '' !== $when ) {
			update_post_meta( $post_id, self::META_VER, self::fingerprint( $post_id ) );
			update_post_meta( $post_id, self::META_AT, (string) get_post_field( 'post_modified_gmt', $post_id ) );
		} else {
			delete_post_meta( $post_id, self::META_VER );
			delete_post_meta( $post_id, self::META_AT );
		}
	}

	/* ---------- on the site ---------- */

	/** reviewedBy and lastReviewed on the page's node, and the reviewer's Person node. */
	public static function schema( $graph ) {
		if ( ! is_singular() || ! is_array( $graph ) ) {
			return $graph;
		}
		$r = self::printable( get_queried_object_id() );
		if ( ! $r['who'] ) {
			return $graph;
		}
		foreach ( array( 'webpage', 'article', 'optima_team_page' ) as $key ) {
			if ( isset( $graph[ $key ] ) && is_array( $graph[ $key ] ) ) {
				$graph[ $key ]['reviewedBy'] = array( '@id' => Optima_Team_Schema::person_id( $r['who'] ) );
				if ( '' !== $r['when'] ) {
					$graph[ $key ]['lastReviewed'] = $r['when'];
				}
			}
		}
		if ( ! isset( $graph[ 'person_' . $r['who'] ] ) ) {
			$graph = Optima_Team_Schema::add_person( $graph, $r['who'] );
		}
		return $graph;
	}

	/** The visible line, at the theme's before-close slot. */
	public static function line(): void {
		if ( ! is_singular() ) {
			return;
		}
		$r = self::printable( get_queried_object_id() );
		if ( ! $r['who'] ) {
			return;
		}
		$p    = Optima_Team::profile( $r['who'] );
		$name = get_the_title( $r['who'] );
		$date = '' !== $r['when'] ? date_i18n( get_option( 'date_format' ), strtotime( $r['when'] . ' 12:00:00' ) ) : '';
		echo '<p class="optima-reviewed">';
		/* translators: %s: reviewer's name */
		echo esc_html__( 'Reviewed by', 'optima-team' ) . ' <a href="' . esc_url( Optima_Team::public_url( $r['who'] ) ) . '">' . esc_html( $name ) . '</a>';
		if ( '' !== $p['role'] ) {
			echo ', ' . esc_html( $p['role'] );
		}
		if ( '' !== $date ) {
			/* translators: %s: date */
			echo '. ' . esc_html( sprintf( __( 'Last reviewed %s.', 'optima-team' ), $date ) );
		} else {
			echo '.';
		}
		echo '</p>';
	}

	/** ?optima_reviewed_by=<id> on a post list shows that person's pages. */
	public static function filter_list( WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, read only.
		$who = isset( $_GET['optima_reviewed_by'] ) ? absint( wp_unslash( $_GET['optima_reviewed_by'] ) ) : 0;
		if ( $who ) {
			$q->set( 'meta_key', self::META_WHO );
			$q->set( 'meta_value', (string) $who );
		}
	}
}
