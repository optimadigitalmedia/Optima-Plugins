<?php
// Optima Build: saved secrets (the Email token or password, the theme key) after the host
// rotates the WordPress security keys. They read as "can't be read", never "not set", and
// the fingerprint is never the salt. Runs without WordPress:
//   php tools/tests/build-secret-health.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['salt']  = 'first-salt-' . str_repeat( 'k', 40 );
$GLOBALS['opts']  = array();
$GLOBALS['hooks'] = array();
function wp_salt( $scheme = 'auth' ) { return $GLOBALS['salt']; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function add_action( $hook, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $hook ] = $cb; }
function __( $s, $d = '' ) { return $s; }
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-secret.php';
$S = 'Optima_Hub_Secret';
$S::boot();
$rotate = static function ( string $salt ) use ( $S ) {
	$GLOBALS['salt'] = $salt;
	$S::reset();
};
// The format every earlier release wrote: 'e:' base64( IV . AES-256-CBC ), key sha256( salt . context ).
$old_seal = static function ( string $plain, string $context ): string {
	$iv = random_bytes( 16 );
	return 'e:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', hash( 'sha256', $GLOBALS['salt'] . $context, true ), OPENSSL_RAW_DATA, $iv ) );
};
$theme_read = static fn( string $stored ) => $S::read( 'theme', $stored, static fn( string $v ): string => $S::open( $v, 'optima-theme' ) );

// The cipher.
$pw = 'github_pat_11SECRETSECRET_0123456789abcdef';
ok( $pw === $S::open( $S::seal( $pw, 'optima-theme' ), 'optima-theme' ), 'seal and open round-trip' );
ok( $pw === $S::open( $old_seal( $pw, 'optima-theme' ), 'optima-theme' ), 'a key saved by an earlier release still opens' );
ok( $pw !== $S::open( $S::seal( $pw, 'optima-theme' ), 'optima-mail' ), 'one context cannot open another' );
ok( 'abc' === $S::open( 'p:' . base64_encode( 'abc' ), 'optima-mail' ), 'the no-openssl format still reads' );

// The fingerprint.
$fp = $S::fingerprint();
ok( (bool) preg_match( '/^[0-9a-f]{8}$/', $fp ) && substr( hash( 'sha256', $GLOBALS['salt'] ), 0, 8 ) === $fp, 'the fingerprint is the first 8 hex of sha256 of the salt' );
ok( false === strpos( $GLOBALS['salt'], $fp ), 'the fingerprint is not a piece of the salt' );

// Nothing saved.
ok( 'none' === $theme_read( '' )['state'], 'nothing saved reads as none' );

// A key saved before fingerprints: still readable, and it gets its fingerprint.
$stored = $old_seal( $pw, 'optima-theme' );
$r      = $theme_read( $stored );
ok( 'ok' === $r['state'] && $pw === $r['plain'], 'an older key reads as ok' );
ok( $fp === ( get_option( $S::PRINTS )['theme'] ?? '' ), 'an older key gets its fingerprint the first time it is read' );

// The host rotates the security keys.
$rotate( 'second-salt-' . str_repeat( 'z', 40 ) );
$r = $theme_read( $stored );
ok( 'unreadable' === $r['state'] && '' === $r['plain'], 'after a key change the theme key reads as can\'t be read, not none' );

// Pasting it again records the new fingerprint.
$stored = $S::seal( $pw, 'optima-theme' );
$S::saved( 'theme' );
ok( 'ok' === $theme_read( $stored )['state'], 'a key pasted again reads as ok' );
$S::forgot( 'theme' );
ok( ! isset( get_option( $S::PRINTS )['theme'] ), 'removing the key drops its fingerprint' );

// No fingerprint and a value that won't open (rotated before this release): unreadable, not none.
$GLOBALS['opts'][ $S::PRINTS ] = array();
$S::reset();
$rotate( 'third-salt-' . str_repeat( 'q', 40 ) );
ok( 'unreadable' === $theme_read( $stored )['state'], 'a value that won\'t open reads as can\'t be read' );

// The Email settings are watched from here: the mail class is not touched.
$rotate( 'mail-salt-' . str_repeat( 'm', 40 ) );
$GLOBALS['opts'][ $S::PRINTS ] = array();
ok( 'none' === $S::mail_state(), 'Email never set up reads as none' );
$mail = array( 'service' => 'cloudflare', 'password' => $S::seal( 'cf-token-abcdef0123456789', 'optima-mail' ) );
update_option( 'optima_mail', $mail );
call_user_func( $GLOBALS['hooks']['add_option_optima_mail'], 'optima_mail', $mail );
ok( $S::fingerprint() === ( get_option( $S::PRINTS )['mail'] ?? '' ), 'saving the Email token records its fingerprint' );
ok( 'ok' === $S::mail_state(), 'the Email token reads as ok' );
$rotate( 'mail-salt-2-' . str_repeat( 'n', 40 ) );
ok( 'unreadable' === $S::mail_state(), 'after a key change the Email token reads as can\'t be read' );
$row = $S::mail_row( '/wp-admin/admin.php?page=optima-mail' );
ok( 'bad' === $row['state'] && 'Site email' === $row['label'], 'the Site email row is a fix' );
$new = array( 'service' => 'cloudflare', 'password' => $S::seal( 'cf-token-new-0123456789', 'optima-mail' ) );
call_user_func( $GLOBALS['hooks']['update_option_optima_mail'], $mail, $new );
update_option( 'optima_mail', $new );
ok( 'ok' === $S::mail_state(), 'a token pasted again reads as ok' );
$cleared = array( 'service' => 'google', 'password' => '' );
call_user_func( $GLOBALS['hooks']['update_option_optima_mail'], $new, $cleared );
update_option( 'optima_mail', $cleared );
ok( ! isset( get_option( $S::PRINTS )['mail'] ) && 'none' === $S::mail_state(), 'a cleared token drops its fingerprint and reads as none' );

// No secret or salt in any stored value.
$dump = json_encode( $GLOBALS['opts'][ $S::PRINTS ] ?? array() );
ok( false === strpos( $dump, 'salt' ) && false === strpos( $dump, 'token' ), 'the fingerprint store holds no salt and no secret' );

echo $fail ? "FAILED: $fail\n" : "All passed.\n";
exit( $fail ? 1 : 0 );
