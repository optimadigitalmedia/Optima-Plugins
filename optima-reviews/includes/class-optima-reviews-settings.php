<?php
/**
 * Reviews > Settings: each platform's overall rating and review count, the
 * "leave us a review" links, and an optional disclaimer.
 *
 * Why the totals are typed in here instead of averaged from the reviews on
 * the site: the site shows a hand-picked set. Averaging only those would
 * overstate the rating, which the FTC treats as a misleading review claim.
 * The summary always shows the platform's own numbers. Google sync (0.2.0)
 * will fill the Google row automatically.
 *
 * @package Optima_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Reviews_Settings {

	const OPTION = 'optima_reviews_settings';
	const PAGE   = 'optima-reviews-settings';

	/** When the totals were last saved (a Unix time). Optima Build's Site health reads it to flag stale counts. */
	const STAMP = 'optima_reviews_totals_updated';

	/** @var bool True while code (not the form) writes the option. */
	private static $internal_write = false;

	public static function defaults(): array {
		return array(
			'profiles'           => array(), // slug => { rating, count, url, write_url }.
			'disclaimer'         => '',
			'load_styles'        => true,
			'auto_add_five'      => false, // Publish new 5-star Google reviews without the inbox.
			'purge_on_uninstall' => false,
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One platform's profile: { rating: float, count: int, url, write_url }.
	 */
	public static function profile( string $slug ): array {
		$settings = self::get();
		$profile  = $settings['profiles'][ $slug ] ?? array();
		return array(
			'rating'    => (float) ( $profile['rating'] ?? 0 ),
			'count'     => (int) ( $profile['count'] ?? 0 ),
			'url'       => (string) ( $profile['url'] ?? '' ),
			'write_url' => (string) ( $profile['write_url'] ?? '' ),
		);
	}

	public static function register(): void {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function menu(): void {
		add_submenu_page(
			Optima_Reviews_Menu::parent(),
			__( 'Reviews', 'optima-reviews' ),
			'' !== Optima_Reviews_Menu::build() ? __( 'Reviews', 'optima-reviews' ) : __( 'Settings', 'optima-reviews' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * Replace one platform's totals from code (the Google sync), without going
	 * through the form's rules.
	 */
	public static function set_profile( string $slug, array $profile ): void {
		$settings                      = self::get();
		$settings['profiles'][ $slug ] = array_merge( self::profile( $slug ), $profile );
		self::$internal_write          = true;
		update_option( self::OPTION, $settings );
		self::$internal_write = false;
		update_option( self::STAMP, time(), false );
		Optima_Reviews_Query::flush();
	}

	public static function sanitize( $input ): array {
		if ( self::$internal_write && is_array( $input ) ) {
			return $input;
		}
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		foreach ( Optima_Reviews_Sources::all() as $slug => $info ) {
			// While Google syncs, its row is Google's numbers, not the form's.
			if ( 'google' === $slug && Optima_Reviews_Google::is_ready() ) {
				$current = self::get()['profiles']['google'] ?? null;
				if ( $current ) {
					$out['profiles']['google'] = $current;
				}
				continue;
			}
			$row = $input['profiles'][ $slug ] ?? array();
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rating = round( (float) ( $row['rating'] ?? 0 ), 1 );
			$clean  = array(
				'rating'    => ( $rating >= 1 && $rating <= 5 ) ? $rating : 0,
				'count'     => max( 0, (int) ( $row['count'] ?? 0 ) ),
				'url'       => esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ), array( 'https', 'http' ) ),
				'write_url' => esc_url_raw( trim( (string) ( $row['write_url'] ?? '' ) ), array( 'https', 'http' ) ),
			);
			if ( $clean['rating'] || $clean['count'] || $clean['url'] || $clean['write_url'] ) {
				$out['profiles'][ $slug ] = $clean;
			}
		}

		$out['disclaimer']         = sanitize_textarea_field( (string) ( $input['disclaimer'] ?? '' ) );
		$out['load_styles']        = ! empty( $input['load_styles'] );
		$out['auto_add_five']      = ! empty( $input['auto_add_five'] );
		$out['purge_on_uninstall'] = ! empty( $input['purge_on_uninstall'] );

		// Saving the form means someone looked at the totals, so note when.
		update_option( self::STAMP, time(), false );
		Optima_Reviews_Query::flush();
		return $out;
	}

	/** The reviews themselves, first on the one Reviews tab: counts, the newest five, and the way to all of them. */
	private static function list_box(): void {
		$type   = Optima_Reviews_Post_Type::TYPE;
		$counts = wp_count_posts( $type );
		$live   = (int) ( $counts->publish ?? 0 );
		$wait   = Optima_Reviews_Inbox::counts()['all'];
		$latest = get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => 5 ) );
		echo '<section class="sh-box" id="reviews-list"><h2>' . esc_html__( 'Reviews', 'optima-reviews' ) . '</h2>';
		/* translators: 1: reviews on the site, 2: waiting in the Review inbox */
		echo '<p>' . esc_html( sprintf( __( '%1$d on the site. %2$d waiting in the Review inbox.', 'optima-reviews' ), $live, $wait ) ) . '</p>';
		if ( $latest ) {
			echo '<ul class="sh-waiting">';
			foreach ( $latest as $post ) {
				$src = (string) get_post_meta( $post->ID, '_optima_review_source', true );
				echo '<li><a href="' . esc_url( (string) get_edit_post_link( $post->ID ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a><span class="sh-note">' . esc_html( Optima_Reviews_Sources::label( $src ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '<p class="sh-actions" style="margin-top:12px"><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . $type ) ) . '">' . esc_html__( 'All reviews', 'optima-reviews' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . $type ) ) . '">' . esc_html__( 'Add a review', 'optima-reviews' ) . '</a> ';
		echo '<a class="button' . ( $wait ? ' button-primary' : '' ) . '" href="' . esc_url( Optima_Reviews_Inbox::url() ) . '">' . esc_html__( 'Review inbox', 'optima-reviews' ) . '</a></p></section>';
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get();
		$name     = self::OPTION;
		?>
		<?php Optima_Admin_UI::open( __( 'Reviews', 'optima-reviews' ), __( 'The reviews on the site, the business\'s profile on each platform, asking clients for reviews, and how reviews look.', 'optima-reviews' ) ); ?>
			<p class="sh-note"><?php esc_html_e( 'On this screen:', 'optima-reviews' ); ?> <a href="#reviews-list"><?php esc_html_e( 'Reviews', 'optima-reviews' ); ?></a> · <a href="#reviews-platforms"><?php esc_html_e( 'Platforms', 'optima-reviews' ); ?></a> · <a href="#reviews-ask"><?php esc_html_e( 'Ask for reviews', 'optima-reviews' ); ?></a> · <a href="#reviews-display"><?php esc_html_e( 'Display', 'optima-reviews' ); ?></a></p>

			<?php self::list_box(); ?>

			<?php do_action( 'optima_reviews_settings_top' ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>

				<section class="sh-box" id="reviews-platforms">
				<h2><?php esc_html_e( 'Platforms', 'optima-reviews' ); ?></h2>
				<p class="description" style="max-width:720px;">
					<?php esc_html_e( 'Copy the overall rating and total review count exactly as each platform shows them. The rating summary on the site uses these numbers, so visitors see your real totals, not just the reviews you picked. Leave a row empty to skip that platform.', 'optima-reviews' ); ?>
				</p>

				<div class="sh-scroll"><table class="widefat striped" style="margin-top:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Platform', 'optima-reviews' ); ?></th>
							<th><?php esc_html_e( 'Overall rating', 'optima-reviews' ); ?></th>
							<th><?php esc_html_e( 'Total reviews', 'optima-reviews' ); ?></th>
							<th><?php esc_html_e( 'Profile link (read all reviews)', 'optima-reviews' ); ?></th>
							<th><?php esc_html_e( 'Leave a review link', 'optima-reviews' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$listed = Optima_Reviews_Sources::for_site();
						foreach ( Optima_Reviews_Sources::all() as $slug => $info ) :
							if ( 'direct' === $slug || ! in_array( $slug, $listed, true ) ) {
								continue; // Direct testimonials have no platform profile; other kinds of business use other platforms.
							}
							$p      = self::profile( $slug );
							$base   = $name . '[profiles][' . $slug . ']';
							$synced = 'google' === $slug && Optima_Reviews_Google::is_ready();
							$lock   = $synced ? ' readonly' : '';
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $info['label'] ); ?></strong>
									<?php if ( $synced ) : ?>
										<br /><span class="description"><?php esc_html_e( 'Synced', 'optima-reviews' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $info['stars'] ) : ?>
										<input type="number" step="0.1" min="1" max="5" style="width:80px;" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: platform */ __( '%s overall rating', 'optima-reviews' ), $info['label'] ) ); ?>"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[rating]' ); ?>" value="<?php echo $p['rating'] ? esc_attr( (string) $p['rating'] ) : ''; ?>" />
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'No stars', 'optima-reviews' ); ?></span>
									<?php endif; ?>
								</td>
								<td><input type="number" min="0" step="1" style="width:90px;" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: platform */ __( '%s total reviews', 'optima-reviews' ), $info['label'] ) ); ?>"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[count]' ); ?>" value="<?php echo $p['count'] ? esc_attr( (string) $p['count'] ) : ''; ?>" /></td>
								<td><input type="url" class="regular-text" placeholder="https://" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: platform */ __( '%s profile link', 'optima-reviews' ), $info['label'] ) ); ?>"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[url]' ); ?>" value="<?php echo esc_attr( $p['url'] ); ?>" /></td>
								<td><input type="url" class="regular-text" placeholder="https://" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: platform */ __( '%s leave a review link', 'optima-reviews' ), $info['label'] ) ); ?>"<?php echo $lock; // phpcs:ignore WordPress.Security.EscapeOutput ?> name="<?php echo esc_attr( $base . '[write_url]' ); ?>" value="<?php echo esc_attr( $p['write_url'] ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table></div>
				<p class="description">
					<?php esc_html_e( 'Google\'s "leave a review" link is in your Business Profile under "Ask for reviews." It takes clients straight to the review box.', 'optima-reviews' ); ?>
				</p>
				</section>

				<section class="sh-box" id="reviews-ask">
					<h2><?php esc_html_e( 'Ask for reviews', 'optima-reviews' ); ?></h2>
					<p><?php esc_html_e( 'One review link, ready-made messages and printed cards with a QR code, so every client is asked the same way.', 'optima-reviews' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( Optima_Reviews_Menu::url( Optima_Reviews_Requests::PAGE ) ); ?>"><?php esc_html_e( 'Open Ask for reviews', 'optima-reviews' ); ?></a></p>
				</section>

				<section class="sh-box" id="reviews-display">
				<h2><?php esc_html_e( 'Display', 'optima-reviews' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="optima-reviews-disclaimer"><?php esc_html_e( 'Disclaimer (optional)', 'optima-reviews' ); ?></label></th>
						<td>
							<textarea id="optima-reviews-disclaimer" class="large-text" rows="3" name="<?php echo esc_attr( $name . '[disclaimer]' ); ?>"><?php echo esc_textarea( $settings['disclaimer'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown under every reviews section. Law firms: your state bar may require one with testimonials, for example "Past results do not guarantee a similar outcome." Check your state\'s rules.', 'optima-reviews' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'New 5-star reviews', 'optima-reviews' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name . '[auto_add_five]' ); ?>" value="1" <?php checked( ! empty( $settings['auto_add_five'] ) ); ?> /> <?php esc_html_e( 'Add new 5-star Google reviews to the site automatically, on the pages they match. Everything else still waits in the Review inbox.', 'optima-reviews' ); ?></label>
							<p class="description"><?php esc_html_e( 'Leave off for law firms: bar rules expect a person to approve each testimonial.', 'optima-reviews' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Built-in design', 'optima-reviews' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name . '[load_styles]' ); ?>" value="1" <?php checked( $settings['load_styles'] ); ?> /> <?php esc_html_e( 'Load the Optima Reviews stylesheet. Turn off only if your theme styles the reviews itself.', 'optima-reviews' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On delete', 'optima-reviews' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name . '[purge_on_uninstall]' ); ?>" value="1" <?php checked( $settings['purge_on_uninstall'] ); ?> /> <?php esc_html_e( 'Remove all reviews and settings when the plugin is deleted. Leave off unless you mean it.', 'optima-reviews' ); ?></label>
						</td>
					</tr>
				</table>
				</section>

				<?php submit_button( __( 'Save', 'optima-reviews' ) ); ?>
			</form>

			<?php Optima_Admin_UI::dev_open(); ?>
			<h2><?php esc_html_e( 'Putting reviews on a page', 'optima-reviews' ); ?></h2>
			<p><?php esc_html_e( 'In the block editor, add the "Optima Reviews" block. Anywhere else, use a shortcode:', 'optima-reviews' ); ?></p>
			<ul style="list-style:disc;padding-left:20px;">
				<li><code>[optima_reviews]</code> <?php esc_html_e( 'shows the rating summary and every published review.', 'optima-reviews' ); ?></li>
				<li><code>[optima_reviews featured="1" count="3"]</code> <?php esc_html_e( 'shows three featured reviews, good for the home page.', 'optima-reviews' ); ?></li>
				<li><code>[optima_reviews source="google" layout="row"]</code> <?php esc_html_e( 'shows only Google reviews in a sideways-scrolling row.', 'optima-reviews' ); ?></li>
				<li><code>[optima_reviews summary="0"]</code> <?php esc_html_e( 'hides the rating summary.', 'optima-reviews' ); ?></li>
				<li><code>[optima_reviews topic="law-firms" count="3"]</code> <?php esc_html_e( 'shows reviews tagged with one topic, or featured reviews until that topic has some.', 'optima-reviews' ); ?></li>
				<li><code>[optima_review_summary]</code> <?php esc_html_e( 'shows only the rating summary.', 'optima-reviews' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'Theme developers: call optima_reviews_slot() in a template to show the reviews for that page automatically. See the plugin README.', 'optima-reviews' ); ?></p>
			<?php do_action( 'optima_reviews_settings_dev' ); ?>
			<?php Optima_Admin_UI::dev_close(); ?>
		<?php Optima_Admin_UI::close(); ?>
		<?php
	}
}
