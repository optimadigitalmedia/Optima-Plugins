<?php
// Optima Forms Guard: the optional Cloudflare Turnstile layer. No WordPress needed:
//   php tools/tests/forms-guard-turnstile.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['t'] = array( 'filters' => array(), 'hooks' => array(), 'blocked' => 0, 'posts' => array(), 'scripts' => array(), 'inline' => array(), 'reply' => array( 'code' => 200, 'body' => '{"success":true}' ) );
function add_filter( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['t']['hooks'][ $tag ][] = $p; }
function add_action( ...$a ) {}
function apply_filters( $tag, $value, ...$a ) { return isset( $GLOBALS['t']['filters'][ $tag ] ) ? $GLOBALS['t']['filters'][ $tag ] : $value; }
function do_action( ...$a ) { $GLOBALS['t']['blocked']++; }
function home_url() { return 'https://example-firm.com/'; }
function wp_parse_url( $u, $c ) { return parse_url( $u, $c ); }
function get_option( $k, $d = false ) { return $d; }
function update_option( ...$a ) {}
function wp_date( $f ) { return gmdate( $f ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_basename( $f ) { return basename( $f ); }
function register_activation_hook( ...$a ) {}
function is_admin() { return false; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_unslash( $v ) { return $v; }
function wp_script_is( $h, $l = 'enqueued' ) { return isset( $GLOBALS['t']['scripts'][ $h ] ); }
function wp_register_script( $h, $src, $deps, $ver, $args ) { $GLOBALS['t']['scripts'][ $h ] = array( 'src' => $src, 'ver' => $ver, 'args' => $args, 'enqueued' => 0 ); }
function wp_add_inline_script( $h, $js, $pos ) { $GLOBALS['t']['inline'][] = $pos; }
function wp_enqueue_script( $h ) { $GLOBALS['t']['scripts'][ $h ]['enqueued']++; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error {}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_safe_remote_post( $url, $args ) {
	$GLOBALS['t']['posts'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['t']['reply'];
}
require dirname( __DIR__, 2 ) . '/optima-forms-guard/optima-forms-guard.php';
require dirname( __DIR__, 2 ) . '/optima-core/includes/client-ip.php';

$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
$T    = 'Optima_Forms_Guard_Turnstile';
$form = array( 'id' => 3 );
$html = '<form id="gform_3"><div class="gform_body">fields</div><div class="gform_footer top_label"><input type="submit"></div></form>';

// 1. Off by default.
Optima_Forms_Guard::init();
ok( ! $T::on() && ! isset( $GLOBALS['t']['hooks']['gform_entry_is_spam'][1] ), 'off without the wp-config keys: no extra hook' );
ok( $html === $T::inject( $html, $form ), 'off: the form is unchanged' );
ok( false === $T::is_spam( false, $form, array() ) && array() === $GLOBALS['t']['posts'], 'off: no call, nothing marked' );
$s = Optima_Forms_Guard::turnstile_status();
ok( 'Off' === $s['value'] && false !== strpos( $s['note'], 'OPTIMA_TURNSTILE_SITE_KEY' ) && 'bad' !== $s['state'], 'the screen says it is off and how to turn it on' );

// 2. On with both keys.
define( 'OPTIMA_TURNSTILE_SITE_KEY', '0x4AAAAAAAtest' );
define( 'OPTIMA_TURNSTILE_SECRET', 'secret-never-printed' );
$GLOBALS['t']['hooks'] = array();
Optima_Forms_Guard::init();
ok( $T::on() && in_array( 9, $GLOBALS['t']['hooks']['gform_entry_is_spam'] ?? array(), true ), 'on: the check runs on gform_entry_is_spam, before the content layer' );
$out = $T::inject( $html, $form );
ok( false !== strpos( $out, 'data-sitekey="0x4AAAAAAAtest"' ) && strpos( $out, 'cf-turnstile' ) < strpos( $out, 'gform_footer' ), 'the widget goes above the submit button' );
ok( false === strpos( $out, 'secret-never-printed' ), 'the secret is never printed' );
$script = $GLOBALS['t']['scripts'][ $T::HANDLE ] ?? array();
ok( 'https://challenges.cloudflare.com/turnstile/v0/api.js' === ( $script['src'] ?? '' ) && 'defer' === ( $script['args']['strategy'] ?? '' ) && null === $script['ver'], 'Cloudflare\'s script loads deferred, with no version added' );
ok( array( 'before' ) === $GLOBALS['t']['inline'], 'the AJAX redraw helper runs before the script, so it stays deferred' );
$T::inject( $html, array( 'id' => 4 ) );
ok( 1 === count( $GLOBALS['t']['inline'] ) && 2 === $GLOBALS['t']['scripts'][ $T::HANDLE ]['enqueued'], 'a second form on the page registers the script once' );
ok( $out === $T::inject( $out, $form ), 'a form that already has the widget is left alone' );
$GLOBALS['t']['filters']['optima_forms_guard_turnstile'] = false;
ok( $html === $T::inject( $html, $form ), 'the filter can skip a form' );
unset( $GLOBALS['t']['filters']['optima_forms_guard_turnstile'] );
ok( 'On' === Optima_Forms_Guard::turnstile_status()['value'], 'the screen says it is on' );

// 3. A good answer passes, with the visitor's real address.
$_SERVER = array( 'REMOTE_ADDR' => '162.158.1.20', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5' );
$_POST   = array( 'cf-turnstile-response' => 'good-token' );
ok( false === $T::is_spam( false, $form, array() ), 'a good answer is not spam' );
$call = $GLOBALS['t']['posts'][0];
ok( 'https://challenges.cloudflare.com/turnstile/v0/siteverify' === $call['url'], 'checked with Cloudflare\'s siteverify' );
ok( 'secret-never-printed' === $call['args']['body']['secret'] && 'good-token' === $call['args']['body']['response'], 'the secret and the answer are sent server to server' );
ok( '203.0.113.5' === $call['args']['body']['remoteip'], 'the visitor address comes from optima_client_ip()' );
ok( false === $T::is_spam( false, $form, array() ) && 1 === count( $GLOBALS['t']['posts'] ), 'one answer is checked once per request' );

// 4. A failed or missing answer marks spam, never an error.
$GLOBALS['t']['reply'] = array( 'code' => 200, 'body' => '{"success":false,"error-codes":["invalid-input-response"]}' );
$_POST                 = array( 'cf-turnstile-response' => 'bad-token' );
$before                = $GLOBALS['t']['blocked'];
ok( true === $T::is_spam( false, $form, array() ), 'a failed answer marks the entry as spam' );
ok( $before + 1 === $GLOBALS['t']['blocked'], 'and counts in the blocked total' );
$_POST = array();
ok( true === $T::is_spam( false, $form, array() ), 'no answer at all marks the entry as spam' );
ok( true === $T::is_spam( true, $form, array() ), 'an entry already spam stays spam' );

// 5. Cloudflare unreachable: let through, the other layers still run.
$GLOBALS['t']['reply'] = new WP_Error();
$_POST                 = array( 'cf-turnstile-response' => 'another-token' );
ok( false === $T::is_spam( false, $form, array() ), 'Cloudflare unreachable: not marked as spam' );

// 6. The visitor data list says it is functional.
$data = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/optima-forms-guard/visitor-data.json' ), true );
ok( false !== stripos( (string) json_encode( $data ), 'functional' ) && false !== stripos( (string) json_encode( $data ), 'Turnstile' ), 'visitor-data.json names Turnstile as a functional check' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
