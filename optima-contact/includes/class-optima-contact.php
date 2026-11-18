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

	/**
	 * The event each tap sends (docs/plugin-first.md rule 50), reported by
	 * Optima Analytics. The old contact_* name goes out too only on sites that
	 * turn on OPTIMA_ANALYTICS_LEGACY_EVENTS.
	 */
	const EVENTS = array(
		'call' => 'call_click',
		'text' => 'text_click',
		'book' => 'booking_click',
	);

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// Plugin first: render in the theme's body-end slot; themes without slots still get the bar from wp_footer.
		add_action( 'optima_slot_body-end', array( __CLASS__, 'render_once' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_once' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		// Release 2: the "What happens next" block (slot next-steps) and office directions.
		add_action( 'optima_slot_next-steps', array( __CLASS__, 'slot_steps' ) );
		add_shortcode( 'optima_next_steps', array( __CLASS__, 'shortcode_steps' ) );
		add_shortcode( 'optima_office', array( __CLASS__, 'shortcode_office' ) );
	}

	/** @var bool The bar prints once per page, whichever hook reaches it first. */
	private static $rendered = false;

	public static function render_once(): void {
		if ( self::$rendered ) {
			return;
		}
		self::$rendered = true;
		self::render();
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
			// Text starts off on a new site: it turns on only once someone answers that the
			// number takes texts and is monitored (Call buttons). Saved settings keep their value.
			'text_off'  => 1,
			'book_url'  => function_exists( 'optima_booking_url' ) ? (string) optima_booking_url() : '',
			'label'     => "Let's talk",
			'title'     => "Let's talk",
			'subtitle'  => "Pick whatever's easiest for you.",
			'text_note' => '',
			'book_note' => '',
			'photos'    => array(),
			'hide_on'   => 'card, links, review, book',
			// What happens next: blank steps use the starting wording (step_defaults()).
			'step_1'         => '',
			'step_2'         => '',
			'step_3'         => '',
			'steps_free'     => 0,  // The firm offers free consultations; only then does step 2 say so.
			'steps_response' => '', // A reply window the firm keeps ("within 1 business hour"); printed only when set.
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, false );
		if ( is_array( $saved ) && ! array_key_exists( 'text_off', $saved ) ) {
			$saved['text_off'] = 0; // Saved before the Text switch existed: Text stays as it was.
		}
		$s = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		// The phone is typed once, on Your business, Details (rule 45). The number this
		// screen saved before release 3 stays in the option and is used only while
		// Details has none.
		$biz = self::business_phone();
		if ( '' !== $biz ) {
			$s['phone'] = $biz;
		}
		return $s;
	}

	/** The phone from Your business, Details (the optima_business option), or ''. */
	public static function business_phone(): string {
		$biz = get_option( 'optima_business', array() );
		return is_array( $biz ) ? trim( (string) ( $biz['phone'] ?? '' ) ) : '';
	}

	/**
	 * Once (release 3): a phone typed on Call buttons and missing from Details
	 * moves to Details, so it is typed in one place from now on. The old value
	 * stays in this plugin's option; nothing is deleted.
	 */
	public static function migrate(): void {
		if ( get_option( 'optima_contact_r3_phone' ) ) {
			return;
		}
		$saved = get_option( self::OPTION, array() );
		$old   = is_array( $saved ) ? trim( (string) ( $saved['phone'] ?? '' ) ) : '';
		if ( '' !== $old && '' === self::business_phone() ) {
			$biz          = get_option( 'optima_business', array() );
			$biz          = is_array( $biz ) ? $biz : array();
			$biz['phone'] = $old;
			update_option( 'optima_business', $biz );
		}
		update_option( 'optima_contact_r3_phone', time(), false );
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
		<a class="odc-btn" href="<?php echo esc_attr( 'tel:' . $a['call'] ); ?>" data-optima-track="call_click" data-optima-location="call_bar" data-optima-legacy="contact_call" data-optima-label="Call bar"><?php echo self::icon( 'call' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'Call', 'optima-contact' ); ?></span></a>
		<?php endif; ?>
		<?php if ( '' !== $a['text'] ) : ?>
		<a class="odc-btn" href="<?php echo esc_attr( 'sms:' . $a['text'] ); ?>" data-optima-track="text_click" data-optima-location="call_bar" data-optima-legacy="contact_text" data-optima-label="Call bar"><?php echo self::icon( 'text' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'Text', 'optima-contact' ); ?></span></a>
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
			<li><a class="odc-opt<?php echo 'book' === $opt[0] ? ' is-book' : ''; ?>" href="<?php echo 'book' === $opt[0] ? esc_url( $opt[1] ) : esc_attr( $opt[1] ); ?>" data-optima-track="<?php echo esc_attr( self::EVENTS[ $opt[0] ] ); ?>" data-optima-location="lets_talk_sheet" data-optima-legacy="contact_<?php echo esc_attr( $opt[0] ); ?>" data-optima-label="Lets talk sheet">
				<span class="odc-n" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span>
				<span class="odc-txt"><strong><?php echo esc_html( $opt[2] ); ?></strong><span><?php echo esc_html( $opt[3] ); ?></span></span>
			</a></li>
			<?php endforeach; ?>
		</ol>
	</dialog>
</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* What happens next (release 2 contract, slot next-steps)             */
	/* ------------------------------------------------------------------ */

	/**
	 * The starting wording for each step. It promises nothing: no reply time
	 * and no free call unless the firm sets them on Call buttons.
	 */
	public static function step_defaults( bool $free ): array {
		return array(
			1 => __( 'We read your message and call you back.', 'optima-contact' ),
			2 => $free
				? __( 'A member of our intake team asks a few questions about what happened. The call is free and private.', 'optima-contact' )
				: __( 'A member of our intake team asks a few questions about what happened.', 'optima-contact' ),
			3 => __( 'If we can help, we set up a time with our team. If we cannot, we tell you so.', 'optima-contact' ),
		);
	}

	/** The three steps the site prints, and the reply window ('' when the firm set none). */
	public static function steps(): array {
		$s        = self::settings();
		$defaults = self::step_defaults( ! empty( $s['steps_free'] ) );
		$response = self::clean_response( (string) $s['steps_response'] );
		$steps    = array();
		foreach ( array( 1, 2, 3 ) as $n ) {
			$typed       = trim( (string) ( $s[ 'step_' . $n ] ?? '' ) );
			$steps[ $n ] = '' !== $typed ? $typed : $defaults[ $n ];
		}
		// The reply window joins the starting first step; typed wording gets it as its own line.
		$joined = false;
		if ( '' !== $response && $steps[1] === $defaults[1] ) {
			$steps[1] = rtrim( $defaults[1], '.' ) . ' ' . $response . '.';
			$joined   = true;
		}
		return array(
			'steps'    => $steps,
			'response' => $joined ? '' : $response,
		);
	}

	/** A reply window, or '' when it is empty or says "guarantee" (the site never guarantees a reply time). */
	public static function clean_response( string $text ): string {
		$text = trim( rtrim( sanitize_text_field( $text ), '.' ) );
		return ( '' === $text || preg_match( '/guarant/i', $text ) ) ? '' : $text;
	}

	/** The block itself. $args: variant (row | timeline), heading (text, or '' for none). */
	public static function steps_html( array $args = array() ): string {
		$variant = in_array( $args['variant'] ?? '', array( 'row', 'timeline' ), true ) ? (string) $args['variant'] : 'row';
		$heading = isset( $args['heading'] ) ? trim( (string) $args['heading'] ) : '';
		$data    = self::steps();
		ob_start();
		?>
<div class="optima-next-steps is-<?php echo esc_attr( $variant ); ?>">
	<?php if ( '' !== $heading ) : ?><h2 class="optima-next-steps__title"><?php echo esc_html( $heading ); ?></h2><?php endif; ?>
	<ol class="optima-next-steps__list">
		<?php foreach ( $data['steps'] as $n => $text ) : ?>
		<li class="optima-next-steps__step"><span class="optima-next-steps__n" aria-hidden="true"><?php echo esc_html( (string) $n ); ?></span><p><?php echo esc_html( $text ); ?></p></li>
		<?php endforeach; ?>
	</ol>
	<?php if ( '' !== $data['response'] ) : ?>
	<p class="optima-next-steps__note"><?php /* translators: %s: the firm's reply window, for example "within 1 business hour" */ echo esc_html( sprintf( __( 'We usually reply %s.', 'optima-contact' ), $data['response'] ) ); ?></p>
	<?php endif; ?>
</div>
		<?php
		return self::block_css() . (string) ob_get_clean();
	}

	/**
	 * The slot: the theme prints the section title (contract section 3), so
	 * the block prints none unless the theme passes 'heading'.
	 */
	public static function slot_steps( $args = array() ): void {
		echo self::steps_html( is_array( $args ) ? $args : array() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in steps_html().
	}

	/** [optima_next_steps variant="timeline" heading="What happens next"] for pages without slots. */
	public static function shortcode_steps( $atts ): string {
		$atts = shortcode_atts(
			array(
				'variant' => function_exists( 'optima_layout' ) ? (string) optima_layout( 'blocks.next_steps', 'row' ) : 'row',
				'heading' => __( 'What happens next', 'optima-contact' ),
			),
			$atts,
			'optima_next_steps'
		);
		return self::steps_html( $atts );
	}

	/* ------------------------------------------------------------------ */
	/* Offices and directions                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * The firm's offices in the release 2 shape. Read from Optima Core's
	 * office list (option optima_core_offices, filled by Optima Build), or the
	 * theme's optima-setup.json offices[] the way Build reads it while Core
	 * has none. An empty list means the site has one business node, as before.
	 */
	public static function offices(): array {
		static $list = null;
		if ( null !== $list ) {
			return $list;
		}
		$raw = get_option( 'optima_core_offices', array() );
		$raw = is_array( $raw ) ? $raw : array();
		if ( ! $raw ) {
			if ( class_exists( 'Optima_Hub_Setup', false ) ) {
				$raw = (array) ( Optima_Hub_Setup::data()['offices'] ?? array() );
			} else {
				foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
					$path = trailingslashit( $dir ) . 'optima-setup.json';
					if ( is_readable( $path ) ) {
						$json = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
						$raw  = is_array( $json['offices'] ?? null ) ? $json['offices'] : array();
						break;
					}
				}
			}
		}
		$list = array();
		foreach ( $raw as $key => $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$id          = sanitize_key( (string) ( $o['id'] ?? ( is_string( $key ) ? $key : '' ) ) );
			$list[ $id ] = $o + array( 'id' => $id );
		}
		return $list;
	}

	/** One office by id; 'primary' or '' gives the primary office (else the first). */
	public static function office( string $id = '' ): array {
		$all = self::offices();
		if ( '' !== $id && 'primary' !== $id ) {
			return $all[ sanitize_key( $id ) ] ?? array();
		}
		foreach ( $all as $o ) {
			if ( ! empty( $o['primary'] ) ) {
				return $o;
			}
		}
		return $all ? (array) reset( $all ) : array();
	}

	/** The office's street address on one line ('' when it has none). */
	public static function address_line( array $o ): string {
		$street = trim( (string) ( $o['street'] ?? '' ) . ( ! empty( $o['suite'] ) ? ', ' . $o['suite'] : '' ) );
		$region = trim( (string) ( $o['state'] ?? '' ) . ' ' . (string) ( $o['zip'] ?? '' ) );
		return implode( ', ', array_filter( array( $street, (string) ( $o['city'] ?? '' ), $region ), static fn( $v ) => '' !== trim( $v ) ) );
	}

	/**
	 * The Get directions address: Google Maps directions to the office's
	 * Place ID (the listing itself), with the street address as the
	 * destination text. '' when the office has neither.
	 */
	public static function directions_url( array $o ): string {
		$address = self::address_line( $o );
		$place   = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $o['place_id'] ?? '' ) );
		if ( '' === $address && '' === $place ) {
			return '';
		}
		$query = array(
			'api'         => '1',
			'destination' => '' !== $address ? $address : (string) ( $o['name'] ?? '' ),
		);
		if ( '' !== $place ) {
			$query['destination_place_id'] = $place;
		}
		return 'https://www.google.com/maps/dir/?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/** The Get directions link, tracked as directions_click (rule 50). */
	public static function directions_link( array $o, string $location = 'office' ): string {
		$url = self::directions_url( $o );
		if ( '' === $url ) {
			return '';
		}
		return '<a class="optima-directions" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" data-optima-track="directions_click" data-optima-location="' . esc_attr( $location ) . '" data-optima-label="' . esc_attr( (string) ( $o['id'] ?? '' ) ) . '">' . esc_html__( 'Get directions', 'optima-contact' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens Google Maps)', 'optima-contact' ) . '</span></a>';
	}

	/**
	 * [optima_office id="tampa"]: the office's name, address and Get
	 * directions link, for pages without the office-card slot. Prints nothing
	 * for an office with no address.
	 */
	public static function shortcode_office( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 'primary' ), $atts, 'optima_office' );
		$o    = self::office( (string) $atts['id'] );
		$line = $o ? self::address_line( $o ) : '';
		if ( '' === $line ) {
			return '';
		}
		$html  = '<div class="optima-office">';
		$html .= ! empty( $o['name'] ) ? '<p class="optima-office__name">' . esc_html( (string) $o['name'] ) . '</p>' : '';
		$html .= '<address class="optima-office__address">' . esc_html( $line ) . '</address>';
		$html .= '<p class="optima-office__go">' . self::directions_link( $o, 'office_shortcode' ) . '</p>';
		return self::block_css() . $html . '</div>';
	}

	/** @var bool The block styles print once per page. */
	private static $css_done = false;

	/**
	 * The styles for the steps block and the office line: tokens only
	 * (--optima-*), printed once beside the first block on the page.
	 */
	private static function block_css(): string {
		if ( self::$css_done ) {
			return '';
		}
		self::$css_done = true;
		$css = (string) @file_get_contents( plugin_dir_path( OPTIMA_CONTACT_FILE ) . 'assets/next-steps.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return '' === $css ? '' : '<style id="optima-next-steps-css">' . wp_strip_all_tags( $css ) . '</style>';
	}
}
