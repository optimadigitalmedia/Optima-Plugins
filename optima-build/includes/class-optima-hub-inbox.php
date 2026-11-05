<?php
/**
 * Optima -> Inbox: everything waiting on a person, on one screen.
 *
 * Read-only. It lists what the existing screens already keep: change requests
 * that are not done (Request a change), chat questions not yet answered (Chat
 * questions), new reviews and notes from the review page (Review inbox). Each
 * row links to the screen that handles it; nothing is stored here. The total
 * shows as one count on the Inbox link in the sidebar.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Inbox {

	const PAGE = 'optima-inbox';

	/** Rows shown per kind; the count always covers every item. */
	const SHOW = 5;

	/** @var array<string, array>|null Built once per request. */
	private static $kinds = null;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 16 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'optima_admin_nav_hint', array( __CLASS__, 'nav_hint' ), 10, 2 );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function menu(): void {
		$count  = current_user_can( Optima_Hub_Lock::VIEW ) ? self::count() : 0;
		$bubble = $count ? ' <span class="awaiting-mod"><span class="pending-count">' . (int) $count . '</span></span>' : '';
		add_submenu_page( Optima_Hub_Admin::parent(), __( 'Inbox', 'optima-build' ), __( 'Inbox', 'optima-build' ) . $bubble, Optima_Hub_Lock::VIEW, self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::PAGE ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_HUB_URL . 'assets/optima-admin.css' );
		}
	}

	public static function nav_hint( string $hint, string $slug ): string {
		if ( self::PAGE !== $slug ) {
			return $hint;
		}
		$count = self::count();
		/* translators: %d: items waiting */
		return $count ? sprintf( __( '%d waiting', 'optima-build' ), $count ) : '';
	}

	/** Everything waiting, all kinds together. */
	public static function count(): int {
		return (int) array_sum( array_column( self::kinds(), 'count' ) );
	}

	/**
	 * What is waiting, by kind, from the stores the features already keep.
	 * Each kind: label, who it waits on ("you" or "optima"), count, the screen
	 * that handles it, and up to SHOW rows (title, note, link).
	 *
	 * @return array<string, array{label:string, who:string, count:int, url:string, link:string, cap:string, rows:array}>
	 */
	public static function kinds(): array {
		if ( null !== self::$kinds ) {
			return self::$kinds;
		}
		$kinds = array();

		// Change requests the team has not marked Done.
		$open = get_posts(
			array(
				'post_type'      => Optima_Hub_Requests::TYPE,
				'post_status'    => 'private',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array(
						'key'     => Optima_Hub_Requests::META_STATUS,
						'value'   => 'done',
						'compare' => '!=',
					),
					array(
						'key'     => Optima_Hub_Requests::META_STATUS,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$rows = array();
		foreach ( array_slice( $open, 0, self::SHOW ) as $post ) {
			$status = (string) get_post_meta( $post->ID, Optima_Hub_Requests::META_STATUS, true );
			$status = isset( Optima_Hub_Requests::STATUSES[ $status ] ) ? $status : 'received';
			$rows[] = array(
				'title' => get_the_title( $post ),
				'note'  => get_the_date( 'M j, Y', $post ),
				'url'   => Optima_Hub_Admin::url( 'requests' ),
				'state' => $status,
				'pill'  => Optima_Hub_Requests::STATUSES[ $status ],
			);
		}
		$kinds['requests'] = array(
			'label' => __( 'Change requests', 'optima-build' ),
			'who'   => 'optima',
			'count' => count( $open ),
			'url'   => Optima_Hub_Admin::url( 'requests' ),
			'link'  => __( 'Open Request a change', 'optima-build' ),
			'cap'   => Optima_Hub_Lock::VIEW,
			'rows'  => $rows,
		);

		// Chat questions nobody has answered yet.
		if ( class_exists( 'Optima_Chat' ) && post_type_exists( Optima_Chat::CPT ) ) {
			$questions = get_posts(
				array(
					'post_type'      => Optima_Chat::CPT,
					'post_status'    => 'private',
					'posts_per_page' => -1,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'meta_key'       => '_optima_chat_answered', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			$rows = array();
			foreach ( array_slice( $questions, 0, self::SHOW ) as $post ) {
				$rows[] = array(
					'title' => wp_trim_words( get_the_title( $post ), 14, '…' ),
					'note'  => get_the_date( 'M j, Y', $post ),
					'url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
				);
			}
			$kinds['chat'] = array(
				'label' => __( 'Chat questions', 'optima-build' ),
				'who'   => 'you',
				'count' => count( $questions ),
				'url'   => admin_url( 'edit.php?post_type=' . Optima_Chat::CPT ),
				'link'  => __( 'Open Chat questions', 'optima-build' ),
				'cap'   => 'edit_posts',
				'rows'  => $rows,
			);
		}

		// New reviews and notes from the review page, waiting in the Review inbox.
		if ( class_exists( 'Optima_Reviews_Post_Type' ) && class_exists( 'Optima_Reviews_Google_Sync' ) && post_type_exists( Optima_Reviews_Post_Type::TYPE ) ) {
			$waiting = get_posts(
				array(
					'post_type'      => Optima_Reviews_Post_Type::TYPE,
					'post_status'    => 'draft',
					'posts_per_page' => -1,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'meta_key'       => Optima_Reviews_Google_Sync::META_NEW, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			$inbox = admin_url( 'admin.php?page=' . ( class_exists( 'Optima_Reviews_Inbox' ) ? Optima_Reviews_Inbox::PAGE : 'optima-reviews-inbox' ) );
			$split = array( 'reviews' => array(), 'notes' => array() );
			foreach ( $waiting as $post ) {
				$note = class_exists( 'Optima_Reviews_Notes' ) && Optima_Reviews_Notes::from_website( $post->ID );
				$split[ $note ? 'notes' : 'reviews' ][] = $post;
			}
			$labels = array(
				'reviews' => __( 'New reviews', 'optima-build' ),
				'notes'   => __( 'Review request notes', 'optima-build' ),
			);
			foreach ( $split as $key => $posts ) {
				$rows = array();
				foreach ( array_slice( $posts, 0, self::SHOW ) as $post ) {
					$rows[] = array(
						'title' => get_the_title( $post ),
						'note'  => get_the_date( 'M j, Y', $post ),
						'url'   => $inbox,
					);
				}
				$kinds[ $key ] = array(
					'label' => $labels[ $key ],
					'who'   => 'you',
					'count' => count( $posts ),
					'url'   => $inbox,
					'link'  => __( 'Open Review inbox', 'optima-build' ),
					'cap'   => 'edit_posts',
					'rows'  => $rows,
				);
			}
		}

		// Pages whose reviewer left the firm: they keep the date and wait for a new name (rule 48).
		if ( class_exists( 'Optima_Team_Reviewed' ) ) {
			$orphans = Optima_Team_Reviewed::orphaned();
			$rows    = array();
			foreach ( array_slice( $orphans, 0, self::SHOW ) as $id ) {
				$rows[] = array(
					'title' => get_the_title( (int) $id ),
					/* translators: %s: date */
					'note'  => sprintf( __( 'Last reviewed %s · needs a new reviewer', 'optima-build' ), (string) get_post_meta( (int) $id, Optima_Team_Reviewed::META_WHEN, true ) ),
					'url'   => (string) get_edit_post_link( (int) $id, 'raw' ),
				);
			}
			$kinds['reviewers'] = array(
				'label' => __( 'Pages waiting for a reviewer', 'optima-build' ),
				'who'   => 'optima',
				'count' => count( $orphans ),
				'url'   => admin_url( 'edit.php?post_type=page&optima_reviewed_by=0' ),
				'link'  => __( 'Open the pages', 'optima-build' ),
				'cap'   => 'edit_pages',
				'rows'  => $rows,
			);
		}

		self::$kinds = $kinds;
		return $kinds;
	}

	/**
	 * One kind as a box: label, count, its rows, and the screen that handles it.
	 * Someone who cannot open that screen (the site lock) sees the rows as text.
	 */
	public static function box( array $kind, bool $empty_note = true ): void {
		$open = current_user_can( $kind['cap'] );
		?>
		<section class="sh-box">
			<p class="sh-label"><?php echo esc_html( $kind['label'] . ' · ' . $kind['count'] ); ?></p>
			<?php if ( $kind['rows'] ) : ?>
				<ul class="sh-waiting">
					<?php foreach ( $kind['rows'] as $row ) : ?>
						<li>
							<div>
								<?php $title = '' !== $row['title'] ? $row['title'] : __( '(no title)', 'optima-build' ); ?>
								<?php if ( $open ) : ?>
									<a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $title ); ?></a>
								<?php else : ?>
									<strong><?php echo esc_html( $title ); ?></strong>
								<?php endif; ?>
								<p class="sh-note"><?php echo esc_html( $row['note'] ); ?></p>
							</div>
							<?php if ( ! empty( $row['pill'] ) ) : ?>
								<span class="sh-pill is-<?php echo esc_attr( $row['state'] ); ?>"><?php echo esc_html( $row['pill'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $kind['count'] > count( $kind['rows'] ) ) : ?>
					<p class="sh-note">
						<?php
						/* translators: %d: more items */
						echo esc_html( sprintf( __( 'And %d more.', 'optima-build' ), $kind['count'] - count( $kind['rows'] ) ) );
						?>
					</p>
				<?php endif; ?>
			<?php elseif ( $empty_note ) : ?>
				<p class="sh-note"><?php esc_html_e( 'Nothing waiting.', 'optima-build' ); ?></p>
			<?php endif; ?>
			<?php if ( $open ) : ?>
				<p class="sh-note"><a href="<?php echo esc_url( $kind['url'] ); ?>"><?php echo esc_html( $kind['link'] ); ?> &rsaquo;</a></p>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function render(): void {
		if ( ! current_user_can( Optima_Hub_Lock::VIEW ) ) {
			return;
		}
		$kinds = self::kinds();
		$count = self::count();
		Optima_Admin_UI::open(
			__( 'Inbox', 'optima-build' ),
			__( 'Everything waiting on a person: change requests, chat questions, new reviews and notes from your review page. Each one opens the screen that handles it.', 'optima-build' )
		);
		?>
		<section class="sh-box">
			<div class="sh-status <?php echo $count ? 'is-warn' : 'is-good'; ?>">
				<span class="sh-dot" aria-hidden="true"></span>
				<p class="sh-value">
					<?php
					echo esc_html(
						$count
							/* translators: %d: items waiting */
							? sprintf( _n( '%d item waiting', '%d items waiting', $count, 'optima-build' ), $count )
							: __( 'Nothing waiting', 'optima-build' )
					);
					?>
				</p>
			</div>
		</section>
		<div class="sh-grid sh-grid-2">
			<?php foreach ( $kinds as $kind ) : ?>
				<?php self::box( $kind ); ?>
			<?php endforeach; ?>
		</div>
		<?php
		Optima_Admin_UI::close();
	}
}
