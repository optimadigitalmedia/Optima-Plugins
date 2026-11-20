<?php
/**
 * Optima → Content → Firm approvals: what is waiting for the firm, what
 * changed after approval, the approval record, and (folded below) which kinds
 * of content need the firm's approval before publishing.
 *
 * @package Optima_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Law_Approvals_Admin {

	const SLUG   = 'optima-law-approvals';
	const ACTION = 'optima_law_approvals_save';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
	}

	public static function url( array $args = array() ): string {
		return Optima_Law_Admin::screen_url( self::SLUG, $args );
	}

	public static function menu(): void {
		add_submenu_page( Optima_Law_Admin::parent(), __( 'Firm approvals', 'optima-law' ), __( 'Firm approvals', 'optima-law' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change approvals.', 'optima-law' ), 403 );
		}
		check_admin_referer( self::ACTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked above; cleaned below.
		$types = isset( $_POST['optima_law_types'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['optima_law_types'] ) ) : array();
		update_option( Optima_Law_Approvals::OPTION, array( 'types' => array_values( array_intersect( $types, array_keys( Optima_Law_Approvals::choices() ) ) ) ) );
		wp_safe_redirect( add_query_arg( 'optima-law', 'saved', self::url() ) );
		exit;
	}

	/** A short list of items, each linking to its edit screen. */
	private static function items( array $ids ): void {
		echo '<ul class="sh-waiting">';
		foreach ( array_slice( $ids, 0, 20 ) as $id ) {
			$type = get_post_type_object( (string) get_post_type( $id ) );
			printf(
				'<li><div><a href="%1$s">%2$s</a><p class="sh-note">%3$s</p></div></li>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( get_the_title( $id ) ? get_the_title( $id ) : __( '(no title)', 'optima-law' ) ),
				esc_html( $type ? $type->labels->singular_name : '' )
			);
		}
		echo '</ul>';
		if ( count( $ids ) > 20 ) {
			/* translators: %d: more items */
			echo '<p class="sh-note">' . esc_html( sprintf( __( 'And %d more.', 'optima-law' ), count( $ids ) - 20 ) ) . '</p>';
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$types = Optima_Law_Approvals::types();
		$q     = Optima_Law_Approvals::queues();
		Optima_Admin_UI::open(
			__( 'Firm approvals', 'optima-law' ),
			__( 'Pages and posts the firm signs off on before they go live, with each approval kept.', 'optima-law' ),
			'',
			self::SLUG
		);
		Optima_Law_Admin::message( array( 'saved' => array( 'success', __( 'Saved.', 'optima-law' ) ) ) );

		$waiting_n = count( $q['waiting'] ) + count( $q['edited'] );
		if ( $waiting_n ) {
			/* translators: %d: pages and posts waiting. */
			Optima_Law_Admin::status_line( 'warn', sprintf( _n( '%d item waits for the firm.', '%d items wait for the firm.', $waiting_n, 'optima-law' ), $waiting_n ), __( 'Open each one and record the firm\'s approval.', 'optima-law' ) );
		} elseif ( ! $types ) {
			Optima_Law_Admin::status_line( '', __( 'Nothing needs the firm\'s approval on this site.', 'optima-law' ), __( 'Choose what does under More options below.', 'optima-law' ) );
		} else {
			Optima_Law_Admin::status_line( '', __( 'All caught up.', 'optima-law' ), __( 'Everything that needs the firm\'s approval has it.', 'optima-law' ) );
		}
		?>

		<?php if ( $q['waiting'] ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Waiting for the firm\'s approval', 'optima-law' ); ?></h2>
				<?php self::items( $q['waiting'] ); ?>
			</section>
		<?php endif; ?>

		<?php if ( $q['edited'] ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Edited since approval', 'optima-law' ); ?></h2>
				<p class="sh-note"><?php esc_html_e( 'These stay live. Open each one, have the firm look at the change, and record a new approval.', 'optima-law' ); ?></p>
				<?php self::items( $q['edited'] ); ?>
			</section>
		<?php endif; ?>

		<?php $log = array_slice( Optima_Law_Approvals::log(), 0, 10 ); ?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Approval record', 'optima-law' ); ?></h2>
			<?php if ( ! $log ) : ?>
				<p class="sh-note"><?php esc_html_e( 'No approvals yet. Each one shows here with the date, the attorney and a link to the exact version approved.', 'optima-law' ); ?></p>
			<?php else : ?>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Date', 'optima-law' ); ?></th><th><?php esc_html_e( 'Page or post', 'optima-law' ); ?></th><th><?php esc_html_e( 'Approved by', 'optima-law' ); ?></th><th><?php esc_html_e( 'Version', 'optima-law' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $row['at'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['title'] ); ?></td>
							<td><?php echo esc_html( (string) $row['by'] ); ?></td>
							<td><?php echo ! empty( $row['revision'] ) ? '<a href="' . esc_url( admin_url( 'revision.php?revision=' . (int) $row['revision'] ) ) . '">' . esc_html__( 'See this version', 'optima-law' ) . '</a>' : '<span class="screen-reader-text">' . esc_html__( 'None', 'optima-law' ) . '</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<details class="sh-box">
			<summary class="sh-title"><?php esc_html_e( 'More options: what needs approval', 'optima-law' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Kinds of content that need approval', 'optima-law' ); ?></legend>
					<?php foreach ( Optima_Law_Approvals::choices() as $type => $label ) : ?>
						<label style="display:block;margin:6px 0"><input type="checkbox" name="optima_law_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $types, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p class="sh-note"><?php esc_html_e( 'Blog posts and bios start on. Turn on Pages once the site is built, since it also holds pages your web team publishes. Case results and reviews keep their own approval.', 'optima-law' ); ?></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'optima-law' ); ?></button></p>
			</form>
		</details>
		<?php
		Optima_Admin_UI::close();
	}
}
