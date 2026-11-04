<?php
// Admin kit grouping checks: every Optima screen lands in the group the Site Standard §7
// map says (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).
// A test so the grouping never drifts from the map again. Runs without WordPress:
//   php tools/tests/admin-kit-groups.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
require __DIR__ . '/../admin-kit/class-optima-admin-ui.php';

$map = array(
	// slug => [label, expected group]
	'site-build-features'                     => array( 'Overview', 'overview' ),
	'site-build'                              => array( 'Overview', 'overview' ),
	'site-build-features&tab=features'        => array( 'Features', 'build' ),
	'site-build&tab=features'                 => array( 'Features', 'build' ),
	'site-build-features&tab=updates'         => array( 'Updates', 'build' ),
	'site-build-features&tab=log'             => array( 'Site log', 'build' ),
	'optima&tab=changes'                       => array( 'Change log', 'build' ),
	'optima-mail'                              => array( 'Email', 'build' ),
	'optima-events-crm'                        => array( 'Customer list', 'build' ),
	'optima-chat'                              => array( 'Chat settings', 'build' ),
	'client-theme'                        => array( 'Theme', 'build' ),
	'site-build-features&tab=inbox'           => array( 'Inbox', 'inbox' ),
	'site-build-features&tab=requests'        => array( 'Request a change', 'inbox' ),
	'edit.php?post_type=optima_chat_q'         => array( 'Chat questions', 'inbox' ),
	'optima-reviews-inbox'                     => array( 'Review inbox', 'inbox' ),
	'optima-reviews-ask'                       => array( 'Ask for reviews', 'inbox' ),
	'site-build-features&tab=health'          => array( 'Site health', 'health' ),
	'optima'                                   => array( 'Search health', 'health' ),
	'optima&tab=seo'                           => array( 'SEO', 'search' ),
	'optima&tab=protect'                       => array( 'Spam protection', 'search' ),
	'optima-ai-search'                         => array( 'AI search', 'search' ),
	'optima-analytics'                         => array( 'Analytics', 'search' ),
	'optima-accessibility'                     => array( 'Accessibility', 'search' ),
	'optima-sitecheck'                         => array( 'Site check settings', 'search' ),
	'edit.php?post_type=optima_sitecheck'      => array( 'Site checks', 'search' ),
	'site-build-robots'                       => array( 'robots.txt file', 'search' ),
	'optima&tab=sync'                          => array( 'Site Sync', 'content' ),
	'optima-redirects'                         => array( 'Redirects', 'content' ),
	'optima-optimize'                          => array( 'Image optimizer', 'content' ),
	'optima-writing'                           => array( 'Write', 'content' ),
	'optima&tab=business'                      => array( 'Business info', 'business' ),
	'optima-contact'                           => array( 'Call buttons', 'business' ),
	'optima-hours'                             => array( 'Hours', 'business' ),
	'optima-team'                              => array( 'Team settings', 'business' ),
	'edit.php?post_type=optima_team'           => array( 'Team members', 'business' ),
	'optima-results'                           => array( 'Case results settings', 'business' ),
	'optima-reviews-settings'                  => array( 'Review settings', 'business' ),
	'edit.php?post_type=optima_review'         => array( 'Reviews', 'business' ),
	'optima-cards'                             => array( 'Cards & Links', 'business' ),
	'optima-booking'                           => array( 'Schedule', 'business' ),
	'optima-events'                            => array( 'Overview', 'business' ),
	'optima-members'                           => array( 'Overview', 'business' ),
	'optima-partners'                          => array( 'Overview', 'business' ),
	'optima-newsletter'                        => array( 'Newsletter', 'business' ),
	'site-build-features&tab=features&group=inbox' => array( 'Inbox', 'inbox' ),
);
foreach ( $map as $slug => list( $label, $want ) ) {
	$got = Optima_Admin_UI::group( $slug, $label );
	ok( $got === $want, sprintf( '%-48s %-22s -> %s', $slug, $label, $got ) . ( $got === $want ? '' : " (wanted $want)" ) );
}
ok( array_keys( Optima_Admin_UI::GROUPS ) === array( 'overview', 'business', 'content', 'search', 'health', 'inbox', 'build' ), 'the seven groups, in the §7 order' );
ok( array() === Optima_Admin_UI::hidden(), 'with no hub loaded, nothing is hidden' );
eval( 'class Optima_Hub_Access { public static function is_operator() { return false; } }' );
ok( array( 'build' ) === Optima_Admin_UI::hidden(), 'a non-operator does not see Build' );
echo $fail ? "\n$fail FAILED\n" : "\nAll grouping checks passed\n";
exit( $fail ? 1 : 0 );
