<?php
// Optima Core 1.15.0.0 (upstream 1.10.0): language versions (hreflang). Links save both
// ways, the head lists each live version plus x-default, and pages nobody linked print
// nothing. No WordPress needed:
//   php tools/tests/core-hreflang.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'OPTIMA_SEO_META_NOINDEX', '_optima_seo_noindex' );
define( 'OPTIMA_SEO_META_CANONICAL', '_optima_seo_canonical' );
$GLOBALS['meta']   = array();
$GLOBALS['status'] = array( 1 => 'publish', 2 => 'publish', 3 => 'publish', 4 => 'draft' );
$GLOBALS['locale'] = 'en_US';
function add_action() {}
function add_filter() {}
function apply_filters( $t, $v ) { return $v; }
function get_locale() { return $GLOBALS['locale']; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); }
function get_post( $id ) { return isset( $GLOBALS['status'][ $id ] ) ? (object) array( 'ID' => $id ) : null; }
function get_post_status( $id ) { return $GLOBALS['status'][ $id ] ?? false; }
function get_post_type( $id ) { return 'page'; }
function get_permalink( $id ) { return 'https://firm.test/p' . $id . '/'; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
require dirname( __DIR__, 2 ) . '/optima-core/includes/seo/class-seo-hreflang.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$L = 'Optima_SEO_Hreflang';

ok( 'pt-BR' === $L::code( 'PT_br' ) && 'zh-Hant' === $L::code( 'zh-hant' ) && 'es-419' === $L::code( 'es-419' ), 'codes are written in their usual case' );
ok( '' === $L::code( 'spanish' ) && '' === $L::code( '' ), 'anything that is not a language code is refused' );
ok( 'en-US' === $L::site_lang(), 'the site language comes from the locale' );
ok( 'en-US' === $L::default_lang( array( 'es', 'en-US' ) ) && 'en' === $L::default_lang( array( 'es', 'en' ) ) && '' === $L::default_lang( array( 'es', 'fr' ) ), 'x-default is the site-language version, else none' );

ok( array() === $L::tags( 1 ), 'a page nobody linked prints nothing' );
$group = $L::save_links( 1, '', array( 'es' => 2 ) );
ok( array( 'en-US' => 1, 'es' => 2 ) === $group, 'the group holds both pages' );
ok( array( 'en-US' => 1 ) === $L::links( 2 ) && array( 'es' => 2 ) === $L::links( 1 ), 'links are saved both ways' );
ok( 'es' === get_post_meta( 2, '_optima_lang' ), 'the linked page gets its language' );
$tags = $L::tags( 1 );
ok( 3 === count( $tags ) && false !== strpos( implode( "\n", $tags ), 'hreflang="x-default" href="https://firm.test/p1/"' ), 'two versions and x-default pointing at the site-language page' );

$GLOBALS['meta'][2][ OPTIMA_SEO_META_NOINDEX ] = '1';
ok( array() === $L::tags( 1 ), 'a version hidden from search is left out (one version left: nothing printed)' );
unset( $GLOBALS['meta'][2][ OPTIMA_SEO_META_NOINDEX ] );
$L::save_links( 1, '', array( 'es' => 2, 'fr' => 4 ) );
ok( 3 === count( $L::tags( 1 ) ), 'a draft version is not printed' );

$L::save_links( 1, '', array() );
ok( array() === $L::links( 1 ) && ! in_array( 1, $L::links( 2 ), true ) && ! in_array( 1, $L::links( 4 ), true ), 'a page that leaves the group is removed from every page in it' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
