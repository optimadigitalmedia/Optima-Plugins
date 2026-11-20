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
$GLOBALS['t_allow'] = array();
$GLOBALS['t_trans'] = array();
$GLOBALS['t_logged'] = array();
define( 'HOUR_IN_SECONDS', 3600 );
ini_set( 'error_log', '/dev/null' );
function get_transient( $k ) { return $GLOBALS['t_trans'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t_trans'][ $k ] = $v; $GLOBALS['t_logged'][] = $k; return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_opt'] ) ? $GLOBALS['t_opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_opt'][ $k ] = $v; return true; }
function apply_filters( $tag, $value, ...$rest ) {
	if ( 'optima_core_staging_mail_to' === $tag ) {
		return array_merge( (array) $value, $GLOBALS['t_extra'] );
	}
	if ( 'optima_core_staging_allow_hosts' === $tag ) {
		return array_merge( (array) $value, $GLOBALS['t_allow'] );
	}
	return $value;
}
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

// 5b. Calls that would create real records (INV-2611-01).
$refused = static function ( $url, $args = array() ) use ( $G ) {
	return $G::stop_calls( false, $args, $url ) instanceof WP_Error;
};
ok( $refused( 'https://example.app.n8n.cloud/webhook/abc' ) && $refused( 'https://n8n.cloud/x' ), 'n8n cloud and its subdomains are refused' );
ok( $refused( 'https://hook.us1.make.com/abc' ) && $refused( 'https://hook.eu2.make.com/abc' ), 'Make webhooks in any region are refused' );
ok( ! $refused( 'https://www.make.com/en' ), 'the Make website itself goes through' );
ok( $refused( 'https://api.lawmatics.com/v1/contacts' ), 'the Lawmatics intake API is refused' );
ok( $refused( 'https://app.clio.com/api/v4/contacts' ) && $refused( 'https://eu.app.clio.com/api/v4' ), 'Clio and its regional hosts are refused' );
ok( $refused( 'https://api.calltrackingmetrics.com/api/v1/accounts' ), 'call tracking is refused' );
ok( $refused( 'https://hooks.slack.com/services/T/B/x' ), 'Slack webhooks are refused' );
ok( $refused( 'https://api.hubspot.com/crm/v3/objects/contacts' ), 'the HubSpot API is refused' );
ok( $refused( 'https://services.leadconnectorhq.com/contacts/' ), 'LeadConnector is refused' );
ok( $refused( 'https://us-central1-example.cloudfunctions.net/receiver' ), 'a Cloud Functions receiver is refused' );
ok( $refused( 'https://receiver-abc123-ue.a.run.app/collect' ), 'a Cloud Run receiver is refused' );
ok( ! $refused( 'https://run.app.example-firm.test/x' ) && ! $refused( 'https://cloudfunctions.net.example.test/' ), 'a host that only starts with a receiver suffix goes through' );
ok( ! $refused( 'https://api.wordpress.org/plugins/update-check/1.1/' ) && ! $refused( 'https://api.github.com/repos/x' ), 'updates still go through' );
ok( ! $refused( 'https://www.google.com/recaptcha/api/siteverify' ), 'captcha checks still go through' );

// 5c. Gravity Forms webhook feeds: refused whatever the host.
$marked = $G::mark_webhook( array( 'method' => 'POST' ) );
ok( true === $marked[ $G::WEBHOOK_ARG ] && 'POST' === $marked['method'], 'a webhook feed request is marked and its args kept' );
ok( $refused( 'https://intake.example-crm.test/new-lead', $marked ), 'a webhook feed to an unlisted host is refused' );
ok( ! $refused( 'https://intake.example-crm.test/new-lead', array( 'method' => 'POST' ) ), 'the same host without the webhook mark goes through' );
ok( 'keep' === $G::stop_calls( 'keep', $marked, 'https://intake.example-crm.test/' ), 'another plugin\'s answer is left alone' );

// 5d. The allow-list re-opens hosts.
$GLOBALS['t_allow'] = array( '*.run.app', 'hooks.slack.com', 'intake.example-crm.test' );
ok( ! $refused( 'https://receiver-abc123-ue.a.run.app/collect' ), 'an allowed pattern re-opens a blocked receiver' );
ok( ! $refused( 'https://hooks.slack.com/services/T/B/x' ), 'an allowed host re-opens a blocked host' );
ok( ! $refused( 'https://intake.example-crm.test/new-lead', $marked ), 'an allowed host re-opens a webhook feed' );
ok( $refused( 'https://us-central1-example.cloudfunctions.net/receiver' ) && $refused( 'https://api.stripe.com/v1/charges' ), 'hosts not on the allow-list stay refused' );
$GLOBALS['t_allow'] = array();

// 5e. Log each refused host once an hour.
$GLOBALS['t_trans']  = array();
$GLOBALS['t_logged'] = array();
$refused( 'https://hooks.slack.com/a' );
$refused( 'https://hooks.slack.com/b' );
$refused( 'https://api.lawmatics.com/c' );
ok( 2 === count( $GLOBALS['t_logged'] ), 'each refused host is logged once while its hour runs' );
$GLOBALS['t_trans'] = array();
$refused( 'https://hooks.slack.com/d' );
ok( 3 === count( $GLOBALS['t_logged'] ), 'after the hour the host is logged again' );

// 5f. Host matching.
ok( $G::host_matches( 'HOOK.US1.MAKE.COM', 'hook.*.make.com' ), 'matching ignores letter case' );
ok( ! $G::host_matches( 'hook.make.com', 'hook.*.make.com' ), 'the star stands for at least one label' );
ok( ! $G::host_matches( 'evilclio.com', 'clio.com' ), 'a host that only ends in the same letters goes through' );

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
