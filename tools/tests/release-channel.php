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
class WP_Error {
	public $code;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function do_action( $h, ...$a ) { $GLOBALS['t_log'][] = $a[0] ?? ''; }
function wp_delete_file( $f ) { @unlink( $f ); }
// The zip a site would download: written to a temp file, as core's download_url() does.
function download_url( $url, $timeout = 300 ) {
	$GLOBALS['t_dl'][] = $url;
	$f = tempnam( sys_get_temp_dir(), 'optima-test' );
	file_put_contents( $f, $GLOBALS['t_zip'] );
	return $f;
}
$GLOBALS['t_log'] = array();
$GLOBALS['t_dl']  = array();
$GLOBALS['t_zip'] = 'the zip the release workflow built';
ini_set( 'error_log', '/dev/null' );
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
define( 'T_PREFIX', 'https://github.com/optimadigitalmedia/Optima-Plugins/releases/download/' );
function rel( $tag, $pre = false, $draft = false, $zip = true, $url = null, $sha = null ) {
	$slug  = preg_replace( '/-v[0-9].*$/', '', $tag );
	$asset = array( 'name' => $slug . '.zip', 'browser_download_url' => $url ?? ( T_PREFIX . $tag . '/' . $slug . '.zip' ) );
	if ( null !== $sha ) {
		$asset['sha256'] = $sha;
	}
	return array(
		'tag_name'   => $tag,
		'draft'      => $draft,
		'prerelease' => $pre,
		'body'       => 'notes for ' . $tag,
		'assets'     => $zip ? array( $asset ) : array(),
	);
}
$good_sha = hash( 'sha256', $GLOBALS['t_zip'] );

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
	rel( 'optima-aeo-v2.0.0', false, false, true, 'https://example.invalid/optima-aeo-v2.0.0/optima-aeo.zip' ), // Zip listed off the repo's releases.
	rel( 'optima-aeo-v1.9.0', false, false, true, null, $good_sha ),
	rel( 'optima-aeo-v1.8.0', false, false, true, null, str_repeat( 'a', 64 ) ), // Wrong checksum.
	rel( 'optima-aeo-v1.7.0' ),                      // Old release, no checksum.
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

// 1b. Download addresses are pinned to the repo's releases.
$p = Optima_Plugin_Updater::pick( $feed, 'optima-aeo', false );
ok( '1.9.0' === $p['version'], 'a release whose zip is listed anywhere else is skipped (aeo 2.0.0 ignored, 1.9.0 offered)' );
ok( $good_sha === $p['sha256'], 'pick() carries the sha256 from the version list' );
ok( Optima_Plugin_Updater::is_release_url( T_PREFIX . 'optima-aeo-v1.9.0/optima-aeo.zip' ), 'a release download address is accepted' );
ok( ! Optima_Plugin_Updater::is_release_url( 'https://github.com/someone-else/Optima-Plugins/releases/download/optima-aeo-v1.9.0/optima-aeo.zip' ), 'another owner is refused' );
ok( ! Optima_Plugin_Updater::is_release_url( T_PREFIX . '../../evil/releases/download/x/optima-aeo.zip' ), 'dot segments are refused' );
ok( ! Optima_Plugin_Updater::is_release_url( T_PREFIX . 'optima-aeo-v1.9.0/optima-aeo.zip?x=1' ), 'a query string is refused' );
ok( ! Optima_Plugin_Updater::is_release_url( 'http://github.com/optimadigitalmedia/Optima-Plugins/releases/download/optima-aeo-v1.9.0/optima-aeo.zip' ), 'plain http is refused' );
$api = array( 'name' => 'optima-aeo.zip', 'browser_download_url' => T_PREFIX . 'x/optima-aeo.zip', 'digest' => 'sha256:' . strtoupper( $good_sha ) );
ok( $good_sha === Optima_Plugin_Updater::pick( array( array( 'tag_name' => 'optima-aeo-v3.0.0', 'assets' => array( $api ) ) ), 'optima-aeo', false )['sha256'], 'the API fallback\'s digest field is read as the sha256' );

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

// 3b. The download check (upgrader_pre_download).
$pd = static fn( $url, $extra = array() ) => Optima_Plugin_Updater::pre_download( false, $url, null, $extra );
$GLOBALS['t_dl'] = array();
$r = $pd( T_PREFIX . 'optima-aeo-v1.9.0/optima-aeo.zip' );
ok( is_string( $r ) && is_file( $r ) && 1 === count( $GLOBALS['t_dl'] ), 'matching sha256: the checked file is handed to WordPress' );
if ( is_string( $r ) ) {
	@unlink( $r );
}
$r = $pd( T_PREFIX . 'optima-aeo-v1.8.0/optima-aeo.zip' );
ok( $r instanceof WP_Error && 'optima_updater_checksum' === $r->code, 'sha256 mismatch: the install stops with an error' );
$r = $pd( T_PREFIX . 'optima-aeo-v1.7.0/optima-aeo.zip' );
ok( false === $r && false !== strpos( (string) end( $GLOBALS['t_log'] ), 'No sha256 listed' ), 'no sha256 listed (old release): WordPress downloads it as usual, and it is logged' );
$GLOBALS['t_dl'] = array();
$r = $pd( 'https://example.invalid/optima-core.zip', array( 'plugin' => 'optima-core/optima-core.php' ) );
ok( false === $r && array() === $GLOBALS['t_dl'], 'an address off the releases for a plugin this copy does not serve is left alone' );
$ref_plugins = new ReflectionProperty( 'Optima_Plugin_Updater', 'plugins' );
$ref_plugins->setAccessible( true );
$ref_plugins->setValue( null, array( 'optima-core/optima-core.php' => 'optima-core' ) );
$r = $pd( 'https://example.invalid/optima-core.zip', array( 'plugin' => 'optima-core/optima-core.php' ) );
ok( $r instanceof WP_Error && 'optima_updater_source' === $r->code, 'an Optima plugin update from any other address is refused' );
ok( false === $pd( '/tmp/uploaded-by-hand.zip', array( 'plugin' => 'optima-core/optima-core.php' ) ), 'a zip uploaded by hand (a local path) is left alone' );
ok( false === $pd( 'https://downloads.wordpress.org/plugin/x.zip', array( 'plugin' => 'akismet/akismet.php' ) ), 'other plugins are left alone' );
ok( 'kept' === Optima_Plugin_Updater::pre_download( 'kept', T_PREFIX . 'optima-aeo-v1.9.0/optima-aeo.zip' ), 'an earlier filter\'s answer is kept' );
ok( true === $core->auto_update( null, (object) array( 'id' => 'github.com/optimadigitalmedia/Optima-Plugins/optima-core', 'package' => T_PREFIX . 'optima-core-v1.6.0.9/optima-core.zip' ) ), 'auto-update: a release package installs on its own' );
ok( false === $core->auto_update( null, (object) array( 'id' => 'github.com/optimadigitalmedia/Optima-Plugins/optima-core', 'package' => 'https://example.invalid/optima-core.zip' ) ), 'auto-update: any other package never installs on its own' );

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
ok( array( '1.9.0', '1.8.0', '1.7.0' ) === $versions( 'optima-aeo' ), 'catalog: a zip listed off the repo\'s releases is skipped' );
ok( $good_sha === Optima_Hub_Catalog::releases( 'optima-aeo' )[0]['sha256'], 'catalog: carries the sha256 for Build\'s own check' );
ok( 'https://raw.githubusercontent.com/optimadigitalmedia/Optima-Plugins/version-list/catalog-test.json' === Optima_Hub_Catalog::catalog_url(), 'catalog, test channel: reads catalog-test.json from the version-list branch' );
Optima_Hub_Access::set_channel( 'stable' );
ok( 'https://raw.githubusercontent.com/optimadigitalmedia/Optima-Plugins/version-list/catalog.json' === Optima_Hub_Catalog::catalog_url(), 'catalog, stable: reads the promoted catalog.json, never main' );
Optima_Hub_Access::set_channel( 'test' );
Optima_Hub_Catalog::flush();
ok( ! isset( $GLOBALS['t_tr']['optima_hub_releases'] ) && ! isset( $GLOBALS['t_tr']['optima_hub_releases__test'] ), 'catalog flush clears both channels' );

// 6. The wp-config constant wins over the option.
Optima_Hub_Access::set_channel( 'stable' );
define( 'OPTIMA_HUB_CHANNEL', 'test' );
ok( 'test' === Optima_Plugin_Updater::channel() && Optima_Hub_Access::channel_fixed(), 'OPTIMA_HUB_CHANNEL test overrides the stable option' );

echo $fail ? "\n{$fail} failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
