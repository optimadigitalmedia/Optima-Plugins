<?php
// Every plugin's visitor-data.json (the list Optima Core's privacy draft reads):
// valid JSON, named for its own folder, every item says what, where and how long,
// every cookie says why and how long, and no em dashes. The plugins that collect
// or pass on visitor data must ship one (inventory INV-2611-03).
//   php tools/tests/visitor-data.php
$root = dirname( __DIR__, 2 );
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }

foreach ( array( 'optima-core', 'optima-chat', 'optima-analytics', 'optima-forms-guard', 'optima-sitecheck', 'optima-reviews', 'optima-contact' ) as $slug ) {
	ok( is_readable( "$root/$slug/visitor-data.json" ), "$slug ships visitor-data.json" );
}

foreach ( glob( $root . '/optima-*/visitor-data.json' ) as $file ) {
	$slug = basename( dirname( $file ) );
	$raw  = (string) file_get_contents( $file );
	$data = json_decode( $raw, true );
	ok( is_array( $data ), "$slug: valid JSON" );
	if ( ! is_array( $data ) ) {
		continue;
	}
	ok( ( $data['plugin'] ?? '' ) === $slug, "$slug: \"plugin\" names its own folder" );
	ok( isset( $data['items'], $data['cookies'] ) && is_array( $data['items'] ) && is_array( $data['cookies'] ), "$slug: has items and cookies lists" );
	ok( '' !== trim( (string) ( $data['notes'] ?? '' ) ) || array() !== $data['items'], "$slug: says something (items or notes)" );
	foreach ( (array) $data['items'] as $i => $item ) {
		foreach ( array( 'what', 'where', 'kept' ) as $key ) {
			ok( '' !== trim( (string) ( $item[ $key ] ?? '' ) ), "$slug: item $i has \"$key\"" );
		}
		$extra = array_diff( array_keys( (array) $item ), array( 'what', 'where', 'kept', 'shared', 'when' ) );
		ok( array() === $extra, "$slug: item $i uses only the schema keys" );
	}
	foreach ( (array) $data['cookies'] as $i => $cookie ) {
		foreach ( array( 'name', 'why', 'lasts' ) as $key ) {
			ok( '' !== trim( (string) ( $cookie[ $key ] ?? '' ) ), "$slug: cookie $i has \"$key\"" );
		}
	}
	ok( false === strpos( $raw, "\u{2014}" ), "$slug: no em dashes" );
}

if ( $fail ) {
	echo "$fail failed\n";
	exit( 1 );
}
echo "All passed\n";
