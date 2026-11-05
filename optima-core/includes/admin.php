<?php
/**
 * The Optima admin screens. A top-level "Optima" menu with three screens
 * (with the Optima Build hub: Business info and SEO; its Site health shows the
 * search checks). The shared kit draws the section nav across all of them:
 *  - Dashboard: a plain-language health check ("is my site healthy and getting
 *    found?"), the heart of the plugin.
 *  - Business: the NAP + identity form (Optima_Core_Business).
 *  - SEO: site-wide SEO defaults (Optima_Core_SEO_Settings). Per-page SEO stays
 *    in the Optima SEO box on each page.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** The menu these screens sit in: a theme's Build menu when there is one, else Optima's own. */
	public static function parent() {
		global $admin_page_hooks;
		foreach ( array( 'optima-build', 'recon-build' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'optima';
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_optima' === $hook || '_page_optima' === substr( (string) $hook, -11 ) ) {
			Optima_Admin_UI::enqueue( plugins_url( 'assets/optima-admin.css', OPTIMA_CORE_FILE ) );
		}
	}

	/**
	 * Is the Optima Build hub running? Then it owns the home screen and site
	 * health, and this plugin adds only its settings screens.
	 */
	public static function hub() {
		return class_exists( 'Optima_Hub_Admin' );
	}

	public static function menu() {
		$label = Optima_Admin_UI::branded() ? 'Optima' : 'Site Build';
		$icon  = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#1272C4" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>'
		);
		// A theme's own Build menu (Optima Build, Recon Build) comes first: the
		// screens join it so the site has one Optima menu, not two. The page slug
		// stays "optima" either way, so every link to these screens still works.
		$parent = self::parent();
		if ( 'optima' === $parent ) {
			add_menu_page( $label, $label, 'manage_options', 'optima', array( __CLASS__, 'render' ), $icon, 3 );
		}
		if ( self::hub() ) {
			// The hub's Site health carries these checks, so this screen opens on Details.
			add_submenu_page( $parent, 'Details', 'Details', 'manage_options', 'optima', array( __CLASS__, 'render' ) );
		} else {
			add_submenu_page( $parent, 'Search health', 'Search health', 'manage_options', 'optima', array( __CLASS__, 'render' ) );
			add_submenu_page( $parent, 'Details', 'Details', 'manage_options', 'optima&tab=business', array( __CLASS__, 'render' ) );
		}
		// Testimonials and FAQs are content: their lists sit under Optima, Content (rules 9 and 10).
		if ( post_type_exists( 'testimonial' ) ) {
			add_submenu_page( $parent, 'Testimonials', 'Testimonials', 'edit_posts', 'edit.php?post_type=testimonial' );
		}
		if ( post_type_exists( 'faq' ) ) {
			add_submenu_page( $parent, 'FAQs', 'FAQs', 'edit_posts', 'edit.php?post_type=faq' );
		}
		add_submenu_page( $parent, 'SEO', 'SEO', 'manage_options', 'optima&tab=seo', array( __CLASS__, 'render' ) );
		add_submenu_page( $parent, 'Approved copy', 'Approved copy', 'manage_options', 'optima&tab=sync', array( __CLASS__, 'render' ) );
		add_submenu_page( $parent, 'Spam protection', 'Spam protection', 'manage_options', 'optima&tab=protect', array( __CLASS__, 'render' ) );
		foreach ( self::groups() as $slug => $group ) {
			add_submenu_page( $parent, $group['label'], $group['label'], 'manage_options', 'optima&tab=' . $slug, array( __CLASS__, 'render' ) );
		}
	}

	/** Companion-registered settings groups, if the class is loaded. */
	private static function groups() {
		return class_exists( 'Optima_Core_Settings_Groups' ) ? Optima_Core_Settings_Groups::all() : array();
	}

	/** @return array<string,string> tab => label */
	private static function tabs() {
		$tabs = self::hub()
			? array( 'business' => 'Details', 'seo' => 'SEO' )
			: array( 'dashboard' => 'Search health', 'business' => 'Details', 'seo' => 'SEO' );
		$tabs['sync']    = 'Approved copy';
		$tabs['protect'] = 'Spam protection';
		foreach ( self::groups() as $slug => $group ) {
			$tabs[ $slug ] = $group['label'];
		}
		return $tabs;
	}

	private static function current_tab() {
		$home = self::hub() ? 'business' : 'dashboard';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $home;
		return array_key_exists( $tab, self::tabs() ) ? $tab : $home;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab   = self::current_tab();
		$tabs  = self::tabs();
		$ledes = array(
			'dashboard' => 'A quick check that search engines and AI tools can find the business and read it correctly.',
			'business'  => 'The business name, phone, address and profiles, typed once here. Every other screen shows them read-only with a link back. Keep them identical to the Google Business Profile.',
			'sync'      => 'Keeps each page in step with the copy the theme ships. Pages nobody has edited update on their own after a theme update; anything changed in WordPress waits for you.',
			'protect'   => 'What keeps spam away from the business: the form guard and two switches. Both switches are on unless you turn them off.',
			'seo'       => 'How the site shows in search results: the checks first, then the site-wide settings. Each page sets its own title and description in its SEO box; the sitemap is built at /wp-sitemap.xml.',
		);
		self::styles();
		Optima_Admin_UI::open( $tabs[ $tab ], $ledes[ $tab ] ?? '', $tabs[ $tab ] );
		echo '<div class="optima-admin sh-sections">';

		if ( 'business' === $tab ) {
			self::tab_business();
		} elseif ( 'seo' === $tab ) {
			self::tab_seo();
		} elseif ( 'sync' === $tab ) {
			Optima_Sync_Admin::render();
		} elseif ( 'protect' === $tab ) {
			self::forms_guard_status();
			Optima_Core_Protection::render_tab();
		} elseif ( array_key_exists( $tab, self::groups() ) ) {
			Optima_Core_Settings_Groups::render_tab( $tab );
		} else {
			self::tab_dashboard();
		}
		echo '</div>';
		Optima_Admin_UI::close();
	}

	/* ---------- Dashboard: the health check ---------- */

	public static function tab_dashboard() {
		$checks = self::checks();
		$count  = count( array_filter( $checks, static function ( $c ) { return 'good' !== $c['status']; } ) );
		echo '<section class="sh-box">';
		echo '<h2>' . esc_html( $count ? sprintf( _n( '%d thing to look at', '%d things to look at', $count, 'optima-core' ), $count ) : 'Your site is healthy' ) . '</h2>';
		echo '<p>' . esc_html( $count ? 'Your site is running. These would make it stronger.' : 'Business details, search visibility and sharing are all set.' ) . '</p>';
		echo '</section>';
		self::render_checks( $checks );
	}

	/**
	 * Check cards in the shared Optima style. The Optima Build hub prints the same
	 * cards on its Site health screen, so both read as one product.
	 *
	 * @param array<int,array<string,mixed>> $checks From checks().
	 */
	public static function render_checks( array $checks ) {
		echo '<div class="sh-grid sh-grid-3">';
		foreach ( $checks as $c ) {
			$state = 'good' === $c['status'] ? 'good' : ( 'bad' === $c['status'] ? 'bad' : 'warn' );
			echo '<section class="sh-box sh-check is-' . esc_attr( $state ) . '">';
			echo '<p class="sh-label">' . esc_html( $c['label'] ) . '</p>';
			echo '<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span>' . esc_html( 'good' === $state ? 'All set' : 'Needs a look' ) . '</p>';
			echo '<p class="sh-note">' . esc_html( $c['message'] ) . '</p>';
			// Only links the viewer can open (Site health is also shown to people the site lock holds back).
			if ( ! empty( $c['items'] ) && current_user_can( 'edit_pages' ) ) {
				echo '<ul class="sh-list">';
				foreach ( $c['items'] as $item_id ) {
					$title = get_the_title( $item_id );
					printf(
						'<li><a href="%s">%s &rsaquo;</a></li>',
						esc_url( (string) get_edit_post_link( $item_id ) ),
						esc_html( '' !== $title ? $title : sprintf( '#%d', $item_id ) )
					);
				}
				echo '</ul>';
			}
			if ( ! empty( $c['action'] ) && ! empty( $c['action_label'] ) && current_user_can( 'manage_options' ) ) {
				printf( '<p class="sh-note"><a href="%s">%s &rsaquo;</a></p>', esc_url( $c['action'] ), esc_html( $c['action_label'] ) );
			}
			echo '</section>';
		}
		echo '</div>';
	}

	/**
	 * The search checks, also shown in the Optima Build hub's Site health.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function checks() {
		$base     = admin_url( 'admin.php?page=optima' );
		$biz      = Optima_Core_Business::get();
		$checks   = array();

		// 1. Business identity.
		$have = ! empty( $biz['name'] ) && ! empty( $biz['phone'] ) && ! empty( $biz['city'] );
		$checks[] = array(
			'label'        => 'Business details',
			'status'       => $have ? 'good' : 'warn',
			'message'      => $have
				? 'Your name, phone, and location are set, and they feed your search listing.'
				: 'Add your business name, phone, and city so search engines show the right details.',
			'action'       => $have ? '' : $base . '&tab=business',
			'action_label' => $have ? '' : 'Add business details',
		);

		// 2. Schema type.
		$type = ! empty( $biz['business_type'] );
		$checks[] = array(
			'label'        => 'Search listing (schema)',
			'status'       => $type ? 'good' : 'warn',
			'message'      => $type
				? 'Search engines and AI get a clear description of your business.'
				: 'Set your business type (e.g. LegalService, Restaurant) so your listing is specific.',
			'action'       => $type ? '' : $base . '&tab=business',
			'action_label' => $type ? '' : 'Set business type',
		);

		// 3. Pages missing a written description. Name them, so nobody has to hunt.
		// A theme that writes its own head (optima_head, recon_head) sets each
		// page's description in its templates and never reads the SEO box, so
		// an empty box there is not a gap and filling it would change nothing.
		$theme_head  = class_exists( 'Optima_SEO_Head' ) && Optima_SEO_Head::theme_handles_head();
		$missing_ids = $theme_head ? array() : self::pages_missing_description();
		$missing     = count( $missing_ids );
		$checks[]    = array(
			'label'        => 'Page descriptions',
			'status'       => $missing ? 'warn' : 'good',
			'message'      => $missing
				? sprintf(
					_n(
						'%d published page has no written description, so search engines guess one. Open it and fill in the SEO box.',
						'%d published pages have no written description, so search engines guess one. Open each and fill in the SEO box.',
						$missing,
						'optima-core'
					),
					$missing
				)
				: ( $theme_head ? 'Your theme writes each page\'s description for search.' : 'Every published page has a description written for search.' ),
			'items'        => array_slice( $missing_ids, 0, 10 ),
			'action'       => $missing > 10 ? admin_url( 'edit.php?post_type=page' ) : '',
			'action_label' => $missing > 10 ? 'See all pages' : '',
		);

		// 4. Accidental noindex.
		$hidden = self::pages_noindexed();
		$checks[] = array(
			'label'        => 'Visible to Google',
			'status'       => $hidden ? 'warn' : 'good',
			'message'      => $hidden
				? sprintf(
					_n( '%d page is set to hidden from search. Confirm that is on purpose.', '%d pages are set to hidden from search. Confirm that is on purpose.', $hidden, 'optima-core' ),
					$hidden
				)
				: 'No pages are accidentally hidden from search engines.',
			'action'       => '',
			'action_label' => '',
		);

		// 5. Default share image.
		$image = self::has_share_image();
		$checks[] = array(
			'label'        => 'Social sharing',
			'status'       => $image ? 'good' : 'warn',
			'message'      => $image
				? 'When someone shares a page, it shows an image instead of a blank box.'
				: 'Add a default share image so shared links do not look empty.',
			'action'       => $image ? '' : $base . '&tab=seo',
			'action_label' => $image ? '' : 'Add share image',
		);

		/**
		 * Companions add their own checks (each: label, status good|warn,
		 * message, action, action_label).
		 *
		 * @param array $checks
		 */
		return (array) apply_filters( 'optima_core_dashboard_checks', $checks );
	}

	/** @return int[] Published pages and posts with no SEO description and no excerpt. */
	private static function pages_missing_description() {
		$ids = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $ids as $id ) {
			$desc = get_post_meta( $id, OPTIMA_SEO_META_DESC, true );
			if ( '' === $desc && ! has_excerpt( $id ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	private static function pages_noindexed() {
		$ids = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'meta_key'       => OPTIMA_SEO_META_NOINDEX,
			'meta_value'     => '1',
		) );
		return count( $ids );
	}

	private static function has_share_image() {
		if ( get_site_icon_url() ) {
			return true;
		}
		return class_exists( 'Optima_Core_SEO_Settings' ) && '' !== Optima_Core_SEO_Settings::value( 'default_image' );
	}

	/* ---------- Business tab ---------- */

	public static function tab_business() {
		$values = Optima_Core_Business::get();
		$groups = array(
			'Name'           => array( 'fields' => array( 'name', 'legal_name' ), 'shows' => 'Shows on: the header and footer, digital cards, the review page, email and the search listing.' ),
			'Contact'        => array( 'fields' => array( 'phone', 'email' ), 'shows' => 'Shows on: the footer, the contact page, call buttons, digital cards and the search listing.' ),
			'Address'        => array( 'fields' => array( 'street', 'city', 'region', 'postal', 'country' ), 'shows' => 'Shows on: the footer, the contact page, digital cards and the search listing.' ),
			'Search listing' => array( 'fields' => array( 'business_type', 'price_range', 'latitude', 'longitude', 'same_as' ), 'shows' => 'Read by search engines and AI tools (schema). Latitude and longitude are optional: they pin the map exactly.' ),
		);
		if ( Optima_Core_Business::hours_elsewhere() ) {
			$hours = admin_url( 'admin.php?page=optima-hours' );
		} else {
			$groups['Hours'] = array( 'fields' => array( 'hours' ), 'shows' => 'Shows where the theme places it. Turn on Hours for structured hours, holiday closures and the schema.' );
		}
		$labels = Optima_Core_Business::fields();
		Optima_Admin_UI::old_facts_box();
		echo '<form method="post" action="options.php" class="sh-box">';
		settings_fields( 'optima_business_group' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $groups as $group => $g ) {
			echo '<tr><th colspan="2" scope="rowgroup"><span class="sh-label">' . esc_html( $group ) . '</span><p class="sh-note" style="font-weight:400;font-family:inherit;margin:4px 0 0">' . esc_html( $g['shows'] ) . '</p></th></tr>';
			foreach ( $g['fields'] as $key ) {
				$id   = 'optima-' . $key;
				$name = Optima_Core_Business::OPTION . '[' . $key . ']';
				echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $labels[ $key ] ) . '</label></th><td>';
				if ( 'business_type' === $key ) {
					$types = Optima_Core_Business::types();
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">Not set</option>';
					if ( '' !== $values[ $key ] && ! isset( $types[ $values[ $key ] ] ) ) {
						echo '<option value="' . esc_attr( $values[ $key ] ) . '" selected>' . esc_html( $values[ $key ] ) . '</option>';
					}
					foreach ( $types as $type => $label ) {
						echo '<option value="' . esc_attr( $type ) . '"' . selected( $values[ $key ], $type, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
				} elseif ( in_array( $key, array( 'hours', 'same_as' ), true ) ) {
					printf( '<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $values[ $key ] ) );
				} else {
					$kind = 'email' === $key ? 'email' : ( 'phone' === $key ? 'tel' : 'text' );
					printf( '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" />', esc_attr( $kind ), esc_attr( $id ), esc_attr( $name ), esc_attr( $values[ $key ] ) );
				}
				echo '</td></tr>';
			}
		}
		echo '</tbody></table>';
		if ( isset( $hours ) ) {
			echo '<p class="sh-note">Hours are typed on <a href="' . esc_url( $hours ) . '">Your business, Hours</a>.</p>';
		}
		submit_button( 'Save' );
		echo '</form>';
	}

	/**
	 * Spam protection carries the form guard's status on every site (dashboard
	 * audit: one name, one place). Optima Forms Guard has no settings of its own.
	 */
	private static function forms_guard_status() {
		echo '<section class="sh-box"><h2>Form guard</h2>';
		if ( class_exists( 'Optima_Forms_Guard' ) ) {
			$month = method_exists( 'Optima_Forms_Guard', 'blocked' ) ? (int) Optima_Forms_Guard::blocked() : 0;
			echo '<div class="sh-status"><span class="sh-dot" aria-hidden="true"></span><p class="sh-value">On. Every form checks each message invisibly, with no puzzle for real visitors.</p></div>';
			echo '<p class="sh-note">' . esc_html( $month ? sprintf( _n( '%d spam submission blocked this month.', '%d spam submissions blocked this month.', $month, 'optima-core' ), $month ) : 'Nothing blocked yet this month.' ) . '</p>';
		} else {
			echo '<div class="sh-status is-off"><span class="sh-dot" aria-hidden="true"></span><p class="sh-value">Off. Turn on Spam protection for forms on Build, Features.</p></div>';
		}
		echo '</section>';
	}

	/* ---------- SEO tab ---------- */

	public static function tab_seo() {
		// This tab's own checks first (section 4a: each Search and AI tab shows its checks).
		$checks = array_values( array_filter( self::checks(), static function ( $c ) {
			return in_array( $c['label'], array( 'Page descriptions', 'Visible to Google', 'Social sharing', 'Search listing (schema)' ), true );
		} ) );
		if ( $checks ) {
			self::render_checks( $checks );
		}
		$values = Optima_Core_SEO_Settings::get();
		wp_enqueue_media();
		echo '<form method="post" action="options.php" class="sh-box">';
		settings_fields( 'optima_seo_group' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( Optima_Core_SEO_Settings::fields() as $key => $label ) {
			$id   = 'optima-seo-' . $key;
			$name = Optima_Core_SEO_Settings::OPTION . '[' . $key . ']';
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( 'default_image' === $key ? 'Default share image' : $label ) . '</label></th><td>';
			if ( 'home_description' === $key ) {
				printf( '<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $values[ $key ] ) );
			} else {
				$url = (string) $values[ $key ];
				echo '<div class="optima-media">';
				echo '<img class="optima-media-preview" alt="" src="' . esc_url( $url ) . '"' . ( '' === $url ? ' hidden' : '' ) . ' style="display:block;max-width:240px;height:auto;border-radius:10px;border:1px solid #DDE4EE;margin:0 0 8px">';
				printf( '<input type="url" id="%s" name="%s" value="%s" class="regular-text" placeholder="https://" />', esc_attr( $id ), esc_attr( $name ), esc_attr( $url ) );
				echo ' <button type="button" class="button optima-media-pick" data-target="' . esc_attr( $id ) . '">Choose image</button>';
				echo '<p class="description">Used when a page has no image of its own. 1200 by 630 pixels works everywhere.</p></div>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( 'Save' );
		echo '</form>';
		?>
		<script>
		document.querySelectorAll( '.optima-media-pick' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				if ( ! window.wp || ! wp.media ) { return; }
				var input = document.getElementById( b.dataset.target ), img = b.parentNode.querySelector( '.optima-media-preview' );
				var frame = wp.media( { title: 'Default share image', library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () { var a = frame.state().get( 'selection' ).first().toJSON(); input.value = a.url; img.src = a.url; img.hidden = false; } );
				frame.open();
			} );
		} );
		</script>
		<?php
	}

	/* ---------- Branded, scoped admin styles ---------- */

	private static function styles() {
		echo '<style>
		.optima-admin .optima-lead{max-width:64ch;font-size:14px;color:var(--sh-text-2);margin:16px 0 20px}
		</style>';
	}
}
