<?php
// Optima Email sends through Cloudflare (the default), Google or another SMTP
// service. Checks the route each setting gives PHPMailer (host, port, TLS mode,
// user name), that a site saved before routes existed keeps sending through
// Google, that switching route clears the saved secret, the From-domain check,
// and the plain-words fixes for Cloudflare's errors. No WordPress needed:
//   php tools/tests/mail-services.php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $k ] : $d; }
function apply_filters( $tag, $v ) { return $v; }
function add_action() {}
function add_filter() {}
function __( $s ) { return $s; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function wp_salt() { return 'salt'; }
function get_bloginfo() { return 'Test Site'; }
function wp_specialchars_decode( $s ) { return $s; }
function home_url() { return 'https://www.example.com/'; }
function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-mail.php';

$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }

class Fake_Mailer {
	public $From = 'owner@client.example'; public $FromName = 'Owner'; public $Host; public $Port; public $SMTPSecure; public $SMTPAuth; public $Username; public $Password; public $Sender; public $reply = array();
	public function isSMTP() {}
	public function getReplyToAddresses() { return $this->reply; }
	public function addReplyTo( $a, $n = '' ) { $this->reply[ $a ] = $n; }
	public function setFrom( $a, $n = '', $auto = true ) { $this->From = $a; $this->FromName = $n; }
}
$enc     = new ReflectionMethod( 'Optima_Hub_Mail', 'encrypt' );
$explain = new ReflectionMethod( 'Optima_Hub_Mail', 'explain' );
function route( array $opt ) {
	$GLOBALS['opt']['optima_mail'] = $opt;
	$m = new Fake_Mailer();
	Optima_Hub_Mail::mailer( $m );
	return $m;
}

// 1. Cloudflare.
$m = route( array( 'service' => 'cloudflare', 'email' => 'forms@example.com', 'password' => $enc->invoke( null, 'cf-token' ) ) );
ok( 'smtp.mx.cloudflare.net' === $m->Host && 465 === $m->Port && 'ssl' === $m->SMTPSecure, 'Cloudflare: smtp.mx.cloudflare.net, port 465, implicit TLS' );
ok( 'api_token' === $m->Username && 'cf-token' === $m->Password && true === $m->SMTPAuth, 'Cloudflare: user name api_token, the token as password' );
ok( 'forms@example.com' === $m->From && 'forms@example.com' === $m->Sender && isset( $m->reply['owner@client.example'] ), 'Cloudflare: sends as the saved address; the form\'s From becomes Reply-To' );

// 2. Defaults.
$GLOBALS['opt']['optima_mail'] = array();
ok( 'cloudflare' === Optima_Hub_Mail::service(), 'a new setup starts on Cloudflare' );
$m = route( array( 'email' => 'site@gmail.com', 'password' => $enc->invoke( null, 'apppassword' ) ) );
ok( 'smtp.gmail.com' === $m->Host && 587 === $m->Port && 'tls' === $m->SMTPSecure && 'site@gmail.com' === $m->Username, 'old settings (no service key) keep sending through Google' );
ok( 'Google' === Optima_Hub_Mail::route_name(), 'old settings are named Google' );
$GLOBALS['opt']['optima_mail'] = array( 'service' => 'made-up', 'email' => 'site@gmail.com', 'password' => $enc->invoke( null, 'x' ) );
ok( 'google' === Optima_Hub_Mail::service(), 'an unknown route falls back to Google' );

// 3. Other service.
$m = route( array( 'service' => 'other', 'email' => 'hello@example.com', 'password' => $enc->invoke( null, 'key' ), 'user' => 'smtpuser', 'host' => 'smtp.relay.example', 'port' => 587 ) );
ok( 'smtp.relay.example' === $m->Host && 'smtpuser' === $m->Username && 587 === $m->Port && 'tls' === $m->SMTPSecure, 'other: typed server and user, 587 STARTTLS' );
$GLOBALS['opt']['optima_mail'] = array( 'service' => 'other', 'email' => 'hello@example.com', 'password' => $enc->invoke( null, 'key' ), 'user' => 'smtpuser' );
ok( ! Optima_Hub_Mail::ready(), 'other with no server typed is not ready, so the host keeps sending' );

