<?php
/**
 * The review post type, its fields, and the editor screen.
 *
 * Each review is one `optima_review` post:
 * - Title:   the reviewer's name, as it appears on the platform.
 * - Content: the review text, word for word.
 * - Status:  Published shows on the site; Draft keeps it hidden.
 * - Order:   lower numbers show first (Page Attributes > Order).
 *
 * @package Optima_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Reviews_Post_Type {

	const TYPE = 'optima_review';

	const META_SOURCE   = '_optima_review_source';
	const META_RATING   = '_optima_review_rating';
	const META_URL      = '_optima_review_url';
	const META_DATE     = '_optima_review_date';
	const META_DETAIL   = '_optima_review_detail';
	const META_FEATURED = '_optima_review_featured';

	const NONCE = 'optima_review_nonce';

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Reviews', 'optima-reviews' ),
					'singular_name'      => __( 'Review', 'optima-reviews' ),
					'add_new'            => __( 'Add review', 'optima-reviews' ),
					'add_new_item'       => __( 'Add a review', 'optima-reviews' ),
					'edit_item'          => __( 'Edit review', 'optima-reviews' ),
					'all_items'          => __( 'All reviews', 'optima-reviews' ),
					'search_items'       => __( 'Search reviews', 'optima-reviews' ),
					'not_found'          => __( 'No reviews yet.', 'optima-reviews' ),
					'not_found_in_trash' => __( 'No reviews in the trash.', 'optima-reviews' ),
				),
				// Reviews live inside the blocks and shortcodes, never on their own URL.
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false, // Plain classic screen: a review is short text.
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-star-filled',
				'supports'            => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
			)
		);

		$fields = array(
			self::META_SOURCE   => 'string',
			self::META_RATING   => 'integer',
			self::META_URL      => 'string',
			self::META_DATE     => 'string',
			self::META_DETAIL   => 'string',
			self::META_FEATURED => 'boolean',
		);
		foreach ( $fields as $key => $type ) {
			register_post_meta(
				self::TYPE,
				$key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	public static function admin_hooks(): void {
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_order' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'editor_hint' ) );
	}

	public static function title_placeholder( string $text, WP_Post $post ): string {
		return self::TYPE === $post->post_type ? __( 'Reviewer name, as shown on the platform', 'optima-reviews' ) : $text;
	}

	public static function editor_hint( WP_Post $post ): void {
		if ( self::TYPE !== $post->post_type ) {
			return;
		}
		echo '<p class="description" style="margin:12px 0 4px;">'
			. esc_html__( 'Paste the review text below exactly as the reviewer wrote it. Published reviews show on the site; save as a draft to keep one hidden.', 'optima-reviews' )
			. '</p>';
	}

	public static function add_box(): void {
		add_meta_box( 'optima-review-details', __( 'Review details', 'optima-reviews' ), array( __CLASS__, 'box' ), self::TYPE, 'normal', 'high' );
	}

	public static function box( WP_Post $post ): void {
		$source   = (string) get_post_meta( $post->ID, self::META_SOURCE, true );
		$rating   = (int) get_post_meta( $post->ID, self::META_RATING, true );
		$url      = (string) get_post_meta( $post->ID, self::META_URL, true );
		$date     = (string) get_post_meta( $post->ID, self::META_DATE, true );
		$detail   = (string) get_post_meta( $post->ID, self::META_DETAIL, true );
		$featured = (bool) get_post_meta( $post->ID, self::META_FEATURED, true );

		if ( '' === $source ) {
			$source = 'google';
		}

		// Synced reviews show Google's values without letting them be changed.
		$locked = class_exists( 'Optima_Reviews_Google_Sync' ) && Optima_Reviews_Google_Sync::is_synced( $post->ID );

		wp_nonce_field( 'optima_review_save', self::NONCE );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="optima-review-source"><?php esc_html_e( 'Where it was posted', 'optima-reviews' ); ?></label></th>
				<td>
					<select id="optima-review-source" name="optima_review[source]" <?php disabled( $locked ); ?>>
						<?php foreach ( Optima_Reviews_Sources::all() as $slug => $info ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $source, $slug ); ?>><?php echo esc_html( $info['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Pick "Client" for a testimonial sent to you directly by email or letter.', 'optima-reviews' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="optima-review-rating"><?php esc_html_e( 'Star rating', 'optima-reviews' ); ?></label></th>
				<td>
					<select id="optima-review-rating" name="optima_review[rating]" <?php disabled( $locked ); ?>>
						<option value="0" <?php selected( $rating, 0 ); ?>><?php esc_html_e( 'No star rating', 'optima-reviews' ); ?></option>
						<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
							<option value="<?php echo (int) $i; ?>" <?php selected( $rating, $i ); ?>>
								<?php
								/* translators: %d: number of stars. */
								echo esc_html( sprintf( _n( '%d star', '%d stars', $i, 'optima-reviews' ), $i ) );
								?>
							</option>
						<?php endfor; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Use the rating the reviewer actually gave. Facebook uses recommendations instead of stars.', 'optima-reviews' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="optima-review-url"><?php esc_html_e( 'Link to the review', 'optima-reviews' ); ?></label></th>
				<td>
					<input type="url" id="optima-review-url" class="large-text" name="optima_review[url]" value="<?php echo esc_attr( $url ); ?>" placeholder="https://" <?php disabled( $locked ); ?> />
					<p class="description"><?php esc_html_e( 'The page where anyone can read the original. It becomes the "View on Google" link on the card. Leave empty for a direct client testimonial.', 'optima-reviews' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="optima-review-date"><?php esc_html_e( 'Date posted', 'optima-reviews' ); ?></label></th>
				<td><input type="date" id="optima-review-date" name="optima_review[date]" value="<?php echo esc_attr( $date ); ?>" <?php disabled( $locked ); ?> /></td>
			</tr>
			<tr>
				<th scope="row"><label for="optima-review-detail"><?php esc_html_e( 'Reviewer detail (optional)', 'optima-reviews' ); ?></label></th>
				<td>
					<input type="text" id="optima-review-detail" class="regular-text" name="optima_review[detail]" value="<?php echo esc_attr( $detail ); ?>" />
					<p class="description"><?php esc_html_e( 'A short line under the name, such as "Owner, Smith Law." Only add what the reviewer is comfortable sharing.', 'optima-reviews' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Featured', 'optima-reviews' ); ?></th>
				<td>
					<label><input type="checkbox" name="optima_review[featured]" value="1" <?php checked( $featured ); ?> /> <?php esc_html_e( 'Show this review in "featured only" sections, like the home page.', 'optima-reviews' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), 'optima_review_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Synced reviews: Google owns source, stars, link, and date.
		$synced = class_exists( 'Optima_Reviews_Google_Sync' ) && Optima_Reviews_Google_Sync::is_synced( $post_id );

		$in = isset( $_POST['optima_review'] ) && is_array( $_POST['optima_review'] ) ? wp_unslash( $_POST['optima_review'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field sanitized below.

		$source = sanitize_key( $in['source'] ?? '' );
		if ( ! Optima_Reviews_Sources::exists( $source ) ) {
			$source = 'direct';
		}

		$rating = max( 0, min( 5, (int) ( $in['rating'] ?? 0 ) ) );

		$url = esc_url_raw( trim( (string) ( $in['url'] ?? '' ) ), array( 'https', 'http' ) );

		$date = (string) ( $in['date'] ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = '';
		}

		if ( ! $synced ) {
			update_post_meta( $post_id, self::META_SOURCE, $source );
			update_post_meta( $post_id, self::META_RATING, $rating );
			update_post_meta( $post_id, self::META_URL, $url );
			update_post_meta( $post_id, self::META_DATE, $date );
		}
		update_post_meta( $post_id, self::META_DETAIL, sanitize_text_field( (string) ( $in['detail'] ?? '' ) ) );
		update_post_meta( $post_id, self::META_FEATURED, ! empty( $in['featured'] ) );

		Optima_Reviews_Query::flush();
	}

	public static function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$out[ $key ] = __( 'Reviewer', 'optima-reviews' );
				$out['optima_source']   = __( 'Source', 'optima-reviews' );
				$out['optima_rating']   = __( 'Rating', 'optima-reviews' );
				$out['optima_featured'] = __( 'Featured', 'optima-reviews' );
				$out['optima_posted']   = __( 'Posted', 'optima-reviews' );
				continue;
			}
			if ( 'date' === $key ) {
				continue; // "Posted" (when the reviewer wrote it) replaces the WP date.
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	public static function column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'optima_source':
				echo esc_html( Optima_Reviews_Sources::label( (string) get_post_meta( $post_id, self::META_SOURCE, true ) ) );
				break;
			case 'optima_rating':
				$rating = (int) get_post_meta( $post_id, self::META_RATING, true );
				echo $rating ? esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ) : '&ndash;';
				break;
			case 'optima_featured':
				echo get_post_meta( $post_id, self::META_FEATURED, true ) ? esc_html__( 'Yes', 'optima-reviews' ) : '&ndash;';
				break;
			case 'optima_posted':
				$date = (string) get_post_meta( $post_id, self::META_DATE, true );
				echo $date ? esc_html( mysql2date( get_option( 'date_format' ), $date ) ) : '&ndash;';
				break;
		}
	}

	/**
	 * List reviews in display order on the admin screen, so what you see there
	 * is the order the site uses.
	 */
	public static function admin_order( WP_Query $query ): void {
		if ( ! $query->is_main_query() || self::TYPE !== $query->get( 'post_type' ) || $query->get( 'orderby' ) ) {
			return;
		}
		$query->set( 'orderby', array( 'menu_order' => 'ASC', 'date' => 'DESC' ) );
	}
}
