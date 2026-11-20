<?php
// Optima Core 1.15.0.0: the staging guard (rule 19: a staging copy never emails
// clients or calls outside services). No WordPress needed:
//   php tools/tests/core-staging-guard.php
// Loads the real operators and staging classes with the WordPress functions they use stubbed out.
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['t_opt']   = array( 'admin_email' => 'Office@Example-Firm.test' );
$GLOBALS['t_env']   = 'production';
$GLOBALS['t_home']  = 'https://example-firm.test';
$GLOBALS['t_extra'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_opt'] ) ? $GLOBALS['t_opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_opt'][ $k ] = $v; return true; }
function apply_filters( $tag, $value ) { return 'optima_core_staging_mail_to' === $tag ? array_merge( (array) $value, $GLOBALS['t_extra'] ) : $value; }
function add_filter() {}
function add_action() {}
function wp_get_environment_type() { return $GLOBALS['t_env']; }
function home_url( $p = '' ) { return $GLOBALS['t_home'] . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
class WP_Error { public $code; public function __construct( $c = '', $m = '' ) { $this->code = $c; } }

$root = dirname( __DIR__, 2 );
require $root . '/optima-core/includes/operators.php';
require $root . '/optima-core/includes/staging-guard.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$G = 'Optima_Core_Staging';

// 1. Core's fallback operator list is Build's: same default domain, same partner hash.
$build = (string) file_get_contents( $root . '/optima-build/includes/class-optima-hub-access.php' );
preg_match( "/const DEFAULT_OPERATORS\s*=\s*'([^']*)'/", $build, $d );
preg_match( '/const DEFAULT_OPERATOR_DOMAIN_HASHES\s*=\s*array\(([^)]*)\)/', $build, $h );
preg_match_all( "/'([0-9a-f]{64})'/", $h[1] ?? '', $hashes );
ok( ( $d[1] ?? '' ) === Optima_Core_Operators::DEFAULT_OPERATORS, 'fallback default operator domain equals Optima Build\'s' );
ok( $hashes[1] === Optima_Core_Operators::DEFAULT_OPERATOR_DOMAIN_HASHES, 'fallback partner-domain hashes equal Optima Build\'s' );
preg_match_all( '/@[a-z0-9-]+(\.[a-z0-9-]+)+/i', (string) file_get_contents( $root . '/optima-core/includes/operators.php' ), $named );
ok( array( '@optimadigitalmedia.com' ) === array_values( array_unique( array_map( 'strtolower', $named[0] ) ) ), 'operators.php names only the agency domain: the partner domain is a hash' );

// 2. When is a copy staging?
ok( ! $G::is_staging(), 'a live address in production is not staging' );
ok( $G::is_staging( 'production', 'examplestage.wpenginepowered.com' ), 'a WP Engine staging address is staging' );
ok( $G::is_staging( 'production', 'EXAMPLE.Kinsta.Cloud' ), 'a staging suffix matches in any letter case' );
ok( $G::is_staging( 'staging', 'example-firm.test' ), 'environment type staging is staging' );
ok( $G::is_staging( 'local', 'example-firm.test' ), 'environment type local is staging' );
ok( ! $G::is_staging( 'production', 'wpenginepowered.com.example-firm.test' ), 'a live domain that only contains a staging suffix is live' );

// 3. Who may get mail from a staging copy (Build not loaded: Core's fallback).
ok( $G::allowed( 'someone@optimadigitalmedia.com' ), 'an agency-domain address gets mail' );
ok( $G::allowed( 'Web Team <SOMEONE@OptimaDigitalMedia.com>' ), 'a named address with any letter case gets mail' );
ok( $G::allowed( 'office@example-firm.test' ), 'the site\'s admin email gets mail' );
ok( ! $G::allowed( 'client@gmail.test' ), 'a client address is dropped' );
ok( ! $G::allowed( 'someone@optimadigitalmedia.com.evil.test' ), 'a lookalike domain is dropped' );
ok( ! $G::allowed( 'x@partner.example' ), 'an unknown domain is dropped' );
$GLOBALS['t_extra'] = array( 'qa.example', 'tester@other.test' );
ok( $G::allowed( 'a@qa.example' ) && $G::allowed( 'tester@other.test' ) && ! $G::allowed( 'b@other.test' ), 'the mail_to filter adds a domain or one address' );
$GLOBALS['t_extra'] = array();

// 4. The mail itself.
$GLOBALS['t_opt']['optima_core_staging_held'] = array();
$args = $G::mail_args(
	array(
		'to'      => 'client@gmail.test, someone@optimadigitalmedia.com',
		'headers' => "From: Site <site@example-firm.test>\nCc: other@gmail.test, office@example-firm.test\nBcc: hidden@gmail.test",
	)
);
ok( array( 'someone@optimadigitalmedia.com' ) === $args['to'], 'To keeps only allowed recipients' );
ok( in_array( 'Cc: office@example-firm.test', $args['headers'], true ), 'Cc keeps only allowed recipients' );
ok( ! preg_grep( '/^bcc/i', $args['headers'] ), 'a Bcc with nobody allowed is dropped' );
ok( in_array( 'From: Site <site@example-firm.test>', $args['headers'], true ), 'other headers stay' );
ok( 1 === (int) ( $GLOBALS['t_opt']['optima_core_staging_held']['mail'] ?? 0 ), 'a held message is counted' );
$none = $G::mail_args( array( 'to' => array( 'client@gmail.test' ) ) );
ok( array() === $none['to'] && false === $G::stop_empty_mail( null, $none ), 'with nobody left, the message is not sent' );
ok( null === $G::stop_empty_mail( null, $args ), 'with someone left, the message goes' );
ok( 'keep' === $G::stop_empty_mail( 'keep', $none ), 'another plugin\'s answer is left alone' );

// 5. Outside services.
ok( $G::stop_calls( false, array(), 'https://api.stripe.com/v1/charges' ) instanceof WP_Error, 'a payment call is refused' );
ok( $G::stop_calls( false, array(), 'https://hooks.zapier.com/hooks/catch/1/x' ) instanceof WP_Error, 'a Zapier call is refused' );
ok( $G::stop_calls( false, array(), 'https://us21.api.mailchimp.com/3.0/lists' ) instanceof WP_Error, 'a newsletter call on a subdomain is refused' );
ok( false === $G::stop_calls( false, array(), 'https://api.github.com/repos/x' ), 'other calls (updates) go through' );
ok( false === $G::stop_calls( false, array(), 'https://notstripe.com/api.stripe.com' ), 'a host that only contains a blocked name goes through' );

// 6. With Optima Build running, its operator check is the source.
if ( true ) {
	class WP_User {
		public $user_email;
		public function __construct( $e ) { $this->user_email = $e; }
		public function exists() { return true; }
	}
	final class Optima_Hub_Access {
		const DEFAULT_OPERATOR_DOMAIN_HASHES = array( 'e39f3e0fe84badb5272709c4811df71964e821456fcbca3fbdbf554a8d98a507' ); // sha256 of "@partner.example".
		public static function operators(): array { return array( '@agency.example', 'one@person.example' ); }
		public static function is_operator( $user = null ): bool { return $user && '@agency.example' === substr( $user->user_email, -15 ); }
	}
}
ok( $G::allowed( 'x@partner.example' ), 'with Build: an address on Build\'s hashed partner domain gets mail' );
ok( $G::allowed( 'a@agency.example' ) && $G::allowed( 'one@person.example' ), 'with Build: Build\'s domains and exact addresses get mail' );
ok( ! $G::allowed( 'someone@optimadigitalmedia.com' ), 'with Build: only Build\'s list counts (a site that narrowed it stays narrowed)' );
ok( $G::allowed( 'office@example-firm.test' ), 'with Build: the admin email still gets mail' );
ok( Optima_Core_Operators::is_operator( new WP_User( 'me@agency.example' ) ) && ! Optima_Core_Operators::is_operator( new WP_User( 'me@gmail.test' ) ), 'with Build: signed-in operators come from Build\'s check' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
