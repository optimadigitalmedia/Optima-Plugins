<?php
// Optima Core: cache purge after updates, WP Engine's methods and Cloudflare by URL. No WordPress needed:
//   php tools/tests/core-cache-purge.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['t_opt']     = array();
$GLOBALS['t_trans']   = array();
$GLOBALS['t_actions'] = array();
$GLOBALS['t_posts']   = array();
$GLOBALS['t_logged']  = array();
$GLOBALS['t_reply']   = array( 'code' => 200, 'body' => '{"success":true,"errors":[]}' );
$GLOBALS['t_staging'] = false;
$GLOBALS['t_wpe']     = array();
$GLOBALS['t_theme']   = sys_get_temp_dir() . '/optima-cache-test-' . getmypid();

function home_url( $p = '' ) { return 'https://www.example-firm.com' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function apply_filters( $t, $v, ...$r ) { return $v; }
function add_action( $t, $cb, $p = 10, $n = 1 ) { $GLOBALS['t_actions'][ $t ][] = $cb; }
function do_action( $t, ...$a ) { $GLOBALS['t_did'][ $t ][] = $a; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_opt'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['t_trans'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t_trans'][ $k ] = $v; return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_safe_remote_post( $url, $args ) {
	$GLOBALS['t_posts'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['t_reply'];
}
function get_stylesheet_uri() { return 'https://www.example-firm.com/wp-content/themes/firm/style.css'; }
function get_stylesheet_directory() { return $GLOBALS['t_theme']; }
function get_stylesheet_directory_uri() { return 'https://www.example-firm.com/wp-content/themes/firm'; }
function get_template_directory() { return $GLOBALS['t_theme']; }
function get_template_directory_uri() { return 'https://www.example-firm.com/wp-content/themes/firm'; }
function get_stylesheet() { return 'firm'; }
function get_template() { return 'firm'; }
function wp_get_attachment_url( $id ) { return 'https://www.example-firm.com/wp-content/uploads/2026/11/team.jpg'; }
function wp_get_attachment_metadata( $id ) { return array( 'file' => '2026/11/team.jpg', 'sizes' => array( array( 'file' => 'team-300x200.jpg' ) ) ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
class WP_Error {
	private $c;
	public function __construct( $c = '' ) { $this->c = $c; }
	public function get_error_code() { return $this->c; }
}
final class Optima_Core_Staging {
	public static function is_staging() { return $GLOBALS['t_staging']; }
}
final class WpeCommon {
	public static function purge_memcached() { $GLOBALS['t_wpe'][] = 'purge_memcached'; }
	public static function clear_maxcdn_cache() { $GLOBALS['t_wpe'][] = 'clear_maxcdn_cache'; }
	public static function purge_varnish_cache() { $GLOBALS['t_wpe'][] = 'purge_varnish_cache'; }
}

// error_log goes to a temp file so the test can read it.
$log = $GLOBALS['t_theme'] . '-php.log';
ini_set( 'error_log', $log );

@mkdir( $GLOBALS['t_theme'] . '/assets/css', 0777, true );
@mkdir( $GLOBALS['t_theme'] . '/node_modules/x', 0777, true );
file_put_contents( $GLOBALS['t_theme'] . '/style.css', '' );
file_put_contents( $GLOBALS['t_theme'] . '/assets/css/optima.css', '' );
file_put_contents( $GLOBALS['t_theme'] . '/assets/site.js', '' );
file_put_contents( $GLOBALS['t_theme'] . '/node_modules/x/skip.js', '' );
file_put_contents( $GLOBALS['t_theme'] . '/functions.php', '' );

require dirname( __DIR__, 2 ) . '/optima-core/includes/cache-purge.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$C = 'Optima_Core_Cache';
$C::init();
ok( isset( $GLOBALS['t_actions']['optima_core_purge_urls'], $GLOBALS['t_actions']['enable-media-replace-upload-done'] ), 'Core listens for purge requests and replaced media' );

// 1. WP Engine: every purge method the install has, and only those.
$hosts = $C::purge( 'theme' );
ok( array( 'purge_memcached', 'clear_maxcdn_cache', 'purge_varnish_cache' ) === $GLOBALS['t_wpe'], 'every WP Engine purge method that exists is called, the missing one is skipped' );
ok( array( 'WP Engine' ) === $hosts, 'without Cloudflare set up, only WP Engine is named' );
ok( array() === $GLOBALS['t_posts'], 'no Cloudflare call without the wp-config constants' );

// 2. Not set up: queued URLs are dropped quietly.
$C::queue_urls( array( 'https://www.example-firm.com/a.jpg' ), 'image' );
$C::send_queue();
ok( array() === $GLOBALS['t_posts'], 'a purge request without the constants calls nothing' );

// 3. The theme's files.
$files = $C::asset_files( $GLOBALS['t_theme'] );
ok( array( 'assets/css/optima.css', 'assets/site.js', 'style.css' ) === $files, 'theme .css and .js files are listed, vendor folders and PHP skipped' );
$urls = $C::look_urls();
ok( 'https://www.example-firm.com/' === $urls[0] && in_array( 'https://www.example-firm.com/wp-content/themes/firm/assets/css/optima.css', $urls, true ), 'the home page and the theme files are purged after an update' );
ok( count( $urls ) === count( array_unique( $urls ) ), 'no URL twice' );

// 4. Set up in wp-config.php.
define( 'OPTIMA_CF_ZONE_ID', str_repeat( 'a1', 16 ) );
define( 'OPTIMA_CF_PURGE_TOKEN', 'test-token-never-printed' );
ok( $C::cloudflare_ready(), 'ready with both constants' );
$GLOBALS['t_wpe'] = array();
$hosts            = $C::purge( 'plugin' );
ok( in_array( 'Cloudflare', $hosts, true ) && 1 === count( $GLOBALS['t_posts'] ), 'after an update, Cloudflare is purged once and named' );
$call = $GLOBALS['t_posts'][0];
ok( 'https://api.cloudflare.com/client/v4/zones/' . str_repeat( 'a1', 16 ) . '/purge_cache' === $call['url'], 'the call goes to the zone\'s purge endpoint' );
ok( 'Bearer test-token-never-printed' === $call['args']['headers']['Authorization'], 'the token is sent as a bearer token' );
$body = json_decode( $call['args']['body'], true );
ok( isset( $body['files'] ) && in_array( 'https://www.example-firm.com/', $body['files'], true ), 'the body is {"files":[...]} with the home page' );
ok( false === strpos( wp_json_encode( $GLOBALS['t_opt'] ), 'test-token' ), 'the token is never stored in an option' );

// 5. Batches of 30, own domain only.
$GLOBALS['t_posts'] = array();
$many               = array();
for ( $i = 0; $i < 65; $i++ ) {
	$many[] = 'https://www.example-firm.com/wp-content/uploads/img-' . $i . '.jpg';
}
$many[] = 'https://elsewhere.example.org/x.jpg';
$many[] = 'ftp://www.example-firm.com/x.jpg';
$done   = $C::cloudflare_purge( $many, 'image' );
ok( 3 === $done['sent'] && 3 === count( $GLOBALS['t_posts'] ), '65 URLs go in three calls of at most 30' );
$sizes = array_map( static fn( $p ) => count( json_decode( $p['args']['body'], true )['files'] ), $GLOBALS['t_posts'] );
ok( array( 30, 30, 5 ) === $sizes, 'each call carries at most 30 URLs' );
$all = implode( ' ', array_map( static fn( $p ) => $p['args']['body'], $GLOBALS['t_posts'] ) );
ok( false === strpos( $all, 'elsewhere.example.org' ) && false === strpos( $all, 'ftp:' ), 'other domains and non-web URLs are never sent' );

// 6. Requests from other plugins are gathered and sent once at shutdown.
$GLOBALS['t_posts']   = array();
$GLOBALS['t_actions'] = array();
$C::queue_urls( array( 'https://www.example-firm.com/a.jpg', 'https://www.example-firm.com/b.jpg' ), 'image' );
$C::queue_urls( array( 'https://www.example-firm.com/a.jpg' ), 'image' );
ok( 1 === count( $GLOBALS['t_actions']['shutdown'] ?? array() ) && array() === $GLOBALS['t_posts'], 'nothing is sent until the end of the request, and shutdown is hooked once' );
$C::send_queue();
ok( 1 === count( $GLOBALS['t_posts'] ) && 2 === count( json_decode( $GLOBALS['t_posts'][0]['args']['body'], true )['files'] ), 'one call, each URL once' );

// 7. A replaced image: the file, its sizes and the twins.
$urls = $C::attachment_urls( 12 );
ok( in_array( 'https://www.example-firm.com/wp-content/uploads/2026/11/team-300x200.jpg', $urls, true ) && in_array( 'https://www.example-firm.com/wp-content/uploads/2026/11/team.jpg.webp', $urls, true ), 'an attachment\'s sizes and WebP twins are included' );

// 8. Failures: logged once an hour, never the token.
$GLOBALS['t_posts'] = array();
$GLOBALS['t_reply'] = array( 'code' => 403, 'body' => '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}' );
$done               = $C::cloudflare_purge( $many, 'image' );
ok( 3 === $done['failed'], 'a refused call counts as failed' );
$text = (string) @file_get_contents( $log );
ok( 1 === substr_count( $text, 'Cloudflare cache purge failed' ), 'three failures write one log line' );
ok( false !== strpos( $text, '10000' ) && false === strpos( $text, 'test-token' ), 'the log names the Cloudflare error, never the token' );
$hosts = $C::purge( 'theme' );
ok( ! in_array( 'Cloudflare', $hosts, true ), 'a failed purge is not reported as done' );

// 9. Staging never calls Cloudflare.
$GLOBALS['t_reply']   = array( 'code' => 200, 'body' => '{"success":true}' );
$GLOBALS['t_posts']   = array();
$GLOBALS['t_staging'] = true;
$C::purge( 'theme' );
$C::cloudflare_purge( array( 'https://www.example-firm.com/' ) );
ok( ! $C::cloudflare_ready() && array() === $GLOBALS['t_posts'], 'a staging copy never calls Cloudflare' );

// 10. Optima Optimize asks for the rewritten image's addresses.
require dirname( __DIR__, 2 ) . '/optima-optimize/includes/pipeline/class-optima-optimize-pipeline.php';
$u = Optima_Optimize_Pipeline::urls( 'https://www.example-firm.com/wp-content/uploads', array( 'file' => '2026/11/a.jpg', 'original_image' => 'a-orig.jpg', 'sizes' => array( array( 'file' => 'a-150x150.jpg' ) ) ), true, false );
ok( array( 'https://www.example-firm.com/wp-content/uploads/2026/11/a.jpg', 'https://www.example-firm.com/wp-content/uploads/2026/11/a.jpg.webp', 'https://www.example-firm.com/wp-content/uploads/2026/11/a-orig.jpg', 'https://www.example-firm.com/wp-content/uploads/2026/11/a-orig.jpg.webp', 'https://www.example-firm.com/wp-content/uploads/2026/11/a-150x150.jpg', 'https://www.example-firm.com/wp-content/uploads/2026/11/a-150x150.jpg.webp' ) === $u, 'Optimize lists the file, original, sizes and twins it wrote' );
$batch = (string) file_get_contents( dirname( __DIR__, 2 ) . '/optima-optimize/includes/batch/class-optima-optimize-batch.php' );
ok( false !== strpos( $batch, "do_action( 'optima_core_purge_urls'" ), 'the batch asks Core to purge after rewriting files' );

// Clean up.
foreach ( array( '/assets/css/optima.css', '/assets/site.js', '/node_modules/x/skip.js', '/style.css', '/functions.php' ) as $f ) {
	@unlink( $GLOBALS['t_theme'] . $f );
}
foreach ( array( '/assets/css', '/assets', '/node_modules/x', '/node_modules', '' ) as $d ) {
	@rmdir( $GLOBALS['t_theme'] . $d );
}
@unlink( $log );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
