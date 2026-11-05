<?php
/**
 * The client setup file.
 *
 * A Optima client theme can carry `optima-setup.json` with the client's own
 * facts: team, review page, chat, hours. When the matching feature is on,
 * this fills it in so nobody retypes it in the dashboard. Each section runs
 * again only when its contents change (a new theme version), and it only
 * fills blanks: anything someone already set in WordPress stays as it is.
 *
 * Format (every section optional):
 * {
 *   "team":    [ { "name", "role", "bio", "email", "military_branch", "photo", "order" } ],
 *   "reviews": { "business", "headline", "ask", "photo", "card_headline", "google_review_url",
 *                "background", "text_color", "accent", "heading_font", "body_font", "label_font" },
 *   "chat":    { "name", "team", "notify", "book_url", "work_url", "photo_1", "photo_2" },
 *   "hours":   { "confirmed": true, "days": { "tuesday": [ ["09:00", "17:00"] ] } },
 *   "analytics": { "gtm": "GTM-XXXXXXX" },
 *   "features":  { "use": [ "optima-core", "optima-forms-guard" ], "skip": [ "optima-chat" ] },
 *   "dashboards": { "recon", "analytics", "search_console", "business_profile", "host" }
 *                (https addresses; the doors on Optima -> Overview, shown only when named),
 *   "cards":   { "mode", "accent", "font", "name", "phone", "email", "city", "region", "postal",
 *                "footer", "note", "team_intro", "links_intro", "photo", "spot_heading", "spot_text",
 *                "pick_heading", "spot_label", "spot_sub", "spot_url", "logo", "share_card", "share_links",
 *                "people": [ { "name", "slug", "title", "email", "intro", "pick", "tags", "photo", "share" } ],
 *                "links": [ { "label", "sub", "url" } ] },
 *   "seo":     { "site": {...}, "pages": { "/": {...} }, "schema": {...} }
 * }
 * The "seo" section is the theme's SEO head and JSON-LD as data, for Optima Core
 * (docs/plugin-first.md, section 3a). Unlike the other sections it is a mirror:
 * it is copied whole into the option Optima Core reads, every time the theme
 * file or the theme version changes, and on "Fill from theme again". Editors'
 * own SEO box values live elsewhere and are never touched.
 * "photo" values are a path inside the theme (assets/img/team-member.webp) or a full https:// address.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Setup {

	const FILE  = 'optima-setup.json';
	const DONE  = 'optima_hub_setup_done';  // section => hash of what was applied.
	const MEDIA = 'optima_hub_setup_media'; // theme path => attachment ID.
	const SEO_OPTION = 'optima_core_seo_setup'; // the seo section, as Optima Core reads it (Optima_SEO_Setup::OPTION).

	public static function boot(): void {
		add_action( 'admin_init', array( __CLASS__, 'run' ), 30 );
	}

	/** The theme's setup file (child theme first), or an empty array. */
	public static function data(): array {
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			$path = trailingslashit( $dir ) . self::FILE;
			if ( is_readable( $path ) ) {
				$json = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
				return is_array( $json ) ? $json : array();
			}
		}
		return array();
	}

	/**
	 * Which features this client needs, from the setup file's "features" section:
	 * "use" in install order, "skip" for ones this client doesn't need. Features
	 * the file doesn't name are neither.
	 *
	 * @return array{use: string[], skip: string[]}
	 */
	public static function feature_plan(): array {
		$f    = (array) ( self::data()['features'] ?? array() );
		$use  = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $f['use'] ?? array() ) ) ) ) );
		$skip = array_values( array_diff( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $f['skip'] ?? array() ) ) ) ), $use ) );
		return array( 'use' => $use, 'skip' => $skip );
	}

	public static function run(): void {
		if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
			return;
		}
		$data = self::data();
		if ( ! $data ) {
			return;
		}
		$done = (array) get_option( self::DONE, array() );
		$jobs = array(
			'team'    => array( __CLASS__, 'team' ),
			'reviews' => array( __CLASS__, 'reviews' ),
			'chat'    => array( __CLASS__, 'chat' ),
			'hours'   => array( __CLASS__, 'hours' ),
			'analytics' => array( __CLASS__, 'analytics' ),
			'cards'   => array( __CLASS__, 'cards' ),
			'contact' => array( __CLASS__, 'contact' ),
			'seo'     => array( __CLASS__, 'seo' ),
		);
		// The theme dropped its seo section: Optima Core goes back to its own defaults.
		if ( empty( $data['seo'] ) && isset( $done['seo'] ) ) {
			delete_option( self::SEO_OPTION );
			unset( $done['seo'] );
			update_option( self::DONE, $done, false );
		}
		$changed = false;
		foreach ( $jobs as $section => $job ) {
			if ( empty( $data[ $section ] ) || ! is_array( $data[ $section ] ) ) {
				continue;
			}
			// The reviews section reruns once after 0.4.4, when untouched defaults started counting as blank.
			$hash = md5( (string) wp_json_encode( $data[ $section ] ) . ( 'reviews' === $section ? '|defaults-blank' : '' ) . ( 'seo' === $section ? '|' . self::theme_version() : '' ) );
			if ( ( $done[ $section ] ?? '' ) === $hash ) {
				continue;
			}
			// A job returns false while its feature is off, so it runs once the feature is turned on.
			if ( call_user_func( $job, $data[ $section ] ) ) {
				$done[ $section ] = $hash;
				$changed          = true;
			}
		}
		if ( $changed ) {
			update_option( self::DONE, $done, false );
		}
	}

	/* ---------- sections ---------- */

	private static function team( array $people ): bool {
		if ( ! post_type_exists( 'optima_team' ) || ! class_exists( 'Optima_Team' ) ) {
			return false;
		}
		foreach ( $people as $p ) {
			$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$found = get_posts(
				array(
					'post_type'   => 'optima_team',
					'title'       => $name,
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			$id    = $found ? (int) $found[0] : 0;
			if ( ! $id ) {
				$id = (int) wp_insert_post(
					array(
						'post_type'    => 'optima_team',
						'post_status'  => 'publish',
						'post_title'   => $name,
						'post_content' => (string) ( $p['bio'] ?? '' ),
						'menu_order'   => (int) ( $p['order'] ?? 0 ),
					)
				);
				if ( ! $id ) {
					continue;
				}
			}
			// Fill only the blanks of an existing profile.
			$profile = Optima_Team::profile( $id );
			$email   = (string) ( $p['email'] ?? '' );
			$user    = '' !== $email ? get_user_by( 'email', $email ) : false;
			$add     = array(
				'role'            => (string) ( $p['role'] ?? '' ),
				'bio'             => (string) ( $p['bio'] ?? '' ),
				'email'           => $email,
				'user'            => $user ? $user->ID : 0,
				'military_branch' => (string) ( $p['military_branch'] ?? '' ),
			);
			foreach ( $add as $key => $value ) {
				if ( empty( $profile[ $key ] ) && ! empty( $value ) ) {
					$profile[ $key ] = $value;
				}
			}
			// The sanitizer takes these two lists as one-per-line text.
			foreach ( array( 'memberships', 'same_as' ) as $list ) {
				$profile[ $list ] = implode( "\n", (array) $profile[ $list ] );
			}
			update_post_meta( $id, Optima_Team::META, Optima_Team::sanitize_profile( $profile ) );
			if ( ! has_post_thumbnail( $id ) && ! empty( $p['photo'] ) ) {
				$att = self::attachment( (string) $p['photo'] );
				if ( $att ) {
					set_post_thumbnail( $id, $att );
				}
			}
		}
		return true;
	}

	private static function reviews( array $in ): bool {
		if ( ! class_exists( 'Optima_Reviews_Requests' ) ) {
			return false;
		}
		$keys = array( 'business', 'headline', 'ask', 'card_headline', 'background', 'text_color', 'accent', 'heading_font', 'body_font', 'label_font' );
		$fill = array_intersect_key( $in, array_flip( $keys ) );
		if ( ! empty( $in['photo'] ) ) {
			$fill['photo'] = self::url( (string) $in['photo'] );
		}
		// A setting still on the plugin's own default counts as blank, so the client's look wins
		// on sites where the review page was saved before the theme carried it (a client site, Oct 15).
		self::fill_option( Optima_Reviews_Requests::OPTION, array_map( 'sanitize_text_field', $fill ), Optima_Reviews_Requests::defaults() );

		$write = esc_url_raw( (string) ( $in['google_review_url'] ?? '' ), array( 'https' ) );
		if ( '' !== $write && class_exists( 'Optima_Reviews_Settings' ) ) {
			$saved = get_option( Optima_Reviews_Settings::OPTION, array() );
			$saved = is_array( $saved ) ? $saved : array();
			if ( empty( $saved['profiles']['google']['write_url'] ) ) {
				$saved['profiles']['google']['write_url'] = $write;
				update_option( Optima_Reviews_Settings::OPTION, $saved );
			}
		}
		return true;
	}

	private static function chat( array $in ): bool {
		if ( ! class_exists( 'Optima_Chat' ) ) {
			return false;
		}
		$fill = array();
		foreach ( array( 'name', 'team', 'notify', 'book_url', 'work_url', 'photo_1', 'photo_2' ) as $key ) {
			if ( empty( $in[ $key ] ) ) {
				continue;
			}
			$value = (string) $in[ $key ];
			if ( 'notify' === $key ) {
				$value = sanitize_email( $value );
			} elseif ( 0 === strpos( $key, 'photo_' ) ) {
				$value = self::url( $value );
			} elseif ( '_url' === substr( $key, -4 ) ) {
				$value = esc_url_raw( $value );
			} else {
				$value = sanitize_text_field( $value );
			}
			$fill[ $key ] = $value;
		}
		self::fill_option( Optima_Chat::OPTION, $fill );
		return true;
	}

	/** Call buttons: phone, text number, booking link and the labels, filled only where blank or still the default. */
	private static function contact( array $in ): bool {
		if ( ! class_exists( 'Optima_Contact' ) ) {
			return false;
		}
		$fill = array();
		foreach ( array( 'phone', 'text', 'label', 'title', 'subtitle', 'text_note', 'book_note' ) as $key ) {
			if ( isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ) {
				$fill[ $key ] = sanitize_text_field( (string) $in[ $key ] );
			}
		}
		if ( isset( $in['book_url'] ) && is_scalar( $in['book_url'] ) ) {
			$fill['book_url'] = esc_url_raw( (string) $in['book_url'] );
		}
		self::fill_option( Optima_Contact::OPTION, $fill, Optima_Contact::defaults() );
		return true;
	}

	private static function hours( array $in ): bool {
		if ( ! class_exists( 'Optima_Hours' ) ) {
			return false;
		}
		// Hours are all or nothing: only fill a site that has none saved yet.
		$saved = get_option( Optima_Hours::OPTION, array() );
		if ( is_array( $saved ) && ! empty( $saved['days'] ) ) {
			return true;
		}
		$days = array();
		foreach ( (array) ( $in['days'] ?? array() ) as $day => $ranges ) {
			$day = sanitize_key( (string) $day );
			$out = array();
			foreach ( (array) $ranges as $r ) {
				if ( is_array( $r ) && 2 === count( $r ) ) {
					$out[] = array( sanitize_text_field( (string) $r[0] ), sanitize_text_field( (string) $r[1] ) );
				}
			}
			$days[ $day ] = array(
				'open'   => (bool) $out,
				'ranges' => $out,
			);
		}
		update_option(
			Optima_Hours::OPTION,
			array(
				'confirmed' => ! empty( $in['confirmed'] ),
				'bar'       => 'off',
				'days'      => $days,
			)
		);
		return true;
	}

	private static function analytics( array $in ): bool {
		if ( ! class_exists( 'Optima_Analytics' ) ) {
			return false;
		}
		$gtm = strtoupper( trim( (string) ( $in['gtm'] ?? '' ) ) );
		if ( Optima_Analytics::valid_gtm( $gtm ) ) {
			self::fill_option( Optima_Analytics::OPTION, array( 'gtm' => $gtm ) );
		}
		return true;
	}

	private static function cards( array $in ): bool {
		if ( ! class_exists( 'Optima_Cards_Settings' ) ) {
			return false;
		}
		$keys = array( 'mode', 'accent', 'font', 'name', 'phone', 'email', 'city', 'region', 'postal', 'footer', 'note', 'team_intro', 'links_intro', 'pick_heading', 'spot_heading', 'spot_text', 'spot_label', 'spot_sub', 'spot_url' );
		$fill = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $in[ $key ] ) || '' === (string) $in[ $key ] ) {
				continue;
			}
			$value = (string) $in[ $key ];
			if ( 'email' === $key ) {
				$value = sanitize_email( $value );
			} elseif ( in_array( $key, array( 'note', 'team_intro', 'links_intro', 'spot_text' ), true ) ) {
				$value = sanitize_textarea_field( $value );
			} else {
				$value = sanitize_text_field( $value );
			}
			$fill[ $key ] = $value;
		}
		// The card's untouched defaults count as blank. So does the site admin's email: on client
		// sites that is often whoever set WordPress up, not the business (a client site, Oct 15).
		$defaults          = Optima_Cards_Settings::defaults();
		$defaults['email'] = (string) get_option( 'admin_email' );
		self::fill_option( Optima_Cards_Settings::OPTION, $fill, $defaults );

		$saved = get_option( Optima_Cards_Settings::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$dirty = false;
		// The photo, logo and share pictures. A picture this file put there earlier (from the
		// theme) still counts as blank, so a new theme version can swap it; one picked by hand stays.
		$ours = array_map( 'intval', array_values( (array) get_option( self::MEDIA, array() ) ) );
		foreach ( array( 'photo' => 'photo_id', 'logo' => 'logo_id', 'share_card' => 'share_card_id', 'share_links' => 'share_links_id' ) as $from => $key ) {
			$current = (int) ( $saved[ $key ] ?? 0 );
			if ( ( ! $current || in_array( $current, $ours, true ) ) && ! empty( $in[ $from ] ) ) {
				$att = self::attachment( (string) $in[ $from ] );
				if ( $att && $att !== $current ) {
					$saved[ $key ] = $att;
					$dirty         = true;
				}
			}
		}
		// People cards, filled only while there are none.
		if ( ! empty( $in['people'] ) && is_array( $in['people'] ) && empty( $saved['people'] ) ) {
			$people = array();
			foreach ( $in['people'] as $p ) {
				$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}
				$row = array(
					'name'     => $name,
					'slug'     => sanitize_title( (string) ( $p['slug'] ?? $name ) ),
					'title'    => sanitize_text_field( (string) ( $p['title'] ?? '' ) ),
					'email'    => sanitize_email( (string) ( $p['email'] ?? '' ) ),
					'intro'    => sanitize_textarea_field( (string) ( $p['intro'] ?? '' ) ),
					'pick'     => sanitize_textarea_field( (string) ( $p['pick'] ?? '' ) ),
					'tags'     => sanitize_text_field( (string) ( $p['tags'] ?? '' ) ),
					'photo_id' => ! empty( $p['photo'] ) ? self::attachment( (string) $p['photo'] ) : 0,
					'share_id' => ! empty( $p['share'] ) ? self::attachment( (string) $p['share'] ) : 0,
				);
				foreach ( array( 'heading', 'text', 'label', 'sub', 'url' ) as $k ) {
					$row[ 'spot_' . $k ] = sanitize_text_field( (string) ( $p[ 'spot_' . $k ] ?? '' ) );
				}
				$people[] = $row;
			}
			if ( $people ) {
				$saved['people'] = $people;
				$dirty           = true;
			}
		}
		// The links list is filled only while it is empty or still the plugin's two starter rows.
		if ( ! empty( $in['links'] ) && is_array( $in['links'] ) && ( empty( $saved['links'] ) || $saved['links'] == $defaults['links'] ) ) { // phpcs:ignore Universal.Operators.StrictComparisons -- compares row arrays.
			$rows = array();
			foreach ( $in['links'] as $row ) {
				$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
				$url   = (string) ( $row['url'] ?? '' );
				if ( '' === $label || '' === $url ) {
					continue;
				}
				$rows[] = array(
					'label'    => $label,
					'sub'      => sanitize_text_field( (string) ( $row['sub'] ?? '' ) ),
					'url'      => 0 === strpos( $url, '/' ) ? sanitize_text_field( $url ) : esc_url_raw( $url ),
					'external' => 0 === strpos( $url, '/' ) ? 0 : 1,
				);
			}
			if ( $rows ) {
				$saved['links'] = $rows;
				$dirty          = true;
			}
		}
		if ( $dirty ) {
			update_option( Optima_Cards_Settings::OPTION, $saved );
		}
		return true;
	}

	/**
	 * The SEO head and JSON-LD facts, for Optima Core. Stored whole (cleaned),
	 * replacing the last copy: this is the theme's data, not anything an editor
	 * typed. Per-page SEO box values are post meta and stay as they are.
	 */
	private static function seo( array $in ): bool {
		if ( ! class_exists( 'Optima_SEO_Head' ) ) {
			return false;
		}
		$out = array();
		if ( isset( $in['site'] ) && is_array( $in['site'] ) ) {
			$out['site'] = self::seo_fields( $in['site'] );
		}
		if ( isset( $in['pages'] ) && is_array( $in['pages'] ) ) {
			$out['pages'] = array();
			foreach ( $in['pages'] as $path => $page ) {
				if ( ! is_array( $page ) ) {
					continue;
				}
				$key = self::seo_path( (string) $path );
				if ( '' !== $key ) {
					$out['pages'][ $key ] = self::seo_fields( $page );
				}
			}
		}
		if ( isset( $in['schema'] ) && is_array( $in['schema'] ) ) {
			$out['schema'] = self::clean_tree( $in['schema'] );
		}
		$out['theme'] = array(
			'stylesheet' => get_stylesheet(),
			'version'    => self::theme_version(),
			'filled'     => gmdate( 'c' ),
		);
		// Large (every page's JSON-LD), and read only on the front end: never autoloaded.
		update_option( self::SEO_OPTION, $out, false );
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( self::SEO_OPTION, false );
		}
		if ( class_exists( 'Optima_SEO_Setup' ) ) {
			Optima_SEO_Setup::flush();
		}
		return true;
	}

	/** One site or page entry: known keys only, each cleaned for what it holds. */
	private static function seo_fields( array $in ): array {
		$text = array( 'title', 'title_format', 'site_name', 'og_title', 'og_type', 'og_type_post', 'twitter', 'robots', 'locale', 'image_alt' );
		$long = array( 'description', 'default_description', 'og_description' );
		$addr = array( 'canonical', 'og_url', 'image', 'default_image', 'production_url' );
		$out  = array();
		foreach ( $in as $key => $value ) {
			$key = (string) $key;
			// false means "none" (no canonical, no og:url, no description): kept as false.
			if ( false === $value && ( in_array( $key, $addr, true ) || in_array( $key, $long, true ) ) ) {
				$out[ $key ] = false;
			} elseif ( 'robots_exact' === $key ) {
				$out[ $key ] = (bool) $value;
			} elseif ( 'print' === $key && is_array( $value ) ) {
				$out['print'] = array();
				foreach ( $value as $tag => $on ) {
					$tag = sanitize_key( (string) $tag );
					if ( '' !== $tag && ( is_bool( $on ) || is_string( $on ) ) ) {
						$out['print'][ $tag ] = is_bool( $on ) ? $on : sanitize_key( $on );
					}
				}
			} elseif ( 'schema_blocks' === $key && is_array( $value ) ) {
				$out['schema_blocks'] = array_values( array_map( 'absint', array_filter( $value, 'is_numeric' ) ) );
			} elseif ( in_array( $key, $text, true ) && is_scalar( $value ) ) {
				// Titles keep their spaces exactly as the theme printed them (" | Example Firm" on an archive,
				// a doubled space inside); tags and line breaks still go.
				$out[ $key ] = in_array( $key, array( 'title', 'og_title' ), true )
					? (string) preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', wp_check_invalid_utf8( (string) $value ) ) ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- wp_strip_all_tags() would trim the spaces we keep.
					: sanitize_text_field( (string) $value );
			} elseif ( in_array( $key, $long, true ) && is_scalar( $value ) ) {
				$out[ $key ] = sanitize_textarea_field( (string) $value );
			} elseif ( in_array( $key, $addr, true ) && is_scalar( $value ) ) {
				$out[ $key ] = self::seo_address( (string) $value );
			} elseif ( in_array( $key, array( 'image_width', 'image_height' ), true ) && is_numeric( $value ) ) {
				$out[ $key ] = absint( $value );
			} elseif ( 'meta' === $key && is_array( $value ) ) {
				$out['meta'] = array();
				foreach ( $value as $tag ) {
					if ( ! is_array( $tag ) || ! isset( $tag['content'] ) || ! is_scalar( $tag['content'] ) ) {
						continue;
					}
					$attr = isset( $tag['property'] ) ? 'property' : 'name';
					$name = preg_replace( '/[^A-Za-z0-9:_\-.]/', '', (string) ( $tag[ $attr ] ?? '' ) );
					if ( '' !== $name ) {
						$out['meta'][] = array(
							$attr     => $name,
							'content' => sanitize_text_field( (string) $tag['content'] ),
						);
					}
				}
			} elseif ( 'schema' === $key && is_array( $value ) ) {
				$out['schema'] = array_values( array_filter( self::clean_tree( $value ), 'is_array' ) );
			}
		}
		return $out;
	}

	/** A page key: "/about/" style, a prefix pattern ending in "*" ("/blog/*"), or a special key (":search"). */
	private static function seo_path( string $path ): string {
		$path = trim( $path );
		// Requests without a path of their own (search, 404, posts page, archives) keep their special key.
		if ( in_array( $path, array( ':search', ':404', ':posts', ':category', ':tag', ':date', ':author', ':archive' ), true ) ) {
			return $path;
		}
		if ( '' === $path || preg_match( '#^[a-z]+://#i', $path ) ) {
			$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		}
		$path = '/' . ltrim( preg_replace( '#[^A-Za-z0-9/_\-.~%*]#', '', $path ), '/' );
		if ( '*' === substr( $path, -1 ) ) {
			return $path;
		}
		return '/' === $path ? '/' : trailingslashit( $path );
	}

	/** A full https address, a site path ("/about/"), or a theme path ("assets/og/home.png"). */
	private static function seo_address( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		return str_replace( array( '..', '\\' ), '', sanitize_text_field( $value ) );
	}

	/** JSON-LD as plain data: keys and strings stripped of tags, numbers and true/false kept. */
	private static function clean_tree( array $in ): array {
		$out = array();
		foreach ( $in as $key => $value ) {
			$key = is_int( $key ) ? $key : preg_replace( '/[^A-Za-z0-9@_\-:]/', '', (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = self::clean_tree( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$out[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$out[ $key ] = sanitize_textarea_field( $value );
			}
		}
		return $out;
	}

	/** The active theme's version (child theme first). */
	private static function theme_version(): string {
		return (string) wp_get_theme()->get( 'Version' );
	}

	/* ---------- helpers ---------- */

	/** Set keys that are missing or blank in an option; leave everything else. */
	/**
	 * Fill blank settings. A setting that still holds the plugin's own default
	 * (from $defaults) also counts as blank, since nobody chose it.
	 */
	private static function fill_option( string $option, array $fill, array $defaults = array() ): void {
		$saved = get_option( $option, null );
		// A new option starts from the plugin's defaults (enabled included); a saved one is never re-enabled.
		$dirty = null === $saved && $defaults;
		$saved = is_array( $saved ) ? $saved : ( null === $saved ? $defaults : array() );
		foreach ( $fill as $key => $value ) {
			$current = isset( $saved[ $key ] ) ? (string) $saved[ $key ] : '';
			$default = isset( $defaults[ $key ] ) && is_scalar( $defaults[ $key ] ) ? (string) $defaults[ $key ] : null;
			$blank   = '' === $current || ( null !== $default && 0 === strcasecmp( $current, $default ) );
			if ( '' !== $value && $blank && 0 !== strcasecmp( $current, (string) $value ) ) {
				$saved[ $key ] = $value;
				$dirty         = true;
			}
		}
		if ( $dirty ) {
			update_option( $option, $saved );
		}
	}

	/** A theme path becomes a Media Library item once; a full address is used as is. */
	private static function attachment( string $photo ): int {
		if ( preg_match( '#^https?://#', $photo ) ) {
			$id = attachment_url_to_postid( $photo );
			return $id ? (int) $id : 0;
		}
		$path  = ltrim( str_replace( '..', '', $photo ), '/' );
		$media = (array) get_option( self::MEDIA, array() );
		if ( ! empty( $media[ $path ] ) && get_post( (int) $media[ $path ] ) ) {
			return (int) $media[ $path ];
		}
		$file = get_stylesheet_directory() . '/' . $path;
		if ( ! is_readable( $file ) ) {
			$file = get_template_directory() . '/' . $path;
		}
		if ( ! is_readable( $file ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$up = wp_upload_bits( basename( $file ), null, (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
		if ( ! empty( $up['error'] ) ) {
			return 0;
		}
		$type = wp_check_filetype( $up['file'] );
		$id   = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $type['type'],
				'post_title'     => sanitize_file_name( pathinfo( $file, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			),
			$up['file']
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
		$media[ $path ] = (int) $id;
		update_option( self::MEDIA, $media, false );
		return (int) $id;
	}

	private static function url( string $photo ): string {
		if ( preg_match( '#^https?://#', $photo ) ) {
			return esc_url_raw( $photo );
		}
		$id = self::attachment( $photo );
		return $id ? (string) wp_get_attachment_url( $id ) : '';
	}
}
