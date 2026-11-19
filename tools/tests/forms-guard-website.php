<?php
// Stub harness for Optima Forms Guard's Website field handling (from upstream 0.3.3):
// "www.example.com" gets https:// before Gravity Forms validates it, and a
// Website field never marks an entry as spam in the content check.
// Run: php tools/tests/forms-guard-website.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['t'] = array( 'filters' => array(), 'blocked' => 0 );
function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function apply_filters( $tag, $value, ...$a ) { return isset( $GLOBALS['t']['filters'][ $tag ] ) ? $GLOBALS['t']['filters'][ $tag ] : $value; }
function do_action( ...$a ) { $GLOBALS['t']['blocked']++; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function home_url() { return 'https://example-firm.com/'; }
function wp_parse_url( $u, $c ) { return parse_url( $u, $c ); }
function get_option( $k, $d = false ) { return $d; }
function update_option( ...$a ) {}
function wp_date( $f ) { return gmdate( $f ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_basename( $f ) { return basename( $f ); }
function register_activation_hook( ...$a ) {}
function is_admin() { return false; }
require dirname( __DIR__, 2 ) . '/optima-forms-guard/optima-forms-guard.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
$G = 'Optima_Forms_Guard';

// Field objects, as Gravity Forms passes them, and a plain-array field.
$web       = new stdClass();
$web->id   = 4;
$web->type = 'website';
$msg       = new stdClass();
$msg->id   = 5;
$msg->type = 'textarea';
$form      = array( 'id' => 1, 'fields' => array( $web, $msg, array( 'id' => 6, 'type' => 'website' ) ) );

$cases = array(
	'www.other-firm.com'            => 'https://www.other-firm.com',
	'other-firm.com'                => 'https://other-firm.com',
	'  other-firm.com/about  '      => 'https://other-firm.com/about',
	'https://other-firm.com'        => 'https://other-firm.com',
	'http://other-firm.com'         => 'http://other-firm.com',
	'ftp://files.other-firm.com'    => 'ftp://files.other-firm.com',
	'not a website'                 => 'not a website',
	'n/a'                           => 'n/a',
	''                              => '',
);
foreach ( $cases as $in => $want ) {
	$_POST = array( 'input_4' => $in, 'input_5' => 'www.leave-me.com' );
	$out   = call_user_func( array( $G, 'fix_website_urls' ), $form );
	ok( $out === $form, 'form returned unchanged for "' . $in . '"' );
	ok( ( '' === $want ? '' === $_POST['input_4'] || '' === trim( $_POST['input_4'] ) : $want === $_POST['input_4'] ), 'website "' . $in . '" -> "' . $_POST['input_4'] . '"' );
	ok( 'www.leave-me.com' === $_POST['input_5'], 'non-website field untouched for "' . $in . '"' );
}
$_POST = array( 'input_6' => 'example.org' );
call_user_func( array( $G, 'fix_website_urls' ), $form );
ok( 'https://example.org' === $_POST['input_6'], 'plain-array Website field gets https://' );
$_POST = array( 'input_4' => array( 'www.x.com' ) );
call_user_func( array( $G, 'fix_website_urls' ), $form );
ok( array( 'www.x.com' ) === $_POST['input_4'], 'non-string value left alone' );
$_POST = array();
call_user_func( array( $G, 'fix_website_urls' ), $form );
ok( array() === $_POST, 'missing field left alone' );
call_user_func( array( $G, 'fix_website_urls' ), array( 'id' => 2 ) );
ok( true, 'form without fields does not error' );

// Content check: the visitor's own website is not spam, a link in the message still is.
ok( false === $G::content_spam( false, $form, array( '4' => 'https://www.my-own-firm.com', '5' => 'I was hurt in a crash last week.' ) ), 'own website in a Website field is not spam' );
ok( false === $G::content_spam( false, $form, array( '6' => 'my-own-firm.net', '5' => 'Please call me.' ) ), 'plain-array Website field skipped too' );
ok( 0 === $GLOBALS['t']['blocked'], 'nothing counted as blocked' );
ok( true === $G::content_spam( false, $form, array( '4' => 'https://www.my-own-firm.com', '5' => 'Check https://cheap-leads.biz for more customers' ) ), 'link typed in the message is still spam' );
ok( true === $G::content_spam( false, $form, array( '4' => 'my-own-firm.com', '5' => 'Add your site to the search index today' ) ), 'pitch in the message is still spam' );
ok( 2 === $GLOBALS['t']['blocked'], 'spam counted as blocked' );
$plain = array( 'id' => 3, 'fields' => array( $msg ) );
ok( true === $G::content_spam( false, $plain, array( '4' => 'https://www.other-firm.com' ) ), 'same link in a non-Website field is still spam' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
