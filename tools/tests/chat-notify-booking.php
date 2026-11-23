<?php
// Optima Chat: where new chat emails go and where the booking link goes.
// Runs without WordPress:
//   php tools/tests/chat-notify-booking.php
// - "Email new questions to" is an optional override: blank uses the site's
//   shared notice address (optima_core_notice_email(), when Optima Core has one),
//   else the admin email. Before, a saved blank field sent nothing.
// - "See open times" and the Booking page placeholder fall back to Contact when
//   the booking function returns nothing.
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['opts']    = array( 'admin_email' => 'admin@example.com' );
$GLOBALS['booking'] = null;
function __( $s ) { return $s; }
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function apply_filters( $t, $v ) { return $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function sanitize_email( $e ) { $e = trim( (string) $e ); return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : ''; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }

$root = dirname( __DIR__, 2 );
require $root . '/optima-chat/includes/class-optima-chat.php';

ok( 'admin@example.com' === Optima_Chat::notify_to(), 'nothing saved: the admin email' );
$GLOBALS['opts']['optima_chat'] = array( 'notify' => '' );
ok( 'admin@example.com' === Optima_Chat::notify_to(), 'saved blank: the admin email, never nobody' );
$GLOBALS['opts']['optima_chat'] = array( 'notify' => 'intake@example.com' );
ok( 'intake@example.com' === Optima_Chat::notify_to(), 'an address saved here wins' );
$GLOBALS['opts']['optima_chat'] = array( 'notify' => '' );
if ( ! function_exists( 'optima_core_notice_email' ) ) { // Declared here, not hoisted to the top of the file.
	function optima_core_notice_email() { return 'notices@example.com'; }
}
ok( 'notices@example.com' === Optima_Chat::notify_to(), 'blank with a shared notice address: that address' );
$src = (string) file_get_contents( $root . '/optima-chat/includes/class-optima-chat.php' );
ok( false !== strpos( $src, '$to      = self::notify_to();' ), 'the new-chat email uses notify_to()' );
ok( false !== strpos( $src, "self::site_booking_url();" ), 'the widget\'s booking link uses site_booking_url()' );

ok( 'https://example.com/contact/' === Optima_Chat::site_booking_url(), 'no booking function: Contact' );
if ( ! function_exists( 'optima_booking_url' ) ) {
	function optima_booking_url() { return $GLOBALS['booking']; }
}
$GLOBALS['booking'] = '';
ok( 'https://example.com/contact/' === Optima_Chat::site_booking_url(), 'booking page removed (empty): Contact, never an empty link' );
$GLOBALS['booking'] = 'https://example.com/book/';
ok( 'https://example.com/book/' === Optima_Chat::site_booking_url(), 'booking page set: the booking page' );

if ( $fail ) {
	echo "$fail failed\n";
	exit( 1 );
}
echo "All passed\n";
