<?php
// Optima Team: what visitors and the public REST API get, and where the
// "Reviewed by" line sits in the schema. No WordPress; stand-ins only:
//   php tools/tests/team-public.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['meta']    = array();
$GLOBALS['posts']   = array();
$GLOBALS['can']     = false;
$GLOBALS['queried'] = 0;
$GLOBALS['exact']   = false;

function __( $s, $d = '' ) { return $s; }
function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function get_option( $k, $d = false ) { return $d; }
function current_user_can( $cap ) { return $GLOBALS['can']; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_type( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]->post_type : false; }
function get_post_status( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]->post_status : false; }
function get_post_field( $field, $id ) { return $GLOBALS['posts'][ $id ]->$field ?? ''; }
function is_singular() { return true; }
function get_queried_object_id() { return $GLOBALS['queried']; }

class WP_Post {
	public $ID, $post_type, $post_status, $post_title = '', $post_content = '', $post_excerpt = '', $post_modified = '2026-01-01 00:00:00';
	public function __construct( array $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } }
}
class WP_Error {
	public $code;
	public function __construct( $code ) { $this->code = $code; }
}
class Response {
	private $data;
	public function __construct( array $d ) { $this->data = $d; }
	public function get_data() { return $this->data; }
	public function set_data( $d ) { $this->data = $d; }
}
final class Optima_Schema {
	public static $calls = 0;
	public static function add_webpage( array $graph ) {
		++self::$calls;
		foreach ( $graph as $node ) {
			if ( in_array( 'WebPage', (array) ( $node['@type'] ?? array() ), true ) ) {
				return $graph;
			}
		}
		$graph['webpage'] = array( '@type' => 'WebPage', '@id' => 'https://example.test/page/#webpage' );
		return $graph;
	}
}
final class Optima_SEO_Setup {
	public static function exact() { return $GLOBALS['exact']; }
}
final class Optima_Team_Schema {
	public static function person_id( int $id ): string { return 'https://example.test/team/' . $id . '/#person'; }
	public static function add_person( array $graph, int $id ): array { $graph[ 'person_' . $id ] = array( '@type' => 'Person', '@id' => self::person_id( $id ) ); return $graph; }
}

require __DIR__ . '/../../optima-team/includes/class-optima-team.php';
require __DIR__ . '/../../optima-team/includes/class-optima-team-front.php';
require __DIR__ . '/../../optima-team/includes/class-optima-team-reviewed.php';
$F = 'Optima_Team_Front';
$R = 'Optima_Team_Reviewed';

$fail = 0;
function check( string $what, bool $ok ): void {
	global $fail;
	echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $what . "\n";
	if ( ! $ok ) {
		++$fail;
	}
}

// 1. The REST API for visitors.
$data = $F::rest_public_data(
	array(
		'id'           => 5,
		'title'        => 'Dana',
		'_optima_team' => array( 'card_phone' => '1' ),
		'card_email'   => 'x@example.com',
		'meta'         => array( '_optima_team_pending' => array( 'bio' => 'x' ), '_optima_team_link_hash' => 'abc', 'card_intro' => 'Hi', 'kept' => 1 ),
	)
);
check( 'the profile meta and card data never go to visitors', ! isset( $data['_optima_team'] ) && ! isset( $data['card_email'] ) );
check( 'details waiting for an OK and link data never go to visitors', array( 'kept' => 1 ) === $data['meta'] );
check( 'everything else is left alone', 5 === $data['id'] && 'Dana' === $data['title'] );

$args = $F::without_hidden( array( 'post__not_in' => array( 3 ) ), array( 7, 8 ) );
check( 'hidden people are added to post__not_in', array( 3, 7, 8 ) === $args['post__not_in'] );
$args = $F::without_hidden( array( 'post__in' => array( 7, 9 ) ), array( 7, 8 ) );
check( 'asking for hidden people by ID leaves them out', array( 9 ) === $args['post__in'] );
$args = $F::without_hidden( array( 'post__in' => array( 7 ) ), array( 7 ) );
check( 'asking only for a hidden person finds nobody', array( 0 ) === $args['post__in'] );
check( 'nothing hidden: the query is untouched', array( 'x' => 1 ) === $F::without_hidden( array( 'x' => 1 ), array() ) );

