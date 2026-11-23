<?php
// Optima Accessibility weekly heading check: a classic theme can say a template
// prints no page title (optima_accessibility_template_prints_title), so a page
// whose own content carries its one H1 is not flagged for a second H1.
//   php tools/tests/accessibility-headings.php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Post {
	public $ID = 7;
	public $post_type = 'page';
	public $post_name = 'status';
	public $template = '';
}
$GLOBALS['filters'] = array();
function add_filter( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['filters'][ $tag ][] = array( $cb, $n ); }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $tag ] ?? array() as $f ) {
		$value = call_user_func_array( $f[0], array_slice( array_merge( array( $value ), $args ), 0, $f[1] ) );
	}
	return $value;
}
function add_action( $tag, $cb, $p = 10, $n = 1 ) {}
function wp_is_block_theme() { return false; }
function get_page_template_slug( $post ) { return $post->template; }
require dirname( __DIR__, 2 ) . '/optima-accessibility/includes/class-optima-accessibility-checks.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
$C = 'Optima_Accessibility_Checks';

$plain   = new WP_Post();
$partner = new WP_Post();
$partner->template = 'page-templates/private-partner.php';
$html = '<h1>Program status</h1><h2>This month</h2><h3>Calls</h3>';

ok( true === $C::template_prints_title( $plain ), 'a classic theme prints the title by default' );
ok( 1 === count( $C::heading_skips( $html, $C::template_prints_title( $partner ) ) ), 'without the filter the content H1 is flagged as a second H1' );

add_filter(
	'optima_accessibility_template_prints_title',
	function ( $prints, $post ) {
		return 'page-templates/private-partner.php' === get_page_template_slug( $post ) ? false : $prints;
	},
	10,
	2
);
ok( false === $C::template_prints_title( $partner ), 'the filter says the partner template prints no title' );
ok( array() === $C::heading_skips( $html, $C::template_prints_title( $partner ) ), 'that page\'s own H1 is no longer flagged' );
ok( true === $C::template_prints_title( $plain ), 'other templates still print the title' );
ok( 1 === count( $C::heading_skips( $html, $C::template_prints_title( $plain ) ) ), 'a content H1 under a printed title is still flagged' );
ok( 1 === count( $C::heading_skips( '<h1>A</h1><h1>B</h1>', false ) ), 'a real second H1 on a title-less template is still flagged' );

exit( $fail ? 1 : 0 );
