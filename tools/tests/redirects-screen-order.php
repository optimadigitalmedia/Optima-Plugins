<?php
// Optima Redirects: the screen opens on what needs doing (plugin-first rule 52).
// Broken links, then missing pages, then the redirect list and its form; the
// form stays on top while a redirect is being changed. No WordPress needed:
//   php tools/tests/redirects-screen-order.php
define( 'ABSPATH', __DIR__ . '/' );
function add_action() {}
function add_filter() {}
require dirname( __DIR__, 2 ) . '/optima-redirects/includes/class-optima-redirects-admin.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$A = 'Optima_Redirects_Admin';
ok( array( 'broken', 'missing', 'list' ) === $A::box_order( false, true, true ), 'broken links first, then missing pages, then the list' );
ok( array( 'missing', 'list', 'broken' ) === $A::box_order( false, false, true ), 'with no broken links, missing pages come first and the empty broken box waits below' );
ok( array( 'list', 'missing', 'broken' ) === $A::box_order( false, false, false ), 'with nothing to do, the list comes first' );
ok( array( 'broken', 'list', 'missing' ) === $A::box_order( false, true, false ), 'an empty missing list waits below the list' );
ok( 'list' === $A::box_order( true, true, true )[0], 'while a redirect is being changed, the form stays on top' );
ok( 3 === count( array_unique( $A::box_order( false, true, false ) ) ), 'every box shows exactly once' );

// The screen source wires it up: the status line turns gold only for broken links.
$src = file_get_contents( dirname( __DIR__, 2 ) . '/optima-redirects/includes/class-optima-redirects-admin.php' );
ok( false !== strpos( $src, "echo \$broken ? 'is-warn' : '';" ), 'the status line turns gold only when a link on the site is broken' );
ok( false !== strpos( $src, 'self::box_order(' ), 'the screen prints its boxes in box_order()' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
