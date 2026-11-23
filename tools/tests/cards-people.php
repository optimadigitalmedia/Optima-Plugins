<?php
// Optima Cards reading People: the card's words move into the profile once,
// People wins over the card's own saved words, the people with a card come in
// team order, and a save keeps every card's own settings. Pure parts, no
// WordPress:
//   php tools/tests/cards-people.php
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['types'] = array( 10 => 'optima_team', 11 => 'optima_team', 12 => 'optima_team', 99 => 'page' );

function __( $s, $d = '' ) { return $s; }
function add_action() {}
function add_filter() {}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ), '-' ) ); }
function esc_url_raw( $u ) { return (string) $u; }
function absint( $n ) { return abs( (int) $n ); }
function get_post_type( $id ) { return $GLOBALS['types'][ $id ] ?? false; }
function get_post_field( $field, $id ) { return 'person-' . $id; }

require __DIR__ . '/../../optima-cards/includes/settings.php';
require __DIR__ . '/../../optima-cards/includes/admin.php';
$S = 'Optima_Cards_Settings';
$A = 'Optima_Cards_Admin';

$fail = 0;
function check( string $what, bool $ok ): void {
	global $fail;
	echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $what . "\n";
	if ( ! $ok ) {
		++$fail;
	}
}

// 1. The one-time move: the card's words fill only what People is missing.
$profile = array( 'role' => 'Partner', 'card_intro' => '', 'card_why' => 'People says this.', 'card_tags' => '' );
$card    = array( 'profile' => 10, 'intro' => 'Hi, I am Ana.', 'pick' => 'The card says this.', 'tags' => 'Trials, Appeals' );
$next    = $S::copy_words( $profile, $card );
check( 'an empty intro in People gets the card\'s', 'Hi, I am Ana.' === $next['card_intro'] );
check( 'a People value is never overwritten', 'People says this.' === $next['card_why'] );
check( 'tags move too', 'Trials, Appeals' === $next['card_tags'] );
check( 'nothing else on the profile changes', 'Partner' === $next['role'] );
check( 'a card with no words changes nothing', $profile === $S::copy_words( $profile, array( 'profile' => 10 ) ) );

// 2. One card from People and the card's saved record.
$person = array(
	'id'         => 10,
	'status'     => 'publish',
	'on'         => true,
	'name'       => 'Ana Ruiz',
	'slug'       => 'ana-ruiz',
	'role'       => 'Partner',
	'email'      => 'ana@example.com',
	'card_email' => 'ana.card@example.com',
	'phone'      => '(919) 555-0100',
	'intro'      => '',
	'why'        => 'Ask about trials.',
	'tags'       => '',
	'photo_id'   => 7,
);
$row = $S::merge_person( $person, array( 'slug' => 'ana', 'intro' => 'Old card intro.', 'pick' => 'Old why.', 'tags' => 'Old, Tags', 'share_id' => 3 ) );
check( 'name, title and photo come from People', 'Ana Ruiz' === $row['name'] && 'Partner' === $row['title'] && 7 === $row['photo_id'] );
check( 'the card email wins over the profile email', 'ana.card@example.com' === $row['email'] );
check( 'the card phone comes from People', '(919) 555-0100' === $row['phone'] );
check( 'People\'s "why talk to them" wins', 'Ask about trials.' === $row['pick'] );
check( 'with no intro in People the card\'s own is the fallback', 'Old card intro.' === $row['intro'] && 'Old, Tags' === $row['tags'] );
check( 'the card keeps its link name and share picture', 'ana' === $row['slug'] && 3 === $row['share_id'] );
check( 'a new card takes the profile\'s address name', 'ana-ruiz' === $S::merge_person( $person, array() )['slug'] );
check( 'no card email: the profile email', 'ana@example.com' === $S::merge_person( array( 'card_email' => '' ) + $person, array() )['email'] );

// 3. Who has a card, in order.
$saved = array(
	array( 'profile' => 11, 'slug' => 'ben', 'share_id' => 5 ),
	array( 'name' => 'Old Card', 'slug' => 'old' ),
	array( 'profile' => 12, 'slug' => 'dee' ),
);
$recs = $S::order_records( $saved, array( 10, 11 ) );
check( 'people with a card come in team order', array( 10, 11 ) === array( (int) $recs[0]['profile'], (int) $recs[1]['profile'] ) );
check( 'their saved card settings come with them', 'ben' === $recs[1]['slug'] && 5 === $recs[1]['share_id'] );
check( 'someone whose card is off in People has no card', ! in_array( 12, array_map( static fn( $r ) => (int) ( $r['profile'] ?? 0 ), $recs ), true ) );
check( 'a card set up before People, pointing at no profile, keeps working', 'Old Card' === $recs[2]['name'] && 3 === count( $recs ) );

// 4. Saving the Cards screen while it reads People.
$old    = array(
	array( 'profile' => 10, 'slug' => 'ana', 'intro' => 'Kept for the fallback.', 'share_id' => 1 ),
	array( 'profile' => 12, 'slug' => 'dee' ),
	array( 'name' => 'Old Card', 'slug' => 'old' ),
);
$posted = array(
	10 => array( 'keep' => '1', 'slug' => 'Ana R', 'share_id' => '4', 'spot_heading' => 'Book a call' ),
	11 => array( 'keep' => '1' ),
	99 => array( 'keep' => '1', 'slug' => 'nope' ),
);
$out = $A::save_profiles( $old, $posted );
$by  = array();
foreach ( $out as $r ) {
	$by[ (int) ( $r['profile'] ?? 0 ) ][] = $r;
}
check( 'the card\'s own settings are saved', 'ana-r' === $by[10][0]['slug'] && 4 === $by[10][0]['share_id'] && 'Book a call' === $by[10][0]['spot_heading'] );
check( 'its old words stay for the fallback', 'Kept for the fallback.' === $by[10][0]['intro'] );
check( 'a profile with no card and nothing saved gets no record', ! isset( $by[11] ) );
check( 'a record for a profile not on the form stays as it was', array( array( 'profile' => 12, 'slug' => 'dee' ) ) === $by[12] );
check( 'a page that is not a person is ignored', ! isset( $by[99] ) );
check( 'a card that points at no profile is kept exactly', array( array( 'name' => 'Old Card', 'slug' => 'old' ) ) === $by[0] );

echo $fail ? "\n$fail FAILED\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
