<?php
// Review totals and reviews from the theme's optima-setup.json ("reviews": "totals"
// and "items"), handed to Optima_Reviews_Theme by Optima Build's Fill from theme.
// Totals: the newest check wins, a future or broken date is ignored, the saved
// stamp moves to the checked date, not to now, and a low rating applies like any
// other. Reviews: added once, never edited or removed; on a law site they go in
// as drafts marked new, so a person approves each one in the Review inbox.
// Runs without WordPress:
//   php tools/tests/reviews-from-theme.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts']  = array();
$GLOBALS['posts'] = array(); // id => { title, content, status, type, meta }.
$GLOBALS['ready'] = false;   // Google connected and syncing.
$GLOBALS['theme'] = sys_get_temp_dir() . '/optima-reviews-theme-' . getmypid();
@mkdir( $GLOBALS['theme'] );

function __( $s ) { return $s; }
function apply_filters( $t, $v ) { return $v; }
function add_action() { return true; }
function do_action() {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url_raw( $u, $p = null ) { return preg_match( '#^https?://#', (string) $u ) ? (string) $u : ''; }
function wp_slash( $v ) { return $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_user_can() { return true; }
function wp_doing_ajax() { return false; }
function get_stylesheet_directory() { return $GLOBALS['theme']; }
function get_template_directory() { return $GLOBALS['theme']; }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function wp_insert_post( $a ) {
	$id = count( $GLOBALS['posts'] ) + 100;
	$GLOBALS['posts'][ $id ] = array( 'title' => $a['post_title'], 'content' => $a['post_content'], 'status' => $a['post_status'], 'type' => $a['post_type'], 'meta' => array() );
	return $id;
}
function update_post_meta( $id, $k, $v ) { $GLOBALS['posts'][ $id ]['meta'][ $k ] = $v; return true; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['posts'][ $id ]['meta'][ $k ] ?? ''; }
function get_post_field( $f, $id ) { return 'post_title' === $f ? ( $GLOBALS['posts'][ $id ]['title'] ?? '' ) : ''; }
function get_posts( $q ) {
	$out = array();
	foreach ( $GLOBALS['posts'] as $id => $p ) {
		if ( $p['type'] !== $q['post_type'] || ! in_array( $p['status'], (array) $q['post_status'], true ) ) {
			continue;
		}
		foreach ( (array) ( $q['meta_query'] ?? array() ) as $m ) {
			if ( ( $p['meta'][ $m['key'] ] ?? null ) !== $m['value'] ) {
				continue 2;
			}
		}
		$out[] = $id;
	}
	return $out;
}

final class Optima_Reviews_Query { public static $flushed = 0; public static function flush() { self::$flushed++; } }
final class Optima_Reviews_Google { public static function is_ready() { return $GLOBALS['ready']; } }
final class Optima_Reviews_Google_Sync { const META_NEW = '_optima_review_new'; }
final class Optima_Reviews_Law { public static function is_law() { return ! empty( $GLOBALS['law'] ); } }
$GLOBALS['law'] = false;

$root = dirname( __DIR__, 2 );
require $root . '/optima-reviews/includes/class-optima-reviews-sources.php';
require $root . '/optima-reviews/includes/class-optima-reviews-post-type.php';
require $root . '/optima-reviews/includes/class-optima-reviews-settings.php';
require $root . '/optima-reviews/includes/class-optima-reviews-theme.php';

const DAY = 86400;
function days_ago( $n ) { return gmdate( 'Y-m-d', time() - $n * DAY ); }
function midnight( $date ) { return ( new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp(); }
function profile( $slug ) { return Optima_Reviews_Settings::get()['profiles'][ $slug ] ?? array(); }
function stamp() { return (int) get_option( Optima_Reviews_Settings::STAMP, 0 ); }

// ---------- dates ----------
ok( Optima_Reviews_Theme::day( '2026-11-09' ) === midnight( '2026-11-09' ), 'a real date reads as midnight that day' );
ok( 0 === Optima_Reviews_Theme::day( '2026-02-30' ), 'February 30 is not a date' );
ok( 0 === Optima_Reviews_Theme::day( 'last week' ), 'words are not a date' );
ok( 0 === Optima_Reviews_Theme::day( '' ), 'blank is not a date' );
ok( Optima_Reviews_Theme::is_future( days_ago( -1 ) ) && ! Optima_Reviews_Theme::is_future( days_ago( 0 ) ), 'tomorrow is in the future, today is not' );

// ---------- totals: first fill ----------
// Yelp was typed in by hand earlier, with its review link; nothing has been checked since.
$GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ] = array(
	'profiles' => array(
		'yelp'   => array( 'rating' => 4.0, 'count' => 4, 'url' => 'https://yelp.test/old', 'write_url' => 'https://yelp.test/write' ),
		'google' => array( 'rating' => 0, 'count' => 0, 'url' => '', 'write_url' => 'https://g.page/r/x/review' ),
	),
);
$checked = days_ago( 3 );
$changed = Optima_Reviews_Theme::apply_totals(
	array(
		'checked'  => $checked,
		'profiles' => array(
			'google'   => array( 'rating' => 4.9, 'count' => 52, 'url' => 'https://maps.test/place' ),
			'yelp'     => array( 'rating' => 4.5, 'count' => 6 ),
			'facebook' => array( 'rating' => 5, 'count' => 10 ),      // No stars on Facebook: the count only.
			'madeup'   => array( 'rating' => 5, 'count' => 99 ),      // Not a Optima Reviews platform.
			'direct'   => array( 'count' => 3 ),                      // Direct testimonials have no profile.
			'bbb'      => array( 'rating' => 9, 'count' => 0 ),       // Nothing usable.
		),
	)
);
sort( $changed );
ok( array( 'facebook', 'google', 'yelp' ) === $changed, 'newer totals apply to real platforms only: ' . implode( ', ', $changed ) );
ok( 4.9 === profile( 'google' )['rating'] && 52 === profile( 'google' )['count'], 'Google rating and count filled' );
ok( 'https://maps.test/place' === profile( 'google' )['url'], 'Google profile link filled' );
ok( 'https://g.page/r/x/review' === profile( 'google' )['write_url'], 'the Google review link stays' );
ok( 4.5 === profile( 'yelp' )['rating'] && 6 === profile( 'yelp' )['count'], 'Yelp totals replaced by the newer check' );
ok( 'https://yelp.test/old' === profile( 'yelp' )['url'] && 'https://yelp.test/write' === profile( 'yelp' )['write_url'], 'Yelp links stay when the theme leaves them out' );
ok( 0.0 === (float) profile( 'facebook' )['rating'] && 10 === profile( 'facebook' )['count'], 'Facebook takes the count, never stars' );
ok( ! profile( 'madeup' ) && ! profile( 'direct' ) && ! profile( 'bbb' ), 'unknown, direct and empty rows are skipped' );
ok( stamp() === midnight( $checked ), 'the stamp is the checked date, not now' );
ok( midnight( $checked ) === Optima_Reviews_Settings::checked_at( 'google' ), 'each platform remembers its own check' );
ok( 4 === count( array_filter( array( 'rating', 'count', 'url', 'write_url' ), fn( $k ) => array_key_exists( $k, Optima_Reviews_Settings::profile( 'google' ) ) ) ), 'Settings::profile() still reads the four fields' );

// ---------- totals: older, same day, future, broken ----------
$before = $GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ];
ok( array() === Optima_Reviews_Theme::apply_totals( array( 'checked' => days_ago( 10 ), 'profiles' => array( 'google' => array( 'rating' => 3.1, 'count' => 1 ) ) ) ), 'an older check is ignored' );
ok( array() === Optima_Reviews_Theme::apply_totals( array( 'checked' => $checked, 'profiles' => array( 'google' => array( 'rating' => 3.1, 'count' => 1 ) ) ) ), 'the same check again is ignored' );
ok( array() === Optima_Reviews_Theme::apply_totals( array( 'checked' => days_ago( -2 ), 'profiles' => array( 'google' => array( 'rating' => 3.1, 'count' => 1 ) ) ) ), 'a future date is ignored' );
ok( array() === Optima_Reviews_Theme::apply_totals( array( 'checked' => '2026-13-01', 'profiles' => array( 'google' => array( 'rating' => 3.1, 'count' => 1 ) ) ) ), 'an invalid date is ignored' );
ok( array() === Optima_Reviews_Theme::apply_totals( array( 'profiles' => array( 'google' => array( 'rating' => 3.1, 'count' => 1 ) ) ) ), 'no date is ignored' );
ok( $before === $GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ] && stamp() === midnight( $checked ), 'nothing changed after the ignored checks' );

