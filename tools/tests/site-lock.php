<?php
// The site lock: on only when the site has an operator account. No WordPress needed:
//   php tools/tests/site-lock.php
// Loads the real access and lock classes with the few WordPress functions they use stubbed out.
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['t_users']   = array();
$GLOBALS['t_options'] = array();
$GLOBALS['t_trans']   = array();
$GLOBALS['t_queries'] = 0;
class WP_User {
	public $ID;
	public $user_email;
	public $caps;
	public function __construct( int $id, string $email, array $caps ) {
		$this->ID         = $id;
		$this->user_email = $email;
		$this->caps       = $caps;
	}
	public function exists() {
		return true;
	}
}
function __( $s, $d = '' ) { return $s; }
function add_filter() {}
function add_action() {}
function apply_filters( $h, $v ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['t_options'][ $k ] ?? $d; }
function get_transient( $k ) { return $GLOBALS['t_trans'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['t_trans'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t_trans'][ $k ] ); return true; }
function get_users( $args ) {
	$GLOBALS['t_queries']++;
	return array_values( array_filter( $GLOBALS['t_users'], static fn( $u ) => array_intersect( $args['capability__in'], $u->caps ) ) );
}
function user_can( $u, $cap ) { return in_array( $cap, $u->caps, true ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function wp_doing_cron() { return false; }
function wp_get_current_user() { return null; }
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-access.php';
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-lock.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
$admin  = array( 'manage_options', 'activate_plugins', 'edit_posts', 'publish_posts' );
$client = new WP_User( 2, 'owner@example-firm.com', $admin );
$locked = static fn() => Optima_Hub_Lock::caps( array_fill_keys( $client->caps, true ), array( 'edit_posts' ), array(), $client );

// No operator account on the site.
$GLOBALS['t_users'] = array( $client );
ok( 'waiting' === Optima_Hub_Lock::state(), 'no operator account: state is waiting' );
$caps = $locked();
ok( ! empty( $caps['edit_posts'] ) && ! empty( $caps['manage_options'] ), 'no operator account: a client admin keeps every capability' );
ok( ! empty( $caps[ Optima_Hub_Lock::VIEW ] ), 'no operator account: the client still opens Overview, Site health and Inbox' );
$before = $GLOBALS['t_queries'];
Optima_Hub_Lock::state();
ok( $before === $GLOBALS['t_queries'], 'the answer is kept in a transient (no second user query)' );

// An operator account is added: the user hook clears the transient.
$GLOBALS['t_users'][] = new WP_User( 1, 'person@optimadigitalmedia.com', $admin );
ok( 'waiting' === Optima_Hub_Lock::state(), 'before the user hook runs, the kept answer stands' );
Optima_Hub_Lock::forget();
ok( 'on' === Optima_Hub_Lock::state(), 'operator account added and the hook ran: state is on' );
$caps = $locked();
ok( empty( $caps['edit_posts'] ) && empty( $caps['publish_posts'] ) && empty( $caps['manage_options'] ) && empty( $caps['activate_plugins'] ), 'operator on the site: a client admin cannot edit, publish, change settings or plugins' );
ok( ! empty( $caps[ Optima_Hub_Lock::VIEW ] ), 'operator on the site: the client still opens Overview, Site health and Inbox' );
$op   = $GLOBALS['t_users'][1];
$caps = Optima_Hub_Lock::caps( array_fill_keys( $op->caps, true ), array( 'edit_posts' ), array(), $op );
ok( ! empty( $caps['edit_posts'] ) && ! empty( $caps['manage_options'] ), 'the operator keeps every capability' );

// Switched off.
$GLOBALS['t_options'][ Optima_Hub_Lock::OPTION ] = '0';
ok( 'off' === Optima_Hub_Lock::state(), 'switched off: state is off' );
$caps = $locked();
ok( ! empty( $caps['edit_posts'] ), 'switched off: the client edits again' );

echo $fail ? "FAILED: $fail\n" : "All passed.\n";
exit( $fail ? 1 : 0 );
