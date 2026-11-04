<?php
/**
 * Optima-only dashboard rules on top of the shared Build screens.
 *
 * - The Inbox (and Overview's "Needs you") lists hours that are not confirmed
 *   yet: unconfirmed hours never show to visitors or Google, so the client has
 *   to say they are right.
 * - With the site lock on, a person who is not an operator sees only the groups
 *   they can open (Overview, Site health, Inbox). The others are hidden instead
 *   of shown as "Not on", which would read as if the features were off.
 * - The admin footer links to Request a change, never to a personal address.
 * - Optima's Design screen (the DESIGN.md tokens every plugin dresses in) files
 *   under Build, beside Features and Updates.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Optima {

	public static function boot(): void {
		add_filter( 'optima_hub_inbox', array( __CLASS__, 'hours_row' ) );
		add_filter( 'optima_admin_hidden_groups', array( __CLASS__, 'locked_groups' ) );
		add_filter( 'optima_admin_help_url', static fn() => Optima_Hub_Admin::url( 'requests' ) );
		add_filter( 'optima_admin_nav_group', array( __CLASS__, 'design_in_build' ), 10, 2 );
	}

	/** The Design screen belongs to Build (operators). */
	public static function design_in_build( $group, $slug ) {
		return ( class_exists( 'Optima_Hub_Design' ) && Optima_Hub_Design::PAGE === $slug ) ? 'build' : $group;
	}

	/** "Hours not confirmed yet" while Optima Hours is on and the hours are unconfirmed. */
	public static function hours_row( $rows ): array {
		$rows = (array) $rows;
		if ( class_exists( 'Optima_Hours' ) && method_exists( 'Optima_Hours', 'settings' ) && empty( Optima_Hours::settings()['confirmed'] ) ) {
			// Under the site lock the client cannot open Hours, so they confirm through a request.
			$locked = class_exists( 'Optima_Hub_Lock' ) && Optima_Hub_Lock::applies();
			$rows[] = array(
				'label' => __( 'Hours not confirmed yet', 'optima-build' ),
				'count' => 1,
				'url'   => $locked ? Optima_Hub_Admin::url( 'requests' ) : admin_url( 'admin.php?page=optima-hours' ),
				'note'  => $locked
					? __( 'Visitors and Google see your hours once they are confirmed. Tell Optima they are right, or what to change.', 'optima-build' )
					: __( 'Visitors and Google see your hours once you confirm they are right.', 'optima-build' ),
			);
		}
		return $rows;
	}

	/** Under the site lock, hide the groups a non-operator cannot open. */
	public static function locked_groups( $hidden ): array {
		$hidden = (array) $hidden;
		if ( class_exists( 'Optima_Hub_Lock' ) && Optima_Hub_Lock::applies() ) {
			$hidden = array_values( array_unique( array_merge( $hidden, array( 'business', 'content', 'search', 'build' ) ) ) );
		}
		return $hidden;
	}
}
