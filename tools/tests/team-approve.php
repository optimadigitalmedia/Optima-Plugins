<?php
// Optima Team: approving what a person sent through their link. On a law
// site whose team bios need the firm's approval (Optima Law), nothing changes
// without the attorney's name, and the approval goes on the firm's record.
// No WordPress; the few functions it calls are stand-ins:
//   php tools/tests/team-approve.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['meta']   = array();
$GLOBALS['titles'] = array( 20 => 'Dana L.' );
$GLOBALS['thumb']  = array();
$GLOBALS['gated']  = false;
$GLOBALS['record'] = array();

function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return (string) $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function esc_url_raw( $u ) { return (string) $u; }
function absint( $n ) { return abs( (int) $n ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function get_option( $k, $d = false ) { return $d; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function get_the_title( $id ) { return $GLOBALS['titles'][ $id ] ?? ''; }
function wp_update_post( $a ) { $GLOBALS['titles'][ $a['ID'] ] = $a['post_title']; return $a['ID']; }
function wp_attachment_is_image( $id ) { return true; }
function set_post_thumbnail( $post, $id ) { $GLOBALS['thumb'][ $post ] = $id; }
function get_post_thumbnail_id( $post ) { return $GLOBALS['thumb'][ $post ] ?? 0; }

final class Optima_Law_Approvals {
	public static function gated( string $type ): bool { return $GLOBALS['gated'] && 'optima_team' === $type; }
	public static function record( int $id, string $by ): void { $GLOBALS['record'][] = array( $id, $by, $GLOBALS['titles'][ $id ] ); }
}

require __DIR__ . '/../../optima-team/includes/class-optima-team.php';
require __DIR__ . '/../../optima-team/includes/class-optima-team-link.php';
$T = 'Optima_Team';
$L = 'Optima_Team_Link';

$fail = 0;
function check( string $what, bool $ok ): void {
	global $fail;
	echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $what . "\n";
	if ( ! $ok ) {
		++$fail;
	}
}

function reset_person(): void {
	$GLOBALS['meta'][20]   = array(
		'_optima_team'         => array( 'role' => 'Associate', 'bio' => 'Old bio.' ),
		'_optima_team_pending' => array( 'name' => 'Dana Lee', 'role' => 'Partner', 'bio' => 'New bio.', 'photo' => 55, 'sent' => 1 ),
	);
	$GLOBALS['titles'][20] = 'Dana L.';
	$GLOBALS['thumb']      = array();
	$GLOBALS['record']     = array();
}

// 1. No law gate: Approve copies the words, the name and the photo in.
reset_person();
check( 'Approve without the firm\'s approval on: approved', 'approved' === $L::approve( 20 ) );
check( 'the title and bio are live', 'Partner' === $T::profile( 20 )['role'] && 'New bio.' === $T::profile( 20 )['bio'] );
check( 'the name is live', 'Dana Lee' === get_the_title( 20 ) );
check( 'the photo they sent is the photo now', 55 === get_post_thumbnail_id( 20 ) );
check( 'nothing waits any more', array() === $L::pending( 20 ) );
check( 'nothing goes on a firm record when there is no gate', array() === $GLOBALS['record'] );
check( 'Approve with nothing waiting says so', 'none' === $L::approve( 20 ) );

// 2. Law gate on: no attorney, no change.
$GLOBALS['gated'] = true;
reset_person();
check( 'with the gate on and no attorney: need_by', 'need_by' === $L::approve( 20 ) );
check( 'and nothing changed', 'Associate' === $T::profile( 20 )['role'] && 'Dana L.' === get_the_title( 20 ) && array() !== $L::pending( 20 ) );
check( 'a name without the tick changes nothing either', 'need_by' === $L::approve( 20, 'Jordan Whitfield', false ) && 'Associate' === $T::profile( 20 )['role'] );

// 3. Law gate on, attorney named and ticked: live, and on the firm's record with the new version.
check( 'with the attorney and the tick: approved', 'approved' === $L::approve( 20, 'Jordan Whitfield', true ) );
check( 'the words are live', 'Partner' === $T::profile( 20 )['role'] );
check( 'the approval is on the firm\'s record once, after the change', array( array( 20, 'Jordan Whitfield', 'Dana Lee' ) ) === $GLOBALS['record'] );

echo $fail ? "\n$fail FAILED\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
