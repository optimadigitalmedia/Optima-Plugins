<?php
// Optima AI Search: one FAQ system in the schema, titles as plain text, and
// every staging address Optima Core knows counts as staging. No WordPress
// needed:
//   php tools/tests/aeo-faq-schema.php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['titles']  = array( 10 => "New Year&#8217;s Eve &amp; <em>more</em>", 11 => 'Contact' );
$GLOBALS['meta']    = array(
	10 => array(
		array( 'q' => 'Do you charge up front?', 'a' => 'No.' ),
		array( 'q' => 'Where are you?', 'a' => 'Raleigh.' ),
	),
	11 => array(),
);
$GLOBALS['queried'] = 10;
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function is_singular() { return true; }
function get_queried_object_id() { return $GLOBALS['queried']; }
function get_post_meta( $id, $key, $single ) { return '_optima_aeo_faqs' === $key ? ( $GLOBALS['meta'][ $id ] ?? array() ) : ''; }
function get_post_field( $field, $id ) { return 'post_title' === $field ? ( $GLOBALS['titles'][ $id ] ?? '' ) : ''; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); }
function get_permalink( $id ) { return 'https://example.test/p' . $id . '/'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function get_the_date( $f, $id ) { return '2026-01-01T00:00:00+00:00'; }
function get_the_modified_date( $f, $id ) { return '2026-02-01T00:00:00+00:00'; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s ) { return $s; }
function wpautop( $s ) { return '<p>' . $s . '</p>'; }
function get_option( $k ) { return '1'; }
function wp_parse_url( $u, $c ) { return parse_url( $u, $c ); }
function wp_get_environment_type() { return 'production'; }
function apply_filters( $h, $v ) { return $v; }
$root = dirname( __DIR__, 2 ) . '/optima-aeo/includes/';
require $root . 'class-optima-aeo-faq.php';
require $root . 'class-optima-aeo.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$F = 'Optima_AEO_FAQ';

// A page Core does not describe as an FAQ page: one FAQPage joins the graph.
$graph = $F::add_to_graph( array( 'business' => array( '@type' => 'LegalService' ) ) );
ok( isset( $graph['aeo_faq'] ) && 'FAQPage' === $graph['aeo_faq']['@type'], 'the answers join the graph as one FAQPage' );
ok( 2 === count( $graph['aeo_faq']['mainEntity'] ), 'every answer is a Question' );
ok( "New Year\u{2019}s Eve & more" === $graph['aeo_faq']['name'], 'the page name is plain text, never an HTML entity' );
ok( 'https://example.test/#business' === $graph['aeo_faq']['about']['@id'], 'the FAQ page is about #business' );
ob_start();
$F::html( 10 );
$F::schema();
$out = ob_get_clean();
ok( false === strpos( $out, 'application/ld+json' ), 'no second FAQPage block in the footer once the graph carried it' );

// Core's FAQ hub already has a FAQPage: merge, skipping questions it asks.
$hub   = array(
	'faq' => array(
		'@type'      => 'FAQPage',
		'mainEntity' => array( array( '@type' => 'Question', 'name' => 'Do you charge up front?', 'acceptedAnswer' => array( 'text' => 'No fee unless we win.' ) ) ),
	),
);
$graph = $F::add_to_graph( $hub );
ok( ! isset( $graph['aeo_faq'] ), 'no second FAQPage node when Core already has one' );
ok( 2 === count( $graph['faq']['mainEntity'] ), 'only the question Core does not already ask is added' );
ok( 'No fee unless we win.' === $graph['faq']['mainEntity'][0]['acceptedAnswer']['text'], "Core's own answer is kept" );

// A page with no answers leaves the graph alone.
$GLOBALS['queried'] = 11;
$same               = array( 'business' => array( '@type' => 'LegalService' ) );
ok( $same === $F::add_to_graph( $same ), 'a page with no answers leaves the graph untouched' );

// Staging addresses.
$A = 'Optima_AEO';
ok( $A::staging_host( 'firm.wpengine.com' ), 'WP Engine staging is staging without Optima Core' );
ok( $A::staging_host( 'firmstg.wpenginepowered.com' ), 'WP Engine powered is staging' );
ok( ! $A::staging_host( 'firm.kinsta.cloud' ), 'without Optima Core only the WP Engine pair is known' );
ok( $A::staging_host( 'localhost' ) && $A::staging_host( 'site.local' ) && $A::staging_host( '192.168.1.5' ), 'local and private hosts count as staging' );
ok( ! $A::staging_host( 'www.example-law.com' ), 'a live domain is not staging' );
// Declared only now (eval), so the checks above ran without Optima Core.
eval( "final class Optima_Core_Staging { const HOSTS = array( '.wpenginepowered.com', '.wpengine.com', '.kinsta.cloud', '.flywheelsites.com', '.pantheonsite.io', '.cloudwaysapps.com' ); }" );
ok( $A::staging_host( 'firm.kinsta.cloud' ) && $A::staging_host( 'x.pantheonsite.io' ) && $A::staging_host( 'x.cloudwaysapps.com' ), "with Optima Core every host in its list is staging" );
ok( ! $A::staging_host( 'notkinsta.cloud' ), 'a suffix matches only at a dot' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
