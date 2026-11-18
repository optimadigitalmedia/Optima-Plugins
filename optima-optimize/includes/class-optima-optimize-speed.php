<?php
/**
 * First-paint speed for every Optima site: Gravity Forms' stylesheets and
 * jQuery stop holding up the page.
 *
 * Gravity Forms loads about 40 KB of CSS and, through its scripts, jQuery plus
 * the wp-i18n, wp-hooks, wp-a11y and wp-dom-ready helpers in <head> on every
 * page with a form. On phones PageSpeed counted 1 to 2 seconds of
 * render-blocking from them (two client sites, Oct 2026).
 * Forms sit below the first screen, so none of that has to load first.
 *
 *  - Gravity Forms stylesheets (and any handle in optima_async_styles) load at
 *    low priority and apply when they arrive, with a <noscript> copy. On by
 *    default.
 *  - When the jQuery move is on: every queued script that needs jQuery or
 *    wp-a11y moves to the footer with everything it depends on, so nothing
 *    pulls jQuery back into <head>, and forms on the page are enqueued up front
 *    so Gravity Forms does not print its scripts in the middle of the page.
 *
 * The jQuery move is off unless a site turns it on (Image optimizer screen,
 * "Move jQuery to the end of the page"), and it never runs on a site with
 * Elementor or NitroPack: Elementor HTML widgets and third-party embeds call
 * jQuery inline in the page body, and NitroPack defers and reorders scripts
 * itself. Moving jQuery there breaks those inline calls.
 *
 * Switches, strongest first:
 *  - OPTIMA_OPTIMIZE_SPEED false in wp-config.php, or the optima_optimize_speed
 *    filter returning false: the whole module is off.
 *  - The optima_jquery_in_footer filter has the last word on the jQuery move
 *    (it receives the decision and the reason), so a site can force it on or
 *    off in code, even over the Elementor and NitroPack checks.
 *  - OPTIMA_JQUERY_IN_FOOTER true or false in wp-config.php forces the move on
 *    or off over the screen setting and the checks.
 *
 * Moved here from two client themes (the same fix, copied per
 * theme). A theme that still carries its own copy is harmless: each filter
 * skips a tag the other already changed.
 *
 * @package Optima_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Optimize_Speed {

	public static function boot(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'OPTIMA_OPTIMIZE_SPEED' ) && ! OPTIMA_OPTIMIZE_SPEED ) ) {
			return;
		}
		add_filter( 'style_loader_tag', array( __CLASS__, 'lazy_style' ), 10, 3 );
		add_filter( 'gform_init_scripts_footer', array( __CLASS__, 'gf_footer' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'jquery_footer' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_forms' ), 5 );
		add_action( 'wp_print_scripts', array( __CLASS__, 'scripts_footer' ), 999 );
	}

	private static function on(): bool {
		return (bool) apply_filters( 'optima_optimize_speed', true );
	}

	private static function scripts_on(): bool {
		return self::jquery_state()['move'];
	}

	/** True when Elementor (or Elementor Pro) is active on this site. */
	public static function elementor(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || defined( 'ELEMENTOR_PRO_VERSION' ) || did_action( 'elementor/loaded' ) > 0 || class_exists( '\Elementor\Plugin', false );
	}

	/** True when NitroPack is active on this site. */
	public static function nitropack(): bool {
		$on = defined( 'NITROPACK_VERSION' ) || defined( 'NITROPACK_HOME_URL' ) || function_exists( 'get_nitropack' ) || function_exists( 'nitropack_get_instance' )
			|| class_exists( '\NitroPack\WordPress\NitroPack', false ) || class_exists( '\NitroPack\SDK\NitroPack', false );
		/**
		 * Filters whether Optima Optimize treats NitroPack as active.
		 *
		 * @param bool $on True when a NitroPack marker was found.
		 */
		return (bool) apply_filters( 'optima_optimize_nitropack_active', $on );
	}

	/**
	 * Whether jQuery moves to the footer on this site, and why.
	 *
	 * @return array{move:bool,why:string} why is one of: off (module off),
	 *               constant, elementor, nitropack, setting, default, filter.
	 */
	public static function jquery_state(): array {
		if ( ! self::on() || ( defined( 'OPTIMA_OPTIMIZE_SPEED' ) && ! OPTIMA_OPTIMIZE_SPEED ) ) {
			return array(
				'move' => false,
				'why'  => 'off',
			);
		}
		if ( defined( 'OPTIMA_JQUERY_IN_FOOTER' ) ) {
			$move = (bool) OPTIMA_JQUERY_IN_FOOTER;
			$why  = 'constant';
		} elseif ( self::elementor() ) {
			$move = false;
			$why  = 'elementor';
		} elseif ( self::nitropack() ) {
			$move = false;
			$why  = 'nitropack';
		} else {
			$settings = class_exists( 'Optima_Optimize' ) ? Optima_Optimize::settings() : array();
			$move     = ! empty( $settings['jquery_footer'] );
			$why      = $move ? 'setting' : 'default';
		}
		/**
		 * Filters whether jQuery and the scripts that need it print in the footer.
		 * Has the last word, over the screen setting and the Elementor and
		 * NitroPack checks.
		 *
		 * @param bool   $move True to move them.
		 * @param string $why  Why: constant, elementor, nitropack, setting or default.
		 */
		$filtered = (bool) apply_filters( 'optima_jquery_in_footer', $move, $why );
		return array(
			'move' => $filtered,
			'why'  => $filtered === $move ? $why : 'filter',
		);
	}

	/** Gravity Forms and listed stylesheets load without blocking the first paint. */
	public static function lazy_style( $tag, $handle, $href = '' ) {
		if ( ! self::on() || false !== strpos( (string) $tag, 'onload=' ) ) {
			return $tag;
		}
		$handle = (string) $handle;
		$async  = (array) apply_filters( 'optima_async_styles', array( 'optima-chat', 'optima-reviews' ) );
		$is_gf  = 0 === strpos( $handle, 'gform_' ) || 0 === strpos( $handle, 'gravity_forms' ) || 0 === strpos( $handle, 'gravity-forms' ) || false !== strpos( (string) $href, '/gravityforms/' );
		if ( ! $is_gf && ! in_array( $handle, $async, true ) ) {
			return $tag;
		}
		$lazy = preg_replace( '/\smedia=([\'"])all\1/', ' media="print" onload="this.media=\'all\';this.onload=null"', (string) $tag, 1, $count );
		if ( ! $count ) {
			return $tag;
		}
		return $lazy . '<noscript>' . $tag . '</noscript>';
	}

	public static function gf_footer( $footer ) {
		return self::scripts_on() ? true : $footer;
	}

	/** jQuery itself prints in the footer. */
	public static function jquery_footer(): void {
		if ( ! self::scripts_on() ) {
			return;
		}
		foreach ( array( 'jquery', 'jquery-core', 'jquery-migrate' ) as $h ) {
			wp_scripts()->add_data( $h, 'group', 1 );
		}
	}

	/**
	 * Enqueue the forms this page shows, before <head> prints, so Gravity Forms
	 * does not print its scripts inline at the form. Reads the page's own
	 * content (shortcode or block); themes that print forms from a template add
	 * the IDs with the optima_optimize_form_ids filter.
	 */
	public static function enqueue_forms(): void {
		if ( ! self::scripts_on() || ! function_exists( 'gravity_form_enqueue_scripts' ) ) {
			return;
		}
		$ids = array();
		if ( is_singular() ) {
			$content = (string) get_post_field( 'post_content', get_queried_object_id() );
			if ( preg_match_all( '/\[gravityforms?\b[^\]]*\bid=["\']?(\d+)/i', $content, $m ) ) {
				$ids = array_merge( $ids, $m[1] );
			}
			if ( preg_match_all( '/<!--\s*wp:gravityforms\/form\s*\{[^}]*"formId":"?(\d+)/', $content, $m ) ) {
				$ids = array_merge( $ids, $m[1] );
			}
		}
		$ids = (array) apply_filters( 'optima_optimize_form_ids', $ids );
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( $id ) {
				gravity_form_enqueue_scripts( $id, true );
			}
		}
	}

	/** Any script that needs jQuery or wp-a11y goes to the footer with its whole dependency tree. */
	public static function scripts_footer(): void {
		if ( ! self::scripts_on() ) {
			return;
		}
		$wp      = wp_scripts();
		$closure = static function ( $handle, &$out ) use ( &$closure, $wp ) {
			if ( isset( $out[ $handle ] ) || ! isset( $wp->registered[ $handle ] ) ) {
				return;
			}
			$out[ $handle ] = true;
			foreach ( (array) $wp->registered[ $handle ]->deps as $dep ) {
				$closure( $dep, $out );
			}
		};
		foreach ( (array) $wp->queue as $handle ) {
			$need = array();
			$closure( $handle, $need );
			$pulls = isset( $need['jquery'] ) || isset( $need['jquery-core'] ) || isset( $need['wp-a11y'] )
				|| 0 === strpos( $handle, 'gform_' ) || 0 === strpos( $handle, 'gravity' );
			if ( ! $pulls ) {
				continue;
			}
			foreach ( array_keys( $need ) as $h ) {
				$wp->add_data( $h, 'group', 1 );
			}
		}
	}
}
