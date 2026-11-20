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
		'law'           => 'optima-law',
		'account-privacy' => 'optima-build',
	);

	/**
	 * The fixed spots a theme offers for plugin blocks. The second line is the
	 * law-site page layer (release 2 contract, section 3): each is filled by
	 * one plugin and prints nothing while that plugin is off.
	 */
	const SLOTS = array(
		'header', 'after-hero', 'before-close', 'footer', 'footer-contact', 'body-end',
		'breadcrumb', 'state-law', 'practice-results', 'practice-reviews', 'practice-attorneys', 'related-guides', 'next-steps', 'office-card', 'bio-glance', 'bio-results',
	);

	/** Slot => its key in the layout recipe's "blocks" (where the slot's variant is named). */
	const VARIANT_KEYS = array(
		'practice-results'   => 'results',
		'practice-reviews'   => 'reviews',
		'practice-attorneys' => 'attorneys',
		'state-law'          => 'state_law',
		'next-steps'         => 'next_steps',
		'office-card'        => 'office',
		'related-guides'     => 'guides',
	);

	/** The option Optima Build fills from optima-setup.json "layout" (Optima_Hub_Setup::layout()). */
	const LAYOUT_OPTION = 'optima_layout_recipe';

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
		// A theme that passes no variant gets the one its layout recipe names (layout.blocks).
		if ( ! isset( $args['variant'] ) && isset( self::VARIANT_KEYS[ $name ] ) ) {
			$variant = self::layout( 'blocks.' . self::VARIANT_KEYS[ $name ], '' );
			if ( is_string( $variant ) && '' !== $variant ) {
				$args['variant'] = $variant;
			}
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

	/** @var array|null The layout recipe, read once per request. */
	private static $layout = null;

	/**
	 * One value from the site's layout recipe (optima-setup.json "layout",
	 * stored by Optima Build as the optima_layout_recipe option). A dotted key
	 * reads inside it: 'hero', 'home_order', 'blocks.results'. Before Build has
	 * stored the recipe (no operator has opened the dashboard since the theme
	 * changed), it reads the theme's setup file directly.
	 *
	 * @param string $key     The key, dotted for nested values ('' for the whole recipe).
	 * @param mixed  $default What to return when the recipe does not set it.
	 * @return mixed
	 */
	public static function layout( string $key = '', $default = null ) {
		if ( null === self::$layout ) {
			$saved = get_option( self::LAYOUT_OPTION, null );
			if ( is_array( $saved ) ) {
				self::$layout = $saved;
			} elseif ( class_exists( 'Optima_Hub_Setup', false ) ) {
				$raw          = Optima_Hub_Setup::data()['layout'] ?? array();
				self::$layout = is_array( $raw ) ? Optima_Hub_Setup::clean_layout( $raw ) : array();
			} else {
				self::$layout = array();
			}
		}
		$value = self::$layout;
		foreach ( '' === $key ? array() : explode( '.', $key ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return $default;
			}
			$value = $value[ $part ];
		}
		return ( '' === $value || array() === $value ) ? $default : $value;
	}

	/** Forget the cached recipe (after Build stores a new one). */
	public static function flush_layout(): void {
		self::$layout = null;
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

if ( ! function_exists( 'optima_layout' ) ) {
	/**
	 * One value from the site's layout recipe (optima-setup.json "layout"), for
	 * themes and plugins: optima_layout( 'blocks.results', 'grid' ).
	 *
	 * @param string $key     Dotted key inside the recipe.
	 * @param mixed  $default Returned when the recipe does not set it.
	 * @return mixed
	 */
	function optima_layout( string $key, $default = null ) {
		return Optima_Hub_Owns::layout( $key, $default );
	}
}

if ( ! function_exists( 'optima_slot_has' ) ) {
	/** True when a plugin attached a block to the slot. */
	function optima_slot_has( string $name ): bool {
		return Optima_Hub_Owns::slot_has( $name );
	}
}
