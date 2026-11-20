<?php
// Optima Core 1.15.0.0 (upstream 1.15.0): [optima_map] loads Google only on a tap, reads
// the place from Your business, and sends one of the seven event names. No WordPress needed:
//   php tools/tests/core-map.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'OPTIMA_CORE_FILE', __DIR__ . '/optima-core.php' );
define( 'OPTIMA_CORE_VERSION', 'test' );
$GLOBALS['biz'] = array( 'name' => 'Smith & Jones Injury Law', 'street' => '100 Main St', 'city' => 'Tampa', 'region' => 'FL', 'postal' => '33602', 'latitude' => '', 'longitude' => '' );
$GLOBALS['enq'] = array();
function add_shortcode() {}
function optima_core_business() { return $GLOBALS['biz']; }
function shortcode_atts( $d, $a ) { return array_merge( $d, array_intersect_key( (array) $a, $d ) ); }
function do_shortcode( $s ) { return $s; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function get_bloginfo( $k ) { return 'Site'; }
function wp_specialchars_decode( $s ) { return $s; }
function plugins_url( $p, $f ) { return 'https://firm.test/wp-content/plugins/optima-core/' . $p; }
function wp_enqueue_style( $h ) { $GLOBALS['enq'][] = $h; }
function wp_enqueue_script( $h ) { $GLOBALS['enq'][] = $h; }
$root = dirname( __DIR__, 2 ) . '/optima-core/';
require $root . 'includes/map.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$M = 'Optima_Core_Map';

ok( 'Smith & Jones Injury Law, 100 Main St, Tampa, FL 33602' === $M::place(), 'the place is the name and address from Details' );
$html = $M::shortcode( array(), null );
ok( false === stripos( $html, '<iframe' ), 'no Google map loads before the tap' );
ok( false !== strpos( $html, 'data-optima-track="directions_click"' ) && false !== strpos( $html, 'data-optima-location="map"' ), 'the tap sends directions_click, one of the seven event names (rule 50)' );
ok( false !== strpos( $html, 'href="https://www.google.com/maps/search/?api=1&amp;query=' ), 'without JavaScript the button is a link to Google Maps' );
ok( false !== strpos( $html, 'optima-map-plain' ) && in_array( 'optima-map', $GLOBALS['enq'], true ) && in_array( 'optima-core', $GLOBALS['enq'], true ), 'a plain panel, the stylesheet and the script' );
$pic = $M::shortcode( array( 'label' => 'See the office' ), '<img src="x.jpg" alt="">' );
ok( false === strpos( $pic, 'optima-map-plain' ) && false !== strpos( $pic, '<img src="x.jpg"' ) && false !== strpos( $pic, '>See the office</a>' ), 'the button sits over the theme\'s picture, with its own words' );
$GLOBALS['biz'] = array( 'name' => 'Firm', 'street' => '', 'city' => '', 'region' => '', 'postal' => '', 'latitude' => '27.9506', 'longitude' => '-82.4572' );
ok( '27.9506,-82.4572' === $M::place(), 'with no street, the coordinates' );
$GLOBALS['biz'] = array( 'name' => '' );
ok( '' === $M::place() && 'inner' === $M::shortcode( array(), 'inner' ), 'with no place, only what the theme wrapped' );
$css = (string) file_get_contents( $root . 'assets/optima-core.css' );
ok( ! preg_match( '/#[0-9a-fA-F]{3,6}\b/', $css ), 'the styles use only --optima-* variables (rule 7)' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
