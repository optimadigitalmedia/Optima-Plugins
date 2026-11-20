<?php
// Optima Partners click counting (inventory INV-2611-10): a visitor counts once a
// day per partner (a salted hash of IP and browser, never stored as is), the
// count is added in one SQL UPDATE, a new month starts its own row, old counts
// stored as one array still show, and only 24 months are kept.
//   php tools/tests/partners-clicks.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['meta']  = array(); // post_id => key => value
$GLOBALS['trans'] = array();
$GLOBALS['sql']   = array();
$GLOBALS['month'] = '2026-11';
function add_action( ...$a ) {}
function wp_date( $f ) { return 'Y-m' === $f ? $GLOBALS['month'] : $GLOBALS['month'] . '-20'; }
function wp_salt( $s = '' ) { return 'salt'; }
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['trans'][ $k ] = $v; return true; }
function wp_cache_delete( ...$a ) { return true; }
function get_post_meta( $id, $key = '', $single = false ) {
	$m = $GLOBALS['meta'][ $id ] ?? array();
	if ( '' === $key ) {
		return array_map( static fn( $v ) => array( $v ), $m );
	}
	return array_key_exists( $key, $m ) ? $m[ $key ] : ( $single ? '' : array() );
}
function add_post_meta( $id, $key, $value, $unique = false ) {
	if ( $unique && isset( $GLOBALS['meta'][ $id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['meta'][ $id ][ $key ] = $value;
	return 1;
}
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
final class Fake_DB {
	public $postmeta = 'wp_postmeta';
	public function prepare( $q, ...$a ) { return array( $q, $a ); }
	public function query( $p ) {
		list( $q, $a ) = $p;
		$GLOBALS['sql'][] = $q;
		if ( 0 === strpos( $q, 'UPDATE' ) && preg_match( '/meta_value = meta_value \+ 1/', $q ) ) {
			list( $id, $key ) = $a;
			if ( ! isset( $GLOBALS['meta'][ $id ][ $key ] ) ) {
				return 0;
			}
			$GLOBALS['meta'][ $id ][ $key ] = (int) $GLOBALS['meta'][ $id ][ $key ] + 1;
			return 1;
		}
		return 0;
	}
}
$GLOBALS['wpdb'] = new Fake_DB();
require dirname( __DIR__, 2 ) . '/optima-partners/includes/class-optima-partners.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
$P = 'Optima_Partners';

$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
$P::count_click( 7 );
ok( 1 === $P::clicks( 7 )['2026-11'], 'first click counts' );
$P::count_click( 7 );
$P::count_click( 7 );
ok( 1 === $P::clicks( 7 )['2026-11'], 'the same visitor again the same day does not' );
$P::count_click( 8 );
ok( 1 === ( $P::clicks( 8 )['2026-11'] ?? 0 ), 'the same visitor on another partner counts' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$P::count_click( 7 );
ok( 2 === $P::clicks( 7 )['2026-11'], 'another visitor counts' );
ok( (bool) preg_grep( '/^UPDATE wp_postmeta SET meta_value = meta_value \+ 1 WHERE/', $GLOBALS['sql'] ), 'the count is added in one SQL UPDATE' );
$stored = json_encode( $GLOBALS['trans'] );
ok( false === strpos( $stored, '203.0.113' ) && false === strpos( $stored, 'Mozilla' ), 'no IP address or browser stored as is' );

// Counts from before this release (one array) still show, added to the new rows.
$GLOBALS['meta'][7]['_optima_partner_clicks'] = array( '2026-10' => 5, '2026-11' => 3 );
$c = $P::clicks( 7 );
ok( 5 === $c['2026-10'] && 5 === $c['2026-11'], 'old array counts are added in' );
ok( array_keys( $c ) === array( '2026-11', '2026-10' ), 'newest month first' );

// A new month starts its own row and only 24 months stay.
for ( $y = 2024; $y <= 2026; $y++ ) {
	for ( $m = 1; $m <= 12; $m++ ) {
		if ( 2026 !== $y || $m < 12 ) {
			$GLOBALS['meta'][9][ sprintf( '_optima_partner_clicks_%d-%02d', $y, $m ) ] = 1;
		}
	}
}
$GLOBALS['month']           = '2026-12';
$_SERVER['REMOTE_ADDR']     = '198.51.100.1';
$P::count_click( 9 );
$keys = array_filter( array_keys( $GLOBALS['meta'][9] ), static fn( $k ) => 0 === strpos( $k, '_optima_partner_clicks_' ) );
ok( 24 === count( $keys ), 'only 24 months of counts are kept (' . count( $keys ) . ')' );
ok( isset( $GLOBALS['meta'][9]['_optima_partner_clicks_2026-12'] ) && ! isset( $GLOBALS['meta'][9]['_optima_partner_clicks_2024-12'] ), 'the oldest month goes, the new one stays' );

if ( $fail ) {
	echo "$fail failed\n";
	exit( 1 );
}
echo "All passed\n";
