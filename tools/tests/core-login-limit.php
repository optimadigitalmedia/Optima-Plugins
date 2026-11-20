<?php
// Optima Core: the failed-login limit (rule 19: login attempts limited). No WordPress needed:
//   php tools/tests/core-login-limit.php
// Loads the real protection and login-limit classes with the WordPress functions they use stubbed out.
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['t_opt']     = array();
$GLOBALS['t_trans']   = array();
$GLOBALS['t_hooks']   = array();
$GLOBALS['t_filters'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_opt'] ) ? $GLOBALS['t_opt'][ $k ] : $d; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function apply_filters( $tag, $value, ...$rest ) {
	return array_key_exists( $tag, $GLOBALS['t_filters'] ) ? $GLOBALS['t_filters'][ $tag ] : $value;
}
function add_filter( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['t_hooks'][ $tag ] = $p; }
function add_action( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['t_hooks'][ $tag ] = $p; }
function wp_salt( $s = 'auth' ) { return 'test-salt'; }
function __( $t, $d = '' ) { return $t; }
function get_transient( $k ) { return $GLOBALS['t_trans'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t_trans'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t_trans'][ $k ] ); return true; }
class WP_Error {
	private $c;
	private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_codes() { return array( $this->c ); }
	public function get_error_message() { return $this->m; }
}
class WP_User {}

$root = dirname( __DIR__, 2 );
require $root . '/optima-core/includes/protection.php';
require $root . '/optima-core/includes/login-limit.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$L    = 'Optima_Core_Login_Limit';
$good = new WP_User();
$fail_as = static function ( $name ) use ( $L ) {
	$L::failed( $name, new WP_Error( 'incorrect_password', 'The password you entered for the username ' . $name . ' is incorrect.' ) );
};
$code = static function ( $r ) {
	return $r instanceof WP_Error ? $r->get_error_code() : 'user';
};

// 1. Wiring and the switch.
$L::init();
ok( 99 === ( $GLOBALS['t_hooks']['authenticate'] ?? 0 ), 'the check runs after WordPress checks the password' );
ok( isset( $GLOBALS['t_hooks']['wp_login_failed'], $GLOBALS['t_hooks']['wp_login'] ), 'failures are counted and a good login clears them' );
ok( isset( Optima_Core_Protection::fields()['login_limit'] ) && Optima_Core_Protection::on( 'login_limit' ), 'login_limit is a protection switch, on by default' );

// 2. Four failures, then the right password still works.
for ( $i = 0; $i < 4; $i++ ) {
	$fail_as( 'admin' );
}
ok( 'user' === $code( $L::authenticate( $good, 'admin', 'right' ) ), 'four failures do not lock anyone out' );

