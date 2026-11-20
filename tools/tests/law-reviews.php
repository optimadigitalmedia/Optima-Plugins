<?php
// Optima Law Firm Tools: pages due for an attorney's review, read from Optima
// Team's Reviewed by record. Runs without WordPress:
//   php tools/tests/law-reviews.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'ok    ' : 'FAIL  ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts']  = array();
$GLOBALS['posts'] = array();
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function get_posts( $args ) {
	return array_values( array_filter( $GLOBALS['posts'], function ( $p ) use ( $args ) { return in_array( $p->post_type, (array) $args['post_type'], true ) && in_array( $p->post_status, (array) $args['post_status'], true ); } ) );
}
function get_the_title( $p ) { return is_object( $p ) ? $p->post_title : ( $GLOBALS['posts'][ $p ]->post_title ?? '' ); }
function is_singular() { return true; }
function get_queried_object_id() { return $GLOBALS['queried']; }
require __DIR__ . '/../../optima-law/includes/class-optima-law-reviews.php';
$R   = 'Optima_Law_Reviews';
$now = time();

// Due dates (pure).
$t = strtotime( '2026-11-04 12:00:00' );
ok( $R::is_due( 0, 6, $t ), 'a page never reviewed is due' );
ok( ! $R::is_due( strtotime( '2026-10-01' ), 6, $t ), 'reviewed a month ago: not due' );
ok( ! $R::is_due( strtotime( '2026-05-05' ), 6, $t ), 'reviewed just inside six months: not due' );
ok( $R::is_due( strtotime( '2026-05-03' ), 6, $t ), 'reviewed just over six months ago: due' );
ok( ! $R::is_due( strtotime( '2026-01-01' ), 12, $t ), 'with a 12 month period, ten months ago is fine' );
ok( $R::is_due( strtotime( '2026-07-01' ), 3, $t ), 'with a 3 month period, four months ago is due' );

// Settings.
$s = $R::settings();
ok( array( 'page' ) === $s['types'] && 6 === $s['months'], 'defaults: pages, every six months' );
$GLOBALS['opts'][ $R::OPTION ] = array( 'types' => array( 'page', 'post' ), 'months' => 7 );
$s = $R::settings();
ok( array( 'page', 'post' ) === $s['types'] && 6 === $s['months'], 'saved settings read back; a period other than 3, 6 or 12 falls back to 6' );
$GLOBALS['opts'][ $R::OPTION ] = array();

// Without Optima Team there is no record to read, so nothing is due and nothing is built.
$GLOBALS['posts'][1] = (object) array( 'ID' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home' );
ok( ! $R::available() && array() === $R::due(), 'without Optima Team nothing is listed' );
ok( array( 'x' => 1 ) === $R::schema( array( 'x' => 1 ) ), 'without Optima Team the schema is left alone' );

// Optima Team's Reviewed by record, as stand-ins for the real classes.
// Declared inside a block so PHP does not bind them before the checks above.
if ( true ) {
final class Optima_Team {
	const POST_TYPE = 'optima_team';
	public static function profile( int $id ): array { return array( 'attorney' => $GLOBALS['attorney'][ $id ] ?? 0 ); }
	public static function is_attorney( array $p ): bool { return ! empty( $p['attorney'] ); }
}
final class Optima_Team_Reviewed {
	public static $rec = array(); // id => [ status, who, when ].
	public static function status( int $id ): string { return self::$rec[ $id ][0] ?? 'none'; }
	public static function get( int $id ): array { return array( 'who' => self::$rec[ $id ][1] ?? 0, 'when' => self::$rec[ $id ][2] ?? '' ); }
	public static function printable( int $id ): array { return 'complete' === self::status( $id ) ? self::get( $id ) : array( 'who' => 0, 'when' => '' ); }
	public static function orphaned(): array { return array( 6 ); }
}
final class Optima_Schema {
	public static function add_webpage( array $graph ) { $graph['webpage'] = array( '@type' => 'WebPage' ); return $graph; }
}
}
$recent = gmdate( 'Y-m-d', $now - 30 * 86400 );
$old    = gmdate( 'Y-m-d', $now - 300 * 86400 );
$GLOBALS['posts'] = array(
	1 => (object) array( 'ID' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Never reviewed' ),
	2 => (object) array( 'ID' => 2, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Reviewed last month' ),
	3 => (object) array( 'ID' => 3, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Reviewed ten months ago' ),
	4 => (object) array( 'ID' => 4, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Changed since its review' ),
	5 => (object) array( 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Privacy policy' ),
	6 => (object) array( 'ID' => 6, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Reviewer left' ),
	7 => (object) array( 'ID' => 7, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Draft' ),
	8 => (object) array( 'ID' => 8, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'A blog post' ),
	20 => (object) array( 'ID' => 20, 'post_type' => 'optima_team', 'post_status' => 'publish', 'post_title' => 'Dana Whitfield' ),
	21 => (object) array( 'ID' => 21, 'post_type' => 'optima_team', 'post_status' => 'publish', 'post_title' => 'Paralegal' ),
);
$GLOBALS['attorney'] = array( 20 => 1 );
$GLOBALS['opts']['wp_page_for_privacy_policy'] = 5;
Optima_Team_Reviewed::$rec = array(
	2 => array( 'complete', 20, $recent ),
	3 => array( 'complete', 20, $old ),
	4 => array( 'stale', 20, $recent ),
	6 => array( 'incomplete', 0, $old ),
);
ok( $R::available(), 'with Optima Team the record is read' );
ok( array( 20 => 'Dana Whitfield' ) === $R::attorneys(), 'only profiles marked as an attorney can sign' );
ok( 0 === $R::reviewed_at( 4 ) && 0 === $R::reviewed_at( 1 ), 'a review out of date or missing counts as never reviewed' );
ok( $R::reviewed_at( 2 ) > 0 && 20 === $R::reviewer( 2 ), 'a complete review gives its date and reviewer' );
$due = $R::due();
sort( $due );
ok( array( 1, 3, 4 ) === $due, 'due: never reviewed, reviewed too long ago, and changed since the review' );
ok( ! in_array( 5, $due, true ), 'the privacy page is left out (its own yearly review)' );
ok( ! in_array( 6, $due, true ), 'a page whose reviewer left is left out (it is on the Inbox already)' );
ok( ! in_array( 7, $due, true ) && ! in_array( 8, $due, true ), 'drafts and kinds not chosen are left out' );

// Schema: a reviewed page gets Core's WebPage node before Team stamps it.
$GLOBALS['queried'] = 2;
ok( isset( $R::schema( array() )['webpage'] ), 'a page with a complete review gets the WebPage node' );
$GLOBALS['queried'] = 1;
ok( array() === $R::schema( array() ), 'a page without a review gets nothing added' );

echo $fail ? "\n$fail FAILED\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
