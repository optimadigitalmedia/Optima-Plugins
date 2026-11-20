<?php
// Optima Core: one owner for opening hours (INV-2611-07). An office node prints
// its own walk-in hours only while Optima Hours does not own the hours job; while
// it does, the node takes Optima Hours' confirmed schedule. No WordPress needed:
//   php tools/tests/core-offices-hours.php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['t_owns'] = false;
function add_filter() {}
function add_action() {}
function apply_filters( $tag, $value, ...$rest ) { return $value; }
function get_option( $k, $d = false ) { return $d; }
function get_permalink( $id ) { return 'https://example-firm.test/offices/downtown/'; }
function get_posts( $a ) { return array( 7 ); }
function get_post_status( $id ) { return 'publish'; }
function home_url( $p = '' ) { return 'https://example-firm.test' . $p; }
function optima_core_business() { return array( 'business_type' => 'Attorney', 'country' => 'US', 'phone' => '(555) 010-0100' ); }
final class Optima_Core_Business {
	public static function schema_type( $t ) { return 'Attorney' === $t ? 'LegalService' : $t; }
}
require dirname( __DIR__, 2 ) . '/optima-core/includes/offices.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$O      = 'Optima_Core_Offices';
$office = array(
	'id' => 'downtown', 'name' => 'Downtown office', 'page' => '', 'street' => '100 Main St', 'suite' => '', 'city' => 'Springfield',
	'state' => 'NC', 'zip' => '27500', 'phone' => '', 'place_id' => '', 'geo' => array(), 'gbp_url' => '',
	'hours' => array( array( 'days' => array( 'Mo', 'Tu', 'We', 'Th', 'Fr' ), 'opens' => '08:30', 'closes' => '17:30' ) ),
	'phone_hours' => '', 'timezone' => '', 'photo' => '', 'parking' => '', 'entrance' => '', 'courthouses' => array(), 'practices' => array(), 'primary' => true,
);
$count = static function ( $node ) {
	return substr_count( json_encode( $node ), '"openingHoursSpecification"' );
};

// 1. No Optima Build, no Optima Hours: the office prints its own walk-in hours.
$node = $O::node( $office );
ok( ! $O::hours_owned(), 'without Optima Build nothing owns the hours job' );
ok( '08:30' === ( $node['openingHoursSpecification'][0]['opens'] ?? '' ), 'the office prints its own walk-in hours' );

// 2. Optima Build present, Optima Hours not active: the office keeps printing.
if ( true ) {
	function optima_owns( $job ) { return 'hours' === $job && $GLOBALS['t_owns']; }
}
ok( ! $O::hours_owned() && 1 === $count( $O::node( $office ) ), 'Optima Hours not active: the office keeps its own hours' );

// 3. Optima Hours owns the job but its reader is missing: the office keeps printing.
$GLOBALS['t_owns'] = true;
ok( ! $O::hours_owned() && '08:30' === ( $O::node( $office )['openingHoursSpecification'][0]['opens'] ?? '' ), 'no Optima Hours reader loaded: the office keeps its own hours' );

// 4. Optima Hours owns the job: its schedule, once, and never the office's rows.
if ( true ) {
	final class Optima_Hours_Schema {
		public static $props = array();
		public static function properties(): array { return self::$props; }
	}
}
Optima_Hours_Schema::$props = array(
	'openingHoursSpecification'        => array( array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array( 'Monday', 'Tuesday' ), 'opens' => '09:00', 'closes' => '17:00' ) ),
	'specialOpeningHoursSpecification' => array( array( '@type' => 'OpeningHoursSpecification', 'validFrom' => '2026-12-25', 'validThrough' => '2026-12-25', 'opens' => '00:00', 'closes' => '00:00' ) ),
	'somethingElse'                    => 'x',
);
$node = $O::node( $office );
ok( $O::hours_owned(), 'with Optima Hours owning the job, Core defers to it' );
ok( '09:00' === ( $node['openingHoursSpecification'][0]['opens'] ?? '' ), 'the office node carries the Optima Hours schedule' );
ok( 1 === $count( $node ), 'exactly one openingHoursSpecification on the node' );
ok( false === strpos( json_encode( $node ), '08:30' ), 'the office\'s own walk-in rows are not printed' );
ok( isset( $node['specialOpeningHoursSpecification'] ) && ! isset( $node['somethingElse'] ), 'holiday hours come along; nothing else from Optima Hours is copied' );

// 5. Optima Hours owns the job, hours not confirmed yet: no hours at all, never the office's rows.
Optima_Hours_Schema::$props = array();
$node = $O::node( $office );
ok( 0 === $count( $node ), 'unconfirmed Optima Hours schedule: the office node prints no hours' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
