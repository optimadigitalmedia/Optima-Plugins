<?php
/**
 * Optima → Build → Form entries (operators only): whether the website deletes
 * old form entries, how long it keeps them, what happens to each form, and a
 * record of each night that deleted something.
 *
 * Off by default until the decision is made (QUESTIONS Q10 in the internal repo).
 *
 * @package Optima_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Law_Entries_Admin {

	const SLUG = 'optima-law-entries';
	const SAVE = 'optima_law_entries_save';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 33 );
		add_action( 'admin_post_' . self::SAVE, array( __CLASS__, 'save' ) );
	}

	public static function url( array $args = array() ): string {
		return Optima_Law_Admin::screen_url( self::SLUG, $args );
	}

	public static function menu(): void {
		if ( ! Optima_Law_Admin::operator() ) {
			return; // Build screens are for operators only (plugin-first rules 9 and 53).
		}
		add_submenu_page( Optima_Law_Admin::parent(), __( 'Form entries', 'optima-law' ), __( 'Form entries', 'optima-law' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function save(): void {
		if ( ! Optima_Law_Admin::operator() ) {
			wp_die( esc_html__( 'Only the web team can change how long form entries are kept.', 'optima-law' ), 403 );
		}
		check_admin_referer( self::SAVE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$days = absint( $_POST['optima_law_entry_days'] ?? 90 );
		$keep = isset( $_POST['optima_law_entry_keep'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['optima_law_entry_keep'] ) ) : array();
		$on   = ! empty( $_POST['optima_law_entry_on'] );
		// phpcs:enable
		$was = Optima_Law_Entries::settings()['on'];
		update_option(
			Optima_Law_Entries::OPTION,
			array(
				'on'   => $on,
				'days' => in_array( $days, Optima_Law_Entries::DAYS, true ) ? $days : 90,
				'keep' => array_values( array_filter( $keep ) ),
			)
		);
		if ( $on !== $was ) {
			// Turned on: the notice starts now. Turned off: the next time it is turned on starts a new notice.
			Optima_Law_Entries::reset_notice();
			if ( $on ) {
				Optima_Law_Entries::on_since( time() );
			}
		}
		Optima_Law_Entries::schedule();
		wp_safe_redirect( add_query_arg( 'optima-law', 'saved', self::url() ) );
		exit;
	}

	public static function render(): void {
		if ( ! Optima_Law_Admin::operator() ) {
			return;
		}
		$settings = Optima_Law_Entries::settings();
		$gravity  = Optima_Law_Entries::gravity();
		$plan     = Optima_Law_Entries::plan( Optima_Law_Entries::forms(), $settings );
		$now      = time();
		$starts   = $settings['on'] ? Optima_Law_Entries::starts( Optima_Law_Entries::on_since( $now ) ) : 0;
		$last     = Optima_Law_Entries::last_run();
		$date     = get_option( 'date_format' );
		Optima_Admin_UI::open(
			__( 'Form entries', 'optima-law' ),
			__( 'How long the website keeps what people send through its forms.', 'optima-law' ),
			'',
			self::SLUG
		);
		Optima_Law_Admin::message( array( 'saved' => array( 'success', __( 'Saved.', 'optima-law' ) ) ) );

		if ( ! $gravity ) {
			Optima_Law_Admin::status_line( '', __( 'Nothing to do.', 'optima-law' ), __( 'Gravity Forms isn\'t active, so the website keeps no form entries.', 'optima-law' ) );
		} elseif ( ! $settings['on'] ) {
			Optima_Law_Admin::status_line( '', __( 'Off. Entries stay on the website until someone deletes them.', 'optima-law' ), __( 'Off by default until the decision on deleting form entries is made. Turn it on under More options.', 'optima-law' ) );
		} elseif ( $now < $starts ) {
			/* translators: 1: date, 2: days */
			Optima_Law_Admin::status_line( 'warn', sprintf( __( 'Starts %1$s: entries older than %2$d days are deleted each night from then on.', 'optima-law' ), wp_date( $date, $starts ), $settings['days'] ), __( 'Star any old entry that has to stay, in Gravity Forms. Emails already sent are not touched.', 'optima-law' ) );
		} else {
			$note = $last ? sprintf( /* translators: %s: date */ __( 'Last checked %s.', 'optima-law' ), wp_date( $date, $last ) ) : '';
			/* translators: %d: days */
			Optima_Law_Admin::status_line( '', sprintf( __( 'On. Entries older than %d days are deleted each night.', 'optima-law' ), $settings['days'] ), $note );
		}
		?>

		<?php if ( $gravity && $plan ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Each form', 'optima-law' ); ?></h2>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Form', 'optima-law' ); ?></th><th><?php esc_html_e( 'What happens to its entries', 'optima-law' ); ?></th></tr></thead>
					<tbody>
					<?php
					$words = array(
						/* translators: %d: days */
						'delete'   => sprintf( __( 'Deleted after %d days', 'optima-law' ), $settings['days'] ),
						'kept'     => __( 'Kept: this form\'s entries are kept by choice', 'optima-law' ),
						'no-email' => __( 'Kept: this form doesn\'t email anyone, so the entry is the only copy', 'optima-law' ),
						'gravity'  => __( 'Handled by this form\'s own Gravity Forms retention setting', 'optima-law' ),
					);
					foreach ( $plan as $id => $row ) :
						?>
						<tr>
							<td><?php echo esc_html( '' !== $row['title'] ? $row['title'] : sprintf( /* translators: %d: form id */ __( 'Form %d', 'optima-law' ), $id ) ); ?></td>
							<td><?php echo esc_html( $settings['on'] ? $words[ $row['does'] ] : __( 'Kept: deleting is off', 'optima-law' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>

		<details class="sh-box">
			<summary class="sh-title"><?php esc_html_e( 'More options', 'optima-law' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>">
				<?php wp_nonce_field( self::SAVE ); ?>
				<p><label><input type="checkbox" name="optima_law_entry_on" value="1" <?php checked( $settings['on'] ); ?>> <?php esc_html_e( 'Delete old form entries from the website', 'optima-law' ); ?></label></p>
				<p class="sh-note"><?php esc_html_e( 'The first delete waits 14 days after this is turned on.', 'optima-law' ); ?></p>
				<p>
					<label for="optima-law-days"><?php esc_html_e( 'Keep entries for', 'optima-law' ); ?></label>
					<select id="optima-law-days" name="optima_law_entry_days">
						<?php foreach ( Optima_Law_Entries::DAYS as $d ) : ?>
							<option value="<?php echo (int) $d; ?>" <?php selected( $settings['days'], $d ); ?>><?php echo esc_html( sprintf( /* translators: %d: days */ _n( '%d day', '%d days', $d, 'optima-law' ), $d ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php if ( $plan ) : ?>
					<fieldset>
						<legend><?php esc_html_e( 'Keep every entry from these forms', 'optima-law' ); ?></legend>
						<?php foreach ( $plan as $id => $row ) : ?>
							<label style="display:block;margin:6px 0"><input type="checkbox" name="optima_law_entry_keep[]" value="<?php echo (int) $id; ?>" <?php checked( in_array( (int) $id, $settings['keep'], true ) ); ?>> <?php echo esc_html( '' !== $row['title'] ? $row['title'] : sprintf( /* translators: %d: form id */ __( 'Form %d', 'optima-law' ), $id ) ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
				<p class="sh-note"><?php esc_html_e( 'If you change the time, the privacy policy page follows it (Optima Core reads it).', 'optima-law' ); ?></p>
				<?php submit_button( __( 'Save', 'optima-law' ), 'secondary' ); ?>
			</form>
		</details>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'What this touches', 'optima-law' ); ?></h2>
			<ul class="sh-list">
				<li><?php esc_html_e( 'Only the website\'s copy. The emails each form already sent stay in the inbox, and the firm\'s case and intake records are untouched.', 'optima-law' ); ?></li>
				<li><?php esc_html_e( 'Files people uploaded with an entry are deleted with it.', 'optima-law' ); ?></li>
				<li><?php esc_html_e( 'Starred entries are kept. Star one in Gravity Forms to keep it.', 'optima-law' ); ?></li>
				<li><?php esc_html_e( 'Spam and trashed entries are deleted on the same schedule. At most 500 entries per form a night.', 'optima-law' ); ?></li>
			</ul>
		</section>

		<?php $log = array_slice( Optima_Law_Entries::log(), 0, 10 ); ?>
		<?php if ( $log ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Deletion record', 'optima-law' ); ?></h2>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Night of', 'optima-law' ); ?></th><th><?php esc_html_e( 'Entries deleted', 'optima-law' ); ?></th><th><?php esc_html_e( 'Older than', 'optima-law' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr><td><?php echo esc_html( wp_date( $date, (int) $row['at'] ) ); ?></td><td><?php echo (int) $row['total']; ?></td><td><?php echo esc_html( sprintf( /* translators: %d: days */ _n( '%d day', '%d days', (int) $row['days'], 'optima-law' ), (int) $row['days'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>
		<?php
		Optima_Admin_UI::close();
	}
}
