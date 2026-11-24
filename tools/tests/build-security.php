<?php
// Optima Build: the Security group on Site health (plugin-first rule 19). Each row only reads
// Optima Core's switches and the site; every row is ok or look. Runs without WordPress:
//   php tools/tests/build-security.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function human_time_diff( $a, $b = 0 ) { return '1 hour'; }
function apply_filters( $h, $v ) { return $v; }
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-security.php';
$X   = 'Optima_Hub_Security';
$now = 1800000000;

// XML-RPC.
ok( 'good' === $X::xmlrpc_row( false, false, true, '/p' )['state'], 'XML-RPC off: ok' );
ok( 'good' === $X::xmlrpc_row( true, true, true, '/p' )['state'], 'XML-RPC on for Jetpack: ok' );
$r = $X::xmlrpc_row( true, false, true, '/p' );
ok( 'warn' === $r['state'] && '/p' === $r['link'], 'XML-RPC on with Optima Core: a look that links to Spam protection' );
$r = $X::xmlrpc_row( true, false, false, '/p' );
ok( 'warn' === $r['state'] && ! isset( $r['link'] ) && false !== strpos( $r['note'], 'Optima Core' ), 'XML-RPC on without Optima Core: says to turn Optima Core on' );

// File editing.
ok( 'good' === $X::file_edit_row( true )['state'], 'file editing off: ok' );
$r = $X::file_edit_row( false, true, '/p' );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'Turn off the code editor' ), 'file editing on: a look naming Optima Core\'s switch' );

// Admins: named people, never an address.
$fresh  = array( 'name' => 'Pat Lee', 'login' => 'plee', 'last' => $now - DAY_IN_SECONDS, 'registered' => $now - 400 * DAY_IN_SECONDS, 'two_step' => null );
$r      = $X::admins_row( array( $fresh ), $now, $now - 400 * DAY_IN_SECONDS );
ok( 'good' === $r['state'] && '1 admin' === $r['value'], 'one named admin who signs in: ok' );
$r = $X::admins_row( array( $fresh, array( 'name' => 'admin', 'login' => 'admin', 'last' => $now, 'registered' => $now, 'two_step' => null ) ), $now, $now );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'generic name' ), 'a generic admin name is a look' );
$r = $X::admins_row( array( array( 'name' => 'someone@example.com', 'login' => 'someone', 'last' => $now, 'registered' => $now, 'two_step' => null ) ), $now, $now );
ok( 'warn' === $r['state'] && false === strpos( $r['note'], 'someone@example.com' ), 'a name that is an email address is a look, and the address is never printed' );
$r = $X::admins_row( array( array( 'name' => 'Old Hand', 'login' => 'old', 'last' => $now - 200 * DAY_IN_SECONDS, 'registered' => $now - 900 * DAY_IN_SECONDS, 'two_step' => null ) ), $now, $now - 900 * DAY_IN_SECONDS );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], '180 days' ), 'an admin idle 180 days is a look' );

// Two-step login.
ok( 'warn' === $X::two_step_row( array( $fresh ), array() )['state'], 'no two-step plugin: a look' );
$on  = array( 'two_step' => true ) + $fresh;
$off = array( 'two_step' => false, 'name' => 'Sam Roe' ) + $fresh;
ok( 'good' === $X::two_step_row( array( $on ), array( 'Two Factor' ) )['state'], 'every admin set up: ok' );
$r = $X::two_step_row( array( $on, $off ), array( 'Two Factor' ) );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'Sam Roe' ), 'an admin without it is named' );
ok( 'Can\'t tell from here' === $X::two_step_row( array( $fresh ), array( 'Wordfence' ) )['value'], 'a plugin that does not say: can\'t tell' );

// Backups.
ok( 'good' === $X::backups_row( 'WP Engine' )['state'], 'WP Engine backs up daily' );
ok( 'warn' === $X::backups_row( '' )['state'], 'unknown backups: a look' );

// Headers.
ok( array() === $X::header_gaps( array( 'Strict-Transport-Security' => '1', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'x' ) ), 'all four headers: none missing' );
ok( array( 'Strict-Transport-Security', 'Referrer-Policy' ) === $X::header_gaps( array( 'x-content-type-options' => 'nosniff', 'content-security-policy' => "frame-ancestors 'self'" ) ), 'CSP frame-ancestors counts for X-Frame-Options; names are matched in any case' );
ok( 'good' === $X::headers_row( null, $now, true, true, '/p' )['state'], 'first check still running: not a look' );
ok( 'warn' === $X::headers_row( array( 'ok' => false ), $now, true, true, '/p' )['state'], 'home page could not load: a look' );
$r = $X::headers_row( array( 'ok' => true, 'at' => $now, 'missing' => array( 'Referrer-Policy' ) ), $now, true, false, '/p' );
ok( 'warn' === $r['state'] && '/p' === $r['link'], 'a missing header with Optima Core\'s switch off links to Spam protection' );
$r = $X::headers_row( array( 'ok' => true, 'at' => $now, 'missing' => array( 'Referrer-Policy' ) ), $now, true, true, '/p' );
ok( ! isset( $r['link'] ) && false !== strpos( $r['note'], 'host' ), 'a missing header with the switch on: ask the host' );

