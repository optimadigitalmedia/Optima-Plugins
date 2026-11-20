<?php
/**
 * Optima → Content → Disclaimers: the firm's lines, where each shows, and the
 * firm's approval of the exact wording, with every approval kept.
 *
 * Also the small pieces every Law screen shares: the parent menu, the group
 * each screen files under, the operator check and the status line.
 *
 * Saving posts to admin-post.php with a nonce and a manage_options check,
 * then returns to the screen. Lines waiting on the firm show on Optima's
 * Inbox (Optima_Law_Inbox).
 *
 * @package Optima_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Law_Admin {

	const SLUG   = 'optima-law-disclaimers';
	const ACTION = 'optima_law_save';

	/** Each Law screen and the Optima menu item it files under (plugin-first rules 10 and 53). */
	const GROUPS = array(
		'optima-law-disclaimers' => 'content',
		'optima-law-approvals'   => 'content',
		'optima-law-reviews'     => 'content',
		'optima-law-entries'     => 'build',
	);

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
		add_filter( 'optima_admin_nav_group', array( __CLASS__, 'nav_group' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_LAW_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/* ---- Shared ------------------------------------------------------- */

	/** Nest under the site's Optima menu; fall back to Settings. */
	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'optima-build', 'recon-build', 'site-build', 'optima' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	/**
	 * The group a Law screen files under, for an admin kit that does not list
	 * it yet. A kit that lists it is left alone.
	 */
	public static function nav_group( $group, $slug ) {
		$slug = strtok( (string) $slug, '&' );
		if ( isset( self::GROUPS[ $slug ] ) && ( ! method_exists( 'Optima_Admin_UI', 'listed' ) || '' === Optima_Admin_UI::listed( $slug ) ) ) {
			return self::GROUPS[ $slug ];
		}
		return $group;
	}

	/** An operator: someone who can manage the site and is on Optima Build's operator list. */
	public static function operator(): bool {
		return current_user_can( 'manage_options' ) && ( ! class_exists( 'Optima_Hub_Access' ) || Optima_Hub_Access::is_operator() );
	}

	/** A screen's address. admin.php?page= opens it wherever its menu is, even while a form saves. */
	public static function screen_url( string $slug, array $args = array() ): string {
		$base = admin_url( 'admin.php?page=' . $slug );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	public static function url( array $args = array() ): string {
		return self::screen_url( self::SLUG, $args );
	}

	/**
	 * The one status line at the top of a screen (rule 52): what needs doing,
	 * or that nothing does.
	 *
	 * @param string $state '' (all good), 'warn' or 'bad'.
	 */
	public static function status_line( string $state, string $value, string $note = '' ): void {
		echo '<section class="sh-box"><div class="sh-status' . ( '' !== $state ? ' is-' . esc_attr( $state ) : '' ) . '"><span class="sh-dot" aria-hidden="true"></span>';
		echo '<p class="sh-value">' . esc_html( $value ) . '</p>';
		if ( '' !== $note ) {
			echo '<p class="sh-note">' . esc_html( $note ) . '</p>';
		}
		echo '</div></section>';
	}

	/** A message after a save, from a short key in the address. */
	public static function message( array $texts ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message key only.
		$msg = isset( $_GET['optima-law'] ) ? sanitize_key( wp_unslash( (string) $_GET['optima-law'] ) ) : '';
		if ( isset( $texts[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $texts[ $msg ][0] ), esc_html( $texts[ $msg ][1] ) );
		}
	}

	/* ---- Disclaimers -------------------------------------------------- */

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Disclaimers', 'optima-law' ), __( 'Disclaimers', 'optima-law' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		foreach ( array_keys( self::GROUPS ) as $slug ) {
			if ( false !== strpos( $hook, $slug ) ) {
				Optima_Admin_UI::enqueue( OPTIMA_LAW_URL . 'assets/optima-admin.css' );
				return;
			}
		}
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Disclaimers', 'optima-law' ) . '</a>' );
		return $links;
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the disclaimers.', 'optima-law' ), 403 );
		}
		check_admin_referer( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$in    = isset( $_POST['optima_law'] ) ? wp_unslash( (array) $_POST['optima_law'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is cleaned below.
		$state = sanitize_key( (string) ( $in['state'] ?? 'nc' ) );
		// phpcs:enable
		$saved = get_option( Optima_Law_Disclaimers::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$saved['state'] = isset( Optima_Law_Disclaimers::states()[ $state ] ) ? $state : 'nc';
		$places         = array_keys( Optima_Law_Disclaimers::places() );
		foreach ( array_keys( Optima_Law_Disclaimers::lines() ) as $key ) {
			$line  = (array) ( $in['lines'][ $key ] ?? array() );
			$text  = trim( sanitize_textarea_field( (string) ( $line['text'] ?? '' ) ) );
			$start = Optima_Law_Disclaimers::starting( $key, $saved['state'] );

			$saved['lines'][ $key ] = array(
				'text'   => $text === $start ? '' : $text, // Matching the starting wording keeps following it.
				'places' => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $line['places'] ?? array() ) ), $places ) ),
			);
		}
		update_option( Optima_Law_Disclaimers::OPTION, $saved );

		$by      = trim( sanitize_text_field( (string) ( $in['approved_by'] ?? '' ) ) );
		$approve = array_intersect( array_map( 'sanitize_key', (array) ( $in['approve'] ?? array() ) ), array_keys( Optima_Law_Disclaimers::lines() ) );
		$msg     = 'saved';
		if ( $approve && '' === $by ) {
			$msg = 'noname';
		} elseif ( $approve ) {
			$msg = 'approved';
			foreach ( $approve as $key ) {
				if ( ! Optima_Law_Disclaimers::approve( $key, $by, get_current_user_id() ) ) {
					$msg = 'verify'; // Wording still marked for counsel is never approved.
				}
			}
		}
		wp_safe_redirect( add_query_arg( 'optima-law', $msg, self::url() ) );
		exit;
	}

	/** Words and a shape for a line's status: never color alone. */
	private static function pill( string $key ): string {
		$status   = Optima_Law_Disclaimers::status( $key );
		$approval = Optima_Law_Disclaimers::approval( $key );
		if ( 'approved' === $status ) {
			/* translators: 1: approver's name, 2: date */
			$label = sprintf( __( 'Approved by %1$s on %2$s', 'optima-law' ), (string) $approval['by'], wp_date( get_option( 'date_format' ), (int) $approval['at'] ) );
			return '<span class="sh-pill is-on">' . esc_html( $label ) . '</span>';
		}
		if ( Optima_Law_Disclaimers::marked( Optima_Law_Disclaimers::text( $key ) ) ) {
			return '<span class="sh-pill is-received">' . esc_html__( 'Waits for counsel\'s wording', 'optima-law' ) . '</span>';
		}
		if ( 'changed' === $status ) {
			return '<span class="sh-pill is-received">' . esc_html__( 'Changed since approval', 'optima-law' ) . '</span>';
		}
		return '<span class="sh-pill is-progress">' . esc_html__( 'Needs the firm\'s approval', 'optima-law' ) . '</span>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Optima_Law_Disclaimers::settings();
		Optima_Admin_UI::open(
			__( 'Disclaimers', 'optima-law' ),
			__( 'The firm\'s required lines, set once and approved word for word.', 'optima-law' ),
			'',
			self::SLUG
		);
		self::message(
			array(
				'saved'    => array( 'success', __( 'Saved.', 'optima-law' ) ),
				'approved' => array( 'success', __( 'Saved, and the approval is on record.', 'optima-law' ) ),
				'noname'   => array( 'warning', __( 'Saved. To record an approval, add the name of the person at the firm who approved it.', 'optima-law' ) ),
				'verify'   => array( 'warning', __( 'Saved. Wording marked [VERIFY: counsel] can\'t be approved: replace it with counsel\'s wording first.', 'optima-law' ) ),
			)
		);
		$waiting = Optima_Law_Disclaimers::waiting();
		$marked  = Optima_Law_Disclaimers::marked_lines();
		if ( $marked ) {
			/* translators: %d: lines */
			self::status_line( 'warn', sprintf( _n( '%d line on the site still carries the [VERIFY: counsel] placeholder.', '%d lines on the site still carry the [VERIFY: counsel] placeholder.', count( $marked ), 'optima-law' ), count( $marked ) ), __( 'There is no state wording here for this state. Type counsel\'s wording into each marked line, then record the approval.', 'optima-law' ) );
		} elseif ( $waiting ) {
			/* translators: %d: lines waiting. */
			self::status_line( 'warn', sprintf( _n( '%d line waits for the firm\'s approval.', '%d lines wait for the firm\'s approval.', count( $waiting ), 'optima-law' ), count( $waiting ) ), __( 'Every approval is kept as the advertising record.', 'optima-law' ) );
		} else {
			self::status_line( '', __( 'Every line on the site is approved.', 'optima-law' ), __( 'Every approval is kept as the advertising record.', 'optima-law' ) );
		}

		// What needs doing first (rule 52): lines waiting on the firm, then the rest.
		$order = array_keys( Optima_Law_Disclaimers::lines() );
		usort( $order, static fn( $a, $b ) => (int) in_array( $b, $waiting, true ) <=> (int) in_array( $a, $waiting, true ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>

			<?php foreach ( $order as $key ) : ?>
				<?php
				$def  = Optima_Law_Disclaimers::lines()[ $key ];
				$line = $s['lines'][ $key ];
				?>
				<section class="sh-box" id="optima-law-<?php echo esc_attr( $key ); ?>">
					<div class="sh-section-row">
						<h2 class="sh-title"><?php echo esc_html( $def['label'] ); ?></h2>
						<?php echo self::pill( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill(). ?>
					</div>
					<p class="sh-note"><?php echo esc_html( $def['help'] ); ?></p>
					<p><label for="optima-law-text-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Wording', 'optima-law' ); ?></label></p>
					<textarea id="optima-law-text-<?php echo esc_attr( $key ); ?>" class="large-text" rows="3" name="optima_law[lines][<?php echo esc_attr( $key ); ?>][text]" placeholder="<?php echo esc_attr( Optima_Law_Disclaimers::starting( $key, $s['state'] ) ); ?>"><?php echo esc_textarea( $line['text'] ); ?></textarea>
					<p class="sh-note"><?php esc_html_e( 'Leave it blank to use the starting wording.', 'optima-law' ); ?></p>
					<fieldset>
						<legend><?php esc_html_e( 'Shows in', 'optima-law' ); ?></legend>
						<?php foreach ( Optima_Law_Disclaimers::places() as $place => $label ) : ?>
							<label style="display:block;margin:4px 0"><input type="checkbox" name="optima_law[lines][<?php echo esc_attr( $key ); ?>][places][]" value="<?php echo esc_attr( $place ); ?>" <?php checked( in_array( $place, $line['places'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<?php if ( 'approved' !== Optima_Law_Disclaimers::status( $key ) && ! Optima_Law_Disclaimers::marked( Optima_Law_Disclaimers::text( $key ) ) ) : ?>
						<p style="margin-top:12px"><label><input type="checkbox" name="optima_law[approve][]" value="<?php echo esc_attr( $key ); ?>"> <?php esc_html_e( 'The firm approved this exact wording', 'optima-law' ); ?></label></p>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Who approved it', 'optima-law' ); ?></h2>
				<p><label for="optima-law-by"><?php esc_html_e( 'Name of the attorney who approved the wording you ticked', 'optima-law' ); ?></label></p>
				<input id="optima-law-by" type="text" class="regular-text" name="optima_law[approved_by]" autocomplete="off">
				<p class="sh-note"><?php esc_html_e( 'The name, the date and the exact wording are kept together. Changing the wording later sends the line back for approval.', 'optima-law' ); ?></p>
				<p><label for="optima-law-state"><?php esc_html_e( 'Starting wording for the state the firm practices in', 'optima-law' ); ?></label></p>
				<select id="optima-law-state" name="optima_law[state]">
					<?php foreach ( Optima_Law_Disclaimers::states() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['state'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="sh-note"><?php esc_html_e( 'Sets the starting wording shown in gray. It isn\'t legal advice: the firm\'s attorneys decide the final words. Louisiana and South Carolina start from a marked placeholder until counsel\'s wording is typed in.', 'optima-law' ); ?></p>
				<?php submit_button( __( 'Save disclaimers', 'optima-law' ) ); ?>
			</section>
		</form>

		<?php $log = array_slice( Optima_Law_Disclaimers::log(), 0, 10 ); ?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Approval record', 'optima-law' ); ?></h2>
			<?php if ( ! $log ) : ?>
				<p class="sh-note"><?php esc_html_e( 'No approvals yet. Each one you record shows here with its date and exact wording.', 'optima-law' ); ?></p>
			<?php else : ?>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Date', 'optima-law' ); ?></th><th><?php esc_html_e( 'Line', 'optima-law' ); ?></th><th><?php esc_html_e( 'Approved by', 'optima-law' ); ?></th><th><?php esc_html_e( 'Wording', 'optima-law' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $row['at'] ) ); ?></td>
							<td><?php echo esc_html( Optima_Law_Disclaimers::lines()[ $row['line'] ]['label'] ?? $row['line'] ); ?></td>
							<td><?php echo esc_html( (string) $row['by'] ); ?></td>
							<td><?php echo esc_html( (string) $row['text'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<?php Optima_Admin_UI::dev_open(); ?>
			<ul class="sh-list">
				<li><?php esc_html_e( 'Footer lines render in the theme\'s footer slot, or at the end of the page on a theme without slots.', 'optima-law' ); ?></li>
				<li><?php esc_html_e( 'Under every Gravity Form; next to every case result when Case results is on (filter optima_results_disclaimer); under the reviews when Reviews is on.', 'optima-law' ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: 1: shortcode, 2: shortcode */ __( 'Anywhere else with %1$s or %2$s.', 'optima-law' ), '[optima_disclaimers place="footer"]', '[optima_disclaimer line="relationship"]' ) ); ?></li>
				<li><?php esc_html_e( 'A theme places the footer lines itself with add_theme_support( \'optima-law-footer\' ) and optima_law_disclaimers( \'footer\' ).', 'optima-law' ); ?></li>
			</ul>
		<?php Optima_Admin_UI::dev_close(); ?>
		<?php
		Optima_Admin_UI::close();
	}
}
