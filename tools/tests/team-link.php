<?php
// Optima Team, Send them a link: the token, what the page takes, how what
// they send lays over the profile, and the firm's approval on a law site.
// The pure parts, no WordPress:
//   php tools/tests/team-link.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( (string) $s, FILTER_VALIDATE_EMAIL ) ? (string) $s : ''; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function esc_url_raw( $u ) { return preg_match( '#^https?://#', (string) $u ) ? (string) $u : ''; }
function absint( $n ) { return abs( (int) $n ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function get_option( $k, $d = false ) { return $d; }
function wp_salt( $s = '' ) { return 'test-salt'; }

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

// 1. The token.
$token = $L::new_token();
check( 'a token is 48 hex characters', $L::well_formed( $token ) && 48 === strlen( $token ) );
check( 'two tokens differ', $token !== $L::new_token() );
check( 'a token with other characters is refused', ! $L::well_formed( str_repeat( 'g', 48 ) ) && ! $L::well_formed( substr( $token, 0, 40 ) ) );
check( 'only a keyed hash is stored, never the token', $L::hash( $token ) !== $token && 64 === strlen( $L::hash( $token ) ) );

$now  = 1_800_000_000;
$hash = $L::hash( $token );
$open = array( 'created' => $now - 10, 'expires' => $now + 100, 'used' => 0 );
check( 'the right token on an open link: ok', 'ok' === $L::state( $open, $hash, $token, $now ) );
check( 'another token: wrong', 'wrong' === $L::state( $open, $hash, $L::new_token(), $now ) );
check( 'no stored hash: wrong', 'wrong' === $L::state( $open, '', $token, $now ) );
check( 'past its end: expired', 'expired' === $L::state( $open, $hash, $token, $now + 100 ) );
check( 'used once: used', 'used' === $L::state( array( 'used' => $now - 5 ) + $open, '', $token, $now ) );
check( 'a link lasts 14 days and allows 8 tries an hour', 14 === $L::DAYS && 8 === $L::TRIES );

// 2. What the page takes: words only, never credentials, links or contact (rule 47).
$sent = array(
	'name'      => '  Dana   Lee ',
	'role'      => 'Partner<script>',
	'bio'       => str_repeat( 'a', 1300 ),
	'email'     => 'dana@example.com',
	'phone'     => '555',
	'bar'       => array( array( 'state' => 'Anywhere', 'source' => 'me' ) ),
	'same_as'   => 'https://example.com/me',
	'intro'     => 'Hello there.',
	'why'       => array( 'not text' ),
	'tags'      => 'Trials, Appeals',
	'user'      => 1,
);
$clean = $L::clean( $sent, false );
check( 'the name, title and bio are kept and cleaned', 'Dana Lee' === $clean['name'] && 'Partner' === $clean['role'] );
check( 'the bio is cut to 1,200 characters', 1200 === strlen( $clean['bio'] ) );
check( 'credentials, links, contact and the login are dropped', array( 'name', 'role', 'bio' ) === array_keys( $clean ) );
$clean = $L::clean( $sent, true );
check( 'with a card on, the card words come through', 'Hello there.' === $clean['card_intro'] && 'Trials, Appeals' === $clean['card_tags'] );
check( 'a card word sent as a list becomes empty', '' === $clean['card_why'] );
check( 'the page offers card words only with A business card on', ! $L::takes_card( $T::shape( array() ) ) && $L::takes_card( $T::shape( array( 'shows' => array( 'card' => true ) ) ) ) );

// 3. Laying what they sent over the profile.
$live = $T::shape( array( 'role' => 'Associate', 'bio' => 'Old bio.', 'card_why' => 'Ask me about trials.', 'bar' => array( array( 'state' => 'North Carolina', 'source' => 'Bar roll' ) ) ) );
$next = $L::merge( $live, array( 'role' => 'Partner', 'bio' => '', 'card_why' => 'Appeals too.' ) );
check( 'a new title replaces the old', 'Partner' === $next['role'] );
check( 'an empty bio keeps the one there', 'Old bio.' === $next['bio'] );
check( 'card words are replaced when sent', 'Appeals too.' === $next['card_why'] );
check( 'credentials never change through the link', $live['bar'] === $next['bar'] && $live['shows'] === $next['shows'] );
$diff = $L::diff( $live, array( 'name' => 'Dana Lee', 'role' => 'Partner' ), 'Dana L.' );
check( 'the review table lists the name and the title', array( 'Name', 'Title' ) === array_keys( $diff ) && array( 'Associate', 'Partner' ) === $diff['Title'] );
check( 'sending the same back changes nothing', array() === $L::diff( $live, array( 'role' => 'Associate' ), 'Dana Lee' ) );

// 4. The firm's approval (Optima Law): no attorney, no change.
check( 'without the firm\'s approval on, Approve may go ahead', '' === $L::approval_problem( false, '', false ) );
check( 'with it on, a name alone is not enough', 'need_by' === $L::approval_problem( true, 'Jordan Whitfield', false ) );
check( 'with it on, the tick alone is not enough', 'need_by' === $L::approval_problem( true, '  ', true ) );
check( 'with it on, the name and the tick let it go ahead', '' === $L::approval_problem( true, 'Jordan Whitfield', true ) );
check( 'without Optima Law the gate is off', ! $L::law_gated() );

echo $fail ? "\n$fail FAILED\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
