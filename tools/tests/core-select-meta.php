<?php
// Optima Core 1.15.0.0 (upstream 1.10.3, 1.10.4): select fields keep their value when
// written through update_post_meta / add_post_meta / REST, which run the registered
// sanitize_callback, and every type with fields supports custom fields so its meta
// reaches REST and the block editor. No WordPress needed:
//   php tools/tests/core-select-meta.php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['meta']  = array();
$GLOBALS['types'] = array();
function add_action() {}
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, (array) $a ); }
function current_user_can() { return true; }
function register_post_meta( $type, $key, $args ) { $GLOBALS['meta'][ $type ][ $key ] = $args; return true; }
function register_post_type( $slug, $args ) { $GLOBALS['types'][ $slug ] = $args; }
function post_type_exists() { return false; }
$root = dirname( __DIR__, 2 ) . '/optima-core/includes/';
require $root . 'controls.php';
require $root . 'registry.php';
require $root . 'meta.php';
require $root . 'post-types.php';
require $root . 'universal-types.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

optima_core_register_universal_types();
Optima_Core_Meta::register_all();
Optima_Core_Post_Types::register_all();

// What update_post_meta does: run the registered sanitize_callback.
$save = static function ( $type, $key, $value ) {
	$cb = $GLOBALS['meta'][ $type ][ $key ]['sanitize_callback'] ?? null;
	return $cb ? $cb( $value, $key, 'post' ) : 'NOT REGISTERED';
};
$key = optima_core_meta_key( 'source' );
ok( 'google' === $save( 'testimonial', $key, 'google' ), 'testimonial source "google" is kept (it saved blank before)' );
ok( 'google' === $save( 'testimonial', $key, 'Google' ), 'testimonial source "Google" is stored as the key "google"' );
ok( 'yelp' === $save( 'testimonial', $key, ' YELP ' ), 'case and surrounding space are ignored' );
ok( '' === $save( 'testimonial', $key, 'myspace' ), 'a value not in the options is rejected' );
ok( '' === $save( 'testimonial', $key, array( 'google' ) ), 'a list sent over REST is rejected, not a PHP error' );
$once = $save( 'testimonial', $key, 'Facebook' );
ok( 'facebook' === $once && $once === $save( 'testimonial', $key, $once ), 'sanitizing twice gives the same key' );
ok( '5' === Optima_Core_Controls::sanitize( 'select', 5, array( 5 => 'Five' ) ), 'an integer option key matches' );

// REST: a type with fields supports custom fields; one without is unchanged.
ok( in_array( 'custom-fields', (array) ( $GLOBALS['types']['testimonial']['supports'] ?? array() ), true ), 'testimonials support custom fields, so their meta reaches REST and the block editor' );
ok( ! in_array( 'custom-fields', (array) ( $GLOBALS['types']['faq']['supports'] ?? array() ), true ), 'FAQs (no fields) are unchanged' );
ok( false === ( $GLOBALS['types']['testimonial']['show_in_menu'] ?? null ), 'Optima keeps testimonials under Optima, Content (show_in_menu false)' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
