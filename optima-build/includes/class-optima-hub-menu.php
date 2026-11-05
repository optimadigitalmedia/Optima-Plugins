<?php
/**
 * One menu, the same on every site (plugin-first rules 9, 10 and 44).
 *
 * Every Optima plugin adds its screen under the Optima menu. This class runs
 * last and tidies that menu: one fixed order that follows the eight groups
 * (Overview, People, Your business, Content, Search and AI, Site health, Inbox, Build),
 * plain names ("Team", not "Team settings"), and Build only for operators.
 * The shared admin kit then shows one sidebar link per group. Every screen
 * keeps its own address; no plugin has to change for this.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Menu {

	/**
	 * Sidebar order by screen address, group by group. Inside a group the first
	 * screen is the one the group's sidebar link opens. "{hub}" is the hub's own
	 * page (site-build-features, or site-build when the hub made the menu).
	 * Screens not listed sit at the end of their group.
	 */
	const ORDER = array(
		// Overview.
		'optima-overview',
		// People.
		'optima-team',
		'edit.php?post_type=optima_team',
		'optima-cards',
		// Your business: Details, Hours, Call buttons, Case results, Reviews, Chat.
		'optima',
		'optima&tab=business',
		'optima-hours',
		'optima-contact',
		'optima-results',
		'edit.php?post_type=optima_result',
		'optima-reviews-settings',
		'edit.php?post_type=optima_review',
		'optima-reviews-ask',
		'optima-chat',
		// Content.
		'optima-pages',
		'edit.php?post_type=testimonial',
		'edit.php?post_type=faq',
		'optima&tab=sync',
		'optima-redirects',
		'optima-optimize',
		// Search and AI.
		'optima&tab=seo',
		'optima-ai-search',
		'optima-analytics',
		'optima-accessibility',
		'optima&tab=protect',
		'optima-forms-guard',
		// Site health.
		'{hub}&tab=health',
		'optima&tab=dashboard',
		// Inbox.
		'optima-inbox',
		'{hub}&tab=requests',
		'edit.php?post_type=optima_chat_q',
		'optima-reviews-inbox',
		// Build: the update queue first.
		'{hub}&tab=updates',
		'{hub}',
		'optima-design',
		'optima-mail',
		'edit.php?post_type=optima_sitecheck',
		'optima-sitecheck',
		'optima-events-crm',
		'optima-build',
		'optima-content-updates',
	);

	/** Old name => plain name. Empty since every plugin names its screen the way the sidebar does (rule 44). */
	const RENAME = array();

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'tidy' ), 999 ); // Before the admin kit picks each group's link (1000).
		// After WordPress has checked access to the screen, Build's screens leave the
		// menu list for anyone who is not an operator, so neither the sidebar nor the
		// shared tab row shows them.
		add_action( 'admin_head', array( __CLASS__, 'drop_build' ), 999 );
		// The admin footer's help link: Request a change, never a personal address.
		add_filter( 'optima_admin_help_url', static fn() => Optima_Hub_Admin::url( 'requests' ) );
		// The sign-off is for the people the agency builds for; operators are the agency.
		add_filter( 'optima_admin_show_foot', static fn( $show ) => $show && ! Optima_Hub_Access::is_operator() );
	}

	/** Is this menu entry one of Build's screens? */
	public static function is_build( string $slug ): bool {
		return 'build' === Optima_Admin_UI::listed( $slug );
	}

	/** Remove Build's screens from the menu list for anyone who is not an operator. */
	public static function drop_build(): void {
		global $submenu;
		if ( Optima_Hub_Access::is_operator() ) {
			return;
		}
		$parent = Optima_Hub_Admin::parent();
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}
		foreach ( $submenu[ $parent ] as $i => $item ) {
			if ( self::is_build( (string) ( $item[2] ?? '' ) ) ) {
				unset( $submenu[ $parent ][ $i ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- hiding our own items after the access check.
			}
		}
	}

	public static function tidy(): void {
		global $submenu;
		$parent = Optima_Hub_Admin::parent();
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}
		$hub   = 'site-build' === $parent ? 'site-build' : Optima_Hub_Admin::PAGE;
		$rank  = array_flip( str_replace( '{hub}', $hub, self::ORDER ) );
		$items = array();
		foreach ( array_values( $submenu[ $parent ] ) as $i => $item ) {
			$slug = (string) ( $item[2] ?? '' );
			$name = trim( wp_strip_all_tags( (string) ( $item[0] ?? '' ) ) );
			$name = preg_replace( '/\s*\d+$/', '', $name ); // Drop a count bubble from the name.
			foreach ( self::RENAME as $from => $to ) {
				if ( 0 === strcasecmp( $name, $from ) ) {
					$item[0] = str_ireplace( $from, $to, (string) $item[0] );
				}
			}
			$items[] = array( 'rank' => $rank[ $slug ] ?? count( $rank ), 'pos' => $i, 'item' => $item );
		}
		usort(
			$items,
			static fn( $a, $b ) => $a['rank'] <=> $b['rank'] ?: $a['pos'] <=> $b['pos']
		);
		$submenu[ $parent ] = array_values( array_column( $items, 'item' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering our own menu.
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

	/** The screen that applies approved theme copy: Optima Core's Site Sync, or the theme's own fallback screen. */
	public static function content_screen(): array {
		if ( function_exists( 'optima_owns' ) && optima_owns( 'content-sync' ) ) {
			return array(
				'label' => __( 'Approved copy', 'optima-build' ),
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
