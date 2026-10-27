<?php
/**
 * Reviews > Inbox: new reviews pulled from Google and notes sent from the
 * review page (/review/note/), one card each, with "Add to site" and "Skip".
 * A note the client did not OK for the site can only be marked as read.
 *
 * "Add to site" publishes the review with the checked topics (suggested ones
 * are pre-checked), so it lands on the right pages in one click. "Skip"
 * keeps it as a hidden draft, out of the inbox.
 *
 * @package Optima_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Reviews_Inbox {

	const PAGE         = 'optima-reviews-inbox';
	const META_SKIPPED = '_optima_review_skipped';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), Optima_Reviews_Menu::PRIORITY + 1 );
		add_action( 'admin_post_optima_reviews_inbox', array( __CLASS__, 'handle' ) );
	}

	public static function url(): string {
		return Optima_Reviews_Menu::url( self::PAGE );
	}

	public static function menu(): void {
		$count = Optima_Reviews_Google_Sync::new_count();
		$build = Optima_Reviews_Menu::build();
		$label = '' !== $build ? __( 'Review inbox', 'optima-reviews' ) : __( 'Inbox', 'optima-reviews' );
		if ( $count ) {
			$label .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $count . '</span></span>';
		}
		add_submenu_page(
			Optima_Reviews_Menu::parent(),
			__( 'Review inbox', 'optima-reviews' ),
			$label,
			'edit_posts',
			self::PAGE,
			array( __CLASS__, 'page' ),
			'' !== $build ? null : 1 // First under its own Reviews menu; in order inside Build.
		);
	}

	/**
	 * @return WP_Post[]
	 */
	public static function items(): array {
		return get_posts(
			array(
				'post_type'      => Optima_Reviews_Post_Type::TYPE,
				'post_status'    => 'draft',
				'posts_per_page' => 100,
				'meta_key'       => Optima_Reviews_Google_Sync::META_NEW, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$items  = self::items();
		$topics = Optima_Reviews_Topics::all();
		?>
		<div class="wrap srk recon-theme" data-theme="light">
			<?php Optima_Reviews_Requests::header( __( 'Inbox', 'optima-reviews' ), __( 'New reviews and notes', 'optima-reviews' ) ); ?>
			<p class="srk-lede">
				<?php esc_html_e( 'New Google reviews and notes from your review page wait here. Check the pages each one belongs on (we pre-check the ones that match), then click Add to site. Skipped items stay saved as hidden drafts under All reviews.', 'optima-reviews' ); ?>
			</p>

			<?php if ( ! $items ) : ?>
				<section class="srk-box" style="max-width:720px;">
					<p class="srk-label"><?php esc_html_e( 'All caught up', 'optima-reviews' ); ?></p>
					<p class="srk-note" style="font-size:14px;">
						<?php
						if ( Optima_Reviews_Google::is_ready() ) {
							esc_html_e( 'New Google reviews show up here after the next daily sync. Notes from your review page arrive the moment someone sends one.', 'optima-reviews' );
						} else {
							esc_html_e( 'Notes from your review page arrive here the moment someone sends one. Google reviews join them once you connect Google in Settings. Yelp and Facebook reviews can be added by hand under Add review.', 'optima-reviews' );
						}
						?>
					</p>
				</section>
			<?php else : ?>

				<?php if ( count( $items ) > 1 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0 0;">
						<input type="hidden" name="action" value="optima_reviews_inbox" />
						<input type="hidden" name="do" value="add_all" />
						<?php wp_nonce_field( 'optima_reviews_inbox' ); ?>
						<button type="submit" class="button"><?php
							esc_html_e( 'Add every item that may be shown, with its suggested topics', 'optima-reviews' );
						?></button>
					</form>
				<?php endif; ?>

				<?php if ( ! $topics ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'No topics exist yet, so reviews will be added without one. They still show anywhere that displays all reviews.', 'optima-reviews' ); ?></p></div>
				<?php endif; ?>

				<div class="srk-inbox">
					<?php foreach ( $items as $post ) : ?>
						<?php self::card( $post, $topics ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param WP_Term[] $topics
	 */
	private static function card( WP_Post $post, array $topics ): void {
		$rating    = (int) get_post_meta( $post->ID, Optima_Reviews_Post_Type::META_RATING, true );
		$date      = Optima_Reviews_Render::date( (string) get_post_meta( $post->ID, Optima_Reviews_Post_Type::META_DATE, true ) );
		$detail    = (string) get_post_meta( $post->ID, Optima_Reviews_Post_Type::META_DETAIL, true );
		$suggested = Optima_Reviews_Topics::suggest( $post->post_content );
		$website   = Optima_Reviews_Notes::from_website( $post->ID );
		$allowed   = Optima_Reviews_Notes::can_publish( $post->ID );
		$source    = Optima_Reviews_Sources::label( (string) get_post_meta( $post->ID, Optima_Reviews_Post_Type::META_SOURCE, true ) );
		?>
		<form class="srk-box srk-item is-new" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="optima_reviews_inbox" />
			<input type="hidden" name="review" value="<?php echo (int) $post->ID; ?>" />
			<input type="hidden" name="topics_sent" value="1" />
			<?php wp_nonce_field( 'optima_reviews_inbox' ); ?>

			<p class="srk-itemhead">
				<span class="srk-pill"><?php echo esc_html( $website ? __( 'New · from the website', 'optima-reviews' ) : sprintf( /* translators: %s: platform */ __( 'New · from %s', 'optima-reviews' ), $source ? $source : 'Google' ) ); ?></span>
				<?php if ( $rating ) : ?>
					<span class="srk-stars" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: stars */ __( '%d out of 5 stars', 'optima-reviews' ), $rating ) ); ?>"><?php echo esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ); ?></span>
				<?php endif; ?>
				<?php if ( $date ) : ?>
					<span class="srk-note" style="margin:0;"><?php echo esc_html( $date ); ?></span>
				<?php endif; ?>
			</p>
			<blockquote class="srk-quote"><?php echo wpautop( esc_html( $post->post_content ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped before wpautop. ?></blockquote>
			<p class="srk-itemhead">
				<strong><?php echo esc_html( get_the_title( $post ) . ( '' !== $detail ? ', ' . $detail : '' ) ); ?></strong>
				<?php if ( $website ) : ?>
					<?php if ( $allowed ) : ?>
						<span class="srk-pill is-ok"><?php esc_html_e( 'OK to show on the site', 'optima-reviews' ); ?></span>
					<?php else : ?>
						<span class="srk-pill is-private"><?php esc_html_e( 'Private: not OK to show', 'optima-reviews' ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</p>

			<?php if ( $allowed && $topics ) : ?>
				<fieldset class="srk-topics">
					<legend class="screen-reader-text"><?php esc_html_e( 'Show on', 'optima-reviews' ); ?></legend>
					<span class="srk-label" style="display:inline;margin-right:8px;"><?php esc_html_e( 'Show on', 'optima-reviews' ); ?></span>
					<?php foreach ( $topics as $term ) : ?>
						<?php $is = in_array( $term->term_id, $suggested, true ); ?>
						<label>
							<input type="checkbox" name="topics[]" value="<?php echo (int) $term->term_id; ?>" <?php checked( $is ); ?> />
							<?php echo esc_html( $term->name ); ?><?php echo $is ? ' <span class="srk-note">' . esc_html__( '(suggested)', 'optima-reviews' ) . '</span>' : ''; ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>

			<p class="srk-btnrow">
				<?php if ( $allowed ) : ?>
					<label style="margin-right:8px;"><input type="checkbox" name="featured" value="1" /> <?php esc_html_e( 'Featured', 'optima-reviews' ); ?></label>
					<button type="submit" name="do" value="add" class="button button-primary"><?php esc_html_e( 'Approve and add to site', 'optima-reviews' ); ?></button>
					<button type="submit" name="do" value="skip" class="button"><?php esc_html_e( 'Skip', 'optima-reviews' ); ?></button>
				<?php else : ?>
					<button type="submit" name="do" value="skip" class="button button-primary"><?php esc_html_e( 'Mark as read', 'optima-reviews' ); ?></button>
				<?php endif; ?>
				<?php if ( $website ) : ?>
					<a class="button" href="<?php echo esc_url( Optima_Reviews_Menu::url( Optima_Reviews_Requests::PAGE, 'srk-messages' ) ); ?>"><?php esc_html_e( 'Ask for a Google review too', 'optima-reviews' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	public static function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'optima-reviews' ), 403 );
		}
		check_admin_referer( 'optima_reviews_inbox' );

		$do = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );

		if ( 'add_all' === $do ) {
			$added = 0;
			foreach ( self::items() as $post ) {
				if ( current_user_can( 'publish_post', $post->ID ) && self::add( $post->ID, Optima_Reviews_Topics::suggest( $post->post_content ), false ) ) {
					++$added;
				}
			}
			/* translators: %d: number of reviews. */
			self::back( sprintf( _n( '%d review added to the site.', '%d reviews added to the site.', $added, 'optima-reviews' ), $added ) );
		}

		$id   = (int) ( $_POST['review'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post || Optima_Reviews_Post_Type::TYPE !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
			self::back( __( 'That review could not be found.', 'optima-reviews' ), 'error' );
		}

		if ( 'skip' === $do ) {
			delete_post_meta( $id, Optima_Reviews_Google_Sync::META_NEW );
			update_post_meta( $id, self::META_SKIPPED, 1 );
			/* translators: %s: reviewer name. */
			self::back( sprintf( __( 'Skipped the review from %s. It is saved as a hidden draft.', 'optima-reviews' ), get_the_title( $post ) ) );
		}

		if ( 'add' === $do ) {
			if ( ! current_user_can( 'publish_post', $id ) ) {
				self::back( __( 'You do not have permission to publish reviews.', 'optima-reviews' ), 'error' );
			}
			if ( ! Optima_Reviews_Notes::can_publish( $id ) ) {
				self::back( __( 'This note is private. The client did not say it was OK to show it on the site.', 'optima-reviews' ), 'error' );
			}
			$topics = array_map( 'intval', (array) ( $_POST['topics'] ?? array() ) );
			self::add( $id, $topics, ! empty( $_POST['featured'] ) );
			$names = array();
			foreach ( $topics as $tid ) {
				$term = get_term( $tid, Optima_Reviews_Topics::TAX );
				if ( $term instanceof WP_Term ) {
					$names[] = $term->name;
				}
			}
			$where = $names ? implode( ', ', $names ) : __( 'sections that show all reviews', 'optima-reviews' );
			/* translators: 1: reviewer name, 2: list of topics. */
			self::back( sprintf( __( 'Added the review from %1$s. It now shows on: %2$s.', 'optima-reviews' ), get_the_title( $post ), $where ) );
		}

		self::back();
	}

	/**
	 * Publish one review with these topics.
	 *
	 * @param int[] $topic_ids
	 */
	public static function add( int $post_id, array $topic_ids, bool $featured ): bool {
		$valid = array();
		foreach ( $topic_ids as $tid ) {
			if ( get_term( $tid, Optima_Reviews_Topics::TAX ) instanceof WP_Term ) {
				$valid[] = (int) $tid;
			}
		}
		wp_set_object_terms( $post_id, $valid, Optima_Reviews_Topics::TAX );
		if ( $featured ) {
			update_post_meta( $post_id, Optima_Reviews_Post_Type::META_FEATURED, true );
		}
		delete_post_meta( $post_id, self::META_SKIPPED );
		$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true );
		return ! is_wp_error( $result ) && $result;
	}

	private static function back( string $message = '', string $type = 'success' ): void {
		if ( '' !== $message ) {
			set_transient( Optima_Reviews_Google_Admin::NOTICE . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( self::url() );
		exit;
	}
}
