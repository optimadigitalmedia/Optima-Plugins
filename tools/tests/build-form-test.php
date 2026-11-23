<?php
// Optima Build: the nightly form test. Checks the made-up answers, the two-failure rule, the
// weekly repeat, the cleanup (only marked test entries are ever deleted), the [Test] marks,
// the Site health row, the Inbox list, and that the signed token still matches Optima Forms
// Guard. Runs without WordPress: php tools/tests/build-form-test.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array();
$GLOBALS['salt'] = 'salt-' . str_repeat( 's', 40 );
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_salt( $s = 'auth' ) { return $GLOBALS['salt']; }
function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-form-test.php';
$T   = 'Optima_Hub_Form_Test';
$tz  = new DateTimeZone( 'America/New_York' );
$now = ( new DateTimeImmutable( '2026-11-20 10:00:00', $tz ) )->getTimestamp();

// Scheduling: around 2 am local, each site its own minute.
$m = $T::minute_for( 'https://example.test/' );
ok( $m >= 0 && $m < 120 && $m === $T::minute_for( 'https://example.test/' ), 'each site gets a steady minute from 0 to 119' );
$next = ( new DateTimeImmutable( '@' . $T::next_run( $now, $tz, 0 ) ) )->setTimezone( $tz );
ok( '2026-11-21 02:00' === $next->format( 'Y-m-d H:i' ), 'the next run is 2 am tomorrow, local time' );
ok( ! $T::due( '2026-11-20', $now, $tz ) && $T::due( '2026-11-19', $now, $tz ), 'once a night at most' );

// Answers.
$form = array(
	'fields' => array(
		array( 'id' => 1, 'type' => 'name', 'isRequired' => true, 'inputs' => array( 1 ) ),
		array( 'id' => 2, 'type' => 'email', 'isRequired' => true ),
		array( 'id' => 3, 'type' => 'phone', 'isRequired' => true ),
		array( 'id' => 4, 'type' => 'textarea', 'isRequired' => true ),
		array( 'id' => 5, 'type' => 'select', 'isRequired' => true, 'choices' => array( array( 'value' => 'Car crash' ) ) ),
		array( 'id' => 6, 'type' => 'text', 'isRequired' => false ),
	),
);
$a = $T::answers( $form, 'ops@example.com', 'https://example.test/', $now );
ok( '' === $a['skip'], 'a plain contact form can be tested' );
ok( 'ops@example.com' === $a['values']['input_2'], 'email fields get the given address' );
ok( 'Nightly' === $a['values']['input_1_3'] && 'Car crash' === $a['values']['input_5'] && ! isset( $a['values']['input_6'] ), 'required fields filled, optional left blank' );
ok( '' !== $T::answers( array( 'fields' => array( array( 'id' => 1, 'type' => 'captcha' ) ) ), 'x@example.com', '', $now )['skip'], 'a CAPTCHA form is skipped with a reason' );
ok( '' !== $T::answers( array( 'fields' => array( array( 'id' => 1, 'type' => 'product' ) ) ), 'x@example.com', '', $now )['skip'], 'a payment form is skipped' );
ok( '' !== $T::answers( array( 'requireLogin' => true ), 'x@example.com', '', $now )['skip'], 'a login-only form is skipped' );

// The two-failure rule.
$s = $T::apply( array( 'streak' => array() ), array( 3 => array( 'r' => 'failed', 'n' => 0, 'ok' => 0, 'e' => 'x' ) ), $now, $tz );
ok( 1 === $s['streak'][3], 'one failure counts one' );
$s = $T::apply( $s, array( 3 => array( 'r' => 'failed', 'n' => 0, 'ok' => 0, 'e' => 'x' ) ), $now + 86400, $tz );
ok( 2 === $s['streak'][3], 'two nights in a row count two' );
$s2 = $T::apply( $s, array( 3 => array( 'r' => 'refused', 'n' => 0, 'ok' => 0, 'e' => 'x' ) ), $now, $tz );
ok( 2 === $s2['streak'][3], 'refused leaves the count' );
$s2 = $T::apply( $s, array( 3 => array( 'r' => 'sent', 'n' => 1, 'ok' => 1, 'e' => '' ) ), $now, $tz );
ok( ! isset( $s2['streak'][3] ), 'sent clears the count' );
ok( ! $T::should_notify( 1 ) && $T::should_notify( 2 ) && ! $T::should_notify( 3 ) && $T::should_notify( 9 ), 'the alert goes at two failures, then once a week' );