// 4. Not ready leaves WordPress alone.
$m = route( array( 'service' => 'cloudflare', 'email' => 'forms@example.com', 'password' => '' ) );
ok( null === $m->Host, 'no token saved: WordPress mail is left alone' );
$GLOBALS['opt']['optima_mail'] = array( 'service' => 'cloudflare', 'email' => 'not an address', 'password' => $enc->invoke( null, 'cf-token' ) );
ok( ! Optima_Hub_Mail::ready(), 'a bad send-from address is not ready' );

// 5. Switching route clears the saved secret.
$saved = array( 'service' => 'google', 'email' => 'site@gmail.com', 'password' => $enc->invoke( null, 'apppassword' ), 'last' => array( 'ok' => true ), 'host' => 'x', 'user' => 'y', 'port' => 587 );
$new   = Optima_Hub_Mail::with_service( $saved, 'cloudflare' );
ok( 'cloudflare' === $new['service'] && '' === $new['password'] && array() === $new['last'], 'switching Google to Cloudflare clears the app password and the last test' );
ok( '' === $new['host'] && '' === $new['user'] && 0 === $new['port'] && 'site@gmail.com' === $new['email'], 'switching clears the server fields and keeps the address' );
ok( Optima_Hub_Mail::with_service( $saved, 'google' ) === $saved, 'the same route keeps the secret' );
ok( Optima_Hub_Mail::with_service( $saved, 'made-up' ) === $saved, 'an unknown route changes nothing' );

// 6. From-domain check.
ok( Optima_Hub_Mail::from_on_site_domain( 'forms@example.com' ), 'forms@example.com is on www.example.com' );
ok( Optima_Hub_Mail::from_on_site_domain( 'forms@send.example.com' ), 'a subdomain address is on the site domain' );
ok( ! Optima_Hub_Mail::from_on_site_domain( 'someone@gmail.com' ), 'a Gmail address is not on the site domain' );
ok( ! Optima_Hub_Mail::from_on_site_domain( 'forms@example.com', 'https://staging.wpengine.com/' ), 'a staging host is a different domain' );
ok( Optima_Hub_Mail::from_on_site_domain( 'forms@firm.co.uk', 'https://www.firm.co.uk/' ) && ! Optima_Hub_Mail::from_on_site_domain( 'forms@other.co.uk', 'https://www.firm.co.uk/' ), 'two-part endings like co.uk compare the name before them' );
ok( ! Optima_Hub_Mail::from_on_site_domain( 'no-at-sign' ), 'an address with no @ fails' );

// 7. Cloudflare error hints.
$h = $explain->invoke( null, 'SMTP Error: Could not authenticate. 535 5.7.8 Authentication failed', 'cloudflare' );
ok( false !== strpos( $h, 'Email Sending: Edit' ), '535 on Cloudflare: token wrong or missing Email Sending: Edit' );
$h = $explain->invoke( null, 'SMTP Error: data not accepted. 550 5.7.1 Sender denied', 'cloudflare' );
ok( false !== strpos( $h, 'Email Sending' ) && false !== strpos( $h, 'domain is not added' ), '550 5.7.1 on Cloudflare: the From domain is not added in Email Sending' );
$h = $explain->invoke( null, 'SMTP Error: Could not authenticate.', 'google' );
ok( false !== strpos( $h, 'app password' ), 'auth failure on Google still points at the app password' );

// 8. The Build card's words.
ok( false !== strpos( Optima_Hub_Mail::not_set_up_text(), 'Connect Cloudflare email' ), 'not set up: the card asks to connect Cloudflare email' );
$GLOBALS['opt']['optima_mail'] = array( 'service' => 'cloudflare', 'email' => 'forms@example.com', 'password' => $enc->invoke( null, 'cf-token' ) );
ok( 'Sending through Cloudflare as forms@example.com.' === Optima_Hub_Mail::summary(), 'set up: the card names the route and the address' );

echo $fail ? "$fail failed\n" : "All mail route checks passed\n";
exit( $fail ? 1 : 0 );
