<?php
// Optima Core 1.15.0.0: verification codes, thank-you pages, the SEO save, and
// the canonical fixes (page 2 and on, not-found and search). No WordPress needed:
//   php tools/tests/core-seo-gaps.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'OPTIMA_SEO_META_CANONICAL', '_optima_seo_canonical' );
define( 'OPTIMA_SEO_META_TITLE', '_optima_seo_title' );
$GLOBALS['opts']      = array( 'page_on_front' => 12, 'page_for_posts' => 30, 'permalink_structure' => '/%postname%/' );
$GLOBALS['transient'] = false;
$GLOBALS['q']         = array();   // Which WordPress conditionals are true.
$GLOBALS['paged']     = 0;
$GLOBALS['meta']      = array();
$GLOBALS['setup']     = array();   // The theme's per-URL seo data for the request.
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function get_transient( $k ) { return $GLOBALS['transient']; }
function set_transient( $k, $v ) { $GLOBALS['transient'] = $v; }
function get_posts( $a ) { return array( 40 ); } // A page at the address thank-you.
function home_url( $p = '' ) { return 'https://firm.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function url_to_postid( $u ) { return array( 'https://firm.test/message-sent/' => 41, 'https://firm.test/' => 12 )[ $u ] ?? 0; }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function esc_url_raw( $s ) { return (string) $s; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
foreach ( array( 'is_singular', 'is_front_page', 'is_home', 'is_404', 'is_search', 'is_category', 'is_tag', 'is_tax', 'is_post_type_archive' ) as $fn ) {
	eval( "function $fn() { return ! empty( \$GLOBALS['q']['$fn'] ); }" );
}
function get_query_var( $k ) { return 'paged' === $k ? $GLOBALS['paged'] : ''; }
function get_queried_object_id() { return $GLOBALS['qid'] ?? 0; }
function get_queried_object() { return null; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_permalink( $id ) { return 'https://firm.test/' . ( 30 === $id ? 'blog' : 'page-' . $id ) . '/'; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function user_trailingslashit( $s, $t = '' ) { return trailingslashit( $s ); }
function add_query_arg( $k, $v, $u ) { return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
final class Optima_SEO_Setup {
	public static function page_has( $k ) { return array_key_exists( $k, $GLOBALS['setup'] ); }
	public static function page( $k ) { return $GLOBALS['setup'][ $k ] ?? ''; }
	public static function tokens( $s ) { return $s; }
	public static function resolve( $s ) { return 0 === strpos( $s, '/' ) ? 'https://firm.test' . $s : $s; }
}
final class GFAPI {
	public static function get_forms() {
		return array(
			array( 'id' => 1, 'confirmations' => array(
				array( 'isActive' => true, 'type' => 'redirect', 'url' => 'https://firm.test/message-sent/' ),
				array( 'type' => 'page', 'pageId' => 12 ),                                                 // Sends people to the home page.
				array( 'isActive' => true, 'type' => 'redirect', 'url' => 'https://other.test/thanks/' ),  // Another site.
				array( 'isActive' => false, 'type' => 'page', 'pageId' => 77 ),                            // Switched off.
				array( 'isActive' => true, 'type' => 'message', 'message' => 'Thanks' ),
			) ),
		);
	}
}
$root = dirname( __DIR__, 2 ) . '/optima-core/includes/seo/';
require $root . 'seo-settings.php';
require $root . 'class-seo-head.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

// 1. Verification codes.
$S = 'Optima_Core_SEO_Settings';
ok( 'AbC_123-xyz=' === $S::code( '<meta name="google-site-verification" content="AbC_123-xyz=" />' ), 'a pasted Google tag keeps only its code' );
ok( '1A2B3C' === $S::code( "<meta name='msvalidate.01' content='1A2B3C'>" ), 'a pasted Bing tag with single quotes keeps only its code' );
ok( 'abc123' === $S::code( '  abc123  ' ), 'a bare code is trimmed' );
ok( '' === $S::code( 'google8245b9fe93916e79.html' ), 'a Google HTML file name is refused: it is a file check, not a code' );
ok( 'abc' === $S::code( 'a<b>c"' ) && 120 === strlen( $S::code( str_repeat( 'a', 300 ) ) ), 'stray characters are dropped and a code is capped at 120' );

// 2. Saving the SEO screen keeps what it does not show.
$GLOBALS['opts']['optima_seo_defaults'] = array( 'purge_on_uninstall' => '1', 'alternate_name' => 'Smith Law', 'google_verify' => 'old' );
$saved = $S::sanitize( array( 'default_image' => 'https://firm.test/a.png', 'home_description' => 'Hi', 'bing_verify' => '<meta name="msvalidate.01" content="B1" />' ) );
ok( '1' === $saved['purge_on_uninstall'], 'purge_on_uninstall survives a save' );
ok( 'Smith Law' === $saved['alternate_name'] && 'old' === $saved['google_verify'], 'a field an older form did not post keeps its saved value' );
ok( 'B1' === $saved['bing_verify'], 'the Bing code is cleaned on save' );

// 3. Thank-you pages.
$H   = 'Optima_SEO_Head';
$ids = $H::thanks_pages();
sort( $ids );
ok( array( 40, 41 ) === $ids, 'thank-you pages: the thank-you address and the Gravity Forms redirect on this site' );
ok( ! in_array( 12, $ids, true ) && ! in_array( 30, $ids, true ), 'the home page and the posts page are never hidden' );
ok( ! in_array( 77, $ids, true ), 'a switched-off confirmation is ignored' );
ok( 0 === $H::confirmation_page( array( 'type' => 'redirect', 'url' => 'https://other.test/thanks/' ) ), 'a redirect to another site is ignored' );

// 4. Canonicals.
$GLOBALS['q'] = array( 'is_home' => true );
ok( 'https://firm.test/blog/' === $H::canonical_url(), 'the blog list: the posts page' );
$GLOBALS['paged'] = 3;
ok( 'https://firm.test/blog/page/3/' === $H::canonical_url(), 'page 3 of the blog names itself, not page 1' );
$GLOBALS['opts']['permalink_structure'] = '';
ok( 'https://firm.test/blog/?paged=3' === $H::canonical_url(), 'without pretty links: ?paged=3' );
$GLOBALS['opts']['permalink_structure'] = '/%postname%/';
$GLOBALS['setup'] = array( 'canonical' => '/blog/' );
ok( 'https://firm.test/blog/' === $H::canonical_url(), 'the theme\'s own canonical for the URL still wins' );
$GLOBALS['setup'] = array( 'canonical' => false );
ok( '' === $H::canonical_url(), 'a theme that says "no canonical" still gets none' );
$GLOBALS['setup'] = array();
$GLOBALS['paged'] = 0;
$GLOBALS['q'] = array( 'is_404' => true );
ok( '' === $H::canonical_url(), 'a not-found page prints no canonical' );
$GLOBALS['q'] = array( 'is_search' => true );
ok( '' === $H::canonical_url(), 'a search page prints no canonical' );
$GLOBALS['q'] = array( 'is_singular' => true );
$GLOBALS['qid'] = 5;
$GLOBALS['paged'] = 2;
ok( 'https://firm.test/page-5/' === $H::canonical_url(), 'a single page split into parts keeps its own address' );
$GLOBALS['meta'][5][ OPTIMA_SEO_META_CANONICAL ] = 'https://firm.test/other/';
ok( 'https://firm.test/other/' === $H::canonical_url(), 'the SEO box canonical wins' );

// 5. The posts page's SEO title, with the page number past page 1.
$GLOBALS['q']     = array( 'is_home' => true );
$GLOBALS['qid']   = 0;
$GLOBALS['paged'] = 2;
$GLOBALS['meta'][30][ OPTIMA_SEO_META_TITLE ] = 'Firm Insights';
ok( 30 === $H::seo_post_id(), 'the blog list reads the posts page\'s SEO box' );
ok( 'Firm Insights, page 2' === $H::document_title( 'WordPress title' ), 'the posts page SEO title, with ", page 2"' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
