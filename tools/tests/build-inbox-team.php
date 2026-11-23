<?php
// Optima Build Inbox: "Sent their details" from Optima Team. Runs without WordPress:
//   php tools/tests/build-inbox-team.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === $n ? $a : $b; }
function add_action() {}
function add_filter() {}
function post_type_exists( $t ) { return false; }
function get_the_title( $id ) { return array( 21 => 'Dana Whitfield', 22 => 'Lee Park' )[ $id ] ?? ''; }
function get_edit_post_link( $id, $ctx = '' ) { return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_date( $f, $ts ) { return gmdate( $f, $ts ); }

final class Optima_Team { const POST_TYPE = 'optima_team'; }
final class Optima_Team_Admin { public static function url() { return 'https://example.test/wp-admin/admin.php?page=optima-team'; } }
final class Optima_Team_Link {
	public static $ids = array( 21, 22 );
	public static function waiting(): array { return self::$ids; }
	public static function pending( int $id ): array { return 21 === $id ? array( 'sent' => gmmktime( 12, 0, 0, 11, 20, 2026 ) ) : array(); }
}
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-inbox.php';

$k = Optima_Hub_Inbox::kinds();
ok( isset( $k['team_details'] ), 'people waiting for an OK show on the Inbox' );
$t = $k['team_details'];
ok( array( 'label', 'who', 'count', 'url', 'link', 'cap', 'rows' ) === array_keys( $t ), 'the kind has the keys the Inbox reads, in its order' );
ok( 'Sent their details' === $t['label'] && 2 === $t['count'] && 'optima' === $t['who'], 'labeled "Sent their details", counted, and waiting on the web team' );
ok( 'Dana Whitfield' === $t['rows'][0]['title'] && false !== strpos( $t['rows'][0]['note'], 'Sent Nov 20, 2026' ), 'each row names the person and the day they sent it' );
ok( false !== strpos( $t['rows'][0]['url'], 'post=21' ), 'a row opens that profile' );
ok( 'Waiting for an OK' === $t['rows'][1]['note'], 'a row without a date still says it waits' );
ok( false !== strpos( $t['url'], 'page=optima-team' ) && 'manage_options' === $t['cap'], 'the kind opens People, for people who manage the site' );
ok( 2 === Optima_Hub_Inbox::count(), 'they count toward the Inbox number' );

$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/optima-team/includes/class-optima-team-link.php' );
ok( false !== strpos( $src, 'public static function waiting(): array' ) && false !== strpos( $src, "\$clean['sent']  = time();" ), 'Optima Team still has waiting() and stamps when details were sent' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
