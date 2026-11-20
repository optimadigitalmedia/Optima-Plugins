<?php
// Optima Accessibility footer link (inventory INV-2611-09): on by default, printed
// once through optima_slot( 'footer' ) (rule 12), with wp_footer as the fallback
// only for a theme without that slot, skipped when a menu already links to the
// statement, and kept off when someone turned it off on the screen.
//   php tools/tests/accessibility-footer-link.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Post {
	public $ID = 42;
	public $post_type = 'page';
	public $post_status = 'publish';
}
$GLOBALS['opts']   = array();
$GLOBALS['trans']  = array();
$GLOBALS['menu']   = array();
$GLOBALS['hooks']  = array();
function add_action( $tag, $cb, $p = 10 ) { $GLOBALS['hooks'][ $tag ][] = $cb; }
function do_action( $tag ) { foreach ( $GLOBALS['hooks'][ $tag ] ?? array() as $cb ) { call_user_func( $cb ); } }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function get_post( $id ) { return 42 === (int) $id ? new WP_Post() : null; }
function get_permalink( $p ) { return 'https://firm.test/accessibility/'; }
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['trans'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); return true; }
function get_posts( $q ) { return $GLOBALS['menu']; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function esc_url( $u ) { return $u; }
function esc_html__( $s, $d = '' ) { return $s; }
require dirname( __DIR__, 2 ) . '/optima-accessibility/includes/class-optima-accessibility.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
$A = 'Optima_Accessibility';
function reset_page() {
	$r = new ReflectionProperty( 'Optima_Accessibility', 'printed' );
	$r->setValue( null, false );
}
function render( $hooks ) {
	reset_page();
	ob_start();
	foreach ( $hooks as $h ) {
		do_action( $h );
	}
	return (string) ob_get_clean();
}

$A::boot();
ok( ! empty( $GLOBALS['hooks']['optima_slot_footer'] ), 'the link is attached to the footer slot' );
ok( ! empty( $GLOBALS['hooks']['wp_footer'] ), 'wp_footer is kept as the fallback' );

$GLOBALS['opts']['optima_accessibility'] = array( 'page_id' => 42 );
ok( 1 === (int) $A::settings()['footer_link'], 'on by default on a new site' );
$GLOBALS['opts']['optima_accessibility'] = array( 'page_id' => 42, 'footer_link' => 0 );
ok( 1 === (int) $A::settings()['footer_link'], 'an old saved 0 (the earlier default, never chosen) reads as on' );

$out = render( array( 'optima_slot_footer', 'wp_footer' ) );
ok( 1 === substr_count( $out, 'optima-a11y-link' ), 'a theme with the footer slot prints the link once' );
$out = render( array( 'wp_footer' ) );
ok( 1 === substr_count( $out, 'optima-a11y-link' ), 'a theme without the slot gets it from wp_footer' );

reset_page();
$A::link_shortcode();
ob_start();
do_action( 'optima_slot_footer' );
do_action( 'wp_footer' );
ok( '' === ob_get_clean(), 'skipped when the page already printed [optima_accessibility_link]' );

$GLOBALS['menu'] = array( 7 );
$A::forget_menu_check();
ok( '' === render( array( 'optima_slot_footer', 'wp_footer' ) ), 'skipped when a menu links to the statement' );
$GLOBALS['menu'] = array();
$A::forget_menu_check();

$GLOBALS['opts']['optima_accessibility'] = array( 'page_id' => 42, 'footer_link' => 0, 'footer_link_chosen' => 1 );
ok( 0 === (int) $A::settings()['footer_link'], 'turned off on the screen stays off' );
ok( '' === render( array( 'optima_slot_footer', 'wp_footer' ) ), 'nothing prints when it is off' );

if ( $fail ) {
	echo "$fail failed\n";
	exit( 1 );
}
echo "All passed\n";
