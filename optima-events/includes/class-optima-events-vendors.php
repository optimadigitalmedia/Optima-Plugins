<?php
/**
 * Vendors: people who sell at an event apply on the site, the owner approves
 * them, and approved vendors show on the event page as its vendor list.
 *
 *   [optima_vendor_apply event="summit-2027"]   the application form
 *   [optima_vendors event="summit-2027"]        the vendor list (without event, every vendor)
 *
 * A vendor is a post the owner edits like any other: name, description and
 * logo are the post's own; city, website, what they sell and the events they
 * are at sit in a box. Contact details (who to write to, email, phone, power
 * and space needs) are for the owner only and never reach the site. Vendors
 * have no account to sign in to; the owner writes to them.
 *
 * @package Optima_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Events_Vendors {

	const TYPE        = 'optima_vendor';
	const META        = '_optima_vendor';
	const PRIVATE     = '_optima_vendor_private';
	const NONCE       = '_se_vendor_box';
	const MIN_SECONDS = 4;

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_shortcode( 'optima_vendor_apply', array( __CLASS__, 'apply_shortcode' ) );
		add_shortcode( 'optima_vendors', array( __CLASS__, 'list_shortcode' ) );
		add_action( 'admin_post_nopriv_optima_vendor_apply', array( __CLASS__, 'apply' ) );
		add_action( 'admin_post_optima_vendor_apply', array( __CLASS__, 'apply' ) );
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save_box' ) );
		// After the edit screen's boxes are saved, so the email can name the events just ticked.
		add_action( 'wp_after_insert_post', array( __CLASS__, 'approved' ), 10, 4 );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'optima_events_needs_you', array( __CLASS__, 'needs_you' ) );
		add_filter( 'the_content', array( __CLASS__, 'on_event_page' ), 22 );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/** Vendors have no pages of their own; they appear in lists on event pages. */
	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'             => array(
					'name'               => __( 'Vendors', 'optima-events' ),
					'singular_name'      => __( 'Vendor', 'optima-events' ),
					'add_new'            => __( 'Add vendor', 'optima-events' ),
					'add_new_item'       => __( 'Add vendor', 'optima-events' ),
					'edit_item'          => __( 'Edit vendor', 'optima-events' ),
					'search_items'       => __( 'Search vendors', 'optima-events' ),
					'all_items'          => __( 'Vendors', 'optima-events' ),
					'not_found'          => __( 'No vendors yet.', 'optima-events' ),
					'not_found_in_trash' => __( 'No vendors in the trash.', 'optima-events' ),
					'featured_image'     => __( 'Logo or photo', 'optima-events' ),
					'set_featured_image' => __( 'Set logo or photo', 'optima-events' ),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => false,
				'publicly_queryable' => false,
				'exclude_from_search' => true,
				'supports'           => array( 'title', 'editor', 'thumbnail' ),
			)
		);
	}

	/** The Events edit-screen look on the vendor screens too. */
	public static function assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::TYPE === $screen->post_type ) {
			Optima_Admin_UI::enqueue( OPTIMA_EVENTS_URL . 'assets/optima-admin.css' );
			wp_enqueue_style( 'optima-events-admin', OPTIMA_EVENTS_URL . 'assets/events-admin.css', array( 'optima-admin-kit' ), OPTIMA_EVENTS_VERSION );
		}
	}

	/** Keep the Events menu open while editing a vendor. */
	public static function highlight( $file ) {
		global $submenu_file;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::TYPE === $screen->post_type ) {
			$submenu_file = 'edit.php?post_type=' . self::TYPE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return Optima_Events_Admin::SLUG;
		}
		return $file;
	}

	/* ---------- the data ---------- */

	/** @return array{city:string,website:string,products:string,events:int[]} */
	public static function vendor( int $id ): array {
		$v = get_post_meta( $id, self::META, true );
		$v = is_array( $v ) ? $v : array();
		return array(
			'city'     => (string) ( $v['city'] ?? '' ),
			'website'  => (string) ( $v['website'] ?? '' ),
			'products' => (string) ( $v['products'] ?? '' ),
			'events'   => array_values( array_filter( array_map( 'intval', (array) ( $v['events'] ?? array() ) ) ) ),
		);
	}

	public static function save_vendor( int $id, array $in ): void {
		update_post_meta(
			$id,
			self::META,
			array(
				'city'     => sanitize_text_field( (string) ( $in['city'] ?? '' ) ),
				'website'  => esc_url_raw( trim( (string) ( $in['website'] ?? '' ) ), array( 'http', 'https' ) ),
				'products' => sanitize_textarea_field( (string) ( $in['products'] ?? '' ) ),
				'events'   => array_values( array_filter( array_map( 'intval', (array) ( $in['events'] ?? array() ) ) ) ),
			)
		);
	}

	/** @return array{contact:string,pronouns:string,email:string,phone:string,power:string,space:string,notes:string,applied:string} */
	public static function private_info( int $id ): array {
		$p = get_post_meta( $id, self::PRIVATE, true );
		$p = is_array( $p ) ? $p : array();
		$out = array();
		foreach ( array( 'contact', 'pronouns', 'email', 'phone', 'power', 'space', 'notes', 'applied' ) as $k ) {
			$out[ $k ] = (string) ( $p[ $k ] ?? '' );
		}
		return $out;
	}

	/** Approved vendors, in name order; for one event when $event is set. */
	public static function ids( int $event = 0 ): array {
		$ids = array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 300,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'fields'         => 'ids',
				)
			)
		);
		return $event ? array_values( array_filter( $ids, static fn( $id ) => in_array( $event, self::vendor( $id )['events'], true ) ) ) : $ids;
	}

	/* ---------- on the site ---------- */

	private static function event_from( string $word ): int {
		if ( '' === $word ) {
			return 0;
		}
		if ( ctype_digit( $word ) ) {
			return Optima_Events::EVENT === get_post_type( (int) $word ) ? (int) $word : 0;
		}
		$p = get_page_by_path( sanitize_title( $word ), OBJECT, Optima_Events::EVENT );
		return $p ? (int) $p->ID : 0;
	}

	public static function list_shortcode( $atts ): string {
		$a = shortcode_atts( array( 'event' => '' ), (array) $atts, 'optima_vendors' );
		return self::cards( self::ids( self::event_from( (string) $a['event'] ) ) );
	}

	/** Vendor cards: logo, name, city, what they do, website. */
	public static function cards( array $ids ): string {
		wp_enqueue_style( 'optima-events' );
		if ( ! $ids ) {
			return '';
		}
		$out = '<ul class="se-vendors">';
		foreach ( $ids as $id ) {
			$v    = self::vendor( $id );
			$out .= '<li class="se-vendor">';
			if ( has_post_thumbnail( $id ) ) {
				$out .= '<span class="se-vendor-logo">' . get_the_post_thumbnail( $id, 'thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ) . '</span>';
			}
			$out .= '<div class="se-vendor-body"><h3 class="se-vendor-name">' . esc_html( get_the_title( $id ) ) . '</h3>';
			if ( '' !== $v['city'] ) {
				$out .= '<p class="se-meta">' . esc_html( $v['city'] ) . '</p>';
			}
			$about = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ) );
			if ( '' !== $about ) {
				$out .= '<p class="se-vendor-about">' . esc_html( $about ) . '</p>';
			}
			if ( '' !== $v['website'] ) {
				$host = (string) preg_replace( '#^www\.#', '', (string) wp_parse_url( $v['website'], PHP_URL_HOST ) );
				$out .= '<p class="se-vendor-link"><a href="' . esc_url( $v['website'] ) . '" rel="noopener">' . esc_html( '' !== $host ? $host : $v['website'] ) . '</a></p>';
			}
			$out .= '</div></li>';
		}
		return $out . '</ul>';
	}

	/** The event page lists its vendors near the end, when it has any. */
	public static function on_event_page( $content ) {
		if ( ! is_singular( Optima_Events::EVENT ) || ! in_the_loop() || ! is_main_query() || ! apply_filters( 'optima_events_auto_content', true, get_the_ID() ) ) {
			return $content;
		}
		$ids = self::ids( (int) get_the_ID() );
		if ( ! $ids ) {
			return $content;
		}
		return $content . '<section class="se-block se-vendors-block"><h2>' . esc_html__( 'Vendors', 'optima-events' ) . '</h2>' . self::cards( $ids ) . '</section>';
	}

	/* ---------- the application ---------- */

	public static function apply_shortcode( $atts ): string {
		$a = shortcode_atts( array( 'event' => '' ), (array) $atts, 'optima_vendor_apply' );
		wp_enqueue_style( 'optima-events' );
		$event = self::event_from( (string) $a['event'] );
		$msg   = isset( $_GET['vendor'] ) ? sanitize_key( wp_unslash( $_GET['vendor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'applied'  => __( 'Thank you. Your application is in, and a person will write back to you by email.', 'optima-events' ),
			'missing'  => __( 'Please fill in your business name, your name, a working email, and what you sell.', 'optima-events' ),
			'tooquick' => __( 'That was very fast. Please check your answers and send the form again.', 'optima-events' ),
			'photo'    => __( 'Your application is in, but the image could not be uploaded. Use a JPG or PNG under 8 MB, or send it by email.', 'optima-events' ),
		);
		$out = '<div class="se-portal se-vendor-apply" id="se-vendor-apply">';
		if ( isset( $texts[ $msg ] ) ) {
			$good = in_array( $msg, array( 'applied', 'photo' ), true );
			$out .= '<p class="se-portal-note ' . ( $good ? 'is-good' : 'is-bad' ) . '" role="status">' . esc_html( $texts[ $msg ] ) . '</p>';
			if ( $good ) {
				return $out . '</div>';
			}
		}
		$f    = static function ( string $name, string $label, string $type = 'text', bool $required = false, string $help = '' ): string {
			$id  = 'se-v-' . $name;
			$out = '<p class="se-p-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>';
			$out .= 'textarea' === $type
				? '<textarea id="' . esc_attr( $id ) . '" name="v[' . esc_attr( $name ) . ']" rows="4"' . ( $required ? ' required' : '' ) . '></textarea>'
				: '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="v[' . esc_attr( $name ) . ']"' . ( $required ? ' required' : '' ) . '>';
			if ( '' !== $help ) {
				$out .= '<span class="se-p-help">' . esc_html( $help ) . '</span>';
			}
			return $out . '</p>';
		};
		$out .= '<form class="se-p-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">'
			. '<input type="hidden" name="action" value="optima_vendor_apply">'
			. '<input type="hidden" name="se_event" value="' . (int) $event . '">'
			. wp_nonce_field( 'optima_vendor_apply', '_se_vnonce', true, false )
			. '<input type="hidden" name="se_t" value="' . esc_attr( (string) time() ) . '">'
			. '<p class="se-p-hp" aria-hidden="true"><label for="se-v-website2">' . esc_html__( 'Leave this empty', 'optima-events' ) . '</label><input type="text" id="se-v-website2" name="se_website2" tabindex="-1" autocomplete="off"></p>'
			. '<fieldset class="se-p-group"><legend>' . esc_html__( 'Your business', 'optima-events' ) . '</legend>'
			. $f( 'name', __( 'Business name, as it should appear', 'optima-events' ), 'text', true )
			. $f( 'city', __( 'City and state', 'optima-events' ) )
			. $f( 'website', __( 'Website or shop link', 'optima-events' ), 'url' )
			. $f( 'about', __( 'About your business, in two or three sentences', 'optima-events' ), 'textarea', true, __( 'This is what visitors read on the vendor list.', 'optima-events' ) )
			. $f( 'products', __( 'What you will sell or offer', 'optima-events' ), 'textarea', true )
			. '<p class="se-p-field"><label for="se-v-photo">' . esc_html__( 'Your logo or a photo of your work (JPG or PNG)', 'optima-events' ) . '</label><input type="file" id="se-v-photo" name="se_photo" accept="image/jpeg,image/png,image/webp"></p>'
			. '</fieldset>'
			. '<fieldset class="se-p-group"><legend>' . esc_html__( 'Just for the organizers', 'optima-events' ) . '</legend>'
			. $f( 'contact', __( 'Your name', 'optima-events' ), 'text', true )
			. $f( 'pronouns', __( 'Pronouns', 'optima-events' ) )
			. $f( 'email', __( 'Email', 'optima-events' ), 'email', true, __( 'Where we write to you. Never shown on the site.', 'optima-events' ) )
			. $f( 'phone', __( 'Phone', 'optima-events' ), 'tel', false, __( 'For the day of the event only. Never shown on the site.', 'optima-events' ) )
			. '<div class="se-p-field" role="radiogroup" aria-labelledby="se-v-power-label"><span class="se-p-label" id="se-v-power-label">' . esc_html__( 'Do you need electricity?', 'optima-events' ) . '</span>'
			. '<label class="se-p-choice"><input type="radio" name="v[power]" value="yes"> ' . esc_html__( 'Yes', 'optima-events' ) . '</label>'
			. '<label class="se-p-choice"><input type="radio" name="v[power]" value="no"> ' . esc_html__( 'No', 'optima-events' ) . '</label></div>'
			. $f( 'space', __( 'Your setup: table, tent, rack or anything else', 'optima-events' ), 'textarea' )
			. $f( 'notes', __( 'Anything else we should know', 'optima-events' ), 'textarea' )
			. '</fieldset>'
			. '<p><button type="submit" class="se-button wp-element-button">' . esc_html__( 'Send my application', 'optima-events' ) . '</button></p>'
			. '</form></div>';
		return $out;
	}

	public static function apply(): void {
		$nonce = isset( $_POST['_se_vnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_se_vnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'optima_vendor_apply' ) ) {
			wp_die( esc_html__( 'This form has expired. Go back, reload the page and send it again.', 'optima-events' ), 400 );
		}
		$ref  = wp_get_referer();
		$back = static function ( string $msg ) use ( $ref ): void {
			$url = $ref ? remove_query_arg( 'vendor', $ref ) : home_url( '/' );
			wp_safe_redirect( add_query_arg( 'vendor', $msg, $url ) . '#se-vendor-apply' );
			exit;
		};
		if ( ! empty( $_POST['se_website2'] ) ) {
			$back( 'applied' );
		}
		$t = isset( $_POST['se_t'] ) ? (int) $_POST['se_t'] : 0;
		if ( $t <= 0 || time() - $t < self::MIN_SECONDS ) {
			$back( 'tooquick' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every key is cleaned below.
		$v     = isset( $_POST['v'] ) ? (array) wp_unslash( $_POST['v'] ) : array();
		$name  = sanitize_text_field( (string) ( $v['name'] ?? '' ) );
		$email = sanitize_email( (string) ( $v['email'] ?? '' ) );
		$who   = sanitize_text_field( (string) ( $v['contact'] ?? '' ) );
		$about = sanitize_textarea_field( (string) ( $v['about'] ?? '' ) );
		$sells = sanitize_textarea_field( (string) ( $v['products'] ?? '' ) );
		if ( '' === $name || '' === $who || ! is_email( $email ) || '' === $about || '' === $sells ) {
			$back( 'missing' );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => self::TYPE,
				'post_status'  => 'pending',
				'post_title'   => $name,
				'post_content' => $about,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			$back( 'missing' );
		}
		$event = isset( $_POST['se_event'] ) ? absint( $_POST['se_event'] ) : 0;
		self::save_vendor(
			(int) $id,
			array(
				'city'     => $v['city'] ?? '',
				'website'  => $v['website'] ?? '',
				'products' => $sells,
				'events'   => $event && Optima_Events::EVENT === get_post_type( $event ) ? array( $event ) : array(),
			)
		);
		update_post_meta(
			(int) $id,
			self::PRIVATE,
			array(
				'contact'  => $who,
				'pronouns' => sanitize_text_field( (string) ( $v['pronouns'] ?? '' ) ),
				'email'    => $email,
				'phone'    => sanitize_text_field( (string) ( $v['phone'] ?? '' ) ),
				'power'    => in_array( $v['power'] ?? '', array( 'yes', 'no' ), true ) ? $v['power'] : '',
				'space'    => sanitize_textarea_field( (string) ( $v['space'] ?? '' ) ),
				'notes'    => sanitize_textarea_field( (string) ( $v['notes'] ?? '' ) ),
				'applied'  => Optima_Events::now()->format( 'Y-m-d H:i' ),
			)
		);
		do_action(
			'optima_events_person',
			array(
				'email' => $email,
				'name'  => $who,
				'phone' => sanitize_text_field( (string) ( $v['phone'] ?? '' ) ),
				'role'  => 'vendor',
				'event' => $event && Optima_Events::EVENT === get_post_type( $event ) ? $event : 0,
				'org'   => $name,
			)
		);
		$photo = 0;
		if ( ! empty( $_FILES['se_photo']['name'] ) ) {
			$file = $_FILES['se_photo']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handed to WordPress's own upload checks.
			if ( (int) ( $file['size'] ?? 0 ) <= 8 * MB_IN_BYTES && in_array( wp_check_filetype( (string) $file['name'] )['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$photo = media_handle_upload( 'se_photo', (int) $id, array(), array( 'test_form' => false ) );
				$photo = is_wp_error( $photo ) ? -1 : (int) $photo;
			} else {
				$photo = -1;
			}
			if ( $photo > 0 ) {
				set_post_thumbnail( (int) $id, $photo );
			}
		}
		/* translators: %s: business name. */
		wp_mail( (string) get_option( 'admin_email' ), sprintf( __( 'New vendor application: %s', 'optima-events' ), $name ), __( "A new vendor applied. Review them here:\n", 'optima-events' ) . admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) );
		$back( $photo < 0 ? 'photo' : 'applied' );
	}

	/** Publishing a pending application is the approval: tell them. */
	public static function approved( $post_id, $post, $update, $before ): void {
		if ( ! $post instanceof WP_Post || self::TYPE !== $post->post_type || 'publish' !== $post->post_status || ! $before instanceof WP_Post || 'pending' !== $before->post_status ) {
			return;
		}
		$p = self::private_info( $post->ID );
		if ( ! is_email( $p['email'] ) ) {
			return;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$v    = self::vendor( $post->ID );
		$at   = array_map( static fn( $e ) => wp_specialchars_decode( get_the_title( $e ), ENT_QUOTES ), $v['events'] );
		/* translators: %s: site name. */
		$subject = sprintf( __( 'You are confirmed as a vendor with %s', 'optima-events' ), $site );
		$body    = sprintf( /* translators: %s: name. */ __( 'Hi %s,', 'optima-events' ), $p['contact'] ) . "\n\n"
			. ( $at
				/* translators: %s: event names. */
				? sprintf( __( 'Your application is approved, and %s is on the vendor list for %s.', 'optima-events' ), wp_specialchars_decode( $post->post_title, ENT_QUOTES ), implode( ', ', $at ) )
				: __( 'Your vendor application is approved.', 'optima-events' ) )
			. "\n\n" . __( 'We will be in touch with the details for the day. Reply to this email with any questions.', 'optima-events' ) . "\n\n" . $site;
		wp_mail( $p['email'], $subject, $body );
	}

	/* ---------- the owner's side ---------- */

	public static function boxes( $post ): void {
		add_meta_box( 'optima-vendor', __( 'Vendor details', 'optima-events' ), array( __CLASS__, 'render_box' ), self::TYPE, 'normal', 'high' );
		add_meta_box( 'optima-vendor-private', __( 'Contact (private)', 'optima-events' ), array( __CLASS__, 'render_private' ), self::TYPE, 'side', 'high' );
	}

	public static function render_box( $post ): void {
		$v = self::vendor( (int) $post->ID );
		// Upcoming events, plus any past ones this vendor was at, soonest first.
		$ids = array_values( array_unique( array_merge( Optima_Events::event_ids( 'upcoming' ), $v['events'] ) ) );
		usort(
			$ids,
			static function ( $a, $b ) {
				return strcmp( (string) get_post_meta( $a, '_optima_event_start', true ), (string) get_post_meta( $b, '_optima_event_start', true ) );
			}
		);
		wp_nonce_field( 'optima_vendor_save', self::NONCE );
		?>
		<div class="se-form">
			<p class="se-field"><label for="se-vendor-city"><?php esc_html_e( 'City and state', 'optima-events' ); ?></label><input type="text" id="se-vendor-city" name="optima_vendor[city]" value="<?php echo esc_attr( $v['city'] ); ?>"></p>
			<p class="se-field"><label for="se-vendor-website"><?php esc_html_e( 'Website or shop link', 'optima-events' ); ?></label><input type="url" id="se-vendor-website" name="optima_vendor[website]" value="<?php echo esc_attr( $v['website'] ); ?>"></p>
			<p class="se-field"><label for="se-vendor-products"><?php esc_html_e( 'What they sell (for you; not shown on the site)', 'optima-events' ); ?></label><textarea id="se-vendor-products" name="optima_vendor[products]" rows="3"><?php echo esc_textarea( $v['products'] ); ?></textarea></p>
			<fieldset class="se-field">
				<legend><?php esc_html_e( 'At these events', 'optima-events' ); ?></legend>
				<div class="se-check-grid">
					<?php foreach ( $ids as $ev ) : ?>
						<?php $sd = Optima_Events::start( Optima_Events::event( $ev ) ); ?>
						<label class="se-check-row"><input type="checkbox" name="optima_vendor[events][]" value="<?php echo (int) $ev; ?>" <?php checked( in_array( $ev, $v['events'], true ) ); ?>> <?php echo esc_html( get_the_title( $ev ) ); ?><?php if ( $sd ) : ?> <span class="se-help"><?php echo esc_html( wp_date( 'M j, Y', $sd->getTimestamp() ) ); ?></span><?php endif; ?></label>
					<?php endforeach; ?>
				</div>
				<?php if ( ! $ids ) : ?>
					<p class="se-help"><?php esc_html_e( 'No upcoming events yet.', 'optima-events' ); ?></p>
				<?php endif; ?>
			</fieldset>
			<p class="se-help"><?php esc_html_e( 'The description above is what visitors read. The logo or photo is the featured image. Publish to approve them; they get an email saying they are in.', 'optima-events' ); ?></p>
		</div>
		<?php
	}

	public static function render_private( $post ): void {
		$p = self::private_info( (int) $post->ID );
		$rows = array(
			__( 'Name', 'optima-events' )        => $p['contact'] . ( '' !== $p['pronouns'] ? ' (' . $p['pronouns'] . ')' : '' ),
			__( 'Email', 'optima-events' )       => $p['email'],
			__( 'Phone', 'optima-events' )       => $p['phone'],
			__( 'Electricity', 'optima-events' ) => 'yes' === $p['power'] ? __( 'Needs power', 'optima-events' ) : ( 'no' === $p['power'] ? __( 'No power needed', 'optima-events' ) : '' ),
			__( 'Setup', 'optima-events' )       => $p['space'],
			__( 'Notes', 'optima-events' )       => $p['notes'],
			__( 'Applied', 'optima-events' )     => $p['applied'],
		);
		$any = false;
		echo '<dl class="se-private">';
		foreach ( $rows as $label => $value ) {
			if ( '' === trim( $value ) ) {
				continue;
			}
			$any = true;
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . ( __( 'Email', 'optima-events' ) === $label ? '<a href="mailto:' . esc_attr( $value ) . '">' . esc_html( $value ) . '</a>' : nl2br( esc_html( $value ) ) ) . '</dd>';
		}
		echo '</dl>';
		if ( ! $any ) {
			echo '<p class="se-help">' . esc_html__( 'Added by hand or imported, so there are no contact details here.', 'optima-events' ) . '</p>';
		}
		echo '<p class="se-help">' . esc_html__( 'Only people who run the site see this box.', 'optima-events' ) . '</p>';
	}

	public static function save_box( $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'optima_vendor_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in save_vendor().
		$in = isset( $_POST['optima_vendor'] ) ? (array) wp_unslash( $_POST['optima_vendor'] ) : array();
		self::save_vendor( (int) $post_id, $in );
	}

	public static function columns( array $cols ): array {
		return array(
			'cb'         => $cols['cb'] ?? '',
			'title'      => __( 'Vendor', 'optima-events' ),
			'se_city'    => __( 'City', 'optima-events' ),
			'se_events'  => __( 'Events', 'optima-events' ),
			'date'       => $cols['date'] ?? __( 'Date', 'optima-events' ),
		);
	}

	public static function column( $col, $post_id ): void {
		$v = self::vendor( (int) $post_id );
		if ( 'se_city' === $col ) {
			echo esc_html( $v['city'] );
		} elseif ( 'se_events' === $col ) {
			echo esc_html( implode( ', ', array_map( 'get_the_title', $v['events'] ) ) );
		}
	}

	public static function needs_you( $items ): array {
		$items   = (array) $items;
		$pending = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'pending',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			)
		);
		if ( $pending ) {
			$items[] = array(
				/* translators: %d: number of applications. */
				'text'   => sprintf( _n( '%d vendor application to review.', '%d vendor applications to review.', count( $pending ), 'optima-events' ), count( $pending ) ),
				'url'    => admin_url( 'edit.php?post_type=' . self::TYPE . '&post_status=pending' ),
				'action' => __( 'Review vendors', 'optima-events' ),
			);
		}
		return $items;
	}

	/* ---------- privacy ---------- */

	private static function by_email( string $email ): array {
		$email = strtolower( $email );
		return array_values(
			array_filter(
				array_map(
					'intval',
					get_posts(
						array(
							'post_type'      => self::TYPE,
							'post_status'    => 'any',
							'posts_per_page' => 200,
							'fields'         => 'ids',
						)
					)
				),
				static fn( $id ) => strtolower( self::private_info( $id )['email'] ) === $email
			)
		);
	}

	public static function exporter( array $exporters ): array {
		$exporters['optima-events-vendors'] = array(
			'exporter_friendly_name' => __( 'Vendor applications', 'optima-events' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email ): array {
		$data = array();
		foreach ( self::by_email( $email ) as $id ) {
			$rows = array(
				array(
					'name'  => __( 'Business', 'optima-events' ),
					'value' => get_the_title( $id ),
				),
			);
			foreach ( self::private_info( $id ) as $k => $v ) {
				if ( '' !== $v ) {
					$rows[] = array(
						'name'  => ucfirst( $k ),
						'value' => $v,
					);
				}
			}
			$data[] = array(
				'group_id'    => 'optima-events-vendors',
				'group_label' => __( 'Vendor applications', 'optima-events' ),
				'item_id'     => 'vendor-' . $id,
				'data'        => $rows,
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function eraser( array $erasers ): array {
		$erasers['optima-events-vendors'] = array(
			'eraser_friendly_name' => __( 'Vendor applications', 'optima-events' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/** The business stays on the list; the person's contact details go. */
	public static function erase_data( string $email ): array {
		$ids = self::by_email( $email );
		foreach ( $ids as $id ) {
			delete_post_meta( $id, self::PRIVATE );
		}
		return array(
			'items_removed'  => (bool) $ids,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
