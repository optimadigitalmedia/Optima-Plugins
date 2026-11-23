<?php
// Optima Analytics stays off on staging copies: without Optima Core it knows the
// WP Engine pair; with Optima Core it reads Optima_Core_Staging::HOSTS, so every
// host Core lists counts as staging. Local and private addresses count either way.
//   php tools/tests/analytics-staging-host.php
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__, 2 );
require $root . '/optima-analytics/includes/class-optima-analytics.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }

// Without Optima Core.
ok( Optima_Analytics::staging_host( 'firmstg.wpengine.com' ), 'no Core: WP Engine staging is staging' );
ok( Optima_Analytics::staging_host( 'firmstg.wpenginepowered.com' ), 'no Core: wpenginepowered.com is staging' );
ok( ! Optima_Analytics::staging_host( 'firm.kinsta.cloud' ), 'no Core: other hosts are not known yet' );
ok( Optima_Analytics::staging_host( 'staging.example.com' ), 'no Core: a staging subdomain is staging' );
ok( Optima_Analytics::staging_host( 'site.local' ) && Optima_Analytics::staging_host( 'localhost' ) && Optima_Analytics::staging_host( '192.168.1.4' ), 'no Core: local and private addresses are staging' );
ok( ! Optima_Analytics::staging_host( 'www.example-law.com' ), 'no Core: a live domain is live' );

// With Optima Core's list.
require $root . '/optima-core/includes/staging-guard.php';
foreach ( Optima_Core_Staging::HOSTS as $suffix ) {
	ok( Optima_Analytics::staging_host( 'firmstg' . $suffix ), "Core: firmstg$suffix is staging" );
}
ok( Optima_Analytics::staging_host( 'FIRM.KINSTA.CLOUD' ), 'Core: host case does not matter' );
ok( ! Optima_Analytics::staging_host( 'www.example-law.com' ), 'Core: a live domain is live' );
ok( ! Optima_Analytics::staging_host( 'kinsta.cloud.example.com' ), 'Core: a suffix in the middle of a live host does not count' );

if ( $fail ) {
	echo "$fail failed\n";
	exit( 1 );
}
echo "All passed\n";
