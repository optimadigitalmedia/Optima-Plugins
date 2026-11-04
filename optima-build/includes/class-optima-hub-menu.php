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
	const BEHIND_UPDATES = array( 'optima&tab=sync', 'optima-content-updates' );

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'tidy' ), 9999 );
		add_action( 'admin_head', array( __CLASS__, 'hide_css' ) );
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
			$items[ $i ]['rank'] = $rank[ $row['name'] ] ?? ( $tools - 0.5 );
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
