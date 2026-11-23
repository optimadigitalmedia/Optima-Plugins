<?php
// Optima Build: the Outside plugins row on Site health (plugin-first rule 51). Every active
// plugin that is not an Optima plugin and not Gravity Forms is listed as outside the standard
// (a look, never a fix) unless the theme's optima-setup.json names it under approved_plugins.
// Runs without WordPress: php tools/tests/build-outside-plugins.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-plugins.php';
require dirname( __DIR__, 2 ) . '/optima-build/includes/class-optima-hub-setup.php';
$P = 'Optima_Hub_Plugins';

$plugins = array(
	'optima-core/optima-core.php'     => array( 'Name' => 'Optima Core', 'UpdateURI' => 'https://github.com/optimadigitalmedia/Optima-Plugins/optima-core', 'Author' => 'Optima Digital' ),
	'firm-companion/firm.php'         => array( 'Name' => 'Firm Companion', 'Author' => 'Optima Digital', 'AuthorURI' => 'https://optimadigitalmedia.com' ),
	'gravityforms/gravityforms.php'   => array( 'Name' => 'Gravity Forms', 'Author' => 'Gravity Forms' ),
	'gravityformsmailchimp/mc.php'    => array( 'Name' => 'Gravity Forms Mailchimp Add-On', 'Author' => 'Gravity Forms', 'AuthorURI' => 'https://www.gravityforms.com' ),
	'wp-rocket/wp-rocket.php'         => array( 'Name' => 'WP Rocket', 'Author' => 'WP Media' ),
	'hello.php'                       => array( 'Name' => 'Hello Dolly', 'Author' => 'Matt Mullenweg' ),
	'inactive-thing/inactive.php'     => array( 'Name' => 'Inactive Thing', 'Author' => 'Someone' ),
);
ok( 'optima' === $P::classify( 'optima-core/optima-core.php', $plugins['optima-core/optima-core.php'] ), 'an Optima plugin by its Update URI' );
ok( 'optima' === $P::classify( 'firm-companion/firm.php', $plugins['firm-companion/firm.php'] ), 'a client companion plugin by Optima counts as Optima' );
ok( 'gravity' === $P::classify( 'gravityforms/gravityforms.php', $plugins['gravityforms/gravityforms.php'] ), 'Gravity Forms is Gravity Forms' );
ok( 'gravity' === $P::classify( 'gravityformsmailchimp/mc.php', $plugins['gravityformsmailchimp/mc.php'] ), 'its official add-ons count as Gravity Forms' );
ok( 'outside' === $P::classify( 'wp-rocket/wp-rocket.php', $plugins['wp-rocket/wp-rocket.php'] ), 'anything else is outside' );
ok( 'hello' === $P::slug( 'hello.php' ) && 'wp-rocket' === $P::slug( 'wp-rocket/wp-rocket.php' ), 'slugs are folder names' );

$only = array( 'optima-core/optima-core.php', 'gravityforms/gravityforms.php', 'gravityformsmailchimp/mc.php', 'firm-companion/firm.php' );
$r    = $P::row( $plugins, $only, array() );
ok( 'good' === $r['state'] && 'None' === $r['value'], 'only Optima plugins and Gravity Forms: None, good' );

$active = array_merge( $only, array( 'wp-rocket/wp-rocket.php', 'hello.php' ) );
$r      = $P::row( $plugins, $active, array() );
ok( 'warn' === $r['state'], 'outside plugins with no approval are a look' );
ok( 'bad' !== $r['state'], 'never red' );
ok( '2 outside the standard' === $r['value'], 'the value counts them' );
ok( false !== strpos( $r['note'], 'WP Rocket' ) && false !== strpos( $r['note'], 'Hello Dolly' ) && false === strpos( $r['note'], 'Inactive Thing' ), 'the note names the active ones only' );
ok( false !== strpos( $r['note'], 'approved_plugins' ), 'the note says how to keep one' );

$approved = Optima_Hub_Setup::approved_plugins( array( 'approved_plugins' => array( 'wp-rocket', array( 'plugin' => 'hello', 'job' => 'demo', 'owner' => 'web team', 'decided' => '2026-11-01' ), array( 'job' => 'no plugin key' ) ) ) );
ok( array( 'wp-rocket', 'hello' ) === array_keys( $approved ) && '2026-11-01' === $approved['hello']['decided'], 'approved_plugins reads folder names and objects, and skips bad entries' );
$r = $P::row( $plugins, $active, $approved );
ok( 'good' === $r['state'] && '2 approved' === $r['value'], 'every outside plugin approved: good' );

$r = $P::row( $plugins, $active, array( 'wp-rocket' => array() ) );
ok( 'warn' === $r['state'] && in_array( 'WP Rocket: approved', $r['list'], true ) && in_array( 'Hello Dolly: outside the standard', $r['list'], true ), 'a mix lists each with its state' );
ok( array() === Optima_Hub_Setup::approved_plugins( array() ), 'no list: nothing approved' );

echo $fail ? "FAILED: $fail\n" : "All passed.\n";
exit( $fail ? 1 : 0 );
