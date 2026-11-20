<?php
// Optima Core 1.15.0.0: the security baseline switches (rule 19): login names,
// XML-RPC, browser headers, the code editor, and the per-site opt-out. No WordPress needed:
//   php tools/tests/core-security-headers.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'OPTIMA_CORE_PROTECTION_OFF', 'XMLRPC_OFF, nonsense' );
$GLOBALS['t_opt']       = array();
$GLOBALS['t_filters']   = array();
$GLOBALS['t_removed']   = array();
$GLOBALS['t_ssl']       = true;
$GLOBALS['t_logged_in'] = false;
$GLOBALS['t_jetpack']   = false;
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_opt'] ) ? $GLOBALS['t_opt'][ $k ] : $d; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function apply_filters( $tag, $value, ...$rest ) {
	if ( 'optima_core_xmlrpc_needed' === $tag ) {
		return $GLOBALS['t_jetpack'];
	}
	return $value;
}
function add_filter( $tag, $cb, $p = 10 ) { $GLOBALS['t_filters'][ $tag ][] = $cb; }
function add_action( $tag, $cb, $p = 10 ) {}
function remove_action( $tag, $cb, $p = 10 ) { $GLOBALS['t_removed'][] = $tag . ':' . ( is_string( $cb ) ? $cb : 'cb' ); }
function remove_filter() {}
function is_admin() { return false; }
function is_ssl() { return $GLOBALS['t_ssl']; }
function is_user_logged_in() { return $GLOBALS['t_logged_in']; }
require dirname( __DIR__, 2 ) . '/optima-core/includes/protection.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$P = 'Optima_Core_Protection';

// 1. Every switch is on by default, so an update turns the new ones on.
foreach ( array( 'hide_users', 'browser_headers', 'file_edit_off', 'email_guard', 'close_comments' ) as $key ) {
	ok( $P::on( $key ), "$key is on by default" );
}
ok( array( 'xmlrpc_off', 'nonsense' ) === $P::forced_off(), 'OPTIMA_CORE_PROTECTION_OFF is read as a lower-case list' );
ok( ! $P::on( 'xmlrpc_off' ), 'a switch named in OPTIMA_CORE_PROTECTION_OFF is off whatever was saved' );
$GLOBALS['t_opt']['optima_core_protection'] = array( 'browser_headers' => '0' );
ok( ! $P::on( 'browser_headers' ) && $P::on( 'hide_users' ), 'a switch saved off reads off; the others stay on' );
$GLOBALS['t_opt'] = array();

// 2. Browser headers: added, a host's own header kept, HSTS only on HTTPS.
$h = $P::browser_headers( array( 'X-Frame-Options' => 'DENY' ) );
ok( 'nosniff' === $h['X-Content-Type-Options'] && 'strict-origin-when-cross-origin' === $h['Referrer-Policy'], 'nosniff and a referrer policy are sent' );
ok( 'DENY' === $h['X-Frame-Options'], 'a framing header the host already sends is kept' );
ok( 'max-age=31536000' === $h['Strict-Transport-Security'], 'HSTS is sent on HTTPS' );
$GLOBALS['t_ssl'] = false;
$h = $P::browser_headers( array() );
ok( ! isset( $h['Strict-Transport-Security'] ) && 'SAMEORIGIN' === $h['X-Frame-Options'], 'no HSTS without HTTPS; SAMEORIGIN framing by default' );
$GLOBALS['t_ssl'] = true;

// 3. Login names: closed to visitors, open to signed-in editors.
$routes = array( '/wp/v2/users' => 1, '/wp/v2/users/(?P<id>[\d]+)' => 1, '/wp/v2/users/me' => 1, '/wp/v2/pages' => 1 );
ok( array( '/wp/v2/pages' => 1 ) === $P::hide_user_routes( $routes ), 'visitors cannot list users' );
$GLOBALS['t_logged_in'] = true;
ok( $routes === $P::hide_user_routes( $routes ), 'signed-in people keep the users routes (the block editor needs them)' );
$GLOBALS['t_logged_in'] = false;

// 4. The code editor: the edit-code capabilities are refused, others untouched.
ok( array( 'do_not_allow' ) === $P::deny_editor( array( 'edit_themes' ), 'edit_themes' ) && array( 'do_not_allow' ) === $P::deny_editor( array( 'edit_plugins' ), 'edit_plugins' ) && array( 'do_not_allow' ) === $P::deny_editor( array( 'edit_files' ), 'edit_files' ), 'theme, plugin and file editing are refused (works where wp-config sets DISALLOW_FILE_EDIT false)' );
ok( array( 'edit_posts' ) === $P::deny_editor( array( 'edit_posts' ), 'edit_posts' ), 'other capabilities are untouched' );
$P::maybe_lock_editor();
ok( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT && ! empty( $GLOBALS['t_filters']['map_meta_cap'] ), 'the switch sets DISALLOW_FILE_EDIT and removes the capabilities' );

// 5. Hardening hooks follow the switches.
$GLOBALS['t_filters'] = array();
$P::maybe_harden();
ok( ! empty( $GLOBALS['t_filters']['rest_endpoints'] ) && ! empty( $GLOBALS['t_filters']['wp_headers'] ), 'users routes and headers filters added' );
ok( empty( $GLOBALS['t_filters']['xmlrpc_enabled'] ), 'XML-RPC left on: OPTIMA_CORE_PROTECTION_OFF names it' );
ok( in_array( 'wp_head:wp_generator', $GLOBALS['t_removed'], true ), 'the WordPress version is hidden' );

// 6. Saving: every switch stored as 1 or 0.
$saved = $P::sanitize( array( 'hide_users' => '1' ) );
ok( 6 === count( $saved ) && '1' === $saved['hide_users'] && '0' === $saved['file_edit_off'], 'an unchecked switch saves as off' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
