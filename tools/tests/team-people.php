<?php
// Optima Team: Shows on, sources on credentials, what the public may see, the
// business card fields and "needs a look". The pure parts, no WordPress:
//   php tools/tests/team-people.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( (string) $s, FILTER_VALIDATE_EMAIL ) ? (string) $s : ''; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ), '-' ) ); }
function esc_url_raw( $u ) { return preg_match( '#^https?://#', (string) $u ) ? (string) $u : ''; }
function absint( $n ) { return abs( (int) $n ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function get_option( $k, $d = false ) { return $d; }

require __DIR__ . '/../../optima-team/includes/class-optima-team.php';
$T = 'Optima_Team';

$fail = 0;
function check( string $what, bool $ok ): void {
	global $fail;
	echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $what . "\n";
	if ( ! $ok ) {
		++$fail;
	}
}

// 1. Shows on: the defaults match how the site worked before.
$d = $T::default_shows();
check( 'by default a person has a profile page, a team page spot and a byline', $d['bio'] && $d['team'] && $d['byline'] );
check( 'by default nobody has a business card', false === $d['card'] );
check( 'the four places, in editor order', array( 'bio', 'team', 'card', 'byline' ) === $T::show_keys() );

// 2. The one-time move: old lines keep showing, Shows on is added.
$old = array(
	'role'      => 'Partner',
	'bar'       => array( array( 'state' => 'North Carolina', 'year' => '2010', 'number' => '', 'url' => '' ) ),
	'education' => array( array( 'school' => 'State Law School', 'degree' => 'J.D.', 'year' => '2009' ) ),
);
$up = $T::upgrade_profile( $old );
check( 'a bar line saved before sources gets the legacy source', $T::LEGACY_SOURCE === $up['bar'][0]['source'] );
check( 'an education line saved before sources gets the legacy source', $T::LEGACY_SOURCE === $up['education'][0]['source'] );
check( 'Shows on is added to an old profile', isset( $up['shows'] ) && $up['shows'] === $T::default_shows() );
check( 'the move is safe to run twice', $up === $T::upgrade_profile( $up ) );
$empty_source = $T::upgrade_profile( array( 'bar' => array( array( 'state' => 'Virginia', 'source' => '' ) ) ) );
check( 'a line with an empty source stays empty (hidden)', '' === $empty_source['bar'][0]['source'] );

// 3. The shape: every key, every column, every switch.
$shaped = $T::shape( $old );
check( 'the shape adds the card fields', array_key_exists( 'card_intro', $shaped ) && array_key_exists( 'card_phone', $shaped ) );
check( 'the shape gives each bar line every column, source last', array( 'state', 'year', 'number', 'url', 'source' ) === array_keys( $shaped['bar'][0] ) );
check( 'the shape keeps a switch someone turned off', false === $T::shape( array( 'shows' => array( 'team' => false ) ) )['shows']['team'] );

// 4. Cleaning: the setup file without sources keeps showing; an empty source is hidden.
$in = array(
	'role'       => 'Associate',
	'card_phone' => '(919) 555-0100 ext<b>',
	'card_email' => 'card@example.com',
	'card_tags'  => 'Trials, Appeals',
	'bar'        => array(
		array( 'state' => 'North Carolina', 'year' => '2015' ),
		array( 'state' => 'Virginia', 'year' => '2016', 'source' => '' ),
		array( 'state' => 'Florida', 'year' => '2017', 'source' => 'State bar roll, checked 2026-11-01' ),
	),
);
$c = $T::sanitize_profile( $in );
check( 'a setup file line with no source field gets the legacy source', $T::LEGACY_SOURCE === $c['bar'][0]['source'] );
check( 'a line sent with an empty source stays empty', '' === $c['bar'][1]['source'] );
check( 'a real source is kept', 'State bar roll, checked 2026-11-01' === $c['bar'][2]['source'] );
check( 'the card phone keeps digits and phone punctuation only', '(919) 555-0100' === trim( $c['card_phone'] ) );
check( 'the card email is kept', 'card@example.com' === $c['card_email'] );
check( 'without a Shows on list the defaults stay', $T::default_shows() === $c['shows'] );
$c2 = $T::sanitize_profile( array( 'shows' => array( 'sent' => '1', 'card' => '1' ) ) );
check( 'with a Shows on list an unticked box means off', array( 'bio' => false, 'team' => false, 'card' => true, 'byline' => false ) === $c2['shows'] );

// 5. What the public sees.
$full = $T::shape( $c );
$pub  = $T::public_profile( $full );
check( 'the public profile has no card only fields', ! array_intersect( $T::card_keys(), array_keys( $pub ) ) );
check( 'the public profile leaves out the line with no source', 2 === count( $pub['bar'] ) && 'Florida' === $pub['bar'][1]['state'] );
check( 'one line needs a source', 1 === $T::unsourced( $full ) );

// 6. Needs a look.
$r = $T::look_reasons( $full, false, 'draft', true, true );
check( 'waiting details, no photo, a line without a source and a draft are all reasons', array( 'pending', 'photo', 'source', 'draft' ) === array_keys( $r ) );
check( 'the count reads in plain words', '1 line needs a source' === $r['source'] );
$r = $T::look_reasons( $T::shape( array() ), true, 'publish', false );
check( 'a photo with no alt text is a reason', array( 'alt' ) === array_keys( $r ) );
check( 'a complete live person needs nothing', array() === $T::look_reasons( $T::shape( array() ), true, 'publish' ) );
check( 'people who need a look come first, each part in order', array( 7, 9, 5, 8 ) === $T::look_first( array( 5, 7, 8, 9 ), array( 7 => array( 'photo' => 'x' ), 9 => array( 'draft' => 'y' ) ) ) );

// 7. Byline helpers.
check( 'profile IDs are cleaned: whole numbers, once, in order', array( 4, 2, 9 ) === $T::clean_ids( array( '4', 2, 4, 'x', -3, 0, 9.0 ) ) );
check( 'at most ten people on a byline', 10 === count( $T::clean_ids( range( 1, 15 ) ) ) );
check( 'one name stays one name', 'Dana Lee' === $T::join_names( array( 'Dana Lee' ) ) );
check( 'two names join with &', 'Dana Lee & Sam Park' === $T::join_names( array( 'Dana Lee', 'Sam Park' ) ) );
check( 'three names: commas, then &', 'Ana, Ben & Dee' === $T::join_names( array( 'Ana', '', 'Ben', 'Dee' ) ) );

echo $fail ? "\n$fail FAILED\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