// ---------- a hand-typed save on Review settings is newer ----------
// The form rebuilds every row, stamps each one and the whole set with now (an hour ago here).
$form = $GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ];
foreach ( $form['profiles'] as $slug => $row ) {
	$form['profiles'][ $slug ]['checked'] = time() - 3600;
}
$form['profiles']['google']['count'] = 53;
$GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ] = $form;
$GLOBALS['opts'][ Optima_Reviews_Settings::STAMP ]  = time() - 3600;
ok( array() === Optima_Reviews_Theme::apply_totals( array( 'checked' => days_ago( 1 ), 'profiles' => array( 'google' => array( 'rating' => 4.8, 'count' => 50 ) ) ) ), 'a hand-typed save that is newer stays' );
ok( 53 === profile( 'google' )['count'], 'the typed count is untouched' );

// A later theme check (today) beats that save from an hour ago only if it is newer: midnight today is older.
ok( array() === Optima_Reviews_Theme::apply_totals( array( 'checked' => days_ago( 0 ), 'profiles' => array( 'google' => array( 'count' => 50 ) ) ) ), 'a check dated today loses to a save made later today' );

// A week later Optima checks again: the theme wins, and the stamp never goes backwards.
foreach ( $GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ]['profiles'] as $slug => $row ) {
	$GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ]['profiles'][ $slug ]['checked'] = time() - 8 * DAY;
}
$GLOBALS['opts'][ Optima_Reviews_Settings::STAMP ] = time() - 8 * DAY;
$later = days_ago( 1 );
ok( array( 'google' ) === Optima_Reviews_Theme::apply_totals( array( 'checked' => $later, 'profiles' => array( 'google' => array( 'rating' => 4.9, 'count' => 55 ) ) ) ), 'a newer theme check beats an older save' );
ok( 55 === profile( 'google' )['count'] && stamp() === midnight( $later ), 'count updated and stamp moved to the checked date' );
// Then a daily Google check refreshes only the Google row and the whole-set stamp.
$GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ]['profiles']['google']['checked'] = time();
$GLOBALS['opts'][ Optima_Reviews_Settings::STAMP ] = time();
Optima_Reviews_Theme::apply_totals( array( 'checked' => days_ago( 0 ), 'profiles' => array( 'yelp' => array( 'count' => 7 ) ) ) );
ok( 7 === profile( 'yelp' )['count'], 'Yelp, last checked a week ago, takes today\'s check' );
ok( stamp() >= midnight( days_ago( 0 ) ) && stamp() > time() - 60, 'the stamp never moves backwards' );