// 3. The fifth failure locks the address and the username.
$fail_as( 'admin' );
ok( $L::CODE === $code( $L::authenticate( $good, 'admin', 'right' ) ), 'the fifth failure locks out even the right password' );
ok( $L::CODE === $code( $L::authenticate( $good, 'someone-else', 'right' ) ), 'the locked address cannot try another username' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
ok( $L::CODE === $code( $L::authenticate( $good, 'ADMIN', 'right' ) ), 'the locked username is refused from another address, any letter case' );
ok( 'user' === $code( $L::authenticate( $good, 'editor', 'right' ) ), 'another address with another username is not affected' );

// 4. The message never says whether a username exists.
$locked = $L::authenticate( $good, 'admin', 'right' );
$wrong  = $L::authenticate( new WP_Error( 'incorrect_password', 'The password you entered for the username editor is incorrect.' ), 'editor', 'x' );
$nouser = $L::authenticate( new WP_Error( 'invalid_username', 'The username ghost is not registered on this site.' ), 'ghost', 'x' );
ok( $locked->get_error_message() === $wrong->get_error_message() && $wrong->get_error_message() === $nouser->get_error_message(), 'lockout, wrong password and unknown username show the same sentence' );
ok( false === stripos( $nouser->get_error_message(), 'ghost' ) && false === stripos( $nouser->get_error_message(), 'registered' ), 'the sentence names no username' );
ok( 'invalid_username' === $nouser->get_error_code(), 'the error code is kept for other plugins' );
$other = $L::authenticate( new WP_Error( 'spam_check', 'Spam check failed.' ), 'editor', 'x' );
ok( 'Spam check failed.' === $other->get_error_message(), 'other plugins\' errors are left as they are' );

// 5. Storage: salted hashes only, never the address or the name.
$keys = implode( ' ', array_keys( $GLOBALS['t_trans'] ) );
ok( false === strpos( $keys, '203.0.113.7' ) && false === stripos( $keys, 'admin' ), 'transient names hold no address and no username' );
ok( $L::key( 'ip', '203.0.113.7' ) !== $L::key( 'user', '203.0.113.7' ), 'an address and a username with the same text get different keys' );
ok( strlen( $L::key( 'ip', '203.0.113.7' ) ) <= 172, 'the transient name fits WordPress\'s limit' );

// 6. A refused try does not stretch the lockout; a good login clears the count.
$GLOBALS['t_trans']     = array();
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$fail_as( 'writer' );
$fail_as( 'writer' );
$L::failed( 'writer', new WP_Error( $L::CODE, 'x' ) );
$row = get_transient( $L::key( 'ip', '192.0.2.1' ) );
ok( 2 === $row['n'], 'a refused try is not counted again' );
$L::succeeded( 'writer' );
ok( false === get_transient( $L::key( 'ip', '192.0.2.1' ) ) && false === get_transient( $L::key( 'user', 'writer' ) ), 'a good login clears both counts' );

// 7. Failures older than the window start over.
$GLOBALS['t_trans'][ $L::key( 'ip', '192.0.2.1' ) ] = array( 'n' => 4, 'first' => time() - 3600 );
$fail_as( 'writer' );
ok( 1 === get_transient( $L::key( 'ip', '192.0.2.1' ) )['n'], 'an old run of failures starts over' );

// 8. The visitor's address: REMOTE_ADDR unless the site trusts a proxy header.
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.50';
ok( '192.0.2.1' === $L::ip(), 'a proxy header is ignored by default (anyone can send one)' );
$GLOBALS['t_filters']['optima_core_login_limit_proxy_header'] = 'HTTP_CF_CONNECTING_IP';
ok( '203.0.113.50' === $L::ip(), 'a site that trusts Cloudflare uses its header' );
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
ok( '192.0.2.1' === $L::ip(), 'a header that is not an address falls back to REMOTE_ADDR' );
unset( $GLOBALS['t_filters']['optima_core_login_limit_proxy_header'] );

// 9. Filters: a lower limit, and the skip filter.
$GLOBALS['t_trans']                                  = array();
$GLOBALS['t_filters']['optima_core_login_limit_max'] = 2;
$fail_as( 'writer' );
$fail_as( 'writer' );
ok( $L::CODE === $code( $L::authenticate( $good, 'writer', 'right' ) ), 'the max filter changes the number of tries' );
$GLOBALS['t_filters']['optima_core_login_limit_skip'] = true;
ok( 'user' === $code( $L::authenticate( $good, 'writer', 'right' ) ), 'the skip filter lets an attempt through' );
unset( $GLOBALS['t_filters']['optima_core_login_limit_skip'], $GLOBALS['t_filters']['optima_core_login_limit_max'] );

// 10. Switched off on the screen: nothing is counted or refused.
$GLOBALS['t_opt']['optima_core_protection'] = array( 'login_limit' => '0' );
ok( 'user' === $code( $L::authenticate( $good, 'writer', 'right' ) ), 'switched off, a locked username gets in' );
$GLOBALS['t_trans'] = array();
for ( $i = 0; $i < 6; $i++ ) {
	$fail_as( 'writer' );
}
ok( array() === $GLOBALS['t_trans'], 'switched off, failures are not counted' );
$nouser = $L::authenticate( new WP_Error( 'invalid_username', 'The username ghost is not registered on this site.' ), 'ghost', 'x' );
ok( 'The username ghost is not registered on this site.' === $nouser->get_error_message(), 'switched off, WordPress\'s own messages come back' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
