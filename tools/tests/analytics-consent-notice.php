<?php
// Optima Analytics cookie notice (inventory INV-2611-10): its CSS dresses only from
// --optima-* variables with neutral system-color fallbacks (rule 7: no hard-coded
// color), and the script moves focus into the notice only when the visitor opens
// it, never on page load (WCAG 2.2).
//   php tools/tests/analytics-consent-notice.php
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/optima-analytics/includes/class-optima-analytics.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }

$css = ( new ReflectionMethod( 'Optima_Analytics', 'css' ) )->invoke( null );
$js  = ( new ReflectionMethod( 'Optima_Analytics', 'js' ) )->invoke( null );
ok( ! preg_match( '/#[0-9a-fA-F]{3,8}\b/', $css ), 'no hex colors in the notice CSS' );
ok( ! preg_match( '/rgba?\(|hsla?\(/', $css ), 'no rgb or hsl colors in the notice CSS' );
ok( false !== strpos( $css, 'var(--optima-surface' ) && false !== strpos( $css, 'var(--optima-ink' ) && false !== strpos( $css, 'var(--optima-accent' ), 'panel, text and button use the design tokens' );
ok( ! preg_match( '/border-radius:\d/', $css ), 'no hard-coded radius' );
ok( 1 === substr_count( $js, '.focus(' ) - substr_count( $js, 'back.focus(' ), 'one place moves focus into the notice' );
ok( (bool) preg_match( '/function show\(from\)\{[^}]*focus/', $js ), 'that place is show(), which only the Privacy choices link calls' );
ok( (bool) preg_match( '/if\(!has\(\)&&navigator\.globalPrivacyControl!==true&&box\)box\.hidden=false;/', $js ), 'on page load the notice only appears, without focus' );
ok( false !== strpos( $js, 'show(p)' ), 'the Privacy choices link opens it and is remembered to return focus' );

if ( $fail ) {
	echo "$fail failed\n";
	exit( 1 );
}
echo "All passed\n";