// Cleanup only deletes marked entries.
$deleted = array();
$out     = $T::cleanup( array( 10, 11, 12 ), static fn( $id ) => 12 !== $id, static fn( $id ) => 10 === $id, static function ( $id ) use ( &$deleted ) { $deleted[] = $id; return true; } );
ok( array( 10 ) === $deleted && array( 10 ) === $out['deleted'], 'only an entry with the test mark is deleted' );

// The marks on the emails.
$e = $T::marked( array( 'subject' => 'New lead', 'message' => '<p>Hi</p>', 'headers' => array() ), 'html' );
ok( 0 === strpos( $e['subject'], '[Test] ' ) && false !== strpos( $e['message'], 'Test only' ) && isset( $e['headers']['X-Form-Test'] ), '[Test] subject, first line and header' );
ok( '[Test] New lead' === $T::marked( $e, 'html' )['subject'], 'never marked twice' );

// The signed token matches Optima Forms Guard.
ok( hash_hmac( 'sha256', '7|123', $GLOBALS['salt'] ) === $T::guard_token( 7, 123 ), 'the token is the HMAC of form id and time' );
$guard = (string) file_get_contents( dirname( __DIR__, 2 ) . '/optima-forms-guard/optima-forms-guard.php' );
ok( false !== strpos( $guard, "hash_hmac( 'sha256', \$form_id . '|' . \$ts, wp_salt( 'auth' ) )" ), 'Optima Forms Guard still signs the same way' );
ok( false !== strpos( $guard, "const TS  = 'optima_ts';" ) && false !== strpos( $guard, "const SIG = 'optima_sig';" ), 'Optima Forms Guard still reads optima_ts and optima_sig' );

// The Site health row.
$title = static fn( $id ) => 'Contact';
ok( null === $T::build_row( array( 'on' => false ), array(), $now, '', $title, '' ), 'off: no row' );
ok( 'good' === $T::build_row( array( 'on' => true, 'forms' => array( 3 ) ), array(), $now, 'staging', $title, '' )['state'], 'staging: waits, good' );
$state = array( 'last_run' => $now, 'results' => array( 3 => array( 'r' => 'failed', 'e' => 'no email went out' ) ), 'streak' => array( 3 => 1 ) );
$r     = $T::build_row( array( 'on' => true, 'forms' => array( 3 ) ), $state, $now, '', $title, '' );
ok( 'good' === $r['state'] && 'Trying again tonight' === $r['value'], 'one miss is not an alarm' );
$state['streak'][3] = 2;
$r                  = $T::build_row( array( 'on' => true, 'forms' => array( 3 ) ), $state, $now, '', $title, '' );
ok( 'bad' === $r['state'] && false !== strpos( $r['value'], 'Contact' ), 'two misses in a row: red, naming the form' );

// The Inbox list.
$GLOBALS['opts'][ $T::OPTION ] = array( 'on' => true, 'forms' => array( 3 ) );
$GLOBALS['opts'][ $T::STATE ]  = $state;
$f = $T::failing();
ok( isset( $f[3] ) && 2 === $f[3]['nights'] && 'no email went out' === $f[3]['why'], 'a form failing two nights is listed for the Inbox' );
$GLOBALS['opts'][ $T::STATE ]['streak'][3] = 1;
ok( array() === $T::failing(), 'one failure is not listed' );
$GLOBALS['opts'][ $T::OPTION ]['on'] = false;
ok( array() === $T::failing(), 'nothing is listed while the test is off' );

echo $fail ? "FAILED: $fail\n" : "All passed.\n";
exit( $fail ? 1 : 0 );
