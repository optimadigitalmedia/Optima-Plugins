<?php
/**
 * Byline (release 2): a post names its author from People without a
 * WordPress login (plugin-first rule 47: a profile is a record, not an
 * account). The post's Article schema then names that Person, with the
 * person's one bio address, and the author name and link the theme prints
 * point at the profile.
 *
 * A post without a byline keeps the old behavior: the WordPress author, and
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

	/** Post types that carry a byline: posts, plus any a site adds with the filter. */
	public static function types(): array {
		return (array) apply_filters( 'optima_team_byline_types', array( 'post' ) );
	}

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'box' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'the_author', array( __CLASS__, 'author_name' ) );
		add_filter( 'author_link', array( __CLASS__, 'author_link' ), 20, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list' ) );
	}

	public static function register_meta(): void {
		foreach ( self::types() as $type ) {
			register_post_meta(
				$type,
				self::META,
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'absint',
					'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
				)
			);
		}
	}

	/**
	 * The profile a post's byline names, or 0. Nobody when the profile is gone
	 * or not published (an offboarded person names nobody).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function get( int $post_id ): int {
		if ( ! $post_id || ! in_array( get_post_type( $post_id ), self::types(), true ) ) {
			return 0;
		}
		$who = (int) get_post_meta( $post_id, self::META, true );
		if ( $who && Optima_Team::POST_TYPE === get_post_type( $who ) && 'publish' === get_post_status( $who ) ) {
			return $who;
		}
		return 0;
	}

	/** Posts whose byline names a person, any status. */
	public static function posts_for( int $person_id ): array {
		return get_posts(
			array(
				'post_type'      => self::types(),
				'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (string) $person_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	/* ---------- the box ---------- */

	public static function box( string $post_type, $post ): void {
		if ( ! in_array( $post_type, self::types(), true ) ) {
			return;
		}
		add_meta_box( 'optima-team-byline', __( 'Byline', 'optima-team' ), array( __CLASS__, 'render_box' ), $post_type, 'side', 'default' );
	}

	public static function render_box( WP_Post $post ): void {
		$who    = (int) get_post_meta( $post->ID, self::META, true );
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
		wp_nonce_field( self::NONCE, '_optima_team_byline_nonce' );
		?>
		<p>
			<label for="st-byline" class="screen-reader-text"><?php esc_html_e( 'Byline', 'optima-team' ); ?></label>
			<select id="st-byline" name="optima_byline" style="width:100%">
				<option value="0"><?php esc_html_e( 'The WordPress author', 'optima-team' ); ?></option>
				<?php foreach ( $people as $person ) : ?>
					<option value="<?php echo esc_attr( (string) $person->ID ); ?>" <?php selected( $who, (int) $person->ID ); ?>><?php echo esc_html( $person->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php if ( $who && ! self::get( $post->ID ) ) : ?>
			<p class="description"><strong><?php esc_html_e( 'The person picked here is no longer on the site. Pick someone else.', 'optima-team' ); ?></strong></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'The person from People who wrote this post. Their name links to their bio and the post\'s schema names them as the author. No WordPress login is needed.', 'optima-team' ); ?></p>
		<?php
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::types(), true ) || ! isset( $_POST['_optima_team_byline_nonce'], $_POST['optima_byline'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_optima_team_byline_nonce'] ) ), self::NONCE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$who = absint( wp_unslash( $_POST['optima_byline'] ) );
		if ( $who && Optima_Team::POST_TYPE !== get_post_type( $who ) ) {
			$who = 0;
		}
		if ( $who ) {
			update_post_meta( $post_id, self::META, $who );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/* ---------- on the site ---------- */

	/** The post being shown, while the theme prints its byline. */
	private static function current(): int {
		if ( is_admin() ) {
			return 0;
		}
		$post = get_post();
		return $post ? self::get( (int) $post->ID ) : 0;
	}

	/** The author name the theme prints: the byline's person. */
	public static function author_name( $name ) {
		$who = self::current();
		return $who ? wp_strip_all_tags( get_the_title( $who ) ) : $name;
	}

	/** The author link the theme prints: the byline person's one bio address. */
	public static function author_link( $link, $author_id ) {
		$post = is_admin() ? null : get_post();
		if ( ! $post || (int) $post->post_author !== (int) $author_id ) {
			return $link;
		}
		$who = self::get( (int) $post->ID );
		return $who ? Optima_Team::public_url( $who ) : $link;
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