$GLOBALS['posts'][5] = new WP_Post( array( 'ID' => 5, 'post_type' => 'optima_team', 'post_status' => 'publish' ) );
$GLOBALS['posts'][6] = new WP_Post( array( 'ID' => 6, 'post_type' => 'optima_team', 'post_status' => 'publish' ) );
$GLOBALS['posts'][7] = new WP_Post( array( 'ID' => 7, 'post_type' => 'optima_team', 'post_status' => 'draft' ) );
$GLOBALS['meta'][6]['_optima_team'] = array( 'shows' => array( 'bio' => false ) );
$out = $F::rest_prepare( new Response( array( 'id' => 5, 'card_tags' => 'x' ) ), $GLOBALS['posts'][5] );
check( 'a live person with a page: sent, without card data', $out instanceof Response && array( 'id' => 5 ) === $out->get_data() );
check( 'a person whose page is off: not found', $F::rest_prepare( new Response( array() ), $GLOBALS['posts'][6] ) instanceof WP_Error );
check( 'a draft: not found', $F::rest_prepare( new Response( array() ), $GLOBALS['posts'][7] ) instanceof WP_Error );
$GLOBALS['can'] = true;
check( 'editors and the block editor see everyone, as before', $F::rest_prepare( new Response( array( 'card_tags' => 'x' ) ), $GLOBALS['posts'][6] ) instanceof Response );
$GLOBALS['can'] = false;

// 2. Reviewed by on a plain page: Core's WebPage node is there for the stamp.
$GLOBALS['posts'][20] = new WP_Post( array( 'ID' => 20, 'post_type' => 'optima_team', 'post_status' => 'publish' ) );
$GLOBALS['posts'][30] = new WP_Post( array( 'ID' => 30, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Car accidents', 'post_content' => 'Words.' ) );
$GLOBALS['meta'][30]  = array(
	$R::META_WHO  => '20',
	$R::META_WHEN => '2026-10-01',
	$R::META_VER  => md5( "Car accidents\nWords.\n" ),
);
$GLOBALS['queried'] = 30;
check( 'the review is complete and printable (signatures unchanged)', 'complete' === $R::status( 30 ) && 20 === $R::printable( 30 )['who'] && 20 === $R::get( 30 )['who'] );
$g = $R::schema( array( 'business' => array( '@type' => 'LegalService' ) ) );
check( 'a plain page gets the WebPage node before the stamp', isset( $g['webpage'] ) && 1 === Optima_Schema::$calls );
check( 'reviewedBy and lastReviewed sit on the WebPage node', 'https://example.test/team/20/#person' === $g['webpage']['reviewedBy']['@id'] && '2026-10-01' === $g['webpage']['lastReviewed'] );
check( 'the reviewer joins the graph', isset( $g['person_20'] ) );
check( 'the business node is not stamped', ! isset( $g['business']['reviewedBy'] ) );
$g = $R::schema( array( 'webpage' => array( '@type' => 'WebPage' ) ) );
check( 'a WebPage node already there (Optima Law\'s) is stamped, not doubled', 1 === Optima_Schema::$calls && isset( $g['webpage']['reviewedBy'] ) && 2 === count( $g ) );
$g = $R::schema( array( 'page_7' => array( '@type' => array( 'WebPage', 'FAQPage' ) ) ) );
check( 'a page node under a theme\'s own key is stamped', isset( $g['page_7']['reviewedBy'] ) && ! isset( $g['webpage'] ) );
$g = $R::schema( array( 'article' => array( '@type' => 'BlogPosting' ) ) );
check( 'an article is stamped and gets no extra WebPage', isset( $g['article']['reviewedBy'] ) && ! isset( $g['webpage'] ) );
$GLOBALS['exact'] = true;
$g = $R::schema( array( 'business' => array( '@type' => 'LegalService' ) ) );
check( 'a theme\'s exact page graph gets no WebPage added', ! isset( $g['webpage'] ) );
$GLOBALS['exact']   = false;
$GLOBALS['meta'][30][ $R::META_VER ] = 'changed';
check( 'a review out of date adds nothing', array( 'x' => array( '@type' => 'WebPage' ) ) === $R::schema( array( 'x' => array( '@type' => 'WebPage' ) ) ) );

echo $fail ? "\n$fail FAILED\n" : "\nPASS\n";
exit( $fail ? 1 : 0 );
