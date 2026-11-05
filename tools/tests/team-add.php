<?php
// The Add a person walkthrough: the pure parts. No WordPress needed:
//   php tools/tests/team-add.php
// Loads the real class with the few WordPress functions it uses stubbed out.
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

function __( $s, $d = '' ) { return $s; }
function add_action() {}
function add_filter() {}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( (string) $s, FILTER_VALIDATE_EMAIL ) ? (string) $s : ''; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function get_option( $k, $d = false ) { return $d; }

// Optima_Team::is_law() reads settings; the test runs the non-law path.
require __DIR__ . '/../../optima-team/includes/class-optima-team.php';
require __DIR__ . '/../../optima-team/includes/class-optima-team-add.php';

$fail = 0;
function check( string $what, bool $ok ): void {
	global $fail;
	echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $what . "\n";
	if ( ! $ok ) {
		++$fail;
	}
}

// 1. Candidates: everyone in the file who is not on the site, by name, case-insensitively.
$file = array(
	array( 'name' => 'Dana Lee', 'role' => 'Founding Attorney', 'bio' => 'Bio.', 'email' => 'dana@example.com', 'photo' => 'assets/img/dana.webp', 'order' => 1 ),
	array( 'name' => 'Priya Raman', 'role' => 'Associate Attorney' ),
	array( 'name' => '' ),
	array( 'name' => '<b>Jordan Whitfield</b>', 'military_branch' => 'navy' ),
);
$c = Optima_Team_Add::candidates( $file, array( 'dana lee ' ) );
check( 'skips the person already on the site, ignoring case and spaces', 2 === count( $c ) && 'Priya Raman' === $c[0]['name'] );
check( 'drops the row with no name', ! in_array( '', array_column( $c, 'name' ), true ) );
check( 'strips tags from a name', 'Jordan Whitfield' === $c[1]['name'] );
check( 'lists which facts the file holds', array( 'role' ) === $c[0]['has'] && array( 'branch' ) === $c[1]['has'] );
check( 'nobody on the site: everyone named is a candidate', 3 === count( Optima_Team_Add::candidates( $file, array() ) ) );

// 2. State shape: a stale or hand-made transient never breaks the screen.
$s = Optima_Team_Add::clean( array( 'step' => 9, 'source' => 'hub', 'name' => ' Dana ', 'user' => '-4', 'attorney' => 'yes', 'photo_id' => '12' ) );
check( 'an unknown step falls back to 1', 1 === $s['step'] );
check( 'an unknown source becomes empty', '' === $s['source'] );
check( 'name is trimmed', 'Dana' === $s['name'] );
check( 'user is a whole number', 4 === $s['user'] );
check( 'attorney is 0 or 1', 1 === $s['attorney'] );
check( 'photo id is kept', 12 === $s['photo_id'] );
check( 'every key is present on an empty state', array_keys( Optima_Team_Add::clean( array() ) ) === array( 'step', 'source', 'name', 'role', 'bio', 'email', 'branch', 'photo', 'photo_id', 'attorney', 'user', 'order' ) );

// 3. The pre-publish checks on gathered data.
$k = Optima_Team_Add::checks( Optima_Team_Add::clean( array( 'role' => 'Partner', 'bio' => 'Short bio', 'photo' => 'assets/img/x.webp', 'user' => 0 ) ) );
check( 'photo from the file counts as a photo', true === $k['photo'][2] );
check( 'role and bio together pass the bio check', true === $k['bio'][2] );
check( 'no linked user means no byline, and that is allowed', false === $k['byline'][2] );
check( 'no bar check on a non-law site', ! isset( $k['bar'] ) );

echo $fail ? "\n$fail FAILED\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
