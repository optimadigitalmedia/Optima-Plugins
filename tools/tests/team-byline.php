<?php
// Optima Team: bylines with more than one person, and offboarding keeping a
// post someone wrote with others. No WordPress; stand-ins only:
//   php tools/tests/team-byline.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['meta']  = array(); // post => key => list of values.
$GLOBALS['posts'] = array();

function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function get_option( $k, $d = false ) { return $d; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function get_post_meta( $id, $key, $single = false ) {
	$values = $GLOBALS['meta'][ $id ][ $key ] ?? array();
	return $single ? ( $values[0] ?? '' ) : $values;
}
function add_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ][] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function get_post_type( $id ) { return $GLOBALS['posts'][ $id ]['type'] ?? false; }
function get_post_status( $id ) { return $GLOBALS['posts'][ $id ]['status'] ?? false; }
function get_the_title( $id ) { return $GLOBALS['posts'][ $id ]['title'] ?? ''; }
function get_permalink( $id ) { return 'https://example.test/team/' . $id . '/'; }
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['meta'] as $id => $keys ) {
		if ( in_array( (string) $args['meta_value'], array_map( 'strval', $keys[ $args['meta_key'] ] ?? array() ), true ) ) {
			$out[] = $id;
		}
	}
	return $out;
}

require __DIR__ . '/../../optima-team/includes/class-optima-team.php';
require __DIR__ . '/../../optima-team/includes/class-optima-team-byline.php';
$T = 'Optima_Team';
$B = 'Optima_Team_Byline';

$fail = 0;
function check( string $what, bool $ok ): void {
	global $fail;
	echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $what . "\n";
	if ( ! $ok ) {
		++$fail;
	}
}

$GLOBALS['posts'] = array(
	1  => array( 'type' => 'post', 'status' => 'publish', 'title' => 'A post' ),
	2  => array( 'type' => 'post', 'status' => 'publish', 'title' => 'Another post' ),
	10 => array( 'type' => 'optima_team', 'status' => 'publish', 'title' => 'Ana Ruiz' ),
	11 => array( 'type' => 'optima_team', 'status' => 'publish', 'title' => 'Ben Cole' ),
	12 => array( 'type' => 'optima_team', 'status' => 'draft', 'title' => 'Dee Fox' ),
	13 => array( 'type' => 'optima_team', 'status' => 'publish', 'title' => 'Eve Byline Off' ),
	14 => array( 'type' => 'optima_team', 'status' => 'publish', 'title' => 'Flo No Page' ),
	99 => array( 'type' => 'page', 'status' => 'publish', 'title' => 'Not a person' ),
);
$GLOBALS['meta'][13]['_optima_team'] = array( array( 'shows' => array( 'byline' => false ) ) );
$GLOBALS['meta'][14]['_optima_team'] = array( array( 'shows' => array( 'bio' => false ) ) );

// 1. A post saved before this release (one row) reads the same.
$GLOBALS['meta'][1]['_optima_byline'] = array( 10 );
check( 'one picked person from before reads as one', array( 10 ) === $B::people( 1 ) && 10 === $B::get( 1 ) );

// 2. What the box sends: ticked people in order, then the one picked to add.
check( 'ticked in order, then the added one, each once', array( 11, 10, 14 ) === $B::picked( array( '11', '10' ), '14' ) );
check( 'adding someone already ticked changes nothing', array( 11, 10 ) === $B::picked( array( 11, 10 ), '10' ) );

// 3. Store and read: order kept, non-people dropped, not live or byline off left off.
$B::store( 2, array( 11, 99, 10, 12, 13, 14 ) );
check( 'stored in order, without the page that is not a person', array( 11, 10, 12, 13, 14 ) === $B::chosen( 2 ) );
check( 'credited now: live with Byline on', array( 11, 10, 14 ) === $B::people( 2 ) );
check( 'the first person is the one get() gives', 11 === $B::get( 2 ) );
check( 'the theme prints every name', 'Ben Cole, Ana Ruiz & Flo No Page' === $B::names( $B::people( 2 ) ) );
$html = $B::html( $B::people( 2 ) );
check( 'each name links to its bio', false !== strpos( $html, '<a href="https://example.test/team/11/" rel="author">Ben Cole</a>' ) );
check( 'a person whose page is off shows as a plain name', false !== strpos( $html, '&amp; Flo No Page' ) && false === strpos( $html, 'team/14/' ) );
$B::store( 2, array() );
check( 'an empty list removes the value', array() === $B::chosen( 2 ) && ! isset( $GLOBALS['meta'][2]['_optima_byline'] ) );

// 4. Offboarding: a post written with someone else stays; only the name comes off.
$B::store( 1, array( 10 ) );
$B::store( 2, array( 10, 11 ) );
check( 'posts naming Ana: both', array( 1, 2 ) === $B::posts_for( 10 ) );
check( 'only the post written with Ben is shared', array( 2 ) === $B::shared_posts_for( 10 ) );
check( 'release takes Ana off the shared post', 1 === $B::release( 10 ) && array( 11 ) === $B::chosen( 2 ) );
check( 'her own post is left for offboarding to handle', array( 10 ) === $B::chosen( 1 ) );

echo $fail ? "\n$fail FAILED\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
