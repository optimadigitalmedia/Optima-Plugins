<?php
/**
 * Search and AI -> Spam protection on a site without Optima Core (the agency's
 * own site). With Core on, Core's Spam protection tab shows this status with
 * its two switches, so the name and the place are the same on every site
 * (dashboard audit). The guard has no settings: the screen says what it does
 * and what it blocked.
 *
 * @package Optima_Forms_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Forms_Guard_Admin {

	const PAGE = 'optima-forms-guard';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Only inside an Optima menu, and only when Optima Core does not carry the tab. */
	private static function parent(): string {
		global $admin_page_hooks;
		if ( class_exists( 'Optima_Core_Admin' ) || ! class_exists( 'Optima_Hub_Admin' ) ) {
			return '';
		}
		$parent = Optima_Hub_Admin::parent();
		return ( 'site-build' === $parent || isset( $admin_page_hooks[ $parent ] ) ) ? $parent : '';
	}

	public static function menu(): void {
		$parent = self::parent();
		if ( '' !== $parent ) {
			add_submenu_page( $parent, 'Spam protection', 'Spam protection', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
		}
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::PAGE ) ) {
			Optima_Admin_UI::enqueue( plugins_url( 'assets/optima-admin.css', OPTIMA_FORMS_GUARD_FILE ) );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$month = (int) Optima_Forms_Guard::blocked();
		$year  = (int) Optima_Forms_Guard::blocked( 'all' );
		Optima_Admin_UI::open( 'Spam protection', 'What keeps spam away from the forms on this site. It runs by itself; there is nothing to set.' );
		echo '<section class="sh-box"><h2>Form guard</h2>';
		echo '<div class="sh-status"><span class="sh-dot" aria-hidden="true"></span><p class="sh-value">On. Every form checks each message invisibly, with no puzzle for real visitors.</p></div>';
		/* translators: 1: blocked this month, 2: blocked in the last year */
		echo '<p class="sh-note">' . esc_html( sprintf( '%1$d blocked this month, %2$d in the last year.', $month, $year ) ) . '</p>';
		echo '</section>';
		$upload = Optima_Forms_Guard::upload_check();
		echo '<section class="sh-box sh-check is-' . esc_attr( (string) $upload['state'] ) . '"><p class="sh-label">' . esc_html( (string) $upload['label'] ) . '</p>';
		echo '<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span>' . esc_html( (string) $upload['value'] ) . '</p>';
		echo '<p class="sh-note">' . esc_html( (string) $upload['note'] ) . '</p></section>';
		Optima_Admin_UI::close();
	}
}
