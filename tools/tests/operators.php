<?php
// Who is an operator (Optima Build sees Build, turn-on buttons and updates). No WordPress needed:
//   php tools/tests/operators.php            the default rule: administrators on the agency domain
//   OPERATORS='a@example.com, @example.org' php tools/tests/operators.php   a site's own list
define( 'ABSPATH', __DIR__ . '/' );
if ( false !== getenv( 'OPERATORS' ) ) {
	define( 'OPTIMA_HUB_OPERATORS', getenv( 'OPERATORS' ) );
}
class WP_User {
	public $user_email;
	public $caps;
	public function __construct( string $email, array $caps ) {
		$this->user_email = $email;
		$this->caps       = $caps;
	}
	public function exists() {
		return true;
	}
}
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function user_can( $u, $cap ) { return in_array( $cap, $u->caps, true ); }
function wp_get_current_user() { return null; }
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-access.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$admin  = array( 'manage_options', 'activate_plugins' );
$editor = array( 'edit_posts' );
$op     = static fn( $email, $caps ) => Optima_Hub_Access::is_operator( new WP_User( $email, $caps ) );

if ( defined( 'OPTIMA_HUB_OPERATORS' ) ) {
	ok( $op( 'a@example.com', $admin ), 'listed address, administrator: operator' );
	ok( ! $op( 'a@example.com', array( 'manage_options' ) ), 'listed address without plugin rights: not an operator' );
	ok( $op( 'anyone@example.org', array( 'manage_options' ) ), 'listed @domain, can manage settings: operator' );
	ok( ! $op( 'person@optimadigitalmedia.com', $admin ), 'agency domain not on this site\'s list: not an operator' );
} else {
	ok( array( '@optimadigitalmedia.com' ) === Optima_Hub_Access::operators(), 'default list is the agency domain only' );
	ok( $op( 'person@optimadigitalmedia.com', $admin ), 'agency-domain administrator: operator' );
	ok( $op( 'Person@OptimaDigitalMedia.com', $admin ), 'letter case does not matter' );
	ok( ! $op( 'person@example-firm.com', $admin ), 'administrator on another domain: not an operator' );
	ok( ! $op( 'person@optimadigitalmedia.com', $editor ), 'agency-domain user who cannot manage settings: not an operator' );
	ok( ! $op( 'person@sub.optimadigitalmedia.com', $admin ), 'a subdomain address is not the agency domain' );
	ok( ! $op( 'person@not-optimadigitalmedia.com', $admin ), 'a look-alike domain is not the agency domain' );
}
echo $fail ? "FAILED: $fail\n" : "All passed.\n";
exit( $fail ? 1 : 0 );