// Rows saved before per-row check times fall back to the whole-set stamp.
unset( $GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ]['profiles']['clutch'] );
$GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ]['profiles']['clutch'] = array( 'rating' => 5, 'count' => 2, 'url' => '', 'write_url' => '' );
ok( stamp() === Optima_Reviews_Settings::checked_at( 'clutch' ), 'an old row without its own check time uses the saved stamp' );

// ---------- Google syncing: its numbers win ----------
$GLOBALS['ready'] = true;
$GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ]['profiles']['google']['checked'] = time() - 8 * DAY;
ok( array() === Optima_Reviews_Theme::apply_totals( array( 'checked' => days_ago( 0 ), 'profiles' => array( 'google' => array( 'count' => 1 ) ) ) ), 'while Google syncs, the theme leaves the Google row alone' );
ok( 55 === profile( 'google' )['count'], 'Google count untouched while syncing' );
$GLOBALS['ready'] = false;

// ---------- reviews ----------
$items = array(
	array( 'source' => 'google', 'author' => 'Pat R.', 'rating' => 5, 'date' => '2026-10-02', 'text' => 'Great service.', 'url' => 'https://maps.test/r1' ),
	array( 'source' => 'yelp', 'author' => 'Sam K.', 'rating' => 4, 'date' => '2026-09-15', 'text' => 'Good visit.', 'url' => '' ),
	array( 'source' => 'google', 'author' => '', 'rating' => 5, 'date' => '2026-10-03', 'text' => 'No name.' ),   // Skipped.
	array( 'source' => 'google', 'author' => 'Stars Only', 'rating' => 5, 'date' => '2026-10-04', 'text' => '' ), // Skipped.
	array( 'source' => 'myspace', 'author' => 'Lee', 'rating' => 5, 'date' => '2026-10-05', 'text' => 'Hi.' ),   // Skipped.
	array( 'source' => 'direct', 'author' => 'Jo B.', 'rating' => 9, 'date' => 'soon', 'text' => 'Thank you.' ),
);
ok( 3 === Optima_Reviews_Theme::add_items( $items ), 'three usable reviews added' );
$first = $GLOBALS['posts'][100];
ok( 'Pat R.' === $first['title'] && 'Great service.' === $first['content'] && 'publish' === $first['status'], 'a review is a published optima_review post with the reviewer and text' );
ok( 'google' === $first['meta']['_optima_review_source'] && 5 === $first['meta']['_optima_review_rating'] && '2026-10-02' === $first['meta']['_optima_review_date'] && 'https://maps.test/r1' === $first['meta']['_optima_review_url'], 'source, stars, date and link saved' );
ok( 1 === $first['meta'][ Optima_Reviews_Theme::META_FROM_THEME ] && ! isset( $first['meta']['_optima_review_external_id'] ), 'marked as from the theme, never as a Google sync review' );
ok( 5 === $GLOBALS['posts'][102]['meta']['_optima_review_rating'] && '' === $GLOBALS['posts'][102]['meta']['_optima_review_date'], 'stars capped at 5 and a broken date left blank' );
ok( 0 === Optima_Reviews_Theme::add_items( $items ), 'the same reviews are not added twice' );

