<?php
// Release channels: which version each site is offered. No WordPress needed:
//   php tools/tests/release-channel.php
// Loads the real updater, Optima_Hub_Access and Optima_Hub_Catalog with the few
// WordPress functions they use stubbed out, and a made-up release list.
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'OPTIMA_HUB_VERSION', 'test' );

$GLOBALS['t_opt'] = array();
$GLOBALS['t_tr']  = array();
$GLOBALS['t_get'] = 0;
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_opt'] ) ? $GLOBALS['t_opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_opt'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['t_tr'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t_tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t_tr'][ $k ] ); return true; }
function add_filter() {}
function add_action() {}
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function is_wp_error( $x ) { return false; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['t_get']++;
	return array( 'code' => 200, 'body' => json_encode( $GLOBALS['t_feed'] ) );
}

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
function rel( $tag, $pre = false, $draft = false, $zip = true ) {
	$slug = preg_replace( '/-v[0-9].*$/', '', $tag );
	return array(
		'tag_name'   => $tag,
		'draft'      => $draft,
		'prerelease' => $pre,
		'body'       => 'notes for ' . $tag,
		'assets'     => $zip ? array( array( 'name' => $slug . '.zip', 'browser_download_url' => 'https://example.invalid/' . $tag . '/' . $slug . '.zip' ) ) : array(),
	);
}

$GLOBALS['t_feed'] = array(
	rel( 'optima-core-v1.6.0.9' ),
	rel( 'optima-core-v1.6.0.10', true ),            // Test release, newest.
	rel( 'optima-core-v1.6.0.11', true, true ),      // Draft: never offered.
	rel( 'optima-team-v0.1.9.7' ),
	rel( 'optima-team-v0.1.9.8' ),                   // Promoted: normal and newest.
	rel( 'optima-team-v0.1.9.6', true ),             // Old test release.
	rel( 'optima-hours-v0.1.8.9', true, false, false ), // Test release with no zip.
	rel( 'optima-hours-v0.1.8.8' ),
	rel( 'optima-cards-v1.0.21.8', true ),           // Only a test release so far.
	rel( 'optima-core-extra-v9.0.0' ),               // Another plugin whose name starts the same.
);

require dirname( __DIR__, 2 ) . '/tools/updater/class-optima-plugin-updater.php';
$feed = $GLOBALS['t_feed'];

// 1. pick(): the version choice on its own.
$p = Optima_Plugin_Updater::pick( $feed, 'optima-core', false );
ok( '1.6.0.9' === $p['version'] && false === $p['prerelease'], 'stable ignores the test release (core 1.6.0.9)' );
$p = Optima_Plugin_Updater::pick( $feed, 'optima-core', true );
ok( '1.6.0.10' === $p['version'] && true === $p['prerelease'], 'test takes the newer test release (core 1.6.0.10)' );
ok( false === strpos( $p['package'], '1.6.0.11' ), 'drafts are never offered, on either channel' );
$p = Optima_Plugin_Updater::pick( $feed, 'optima-team', true );
ok( '0.1.9.8' === $p['version'] && false === $p['prerelease'], 'test takes a normal release when it is the newest (team 0.1.9.8)' );
$p = Optima_Plugin_Updater::pick( $feed, 'optima-team', false );
ok( '0.1.9.8' === $p['version'], 'stable takes the same normal release (team 0.1.9.8)' );
$p = Optima_Plugin_Updater::pick( $feed, 'optima-hours', true );
ok( '0.1.8.8' === $p['version'], 'a test release without its zip is skipped (hours 0.1.8.8)' );
ok( null === Optima_Plugin_Updater::pick( $feed, 'optima-cards', false ), 'stable: a plugin with only a test release has nothing to offer' );
ok( '1.0.21.8' === Optima_Plugin_Updater::pick( $feed, 'optima-cards', true )['version'], 'test: the only test release is offered (cards 1.0.21.8)' );
ok( '1.6.0.9' === Optima_Plugin_Updater::pick( $feed, 'optima-core', false )['version'], 'another plugin\'s tags never count (optima-core-extra-v9.0.0 ignored)' );

// 2. No Optima Build: always stable.
ok( 'stable' === Optima_Plugin_Updater::channel(), 'no Optima Build: channel is stable' );
$GLOBALS['t_opt']['optima_hub_channel'] = 'test';
ok( 'stable' === Optima_Plugin_Updater::channel(), 'no Optima Build: a stored "test" option is not read' );

