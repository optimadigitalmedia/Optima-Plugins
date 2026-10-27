<?php
/**
 * The call bar and the "Let's talk" sheet on the front end.
 *
 * Design: Optima features DESIGN.md 5.9 (screen 4b). The bar shows on phones
 * only. "Book a time" opens the site's booking page rather than offering
 * times inline, because the booking calendars we use do not share open
 * times with other sites.
 *
 * @package Optima_Contact
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Contact {

	const OPTION = 'optima_contact';

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	public static function defaults(): array {
		$phone = '';
		if ( class_exists( 'Optima_Core_Business' ) ) {
			$biz   = (array) Optima_Core_Business::get();
			$phone = (string) ( $biz['phone'] ?? '' );
		}
		return array(
			'enabled'   => 1,
			'phone'     => $phone,
			'text'      => '',
			'text_off'  => 0,
			'book_url'  => function_exists( 'optima_booking_url' ) ? (string) optima_booking_url() : '',
			'label'     => "Let's talk",
			'title'     => "Let's talk",
			'subtitle'  => "Pick whatever's easiest for you.",
			'text_note' => '',
			'book_note' => '',
			'photos'    => array(),
			'hide_on'   => 'card, links, review, book',
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/** A dialable number: digits only, with +1 added to ten-digit US numbers. */
	public static function dial( string $number ): string {
		$digits = preg_replace( '/\D+/', '', $number );
		if ( '' === $digits ) {
			return '';
		}
		if ( 10 === strlen( $digits ) ) {
			$digits = '1' . $digits;
		}
		return '+' . $digits;
	}

	/** What the bar offers on this page: call, text and book links ('' when off). */
	public static function actions(): array {
		$s    = self::settings();
		$call = self::dial( (string) $s['phone'] );
		$text = $s['text_off'] ? '' : self::dial( '' !== trim( (string) $s['text'] ) ? (string) $s['text'] : (string) $s['phone'] );
		return array(
			'call' => $call,
			'text' => $text,
			'book' => esc_url_raw( (string) $s['book_url'] ),
		);
	}

	/** Should the bar show on the page being viewed? */
	public static function active(): bool {
		static $active = null;
		if ( null !== $active ) {
			return $active;
		}
		$s      = self::settings();
		$a      = self::actions();
		$active = ! is_admin() && ! empty( $s['enabled'] ) && ( '' !== $a['call'] || '' !== $a['book'] );
		if ( $active ) {
			$path  = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$first = strtolower( explode( '/', $path )[0] ?? '' );
			$hide  = array_filter( array_map( static fn( $p ) => strtolower( trim( $p, " /\t" ) ), explode( ',', (string) $s['hide_on'] ) ) );
			if ( '' !== $first && in_array( $first, $hide, true ) ) {
				$active = false;
			}
		}
		/** Filter: turn the bar off (or on) for the current page. */
		$active = (bool) apply_filters( 'optima_contact_active', $active );
		return $active;
	}

	public static function body_class( array $classes ): array {
		if ( self::active() ) {
			$classes[] = 'odc-on';
		}
		return $classes;
	}

	public static function assets(): void {
		if ( ! self::active() ) {
			return;
		}
		// The bar is on screen from the first paint, so its styles can't load late,
		// and a separate file held up the first paint (PageSpeed: 150 ms). The
		// stylesheet is under 5 KB, so it prints inline in <head> instead.
		$css = (string) @file_get_contents( plugin_dir_path( OPTIMA_CONTACT_FILE ) . 'assets/contact.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' !== $css && apply_filters( 'optima_contact_inline_css', true ) ) {
			wp_register_style( 'optima-contact', false, array(), OPTIMA_CONTACT_VERSION );
			wp_enqueue_style( 'optima-contact' );
			wp_add_inline_style( 'optima-contact', $css );
		} else {
			wp_enqueue_style( 'optima-contact', OPTIMA_CONTACT_URL . 'assets/contact.css', array(), OPTIMA_CONTACT_VERSION );
		}
		wp_enqueue_script( 'optima-contact', OPTIMA_CONTACT_URL . 'assets/contact.js', array(), OPTIMA_CONTACT_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
	}

	private static function icon( string $name ): string {
		$paths = array(
			'call' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
			'text' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
		);
		return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	public static function render(): void {
		if ( ! self::active() ) {
			return;
		}
		$s = self::settings();
		$a = self::actions();

		$options = array();
		if ( '' !== $a['call'] ) {
			$options[] = array( 'call', $a['call'] ? 'tel:' . $a['call'] : '', __( 'Call us now', 'optima-contact' ), (string) $s['phone'] );
		}
		if ( '' !== $a['text'] ) {
			$options[] = array( 'text', 'sms:' . $a['text'], __( 'Send a text', 'optima-contact' ), '' !== trim( (string) $s['text_note'] ) ? (string) $s['text_note'] : __( 'Opens a message on your phone.', 'optima-contact' ) );
		}
		if ( '' !== $a['book'] ) {
			$options[] = array( 'book', $a['book'], __( 'Book a time', 'optima-contact' ), '' !== trim( (string) $s['book_note'] ) ? (string) $s['book_note'] : __( 'Pick a time that works for you.', 'optima-contact' ) );
		}
		$photos = array_slice( array_filter( array_map( 'absint', (array) $s['photos'] ) ), 0, 3 );
		?>
<div class="scc" data-scc>
	<nav class="odc-bar" aria-label="<?php esc_attr_e( 'Contact', 'optima-contact' ); ?>">
		<?php if ( '' !== $a['call'] ) : ?>
		<a class="odc-btn" href="<?php echo esc_attr( 'tel:' . $a['call'] ); ?>" data-optima-track="contact_call" data-optima-label="Call bar"><?php echo self::icon( 'call' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'Call', 'optima-contact' ); ?></span></a>
		<?php endif; ?>
		<?php if ( '' !== $a['text'] ) : ?>
		<a class="odc-btn" href="<?php echo esc_attr( 'sms:' . $a['text'] ); ?>" data-optima-track="contact_text" data-optima-label="Call bar"><?php echo self::icon( 'text' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'Text', 'optima-contact' ); ?></span></a>
		<?php endif; ?>
		<button class="odc-main" type="button" aria-haspopup="dialog" aria-controls="odc-sheet" data-odc-open><?php echo esc_html( (string) $s['label'] ); ?></button>
	</nav>
	<dialog id="odc-sheet" class="odc-sheet" aria-labelledby="odc-title">
		<div class="odc-grab" aria-hidden="true"></div>
		<div class="odc-head">
			<?php if ( $photos ) : ?>
			<span class="odc-faces" aria-hidden="true">
				<?php foreach ( $photos as $id ) { echo wp_get_attachment_image( $id, array( 96, 96 ), false, array( 'alt' => '', 'loading' => 'lazy' ) ); } ?>
			</span>
			<?php endif; ?>
			<div class="odc-titles">
				<h2 id="odc-title"><?php echo esc_html( (string) $s['title'] ); ?></h2>
				<?php if ( '' !== trim( (string) $s['subtitle'] ) ) : ?><p><?php echo esc_html( (string) $s['subtitle'] ); ?></p><?php endif; ?>
			</div>
			<button class="odc-close" type="button" data-odc-close aria-label="<?php esc_attr_e( 'Close', 'optima-contact' ); ?>"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
		</div>
		<ol class="odc-opts">
			<?php foreach ( $options as $i => $opt ) : ?>
			<li><a class="odc-opt<?php echo 'book' === $opt[0] ? ' is-book' : ''; ?>" href="<?php echo 'book' === $opt[0] ? esc_url( $opt[1] ) : esc_attr( $opt[1] ); ?>" data-optima-track="contact_<?php echo esc_attr( $opt[0] ); ?>" data-optima-label="Lets talk sheet">
				<span class="odc-n" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span>
				<span class="odc-txt"><strong><?php echo esc_html( $opt[2] ); ?></strong><span><?php echo esc_html( $opt[3] ); ?></span></span>
			</a></li>
			<?php endforeach; ?>
		</ol>
	</dialog>
</div>
		<?php
	}
}
