<?php
// Optima Optimize ships visitor-data.json saying it collects nothing about
// visitors, and its code sets no cookie and stores nothing in the browser.
//   php tools/tests/optimize-visitor-data.php
$root = dirname( __DIR__, 2 );
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }

$data = json_decode( (string) @file_get_contents( "$root/optima-optimize/visitor-data.json" ), true );
ok( is_array( $data ) && 'optima-optimize' === ( $data['plugin'] ?? '' ), 'optima-optimize ships visitor-data.json for itself' );
ok( array() === ( $data['items'] ?? null ) && array() === ( $data['cookies'] ?? null ), 'it lists no items and no cookies' );
$code = '';
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/optima-optimize", FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( preg_match( '/\.(php|js)$/', $f->getFilename() ) ) {
		$code .= (string) file_get_contents( $f->getPathname() );
	}
}
ok( ! preg_match( '/\bsetcookie\s*\(|document\.cookie|localStorage|sessionStorage/', $code ), 'no cookie or browser storage in the code' );

if ( $fail ) {
	echo "$fail failed\n";
	exit( 1 );
}
echo "All passed\n";