// 3. The full check() path, as WordPress calls it.
$file = '/var/www/wp-content/plugins/optima-core/optima-core.php';
$GLOBALS['t_opt'] = array();
// The constructor is private (boot() wires it to WordPress), so build one directly.
$ref  = new ReflectionClass( 'Optima_Plugin_Updater' );
$make = $ref->getConstructor();
$make->setAccessible( true );
$core = $ref->newInstanceWithoutConstructor();
$make->invoke( $core, $file, 'optima-core' );
$offer = $core->check( false, array( 'Version' => '1.6.0.9' ), 'optima-core/optima-core.php' );
ok( '1.6.0.9' === $offer['version'] && false === $offer['prerelease'], 'check(), no Build: offers 1.6.0.9 (same as installed, so up to date)' );
ok( isset( $GLOBALS['t_tr']['optima_updater_optima-core'] ) && ! isset( $GLOBALS['t_tr']['optima_updater_optima-core__test'] ), 'stable caches under the old key' );

// 4. With Optima Build: the option, then the constant.
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-access.php';
ok( 'stable' === Optima_Plugin_Updater::channel(), 'Build, no option: stable' );
$GLOBALS['t_opt']['optima_hub_channel'] = 'garbage';
ok( 'stable' === Optima_Plugin_Updater::channel(), 'Build, unknown option value: stable' );
Optima_Hub_Access::set_channel( 'test' );
ok( 'test' === Optima_Plugin_Updater::channel(), 'Build, option test: test' );
$offer = $core->check( false, array( 'Version' => '1.6.0.9' ), 'optima-core/optima-core.php' );
ok( '1.6.0.10' === $offer['version'] && true === $offer['prerelease'], 'check(), test channel: offers the test release 1.6.0.10' );
ok( isset( $GLOBALS['t_tr']['optima_updater_optima-core__test'] ), 'test caches under its own key' );
ok( 'optima_updater_optima-core__test' === Optima_Hub_Access::updater_key( 'optima-core' ) && Optima_Hub_Access::updater_key( 'optima-core' ) === Optima_Plugin_Updater::cache_key_for( 'optima-core', 'test' ), 'Build and the updater agree on the cache key' );
Optima_Hub_Access::set_channel( 'stable' );
$offer = $core->check( false, array( 'Version' => '1.6.0.9' ), 'optima-core/optima-core.php' );
ok( '1.6.0.9' === $offer['version'], 'flipping back to stable: offered 1.6.0.9 again, no stale test answer' );
$core->flush();
ok( ! isset( $GLOBALS['t_tr']['optima_updater_optima-core'] ) && ! isset( $GLOBALS['t_tr']['optima_updater_optima-core__test'] ), 'flush() clears both channels' );
ok( 1 === $GLOBALS['t_get'], 'the release list was downloaded once and shared' );

// 5. Optima Build's catalog (turn on, go back) follows the channel.
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-catalog.php';
$versions = static fn( $slug ) => array_column( Optima_Hub_Catalog::releases( $slug ), 'version' );
ok( array( '1.6.0.9' ) === $versions( 'optima-core' ), 'catalog, stable: only normal releases' );
ok( array() === $versions( 'optima-cards' ), 'catalog, stable: nothing to install for a plugin with only a test release' );
Optima_Hub_Access::set_channel( 'test' );
ok( array( '1.6.0.10', '1.6.0.9' ) === $versions( 'optima-core' ), 'catalog, test: the test release first, then the normal one' );
ok( true === Optima_Hub_Catalog::releases( 'optima-core' )[0]['prerelease'], 'catalog, test: the test release is flagged' );
Optima_Hub_Catalog::flush();
ok( ! isset( $GLOBALS['t_tr']['optima_hub_releases'] ) && ! isset( $GLOBALS['t_tr']['optima_hub_releases__test'] ), 'catalog flush clears both channels' );

// 6. The wp-config constant wins over the option.
Optima_Hub_Access::set_channel( 'stable' );
define( 'OPTIMA_HUB_CHANNEL', 'test' );
ok( 'test' === Optima_Plugin_Updater::channel() && Optima_Hub_Access::channel_fixed(), 'OPTIMA_HUB_CHANNEL test overrides the stable option' );

echo $fail ? "\n{$fail} failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
