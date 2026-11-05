<?php
/**
 * Settings: one option, `optima_cards_settings`, layered over defaults built from the
 * business identity in Optima Core (name, phone, email, city, profile URLs). A
 * blank field always falls back, so a fresh install shows a working team card
 * with nothing typed in.
 *
 * @package Optima_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Cards_Settings {

	const OPTION      = 'optima_cards_settings'; // not 'optima_cards': the Optima Digital theme uses that name
	const MAX_PEOPLE  = 6;
	const MAX_LINKS   = 10;

	/**
	 * The business facts from Your business, Details (the optima_business option,
	 * Optima Core's or Optima Build's form). Read straight from the option so it
	 * works whichever of the two owns the form.
	 */
	public static function identity() {
		$id = get_option( 'optima_business', array() );
		$id = is_array( $id ) ? $id : array();
		return wp_parse_args(
			(array) $id,
			array( 'name' => '', 'phone' => '', 'email' => '', 'city' => '', 'region' => '', 'postal' => '', 'country' => '', 'same_as' => '' )
		);
	}

	public static function defaults() {
		$id = self::identity();
		return array(
			'enable_cards' => 1,
			'enable_links' => 1,
			'card_base'    => 'card',
			'links_base'   => 'links',
			'mode'         => 'dark',
			'accent'       => '#1272C4',
			'font'         => '',
			'name'         => $id['name'] ? $id['name'] : get_bloginfo( 'name' ),
			'phone'        => $id['phone'],
			'email'        => $id['email'], // never the WordPress admin email: on client sites that is often not the business
			'city'         => $id['city'],
			'region'       => $id['region'],
			'postal'       => $id['postal'],
			'socials'      => $id['same_as'],
			'footer'       => trim( $id['city'] . ( $id['region'] ? ', ' . $id['region'] : '' ) ),
			'note'         => get_bloginfo( 'description' ),
			'team_intro'   => get_bloginfo( 'description' ),
			'pick_heading' => 'Who would you like to talk to?',
			'photo_id'     => 0,
			'logo_id'        => 0,
			'share_card_id'  => 0,
			'share_links_id' => 0,
			'spot_heading' => '',
			'spot_text'    => '',
			'spot_label'   => '',
			'spot_sub'     => '',
			'spot_url'     => '',
			'people'       => array(),
			'links_intro'  => get_bloginfo( 'description' ),
			'links_latest' => 1,
			'links'        => array(
				array( 'label' => 'Contact us', 'sub' => '', 'url' => '/contact/', 'external' => 0 ),
				array( 'label' => 'Save our contact', 'sub' => 'Add us to your phone', 'url' => '/card/', 'external' => 0 ),
			),
		);
	}

	/** The business facts the card shows, typed once on Details (rule 45): card key => Details key. */
	const FACTS = array(
		'name'    => 'name',
		'phone'   => 'phone',
		'email'   => 'email',
		'city'    => 'city',
		'region'  => 'region',
		'postal'  => 'postal',
		'socials' => 'same_as',
	);

	/** Saved settings over the defaults; blanks fall back. Business facts come from Details first. */
	public static function get() {
		$d = self::defaults();
		$s = get_option( self::OPTION );
		if ( ! is_array( $s ) ) {
			return self::design( $d );
		}
		foreach ( $s as $k => $v ) {
			if ( in_array( $k, array( 'people', 'links' ), true ) ) {
				if ( is_array( $v ) ) {
					$d[ $k ] = $v;
				}
				continue;
			}
			if ( in_array( $k, array( 'enable_cards', 'enable_links', 'links_latest', 'photo_id', 'logo_id', 'share_card_id', 'share_links_id' ), true ) ) {
				$d[ $k ] = (int) $v;
				continue;
			}
			if ( '' !== $v && null !== $v ) {
				$d[ $k ] = $v;
			}
		}
		// A fact Details has wins; the value Cards saved before release 3 is used
		// only while Details is blank, so nothing typed here is ever lost.
		$id = self::identity();
		foreach ( self::FACTS as $card => $biz ) {
			if ( '' !== trim( (string) ( $id[ $biz ] ?? '' ) ) ) {
				$d[ $card ] = (string) $id[ $biz ];
			}
		}
		return self::design( $d );
	}

	/**
	 * The card dresses from the theme's DESIGN.md (rule 7): accent and body font
	 * from Optima Build's design tokens. A color or font Cards saved before
	 * release 3 is used only on a site without Optima Build.
	 */
	private static function design( array $d ) {
		if ( class_exists( 'Optima_Hub_Design' ) ) {
			$t      = Optima_Hub_Design::tokens();
			$accent = sanitize_hex_color( (string) ( $t['color']['accent'] ?? '' ) );
			$body   = trim( (string) ( $t['font']['body'] ?? '' ) );
			if ( $accent ) {
				$d['accent'] = $accent;
			}
			$d['font'] = '' !== $body ? '"' . str_replace( '"', '', $body ) . '", sans-serif' : '';
		}
		return $d;
	}

	/** Is People (Optima Team) running, so cards read from profiles (rule 47)? */
	public static function people_on() {
		return class_exists( 'Optima_Team' ) && post_type_exists( 'optima_team' );
	}

	/**
	 * Once (release 3): business facts typed on Cards and missing from Details
	 * move to Details, and each person slot is linked to the People profile with
	 * the same name or email. Slots with no profile stay as they were (and keep
	 * their card) until a profile exists. Nothing is deleted.
	 */
	public static function migrate() {
		if ( get_option( 'optima_cards_r3' ) ) {
			return;
		}
		$saved = get_option( self::OPTION );
		if ( is_array( $saved ) ) {
			$biz     = get_option( 'optima_business', array() );
			$biz     = is_array( $biz ) ? $biz : array();
			$changed = false;
			foreach ( self::FACTS as $card => $key ) {
				$old = trim( (string) ( $saved[ $card ] ?? '' ) );
				if ( '' !== $old && '' === trim( (string) ( $biz[ $key ] ?? '' ) ) ) {
					$biz[ $key ] = $old;
					$changed     = true;
				}
			}
			if ( $changed ) {
				update_option( 'optima_business', $biz );
			}
			if ( self::people_on() && ! empty( $saved['people'] ) && is_array( $saved['people'] ) ) {
				foreach ( $saved['people'] as $i => $p ) {
					if ( ! empty( $p['profile'] ) ) {
						continue;
					}
					$id = self::match_profile( (string) ( $p['name'] ?? '' ), (string) ( $p['email'] ?? '' ) );
					if ( $id ) {
						$saved['people'][ $i ]['profile'] = $id;
					}
				}
				update_option( self::OPTION, $saved, false );
			}
		}
		if ( self::people_on() || ! is_array( $saved ) || empty( $saved['people'] ) ) {
			update_option( 'optima_cards_r3', time(), false );
		}
	}

	/** The People profile with this name or email, or 0. */
	public static function match_profile( $name, $email ) {
		foreach ( get_posts( array( 'post_type' => 'optima_team', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => 200 ) ) as $post ) {
			$prof = (array) get_post_meta( $post->ID, '_optima_team', true );
			if ( ( '' !== $name && 0 === strcasecmp( trim( $name ), trim( $post->post_title ) ) ) || ( '' !== $email && 0 === strcasecmp( $email, (string) ( $prof['email'] ?? '' ) ) ) ) {
				return (int) $post->ID;
			}
		}
		return 0;
	}

	/** One card's person facts from their People profile: name, title, email, photo. */
	public static function from_profile( $id, array $entry, array $s ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'optima_team' !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		$prof = (array) get_post_meta( $post->ID, '_optima_team', true );
		return array_merge(
			$entry,
			array(
				'name'     => $post->post_title,
				'title'    => (string) ( $prof['role'] ?? '' ),
				'email'    => ! empty( $prof['email'] ) ? (string) $prof['email'] : '',
				'photo_id' => (int) get_post_thumbnail_id( $post->ID ),
				'slug'     => ! empty( $entry['slug'] ) ? $entry['slug'] : $post->post_name,
			)
		);
	}

	public static function phone_e164( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 10 === strlen( $digits ) ) {
			$digits = '1' . $digits;
		}
		return $digits ? '+' . $digits : '';
	}

	/** '/path/' stays on this site; anything else is used as-is. */
	public static function href( $url ) {
		return ( 0 === strpos( (string) $url, '/' ) ) ? home_url( $url ) : $url;
	}

	public static function card_url( $who = 'team', $suffix = '' ) {
		$s    = self::get();
		$path = '/' . $s['card_base'] . '/' . ( 'team' === $who ? '' : $who . '/' ) . ( $suffix ? $suffix . '/' : '' );
		return home_url( $path );
	}

	/**
	 * Spotlight under a card: an optional heading and note, then one link row
	 * (e.g. a product, a booking page, or a second business). Null when empty.
	 */
	public static function spot( $v ) {
		$out = array();
		foreach ( array( 'heading', 'text', 'label', 'sub', 'url' ) as $k ) {
			$out[ $k ] = (string) ( $v[ 'spot_' . $k ] ?? '' );
		}
		$out['href'] = '' !== $out['url'] ? self::href( $out['url'] ) : '';
		return '' === $out['heading'] . $out['text'] . $out['label'] ? null : $out;
	}

	/** Everything each card needs, keyed 'team' then each person's link name. */
	public static function people() {
		$s   = self::get();
		$out = array(
			'team' => array(
				'key'      => 'team',
				'name'     => $s['name'],
				'first'    => '',
				'last'     => '',
				'title'    => '',
				'email'    => $s['email'],
				'intro'    => $s['team_intro'],
				'pick'     => '',
				'tags'     => array(),
				'photo_id' => (int) $s['photo_id'],
				'photo'    => self::photo_url( (int) $s['photo_id'], true ),
				'url'      => self::card_url( 'team' ),
				'save'     => self::card_url( 'team', 'save' ),
				'share'    => self::card_url( 'team', 'share' ),
				'file'     => sanitize_title( $s['name'] ),
				'spot'     => self::spot( $s ),
			),
		);
		foreach ( (array) $s['people'] as $p ) {
			// A card linked to a People profile takes its name, title, email and photo from it (rule 47).
			if ( ! empty( $p['profile'] ) && self::people_on() ) {
				$p = self::from_profile( (int) $p['profile'], (array) $p, $s );
				if ( ! $p ) {
					continue;
				}
			}
			$slug = sanitize_title( $p['slug'] ?? '' );
			if ( '' === $slug || in_array( $slug, array( 'save', 'share', 'team' ), true ) || empty( $p['name'] ) ) {
				continue;
			}
			$parts        = preg_split( '/\s+/', trim( $p['name'] ) );
			$out[ $slug ] = array(
				'key'      => $slug,
				'name'     => $p['name'],
				'first'    => $parts[0],
				'last'     => count( $parts ) > 1 ? end( $parts ) : '',
				'title'    => $p['title'] ?? '',
				'email'    => ! empty( $p['email'] ) ? $p['email'] : $s['email'],
				'intro'    => $p['intro'] ?? '',
				'pick'     => $p['pick'] ?? '',
				'tags'     => array_values( array_filter( array_map( 'trim', explode( ',', $p['tags'] ?? '' ) ) ) ),
				'photo_id' => (int) ( $p['photo_id'] ?? 0 ),
				'photo'    => self::photo_url( (int) ( $p['photo_id'] ?? 0 ), false ),
				'url'      => self::card_url( $slug ),
				'save'     => self::card_url( $slug, 'save' ),
				'share'    => self::card_url( $slug, 'share' ),
				'file'     => sanitize_title( $p['name'] ),
				'spot'     => self::spot( $p ),
				'share_id' => (int) ( $p['share_id'] ?? 0 ),
			);
		}
		return $out;
	}

	/** Photo URL: the uploaded photo, else (team card only) the Site Icon, else ''. */
	/**
	 * The wide picture shown when a page is shared in a text or a post (1200 by 630 works
	 * everywhere). 'card' or 'links'; the links page falls back to the card's. Null when unset.
	 */
	public static function share_image( $which, $person_share_id = 0 ) {
		$s  = self::get();
		$id = 'links' === $which && $s['share_links_id'] ? $s['share_links_id'] : $s['share_card_id'];
		if ( 'person' === $which ) {
			$id = (int) $person_share_id;
		}
		$src = $id ? wp_get_attachment_image_src( (int) $id, 'full' ) : false;
		return $src ? array( 'url' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2] ) : null;
	}

	/** The logo for the links page and share page, or '' (they fall back to the main card's photo). */
	public static function logo_url() {
		$id = (int) self::get()['logo_id'];
		$u  = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		return $u ? (string) $u : '';
	}

	public static function photo_url( $photo_id, $team ) {
		if ( $photo_id ) {
			$u = wp_get_attachment_image_url( $photo_id, 'medium' );
			if ( $u ) {
				return $u;
			}
		}
		return $team ? (string) get_site_icon_url( 256 ) : '';
	}

	/** Profile URLs, one per line (defaults to Optima Core's same_as), as label => url for known networks. */
	public static function socials() {
		$known = array(
			'facebook.com'  => 'Facebook',
			'instagram.com' => 'Instagram',
			'linkedin.com'  => 'LinkedIn',
			'x.com'         => 'X',
			'twitter.com'   => 'X',
			'threads.net'   => 'Threads',
			'threads.com'   => 'Threads',
			'youtube.com'   => 'YouTube',
			'tiktok.com'    => 'TikTok',
			'pinterest.com' => 'Pinterest',
		);
		$out = array();
		foreach ( preg_split( '/\R+/', (string) self::get()['socials'] ) as $line ) {
			$url  = trim( $line );
			$host = preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $host && isset( $known[ $host ] ) && ! isset( $out[ $known[ $host ] ] ) ) {
				$out[ $known[ $host ] ] = $url;
			}
		}
		return $out;
	}

	/** Link rows for /links/, newest post first when switched on. */
	public static function links() {
		$s    = self::get();
		$rows = array();
		if ( $s['links_latest'] ) {
			$latest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'ignore_sticky_posts' => true ) );
			if ( $latest ) {
				$rows[] = array( 'label' => 'New on the blog', 'sub' => get_the_title( $latest[0] ), 'url' => get_permalink( $latest[0] ), 'external' => false, 'featured' => true );
			}
		}
		foreach ( (array) $s['links'] as $l ) {
			if ( empty( $l['label'] ) || empty( $l['url'] ) ) {
				continue;
			}
			$rows[] = array( 'label' => $l['label'], 'sub' => $l['sub'] ?? '', 'url' => self::href( $l['url'] ), 'external' => ! empty( $l['external'] ), 'featured' => false );
		}
		return $rows;
	}
}
