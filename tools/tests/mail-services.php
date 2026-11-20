<?php
// Optima Email sends through Google or a sending service (Optima Build 0.7.11.4).
// Checks the route each setting gives PHPMailer: host, port, user name, the
// From kept on the saved address, the form's own From moved to Reply-To, and
// that a site saved before services existed keeps sending through Google.
// No WordPress needed:
//   php tools/tests/mail-services.php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $k ] : $d; }
function apply_filters( $tag, $v ) { return $v; }
function add_action() {}
function add_filter() {}
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function wp_salt() { return 'salt'; }
function get_bloginfo() { return 'Test Site'; }
function wp_specialchars_decode( $s ) { return $s; }
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
$enc = ( new ReflectionMethod( 'Optima_Hub_Mail', 'encrypt' ) );
function route( array $opt ) {
	$GLOBALS['opt']['optima_mail'] = $opt;
	$m = new Fake_Mailer();
	Optima_Hub_Mail::mailer( $m );
	return $m;
}

// A site saved before services existed: no 'service' key.
$m = route( array( 'email' => 'site@gmail.com', 'password' => $enc->invoke( null, 'apppassword' ) ) );
ok( 'smtp.gmail.com' === $m->Host && 'site@gmail.com' === $m->Username && 587 === $m->Port, 'old settings keep sending through Google' );
ok( 'site@gmail.com' === $m->From && isset( $m->reply['owner@client.example'] ), 'From stays the saved address; the form\'s From becomes Reply-To' );

$m = route( array( 'service' => 'resend', 'email' => 'hello@example.com', 'password' => $enc->invoke( null, 're_key' ) ) );
ok( 'smtp.resend.com' === $m->Host && 'resend' === $m->Username && 're_key' === $m->Password && 'tls' === $m->SMTPSecure, 'Resend: its host, user "resend", the key as password' );
ok( 'hello@example.com' === $m->From && 'hello@example.com' === $m->Sender, 'Resend: sends as the verified-domain address' );

$m = route( array( 'service' => 'postmark', 'email' => 'hello@example.com', 'password' => $enc->invoke( null, 'pm-token' ) ) );
ok( 'smtp.postmarkapp.com' === $m->Host && 'pm-token' === $m->Username && 'pm-token' === $m->Password, 'Postmark: the token is the user name and the password' );

$m = route( array( 'service' => 'ses', 'email' => 'hello@example.com', 'password' => $enc->invoke( null, 'secret' ), 'user' => 'AKIAEXAMPLE', 'host' => 'email-smtp.us-east-1.amazonaws.com', 'port' => 465 ) );
ok( 'email-smtp.us-east-1.amazonaws.com' === $m->Host && 'AKIAEXAMPLE' === $m->Username && 465 === $m->Port && 'ssl' === $m->SMTPSecure, 'SES: typed host and user, port 465 uses SSL' );

$GLOBALS['opt']['optima_mail'] = array( 'service' => 'ses', 'email' => 'hello@example.com', 'password' => $enc->invoke( null, 'secret' ), 'user' => 'AKIAEXAMPLE' );
ok( ! Optima_Hub_Mail::ready(), 'SES with no server typed is not ready, so the host keeps sending' );
$GLOBALS['opt']['optima_mail'] = array( 'service' => 'resend', 'email' => 'not an address', 'password' => $enc->invoke( null, 're_key' ) );
ok( ! Optima_Hub_Mail::ready(), 'a bad send-from address is not ready' );
$GLOBALS['opt']['optima_mail'] = array( 'service' => 'made-up', 'email' => 'site@gmail.com', 'password' => $enc->invoke( null, 'x' ) );
ok( Optima_Hub_Mail::is_google(), 'an unknown service falls back to Google' );

$m = route( array( 'service' => 'resend', 'email' => 'hello@example.com', 'password' => '' ) );
ok( null === $m->Host, 'no key saved: WordPress mail is left alone' );

echo $fail ? "$fail failed\n" : "All mail route checks passed\n";
exit( $fail ? 1 : 0 );
