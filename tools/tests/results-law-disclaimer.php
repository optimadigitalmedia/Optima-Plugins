<?php
// Optima Case Results hands the disclaimer to Optima Law when Law prints it
// next to results (the optima_results_disclaimer filter), and the Case results
// card reads Law's approval. The threshold, the consent date and each result's
// own approval still decide what shows. Runs without WordPress:
//   php tools/tests/results-law-disclaimer.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts']    = array();
$GLOBALS['filters'] = array();
function __( $s ) { return $s; }
function add_action() {}
function add_filter( $tag, $cb ) { $GLOBALS['filters'][ $tag ][] = $cb; }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $tag ] ?? array() as $cb ) {
		$value = call_user_func( $cb, $value, ...$args );
	}
	return $value;
}
function do_action() {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_date( $f, $t ) { return gmdate( $f, $t ); }
function is_admin() { return false; }
$root = dirname( __DIR__, 2 );
require $root . '/optima-results/includes/class-optima-results.php';
$R = 'Optima_Results';

// Without Optima Law: the plugin's own box and approval, as before.
update_option( $R::OPTION, array( 'state' => 'nc', 'disclaimer' => '', 'threshold' => 1000000 ) );
ok( ! $R::law_disclaimer(), 'without Optima Law the results plugin owns the disclaimer' );
ok( 'results' === $R::disclaimer_state()['source'], 'the card reads the plugin\'s own approval' );
ok( 0 === strpos( $R::disclaimer(), 'Prior results do not guarantee' ), 'the North Carolina starting wording prints' );
update_option( $R::OPTION, array( 'state' => 'la', 'disclaimer' => '' ) );
ok( $R::disclaimer_state()['flagged'], 'a counsel preset without approved wording is flagged, as before' );

// With Optima Law printing next to results.
require $root . '/optima-law/includes/class-optima-law-disclaimers.php';
require $root . '/optima-law/includes/class-optima-law-front.php';
add_filter( 'optima_results_disclaimer', array( 'Optima_Law_Front', 'results' ) );
$D = 'Optima_Law_Disclaimers';
update_option( $R::OPTION, array( 'state' => 'nc', 'disclaimer' => 'Our own results box wording.', 'threshold' => 1000000 ) );
update_option( $D::OPTION, array( 'state' => 'nc', 'lines' => array( 'results' => array( 'text' => 'Firm-wide wording from Optima Law.', 'places' => array( 'results' ) ) ) ) );
ok( $R::law_disclaimer(), 'Optima Law with "Next to case results" ticked takes the disclaimer' );
ok( 'Firm-wide wording from Optima Law.' === $R::disclaimer(), 'the results print Optima Law\'s wording, not the box' );
$st = $R::disclaimer_state();
ok( 'law' === $st['source'] && $st['flagged'] && ! $st['approved'], 'until the firm approves it in Optima Law, the card reads Not approved' );
ok( $D::approve( 'results', 'Dana Whitfield', 1 ), 'Optima Law records the firm\'s approval' );
$st = $R::disclaimer_state();
ok( ! $st['flagged'] && $st['approved'] && 'Dana Whitfield' === $st['by'] && '' !== $st['on'], 'once approved in Optima Law, the card shows who approved it and when' );
update_option( $D::OPTION, array( 'state' => 'nc', 'lines' => array( 'results' => array( 'text' => 'Changed wording.', 'places' => array( 'results' ) ) ), 'approvals' => $GLOBALS['opts'][ $D::OPTION ]['approvals'] ) );
ok( $R::disclaimer_state()['flagged'], 'changed wording after the approval reads as not approved again' );

// Law printing elsewhere only: the results box is used again.
update_option( $D::OPTION, array( 'state' => 'nc', 'lines' => array( 'results' => array( 'text' => 'Firm-wide wording from Optima Law.', 'places' => array() ) ) ) );
ok( ! $R::law_disclaimer() && 'Our own results box wording.' === $R::disclaimer(), 'with the results place unticked, the plugin\'s own wording prints' );

// The disclaimer source never changes what may show.
update_option( $D::OPTION, array( 'state' => 'nc', 'lines' => array( 'results' => array( 'text' => 'Firm-wide wording from Optima Law.', 'places' => array( 'results' ) ) ) ) );
$base = array( 'approved' => true, 'approver' => 'Dana Whitfield' );
ok( $R::may_publish( $base + array( 'amount' => '$2,500,000' ) ), 'an approved result over the threshold shows' );
ok( ! $R::may_publish( $base + array( 'amount' => '$400,000' ) ), 'the threshold still holds with Optima Law in charge of the wording' );
ok( ! $R::may_publish( array( 'approved' => false, 'approver' => '', 'amount' => '$2,500,000' ) ), 'a result without the firm\'s approval never shows' );
update_option( $R::OPTION, array( 'state' => 'fl', 'disclaimer' => '', 'threshold' => 1000000 ) );
ok( ! $R::may_publish( $base + array( 'amount' => '$2,500,000', 'consent_on' => '' ) ), 'the Florida consent date still holds' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
