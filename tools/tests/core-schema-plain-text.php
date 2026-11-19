<?php
// Optima Core 1.15.0.0: schema names are plain text, never HTML entities
// (upstream 1.10.5 and 1.14.0). get_the_title() runs wptexturize and the site
// title is stored escaped, so "Smith & Jones's" reached Google as
// "Smith &amp; Jones&#8217;s". No WordPress needed:
//   php tools/tests/core-schema-plain-text.php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['posts'] = array(
	10 => "New Year's Eve",
	11 => 'Rooms &amp; "Suites"',
	13 => "Car <em>accidents</em>",
	14 => '&lt;script&gt;alert(1)&lt;/script&gt;Safe',
);
function get_post_field( $field, $post ) { return 'post_title' === $field ? ( $GLOBALS['posts'][ (int) $post ] ?? '' ) : ''; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); }
function add_action() {}
function add_filter() {}
$root = dirname( __DIR__, 2 ) . '/optima-core/includes/';
require $root . 'schema/class-optima-schema.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$S = 'Optima_Schema';

ok( "New Year's Eve" === $S::title( 10 ), 'a title keeps its apostrophe as typed' );
ok( 'Rooms & "Suites"' === $S::title( 11 ), 'a stored &amp; reads as & and quotes stay plain' );
ok( 'Car accidents' === $S::title( 13 ), 'markup in a title is dropped' );
ok( 'Safe' === $S::title( 14 ), 'an encoded tag is decoded first and then stripped, so it cannot come back as markup' );
ok( 'Smith & Jones' === $S::plain( 'Smith &amp; Jones' ), 'the escaped site title reads as plain text' );
ok( "Smith & Jones\u{2019}s" === $S::plain( 'Smith &#038; Jones&#8217;s' ), 'numeric entities from the display filters decode to the characters' );
ok( 'Joe\'s' === $S::plain( 'Joe&#039;s' ), 'the &#039; WordPress stores for an apostrophe decodes' );
ok( 'It is smart casual.' === $S::plain( "<p>It is smart casual.</p>\n" ), 'an FAQ answer is plain text, trimmed' );
$json = json_encode( array( 'name' => $S::plain( 'Smith &amp; Jones' ) ), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE );
ok( false === strpos( $json, '&amp;' ) && false !== strpos( $json, 'Smith & Jones' ), 'the JSON-LD carries & itself, never &amp;' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
