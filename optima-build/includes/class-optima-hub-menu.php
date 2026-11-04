<?php
/**
 * One menu, the same on every site (plugin-first rules 9 and 10).
 *
 * Every Optima plugin adds its screen under the Optima menu. This class runs
 * last and tidies that menu: one fixed order, plain names ("Team", not
 * "Team settings"), and every update tool behind one item, Updates. Screens
 * that move behind Updates stay reachable at their own address; they just
 * leave the sidebar. No plugin has to change for this.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Menu {

	/** Sidebar order by item name. Names not listed sit after the feature group, before the tools. */
	const ORDER = array(
		'Features',
		'Business info',
		// Features with settings, in a fixed order.
		'Call buttons',
		'Hours',
		'Reviews',
		'Team',
		'Team members',
		'Case results',
		'All case results',
		'Cards & Links',
		'Chat',
		'Chat questions',
		'Redirects',
		'Accessibility',
		'Analytics',
		'AI search',
		'SEO',
		'Spam protection',
		'Email',
		'Site check',
		'Site checks',
		'Customer list',
		// Tools, always last.
		'Design',
		'Updates',
		'Site health',
		'Search health',
		'Request a change',
	);

	/** Old name => plain name. */
	const RENAME = array(
		'Team settings'         => 'Team',
		'Case results settings' => 'Case results',
		'Chat settings'         => 'Chat',
		'Site check settings'   => 'Site check',
	);

	/** Items that live behind Updates instead of in the sidebar (matched on the menu slug). */
	const BEHIND_UPDATES = array( 'optima&tab=sync', 'optima-content-updates', 'optima-build' );

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'tidy' ), 9999 );
		add_action( 'admin_head', array( __CLASS__, 'hide_css' ) );
		// After WordPress has checked access to the screen, the screens behind Updates leave
		// the menu list, so neither the sidebar nor the shared tab row shows them.
		add_action( 'admin_head', array( __CLASS__, 'drop_behind' ), 999 );
		add_filter( 'optima_admin_nav_group', array( __CLASS__, 'nav_group' ), 10, 2 );
		// The admin footer's help link: Request a change, never a personal address.
		add_filter( 'optima_admin_help_url', static fn() => Optima_Hub_Admin::url( 'requests' ) );
	}

	/** Remove the screens behind Updates from the menu list, except the one being viewed. */
	public static function drop_behind(): void {
		global $submenu;
		$parent = Optima_Hub_Admin::parent();
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$here = $page . ( '' !== $tab ? '&tab=' . $tab : '' );
		foreach ( $submenu[ $parent ] as $i => $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( in_array( $slug, self::BEHIND_UPDATES, true ) && $slug !== $here && $slug !== $page ) {
				unset( $submenu[ $parent ][ $i ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- hiding our own items after the access check.
			}
		}
	}

	/** While one of those screens is open, it sits in Home, next to Updates. */
	public static function nav_group( string $group, string $slug ): string {
		if ( in_array( $slug, self::BEHIND_UPDATES, true ) ) {
			return 'home';
		}
		// Business info is about the business, not search: it sits with Call buttons, Hours and Team.
		if ( 'optima' === $slug || 'optima&tab=business' === $slug ) {
			return 'business';
		}
		return $group;
	}

	/** The theme's own setup screen (fallback when no plugin does the job), if the theme registers one. */
	public static function theme_setup_screen(): array {
		global $_registered_pages;
		$hook = get_plugin_page_hookname( 'optima-build', Optima_Hub_Admin::parent() );
		if ( isset( $_registered_pages[ $hook ] ) ) {
			return array(
				'label' => __( 'Site setup', 'optima-build' ),
				'url'   => admin_url( 'admin.php?page=optima-build' ),
			);
		}
		return array();
	}

	/** Screens that live behind Updates stay reachable but leave the sidebar. */
	public static function hide_css(): void {
		$sel = array();
		foreach ( self::BEHIND_UPDATES as $slug ) {
			$sel[] = '#adminmenu a[href$="page=' . esc_attr( $slug ) . '"]';
			$sel[] = '#adminmenu a[href$="' . esc_attr( $slug ) . '"]';
		}
		echo '<style id="optima-behind-updates">' . implode( ',', $sel ) . '{display:none!important}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped constants.
	}

	public static function tidy(): void {
		global $submenu;
		$parent = Optima_Hub_Admin::parent();
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}
		$items = array();
		foreach ( $submenu[ $parent ] as $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( in_array( $slug, self::BEHIND_UPDATES, true ) ) {
				// Keep it registered (WordPress refuses a page that leaves the menu); hide it from the sidebar instead.
				$item[4] = trim( ( $item[4] ?? '' ) . ' optima-behind-updates' );
			}
			$name = trim( wp_strip_all_tags( (string) ( $item[0] ?? '' ) ) );
			$name = preg_replace( '/\s*\d+$/', '', $name ); // Drop a count bubble from the name used for sorting.
			foreach ( self::RENAME as $from => $to ) {
				if ( 0 === strcasecmp( $name, $from ) ) {
					$item[0] = str_ireplace( $from, $to, (string) $item[0] );
					$name    = $to;
				}
			}
			$items[] = array( 'name' => $name, 'item' => $item );
		}
		$rank  = array_flip( self::ORDER );
		$tools = $rank['Design'];
		foreach ( $items as $i => $row ) {
			$slug = (string) ( $row['item'][2] ?? '' );
			// The hub's own screen (Features) always comes first, whatever the shared nav calls it,
			// so clicking the top-level menu item opens it.
			$first = in_array( $slug, array( Optima_Hub_Admin::PAGE, 'site-build' ), true );
			$items[ $i ]['rank'] = $first ? -1 : ( $rank[ $row['name'] ] ?? ( $tools - 0.5 ) );
			$items[ $i ]['pos']  = $i;
		}
		usort(
			$items,
			static fn( $a, $b ) => $a['rank'] <=> $b['rank'] ?: $a['pos'] <=> $b['pos']
		);
		$submenu[ $parent ] = array_values( array_column( $items, 'item' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering our own menu.
	}

	/** The screen that applies approved theme copy: Optima Core's Site Sync, or the theme's own fallback screen. */
	public static function content_screen(): array {
		if ( function_exists( 'optima_owns' ) && optima_owns( 'content-sync' ) ) {
			return array(
				'label' => __( 'Site Sync', 'optima-build' ),
				'url'   => admin_url( 'admin.php?page=optima&tab=sync' ),
				'by'    => __( 'Optima Core', 'optima-build' ),
			);
		}
		global $admin_page_hooks, $_registered_pages;
		$hook = get_plugin_page_hookname( 'optima-content-updates', Optima_Hub_Admin::parent() );
		if ( isset( $admin_page_hooks['optima-content-updates'] ) || isset( $_registered_pages[ $hook ] ) ) {
			return array(
				'label' => __( 'Content updates', 'optima-build' ),
				'url'   => admin_url( 'admin.php?page=optima-content-updates' ),
				'by'    => __( 'the theme', 'optima-build' ),
			);
		}
		return array();
	}
}