// Headers sent twice (the host or CDN adds one after PHP sent its own).
ok( array() === $X::header_twice( array( 'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'no-referrer, strict-origin-when-cross-origin' ) ), 'one of each, and a Referrer-Policy fallback list: nothing twice' );
ok( array( 'X-Content-Type-Options' ) === $X::header_twice( array( 'x-content-type-options' => array( 'nosniff', 'nosniff' ) ) ), 'the same header line sent twice is named' );
ok( array( 'Strict-Transport-Security', 'X-Frame-Options' ) === $X::header_twice( array( 'strict-transport-security' => 'max-age=31536000, max-age=63072000', 'x-frame-options' => 'SAMEORIGIN, SAMEORIGIN' ) ), 'two values joined by a comma are named' );
ok( array( 'Referrer-Policy' ) === $X::header_twice( array( 'Referrer-Policy' => 'strict-origin-when-cross-origin, strict-origin-when-cross-origin' ) ), 'a Referrer-Policy value repeated is named' );
ok( null === $X::twice_row( null, true, '/p' ) && null === $X::twice_row( array( 'ok' => false ), true, '/p' ), 'no answer yet: no row (the headers row says so)' );
ok( 'good' === $X::twice_row( array( 'ok' => true, 'twice' => array() ), true, '/p' )['state'], 'nothing twice: ok' );
$r = $X::twice_row( array( 'ok' => true, 'twice' => array( 'X-Frame-Options' ) ), true, '/p' );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'X-Frame-Options' ) && false !== strpos( $r['note'], 'Pick one owner: the host/CDN or Optima Core\'s switch' ), 'a doubled header is a look that names it and says to pick one owner' );
ok( '/p' === $r['link'], 'with Optima Core sending it, the row links to the switch' );
ok( 'good' === $X::headers_row( array( 'ok' => false, 'local' => true ), $now, true, true, '/p' )['state'], 'a copy on someone\'s own computer is not checked and not a look' );
$scan_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-security.php' );
ok( false !== strpos( $scan_src, 'wp_safe_remote_head( home_url(' ) && false !== strpos( $scan_src, 'Optima_Hub_Health::is_local_host()' ), 'the daily check uses the safe request and skips local copies' );

// Visitor address.
$r = $X::visitor_ip_row( 'cloudflare', false, '2026-11-23' );
ok( 'good' === $r['state'] && 'From Cloudflare\'s header' === $r['value'] && false !== strpos( $r['note'], '2026-11-23' ), 'through Cloudflare: from Cloudflare\'s header, with the date the ranges were read' );
$r = $X::visitor_ip_row( 'server', false, '2026-11-23' );
ok( 'good' === $r['state'] && 'From the server' === $r['value'], 'straight to the server: from the server' );
$r = $X::visitor_ip_row( 'server', true, '2026-11-23' );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'optima_core_proxy_ranges' ), 'a Cloudflare header from outside its ranges: a look naming the proxy filter' );
$r = $X::visitor_ip_row( 'server', true, '2026-11-23', array( 'remote' => '10.4.0.7', 'remote_cf' => false, 'hop' => '198.51.100.9', 'hop_cf' => false ) );
ok( false !== strpos( $r['note'], 'the server saw 10.4.0.7 (not Cloudflare)' ) && false !== strpos( $r['note'], 'reached the host was 198.51.100.9 (not Cloudflare)' ), 'an ignored header shows the addresses this visit came through' );
ok( '' === $X::seen_text( array() ) && false !== strpos( $X::seen_text( array( 'remote' => '10.0.0.1' ) ), 'no forwarded address' ), 'no addresses: no line; no forwarded list: says so' );
ok( 'good' === $X::visitor_ip_row( 'proxy', false, '' )['state'], 'through an added proxy: ok' );
ok( 'warn' === $X::visitor_ip_row( null, false, '' )['state'], 'without Optima Core\'s helper: a look' );

// Nothing is ever a fix.
foreach ( array( $X::xmlrpc_row( true, false, true, '/p' ), $X::file_edit_row( false ), $X::backups_row( '' ), $X::two_step_row( array(), array() ), $X::twice_row( array( 'ok' => true, 'twice' => array( 'Referrer-Policy' ) ), false, '' ), $X::visitor_ip_row( 'server', true, '' ) ) as $row ) {
	ok( 'bad' !== $row['state'], $row['label'] . ' is never red' );
}

// The rows read Optima Core's switches; the contract it relies on is still in Core.
$core = (string) file_get_contents( dirname( __DIR__, 2 ) . '/optima-core/includes/protection.php' );
foreach ( array( 'public static function on( $key )', 'public static function forced_off(): array', 'public static function xmlrpc_needed()', 'public static function editor_locked()', "'browser_headers'", "'xmlrpc_off'", "'file_edit_off'", "'login_limit'" ) as $needle ) {
	ok( false !== strpos( $core, $needle ), 'Optima Core still has ' . $needle );
}

echo $fail ? "FAILED: $fail\n" : "All passed.\n";
exit( $fail ? 1 : 0 );
