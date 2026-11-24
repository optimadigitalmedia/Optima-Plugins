<?php
// Optima Core: optima_client_ip(), the visitor's real address behind Cloudflare or not. No WordPress needed:
//   php tools/tests/core-client-ip.php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['t_filters'] = array();
function apply_filters( $tag, $value, ...$rest ) {
	if ( ! array_key_exists( $tag, $GLOBALS['t_filters'] ) ) {
		return $value;
	}
	$f = $GLOBALS['t_filters'][ $tag ];
	return is_callable( $f ) ? $f( $value, ...$rest ) : $f;
}

$root = dirname( __DIR__, 2 );
require $root . '/optima-core/includes/client-ip.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$C = 'Optima_Core_Client_IP';

// 1. Range math, IPv4 and IPv6.
ok( $C::in_range( '173.245.48.1', '173.245.48.0/20' ), 'an address inside a Cloudflare IPv4 range matches' );
ok( $C::in_range( '173.245.63.255', '173.245.48.0/20' ), 'the last address of a /20 matches' );
ok( ! $C::in_range( '173.245.64.0', '173.245.48.0/20' ), 'the first address past a /20 does not match' );
ok( $C::in_range( '2606:4700:10::6816:1', '2606:4700::/32' ), 'an address inside a Cloudflare IPv6 range matches' );
ok( $C::in_range( '2a06:98c7:ffff::1', '2a06:98c0::/29' ), 'a /29 IPv6 range matches across its partial byte' );
ok( ! $C::in_range( '2a06:98c8::1', '2a06:98c0::/29' ), 'just past a /29 IPv6 range does not match' );
ok( ! $C::in_range( '173.245.48.1', '2606:4700::/32' ), 'IPv4 never matches an IPv6 range' );
ok( ! $C::in_range( 'nonsense', '173.245.48.0/20' ) && ! $C::in_range( '1.2.3.4', 'bad/99' ), 'bad input is never a match' );
ok( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $C::CLOUDFLARE_READ ) && count( $C::CLOUDFLARE ) >= 20, 'the ranges ship with the date they were read' );

