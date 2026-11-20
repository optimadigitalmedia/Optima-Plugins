<?php
/**
 * Optima → Content → Attorney sign-offs: which pages are due for an
 * attorney's review, the review period, and which kinds of content come due.
 *
 * Each review is recorded in the page's own Reviewed by box (Optima Team), so
 * there is one record; this screen links to it.
 *
 * @package Optima_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Law_Reviews_Admin {

	const SLUG = 'optima-law-reviews';
	const SAVE = 'optima_law_reviews_save';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
		add_action( 'admin_post_' . self::SAVE, array( __CLASS__, 'save' ) );
	}

	public static function url( array $args = array() ): string {
		return Optima_Law_Admin::screen_url( self::SLUG, $args );
	}

	public static function menu(): void {
		add_submenu_page( Optima_Law_Admin::parent(), __( 'Attorney sign-offs', 'optima-law' ), __( 'Attorney sign-offs', 'optima-law' ), 'edit_pages', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change review settings.', 'optima-law' ), 403 );
		}
		check_admin_referer( self::SAVE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$types  = isset( $_POST['optima_law_review_types'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['optima_law_review_types'] ) ) : array();
		$months = absint( $_POST['optima_law_review_months'] ?? 6 );
		// phpcs:enable
		update_option(
			Optima_Law_Reviews::OPTION,
			array(
				'types'  => array_values( array_intersect( $types, array_keys( Optima_Law_Approvals::choices() ) ) ),
				'months' => in_array( $months, Optima_Law_Reviews::MONTHS, true ) ? $months : 6,
			)
		);
		wp_safe_redirect( add_query_arg( 'optima-law', 'saved', self::url() ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$settings = Optima_Law_Reviews::settings();
		Optima_Admin_UI::open(
			__( 'Attorney sign-offs', 'optima-law' ),
			__( 'An attorney reads each page on a schedule and signs it in the page\'s Reviewed by box.', 'optima-law' ),
			'',
			self::SLUG
		);
		Optima_Law_Admin::message( array( 'saved' => array( 'success', __( 'Saved.', 'optima-law' ) ) ) );

		if ( ! Optima_Law_Reviews::available() ) {
			Optima_Law_Admin::status_line( 'warn', __( 'Turn on Team credentials to sign pages.', 'optima-law' ), __( 'Reviews are recorded on People profiles, in each page\'s Reviewed by box. Turn it on under Build, Features.', 'optima-law' ) );
			Optima_Admin_UI::close();
			return;
		}
		$people = Optima_Law_Reviews::attorneys();
		$due    = Optima_Law_Reviews::due();
		if ( ! $people ) {
			Optima_Law_Admin::status_line( 'warn', __( 'No attorney can sign yet.', 'optima-law' ), __( 'Add one in People and tick "Attorney", then publish the profile.', 'optima-law' ) );
		} elseif ( $due ) {
			/* translators: 1: pages due, 2: months */
			Optima_Law_Admin::status_line( 'warn', sprintf( _n( '%1$d page is due. Pages are reviewed every %2$d months.', '%1$d pages are due. Pages are reviewed every %2$d months.', count( $due ), 'optima-law' ), count( $due ), $settings['months'] ) );
		} else {
			/* translators: %d: months */
			Optima_Law_Admin::status_line( '', __( 'All caught up.', 'optima-law' ), sprintf( __( 'Every page has been reviewed in the last %d months.', 'optima-law' ), $settings['months'] ) );
		}
		?>

		<?php if ( $due ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Due for a review', 'optima-law' ); ?></h2>
				<p class="sh-note"><?php esc_html_e( 'Have the attorney read the page, then open it and fill its Reviewed by box with the attorney and the date. About five minutes each.', 'optima-law' ); ?></p>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Page', 'optima-law' ); ?></th><th><?php esc_html_e( 'Last reviewed', 'optima-law' ); ?></th><th><?php esc_html_e( 'Sign off', 'optima-law' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $due, 0, 50 ) as $id ) : ?>
						<?php
						$at  = Optima_Law_Reviews::reviewed_at( $id );
						$who = Optima_Law_Reviews::reviewer( $id );
						if ( ! $at ) {
							$status = Optima_Team_Reviewed::status( $id );
							$last   = 'stale' === $status ? __( 'Out of date: the page changed after the review', 'optima-law' ) : __( 'Never', 'optima-law' );
						} else {
							$last = wp_date( get_option( 'date_format' ), $at ) . ( $who ? ', ' . get_the_title( $who ) : '' );
						}
						?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_permalink( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ? get_the_title( $id ) : __( '(no title)', 'optima-law' ) ); ?></a></td>
							<td><?php echo esc_html( $last ); ?></td>
							<td><?php if ( current_user_can( 'edit_post', $id ) ) : ?><a class="button" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Open Reviewed by', 'optima-law' ); ?></a><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( count( $due ) > 50 ) : ?>
					<?php /* translators: %d: more pages */ ?>
					<p class="sh-note"><?php echo esc_html( sprintf( __( 'And %d more.', 'optima-law' ), count( $due ) - 50 ) ); ?></p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<details class="sh-box">
				<summary class="sh-title"><?php esc_html_e( 'More options', 'optima-law' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>">
					<?php wp_nonce_field( self::SAVE ); ?>
					<fieldset>
						<legend><?php esc_html_e( 'What comes due', 'optima-law' ); ?></legend>
						<?php foreach ( Optima_Law_Approvals::choices() as $type => $label ) : ?>
							<label style="display:block;margin:6px 0"><input type="checkbox" name="optima_law_review_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $settings['types'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<p>
						<label for="optima-law-months"><?php esc_html_e( 'Review every', 'optima-law' ); ?></label>
						<select id="optima-law-months" name="optima_law_review_months">
							<?php foreach ( Optima_Law_Reviews::MONTHS as $m ) : ?>
								<option value="<?php echo (int) $m; ?>" <?php selected( $settings['months'], $m ); ?>><?php echo esc_html( sprintf( /* translators: %d: months */ _n( '%d month', '%d months', $m, 'optima-law' ), $m ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="sh-note"><?php esc_html_e( 'The privacy page is left out: it has its own yearly review.', 'optima-law' ); ?></p>
					<?php submit_button( __( 'Save', 'optima-law' ), 'secondary' ); ?>
				</form>
			</details>
		<?php endif; ?>
		<?php
		Optima_Admin_UI::close();
	}
}
