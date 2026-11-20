<?php
// Optima Law Firm Tools: the disclaimer rules. Runs without WordPress:
//   php tools/tests/law-disclaimers.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'ok    ' : 'FAIL  ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array();
function __( $s ) { return $s; }
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
require __DIR__ . '/../../optima-law/includes/class-optima-law-disclaimers.php';
$D = 'Optima_Law_Disclaimers';

ok( 'nc' === $D::settings()['state'], 'North Carolina is the starting state' );
ok( '' !== $D::text( 'relationship' ), 'every line prints something before anything is saved' );
ok( array( 'relationship', 'advertising' ) === $D::in_place( 'footer' ), 'the footer shows Not legal advice and Attorney advertising by default' );
ok( array( 'forms' ) === $D::in_place( 'forms' ), 'forms show the confidential-details line by default' );
ok( count( $D::waiting() ) === 5, 'every line in use waits on the firm at first' );

$GLOBALS['opts']['optima_results'] = array( 'state' => 'va' );
ok( 'va' === $D::settings()['state'], 'with nothing saved here, the state saved under Case results is used' );
$GLOBALS['opts']['optima_results'] = array( 'state' => 'md' );
ok( 'nc' === $D::settings()['state'], 'a Case results state with no wording here falls back to North Carolina' );
unset( $GLOBALS['opts']['optima_results'] );

update_option( $D::OPTION, array( 'state' => 'fl' ) );
ok( false !== strpos( $D::text( 'results' ), 'the law that applies' ), 'Florida gets its own prior-results wording' );
update_option( $D::OPTION, array( 'state' => 'dc' ) );
ok( false !== strpos( $D::text( 'results' ), 'its own facts' ), 'DC gets its own prior-results wording' );
update_option( $D::OPTION, array( 'state' => 'zz' ) );
ok( 'nc' === $D::settings()['state'], 'an unknown state falls back to North Carolina' );

// Louisiana and South Carolina: no state wording is written, every line is visibly marked for counsel.
foreach ( array( 'la', 'sc' ) as $st ) {
	update_option( $D::OPTION, array( 'state' => $st ) );
	$all = true;
	foreach ( array_keys( $D::lines() ) as $line ) {
		$all = $all && 0 === strpos( $D::text( $line ), $D::VERIFY );
	}
	ok( $all, strtoupper( $st ) . ': every line starts with [VERIFY: counsel]' );
	ok( ! $D::approve( 'relationship', 'Dana Whitfield', 1 ), strtoupper( $st ) . ': a marked line cannot be approved' );
	ok( 'waiting' === $D::status( 'relationship' ) && 5 === count( $D::marked_lines() ), strtoupper( $st ) . ': marked lines wait and are counted' );
}
ok( array() === $D::log(), 'refused approvals leave no record' );
update_option( $D::OPTION, array( 'state' => 'la', 'lines' => array( 'relationship' => array( 'text' => 'Counsel wording for this firm.', 'places' => array( 'footer' ) ) ) ) );
ok( $D::approve( 'relationship', 'Dana Whitfield', 1 ) && 'approved' === $D::status( 'relationship' ), 'once counsel\'s wording replaces the marker, the line can be approved' );
ok( 'la' === $D::log()[0]['state'] && 'Counsel wording for this firm.' === $D::log()[0]['text'], 'the record keeps the state and the exact words' );
$GLOBALS['opts'][ $D::LOG ] = array();

update_option( $D::OPTION, array( 'state' => 'nc', 'lines' => array( 'relationship' => array( 'text' => '   ', 'places' => array( 'footer' ) ) ) ) );
ok( '' !== trim( $D::text( 'relationship' ) ), 'a blank saved wording falls back to the starting wording' );

ok( ! $D::approve( 'relationship', '  ', 1 ), 'an approval needs a name' );
ok( $D::approve( 'relationship', 'Dana Whitfield', 1 ), 'an approval with a name is recorded' );
ok( 'approved' === $D::status( 'relationship' ), 'the approved line reads Approved' );
ok( 1 === count( $D::log() ), 'the approval is kept in the record' );

$s = get_option( $D::OPTION );
$s['lines']['relationship']['text'] = 'New wording the firm has not seen.';
update_option( $D::OPTION, $s );
ok( 'changed' === $D::status( 'relationship' ), 'changing the words after approval reads Changed since approval' );
ok( in_array( 'relationship', $D::waiting(), true ), 'a changed line goes back to waiting on the firm' );

$s['lines']['relationship']['text'] = $D::starting( 'relationship', 'nc' ) . '   ';
update_option( $D::OPTION, $s );
ok( 'approved' === $D::status( 'relationship' ), 'extra spaces do not count as a change' );

$s['lines']['relationship']['text'] = $D::VERIFY . ' ' . $D::starting( 'relationship', 'nc' );
update_option( $D::OPTION, $s );
ok( 'waiting' === $D::status( 'relationship' ), 'wording that carries the marker waits, even after an earlier approval' );
$s['lines']['relationship']['text'] = '';
update_option( $D::OPTION, $s );

$s['lines']['advertising']['places'] = array();
update_option( $D::OPTION, $s );
ok( ! in_array( 'advertising', $D::waiting(), true ), 'a line shown nowhere does not wait on the firm' );
ok( ! in_array( 'advertising', $D::in_place( 'footer' ), true ), 'a line switched off leaves the footer' );

echo $fail ? "\n$fail FAILED\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
