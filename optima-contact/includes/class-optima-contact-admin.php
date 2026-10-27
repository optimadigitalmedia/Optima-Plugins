<?php
/**
 * Build -> Call buttons: the numbers, the booking link and the wording.
 *
 * @package Optima_Contact
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Contact_Admin {

	const SLUG = 'optima-contact';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_CONTACT_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/** Nest under the site's Build section; fall back to Settings. */
	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'optima-build', 'recon-build', 'site-build', 'optima' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	public static function url(): string {
		return 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Call buttons', 'optima-contact' ), __( 'Call buttons', 'optima-contact' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		Optima_Admin_UI::enqueue( OPTIMA_CONTACT_URL . 'assets/optima-admin.css' );
		wp_enqueue_media();
		wp_enqueue_script( 'optima-contact-admin', OPTIMA_CONTACT_URL . 'assets/admin.js', array(), OPTIMA_CONTACT_VERSION, true );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'optima-contact' ) . '</a>' );
		return $links;
	}

	public static function register(): void {
		register_setting( 'optima_contact_group', Optima_Contact::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in     = (array) $in;
		$photos = array_slice( array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $in['photos'] ?? '' ) ) ) ) ), 0, 3 );
		$out    = array(
			'enabled'   => ! empty( $in['enabled'] ) ? 1 : 0,
			'phone'     => sanitize_text_field( (string) ( $in['phone'] ?? '' ) ),
			'text'      => sanitize_text_field( (string) ( $in['text'] ?? '' ) ),
			'text_off'  => ! empty( $in['text_off'] ) ? 1 : 0,
			'book_url'  => esc_url_raw( trim( (string) ( $in['book_url'] ?? '' ) ) ),
			'label'     => sanitize_text_field( (string) ( $in['label'] ?? '' ) ),
			'title'     => sanitize_text_field( (string) ( $in['title'] ?? '' ) ),
			'subtitle'  => sanitize_text_field( (string) ( $in['subtitle'] ?? '' ) ),
			'text_note' => sanitize_text_field( (string) ( $in['text_note'] ?? '' ) ),
			'book_note' => sanitize_text_field( (string) ( $in['book_note'] ?? '' ) ),
			'photos'    => $photos,
			'hide_on'   => sanitize_text_field( (string) ( $in['hide_on'] ?? '' ) ),
		);
		$d = Optima_Contact::defaults();
		foreach ( array( 'label', 'title' ) as $k ) {
			if ( '' === $out[ $k ] ) {
				$out[ $k ] = $d[ $k ];
			}
		}
		if ( '' !== $out['phone'] && '' === Optima_Contact::dial( $out['phone'] ) ) {
			add_settings_error( Optima_Contact::OPTION, 'phone', __( 'That phone number has no digits in it.', 'optima-contact' ) );
		}
		return $out;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Optima_Contact::settings();
		$o = Optima_Contact::OPTION;
		$a = Optima_Contact::actions();
		$n = count( array_filter( $a ) );
		Optima_Admin_UI::open(
			__( 'Call buttons', 'optima-contact' ),
			__( 'A bar pinned to the bottom of the screen on phones, so a visitor can call, text or book a time with one tap from any page.', 'optima-contact' ),
			__( 'Call buttons', 'optima-contact' )
		);
		settings_errors( $o );
		?>
		<div class="sh-grid sh-grid-3">
			<section class="sh-box sh-check is-<?php echo ( $s['enabled'] && $n ) ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'On phones', 'optima-contact' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( ( $s['enabled'] && $n ) ? __( 'Showing', 'optima-contact' ) : __( 'Off', 'optima-contact' ) ); ?></p>
				<p class="sh-note"><?php echo esc_html( $n ? sprintf( /* translators: %d: number of options */ _n( '%d option in the sheet.', '%d options in the sheet.', $n, 'optima-contact' ), $n ) : __( 'Add a phone number or a booking link below.', 'optima-contact' ) ); ?></p>
			</section>
			<section class="sh-box sh-check is-good">
				<p class="sh-label"><?php esc_html_e( 'On computers', 'optima-contact' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php esc_html_e( 'Hidden', 'optima-contact' ); ?></p>
				<p class="sh-note"><?php esc_html_e( 'Wide screens already show the phone number and booking button in the header.', 'optima-contact' ); ?></p>
			</section>
			<section class="sh-box sh-check is-good">
				<p class="sh-label"><?php esc_html_e( 'Taps', 'optima-contact' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( class_exists( 'Optima_Analytics' ) ? __( 'Counted', 'optima-contact' ) : __( 'Not counted', 'optima-contact' ) ); ?></p>
				<p class="sh-note"><?php esc_html_e( 'With Optima Analytics on, each tap is sent as contact_call, contact_text, contact_book or contact_open.', 'optima-contact' ); ?></p>
			</section>
		</div>

		<form method="post" action="options.php" class="sh-box">
			<?php settings_fields( 'optima_contact_group' ); ?>
			<h2 class="sh-title"><?php esc_html_e( 'Buttons', 'optima-contact' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Show the bar', 'optima-contact' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Show the call bar on phones', 'optima-contact' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="odc-phone"><?php esc_html_e( 'Phone number', 'optima-contact' ); ?></label></th>
					<td><input id="odc-phone" type="text" class="regular-text" name="<?php echo esc_attr( $o ); ?>[phone]" value="<?php echo esc_attr( $s['phone'] ); ?>" placeholder="(919) 555-0100">
						<p class="description"><?php esc_html_e( 'Shown in the sheet exactly as typed. Use the same format as the rest of the site.', 'optima-contact' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="odc-text"><?php esc_html_e( 'Texting number', 'optima-contact' ); ?></label></th>
					<td><input id="odc-text" type="text" class="regular-text" name="<?php echo esc_attr( $o ); ?>[text]" value="<?php echo esc_attr( $s['text'] ); ?>" placeholder="<?php esc_attr_e( 'Same as the phone number', 'optima-contact' ); ?>">
						<p><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[text_off]" value="1" <?php checked( $s['text_off'] ); ?>> <?php esc_html_e( 'This number cannot receive texts, so leave Text off', 'optima-contact' ); ?></label></p>
						<p class="description"><?php esc_html_e( 'Landlines usually cannot receive texts. Turn Text off unless someone reads texts at this number.', 'optima-contact' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="odc-book"><?php esc_html_e( 'Booking link', 'optima-contact' ); ?></label></th>
					<td><input id="odc-book" type="url" class="regular-text code" name="<?php echo esc_attr( $o ); ?>[book_url]" value="<?php echo esc_attr( $s['book_url'] ); ?>" placeholder="https://">
						<p class="description"><?php esc_html_e( 'The page or calendar link where people pick a time. Leave blank to hide "Book a time".', 'optima-contact' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="odc-hide"><?php esc_html_e( 'Hide on', 'optima-contact' ); ?></label></th>
					<td><input id="odc-hide" type="text" class="regular-text code" name="<?php echo esc_attr( $o ); ?>[hide_on]" value="<?php echo esc_attr( $s['hide_on'] ); ?>">
						<p class="description"><?php esc_html_e( 'Pages that already have their own buttons, by the first part of their address, separated by commas.', 'optima-contact' ); ?></p></td>
				</tr>
			</table>

			<h2 class="sh-title"><?php esc_html_e( 'Wording', 'optima-contact' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="odc-label"><?php esc_html_e( 'Main button', 'optima-contact' ); ?></label></th>
					<td><input id="odc-label" type="text" class="regular-text" name="<?php echo esc_attr( $o ); ?>[label]" value="<?php echo esc_attr( $s['label'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="odc-title"><?php esc_html_e( 'Sheet title', 'optima-contact' ); ?></label></th>
					<td><input id="odc-title" type="text" class="regular-text" name="<?php echo esc_attr( $o ); ?>[title]" value="<?php echo esc_attr( $s['title'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="odc-sub"><?php esc_html_e( 'Line under the title', 'optima-contact' ); ?></label></th>
					<td><input id="odc-sub" type="text" class="large-text" name="<?php echo esc_attr( $o ); ?>[subtitle]" value="<?php echo esc_attr( $s['subtitle'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="odc-tnote"><?php esc_html_e( 'Under "Send a text"', 'optima-contact' ); ?></label></th>
					<td><input id="odc-tnote" type="text" class="large-text" name="<?php echo esc_attr( $o ); ?>[text_note]" value="<?php echo esc_attr( $s['text_note'] ); ?>" placeholder="<?php esc_attr_e( 'Opens a message on your phone.', 'optima-contact' ); ?>">
						<p class="description"><?php esc_html_e( 'Only promise a reply time you will keep.', 'optima-contact' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="odc-bnote"><?php esc_html_e( 'Under "Book a time"', 'optima-contact' ); ?></label></th>
					<td><input id="odc-bnote" type="text" class="large-text" name="<?php echo esc_attr( $o ); ?>[book_note]" value="<?php echo esc_attr( $s['book_note'] ); ?>" placeholder="<?php esc_attr_e( 'Pick a time that works for you.', 'optima-contact' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Photos in the sheet', 'optima-contact' ); ?></th>
					<td>
						<input type="hidden" id="odc-photos" name="<?php echo esc_attr( $o ); ?>[photos]" value="<?php echo esc_attr( implode( ',', (array) $s['photos'] ) ); ?>">
						<div id="odc-photo-list" class="odc-photo-list" style="display:flex;gap:8px;margin-bottom:8px">
							<?php foreach ( (array) $s['photos'] as $id ) { echo wp_get_attachment_image( (int) $id, array( 48, 48 ), false, array( 'style' => 'width:48px;height:48px;border-radius:50%;object-fit:cover' ) ); } ?>
						</div>
						<button type="button" class="button" id="odc-pick"><?php esc_html_e( 'Choose photos', 'optima-contact' ); ?></button>
						<button type="button" class="button-link" id="odc-clear"><?php esc_html_e( 'Remove photos', 'optima-contact' ); ?></button>
						<p class="description"><?php esc_html_e( 'Up to three head shots of the people who answer. Optional.', 'optima-contact' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
		Optima_Admin_UI::close();
	}
}
