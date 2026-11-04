<?php
/**
 * Plugins dress in the theme's brand: reads the theme's DESIGN.md.
 *
 * Every theme ships DESIGN.md at its root with one fenced block:
 *
 *     ```json optima-design
 *     { "color": { "ink": "#12163A", ... }, "font": { ... }, "radius": { ... }, "button": { ... } }
 *     ```
 *
 * This class reads that block (cached until the file changes), prints the
 * values once as --optima-* CSS variables on the site and in the dashboard,
 * and shows them on Optima Build -> Design. Plugin CSS uses only these
 * variables. A theme without DESIGN.md gets the Optima design system values.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Design {

	const PAGE      = 'optima-design';
	const CACHE_KEY = 'optima_hub_design';

	/** Optima design system values, used for anything DESIGN.md leaves out. */
	const DEFAULTS = array(
		'color'  => array(
			'ink'        => '#12163A',
			'ink-2'      => '#4A5378',
			'surface'    => '#FFFFFF',
			'canvas'     => '#F4F7FB',
			'line'       => '#DDE4EE',
			'accent'     => '#1272C4',
			'accent-ink' => '#FFFFFF',
			'highlight'  => '#FDC134',
			'night'      => '#0B0E26',
			'positive'   => '#2BA24B',
			'negative'   => '#E23B2E',
			'caution'    => '#8A5A00',
			'night-ink-2' => '#B7C0D6',
		),
		'font'   => array(
			'display' => 'Montserrat',
			'body'    => 'IBM Plex Sans',
			'mono'    => 'IBM Plex Mono',
		),
		'radius' => array(
			'sm' => 8,
			'md' => 12,
			'lg' => 24,
		),
		'button' => array(
			'shape'  => 'pill',
			'weight' => 700,
		),
	);

	public static function boot(): void {
		add_action( 'wp_head', array( __CLASS__, 'print_vars' ), 1 );
		add_action( 'admin_head', array( __CLASS__, 'print_vars' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	/** The theme's DESIGN.md path (child theme first), or ''. */
	public static function file(): string {
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			$path = trailingslashit( $dir ) . 'DESIGN.md';
			if ( is_readable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/** True when the theme's DESIGN.md has a readable optima-design block. */
	public static function from_theme(): bool {
		return array() !== self::read_block();
	}

	/** The theme's tokens merged over the defaults. */
	public static function tokens(): array {
		$out = self::DEFAULTS;
		foreach ( self::read_block() as $group => $values ) {
			if ( isset( $out[ $group ] ) && is_array( $values ) ) {
				foreach ( $values as $key => $value ) {
					$out[ $group ][ sanitize_key( (string) $key ) ] = $value;
				}
			}
		}
		return $out;
	}

	/** The raw optima-design block from DESIGN.md, cached until the file changes. */
	private static function read_block(): array {
		$path = self::file();
		if ( '' === $path ) {
			return array();
		}
		$stamp  = $path . '|' . (int) filemtime( $path );
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ( $cached['stamp'] ?? '' ) === $stamp ) {
			return (array) $cached['block'];
		}
		$block = array();
		$text  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( preg_match( '/```json[ \t]+optima-design[ \t]*\R(.*?)\R```/s', $text, $m ) ) {
			$decoded = json_decode( $m[1], true );
			if ( is_array( $decoded ) ) {
				$block = $decoded;
			}
		}
		set_transient( self::CACHE_KEY, array( 'stamp' => $stamp, 'block' => $block ), DAY_IN_SECONDS );
		return $block;
	}

	/** The CSS variables, one per token. */
	public static function vars(): array {
		$t    = self::tokens();
		$vars = array();
		foreach ( $t['color'] as $key => $value ) {
			$color = sanitize_hex_color( (string) $value );
			if ( $color ) {
				$vars[ '--optima-' . $key ] = $color;
			}
		}
		foreach ( $t['font'] as $key => $value ) {
			$family = preg_replace( '/[^A-Za-z0-9 \-]/', '', (string) $value );
			$fallback = 'mono' === $key ? 'ui-monospace, monospace' : 'system-ui, sans-serif';
			$vars[ '--optima-font-' . $key ] = "'" . $family . "', " . $fallback;
		}
		foreach ( $t['radius'] as $key => $value ) {
			$vars[ '--optima-radius-' . $key ] = absint( $value ) . 'px';
		}
		$vars['--optima-button-radius'] = 'pill' === ( $t['button']['shape'] ?? '' ) ? '999px' : absint( $t['radius']['sm'] ?? 8 ) . 'px';
		$vars['--optima-button-weight'] = (string) absint( $t['button']['weight'] ?? 700 );
		return $vars;
	}

	public static function print_vars(): void {
		$css = '';
		foreach ( self::vars() as $name => $value ) {
			$css .= $name . ':' . $value . ';';
		}
		echo '<style id="optima-tokens">:root{' . esc_html( $css ) . '}</style>' . "\n"; // Values are sanitized above.
	}

	public static function menu(): void {
		add_submenu_page( Optima_Hub_Admin::parent(), __( 'Design', 'optima-build' ), __( 'Design', 'optima-build' ), 'edit_posts', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$path   = self::file();
		$source = self::from_theme()
			? sprintf( /* translators: %s: file name */ __( 'From the theme’s %s.', 'optima-build' ), 'DESIGN.md' )
			: ( '' === $path
				? __( 'This theme has no DESIGN.md yet, so features use the Optima design system.', 'optima-build' )
				: __( 'DESIGN.md has no optima-design block yet, so features use the Optima design system.', 'optima-build' ) );
		$t = self::tokens();
		echo '<div class="wrap"><h1>' . esc_html__( 'Design', 'optima-build' ) . '</h1>';
		echo '<p>' . esc_html__( 'Every feature on this site dresses in these colors, fonts and corners.', 'optima-build' ) . ' ' . esc_html( $source ) . '</p>';
		echo '<h2>' . esc_html__( 'Colors', 'optima-build' ) . '</h2><div style="display:flex;flex-wrap:wrap;gap:12px">';
		foreach ( $t['color'] as $key => $value ) {
			$color = sanitize_hex_color( (string) $value );
			if ( ! $color ) {
				continue;
			}
			echo '<div style="width:140px;border:1px solid var(--optima-line);border-radius:var(--optima-radius-md);overflow:hidden;background:var(--optima-surface)"><div style="height:56px;background:' . esc_attr( $color ) . '"></div><div style="padding:8px 10px;font-size:12px"><strong>' . esc_html( $key ) . '</strong><br><code>' . esc_html( $color ) . '</code></div></div>';
		}
		echo '</div><h2>' . esc_html__( 'Fonts', 'optima-build' ) . '</h2><ul>';
		foreach ( $t['font'] as $key => $value ) {
			echo '<li><strong>' . esc_html( $key ) . ':</strong> ' . esc_html( (string) $value ) . '</li>';
		}
		echo '</ul><h2>' . esc_html__( 'Corners and buttons', 'optima-build' ) . '</h2><ul>';
		foreach ( $t['radius'] as $key => $value ) {
			echo '<li><strong>' . esc_html( $key ) . ':</strong> ' . absint( $value ) . 'px</li>';
		}
		echo '<li><strong>' . esc_html__( 'Button shape', 'optima-build' ) . ':</strong> ' . esc_html( (string) ( $t['button']['shape'] ?? '' ) ) . '</li></ul>';
		echo '<p><a class="button button-primary" style="border-radius:var(--optima-button-radius);background:var(--optima-accent);border-color:var(--optima-accent);color:var(--optima-accent-ink)">' . esc_html__( 'A button in this design', 'optima-build' ) . '</a></p></div>';
	}
}
