<?php
/**
 * Plugin first: which plugin owns which job, and the slots plugin blocks render in.
 *
 * The rule (docs/plugin-first.md): a plugin owns each job. A theme does a job
 * only as a fallback, when optima_owns( $job ) is false. Themes place plugin
 * blocks only through optima_slot( $name ), so every feature appears in the
 * same spot on every site.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Owns {

	/** Job => the plugin slug that owns it. */
	const JOBS = array(
		'seo'           => 'optima-core',
		'schema'        => 'optima-core',
		'content-sync'  => 'optima-core',
		'ai-search'     => 'optima-aeo',
		'redirects'     => 'optima-redirects',
		'accessibility' => 'optima-accessibility',
		'team'          => 'optima-team',
		'cards'         => 'optima-cards',
		'call-bar'      => 'optima-contact',
		'results'       => 'optima-results',
		'hours'         => 'optima-hours',
		'reviews'       => 'optima-reviews',
		'analytics'     => 'optima-analytics',
		'forms-guard'   => 'optima-forms-guard',
		'chat'          => 'optima-chat',
		'account-privacy' => 'optima-build',
	);

	/** The fixed spots a theme offers for plugin blocks. */
	const SLOTS = array( 'header', 'after-hero', 'before-close', 'footer', 'footer-contact', 'body-end' );

	/** @var array<string,bool>|null Active plugin slugs, read once per request. */
	private static $active = null;

	/** True when the plugin that owns $job is active on this site. */
	public static function owns( string $job ): bool {
		$slug  = self::JOBS[ $job ] ?? '';
		$owned = '' !== $slug && self::is_active( $slug );
		/**
		 * Filters whether a plugin owns a job on this site.
		 *
		 * @param bool   $owned True when the owning plugin is active.
		 * @param string $job   The job name.
		 * @param string $slug  The owning plugin's slug ('' for an unknown job).
		 */
		return (bool) apply_filters( 'optima_owns', $owned, $job, $slug );
	}

	/** The owning plugin's slug for a job, or ''. */
	public static function owner( string $job ): string {
		return self::JOBS[ $job ] ?? '';
	}

	/** Jobs a plugin owns. */
	public static function jobs_for( string $slug ): array {
		return array_keys( array_filter( self::JOBS, static fn( $s ) => $s === $slug ) );
	}

	private static function is_active( string $slug ): bool {
		if ( null === self::$active ) {
			self::$active = array();
			$list = (array) get_option( 'active_plugins', array() );
			if ( is_multisite() ) {
				$list = array_merge( $list, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}
			foreach ( $list as $file ) {
				self::$active[ dirname( (string) $file ) ] = true;
			}
		}
		return isset( self::$active[ $slug ] );
	}

	/** Prints whatever plugins attached to a slot. Unknown slots print nothing. */
	public static function slot( string $name, array $args = array() ): void {
		if ( ! in_array( $name, self::SLOTS, true ) ) {
			return;
		}
		/**
		 * Plugins attach their block to a slot here.
		 *
		 * @param array $args Anything the theme passes along (for example the page context).
		 */
		do_action( 'optima_slot_' . $name, $args );
	}

	/** True when at least one plugin attached to a slot. */
	public static function slot_has( string $name ): bool {
		return in_array( $name, self::SLOTS, true ) && has_action( 'optima_slot_' . $name );
	}
}

if ( ! function_exists( 'optima_owns' ) ) {
	/** True when the plugin that owns $job is active; the theme then skips its own version. */
	function optima_owns( string $job ): bool {
		return Optima_Hub_Owns::owns( $job );
	}
}

if ( ! function_exists( 'optima_slot' ) ) {
	/** Renders the plugin blocks attached to a fixed theme spot. */
	function optima_slot( string $name, array $args = array() ): void {
		Optima_Hub_Owns::slot( $name, $args );
	}
}

if ( ! function_exists( 'optima_slot_has' ) ) {
	/** True when a plugin attached a block to the slot. */
	function optima_slot_has( string $name ): bool {
		return Optima_Hub_Owns::slot_has( $name );
	}
}
