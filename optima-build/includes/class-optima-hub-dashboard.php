<?php
/**
 * The Optima box on the WordPress Dashboard: Overview's status line, what is
 * waiting in the Inbox, review totals and spam blocked, and one click into
 * Overview. First box on the page, so the person who logs in sees it first.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Dashboard {

	const ID = 'optima_hub_dashboard';

	public static function boot(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		if ( ! current_user_can( Optima_Hub_Lock::VIEW ) ) {
			return;
		}
		$title = Optima_Hub_Admin::branded() ? __( 'Optima', 'optima-build' ) : __( 'Site Build', 'optima-build' );
		wp_add_dashboard_widget( self::ID, $title, array( __CLASS__, 'render' ) );

		// First in the left column.
		global $wp_meta_boxes;
		$core = $wp_meta_boxes['dashboard']['normal']['core'] ?? array();
		if ( isset( $core[ self::ID ] ) ) {
			$wp_meta_boxes['dashboard']['normal']['core'] = array( self::ID => $core[ self::ID ] ) + $core; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering only.
		}
	}

	/** Rating, count and new reviews, when Optima Reviews is on. */
	private static function reviews(): void {
		if ( ! class_exists( 'Optima_Reviews_Query' ) || ! class_exists( 'Optima_Reviews_Post_Type' ) ) {
			return;
		}
		$totals = Optima_Reviews_Query::totals();
		$top    = $totals[0] ?? null;
		$new    = class_exists( 'Optima_Reviews_Google_Sync' ) ? count(
			get_posts(
				array(
					'post_type'      => Optima_Reviews_Post_Type::TYPE,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 50,
					'meta_key'       => Optima_Reviews_Google_Sync::META_NEW, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		) : 0;
		$list   = admin_url( 'edit.php?post_type=' . Optima_Reviews_Post_Type::TYPE );
		?>
		<p class="sd-reviews">
			<span class="sd-stars" aria-hidden="true">&#9733;</span>
			<?php
			if ( $top ) {
				$name = class_exists( 'Optima_Reviews_Sources' ) ? ( Optima_Reviews_Sources::all()[ $top['source'] ]['label'] ?? ucfirst( $top['source'] ) ) : ucfirst( $top['source'] );
				/* translators: 1: rating, 2: review count, 3: platform */
				echo esc_html( sprintf( _n( '%1$s from %2$d review on %3$s', '%1$s from %2$d reviews on %3$s', (int) $top['count'], 'optima-build' ), number_format( (float) $top['rating'], 1 ), (int) $top['count'], $name ) );
			} else {
				esc_html_e( 'No review totals yet. Add them in Reviews, Settings.', 'optima-build' );
			}
			if ( $new ) {
				/* translators: %d: new reviews */
				echo ' &middot; <strong>' . esc_html( sprintf( _n( '%d new review', '%d new reviews', $new, 'optima-build' ), $new ) ) . '</strong>';
			}
			?>
			&middot; <a href="<?php echo esc_url( $list ); ?>"><?php esc_html_e( 'See reviews', 'optima-build' ); ?></a>
		</p>
		<?php
	}

	/** Spam blocked this month, when Optima Forms Guard 0.2.0+ is on. */
	private static function spam(): void {
		if ( ! class_exists( 'Optima_Forms_Guard' ) || ! method_exists( 'Optima_Forms_Guard', 'blocked' ) ) {
			return;
		}
		$month = (int) Optima_Forms_Guard::blocked();
		$all   = (int) Optima_Forms_Guard::blocked( 'all' );
		?>
		<p class="sd-spam">
			<span class="sd-shield" aria-hidden="true"></span>
			<?php
			if ( $month ) {
				/* translators: %d: spam submissions blocked this month */
				echo '<strong>' . esc_html( sprintf( _n( '%d spam submission blocked this month', '%d spam submissions blocked this month', $month, 'optima-build' ), $month ) ) . '</strong>';
			} else {
				esc_html_e( 'Spam protection is on. Nothing blocked yet this month.', 'optima-build' );
			}
			if ( $all > $month ) {
				/* translators: %d: spam submissions blocked in the last year */
				echo ' &middot; ' . esc_html( sprintf( _n( '%d in the last year', '%d in the last year', $all, 'optima-build' ), $all ) );
			}
			?>
			<span class="sd-note"><?php esc_html_e( 'Your forms check every message invisibly, with no puzzles for real visitors.', 'optima-build' ); ?></span>
		</p>
		<?php
	}

	public static function render(): void {
		$status = Optima_Hub_Overview::status();
		$count  = Optima_Hub_Inbox::count();
		?>
		<style>
			#<?php echo esc_attr( self::ID ); ?> .hndle::before{content:""; display:inline-block; width:12px; height:15px; margin-right:8px; background:#1272C4; clip-path:polygon(35% 0,100% 0,100% 65%,76% 41%,17% 100%,0 83%,59% 24%); vertical-align:-2px}
			.sd-status{display:grid; grid-template-columns:10px 1fr; gap:8px; align-items:start; margin:4px 0 12px; font-size:14px}
			.sd-status strong{font-weight:600}
			.sd-dot{width:8px; height:8px; border-radius:50%; margin-top:6px; background:#0B6B3A}
			.sd-dot.is-warn{background:#FDC134}
			.sd-dot.is-bad{background:#E23B2E}
			.sd-reviews{margin:0 0 12px; padding:10px 12px; border-radius:10px; background:#f6f7f7}
			.sd-stars{color:#FDC134; margin-right:4px}
			.sd-links{display:flex; flex-wrap:wrap; gap:8px; margin-bottom:0}
		</style>
		<p class="sd-status">
			<span class="sd-dot is-<?php echo esc_attr( $status['state'] ); ?>" aria-hidden="true"></span>
			<span>
				<strong><?php echo esc_html( $status['text'] ); ?></strong>
				<?php if ( $count ) : ?>
					<br><a href="<?php echo esc_url( Optima_Hub_Inbox::url() ); ?>">
						<?php
						/* translators: %d: items waiting */
						echo esc_html( sprintf( _n( '%d item waiting in the Inbox', '%d items waiting in the Inbox', $count, 'optima-build' ), $count ) );
						?>
					</a>
				<?php endif; ?>
			</span>
		</p>
		<?php self::reviews(); ?>
		<p class="sd-links">
			<a class="button button-primary" href="<?php echo esc_url( Optima_Hub_Overview::url() ); ?>"><?php esc_html_e( 'Open Overview', 'optima-build' ); ?></a>
			<a class="button" href="<?php echo esc_url( $status['url'] ); ?>"><?php esc_html_e( 'Site health', 'optima-build' ); ?></a>
		</p>
		<?php
	}
}
