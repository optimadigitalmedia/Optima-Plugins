<?php
/**
 * The firm's offices: Your business, Offices.
 *
 * A repeatable list of offices, typed once here (rule 45) or filled from the
 * theme's optima-setup.json `offices[]` by Optima Build's setup filler (it
 * writes this option by office id, blanks only, and never removes an office).
 * Stored as option `optima_core_offices`, a plain list in the release 2
 * contract shape:
 *
 *   { id, name, page, street, suite, city, state, zip, phone, place_id,
 *     geo: { lat, lng }, gbp_url, hours: [ { days: [ "Mo", ... ], opens, closes } ],
 *     phone_hours: "24/7" | [ same shape as hours ] | "", timezone, photo,
 *     parking, entrance, courthouses: [], practices: [], primary }
 *
 * `page` (optional, Core's own key) is the address of the office's page. When
 * it is empty, the office page is the published page whose slug is the office id.
 *
 * What it feeds:
 *  - Schema: on each office's own page, one node of the business type (a law
 *    firm prints LegalService) with @id <office page URL>#office, its address,
 *    telephone, geo (only with 5 or more decimals), hasMap (Google Maps with the
 *    Place ID), openingHoursSpecification (the walk-in hours; while Optima Hours
 *    owns the hours job, its confirmed schedule instead, see hours_props()), parentOrganization
 *    (the firm, #business) and a ContactPoint for intake (hoursAvailable only from
 *    the phone hours, availableLanguage only from the site's languages). The firm
 *    node lists every office that has a page through `subOrganization`, the
 *    schema.org inverse of parentOrganization (`department` is for a department
 *    inside one place, such as a pharmacy in a store, so it is not used).
 *  - The `office-card` slot: office cards with the address, phone, hours with
 *    the time zone and a Get directions link (event directions_click).
 *
 * No offices saved: nothing here prints, and the firm node is exactly what it
 * was before. The card slot then shows the one address from Details.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Offices {

	const OPTION   = 'optima_core_offices';
	const SETTINGS = 'optima_core_offices_settings'; // { map_key }
	const LANGS    = 'optima_languages';             // BCP 47 codes, mirrored from optima-setup.json by Optima Build.

	const DAYS = array(
		'Mo' => 'Monday',
		'Tu' => 'Tuesday',
		'We' => 'Wednesday',
		'Th' => 'Thursday',
		'Fr' => 'Friday',
		'Sa' => 'Saturday',
		'Su' => 'Sunday',
	);

	/** Language names for availableLanguage, by code. Unknown codes print as the code. */
	const LANGUAGE_NAMES = array(
		'en' => 'English',
		'es' => 'Spanish',
		'fr' => 'French',
		'pt' => 'Portuguese',
		'ht' => 'Haitian Creole',
		'vi' => 'Vietnamese',
		'zh' => 'Chinese',
		'ko' => 'Korean',
		'ar' => 'Arabic',
		'ru' => 'Russian',
		'tl' => 'Tagalog',
		'de' => 'German',
		'it' => 'Italian',
		'pl' => 'Polish',
		'hi' => 'Hindi',
	);

	/** @var array|null Offices, read once per request. */
	private static $cache = null;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'optima_schema_graph', array( __CLASS__, 'graph' ), 15 );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
		add_action( 'update_option_' . self::OPTION, array( 'Optima_Schema', 'clear_cache' ) );
		add_action( 'wp', array( __CLASS__, 'hook_slot' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_style' ) );
	}

	public static function register_settings() {
		register_setting( 'optima_offices_group', self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
		register_setting( 'optima_offices_group', self::SETTINGS, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ) ) );
	}

	public static function flush() {
		self::$cache = null;
	}

	/* ---------- Data ---------- */

	/** @return array<int,array> Every saved office, cleaned, primary first. */
	public static function all() {
		if ( null === self::$cache ) {
			$raw = get_option( self::OPTION, array() );
			$out = array();
			foreach ( is_array( $raw ) ? $raw : array() as $key => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( empty( $row['id'] ) && is_string( $key ) ) {
					$row['id'] = $key;
				}
				$row = self::clean( $row );
				if ( '' !== $row['id'] && '' !== $row['name'] ) {
					$out[] = $row;
				}
			}
			usort(
				$out,
				static function ( $a, $b ) {
					return (int) $b['primary'] - (int) $a['primary'];
				}
			);
			self::$cache = $out;
		}
		return self::$cache;
	}

	/** One office by id, or null. */
	public static function get( $id ) {
		foreach ( self::all() as $office ) {
			if ( $office['id'] === $id ) {
				return $office;
			}
		}
		return null;
	}

	/** Languages the site serves (BCP 47 codes), from optima-setup.json `languages`. */
	public static function languages() {
		$codes = get_option( self::LANGS, array() );
		$out   = array();
		foreach ( is_array( $codes ) ? $codes : array() as $code ) {
			$code = is_scalar( $code ) ? (string) preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $code ) : '';
			if ( '' !== $code && ! in_array( $code, $out, true ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/** The static map key: OPTIMA_MAPS_KEY in wp-config wins, else the one saved on Offices. */
	public static function map_key() {
		if ( defined( 'OPTIMA_MAPS_KEY' ) && is_string( OPTIMA_MAPS_KEY ) && '' !== OPTIMA_MAPS_KEY ) {
			return OPTIMA_MAPS_KEY;
		}
		$s = get_option( self::SETTINGS, array() );
		return is_array( $s ) ? (string) ( $s['map_key'] ?? '' ) : '';
	}

	/* ---------- Cleaning (the form and the setup filler both save through here) ---------- */

	/**
	 * register_setting sanitizer. Takes the Offices form (rows with text boxes,
	 * a remove box per row, one primary radio) or a list already in the
	 * contract shape (Optima Build's filler). Saving twice gives the same list.
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$primary = null;
		if ( isset( $input['_primary'] ) ) {
			$primary = sanitize_key( (string) $input['_primary'] );
			unset( $input['_primary'] );
		}
		$out  = array();
		$ids  = array();
		$keep = false;
		foreach ( $input as $key => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['_remove'] ) ) {
				continue;
			}
			if ( empty( $row['id'] ) && is_string( $key ) ) {
				$row['id'] = $key;
			}
			$from_form = isset( $row['_form'] );
			$row       = self::clean( $row );
			if ( '' === $row['name'] ) {
				continue;
			}
			if ( '' === $row['id'] ) {
				$row['id'] = sanitize_title( '' !== $row['city'] ? $row['city'] : $row['name'] );
			}
			$base = $row['id'];
			for ( $n = 2; in_array( $row['id'], $ids, true ); $n++ ) {
				$row['id'] = $base . '-' . $n;
			}
			$ids[] = $row['id'];
			if ( null !== $primary && $from_form ) {
				$row['primary'] = ( (string) $key === $primary || $row['id'] === $primary );
			}
			if ( $row['primary'] ) {
				// One main office: the first one marked.
				$row['primary'] = ! $keep;
				$keep           = true;
			}
			$out[] = $row;
		}
		self::flush();
		return $out;
	}

	public static function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		return array( 'map_key' => (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $input['map_key'] ?? '' ) ) );
	}

	/** One office in the contract shape (plus `page`), every field cleaned. Text boxes from the form are read too. */
	public static function clean( array $in ) {
		$text = static function ( $key ) use ( $in ) {
			$v = $in[ $key ] ?? '';
			return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
		};
		$list = static function ( $value ) {
			if ( is_string( $value ) ) {
				$value = preg_split( '/[\r\n,]+/', $value );
			}
			return array_values( array_filter( array_map( 'trim', array_map( 'sanitize_text_field', array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) ) ) ), 'strlen' ) );
		};

		// Geo: { lat, lng } from the file, or the form's two boxes.
		$geo = isset( $in['geo'] ) && is_array( $in['geo'] ) ? $in['geo'] : array( 'lat' => $in['lat'] ?? '', 'lng' => $in['lng'] ?? '' );
		$lat = is_numeric( $geo['lat'] ?? null ) ? (float) $geo['lat'] : 0.0;
		$lng = is_numeric( $geo['lng'] ?? null ) ? (float) $geo['lng'] : 0.0;

		$phone_hours = $in['phone_hours'] ?? '';
		if ( is_string( $phone_hours ) ) {
			$phone_hours = '24/7' === strtolower( str_replace( ' ', '', trim( $phone_hours ) ) ) ? '24/7' : self::parse_hours( $phone_hours );
		} else {
			$phone_hours = self::parse_hours( (array) $phone_hours );
		}
		if ( array() === $phone_hours ) {
			$phone_hours = '';
		}

		$zone  = $text( 'timezone' );
		$page  = $text( 'page' );
		if ( '' !== $page && ! preg_match( '#^https?://#', $page ) ) {
			$page = '/' . trim( $page, '/' ) . '/';
			$page = '//' === $page ? '/' : $page;
		} elseif ( '' !== $page ) {
			$page = esc_url_raw( $page );
		}
		$photo = $text( 'photo' );

		return array(
			'id'          => sanitize_key( (string) ( is_scalar( $in['id'] ?? '' ) ? ( $in['id'] ?? '' ) : '' ) ),
			'name'        => $text( 'name' ),
			'page'        => $page,
			'street'      => $text( 'street' ),
			'suite'       => $text( 'suite' ),
			'city'        => $text( 'city' ),
			'state'       => strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', $text( 'state' ) ), 0, 2 ) ),
			'zip'         => (string) preg_replace( '/[^0-9\-]/', '', $text( 'zip' ) ),
			'phone'       => trim( (string) preg_replace( '/[^0-9+().\-\s]/', '', $text( 'phone' ) ) ),
			'place_id'    => (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $text( 'place_id' ) ),
			'geo'         => ( $lat || $lng ) && abs( $lat ) <= 90 && abs( $lng ) <= 180 ? array( 'lat' => $lat, 'lng' => $lng ) : array(),
			'gbp_url'     => esc_url_raw( $text( 'gbp_url' ), array( 'https' ) ),
			'hours'       => self::parse_hours( $in['hours'] ?? array() ),
			'phone_hours' => $phone_hours,
			'timezone'    => in_array( $zone, timezone_identifiers_list(), true ) ? $zone : '',
			'photo'       => '' !== $photo ? esc_url_raw( $photo ) : '',
			'parking'     => sanitize_textarea_field( (string) ( is_scalar( $in['parking'] ?? '' ) ? ( $in['parking'] ?? '' ) : '' ) ),
			'entrance'    => sanitize_textarea_field( (string) ( is_scalar( $in['entrance'] ?? '' ) ? ( $in['entrance'] ?? '' ) : '' ) ),
			'courthouses' => $list( $in['courthouses'] ?? array() ),
			'practices'   => array_values( array_unique( array_filter( array_map( 'sanitize_title', $list( $in['practices'] ?? array() ) ) ) ) ),
			'primary'     => ! empty( $in['primary'] ),
		);
	}

	/**
	 * Hours rows from the file ([ { days, opens, closes } ]) or the form's text
	 * box, one line per row: "Mo-Fr 08:30-17:30", "Sa 09:00-13:00", "Mo,We 09:00-12:00".
	 * "24/7" means every day, all day.
	 *
	 * @param array|string $value
	 * @return array<int,array{days:string[],opens:string,closes:string}>
	 */
	public static function parse_hours( $value ) {
		$rows = array();
		if ( is_string( $value ) ) {
			foreach ( preg_split( '/\r\n|\r|\n|;/', $value ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				if ( '24/7' === str_replace( ' ', '', $line ) ) {
					$rows[] = array( 'days' => array_keys( self::DAYS ), 'opens' => '00:00', 'closes' => '23:59' );
					continue;
				}
				if ( ! preg_match( '/^(.*?)\s*(\d{1,2}:\d{2})\s*(?:-|to)\s*(\d{1,2}:\d{2})$/i', $line, $m ) ) {
					continue;
				}
				$rows[] = array( 'days' => self::parse_days( $m[1] ), 'opens' => $m[2], 'closes' => $m[3] );
			}
		} else {
			$rows = (array) $value;
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$days = array();
			foreach ( array_filter( (array) ( $row['days'] ?? array() ), 'is_scalar' ) as $d ) {
				$d = ucfirst( strtolower( substr( (string) $d, 0, 2 ) ) );
				if ( isset( self::DAYS[ $d ] ) && ! in_array( $d, $days, true ) ) {
					$days[] = $d;
				}
			}
			$opens  = self::time( (string) ( $row['opens'] ?? '' ) );
			$closes = self::time( (string) ( $row['closes'] ?? '' ) );
			if ( $days && '' !== $opens && '' !== $closes ) {
				$order = array_keys( self::DAYS );
				usort(
					$days,
					static function ( $a, $b ) use ( $order ) {
						return array_search( $a, $order, true ) - array_search( $b, $order, true );
					}
				);
				$out[] = array( 'days' => $days, 'opens' => $opens, 'closes' => $closes );
			}
		}
		return $out;
	}

	/** "Mo-Fr", "Mo,We,Fr", "Mo Tu We" => [ "Mo", ... ]. */
	private static function parse_days( $text ) {
		$order = array_keys( self::DAYS );
		$days  = array();
		foreach ( preg_split( '/[\s,]+/', trim( (string) $text ) ) as $part ) {
			if ( preg_match( '/^([A-Za-z]{2})[A-Za-z]*\s*-\s*([A-Za-z]{2})[A-Za-z]*$/', $part, $m ) ) {
				$a = array_search( ucfirst( strtolower( $m[1] ) ), $order, true );
				$b = array_search( ucfirst( strtolower( $m[2] ) ), $order, true );
				if ( false !== $a && false !== $b && $a <= $b ) {
					$days = array_merge( $days, array_slice( $order, $a, $b - $a + 1 ) );
				}
			} elseif ( preg_match( '/^([A-Za-z]{2})/', $part, $m ) ) {
				$days[] = ucfirst( strtolower( $m[1] ) );
			}
		}
		return array_values( array_unique( $days ) );
	}

	/** "HH:MM" (24 hour, 24:00 allowed) or ''. */
	private static function time( $t ) {
		if ( ! preg_match( '/^([01]?\d|2[0-4]):([0-5]\d)$/', trim( $t ), $m ) || ( 24 === (int) $m[1] && '00' !== $m[2] ) ) {
			return '';
		}
		return sprintf( '%02d:%s', (int) $m[1], $m[2] );
	}

	/** Hours rows back to the form's text box. */
	public static function hours_text( $rows ) {
		if ( '24/7' === $rows ) {
			return '24/7';
		}
		$lines = array();
		foreach ( (array) $rows as $r ) {
			$lines[] = implode( ',', $r['days'] ) . ' ' . $r['opens'] . '-' . $r['closes'];
		}
		return implode( "\n", $lines );
	}

	/* ---------- Office pages ---------- */

	/** The office's page ID (its `page` address, else the published page slugged as the office id), or 0. */
	public static function page_id( array $office ) {
		static $found = array();
		$key = $office['id'] . '|' . $office['page'];
		if ( isset( $found[ $key ] ) ) {
			return $found[ $key ];
		}
		$id = 0;
		if ( '' !== $office['page'] ) {
			$id = preg_match( '#^https?://#', $office['page'] )
				? url_to_postid( $office['page'] )
				: (int) ( get_page_by_path( trim( $office['page'], '/' ) )->ID ?? 0 );
		}
		if ( ! $id && '' !== $office['id'] ) {
			$pages = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'name'           => $office['id'],
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$id = $pages ? (int) $pages[0] : 0;
		}
		if ( $id && 'publish' !== get_post_status( $id ) ) {
			$id = 0;
		}
		$found[ $key ] = (int) $id;
		return $found[ $key ];
	}

	/** The office's @id: its page address + #office, or '' when it has no page. */
	public static function node_id( array $office ) {
		$page = self::page_id( $office );
		return $page ? (string) get_permalink( $page ) . '#office' : '';
	}

	/** The office whose page is being viewed, or null. */
	public static function current() {
		if ( ! is_page() ) {
			return null;
		}
		$id = (int) get_queried_object_id();
		foreach ( self::all() as $office ) {
			if ( $id && self::page_id( $office ) === $id ) {
				return $office;
			}
		}
		return null;
	}

	/* ---------- Schema ---------- */

	/**
	 * Lists the offices from the firm node and adds the full node of the office
	 * whose page this is. Steps aside in pages mode (the theme lists every node
	 * itself) and when there are no offices.
	 *
	 * @param array<string,array> $graph
	 */
	public static function graph( $graph ) {
		if ( ! is_array( $graph ) || ! self::all() ) {
			return $graph;
		}
		if ( class_exists( 'Optima_SEO_Setup' ) && Optima_SEO_Setup::exact() ) {
			return $graph;
		}
		$refs = array();
		foreach ( self::all() as $office ) {
			$id = self::node_id( $office );
			if ( '' !== $id ) {
				$refs[] = array( '@id' => $id );
			}
		}
		if ( $refs && isset( $graph['business'] ) && is_array( $graph['business'] ) ) {
			$graph['business']['subOrganization'] = $refs;
		}
		$office = self::current();
		if ( $office ) {
			$graph[ 'office-' . $office['id'] ] = self::node( $office );
		}
		return $graph;
	}

	/** One office's schema node. */
	public static function node( array $office ) {
		$biz  = optima_core_business();
		$type = '' !== $biz['business_type'] ? Optima_Core_Business::schema_type( $biz['business_type'] ) : 'LocalBusiness';
		$url  = (string) get_permalink( self::page_id( $office ) );
		$node = array(
			'@type'              => $type,
			'@id'                => $url . '#office',
			'name'               => $office['name'],
			'url'                => $url,
			'parentOrganization' => array( '@id' => home_url( '/#business' ) ),
		);
		$address = array_filter(
			array(
				'streetAddress'   => trim( $office['street'] . ( '' !== $office['suite'] ? ', ' . $office['suite'] : '' ) ),
				'addressLocality' => $office['city'],
				'addressRegion'   => $office['state'],
				'postalCode'      => $office['zip'],
				'addressCountry'  => '' !== $biz['country'] ? $biz['country'] : 'US',
			),
			'strlen'
		);
		if ( count( $address ) > 1 ) {
			$node['address'] = array( '@type' => 'PostalAddress' ) + $address;
		}
		$phone = '' !== $office['phone'] ? $office['phone'] : $biz['phone'];
		if ( '' !== $phone ) {
			$node['telephone'] = $phone;
		}
		if ( self::precise( $office ) ) {
			$node['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $office['geo']['lat'],
				'longitude' => $office['geo']['lng'],
			);
		}
		$map = self::map_url( $office );
		if ( '' !== $map ) {
			$node['hasMap'] = $map;
		}
		if ( '' !== $office['photo'] ) {
			$node['image'] = $office['photo'];
		}
		if ( '' !== $office['gbp_url'] ) {
			$node['sameAs'] = array( $office['gbp_url'] );
		}
		foreach ( self::hours_props( $office ) as $key => $value ) {
			$node[ $key ] = $value;
		}
		$point = self::contact_point( $office, $phone );
		if ( $point ) {
			$node['contactPoint'] = $point;
		}
		return (array) apply_filters( 'optima_core_office_node', $node, $office );
	}

	/** True when Optima Hours owns the hours job (optima_owns( 'hours' ), from Optima Build) and its schema reader is loaded. */
	public static function hours_owned() {
		return function_exists( 'optima_owns' ) && optima_owns( 'hours' )
			&& class_exists( 'Optima_Hours_Schema' ) && method_exists( 'Optima_Hours_Schema', 'properties' );
	}

	/**
	 * The office node's opening hours. One owner: while Optima Hours owns the
	 * hours job, the node carries Optima Hours' confirmed schedule (opening and
	 * holiday hours, nothing until they are confirmed) and the office's own
	 * walk-in rows are not printed. Without Optima Hours, the office's own rows.
	 *
	 * @return array<string,array> Schema properties to set on the node.
	 */
	public static function hours_props( array $office ) {
		if ( self::hours_owned() ) {
			return array_intersect_key(
				(array) Optima_Hours_Schema::properties(),
				array_flip( array( 'openingHoursSpecification', 'specialOpeningHoursSpecification' ) )
			);
		}
		$hours = self::specs( $office['hours'] );
		return $hours ? array( 'openingHoursSpecification' => $hours ) : array();
	}

	/** The intake ContactPoint: hoursAvailable only from phone hours, availableLanguage only from the site's languages. */
	public static function contact_point( array $office, $phone ) {
		if ( '' === $phone ) {
			return array();
		}
		$point = array(
			'@type'       => 'ContactPoint',
			'contactType' => 'intake',
			'telephone'   => $phone,
		);
		$hours = self::phone_specs( $office );
		if ( $hours ) {
			$point['hoursAvailable'] = $hours;
		}
		$langs = self::language_nodes();
		if ( $langs ) {
			$point['availableLanguage'] = $langs;
		}
		return $point;
	}

	/** The office's phone hours as specifications: its own, else the site's Phones answered schedule (Optima Hours). */
	public static function phone_specs( array $office ) {
		if ( '24/7' === $office['phone_hours'] ) {
			return array( self::always() );
		}
		if ( is_array( $office['phone_hours'] ) && $office['phone_hours'] ) {
			return self::specs( $office['phone_hours'] );
		}
		if ( class_exists( 'Optima_Hours_Schema' ) && method_exists( 'Optima_Hours_Schema', 'phone_specs' ) ) {
			return Optima_Hours_Schema::phone_specs();
		}
		return array();
	}

	/** Open every day, all day: Google's documented 00:00 to 23:59. */
	private static function always() {
		return array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => array_values( self::DAYS ),
			'opens'     => '00:00',
			'closes'    => '23:59',
		);
	}

	/** @return array<int,array> Languages as schema Language entries. */
	public static function language_nodes() {
		$out = array();
		foreach ( self::languages() as $code ) {
			$base  = strtolower( substr( $code, 0, 2 ) );
			$out[] = array(
				'@type'         => 'Language',
				'name'          => self::LANGUAGE_NAMES[ $base ] ?? $code,
				'alternateName' => $code,
			);
		}
		return $out;
	}

	/** Hours rows as OpeningHoursSpecification entries. */
	public static function specs( $rows ) {
		$out = array();
		foreach ( (array) $rows as $r ) {
			$names = array();
			foreach ( $r['days'] as $d ) {
				$names[] = self::DAYS[ $d ];
			}
			$out[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => 1 === count( $names ) ? $names[0] : $names,
				'opens'     => $r['opens'],
				'closes'    => '24:00' === $r['closes'] ? '23:59' : $r['closes'],
			);
		}
		return $out;
	}

	/** True when both coordinates carry 5 or more decimals (geo prints only then). */
	public static function precise( array $office ) {
		if ( empty( $office['geo'] ) ) {
			return false;
		}
		foreach ( array( 'lat', 'lng' ) as $k ) {
			$s = (string) $office['geo'][ $k ];
			if ( ! preg_match( '/\.\d{5,}/', $s ) ) {
				return false;
			}
		}
		return true;
	}

	/** One line of the address: "100 Main St, Suite 200, Tampa, FL 33602". */
	public static function address_line( array $office ) {
		$street = trim( $office['street'] . ( '' !== $office['suite'] ? ', ' . $office['suite'] : '' ) );
		$place  = trim( $office['state'] . ' ' . $office['zip'] );
		return implode( ', ', array_filter( array( $street, $office['city'], $place ), 'strlen' ) );
	}

	/** Google Maps for the office: by Place ID when there is one, else its Business Profile address. */
	public static function map_url( array $office ) {
		if ( '' !== $office['place_id'] ) {
			$query = trim( $office['name'] . ', ' . self::address_line( $office ), ', ' );
			return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query ) . '&query_place_id=' . rawurlencode( $office['place_id'] );
		}
		return $office['gbp_url'];
	}

	/** Google Maps directions to the office. */
	public static function directions_url( array $office ) {
		$url = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( self::address_line( $office ) );
		if ( '' !== $office['place_id'] ) {
			$url .= '&destination_place_id=' . rawurlencode( $office['place_id'] );
		}
		return $url;
	}

	/* ---------- Words: hours and time zones ---------- */

	/** Short US time zone names; anything else uses PHP's abbreviation. */
	const ZONES = array(
		'America/New_York'             => 'ET',
		'America/Detroit'              => 'ET',
		'America/Indiana/Indianapolis' => 'ET',
		'America/Kentucky/Louisville'  => 'ET',
		'America/Chicago'              => 'CT',
		'America/Denver'               => 'MT',
		'America/Boise'                => 'MT',
		'America/Phoenix'              => 'MST',
		'America/Los_Angeles'          => 'PT',
		'America/Anchorage'            => 'AKT',
		'Pacific/Honolulu'             => 'HT',
	);

	/** The office's time zone: its own, else Optima Hours' setting, else WordPress's. */
	public static function zone( array $office ) {
		if ( '' !== $office['timezone'] ) {
			return new DateTimeZone( $office['timezone'] );
		}
		if ( class_exists( 'Optima_Hours' ) && method_exists( 'Optima_Hours', 'timezone' ) ) {
			return Optima_Hours::timezone();
		}
		return wp_timezone();
	}

	/** "ET" for a zone; filter `optima_core_zone_label`. */
	public static function zone_label( DateTimeZone $zone ) {
		$name  = $zone->getName();
		$label = self::ZONES[ $name ] ?? ( new DateTimeImmutable( 'now', $zone ) )->format( 'T' );
		if ( preg_match( '/^[+-]\d/', $label ) ) {
			$label = 'UTC' . $label;
		}
		return (string) apply_filters( 'optima_core_zone_label', $label, $name );
	}

	/** "8:30 a.m.", "noon", "midnight" (the same style as Optima Hours' footer line). */
	private static function short_time( $hhmm ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $hhmm ) );
		$h = $h % 24;
		if ( 0 === $m && 12 === $h ) {
			return 'noon';
		}
		if ( 0 === $m && 0 === $h ) {
			return 'midnight';
		}
		$hour = $h % 12 ? $h % 12 : 12;
		return ( $m ? sprintf( '%d:%02d', $hour, $m ) : (string) $hour ) . ' ' . ( $h < 12 ? 'a.m.' : 'p.m.' );
	}

	/**
	 * Hours rows as lines a visitor reads, each with the time zone:
	 * "Monday to Friday, 8:30 a.m. to 5:30 p.m. ET".
	 *
	 * @return string[]
	 */
	public static function hours_lines( $rows, DateTimeZone $zone ) {
		if ( '24/7' === $rows ) {
			return array( 'Every day, around the clock' );
		}
		$per_day = array();
		foreach ( (array) $rows as $r ) {
			foreach ( $r['days'] as $d ) {
				$per_day[ $d ][] = self::short_time( $r['opens'] ) . ' to ' . self::short_time( '24:00' === $r['closes'] ? '00:00' : $r['closes'] );
			}
		}
		if ( ! $per_day ) {
			return array();
		}
		$tz     = self::zone_label( $zone );
		$groups = array();
		$last   = null;
		foreach ( array_keys( self::DAYS ) as $d ) {
			$sig = isset( $per_day[ $d ] ) ? implode( ' and ', $per_day[ $d ] ) : '';
			if ( '' !== $sig && $sig === $last ) {
				$groups[ count( $groups ) - 1 ]['to'] = $d;
			} elseif ( '' !== $sig ) {
				$groups[] = array( 'from' => $d, 'to' => $d, 'times' => $sig );
			}
			$last = $sig;
		}
		$lines = array();
		foreach ( $groups as $g ) {
			$days    = self::DAYS[ $g['from'] ] . ( $g['to'] !== $g['from'] ? ' to ' . self::DAYS[ $g['to'] ] : '' );
			$lines[] = $days . ', ' . $g['times'] . ( '' !== $tz ? ' ' . $tz : '' );
		}
		return $lines;
	}

	/** "(813) 555-0100" for a US number, else as typed. */
	public static function phone_label( $phone ) {
		$digits = preg_replace( '/\D/', '', (string) $phone );
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		return 10 === strlen( $digits ) ? sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6 ) ) : (string) $phone;
	}

	/* ---------- The office-card slot ---------- */

	/** Fill the slot only when there is something to show, so optima_slot_has() tells the truth. */
	public static function hook_slot() {
		if ( is_admin() ) {
			return;
		}
		$biz = optima_core_business();
		if ( self::all() || '' !== $biz['street'] || '' !== $biz['city'] ) {
			add_action( 'optima_slot_office-card', array( __CLASS__, 'slot' ) );
		}
	}

	public static function register_style() {
		wp_register_style( 'optima-core', plugins_url( 'assets/optima-core.css', OPTIMA_CORE_FILE ), array(), OPTIMA_CORE_VERSION );
	}

	/**
	 * Office cards. $args: office (an id, or 'all', the default), variant
	 * ('directions', the default, or 'map').
	 */
	public static function slot( $args = array() ) {
		$args    = is_array( $args ) ? $args : array();
		$which   = isset( $args['office'] ) && is_scalar( $args['office'] ) ? sanitize_key( (string) $args['office'] ) : 'all';
		$which   = '' === $which ? 'all' : $which;
		$variant = isset( $args['variant'] ) && 'map' === $args['variant'] ? 'map' : 'directions';
		$offices = self::all();
		if ( ! $offices ) {
			$offices = array( self::from_details() );
		} elseif ( 'all' !== $which ) {
			$one     = self::get( $which );
			$offices = $one ? array( $one ) : array();
		}
		$offices = array_filter( $offices );
		if ( ! $offices ) {
			return;
		}
		wp_enqueue_style( 'optima-core' );
		$single = 1 === count( $offices ) && 'all' !== $which;
		echo '<div class="optima-offices is-' . esc_attr( $variant ) . '">';
		foreach ( $offices as $office ) {
			self::card( $office, $variant, $single );
		}
		echo '</div>';
	}

	/** Details as one office, for a site with no offices list. */
	private static function from_details() {
		$b = optima_core_business();
		if ( '' === $b['street'] && '' === $b['city'] ) {
			return null;
		}
		$office = self::clean(
			array(
				'id'      => 'main',
				'name'    => '' !== $b['name'] ? $b['name'] : get_bloginfo( 'name' ),
				'street'  => $b['street'],
				'city'    => $b['city'],
				'state'   => $b['region'],
				'zip'     => $b['postal'],
				'phone'   => $b['phone'],
				'geo'     => array( 'lat' => $b['latitude'], 'lng' => $b['longitude'] ),
				'primary' => true,
			)
		);
		$office['_details'] = true;
		return $office;
	}

	private static function card( array $office, $variant, $single ) {
		$zone       = self::zone( $office );
		$directions = self::directions_url( $office );
		$name       = $office['name'];
		$phone      = '' !== $office['phone'] ? $office['phone'] : optima_core_business()['phone'];

		echo '<article class="optima-office" id="' . esc_attr( 'office-' . $office['id'] ) . '">';
		if ( 'map' === $variant ) {
			$key = self::map_key();
			echo '<a class="optima-office-map' . ( '' === $key || empty( $office['geo'] ) ? ' is-placeholder' : '' ) . '" href="' . esc_url( $directions ) . '" target="_blank" rel="noopener" data-optima-track="directions_click" data-optima-location="office_map" data-optima-label="' . esc_attr( $name ) . '">';
			if ( '' !== $key && ! empty( $office['geo'] ) ) {
				$at  = rawurlencode( $office['geo']['lat'] . ',' . $office['geo']['lng'] );
				$src = 'https://maps.googleapis.com/maps/api/staticmap?center=' . $at . '&zoom=15&size=640x320&scale=2&markers=' . $at . '&key=' . rawurlencode( $key );
				echo '<img src="' . esc_url( $src ) . '" width="640" height="320" loading="lazy" decoding="async" alt="' . esc_attr( sprintf( 'Map showing %s', $name ) ) . '">';
			} else {
				echo '<span class="optima-office-map-text">Open the map</span>';
			}
			echo '<span class="optima-sr">' . esc_html( sprintf( 'to %s (opens in a new tab)', $name ) ) . '</span></a>';
		}
		echo '<h3 class="optima-office-name">' . esc_html( $name ) . '</h3>';

		$street = trim( $office['street'] . ( '' !== $office['suite'] ? ', ' . $office['suite'] : '' ) );
		$place  = trim( $office['city'] . ( '' !== $office['city'] && '' !== $office['state'] ? ', ' : '' ) . trim( $office['state'] . ' ' . $office['zip'] ) );
		echo '<p class="optima-office-address">';
		if ( '' !== $street ) {
			echo '<span>' . esc_html( $street ) . '</span><br>';
		}
		echo '<span>' . esc_html( $place ) . '</span></p>';

		if ( '' !== $phone ) {
			echo '<p class="optima-office-phone"><a href="' . esc_attr( 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) ) . '" data-optima-track="call_click" data-optima-location="office_card" data-optima-label="' . esc_attr( $name ) . '">' . esc_html( self::phone_label( $phone ) ) . '</a></p>';
		}

		// Walk-in hours: the office's own, else (the main office or Details) the site's Hours.
		$lines = self::hours_lines( $office['hours'], $zone );
		if ( ! $lines && ( $office['primary'] || ! empty( $office['_details'] ) ) && function_exists( 'optima_hours_data' ) && class_exists( 'Optima_Hours' ) ) {
			$summary = Optima_Hours::summary();
			$lines   = '' !== $summary ? array( rtrim( $summary, '.' ) ) : array();
		}
		if ( $lines ) {
			echo '<div class="optima-office-hours"><p class="optima-office-label">Office hours</p><ul>';
			foreach ( $lines as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul></div>';
		}
		$phone_lines = self::hours_lines( $office['phone_hours'], $zone );
		if ( ! $phone_lines && class_exists( 'Optima_Hours' ) && method_exists( 'Optima_Hours', 'phone_summary' ) ) {
			$p           = Optima_Hours::phone_summary();
			$phone_lines = '' !== $p ? array( rtrim( $p, '.' ) ) : array();
		}
		if ( $phone_lines ) {
			echo '<div class="optima-office-hours is-phone"><p class="optima-office-label">Phones answered</p><ul>';
			foreach ( $phone_lines as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( $single ) {
			foreach ( array( 'parking' => 'Parking', 'entrance' => 'Finding the door' ) as $key => $label ) {
				if ( '' !== $office[ $key ] ) {
					echo '<p class="optima-office-note"><span class="optima-office-label">' . esc_html( $label ) . '</span> ' . esc_html( $office[ $key ] ) . '</p>';
				}
			}
		}

		echo '<p class="optima-office-actions"><a class="optima-office-directions" href="' . esc_url( $directions ) . '" target="_blank" rel="noopener" data-optima-track="directions_click" data-optima-location="office_card" data-optima-label="' . esc_attr( $name ) . '">Get directions<span class="optima-sr"> ' . esc_html( sprintf( 'to %s (opens in a new tab)', $name ) ) . '</span></a></p>';
		echo '</article>';
	}

	/* ---------- Your business, Offices ---------- */

	/** Problems an operator should fix, per office id. */
	private static function problems( array $office ) {
		$out = array();
		if ( ! self::page_id( $office ) ) {
			$out[] = 'No office page found, so this office prints no schema. Set its page address, or publish a page slugged ' . $office['id'] . '.';
		}
		if ( '' === $office['place_id'] ) {
			$out[] = 'No Place ID: the map link and directions use the address only.';
		}
		if ( ! empty( $office['geo'] ) && ! self::precise( $office ) ) {
			$out[] = 'Latitude and longitude need at least 5 decimals; until then they stay out of the schema.';
		}
		return $out;
	}

	public static function render_tab() {
		$offices = self::all();
		$rows    = $offices;
		$rows[]  = self::clean( array() ); // One empty row: fill it to add an office.
		$s       = get_option( self::SETTINGS, array() );
		$zones   = timezone_identifiers_list();
		$labels  = array(
			'name'        => 'Office name',
			'id'          => 'Short id (letters and dashes)',
			'page'        => 'Office page address',
			'street'      => 'Street',
			'suite'       => 'Suite',
			'city'        => 'City',
			'state'       => 'State (two letters)',
			'zip'         => 'ZIP',
			'phone'       => 'Phone',
			'place_id'    => 'Google Place ID',
			'lat'         => 'Latitude',
			'lng'         => 'Longitude',
			'gbp_url'     => 'Business Profile address',
			'hours'       => 'Office hours (walk-in)',
			'phone_hours' => 'Phones answered',
			'timezone'    => 'Time zone',
			'photo'       => 'Photo address',
			'parking'     => 'Parking',
			'entrance'    => 'Finding the door',
			'courthouses' => 'Courthouses nearby (one per line)',
			'practices'   => 'Practice slugs (one per line)',
		);

		if ( ! $offices ) {
			echo '<section class="sh-box"><p class="sh-value">No offices yet. The site shows the one address on Details, and the schema has one business entry.</p><p class="sh-note">Add an office below, or list them under offices in the theme\'s optima-setup.json and Optima Build fills them in.</p></section>';
		} else {
			$todo = array();
			foreach ( $offices as $office ) {
				foreach ( self::problems( $office ) as $p ) {
					$todo[] = $office['name'] . ': ' . $p;
				}
			}
			if ( $todo ) {
				echo '<section class="sh-box sh-check is-warn"><p class="sh-label">To check</p><ul class="sh-list">';
				foreach ( $todo as $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
				echo '</ul></section>';
			}
		}

		echo '<form method="post" action="options.php">';
		settings_fields( 'optima_offices_group' );
		$name = self::OPTION;
		foreach ( $rows as $i => $row ) {
			$is_new = '' === $row['name'];
			echo '<section class="sh-box">';
			echo '<h2 class="sh-title">' . esc_html( $is_new ? 'Add an office' : $row['name'] ) . '</h2>';
			if ( $is_new ) {
				echo '<p class="sh-note">Fill in the name and address to add an office. Leave it empty to add none.</p>';
			}
			printf( '<input type="hidden" name="%s[%d][_form]" value="1">', esc_attr( $name ), (int) $i );
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $labels as $key => $label ) {
				$field = $name . '[' . $i . '][' . $key . ']';
				$id    = 'optima-office-' . $i . '-' . $key;
				echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
				if ( in_array( $key, array( 'hours', 'phone_hours', 'parking', 'entrance', 'courthouses', 'practices' ), true ) ) {
					if ( 'hours' === $key || 'phone_hours' === $key ) {
						$value = self::hours_text( $row[ $key ] );
					} elseif ( is_array( $row[ $key ] ) ) {
						$value = implode( "\n", $row[ $key ] );
					} else {
						$value = (string) $row[ $key ];
					}
					printf( '<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $field ), esc_textarea( $value ) );
					if ( 'hours' === $key ) {
						echo '<p class="description">One line per set of days: Mo-Fr 08:30-17:30, then Sa 09:00-13:00. 24-hour times. These are the hours the door is open.</p>';
					} elseif ( 'phone_hours' === $key ) {
						echo '<p class="description">24/7, or lines like the office hours. Leave empty to use Your business, Hours, Phones answered.</p>';
					}
				} elseif ( 'timezone' === $key ) {
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '"><option value="">Same as the site</option>';
					foreach ( $zones as $zone ) {
						echo '<option value="' . esc_attr( $zone ) . '"' . selected( $row['timezone'], $zone, false ) . '>' . esc_html( $zone ) . '</option>';
					}
					echo '</select>';
				} else {
					if ( 'lat' === $key || 'lng' === $key ) {
						$value = empty( $row['geo'] ) ? '' : (string) $row['geo'][ $key ];
					} else {
						$value = (string) $row[ $key ];
					}
					printf( '<input type="text" id="%s" name="%s" value="%s" class="regular-text" />', esc_attr( $id ), esc_attr( $field ), esc_attr( $value ) );
					if ( 'page' === $key ) {
						echo '<p class="description">For example /locations/tampa/. Empty: the page whose slug is the short id.</p>';
					} elseif ( 'lat' === $key ) {
						echo '<p class="description">At least 5 decimals, from the Business Profile pin.</p>';
					}
				}
				echo '</td></tr>';
			}
			echo '<tr><th scope="row">Main office</th><td><label><input type="radio" name="' . esc_attr( $name ) . '[_primary]" value="' . esc_attr( (string) $i ) . '"' . checked( $row['primary'], true, false ) . '> The firm\'s main office</label></td></tr>';
			if ( ! $is_new ) {
				echo '<tr><th scope="row">Remove</th><td><label><input type="checkbox" name="' . esc_attr( $name . '[' . $i . '][_remove]' ) . '" value="1"> Remove this office when I save</label></td></tr>';
			}
			echo '</tbody></table></section>';
		}

		echo '<section class="sh-box"><h2 class="sh-title">Map and languages</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="optima-map-key">Static map key</label></th><td>';
		if ( defined( 'OPTIMA_MAPS_KEY' ) && '' !== OPTIMA_MAPS_KEY ) {
			echo '<p class="sh-note">Set in wp-config (OPTIMA_MAPS_KEY).</p>';
		}
		printf( '<input type="text" id="optima-map-key" name="%s[map_key]" value="%s" class="regular-text" autocomplete="off" />', esc_attr( self::SETTINGS ), esc_attr( (string) ( is_array( $s ) ? ( $s['map_key'] ?? '' ) : '' ) ) );
		echo '<p class="description">Optional. A Google Maps Static API key limited to this site\'s address. With it, map cards show a map picture; without it they show a link. A map is never embedded.</p></td></tr>';
		$langs = self::languages();
		echo '<tr><th scope="row">Languages</th><td><p class="sh-value">' . esc_html( $langs ? implode( ', ', wp_list_pluck( self::language_nodes(), 'name' ) ) : 'Not set' ) . '</p>';
		echo '<p class="sh-note">From languages in the theme\'s optima-setup.json. The schema says the phones are answered in these, so list only languages someone really answers in.</p></td></tr>';
		echo '</tbody></table></section>';
		submit_button( 'Save' );
		echo '</form>';
	}
}
