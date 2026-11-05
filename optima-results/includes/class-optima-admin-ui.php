<?php
/**
 * Optima admin kit: the shared frame for every Optima screen in the dashboard.
 *
 * Every Optima plugin (and the Optima theme) ships an identical copy of this
 * file and of assets/optima-admin.css. The class sits inside a conditional so
 * only the first copy is declared, the same way as the shared updater; CI
 * checks the copies match tools/admin-kit/.
 *
 *   Optima_Admin_UI::enqueue( plugins_url( 'assets/optima-admin.css', __FILE__ ) );
 *   Optima_Admin_UI::open( 'AI search', 'One-line description of the screen.' );
 *   ... screen content in .sh-box sections ...
 *   Optima_Admin_UI::close();
 *
 * open() prints a slim top bar (the client's logo and name, a Find box that
 * opens the WordPress Command Palette, the Optima mark), the title with its
 * group as the eyebrow, the lede, and one row of tabs: the open group's
 * screens, read live from WordPress's own menu. The WordPress sidebar is the
 * only group navigation (plugin-first rule 49; release 2); no screen repeats
 * the eight groups (Overview, People, Your business, Content, Search and AI,
 * Site health, Inbox, Build). Each screen's group comes from its address in
 * SCREENS, so a label change never moves a screen; a new screen is added to
 * SCREENS (tools/uniform-check.sh fails until it is), and the
 * optima_admin_nav_group filter can still move one.
 *
 * The Optima sidebar menu shows one link per group, named after the group, so
 * the sidebar and the screen use the same words. The other screens stay
 * registered and reachable and are hidden from the sidebar only.
 * Plugins add a short live hint to their pill with the optima_admin_nav_hint
 * filter (slug, e.g. "5 on" or "5 of 7"). A screen hidden from the sidebar
 * passes the slug it belongs under as $nav, so its pill still lights up.
 *
 * Branding follows the Site Build hub: the Optima arrow and sign-off show unless
 * a site defines OPTIMA_HUB_BRAND as false in wp-config.php (white-label).
 *
 * @package Optima_Admin_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Optima_Admin_UI', false ) ) :

	final class Optima_Admin_UI {

		const VERSION = '1.7.0.0';

		/** Show the Optima mark and sign-off. */
		public static function branded(): bool {
			return ! defined( 'OPTIMA_HUB_BRAND' ) || OPTIMA_HUB_BRAND;
		}

		/**
		 * Load the kit stylesheet once. When this class came from a plugin, use the
		 * stylesheet beside it, so the CSS always matches the markup even if an
		 * older copy (in a theme, say) is the one asking.
		 */
		public static function enqueue( string $url ): void {
			self::$kit = true;
			if ( wp_style_is( 'optima-admin-kit', 'enqueued' ) ) {
				return;
			}
			$own = dirname( __DIR__ ) . '/assets/optima-admin.css';
			if ( defined( 'WP_PLUGIN_DIR' ) && is_readable( $own ) && 0 === strpos( wp_normalize_path( $own ), wp_normalize_path( WP_PLUGIN_DIR ) ) ) {
				$url = plugins_url( 'assets/optima-admin.css', __DIR__ );
			}
			wp_enqueue_style( 'optima-admin-kit', $url, array(), self::VERSION );
			// The Find box opens WordPress's own Command Palette (6.3+); on an older
			// WordPress the script is not registered and the box hides itself.
			if ( wp_script_is( 'wp-commands', 'registered' ) ) {
				wp_enqueue_script( 'wp-commands' );
				wp_enqueue_style( 'wp-commands' );
			}
			add_action( 'admin_print_footer_scripts', array( __CLASS__, 'find_script' ), 20 );
		}

		/** The few lines behind the Find box, printed once. */
		public static function find_script(): void {
			?>
			<script>
			( function () {
				var box = document.querySelector( '.sh-find' );
				if ( ! box ) { return; }
				var open = window.wp && wp.data && wp.data.dispatch && wp.data.select && wp.data.select( 'core/commands' ) ? function () { wp.data.dispatch( 'core/commands' ).open(); } : null;
				if ( ! open ) { box.hidden = true; return; }
				box.addEventListener( 'click', open );
				document.addEventListener( 'keydown', function ( e ) {
					if ( ( e.metaKey || e.ctrlKey ) && 'k' === e.key.toLowerCase() && ! e.defaultPrevented ) { e.preventDefault(); open(); }
				} );
			} )();
			</script>
			<?php
		}

		/**
		 * Open a screen: top bar (whose site, Find, the mark), eyebrow, title,
		 * short description, and the open group's tabs.
		 *
		 * @param string $title   Screen name, e.g. "AI search".
		 * @param string $lede    One or two sentences on what the screen is for.
		 * @param string $section Eyebrow after the brand, defaults to the title.
		 * @param string $nav     Sidebar slug to highlight, for a screen not in the sidebar itself.
		 */
		public static function open( string $title, string $lede = '', string $section = '', string $nav = '' ): void {
			$brand = self::branded() ? 'Optima' : 'Site Build';
			// Inside the Optima menu the eyebrow names the screen's group, the word the sidebar shows.
			$group = self::screen_group( $nav );
			if ( '' !== $group ) {
				$section = self::GROUPS[ $group ];
			}
			echo '<div class="wrap sh">';
			echo '<div class="sh-top">';
			self::lockup();
			self::finder();
			if ( self::branded() ) {
				echo '<p class="sh-brand"><span class="sh-mark" aria-hidden="true"></span>Optima</p>';
			}
			echo '</div>';
			echo '<header class="sh-head"><div>';
			echo '<p class="sh-eyebrow">' . esc_html( $brand . ' · ' . ( '' !== $section ? $section : $title ) ) . '</p>';
			echo '<h1>' . esc_html( $title ) . '</h1>';
			if ( '' !== $lede ) {
				echo '<p class="sh-lede">' . esc_html( $lede ) . '</p>';
			}
			echo '</div></header>';
			if ( ! self::$nav_printed ) {
				self::nav( $nav );
			}
			// WordPress prints admin notices right after the first heading in .wrap.
			echo '<hr class="wp-header-end" style="display:none">';
		}

		/** The Find box: one place to jump to any screen, through the Command Palette. */
		private static function finder(): void {
			echo '<button type="button" class="sh-find" aria-label="Find a screen or setting">';
			echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="20" y1="20" x2="16.5" y2="16.5"></line></svg>';
			echo '<span>Find anything: a screen, a setting, a page</span>';
			echo '<kbd aria-hidden="true">&#8984;K</kbd>';
			echo '</button>';
		}

		/**
		 * The client's logo beside the Optima mark, top right of every screen,
		 * so it is plain whose site this is. Logo order: the optima_admin_client_logo
		 * filter, the theme's custom logo, the Site Icon; with none, the site name.
		 */
		private static function lockup(): void {
			$name = trim( (string) preg_split( '/\s+[|–—-]\s+/u', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )[0] );
			$logo = (string) apply_filters( 'optima_admin_client_logo', '' );
			if ( '' === $logo ) {
				$id   = (int) get_theme_mod( 'custom_logo' );
				$logo = $id ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '';
			}
			if ( '' === $logo && function_exists( 'get_site_icon_url' ) ) {
				$logo = (string) get_site_icon_url( 128 );
			}
			echo '<div class="sh-lockup">';
			echo '<a class="sh-client" href="' . esc_url( home_url( '/' ) ) . '" target="_blank" rel="noopener" title="' . esc_attr( $name ) . '">';
			if ( '' !== $logo ) {
				echo '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '">';
			}
			echo '<span class="sh-client-name">' . esc_html( $name ) . '</span></a>';
			echo '</div>';
		}

		/** The eight groups of the Optima menu, in the order they show (plugin-first rule 9). */
		const GROUPS = array(
			'overview' => 'Overview',
			'people'   => 'People',
			'business' => 'Your business',
			'content'  => 'Content',
			'search'   => 'Search and AI',
			'health'   => 'Site health',
			'inbox'    => 'Inbox',
			'build'    => 'Build',
		);

		/**
		 * Every Optima screen, by address, in the group it belongs to. Inside a
		 * group, the sidebar order decides which screen the group's link opens.
		 * An address ending in * covers every address that starts with the rest.
		 * tools/uniform-check.sh fails when a screen is not listed here.
		 */
		const SCREENS = array(
			'overview' => array( 'optima-overview' ),
			'people'   => array( 'optima-team', 'edit.php?post_type=optima_team', 'optima-cards' ),
			'business' => array(
				'optima',
				'optima&tab=business',
				'optima-contact',
				'optima-hours',
				'optima-results',
				'edit.php?post_type=optima_result',
				'edit.php?post_type=optima_review',
				'optima-reviews-settings',
				'optima-chat',
			),
			'content'  => array( 'optima&tab=sync', 'optima-redirects', 'optima-optimize', 'optima-optimize-sweep' ),
			'search'   => array( 'optima&tab=seo', 'optima-ai-search', 'optima-analytics', 'optima-accessibility', 'optima&tab=protect' ),
			'health'   => array( 'site-build-features&tab=health', 'site-build&tab=health', 'optima&tab=dashboard', 'site-build-robots' ),
			'inbox'    => array(
				'optima-inbox',
				'site-build-features&tab=requests',
				'site-build&tab=requests',
				'edit.php?post_type=optima_chat_q',
				'optima-reviews-inbox',
				'optima-reviews-ask',
			),
			'build'    => array(
				'site-build-features&tab=updates',
				'site-build&tab=updates',
				'site-build-features',
				'site-build-features&tab=features',
				'site-build',
				'site-build&tab=features',
				'optima-design',
				'optima-mail',
				'edit.php?post_type=optima_sitecheck',
				'optima-sitecheck',
				'optima-events-crm',
				'optima-build',
				'optima-content-updates',
			),
			// Settings tabs a theme adds to Optima Core (companion settings groups) are the client's facts.
			'_more'    => array( 'business' => 'optima&tab=*' ),
		);

		/** Optima sidebar menus the kit tidies. */
		const MENUS = array( 'optima-build', 'recon-build', 'site-build', 'optima' );

		/** An Optima menu with more screens than this shows one sidebar link per group. */
		const COMPACT_AFTER = 1;

		/** @var array<string,string> Original sidebar label per slug, for the section nav. */
		private static $labels = array();

		/** @var array<string,string> Slug => the slug of its group's sidebar link. */
		private static $leaders = array();

		/** @var array<string,string> Group => [menu, slug of its sidebar link], for screens with no menu entry. */
		private static $group_links = array();

		/** @var bool A Optima screen loaded the kit styles (it prints its own nav). */
		private static $kit = false;

		/** @var bool The section nav already printed on this screen. */
		private static $nav_printed = false;

		/** @var string Sidebar slug of a plain WordPress screen that gets the nav on top. */
		private static $screen_slug = '';

		/**
		 * Shorten the Optima sidebar menu to one link per group (Overview, People, Your
		 * business, Content, Search and AI, Site health, Inbox, Build). Every screen stays registered and
		 * reachable; the others are hidden from the sidebar only, and the
		 * section nav on each screen leads to them.
		 */
		public static function compact_menu(): void {
			global $submenu;
			foreach ( self::MENUS as $parent ) {
				if ( empty( $submenu[ $parent ] ) || count( $submenu[ $parent ] ) <= self::COMPACT_AFTER ) {
					continue;
				}
				// Same order as the section nav: group by group, sidebar order inside each.
				$order = array_flip( array_keys( self::GROUPS ) );
				$keyed = array();
				foreach ( array_values( $submenu[ $parent ] ) as $i => $item ) {
					$keyed[] = array( $order[ self::group( (string) ( $item[2] ?? '' ), self::clean( (string) ( $item[0] ?? '' ) ) ) ], $i, $item );
				}
				usort( $keyed, static fn( $x, $y ) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1] );
				$submenu[ $parent ] = array_column( $keyed, 2 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering only.
				$lead   = array(); // group => index of its sidebar link.
				$bubble = array(); // group => count bubble: the group link's own, else the first in the group.
				foreach ( $submenu[ $parent ] as $i => $item ) {
					$slug  = (string) ( $item[2] ?? '' );
					$raw   = (string) ( $item[0] ?? '' );
					$label = self::clean( $raw );
					$group = self::group( $slug, $label );
					self::$labels[ $slug ] = $label;
					$leads = ! isset( $lead[ $group ] ) && current_user_can( (string) ( $item[1] ?? 'manage_options' ) );
					if ( ( $leads || ! isset( $bubble[ $group ] ) ) && preg_match( '#<span[^>]*class="[^"]*(update-plugins|awaiting-mod|count)[^"]*"[^>]*>.*?</span>(\s*</span>)?#s', $raw, $m ) ) {
						$bubble[ $group ] = ' ' . $m[0];
					}
					if ( $leads ) {
						$lead[ $group ] = $i;
					}
				}
				foreach ( $submenu[ $parent ] as $i => $item ) {
					$slug  = (string) ( $item[2] ?? '' );
					$group = self::group( $slug, self::$labels[ $slug ] );
					if ( ! isset( $lead[ $group ] ) ) {
						continue;
					}
					$item[3] = $item[3] ?? self::$labels[ $slug ];
					if ( $lead[ $group ] === $i ) {
						$item[0] = esc_html( self::GROUPS[ $group ] ) . ( $bubble[ $group ] ?? '' );
					} else {
						$item[4] = trim( ( $item[4] ?? '' ) . ' sh-menu-hidden' );
					}
					self::$leaders[ $slug ] = (string) $submenu[ $parent ][ $lead[ $group ] ][2];
					self::$group_links[ $group ] = array( $parent, self::$leaders[ $slug ] );
					$submenu[ $parent ][ $i ] = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- relabel and hide only.
				}
			}
		}

		/** On a hidden screen, light up its group's sidebar link. */
		public static function current_link( $file ) {
			if ( ! self::$leaders ) {
				return $file;
			}
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			// phpcs:enable
			foreach ( array( (string) $file, $page . '&tab=' . $tab, $page ) as $slug ) {
				if ( '' !== $slug && isset( self::$leaders[ $slug ] ) ) {
					return self::$leaders[ $slug ];
				}
			}
			$link = self::loose_link( $page );
			return $link ? $link[1] : $file;
		}

		/**
		 * A screen opened from a button (no menu entry of its own, such as the
		 * robots.txt file) still belongs to a group: its group's menu and link.
		 *
		 * @return array{0:string,1:string}|null
		 */
		private static function loose_link( string $page ): ?array {
			global $submenu;
			if ( '' === $page ) {
				return null;
			}
			foreach ( (array) ( $submenu[''] ?? array() ) as $item ) {
				if ( ( $item[2] ?? '' ) === $page ) {
					$group = self::listed( $page );
					return ( '' !== $group && isset( self::$group_links[ $group ] ) ) ? self::$group_links[ $group ] : null;
				}
			}
			return null;
		}

		/** Keep the Optima menu open on a screen opened from a button. */
		public static function current_menu( $file ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
			$link = self::loose_link( isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '' );
			return $link ? $link[0] : $file;
		}

		/** Hide the grouped screens' sidebar links. */
		public static function menu_css(): void {
			if ( self::$leaders ) {
				echo '<style>#adminmenu .sh-menu-hidden{display:none}</style>';
			}
		}

		/** A sidebar label without its count bubble. */
		private static function clean( string $raw ): string {
			return trim( wp_strip_all_tags( preg_replace( '#<span[^>]*class="[^"]*(update-plugins|awaiting-mod|count)[^"]*"[^>]*>.*?</span>#s', '', $raw ) ) );
		}

		/**
		 * WordPress's own screens inside a shortened Optima menu (a review list,
		 * Chat questions, team members) do not print the section nav, and their
		 * sibling screens are hidden from the sidebar. Give those screens the
		 * same nav on top, so no screen is a dead end.
		 */
		public static function screen_nav_prepare(): void {
			// WordPress's own screens (a post list, an edit screen) load the kit styles
			// but never print the kit nav; only admin.php?page= screens do.
			if ( ! self::$leaders || ( self::$kit && ! empty( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
				return;
			}
			global $typenow, $pagenow;
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			// phpcs:enable
			$slug = '';
			foreach ( array( '' !== $tab ? $page . '&tab=' . $tab : '', $page ) as $try ) {
				if ( '' !== $try && isset( self::$leaders[ $try ] ) ) {
					$slug = $try;
					break;
				}
			}
			if ( '' === $slug && '' === $page && $typenow && in_array( $pagenow, array( 'edit.php', 'post.php', 'post-new.php', 'edit-tags.php', 'term.php' ), true ) ) {
				$list = 'edit.php?post_type=' . $typenow;
				$slug = isset( self::$leaders[ $list ] ) ? $list : '';
			}
			if ( '' === $slug ) {
				return;
			}
			self::$screen_slug = $slug;
			$own = dirname( __DIR__ ) . '/assets/optima-admin.css';
			if ( is_readable( $own ) && defined( 'WP_PLUGIN_DIR' ) && 0 === strpos( wp_normalize_path( $own ), wp_normalize_path( WP_PLUGIN_DIR ) ) ) {
				wp_enqueue_style( 'optima-admin-kit', plugins_url( 'assets/optima-admin.css', __DIR__ ), array(), self::VERSION );
			}
		}

		/** Print the nav at the top of a plain WordPress screen picked above. */
		public static function screen_nav(): void {
			if ( '' === self::$screen_slug || self::$nav_printed ) {
				return;
			}
			echo '<div class="sh sh-screen-nav">';
			self::nav( self::$screen_slug );
			echo '</div>';
		}

		/**
		 * Which of the eight groups a screen belongs to, read from its address in
		 * SCREENS. Plugins can move a screen with the optima_admin_nav_group
		 * filter (group key, slug, label). A screen not listed sits in Content, and
		 * tools/uniform-check.sh fails until it is listed.
		 */
		public static function group( string $slug, string $label ): string {
			$group = self::listed( $slug );
			$group = (string) apply_filters( 'optima_admin_nav_group', '' !== $group ? $group : 'content', $slug, $label );
			return isset( self::GROUPS[ $group ] ) ? $group : 'content';
		}

		/** The group SCREENS lists an address in, or '' when it is not listed. */
		public static function listed( string $slug ): string {
			foreach ( self::GROUPS as $key => $name ) {
				if ( in_array( $slug, self::SCREENS[ $key ], true ) ) {
					return $key;
				}
			}
			foreach ( self::SCREENS['_more'] as $key => $pattern ) {
				if ( '*' === substr( $pattern, -1 ) && 0 === strpos( $slug, substr( $pattern, 0, -1 ) ) ) {
					return $key;
				}
			}
			return '';
		}

		/**
		 * The group of the screen being viewed, when it sits in an Optima menu;
		 * '' on any other screen (a plugin's own top-level menu keeps its words).
		 */
		private static function screen_group( string $nav = '' ): string {
			global $submenu;
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			// phpcs:enable
			$want = '' !== $nav ? $nav : $page . ( '' !== $tab ? '&tab=' . $tab : '' );
			if ( '' === $want ) {
				return '';
			}
			foreach ( self::MENUS as $parent ) {
				foreach ( (array) ( $submenu[ $parent ] ?? array() ) as $item ) {
					if ( strtok( (string) ( $item[2] ?? '' ), '&' ) === strtok( $want, '&' ) ) {
						$listed = self::listed( $want );
						return self::group( '' !== $listed ? $want : strtok( $want, '&' ), '' );
					}
				}
			}
			return '';
		}

		/**
		 * A screen opened from a button rather than the sidebar has no menu entry,
		 * so WordPress has no title for it and passes null to strip_tags() (a PHP 8.1
		 * deprecation notice). Give it the title it was registered with.
		 */
		public static function hidden_title(): void {
			global $title, $submenu, $plugin_page;
			if ( ! empty( $title ) || empty( $plugin_page ) ) {
				return;
			}
			foreach ( (array) ( $submenu[''] ?? array() ) as $item ) {
				if ( ( $item[2] ?? '' ) === $plugin_page && '' !== (string) ( $item[3] ?? '' ) ) {
					$title = (string) $item[3]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- filling a missing title only.
					return;
				}
			}
		}

		/**
		 * The tab row: the screens of this screen's group, read from its sidebar
		 * menu. The groups themselves are the sidebar's job (rule 49), so nothing
		 * here repeats them. A group with one screen shows no tabs.
		 *
		 * @param string $current Sidebar slug to highlight; defaults to the screen being viewed.
		 */
		public static function nav( string $current = '' ): void {
			global $submenu;
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			// phpcs:enable
			$want   = '' !== $current ? $current : $page;
			$parent = '';
			foreach ( (array) $submenu as $slug => $items ) {
				foreach ( (array) $items as $item ) {
					if ( strtok( (string) ( $item[2] ?? '' ), '&' ) === strtok( $want, '&' ) ) {
						$parent = (string) $slug;
						break 2;
					}
				}
			}
			$loose = '';
			if ( '' === $parent ) {
				$link = self::loose_link( $want );
				if ( $link ) {
					$parent = $link[0];
					$loose  = (string) self::listed( $want );
				}
			}
			$items = array_values(
				array_filter(
					(array) ( $submenu[ $parent ] ?? array() ),
					static fn( $item ) => current_user_can( (string) ( $item[1] ?? 'manage_options' ) )
				)
			);
			if ( count( $items ) < 2 ) {
				return;
			}
			// The entry that matches the address best: same page and tab, else the page's plain entry.
			$active = '';
			$target = '' !== $current ? $current : $page . ( '' !== $tab ? '&tab=' . $tab : '' );
			foreach ( $items as $item ) {
				if ( $item[2] === $target ) {
					$active = $item[2];
				}
			}
			if ( '' === $active ) {
				foreach ( $items as $item ) {
					if ( $item[2] === strtok( $target, '&' ) ) {
						$active = $item[2];
					}
				}
			}
			// Sort into groups, keeping sidebar order inside each.
			$groups = array_fill_keys( array_keys( self::GROUPS ), array() );
			$open   = '';
			foreach ( $items as $item ) {
				$slug  = (string) $item[2];
				$label = self::$labels[ $slug ] ?? self::clean( (string) $item[0] );
				// A plugin's own top-level menu (Events, Members) keeps one plain row of tabs.
				$key   = in_array( $parent, self::MENUS, true ) ? self::group( $slug, $label ) : 'content';
				$groups[ $key ][] = array(
					'url'   => false !== strpos( $slug, '.php' ) ? admin_url( $slug ) : admin_url( 'admin.php?page=' . $slug ),
					'label' => $label,
					'hint'  => (string) apply_filters( 'optima_admin_nav_hint', '', $slug ),
					'on'    => $slug === $active,
				);
				if ( $slug === $active ) {
					$open = $key;
				}
			}
			$groups = array_filter( $groups );
			if ( '' === $open && '' !== $loose && isset( $groups[ $loose ] ) ) {
				$open = $loose;
			}
			if ( '' === $open ) {
				$open = (string) array_key_first( $groups );
			}
			$link = static function ( array $entry, string $text, bool $on, string $hint ): void {
				printf(
					'<a href="%1$s"%2$s>%3$s%4$s</a>',
					esc_url( $entry['url'] ),
					$on ? ' class="is-active" aria-current="page"' : '',
					esc_html( $text ),
					'' !== $hint ? '<span class="sh-nav-hint">' . esc_html( $hint ) . '</span>' : ''
				);
			};
			self::$nav_printed = true;
			if ( count( $groups[ $open ] ?? array() ) < 2 ) {
				return;
			}
			echo '<nav class="sh-nav" aria-label="' . esc_attr( self::GROUPS[ $open ] ?? ( self::branded() ? 'Optima' : 'Site Build' ) ) . '">';
			echo '<div class="sh-nav-sub">';
			foreach ( $groups[ $open ] as $entry ) {
				$link( $entry, $entry['label'], $entry['on'], $entry['hint'] );
			}
			echo '</div>';
			echo '</nav>';
		}

		/** Close a screen with the Optima sign-off. */
		public static function close(): void {
			if ( self::branded() ) {
				// No personal address on client screens: questions go through Request a change.
				$help = (string) apply_filters( 'optima_admin_help_url', '' );
				echo '<footer class="sh-foot"><span class="sh-mark" aria-hidden="true"></span><p>Built and looked after by Optima Digital.'
					. ( '' !== $help ? ' <a href="' . esc_url( $help ) . '">Request a change</a>' : '' ) . '</p></footer>';
			}
			echo '</div>';
		}
	}

	// After every plugin and the hub's own ordering (999) have built the menu.
	add_action( 'admin_menu', array( 'Optima_Admin_UI', 'compact_menu' ), 1000 );
	add_filter( 'submenu_file', array( 'Optima_Admin_UI', 'current_link' ), 20 );
	add_filter( 'parent_file', array( 'Optima_Admin_UI', 'current_menu' ), 20 );
	add_action( 'admin_head', array( 'Optima_Admin_UI', 'menu_css' ) );
	add_action( 'admin_enqueue_scripts', array( 'Optima_Admin_UI', 'screen_nav_prepare' ), 99 );
	add_action( 'all_admin_notices', array( 'Optima_Admin_UI', 'screen_nav' ), 1 );
	add_action( 'current_screen', array( 'Optima_Admin_UI', 'hidden_title' ) );

endif;
