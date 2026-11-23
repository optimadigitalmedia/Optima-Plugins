<?php
// Optima Accessibility "Describe the image" on Weekly checks: saving a featured
// image's description clears that miss from the stored results, keeps the
// page while it still has content images to fix, and drops it when nothing is left.
//   php tools/tests/accessibility-alt.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'OPTIMA_ACCESSIBILITY_FILE', __FILE__ );
$GLOBALS['thumbs'] = array( 10 => 501, 11 => 502, 12 => 501 );
function get_post_thumbnail_id( $id ) { return $GLOBALS['thumbs'][ (int) $id ] ?? 0; }
require dirname( __DIR__, 2 ) . '/optima-accessibility/includes/class-optima-accessibility-admin.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }

$images = array(
	array( 'id' => 10, 'count' => 0, 'featured' => 1 ),
	array( 'id' => 11, 'count' => 2, 'featured' => 1 ),
	array( 'id' => 12, 'count' => 3, 'featured' => 1 ),
	array( 'id' => 13, 'count' => 1, 'featured' => 0 ),
);
$out = Optima_Accessibility_Admin::clear_featured( $images, 501 );
$ids = array_map( function ( $h ) { return $h['id']; }, $out );
ok( ! in_array( 10, $ids, true ), 'a page whose only miss was that featured image drops out' );
ok( in_array( 12, $ids, true ), 'a page sharing the image keeps its content images to fix' );
$p12 = array_values( array_filter( $out, function ( $h ) { return 12 === $h['id']; } ) )[0];
ok( 0 === $p12['featured'] && 3 === $p12['count'], 'its featured miss is cleared, its content count kept' );
$p11 = array_values( array_filter( $out, function ( $h ) { return 11 === $h['id']; } ) )[0];
ok( 1 === $p11['featured'], 'another page with a different featured image is untouched' );
ok( in_array( 13, $ids, true ), 'a page with only content images stays' );
ok( 3 === count( $out ), 'three pages left to fix' );

$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/optima-accessibility/includes/class-optima-accessibility-admin.php' );
ok( false !== strpos( $src, "'menu_add', 'alt' )" ), 'the alt form has its admin-post handler' );
ok( false !== strpos( $src, "current_user_can( 'edit_post', \$att )" ), 'saving needs the right to edit that image' );

exit( $fail ? 1 : 0 );
