<?php
// Optima Hours ships visitor-data.json for Optima Core's privacy draft, and the
// browser storage item it names is the one optima-hours.js really uses.
//   php tools/tests/hours-visitor-data.php
$root = dirname( __DIR__, 2 );
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }

$data = json_decode( (string) @file_get_contents( "$root/optima-hours/visitor-data.json" ), true );
ok( is_array( $data ) && 'optima-hours' === ( $data['plugin'] ?? '' ), 'optima-hours ships visitor-data.json for itself' );
$js    = (string) file_get_contents( "$root/optima-hours/assets/optima-hours.js" );
$names = array_map( static fn( $c ) => (string) ( $c['name'] ?? '' ), (array) ( $data['cookies'] ?? array() ) );
preg_match_all( "/sessionStorage\\.(?:get|set)Item\\('([^']+)'/", $js, $m );
foreach ( array_unique( $m[1] ) as $key ) {
	ok( in_array( "$key (browser storage)", $names, true ), "browser storage item $key is listed" );
}
ok( false === strpos( $js, 'localStorage' ) && false === strpos( $js, 'document.cookie' ), 'the script keeps nothing past the tab' );

if ( $fail ) {
	echo "$fail failed\n";
	exit( 1 );
}
echo "All passed\n";