// 2. From Cloudflare: its header is used.
$_SERVER = array( 'REMOTE_ADDR' => '162.158.1.20', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5' );
ok( '203.0.113.5' === optima_client_ip() && 'cloudflare' === optima_client_ip_source(), 'a request from a Cloudflare address uses CF-Connecting-IP' );
$_SERVER = array( 'REMOTE_ADDR' => '2400:cb00:2049::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::7' );
ok( '2001:db8::7' === optima_client_ip(), 'Cloudflare over IPv6 works too, with an IPv6 visitor' );
$_SERVER = array( 'REMOTE_ADDR' => '162.158.1.20', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip' );
ok( '162.158.1.20' === optima_client_ip() && 'server' === optima_client_ip_source(), 'a broken Cloudflare header falls back to REMOTE_ADDR' );

// 3. Not from Cloudflare: a spoofed header is ignored.
$_SERVER = array( 'REMOTE_ADDR' => '198.51.100.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5' );
ok( '198.51.100.9' === optima_client_ip() && 'server' === optima_client_ip_source(), 'a CF-Connecting-IP header from outside Cloudflare is ignored' );
ok( $C::header_ignored(), 'and Site health can say the header was ignored' );
$_SERVER = array( 'REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5' );
ok( '198.51.100.9' === optima_client_ip(), 'X-Forwarded-For is ignored unless the sender is a proxy the site added' );
$_SERVER = array( 'REMOTE_ADDR' => '198.51.100.9' );
ok( '198.51.100.9' === optima_client_ip() && ! $C::header_ignored(), 'no proxy: REMOTE_ADDR' );
$_SERVER = array( 'REMOTE_ADDR' => 'garbage' );
ok( '' === optima_client_ip(), 'no valid address: empty' );

// 3b. Behind the host's own proxy (WP Engine): REMOTE_ADDR is internal, X-Forwarded-For ends with the address that reached the host.
$_SERVER = array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 162.158.1.20', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5' );
ok( '203.0.113.5' === optima_client_ip() && 'cloudflare' === optima_client_ip_source() && ! $C::header_ignored(), 'host proxy on loopback, Cloudflare reached the host: Cloudflare\'s header is used' );
$_SERVER = array( 'REMOTE_ADDR' => '10.4.0.7', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 172.70.1.2, 10.4.0.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5' );
ok( '203.0.113.5' === optima_client_ip(), 'internal hops on the right are skipped to find the Cloudflare hop' );
$_SERVER = array( 'REMOTE_ADDR' => '10.4.0.7', 'HTTP_X_FORWARDED_FOR' => '162.158.1.20, 198.51.100.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5' );
ok( '10.4.0.7' === optima_client_ip() && $C::header_ignored(), 'a visitor who reached the host directly cannot spoof Cloudflare by writing its address first' );
$_SERVER = array( 'REMOTE_ADDR' => '10.4.0.7', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5' );
ok( '10.4.0.7' === optima_client_ip() && $C::header_ignored(), 'an internal sender with no forwarded list is not trusted' );
$_SERVER = array( 'REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '162.158.1.20', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5' );
ok( '198.51.100.9' === optima_client_ip(), 'a public sender\'s forwarded list is never read' );
$_SERVER = array( 'REMOTE_ADDR' => '10.4.0.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 162.158.1.20' );
$seen = $C::seen();
ok( '10.4.0.7' === $seen['remote'] && ! $seen['remote_cf'] && '162.158.1.20' === $seen['hop'] && $seen['hop_cf'], 'seen() reports the server address and the hop that reached the host' );
ok( $C::internal( '127.0.0.1' ) && $C::internal( '10.0.0.1' ) && $C::internal( 'fd00::1' ) && ! $C::internal( '162.158.1.20' ) && ! $C::internal( 'x' ), 'internal() knows private, loopback and IPv6 local addresses' );

// 4. An added proxy (optima_core_proxy_ranges): the right-most untrusted forwarded address.
$GLOBALS['t_filters']['optima_core_proxy_ranges'] = array( '10.0.0.0/8' );
$_SERVER = array( 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.8, 162.158.1.20' );
ok( '203.0.113.8' === optima_client_ip() && 'proxy' === optima_client_ip_source(), 'behind an added proxy and Cloudflare, the address left of the trusted hops is used, never the one a visitor wrote first' );
$_SERVER = array( 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_CF_CONNECTING_IP' => '6.6.6.6' );
ok( '10.1.2.3' === optima_client_ip(), 'a Cloudflare header sent straight to an added proxy is not trusted' );
$_SERVER = array( 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => 'junk, 10.9.9.9' );
ok( '10.1.2.3' === optima_client_ip(), 'a broken forwarded list falls back to REMOTE_ADDR' );
unset( $GLOBALS['t_filters']['optima_core_proxy_ranges'] );

// 5. The last word.
$GLOBALS['t_filters']['optima_core_client_ip'] = static function ( $ip, $source ) {
	return 'server' === $source ? '192.0.2.44' : $ip;
};
$_SERVER = array( 'REMOTE_ADDR' => '198.51.100.9' );
ok( '192.0.2.44' === optima_client_ip(), 'optima_core_client_ip filter gets the source and has the last word' );
$GLOBALS['t_filters']['optima_core_client_ip'] = 'not an address';
ok( '' === optima_client_ip(), 'a filter that returns something that is not an address gives empty' );
unset( $GLOBALS['t_filters']['optima_core_client_ip'] );

// 6. Every caller goes through the helper, with a fallback when Core is off.
foreach ( array(
	'optima-core/includes/login-limit.php',
	'optima-chat/includes/class-optima-chat.php',
	'optima-sitecheck/includes/class-optima-sitecheck.php',
	'optima-reviews/includes/class-optima-reviews-notes.php',
	'optima-partners/includes/class-optima-partners.php',
) as $file ) {
	$src = (string) file_get_contents( $root . '/' . $file );
	ok( false !== strpos( $src, "function_exists( 'optima_client_ip' ) ? optima_client_ip()" ), $file . ' uses optima_client_ip() with a fallback' );
}

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