// The client edited one and trashed another: neither is touched or brought back.
$GLOBALS['posts'][100]['content'] = 'Great service (edited).';
$GLOBALS['posts'][100]['status']  = 'draft';
$GLOBALS['posts'][101]['status']  = 'trash';
$snapshot = $GLOBALS['posts'];
ok( 0 === Optima_Reviews_Theme::add_items( $items ), 'a hidden or trashed review is not added again' );
ok( $snapshot === $GLOBALS['posts'], 'existing reviews are untouched' );

$more   = $items;
$more[] = array( 'source' => 'google', 'author' => 'Pat R.', 'rating' => 4, 'date' => '2026-11-01', 'text' => 'Second visit.' );
ok( 1 === Optima_Reviews_Theme::add_items( $more ), 'a second review by the same person on another day is added' );

$GLOBALS['ready'] = true;
ok( 0 === Optima_Reviews_Theme::add_items( array( array( 'source' => 'google', 'author' => 'New', 'date' => '2026-11-02', 'text' => 'Hi.' ) ) ), 'while Google syncs, Google reviews come from the sync' );
$GLOBALS['ready'] = false;

// ---------- law sites: a person approves every review ----------
$GLOBALS['law'] = true;
$law_items = array(
	array( 'source' => 'google', 'author' => 'Mr. & Mrs. Lee', 'rating' => 2, 'date' => '2026-10-20', 'text' => 'Slow to call back.' ),
	array( 'source' => 'google', 'author' => 'Dana P.', 'rating' => 5, 'date' => '2026-10-21', 'text' => 'They got me  ,000.' ),
);
ok( 2 === Optima_Reviews_Theme::add_items( $law_items ), 'a law site still brings in every review, whatever its stars' );
$ids = array_slice( array_keys( $GLOBALS['posts'] ), -2 );
$ok  = true;
foreach ( $ids as $id ) {
	$ok = $ok && 'draft' === $GLOBALS['posts'][ $id ]['status'] && 1 === ( $GLOBALS['posts'][ $id ]['meta']['_optima_review_new'] ?? 0 );
}
ok( $ok, 'on a law site each one is a draft marked new, waiting in the Review inbox' );
ok( 2 === $GLOBALS['posts'][ $ids[0] ]['meta']['_optima_review_rating'], 'a low rating is kept as given' );

// WordPress may store "&" in the title as "&amp;": the name still matches, so no second copy.
$GLOBALS['posts'][ $ids[0] ]['title'] = 'Mr. &amp; Mrs. Lee';
ok( 0 === Optima_Reviews_Theme::add_items( $law_items ), 'a name with "&" stored as "&amp;" is not added twice' );

// Totals apply on a law site whatever the rating, so a low rating is never held back.
$GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ]['profiles']['bbb'] = array( 'rating' => 0, 'count' => 0, 'url' => '', 'write_url' => '', 'checked' => time() - 9 * DAY );
$GLOBALS['opts'][ Optima_Reviews_Settings::OPTION ]['profiles']['yelp']['checked'] = time() - 9 * DAY;
ok( array( 'yelp' ) === Optima_Reviews_Theme::apply_totals( array( 'checked' => days_ago( 1 ), 'profiles' => array( 'yelp' => array( 'rating' => 2.1, 'count' => 9 ) ) ) ), 'a low rating from the theme applies on a law site' );
ok( 2.1 === profile( 'yelp' )['rating'], 'the low rating is saved for the summary' );
$GLOBALS['law'] = false;

@unlink( $GLOBALS['theme'] . '/optima-setup.json' );
@rmdir( $GLOBALS['theme'] );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
