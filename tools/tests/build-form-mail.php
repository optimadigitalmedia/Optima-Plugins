<?php
// Optima Build: the "Form emails sent" row on Site health. Each Gravity Forms notification is
// noted as sent or failed (never the recipient, subject or message), and the row reads the
// last 7 and 30 days. Runs without WordPress: php tools/tests/build-form-mail.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-form-mail.php';
$M     = 'Optima_Hub_Form_Mail';
$now   = 1800000000;
$title = static fn( int $id ) => 'Contact';

ok( '[address] refused' === $M::clean_error( '<jane@example.com> refused' ), 'email addresses are taken out of errors' );
ok( 160 >= strlen( $M::clean_error( str_repeat( 'x', 400 ) ) ), 'errors are kept short' );

$r = $M::build_row( $M::data(), $now, null, $title );
ok( 'good' === $r['state'] && 'None yet' === $r['value'], 'nothing noted yet: none yet, good' );

$M::record( 'form', 3, 'Admin notice', true, '', $now - 3600 );
$M::record( 'form', 3, 'Auto reply', true, '', $now - 1800 );
$r = $M::build_row( $M::data(), $now, null, $title );
ok( 'good' === $r['state'] && '2 sent' === $r['value'], 'two sent this week: good' );

$M::record( 'form', 3, 'Admin notice', false, 'SMTP connect() failed for bob@example.com', $now - 600 );
$r = $M::build_row( $M::data(), $now, null, $title );
ok( 'bad' === $r['state'] && '1 of 3 failed' === $r['value'], 'a failure in the last 24 hours is a fix' );
ok( false === strpos( $r['note'], 'bob@example.com' ) && false !== strpos( $r['note'], 'Contact' ), 'the note names the form and never an address' );
ok( false !== strpos( $r['link'], 'id=3' ), 'the link opens that form\'s notifications' );
$r = $M::build_row( $M::data(), $now + 2 * 86400, null, $title );
ok( 'warn' === $r['state'], 'an older failure in the week is a look' );

$GLOBALS['opts'] = array();
$M::record( 'form', 3, 'Admin notice', true, '', $now - 40 * 86400 );
$d          = $M::data();
$d['since'] = $now - 40 * 86400;
$r          = $M::build_row( $d, $now, 4, $title );
ok( 'warn' === $r['state'] && 'None sent' === $r['value'] && false !== strpos( $r['note'], '4 entries' ), 'entries but no form email in 30 days: a look' );

$stored = json_encode( $GLOBALS['opts'] );
ok( false === strpos( $stored, '@example.com' ), 'no address is stored' );

echo $fail ? "FAILED: $fail\n" : "All passed.\n";
exit( $fail ? 1 : 0 );
