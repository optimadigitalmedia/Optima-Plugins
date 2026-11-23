<?php
// Optima Site Check: where the team's copy goes, where Book a call goes, and what
// the privacy eraser clears. Runs without WordPress:
//   php tools/tests/sitecheck-notify-erase.php
// - "Email new checks to" is an optional override: blank uses the site's shared
//   notice address (optima_core_notice_email(), when Optima Core has one), else the
//   admin email. Before, a saved blank field sent the team nothing.
// - Book a call falls back to Contact when the booking function returns nothing.
// - Erasing an email deletes its checks, clears their finishing jobs (both kinds)
//   and clears the per-email check count.
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['opts']      = array( 'admin_email' => 'admin@example.com' );
$GLOBALS['transient'] = array();
$GLOBALS['cleared']   = array();
$GLOBALS['deleted']   = array();
$GLOBALS['meta']      = array();
$GLOBALS['booking']   = null;
function __( $s ) { return $s; }
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function apply_filters( $t, $v ) { return $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function sanitize_email( $e ) { $e = trim( (string) $e ); return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : ''; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function wp_salt( $s ) { return 'salt'; }
function get_transient( $k ) { return $GLOBALS['transient'][ $k ] ?? false; }
function delete_transient( $k ) { unset( $GLOBALS['transient'][ $k ] ); return true; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { $GLOBALS['cleared'][] = array( $hook, $args ); return 1; }
function get_posts( $q ) { return array_keys( array_filter( $GLOBALS['meta'], static fn( $m ) => $m['_ssc_email'] === $q['meta_value'] ) ); }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function wp_delete_post( $id, $force = false ) { unset( $GLOBALS['meta'][ $id ] ); $GLOBALS['deleted'][] = $id; return true; }

$root = dirname( __DIR__, 2 );
require $root . '/optima-sitecheck/includes/class-optima-sitecheck.php';
require $root . '/optima-sitecheck/includes/class-optima-sitecheck-privacy.php';

// Notify.
ok( 'admin@example.com' === Optima_Sitecheck::notify_to(), 'nothing saved: the admin email' );
$GLOBALS['opts']['optima_sitecheck'] = array( 'notify' => '' );
ok( 'admin@example.com' === Optima_Sitecheck::notify_to(), 'saved blank: the admin email, never nobody' );
$GLOBALS['opts']['optima_sitecheck'] = array( 'notify' => 'intake@example.com' );
ok( 'intake@example.com' === Optima_Sitecheck::notify_to(), 'an address saved here wins' );
$GLOBALS['opts']['optima_sitecheck'] = array( 'notify' => '' );
if ( ! function_exists( 'optima_core_notice_email' ) ) { // Declared here, not hoisted to the top of the file.
	function optima_core_notice_email() { return 'notices@example.com'; }
}
ok( 'notices@example.com' === Optima_Sitecheck::notify_to(), 'blank with a shared notice address: that address' );
$report = (string) file_get_contents( $root . '/optima-sitecheck/includes/class-optima-sitecheck-report.php' );
ok( 2 === substr_count( $report, 'Optima_Sitecheck::notify_to()' ) && false === strpos( $report, "settings()['notify']" ), 'the report reply address and the team copy both use notify_to()' );

// Book a call.
ok( 'https://example.com/contact/' === Optima_Sitecheck::book_url(), 'no booking function: Contact' );
if ( ! function_exists( 'optima_booking_url' ) ) {
	function optima_booking_url() { return $GLOBALS['booking']; }
}
$GLOBALS['booking'] = '';
ok( 'https://example.com/contact/' === Optima_Sitecheck::book_url(), 'booking page removed (empty): Contact, never an empty button' );
$GLOBALS['booking'] = 'https://example.com/book/';
ok( 'https://example.com/book/' === Optima_Sitecheck::book_url(), 'booking page set: the booking page' );

// Eraser.
$key = 'optima_ssc_' . md5( 'em' . 'pat@example.com' . 'salt' );
$GLOBALS['transient'][ $key ] = array( 'n' => 3, 'until' => time() + 3600 );
$GLOBALS['meta'] = array(
	11 => array( '_ssc_email' => 'pat@example.com' ),
	12 => array( '_ssc_email' => 'pat@example.com' ),
	13 => array( '_ssc_email' => 'sam@example.com' ),
);
$r = Optima_Sitecheck_Privacy::erase_data( 'Pat@Example.com ' );
ok( array( 11, 12 ) === $GLOBALS['deleted'], 'erasing deletes only that email\'s checks' );
ok( $r['items_removed'] && $r['done'], 'eraser reports removed and done' );
ok( in_array( array( Optima_Sitecheck::FINISH, array( 11 ) ), $GLOBALS['cleared'], true ) && in_array( array( Optima_Sitecheck::FINISH, array( 11, 'speed' ) ), $GLOBALS['cleared'], true ), 'both finishing jobs of an erased check are cleared' );
ok( ! isset( $GLOBALS['transient'][ $key ] ), 'the per-email check count is cleared' );

if ( $fail ) {
	echo "$fail failed\n";
	exit( 1 );
}
echo "All passed\n";
