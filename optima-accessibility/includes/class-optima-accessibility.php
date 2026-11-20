<?php
/**
 * The statement: settings, the [optima_accessibility_statement] shortcode, the
 * statement page itself and the footer link.
 *
 * The footer link is on by default (inventory INV-2611-09). It prints in the
 * theme's footer spot, optima_slot( 'footer' ) (rule 12). A theme without that
 * slot gets it at the very end of the page from wp_footer instead, once. It is
 * skipped when a menu already links to the statement page or the page already
 * printed [optima_accessibility_link], and the Accessibility screen can turn
 * it off.
 *
 * No overlay widgets, ever. This plugin adds no toolbar, no script that
 * rewrites the page, and nothing that claims to make a site compliant.
 *
 * @package Optima_Accessibility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Accessibility {

	const OPTION    = 'optima_accessibility';
	const SHORTCODE = 'optima_accessibility_statement';
	const LINK_CODE = 'optima_accessibility_link';
	const STANDARD  = 'WCAG 2.2 AA';

	/** The content a new statement page starts with. */
	const PAGE_CONTENT = "<!-- wp:shortcode -->\n[optima_accessibility_statement]\n<!-- /wp:shortcode -->";

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'fill_description' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'footer_style' ) );
		// Plugin first (rule 12): the theme's footer slot; themes without one still get it from wp_footer.
		add_action( 'optima_slot_footer', array( __CLASS__, 'footer_link' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer_link' ), 50 );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'forget_menu_check' ) );
		add_action( 'wp_delete_nav_menu', array( __CLASS__, 'forget_menu_check' ) );
	}

	/** The footer link's few lines of style, only when that link is on. */
	public static function footer_style(): void {
		if ( ! empty( self::settings()['footer_link'] ) ) {
			wp_enqueue_style( 'optima-accessibility' );
		}
	}

	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'statement' ) );
		add_shortcode( self::LINK_CODE, array( __CLASS__, 'link_shortcode' ) );
		wp_register_style( 'optima-accessibility', OPTIMA_ACCESSIBILITY_URL . 'assets/statement.css', array(), OPTIMA_ACCESSIBILITY_VERSION );
	}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'email'         => '',
			'phone'         => '',
			'response_days' => 0,
			'other_formats' => 0,
			'last_reviewed' => '',
			'page_id'       => 0,
			'own_page'      => 0,
			// On by default: every page links to the statement. The Accessibility screen can turn it off.
			'footer_link'   => 1,
		);
	}

	/** @return array<string,mixed> */
	public static function settings(): array {
		$saved = (array) get_option( self::OPTION, array() );
		// Before the link was on by default every save stored footer_link 0. Only an
		// off chosen on the footer form (footer_link_chosen) keeps the link off.
		if ( empty( $saved['footer_link_chosen'] ) ) {
			unset( $saved['footer_link'] );
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/** True when optima-core's business info is available. */
	public static function has_core(): bool {
		return class_exists( 'Optima_Core_Business', false );
	}

	/** @return array<string,string> */
	/** The business facts from Your business, Details (Optima Core's form or Optima Build's), read from their one option. */
	private static function core_business(): array {
		$b = get_option( 'optima_business', array() );
		return is_array( $b ) ? $b : array();
	}

	/**
	 * Once (release 3): a phone typed on this screen and missing from Details
	 * moves to Details. The old value stays in this plugin's settings.
	 */
	public static function migrate(): void {
		if ( get_option( 'optima_a11y_r3_phone' ) ) {
			return;
		}
		$old = trim( (string) ( self::settings()['phone'] ?? '' ) );
		$biz = self::core_business();
		if ( '' !== $old && '' === trim( (string) ( $biz['phone'] ?? '' ) ) ) {
			$biz['phone'] = $old;
			update_option( 'optima_business', $biz );
		}
		update_option( 'optima_a11y_r3_phone', time(), false );
	}

	/** Business name from optima-core, else the site name. */
	public static function business_name(): string {
		$core = self::core_business();
		$name = trim( (string) ( $core['name'] ?? '' ) );
		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** Contact email: this screen first, then optima-core, then nothing. */
	public static function email(): string {
		$s = self::settings();
		if ( '' !== $s['email'] ) {
			return $s['email'];
		}
		return sanitize_email( (string) ( self::core_business()['email'] ?? '' ) );
	}

	/**
	 * Contact phone: Your business, Details (rule 45: typed once). The phone this
	 * screen saved before release 3 is used only while Details has none.
	 */
	public static function phone(): string {
		$biz = trim( (string) ( self::core_business()['phone'] ?? '' ) );
		if ( '' !== $biz ) {
			return $biz;
		}
		$s = self::settings();
		if ( '' !== $s['phone'] ) {
			return $s['phone'];
		}
		return trim( (string) ( self::core_business()['phone'] ?? '' ) );
	}

	/** The statement page, if it exists and is not in the trash. */
	public static function page(): ?WP_Post {
		$id = (int) self::settings()['page_id'];
		if ( $id ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
				return $post;
			}
		}
		return null;
	}

	public static function page_url(): string {
		$page = self::page();
		return ( $page && 'publish' === $page->post_status ) ? (string) get_permalink( $page ) : '';
	}

	/**
	 * Create the Accessibility page, or make sure the existing one is
	 * published and still carries the statement. Edits around the shortcode
	 * are kept.
	 */
	public static function ensure_page(): int {
		$s    = self::settings();
		$page = self::page();
		if ( ! $page ) {
			$found = get_page_by_path( 'accessibility' );
			if ( $found instanceof WP_Post && 'trash' !== $found->post_status ) {
				$page = $found;
				// A statement someone already wrote by hand is kept word for word.
				// We link to it and check it, and never add ours on top.
				if ( ! has_shortcode( $found->post_content, self::SHORTCODE ) && strlen( trim( wp_strip_all_tags( $found->post_content ) ) ) > 200 ) {
					$s['own_page'] = 1;
				}
			}
		}
		if ( $page && empty( $s['own_page'] ) && ! has_shortcode( $page->post_content, self::SHORTCODE ) && strlen( trim( wp_strip_all_tags( $page->post_content ) ) ) > 200 ) {
			$s['own_page'] = 1; // Someone replaced our statement with their own words; keep them.
		}
		if ( $page && ! empty( $s['own_page'] ) ) {
			if ( 'publish' === $page->post_status ) {
				$s['page_id'] = $page->ID;
				update_option( self::OPTION, $s );
			}
			return (int) $page->ID;
		}
		if ( $page ) {
			$update = array( 'ID' => $page->ID );
			if ( ! has_shortcode( $page->post_content, self::SHORTCODE ) ) {
				$update['post_content'] = self::PAGE_CONTENT . ( '' !== trim( $page->post_content ) ? "\n\n" . $page->post_content : '' );
			}
			if ( 'publish' !== $page->post_status ) {
				$update['post_status'] = 'publish';
			}
			if ( ! has_excerpt( $page ) ) {
				$update['post_excerpt'] = self::description();
			}
			if ( count( $update ) > 1 ) {
				wp_update_post( wp_slash( $update ) );
			}
			$id = $page->ID;
		} else {
			$id = (int) wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => __( 'Accessibility', 'optima-accessibility' ),
						'post_name'    => 'accessibility',
						'post_content' => self::PAGE_CONTENT,
						'post_excerpt' => self::description(),
					)
				)
			);
		}
		if ( $id ) {
			$s['page_id'] = $id;
			update_option( self::OPTION, $s );
		}
		return $id;
	}

	/** The page's search description, so search engines don't have to guess one. */
	public static function description(): string {
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		return sprintf( __( 'How %s keeps this website usable for everyone, what we check, and how to reach us if something doesn\'t work for you.', 'optima-accessibility' ), $name );
	}

	/**
	 * Give an existing statement page its description once. Sites that made the
	 * page before 0.1.6 have none, and Site health flags it.
	 */
	public static function fill_description(): void {
		if ( get_option( 'optima_accessibility_desc_v1' ) || ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$page = self::page();
		if ( $page && ! has_excerpt( $page ) && '' === (string) get_post_meta( $page->ID, '_optima_seo_desc', true ) ) {
			wp_update_post(
				array(
					'ID'           => $page->ID,
					'post_excerpt' => self::description(),
				)
			);
		}
		update_option( 'optima_accessibility_desc_v1', 1, false );
	}

	/** Format a phone number for a tel: link. */
	private static function tel( string $phone ): string {
		$digits = preg_replace( '/[^0-9+]/', '', $phone );
		return 'tel:' . $digits;
	}

	/** True when both at-launch reviews are ticked. */
	public static function launch_done(): bool {
		$launch = Optima_Accessibility_Checks::launch();
		foreach ( Optima_Accessibility_Checks::launch_items() as $key => $item ) {
			if ( empty( $launch[ $key ]['done'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * [optima_accessibility_statement]: the statement itself.
	 *
	 * @param array|string $atts Unused.
	 */
	public static function statement( $atts = array() ): string {
		wp_enqueue_style( 'optima-accessibility' );
		$s     = self::settings();
		$name  = self::business_name();
		$email = self::email();
		$phone = self::phone();
		$days  = (int) $s['response_days'];

		ob_start();
		?>
		<div class="optima-a11y">
			<h2><?php esc_html_e( 'We want everyone to be able to use this site', 'optima-accessibility' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: business name */
					esc_html__( '%s aims to meet the Web Content Accessibility Guidelines (WCAG) 2.2 at level AA. These guidelines describe how to make a website work for people who use screen readers, keyboards, zoom and other tools.', 'optima-accessibility' ),
					esc_html( $name )
				);
				?>
			</p>

			<h2><?php esc_html_e( 'How we check the site', 'optima-accessibility' ); ?></h2>
			<p><?php esc_html_e( 'Every week, automated checks look for images without descriptions, headings out of order and form fields without labels, and we work through what they find.', 'optima-accessibility' ); ?>
			<?php if ( self::launch_done() ) : ?>
				<?php esc_html_e( 'Color contrast and keyboard use need a real browser, so we review those by hand.', 'optima-accessibility' ); ?>
			<?php endif; ?></p>

			<h2><?php esc_html_e( 'Where the site may fall short', 'optima-accessibility' ); ?></h2>
			<p><?php esc_html_e( 'Some parts of the site may not meet the guidelines yet, such as older pages, documents, or videos and tools from other companies. We keep improving them as we find them.', 'optima-accessibility' ); ?></p>

			<h2><?php esc_html_e( 'Tell us if something gets in your way', 'optima-accessibility' ); ?></h2>
			<p><?php esc_html_e( 'If something on this site is hard to use, please let us know. Tell us which page you were on and what happened, and we will work with you to get you what you need.', 'optima-accessibility' ); ?></p>
			<?php if ( '' !== $email || '' !== $phone ) : ?>
				<ul class="optima-a11y-contact">
					<?php if ( '' !== $email ) : ?>
						<li><a class="optima-a11y-button" href="<?php echo esc_url( 'mailto:' . $email ); ?>" data-optima-track="accessibility_contact" data-optima-label="email">
							<?php
							/* translators: %s: email address */
							printf( esc_html__( 'Email %s', 'optima-accessibility' ), esc_html( $email ) );
							?>
						</a></li>
					<?php endif; ?>
					<?php if ( '' !== $phone ) : ?>
						<li><a class="optima-a11y-button" href="<?php echo esc_url( self::tel( $phone ) ); ?>" data-optima-track="accessibility_contact" data-optima-label="phone">
							<?php
							/* translators: %s: phone number */
							printf( esc_html__( 'Call %s', 'optima-accessibility' ), esc_html( $phone ) );
							?>
						</a></li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $days > 0 ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of business days */
						esc_html( _n( 'We aim to reply within %d business day.', 'We aim to reply within %d business days.', $days, 'optima-accessibility' ) ),
						(int) $days
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $s['other_formats'] ) ) : ?>
				<p><?php esc_html_e( 'We can also send you any page in another format.', 'optima-accessibility' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $s['last_reviewed'] ) : ?>
				<p class="optima-a11y-reviewed">
					<?php
					printf(
						/* translators: %s: month and year */
						esc_html__( 'This statement was last reviewed in %s.', 'optima-accessibility' ),
						esc_html( date_i18n( 'F Y', strtotime( $s['last_reviewed'] . ' 12:00:00' ) ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** The footer link markup, or nothing while there is no published page. */
	public static function link_html(): string {
		$url = self::page_url();
		if ( '' === $url ) {
			return '';
		}
		return '<a class="optima-a11y-link" href="' . esc_url( $url ) . '">' . esc_html__( 'Accessibility', 'optima-accessibility' ) . '</a>';
	}

	/**
	 * [optima_accessibility_link]: for a footer widget, block or menu area.
	 *
	 * @param array|string $atts Unused.
	 */
	public static function link_shortcode( $atts = array() ): string {
		$html = self::link_html();
		if ( '' !== $html ) {
			self::mark_link_seen();
			self::$printed = true; // The automatic footer link then stays out, so the page never shows two.
		}
		return $html;
	}

	/** @var bool The link is on this page already (the footer slot, wp_footer or the shortcode). */
	private static $printed = false;

	const MENU_CHECK = 'optima_accessibility_in_menu';

	/** True when a menu item already links to the statement page. Cached until a menu changes. */
	public static function in_menu(): bool {
		$page = self::page();
		if ( ! $page ) {
			return false;
		}
		$cached = get_transient( self::MENU_CHECK );
		if ( is_array( $cached ) && (int) ( $cached['page'] ?? 0 ) === (int) $page->ID ) {
			return ! empty( $cached['in'] );
		}
		$items = get_posts(
			array(
				'post_type'        => 'nav_menu_item',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query -- cached until a menu changes.
				'meta_query'       => array(
					array(
						'key'   => '_menu_item_object_id',
						'value' => (string) $page->ID,
					),
					array(
						'key'   => '_menu_item_type',
						'value' => 'post_type',
					),
				),
			)
		);
		$in = ! empty( $items );
		set_transient( self::MENU_CHECK, array( 'page' => (int) $page->ID, 'in' => $in ? 1 : 0 ), DAY_IN_SECONDS );
		return $in;
	}

	/** A menu changed: check again on the next page view. */
	public static function forget_menu_check(): void {
		delete_transient( self::MENU_CHECK );
	}

	const LINK_SEEN = 'optima_accessibility_link_seen';

	/**
	 * Remember that a visitor-facing page printed the link. The statement check trusts this
	 * when the site can't load its own home page (hosts and firewalls often block that).
	 * Written at most once a day.
	 */
	public static function mark_link_seen(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$today = gmdate( 'Y-m-d' );
		if ( get_option( self::LINK_SEEN ) !== $today ) {
			update_option( self::LINK_SEEN, $today, false );
		}
	}

	/**
	 * The small Accessibility link, once per page: in the theme's footer slot,
	 * or from wp_footer on a theme that has no footer slot (the slot runs first,
	 * so wp_footer then finds it printed). Off when the screen turned it off,
	 * and skipped when a menu or the shortcode already links to the statement.
	 */
	public static function footer_link(): void {
		if ( self::$printed || empty( self::settings()['footer_link'] ) || is_admin() ) {
			return;
		}
		$link = self::link_html();
		if ( '' === $link ) {
			return;
		}
		self::$printed = true;
		if ( self::in_menu() ) {
			return;
		}
		self::mark_link_seen();
		echo '<p class="optima-a11y-footer">' . $link . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in link_html().
	}
}
