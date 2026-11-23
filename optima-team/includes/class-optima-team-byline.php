<?php
/**
 * Byline (release 2, more than one person since this release): a post names
 * who wrote it from People, with or without a WordPress login (plugin-first
 * rule 47: a profile is a record, not an account). Guest writers, people with
 * no login and posts written together can all be credited.
 *
 * - Stored on the post as `_optima_byline`, one meta row per person, in
 *   order. A post saved before this release has one row and reads the same.
 * - The box works in the classic editor and, as a meta box, in the block
 *   editor. It lists live people; people whose byline is off can be picked
 *   but carry a note, and are left off the byline until it is on.
 * - On the site the author name becomes the people's names ("Ana Ruiz, Ben
 *   Cole & Dee Fox"), the author link goes to the first person's one bio
 *   address, the_author_posts_link() links each name, and the post's Article
 *   schema names each Person (Optima_Team_Schema).
 *
 * A post with nobody picked keeps the old behavior: the WordPress author, and
 * the profile linked to that login when there is one.
 *
 * @package Optima_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Team_Byline {

	const META  = '_optima_byline';
	const NONCE = 'optima_team_byline';

	/** Fields the box sends. */
	const FIELD     = 'optima_byline';
	const FIELD_ADD = 'optima_byline_add';

	/** Post types that carry a byline: posts, plus any a site adds with the filter. */
	public static function types(): array {
		return (array) apply_filters( 'optima_team_byline_types', array( 'post' ) );
	}

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'box' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'the_author', array( __CLASS__, 'author_name' ), 20 );
		add_filter( 'get_the_author_display_name', array( __CLASS__, 'display_name' ), 20, 2 );
		add_filter( 'author_link', array( __CLASS__, 'author_link' ), 20, 2 );
		add_filter( 'the_author_posts_link', array( __CLASS__, 'posts_link' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list' ) );
	}

	public static function register_meta(): void {
		foreach ( self::types() as $type ) {
			register_post_meta(
				$type,
				self::META,
				array(
					'type'              => 'integer',
					'single'            => false, // One row per person, in order.
					'show_in_rest'      => false,
					'sanitize_callback' => 'absint',
					'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id ),
				)
			);
		}
	}

	/* ---------- the stored value ---------- */

	/**
	 * The people picked for a post, as saved, in order. Any status.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function chosen( int $post_id ): array {
		if ( ! $post_id ) {
			return array();
		}
		return Optima_Team::clean_ids( (array) get_post_meta( $post_id, self::META, false ) );
	}

	/**
	 * The people a post's byline credits now: picked, live, with Byline on.
	 * Empty means the post's byline works as it always did. An offboarded
	 * person names nobody.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function people( int $post_id ): array {
		if ( ! $post_id || ! in_array( get_post_type( $post_id ), self::types(), true ) ) {
			return array();
		}
		return array_values( array_filter( self::chosen( $post_id ), array( 'Optima_Team', 'byline_ready' ) ) );
	}

	/**
	 * The first person a post's byline credits, or 0. Kept for code that reads
	 * one byline (release 2).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function get( int $post_id ): int {
		$people = self::people( $post_id );
		return $people ? (int) $people[0] : 0;
	}

	/**
	 * Store a post's people, in order. An empty list removes the value, so the
	 * post works as it always did.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $ids     Profile IDs.
	 */
	public static function store( int $post_id, array $ids ): void {
		$ids = array_values(
			array_filter(
				Optima_Team::clean_ids( $ids ),
				static fn( $id ) => Optima_Team::POST_TYPE === get_post_type( $id )
			)
		);
		delete_post_meta( $post_id, self::META );
		foreach ( $ids as $id ) {
			add_post_meta( $post_id, self::META, (int) $id );
		}
	}

	/**
	 * What the box sent: the ticked people in order, then the one picked to add.
	 *
	 * @param array $ticked Ticked profile IDs.
	 * @param mixed $add    The profile picked to add, or ''.
	 */
	public static function picked( array $ticked, $add ): array {
		$ticked[] = is_scalar( $add ) ? $add : 0;
		return Optima_Team::clean_ids( $ticked );
	}

	/** Posts whose byline names a person (alone or with others), any status. */
	public static function posts_for( int $person_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::types(),
					'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => (string) $person_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}

	/**
	 * Of a person's byline posts, the ones written with someone else (another
	 * person is picked too). Offboarding keeps these and takes the name off.
	 *
	 * @param int $person_id Person post ID.
	 */
	public static function shared_posts_for( int $person_id ): array {
		$out = array();
		foreach ( self::posts_for( $person_id ) as $id ) {
			if ( array_diff( self::chosen( $id ), array( $person_id ) ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/** Offboarding: take a person off every byline that also names someone else. */
	public static function release( int $person_id ): int {
		$n = 0;
		foreach ( self::shared_posts_for( $person_id ) as $id ) {
			self::store( $id, array_values( array_diff( self::chosen( $id ), array( $person_id ) ) ) );
			++$n;
		}
		return $n;
	}

	/* ---------- the box ---------- */

	public static function box( string $post_type, $post ): void {
		if ( ! in_array( $post_type, self::types(), true ) ) {
			return;
		}
		add_meta_box( 'optima-team-byline', __( 'Byline', 'optima-team' ), array( __CLASS__, 'render_box' ), $post_type, 'side', 'default' );
	}

	public static function assets( $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( (string) $screen->post_type, self::types(), true ) ) {
			return;
		}
		wp_enqueue_script( 'optima-team-byline-box', OPTIMA_TEAM_URL . 'assets/optima-team-byline-box.js', array(), OPTIMA_TEAM_VERSION, true );
	}

	/** Live people the picker offers, by name: [ id, name, byline on ]. */
	public static function choices(): array {
		$ids = get_posts(
			array(
				'post_type'      => Optima_Team::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
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
			$id    = (int) $id;
			$out[] = array(
				'id'     => $id,
				'name'   => Optima_Team::name( $id ),
				'byline' => Optima_Team::shows( Optima_Team::profile( $id ), 'byline' ),
			);
		}
		return $out;
	}

	/**
	 * The box: the picked people in order, each with Remove (and Move up and
	 * Move down with the script), and a list to add one more. Without the
	 * script, untick a person to remove them and pick one to add on save.
	 */
	public static function render_box( WP_Post $post ): void {
		$picked  = self::chosen( (int) $post->ID );
		$choices = self::choices();
		$by_id   = array();
		foreach ( $choices as $c ) {
			$by_id[ $c['id'] ] = $c;
		}
		$off_note = __( 'Their byline is off in People, so this post leaves them out until it is on.', 'optima-team' );
		wp_nonce_field( self::NONCE, '_optima_team_byline_nonce' );
		echo '<div class="optima-team-byline" data-otb data-otb-offnote="' . esc_attr( $off_note ) . '">';
		echo '<p class="description">' . esc_html__( 'Who wrote this, from People. Their names link to their bios and the post\'s schema names them as the authors. No WordPress login is needed. Leave it empty to use the post\'s author.', 'optima-team' ) . '</p>';
		if ( ! Optima_Team::settings()['bylines'] ) {
			echo '<p class="description"><strong>' . esc_html__( 'Bylines are off in People settings, so author links stay as WordPress makes them.', 'optima-team' ) . '</strong></p>';
		}
		echo '<ul class="otb-list" data-otb-list>';
		foreach ( $picked as $id ) {
			self::row( $id, $by_id[ $id ] ?? null, $off_note );
		}
		echo '</ul>';
		echo '<template data-otb-template>';
		self::row( 0, null, $off_note );
		echo '</template>';
		if ( $choices ) {
			echo '<p class="otb-add"><label for="otb-add">' . esc_html__( 'Add a person', 'optima-team' ) . '</label><br>';
			echo '<select id="otb-add" name="' . esc_attr( self::FIELD_ADD ) . '" data-otb-select style="width:100%">';
			echo '<option value="">' . esc_html__( 'Choose a person', 'optima-team' ) . '</option>';
			foreach ( $choices as $c ) {
				$label = $c['byline'] ? $c['name'] : $c['name'] . ' (' . __( 'Byline off', 'optima-team' ) . ')';
				echo '<option value="' . esc_attr( (string) $c['id'] ) . '" data-name="' . esc_attr( $c['name'] ) . '" data-off="' . ( $c['byline'] ? '0' : '1' ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select> <button type="button" class="button" hidden data-otb-add>' . esc_html__( 'Add', 'optima-team' ) . '</button></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'No people yet. Add them in People.', 'optima-team' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * One picked person in the box.
	 *
	 * @param int        $id       Person post ID, or 0 for the template.
	 * @param array|null $choice   The person's choice record, or null when they are not live.
	 * @param string     $off_note The note for a person whose byline is off.
	 */
	private static function row( int $id, ?array $choice, string $off_note ): void {
		$name = $choice ? $choice['name'] : ( $id ? Optima_Team::name( $id ) : '' );
		$note = '';
		if ( $id && ! $choice ) {
			$note = __( 'No longer live in People. Pick someone else.', 'optima-team' );
		} elseif ( $choice && ! $choice['byline'] ) {
			$note = $off_note;
		}
		echo '<li class="otb-row" data-otb-row>';
		echo '<label><input type="checkbox" name="' . esc_attr( self::FIELD ) . '[]" value="' . esc_attr( (string) $id ) . '" checked data-otb-id> <span data-otb-name>' . esc_html( $name ) . '</span></label>';
		echo '<span class="otb-tools" hidden data-otb-tools> ';
		echo '<button type="button" class="button-link" data-otb-up>' . esc_html__( 'Move up', 'optima-team' ) . '</button> ';
		echo '<button type="button" class="button-link" data-otb-down>' . esc_html__( 'Move down', 'optima-team' ) . '</button> ';
		echo '<button type="button" class="button-link button-link-delete" data-otb-remove>' . esc_html__( 'Remove', 'optima-team' ) . '</button>';
		echo '</span>';
		echo '<span class="description otb-note" style="display:block" data-otb-note' . ( '' === $note ? ' hidden' : '' ) . '>' . esc_html( $note ) . '</span>';
		echo '</li>';
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::types(), true ) || ! isset( $_POST['_optima_team_byline_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_optima_team_byline_nonce'] ) ), self::NONCE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to whole numbers in picked().
		$ticked = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array();
		// A form from before this release sends one value, not a list.
		$ticked = is_array( $ticked ) ? $ticked : array( $ticked );
		$add    = isset( $_POST[ self::FIELD_ADD ] ) ? wp_unslash( $_POST[ self::FIELD_ADD ] ) : '';
		// phpcs:enable
		self::store( $post_id, self::picked( $ticked, $add ) );
	}

	/* ---------- on the site ---------- */

	/**
	 * The people picked on the post being printed, when the theme is asking
	 * about that post's author. Empty in every other case, so WordPress and the
	 * linked login work as they always did.
	 *
	 * @param int $user_id The author the theme asked about, or -1 for "the current author".
	 */
	private static function here( int $user_id ): array {
		if ( is_admin() ) {
			return array();
		}
		$post = get_post();
		if ( ! $post || ! in_array( $post->post_type, self::types(), true ) ) {
			return array();
		}
		if ( $user_id >= 0 && $user_id !== (int) $post->post_author ) {
			return array();
		}
		// An author archive's own title is about that login, not the first post on it.
		if ( is_author() && ! in_the_loop() ) {
			return array();
		}
		return self::people( (int) $post->ID );
	}

	/** The login WordPress is printing the author of, or -1 when it has none. */
	private static function current_author(): int {
		global $authordata;
		return is_object( $authordata ) && isset( $authordata->ID ) ? (int) $authordata->ID : -1;
	}

	/**
	 * Names for a byline, joined: "Dana Lee", "Dana Lee & Sam Park".
	 *
	 * @param int[] $members Person post IDs.
	 */
	public static function names( array $members ): string {
		return Optima_Team::join_names( array_map( array( 'Optima_Team', 'name' ), $members ) );
	}

	/**
	 * A byline with each name linked to the person's one bio. A person whose
	 * profile page is off shows as a plain name.
	 *
	 * @param int[] $members Person post IDs.
	 */
	public static function html( array $members ): string {
		$parts = array();
		foreach ( $members as $member ) {
			$name    = esc_html( Optima_Team::name( (int) $member ) );
			$url     = Optima_Team::profile_url( (int) $member );
			$parts[] = '' !== $url ? '<a href="' . esc_url( $url ) . '" rel="author">' . $name . '</a>' : $name;
		}
		if ( count( $parts ) < 2 ) {
			return (string) ( $parts[0] ?? '' );
		}
		$last = array_pop( $parts );
		return implode( ', ', $parts ) . ' &amp; ' . $last;
	}

	/** The author name the theme prints: the byline's people. */
	public static function author_name( $name ) {
		$people = self::here( self::current_author() );
		return $people ? self::names( $people ) : $name;
	}

	/** get_the_author_meta( 'display_name' ) for the post's author: the byline's people. */
	public static function display_name( $name, $user_id = 0 ) {
		$people = self::here( (int) $user_id );
		return $people ? self::names( $people ) : $name;
	}

	/** The author link the theme prints: the first person's one bio address (none when their page is off). */
	public static function author_link( $link, $author_id ) {
		$people = self::here( (int) $author_id );
		return $people ? Optima_Team::profile_url( (int) $people[0] ) : $link;
	}

	/** the_author_posts_link: every name, each linked to its own bio. */
	public static function posts_link( $link ) {
		$people = self::here( self::current_author() );
		return $people ? self::html( $people ) : $link;
	}

	/** ?optima_byline=<id> on a post list shows that person's posts. */
	public static function filter_list( WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, read only.
		$who = isset( $_GET['optima_byline'] ) ? absint( wp_unslash( $_GET['optima_byline'] ) ) : 0;
		if ( $who ) {
			$q->set( 'meta_key', self::META );
			$q->set( 'meta_value', (string) $who );
		}
	}
}
