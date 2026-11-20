<?php
// Optima Law Firm Tools: the rows Law hands to Optima's Inbox, in the shape
// Optima_Hub_Inbox::kinds() uses. Runs without WordPress:
//   php tools/tests/law-inbox.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'ok    ' : 'FAIL  ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['opts']  = array();
$GLOBALS['meta']  = array();
$GLOBALS['posts'] = array();
class WP_Post { public $ID; public $post_title = ''; public $post_content = ''; public $post_status = 'publish'; public $post_type = 'post'; }
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function do_action() {}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_posts( $args ) { return array_values( array_filter( $GLOBALS['posts'], function ( $p ) use ( $args ) { return in_array( $p->post_type, (array) $args['post_type'], true ) && in_array( $p->post_status, (array) $args['post_status'], true ); } ) ); }
function get_the_title( $p ) { return is_object( $p ) ? $p->post_title : ( $GLOBALS['posts'][ $p ]->post_title ?? '' ); }
function get_edit_post_link( $id, $ctx = '' ) { return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function wp_date( $format, $ts ) { return gmdate( 'Y-m-d', $ts ); }
foreach ( array( 'disclaimers', 'admin', 'approvals', 'approvals-admin', 'reviews', 'reviews-admin', 'entries', 'entries-admin', 'inbox' ) as $f ) {
	require __DIR__ . '/../../optima-law/includes/class-optima-law-' . $f . '.php';
}
$I    = 'Optima_Law_Inbox';
$keys = array( 'label', 'who', 'count', 'url', 'link', 'cap', 'rows' );

// A fresh law site: every disclaimer line in use waits on the firm; nothing else.
$k = $I::kinds();
ok( array( 'law-disclaimers' ) === array_keys( $k ), 'a fresh site lists only the disclaimers to approve' );
ok( 5 === $k['law-disclaimers']['count'] && 5 === count( $k['law-disclaimers']['rows'] ), 'all five lines show somewhere by default and wait on the firm' );
ok( $keys === array_keys( $k['law-disclaimers'] ), 'each kind has the keys the Inbox reads, in its order' );
ok( array( 'title', 'note', 'url' ) === array_keys( $k['law-disclaimers']['rows'][0] ), 'each row has a title, a note and a link' );
ok( false !== strpos( $k['law-disclaimers']['url'], 'page=optima-law-disclaimers' ), 'the kind opens Disclaimers' );
ok( in_array( $k['law-disclaimers']['who'], array( 'you', 'optima' ), true ), 'who is one of the Inbox\'s two values' );

// A Louisiana site says the line waits for counsel.
$I::reset();
$GLOBALS['opts']['optima_law_disclaimers'] = array( 'state' => 'la' );
ok( 'Waits for counsel\'s wording' === $I::kinds()['law-disclaimers']['rows'][0]['note'], 'a marked line\'s row says it waits for counsel' );

// Pending posts, an edited live post, the form-entry notice.
$I::reset();
$GLOBALS['opts']['optima_law_disclaimers'] = array( 'lines' => array_fill_keys( array( 'relationship', 'forms', 'advertising', 'results', 'reviews' ), array( 'text' => '', 'places' => array() ) ) );
$p = new WP_Post(); $p->ID = 9; $p->post_title = 'Held post'; $p->post_status = 'pending';
$q = new WP_Post(); $q->ID = 10; $q->post_title = 'Live post'; $q->post_content = 'Changed words';
$GLOBALS['posts'] = array( 9 => $p, 10 => $q );
$GLOBALS['meta'][10] = array( '_optima_law_approved_by' => 'Dana Whitfield', '_optima_law_approved_hash' => md5( "Live post\nOld words" ) );
final class GFAPI { public static function get_forms() { return array(); } }
$GLOBALS['opts']['optima_law_entries']       = array( 'on' => true );
$GLOBALS['opts']['optima_law_entries_state'] = array( 'on_since' => time() - 2 * DAY_IN_SECONDS );
$k = $I::kinds();
ok( array( 'law-approvals', 'law-edited', 'law-entries' ) === array_keys( $k ), 'waiting, edited and the entry notice are listed; disclaimers shown nowhere are not' );
ok( 1 === $k['law-approvals']['count'] && 'Held post' === $k['law-approvals']['rows'][0]['title'], 'the held post is in the waiting row' );
ok( 1 === $k['law-edited']['count'] && 'Live post' === $k['law-edited']['rows'][0]['title'], 'the edited live post is in the edited row' );
ok( 'optima' === $k['law-entries']['who'] && 1 === $k['law-entries']['count'], 'the entry notice waits on the web team, counted once' );
ok( 3 === $I::count(), 'count() adds every kind' );
foreach ( $k as $key => $kind ) {
	ok( $keys === array_keys( $kind ), $key . ' has the Inbox keys' );
}

// Off: no notice.
$I::reset();
$GLOBALS['opts']['optima_law_entries'] = array( 'on' => false );
ok( ! isset( $I::kinds()['law-entries'] ), 'with deleting off there is no entry notice' );

echo $fail ? "\n$fail FAILED\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
