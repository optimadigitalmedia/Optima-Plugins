<?php
// Optima Core 1.15.0.0 (upstream 1.8.0): Privacy and terms drafts. Built from what the
// plugins that are on say they keep; a plugin with no list gets a [Confirm] line; the
// approval is recorded; a change after approval is caught; nothing publishes. No WordPress needed:
//   php tools/tests/core-policies.php
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__, 2 );
define( 'WP_PLUGIN_DIR', $root );
$GLOBALS['opts'] = array( 'active_plugins' => array( 'optima-core/optima-core.php', 'optima-analytics/optima-analytics.php', 'other-plugin/other.php' ) );
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function add_action() {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function home_url( $p = '' ) { return 'https://firm.test' . $p; }
function get_bloginfo( $k ) { return 'Smith Injury Law'; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function optima_core_business() { return array( 'name' => 'Smith Injury Law', 'legal_name' => 'Smith Law, PLLC', 'email' => 'help@firm.test', 'phone' => '(555) 010-0142', 'city' => 'Tampa', 'region' => 'FL' ); }
require $root . '/optima-core/includes/policies.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$P = 'Optima_Core_Policies';

$src = $P::sources();
ok( isset( $src['optima-core'] ) && ! empty( $src['optima-core']['items'] ), 'Optima Core\'s visitor data list is read' );
ok( ! empty( $src['optima-analytics']['unlisted'] ), 'an Optima plugin that is on with no list is marked' );
ok( ! isset( $src['other-plugin'] ), 'plugins outside the suite are not read' );
$privacy = $P::privacy( $src );
ok( false !== strpos( $privacy, 'Smith Law, PLLC runs this website' ), 'the draft names the legal entity from Details' );
ok( false !== strpos( $privacy, '[Confirm: Optima Analytics is on this website.' ), 'the draft asks about the plugin with no list instead of claiming nothing' );
ok( false !== strpos( $privacy, 'Google Maps' ), 'the map\'s Google load is listed' );
ok( false !== strpos( $P::terms(), 'governed by the laws of FL' ), 'the terms name the state from Details' );

$state = array( 'privacy' => array( 'status' => 'draft', 'sent' => 0, 'approved' => 0, 'note' => '', 'by' => '', 'html' => '', 'lines' => array(), 'page' => 0 ) );
$state['terms'] = $state['privacy'];
$s1 = $P::apply( $state, 'privacy', 'approve', '', 'Op', 100, $src );
ok( 'draft' === $s1['privacy']['status'], 'approval needs a note saying who approved it' );
$s2 = $P::apply( $state, 'privacy', 'approve', 'Approved by the firm\'s named approver, by email', 'Op', 100, $src );
ok( 'approved' === $s2['privacy']['status'] && 100 === $s2['privacy']['approved'] && 0 === $s2['privacy']['page'], 'approval is recorded, and no page is made by it' );
ok( ! $P::out_of_date( 'privacy', $s2['privacy'], $src ), 'just approved: up to date' );
$more = $src;
unset( $more['optima-analytics'] );
ok( $P::out_of_date( 'privacy', $s2['privacy'], $more ), 'a feature turned off after approval marks the policy as changed' );
ok( 1 === $P::waiting( $s2, $src ), 'one document (terms) still waits for the firm' );
$code = (string) file_get_contents( $root . '/optima-core/includes/policies.php' );
ok( 1 === preg_match_all( "/'post_status'\s*=>\s*'([a-z]+)'/", $code, $m ) && array( 'draft' ) === $m[1], 'the only page it ever makes is a draft' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
