<?php
/**
 * The site health strip: a few facts read from WordPress itself, each with a
 * plain verdict. Nothing is sent anywhere.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Health {

	const SCAN_KEY  = 'optima_hub_answer_scan_v2';
	const NAV_KEY   = 'optima_hub_nav_pages'; // Page IDs linked from the home page's menus, in order. // v2 (0.4.9): new page choice, so old counts are dropped.
	const SCAN_HOOK = 'optima_hub_answer_scan';
	const SEEN_KEY  = 'optima_hub_answer_seen';

	/** Host suffixes that only ever serve a host's staging or temporary copy of a site. */
	const STAGING_HOSTS = array( '.wpenginepowered.com', '.wpengine.com', '.kinsta.cloud', '.flywheelsites.com', '.pantheonsite.io', '.cloudwaysapps.com' );

	public static function boot(): void {
		add_action( self::SCAN_HOOK, array( __CLASS__, 'run_scan' ) );
		add_action( 'template_redirect', array( __CLASS__, 'watch_page' ), 99 );
	}

	/**
	 * Many hosts and firewalls stop a site from loading its own pages, which
	 * leaves the background scan stuck. So when a visitor opens a page, note
	 * whether it carries FAQPage, at most once a day per page. The scan falls
	 * back to these notes.
	 */
	public static function watch_page(): void {
		if ( is_admin() || ! is_page() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || is_preview() ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
			return;
		}
		$id    = (int) get_queried_object_id();
		$seen  = (array) get_option( self::SEEN_KEY, array() );
		$today = gmdate( 'Y-m-d' );
		if ( ! $id || ( isset( $seen[ $id ]['d'] ) && $today === $seen[ $id ]['d'] ) ) {
			return;
		}
		ob_start(
			static function ( $html ) use ( $id, $today ) {
				if ( is_string( $html ) && false !== stripos( $html, '</html>' ) ) {
					$seen        = (array) get_option( self::SEEN_KEY, array() );
					$seen[ $id ] = array( 'f' => (bool) preg_match( '/"@type"\s*:\s*(\[[^\]]*)?"FAQPage"/', $html ), 'd' => $today, 't' => time() );
					update_option( self::SEEN_KEY, $seen, false );
					if ( (int) get_option( 'page_on_front' ) === $id ) {
						self::note_nav( $html );
					}
				}
				return $html;
			}
		);
	}

	/** What visitors' page views showed in the last 30 days, or null when there are none. */
	private static function seen_scan(): ?array {
		$found   = 0;
		$checked = 0;
		$time    = 0;
		$cut     = strtotime( '-30 days' );
		$main    = array_map( 'intval', wp_list_pluck( self::main_pages(), 'ID' ) );
		foreach ( (array) get_option( self::SEEN_KEY, array() ) as $id => $row ) {
			$t = (int) ( $row['t'] ?? strtotime( (string) ( $row['d'] ?? '' ) ) );
			if ( ! $t || $t < $cut || ! in_array( (int) $id, $main, true ) || 'publish' !== get_post_status( (int) $id ) ) {
				continue;
			}
			++$checked;
			$found += empty( $row['f'] ) ? 0 : 1;
			$time   = max( $time, $t );
		}
		return $checked ? array( 'found' => $found, 'checked' => $checked, 'time' => $time ) : null;
	}

	/**
	 * Which key pages print FAQ schema. Themes and plugins all do FAQs their
	 * own way, so this looks at what the pages actually send: it loads up to
	 * 15 of the main pages from the site itself (in the background, through
	 * WP-Cron) and counts those carrying FAQPage. Cached for 12 hours.
	 *
	 * @return array{found:int, checked:int, time:int}|null Null while the first scan runs.
	 */
	public static function answer_scan(): ?array {
		$scan = get_transient( self::SCAN_KEY );
		if ( is_array( $scan ) && ! empty( $scan['checked'] ) ) {
			return $scan;
		}
		$seen = self::seen_scan();
		if ( $seen ) {
			return $seen;
		}
		if ( is_array( $scan ) ) {
			return $scan;
		}
		if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
			wp_schedule_single_event( time(), self::SCAN_HOOK );
			spawn_cron();
		}
		return null;
	}

	public static function forget_scan(): void {
		delete_transient( self::SCAN_KEY );
	}

	/**
	 * The pages that bring in business: the home page, then the pages in the
	 * site's menus (what visitors are steered to), then top-level pages and
	 * their children. Utility pages (legal, site map, search, the blog index)
	 * are left out. a client site, Oct 15: picking top-level pages alphabetically
	 * checked the site map, gallery and booking forms and missed every service page.
	 *
	 * @return WP_Post[]
	 */
	public static function main_pages(): array {
		$all   = get_pages( array( 'sort_column' => 'menu_order,post_title' ) );
		$byid  = array();
		foreach ( $all as $p ) {
			$byid[ (int) $p->ID ] = $p;
		}
		$order = array();
		$front = (int) get_option( 'page_on_front' );
		if ( $front ) {
			$order[] = $front;
		}
		foreach ( (array) get_nav_menu_locations() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
				if ( 'post_type' === ( $item->type ?? '' ) && 'page' === ( $item->object ?? '' ) ) {
					$order[] = (int) $item->object_id;
				}
			}
		}
		// Whatever the theme does (classic menus, Navigation blocks, a hand-written
		// header), the home page's own menu links are noted when it is loaded.
		foreach ( (array) get_option( self::NAV_KEY, array() ) as $id ) {
			$order[] = (int) $id;
		}
		// Block themes keep their menus as Navigation posts instead.
		$walk = static function ( array $blocks ) use ( &$walk, &$order ): void {
			foreach ( $blocks as $b ) {
				$a = $b['attrs'] ?? array();
				if ( in_array( $b['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true ) && 'page' === ( $a['type'] ?? '' ) && ! empty( $a['id'] ) ) {
					$order[] = (int) $a['id'];
				}
				if ( ! empty( $b['innerBlocks'] ) ) {
					$walk( $b['innerBlocks'] );
				}
			}
		};
		foreach ( get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 5 ) ) as $nav ) {
			$walk( parse_blocks( (string) $nav->post_content ) );
		}
		$top = array_filter( $all, static fn( $p ) => ! $p->post_parent );
		$ids = array_map( 'intval', wp_list_pluck( $top, 'ID' ) );
		foreach ( $top as $p ) {
			$order[] = (int) $p->ID;
		}
		foreach ( $all as $p ) {
			if ( in_array( (int) $p->post_parent, $ids, true ) ) {
				$order[] = (int) $p->ID;
			}
		}
		$skip  = '/^(privacy|terms|cookie|thank|sample-page|card|links|site-?map|search|accessibility)/';
		$posts = (int) get_option( 'page_for_posts' );
		$out   = array();
		foreach ( array_unique( $order ) as $id ) {
			// A password-protected page (a private partner page) is read by no
			// visitor and no AI tool, so it never counts against the site.
			if ( isset( $byid[ $id ] ) && $id !== $posts && ! preg_match( $skip, $byid[ $id ]->post_name ) && '' === (string) $byid[ $id ]->post_password ) {
				$out[] = $byid[ $id ];
			}
		}
		return array_slice( $out, 0, 15 );
	}

	/** Note the pages the home page's menus link to. */
	public static function note_nav( string $html ): void {
		if ( ! preg_match_all( '#<nav\b[^>]*>(.*?)</nav>#is', $html, $navs ) ) {
			return;
		}
		$ids = array();
		foreach ( $navs[1] as $nav ) {
			if ( ! preg_match_all( '#href=["\']([^"\'\#?]+)#i', $nav, $links ) ) {
				continue;
			}
			foreach ( $links[1] as $href ) {
				$id = url_to_postid( 0 === strpos( $href, '/' ) ? home_url( $href ) : $href );
				if ( $id && 'page' === get_post_type( $id ) ) {
					$ids[] = (int) $id;
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( $ids && get_option( self::NAV_KEY ) !== $ids ) {
			update_option( self::NAV_KEY, $ids, false );
		}
	}

	public static function run_scan(): void {
		$home  = wp_remote_get( home_url( '/' ), array( 'timeout' => 8, 'redirection' => 2, 'sslverify' => false, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		if ( ! is_wp_error( $home ) && 200 === (int) wp_remote_retrieve_response_code( $home ) ) {
			self::note_nav( (string) wp_remote_retrieve_body( $home ) );
		}
		$pages = self::main_pages();

		$found   = 0;
		$checked = 0;
		foreach ( $pages as $p ) {
			$r = wp_remote_get( get_permalink( $p ), array( 'timeout' => 8, 'redirection' => 2, 'sslverify' => false, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
			if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
				continue;
			}
			++$checked;
			if ( preg_match( '/"@type"\s*:\s*(\[[^\]]*)?"FAQPage"/', (string) wp_remote_retrieve_body( $r ) ) ) {
				++$found;
			}
		}
		set_transient( self::SCAN_KEY, array( 'found' => $found, 'checked' => $checked, 'time' => time() ), $checked ? 12 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
	}

	/**
	 * A staging copy: the site's own address (home_url, never the request's host,
	 * so a live site reached through its host's alias still reads as live) is a
	 * host staging address, or WordPress says the environment is not production.
	 */
	public static function is_staging(): bool {
		if ( in_array( wp_get_environment_type(), array( 'staging', 'development', 'local' ), true ) ) {
			return true;
		}
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		foreach ( self::STAGING_HOSTS as $suffix ) {
			if ( '' !== $host && str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a staging copy is already kept out of search without touching
	 * WordPress's own setting: the host's password protection (the site's own
	 * front page answers 401 to a visitor with no login), or a noindex robots
	 * directive from the theme or a plugin. The password probe is cached for
	 * an hour so the health screen never waits on it twice.
	 */
	public static function staging_shielded(): bool {
		$robots = (array) apply_filters( 'wp_robots', array() );
		if ( ! empty( $robots['noindex'] ) ) {
			return true;
		}
		$cached = get_transient( 'optima_hub_staging_auth' );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}
		$r      = wp_remote_head( home_url( '/' ), array( 'timeout' => 5, 'redirection' => 0, 'sslverify' => false ) );
		$locked = ! is_wp_error( $r ) && 401 === (int) wp_remote_retrieve_response_code( $r );
		set_transient( 'optima_hub_staging_auth', $locked ? 'yes' : 'no', HOUR_IN_SECONDS );
		return $locked;
	}

	/**
	 * Search engines allowed is right for a live site and wrong for a staging copy,
	 * which can end up in Google beside the real site. A staging site that already
	 * asks search engines to stay away reads as it always has.
	 */
	public static function search_engines(): array {
		$public  = '1' === (string) get_option( 'blog_public' );
		$staging = $public && self::is_staging();
		if ( $staging && self::staging_shielded() ) {
			return array(
				'label' => __( 'Search engines', 'optima-build' ),
				'value' => __( 'Kept out on staging', 'optima-build' ),
				'state' => 'good',
				'note'  => __( 'This staging copy is behind the host\'s password or tells search engines not to index it, so it stays out of Google. Search engine visibility in WordPress stays on, ready for the live site.', 'optima-build' ),
			);
		}
		if ( $staging ) {
			return array(
				'label' => __( 'Search engines', 'optima-build' ),
				'value' => __( 'Allowed on staging', 'optima-build' ),
				'state' => 'warn',
				'note'  => __( 'This is a staging copy, and search engines are allowed to index it. Turn on your host\'s password protection for staging. Leave Search engine visibility alone in WordPress: that setting can travel to the live site with the next copy.', 'optima-build' ),
			);
		}
		return array(
			'label' => __( 'Search engines', 'optima-build' ),
			'value' => $public ? __( 'Allowed', 'optima-build' ) : __( 'Hidden', 'optima-build' ),
			'state' => $public ? 'good' : 'warn',
			'note'  => $public ? __( 'Google can find the site.', 'optima-build' ) : __( 'Settings, Reading asks search engines to stay away. Right for staging, wrong for a live site.', 'optima-build' ),
		);
	}

	/** The repo every Optima plugin updates from (its Update URI). */
	const REPO = 'optimadigitalmedia/Optima-Plugins';

	/** Host names that only ever serve a copy on someone's own computer. */
	const LOCAL_HOSTS = array( '.local', '.test', '.localhost', '.invalid', '.example', '.lndo.site', '.ddev.site' );

	/**
	 * The live site: not a staging copy (see is_staging()) and not a copy on
	 * someone's own computer (localhost, *.local, *.test, a private IP).
	 */
	public static function is_live(): bool {
		if ( self::is_staging() ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host ) {
			return false;
		}
		foreach ( self::LOCAL_HOSTS as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return false;
			}
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return true;
	}

	/**
	 * The newest release the updater already knows about for one Optima plugin,
	 * read only from what it has cached (never a new call to GitHub).
	 *
	 * @return string|false|null The version; false when the release list was
	 *                           read and has no release for this plugin; null
	 *                           when nothing is cached yet.
	 */
	public static function known_release( string $slug, string $file ) {
		// 1. The updater's own answer for this plugin (6 hours).
		$cached = get_transient( 'optima_updater_' . $slug );
		if ( is_array( $cached ) ) {
			return array() === $cached ? false : (string) ( $cached['version'] ?? '' );
		}
		// 2. The shared release list every Optima plugin reads (6 hours).
		$feed = get_transient( 'optima_updater_feed' );
		if ( is_array( $feed ) && ! empty( $feed['ok'] ) && isset( $feed['releases'] ) ) {
			$best   = false;
			$prefix = $slug . '-v';
			foreach ( (array) $feed['releases'] as $r ) {
				if ( ! is_array( $r ) || ! empty( $r['draft'] ) || ! empty( $r['prerelease'] ) || 0 !== strpos( (string) ( $r['tag_name'] ?? '' ), $prefix ) ) {
					continue;
				}
				$zip = false;
				foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
					$zip = $zip || ( $slug . '.zip' === ( $a['name'] ?? '' ) );
				}
				$v = substr( (string) $r['tag_name'], strlen( $prefix ) );
				if ( $zip && ( false === $best || version_compare( $v, $best, '>' ) ) ) {
					$best = $v;
				}
			}
			return $best;
		}
		// 3. WordPress's own update check, which the updater answers for every Optima plugin.
		$plugins = get_site_transient( 'update_plugins' );
		foreach ( array( 'response', 'no_update' ) as $list ) {
			$item = is_object( $plugins ) && isset( $plugins->{$list}[ $file ] ) ? (object) $plugins->{$list}[ $file ] : null;
			if ( $item && ! empty( $item->new_version ) && false !== strpos( (string) ( $item->id ?? '' ), 'github.com/' . self::REPO . '/' ) ) {
				return (string) $item->new_version;
			}
		}
		return null;
	}

	/**
	 * Optima plugins running a test build: a version with a pre-release label
	 * (0.6.7-test.1), or one newer than any published release. Neither updates
	 * the normal way. a client site site, Oct 2026: a test zip of Optima Build was
	 * replaced by the next release and its unmerged features vanished, and a
	 * Forms Guard test build had no release to update to.
	 */
	public static function test_builds(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$notes = array();
		foreach ( get_plugins() as $file => $data ) {
			$uri    = (string) ( $data['UpdateURI'] ?? '' );
			// Only plugins that update from this repo. Client companion plugins (Optima Digital
			// author, no Update URI here) live in the client's own repo and never get a release.
			$ours   = 0 === strpos( $uri, 'https://github.com/' . self::REPO . '/' );
			$active = is_plugin_active( $file );
			$v      = (string) ( $data['Version'] ?? '' );
			if ( ! $ours || ! $active || '' === $v ) {
				continue;
			}
			$slug = '' !== $uri ? basename( untrailingslashit( $uri ) ) : dirname( $file );
			$name = (string) $data['Name'];
			if ( preg_match( '/^([0-9.]+)[-+]/', $v, $m ) ) {
				/* translators: 1: plugin name, 2: installed version, 3: release version */
				$notes[] = sprintf( __( '%1$s %2$s is a test build. It updates the normal way once %3$s is released.', 'optima-build' ), $name, $v, $m[1] );
				continue;
			}
			$known = self::known_release( $slug, $file );
			if ( false === $known ) {
				/* translators: 1: plugin name, 2: installed version */
				$notes[] = sprintf( __( '%1$s %2$s has no release yet, so it is a test build. It will not update until a release comes out.', 'optima-build' ), $name, $v );
			} elseif ( is_string( $known ) && '' !== $known && version_compare( $v, $known, '>' ) ) {
				/* translators: 1: plugin name, 2: installed version, 3: newest release */
				$notes[] = sprintf( __( '%1$s %2$s is newer than the newest release (%3$s), so it is a test build. It will not update the normal way until %2$s or newer is released.', 'optima-build' ), $name, $v, $known );
			}
		}
		if ( ! $notes ) {
			return array(
				'label' => __( 'Test builds', 'optima-build' ),
				'value' => __( 'None', 'optima-build' ),
				'state' => 'good',
				'note'  => __( 'Every Optima plugin is a released version, so each one updates itself.', 'optima-build' ),
			);
		}
		return array(
			'label'     => __( 'Test builds', 'optima-build' ),
			/* translators: %d: number of plugins */
			'value'     => sprintf( _n( '%d plugin', '%d plugins', count( $notes ), 'optima-build' ), count( $notes ) ),
			'state'     => 'warn',
			'note'      => implode( ' ', $notes ),
			'link'      => admin_url( 'plugins.php' ),
			'link_text' => __( 'Plugins', 'optima-build' ),
		);
	}

	/**
	 * Where each active Gravity Forms form sends its emails. Null when Gravity
	 * Forms is not running.
	 *
	 * Assumes GFAPI::get_forms() returns the active forms, each with a
	 * 'notifications' array whose items carry isActive, toType (email, field
	 * or routing) and to (addresses, merge tags, or a field id).
	 */
	public static function form_emails(): ?array {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_forms' ) ) {
			return null;
		}
		$forms   = (array) GFAPI::get_forms();
		$agency  = array_map( 'strtolower', (array) apply_filters( 'optima_build_agency_domains', array( 'optimadigitalmedia.com' ) ) );
		$live    = self::is_live();
		$admin   = (string) get_option( 'admin_email' );
		$lines   = array();
		$silent  = array();
		$ours    = array();
		foreach ( $forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}
			$title = (string) ( $form['title'] ?? '' );
			/* translators: 1: form title, 2: form id */
			$label = sprintf( __( '%1$s (form %2$d)', 'optima-build' ), $title, (int) ( $form['id'] ?? 0 ) );
			$to    = array();
			foreach ( (array) ( $form['notifications'] ?? array() ) as $n ) {
				if ( ! is_array( $n ) || ( isset( $n['isActive'] ) && ! $n['isActive'] ) ) {
					continue;
				}
				$type = (string) ( $n['toType'] ?? 'email' );
				$raw  = trim( (string) ( $n['to'] ?? '' ) );
				$who  = array();
				if ( 'routing' === $type ) {
					$who[] = __( 'routing rules', 'optima-build' );
				} elseif ( 'field' === $type ) {
					/* translators: %s: field id */
					$who[] = sprintf( __( 'the address in field %s', 'optima-build' ), $raw );
				} else {
					foreach ( array_filter( array_map( 'trim', explode( ',', str_replace( '{admin_email}', $admin, $raw ) ) ) ) as $addr ) {
						// A merge tag such as {Email:3} sends to what the visitor typed in field 3.
						/* translators: %s: field id */
						$who[] = preg_match( '/^\{[^:}]*:(\d+)[^}]*\}$/', $addr, $m ) ? sprintf( __( 'the address in field %s', 'optima-build' ), $m[1] ) : $addr;
					}
				}
				if ( ! $who ) {
					continue;
				}
				$agency_only = true;
				foreach ( $who as $addr ) {
					$domain      = strtolower( (string) substr( (string) strrchr( $addr, '@' ), 1 ) );
					$agency_only = $agency_only && '' !== $domain && in_array( $domain, $agency, true );
				}
				if ( $agency_only ) {
					$ours[ $label ] = true;
				}
				$to = array_merge( $to, $who );
			}
			if ( $to ) {
				$lines[] = $label . ': ' . implode( ', ', array_unique( $to ) ) . '.';
			} else {
				$silent[] = $label;
				/* translators: %s: form title and id */
				$lines[] = sprintf( __( '%s: no email is sent.', 'optima-build' ), $label );
			}
		}
		if ( ! $lines ) {
			return array(
				'label' => __( 'Form emails go to', 'optima-build' ),
				'value' => __( 'No forms', 'optima-build' ),
				'state' => 'good',
				'note'  => __( 'Gravity Forms has no active forms.', 'optima-build' ),
			);
		}
		$problem = $live && ( $silent || $ours );
		$intro   = '';
		if ( $live && $silent ) {
			$intro .= __( 'A form on this live site sends no email, so new requests can sit unseen.', 'optima-build' ) . ' ';
		}
		if ( $live && $ours ) {
			$intro .= __( 'A form on this live site emails only Optima, not the business.', 'optima-build' ) . ' ';
		}
		return array(
			'label'     => __( 'Form emails go to', 'optima-build' ),
			'value'     => $problem ? __( 'Check the forms', 'optima-build' ) : sprintf( _n( '%d form', '%d forms', count( $lines ), 'optima-build' ), count( $lines ) ),
			'state'     => $problem ? 'warn' : 'good',
			'note'      => $intro . implode( ' ', $lines ),
			'link'      => admin_url( 'admin.php?page=gf_edit_forms' ),
			'link_text' => __( 'Forms', 'optima-build' ),
		);
	}

	/**
	 * How long ago the review totals (each platform's rating and count, typed
	 * in on Review settings) were last saved. Optima Reviews 0.5.5 stamps each
	 * save. a client site site showed 17 Google reviews while Google showed 20. Null
	 * when Optima Reviews is not running or no totals are entered.
	 */
	public static function review_totals(): ?array {
		if ( ! class_exists( 'Optima_Reviews_Settings' ) ) {
			return null;
		}
		$profiles = (array) ( Optima_Reviews_Settings::get()['profiles'] ?? array() );
		$counted  = array_filter( $profiles, static fn( $p ) => is_array( $p ) && (int) ( $p['count'] ?? 0 ) > 0 );
		if ( ! $counted ) {
			return null;
		}
		$link  = array(
			'link'      => admin_url( 'admin.php?page=optima-reviews-settings' ),
			'link_text' => __( 'Review settings', 'optima-build' ),
		);
		$stamp = (int) get_option( 'optima_reviews_totals_updated', 0 );
		if ( ! $stamp ) {
			return array(
				'label' => __( 'Review totals', 'optima-build' ),
				'value' => __( 'Not known', 'optima-build' ),
				'state' => 'warn',
				'note'  => __( 'The site does not know when the review counts were last checked. Update the totals once to start tracking.', 'optima-build' ),
			) + $link;
		}
		$days = (int) floor( ( time() - $stamp ) / DAY_IN_SECONDS );
		$old  = $days > 30;
		return array(
			'label' => __( 'Review totals', 'optima-build' ),
			/* translators: %d: days */
			'value' => 0 === $days ? __( 'Today', 'optima-build' ) : sprintf( _n( '%d day ago', '%d days ago', $days, 'optima-build' ), $days ),
			'state' => $old ? 'warn' : 'good',
			'note'  => $old ? __( 'The review counts on the site have not been updated in over a month. Check each platform and type in its current rating and count.', 'optima-build' ) : __( 'The review counts on the site were updated in the last month.', 'optima-build' ),
		) + ( $old ? $link : array() );
	}

	/**
	 * @return array<int, array{label:string, value:string, state:string, note:string}>
	 *         state is good | warn | bad.
	 */
	public static function checks(): array {
		global $wp_version;
		$out = array();

		$updates = 0;
		$plugins = get_site_transient( 'update_plugins' );
		if ( is_object( $plugins ) && ! empty( $plugins->response ) ) {
			$updates += count( (array) $plugins->response );
		}
		$core = get_site_transient( 'update_core' );
		$core_update = false;
		if ( is_object( $core ) && ! empty( $core->updates ) ) {
			foreach ( $core->updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response ) {
					$core_update = true;
				}
			}
		}

		$out[] = array(
			'label' => __( 'WordPress', 'optima-build' ),
			'value' => (string) $wp_version,
			'state' => $core_update ? 'warn' : 'good',
			'note'  => $core_update ? __( 'A newer version is available.', 'optima-build' ) : __( 'Up to date.', 'optima-build' ),
		);
		$php_ok = version_compare( PHP_VERSION, '8.1', '>=' );
		$out[]  = array(
			'label' => __( 'PHP', 'optima-build' ),
			'value' => PHP_VERSION,
			'state' => $php_ok ? 'good' : 'warn',
			'note'  => $php_ok ? __( 'Supported.', 'optima-build' ) : __( 'Ask the host to move to PHP 8.1 or newer.', 'optima-build' ),
		);
		$out[] = array(
			'label' => __( 'Updates waiting', 'optima-build' ),
			'value' => (string) $updates,
			'state' => $updates ? 'warn' : 'good',
			'note'  => $updates ? __( 'Plugins with an update ready.', 'optima-build' ) : __( 'Nothing waiting.', 'optima-build' ),
		);
		$https = 0 === strpos( home_url(), 'https://' );
		$out[] = array(
			'label' => __( 'Security lock', 'optima-build' ),
			'value' => $https ? __( 'On', 'optima-build' ) : __( 'Off', 'optima-build' ),
			'state' => $https ? 'good' : 'bad',
			'note'  => $https ? __( 'The site loads over HTTPS.', 'optima-build' ) : __( 'The site address is not HTTPS.', 'optima-build' ),
		);
		$out[] = self::search_engines();

		if ( class_exists( 'Optima_Hub_Mail' ) ) {
			$out[] = Optima_Hub_Mail::check();
		}

		// Hidden upload fields, from Optima Forms Guard 0.3.0+.
		if ( class_exists( 'Optima_Forms_Guard' ) && method_exists( 'Optima_Forms_Guard', 'upload_check' ) ) {
			$out[] = Optima_Forms_Guard::upload_check();
		}

		$forms = self::form_emails();
		if ( $forms ) {
			$out[] = $forms;
		}
		$out[]   = self::test_builds();
		$reviews = self::review_totals();
		if ( $reviews ) {
			$out[] = $reviews;
		}

		$health = json_decode( (string) get_transient( 'health-check-site-status-result' ), true );
		if ( is_array( $health ) ) {
			$critical = (int) ( $health['critical'] ?? 0 );
			$advised  = (int) ( $health['recommended'] ?? 0 );
			$out[]    = array(
				'label' => __( 'Site Health', 'optima-build' ),
				'value' => $critical ? __( 'Needs attention', 'optima-build' ) : __( 'Good', 'optima-build' ),
				'state' => $critical ? 'bad' : ( $advised > 3 ? 'warn' : 'good' ),
				/* translators: 1: critical count, 2: recommended count */
				'note'  => sprintf( __( '%1$d critical, %2$d recommended (WordPress Site Health).', 'optima-build' ), $critical, $advised ),
			);
		} else {
			$out[] = array(
				'label' => __( 'Site Health', 'optima-build' ),
				'value' => __( 'Not checked yet', 'optima-build' ),
				'state' => 'warn',
				'note'  => __( 'Open Tools, Site Health once to run WordPress\'s own check.', 'optima-build' ),
			);
		}
		return $out;
	}

	/**
	 * How ready the site is for AI search (ChatGPT, Google AI answers,
	 * Perplexity, Claude). Each check reads WordPress itself; nothing is sent.
	 * Works with or without the AI search feature (optima-aeo) turned on, and
	 * counts what the theme or Optima Core already does.
	 *
	 * @return array<int, array{label:string, value:string, state:string, note:string, link?:string, link_text?:string}>
	 */
	public static function ai_checks(): array {
		$out    = array();
		$aeo    = class_exists( 'Optima_AEO' );
		$set    = $aeo ? admin_url( 'admin.php?page=optima-ai-search' ) : Optima_Hub_Admin::url( 'features' );
		$set_tx = $aeo ? __( 'AI search settings', 'optima-build' ) : __( 'Turn on AI search', 'optima-build' );

		// 1. Crawlers allowed.
		$file    = file_exists( ABSPATH . 'robots.txt' );
		$blocked = self::blocked_ai_bots();
		$public  = '1' === (string) get_option( 'blog_public' );
		if ( ! $public ) {
			$out[] = array( 'label' => __( 'AI crawlers', 'optima-build' ), 'value' => __( 'Hidden', 'optima-build' ), 'state' => 'warn', 'note' => __( 'Search engines are switched off in Settings, Reading, so AI tools are kept out too. Right for staging only.', 'optima-build' ) );
		} elseif ( $file ) {
			$safe  = '' === Optima_Hub_Robots::blocker();
			$out[] = array(
				'label'     => __( 'AI crawlers', 'optima-build' ),
				'value'     => $safe ? __( 'File in the way', 'optima-build' ) : __( 'Check by hand', 'optima-build' ),
				'state'     => 'warn',
				'note'      => $safe ? __( 'A robots.txt file on the server hides WordPress\'s version and everything Optima adds to it. Switching is safe: no rule would be lost.', 'optima-build' ) : __( 'A robots.txt file on the server overrides WordPress, and it has rules of its own. Review it before switching.', 'optima-build' ),
				'link'      => Optima_Hub_Robots::url(),
				'link_text' => $safe ? __( 'Switch to WordPress\'s robots.txt', 'optima-build' ) : __( 'Review the file', 'optima-build' ),
			);
		} elseif ( $blocked ) {
			/* translators: %s: crawler names */
			$out[] = array( 'label' => __( 'AI crawlers', 'optima-build' ), 'value' => __( 'Blocked', 'optima-build' ), 'state' => 'bad', 'note' => sprintf( __( 'robots.txt blocks %s.', 'optima-build' ), implode( ', ', $blocked ) ), 'link' => $set, 'link_text' => $set_tx );
		} else {
			$named = $aeo || function_exists( 'optima_llms_txt' );
			$out[] = array( 'label' => __( 'AI crawlers', 'optima-build' ), 'value' => __( 'Welcome', 'optima-build' ), 'state' => 'good', 'note' => $named ? __( 'robots.txt names the AI search tools and lets them in.', 'optima-build' ) : __( 'Nothing blocks them. Turning on AI search names them outright.', 'optima-build' ) );
		}

		// 2. The plain-text guide.
		$llms  = ( $aeo && Optima_AEO::settings()['llms'] ) || function_exists( 'optima_llms_txt' ) || file_exists( ABSPATH . 'llms.txt' );
		$out[] = array(
			'label' => __( 'Guide for AI tools', 'optima-build' ),
			'value' => $llms ? __( 'Published', 'optima-build' ) : __( 'Missing', 'optima-build' ),
			'state' => $llms ? 'good' : 'warn',
			'note'  => $llms ? __( 'A plain-text summary of the site is at /llms.txt.', 'optima-build' ) : __( 'There is no /llms.txt for AI tools to read.', 'optima-build' ),
		) + ( $llms ? array() : array( 'link' => $set, 'link_text' => $set_tx ) );

		// 3. Structured data.
		// Optima themes that print their own JSON-LD (Optima Digital's optima_head(), Recon's recon_head()) count too.
		$schema = class_exists( 'Optima_Schema' ) || function_exists( 'optima_head' ) || function_exists( 'recon_head' ) || current_theme_supports( 'optima-seo-head' ) || defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' );
		$out[]  = array(
			'label' => __( 'Structured data', 'optima-build' ),
			'value' => $schema ? __( 'On', 'optima-build' ) : __( 'Missing', 'optima-build' ),
			'state' => $schema ? 'good' : 'bad',
			'note'  => $schema ? __( 'Pages tell search and AI tools who the business is, in their own format.', 'optima-build' ) : __( 'No schema found. Turn on Business info and SEO.', 'optima-build' ),
		) + ( $schema ? array() : array( 'link' => Optima_Hub_Admin::url( 'features' ), 'link_text' => __( 'Your features', 'optima-build' ) ) );

		// 4. Business details complete (Optima Core sites).
		if ( class_exists( 'Optima_Core_Business' ) ) {
			$b       = (array) Optima_Core_Business::get();
			$missing = array_keys( array_filter( array( 'name' => $b['name'] ?? '', 'phone' => $b['phone'] ?? '', 'city' => $b['city'] ?? '' ), static fn( $v ) => '' === trim( (string) $v ) ) );
			$out[]   = array(
				'label' => __( 'Business details', 'optima-build' ),
				'value' => $missing ? __( 'Incomplete', 'optima-build' ) : __( 'Complete', 'optima-build' ),
				'state' => $missing ? 'warn' : 'good',
				/* translators: %s: missing fields */
				'note'  => $missing ? sprintf( __( 'Missing: %s. AI tools match these against Google and directories.', 'optima-build' ), implode( ', ', $missing ) ) : __( 'Name, phone and city are filled in.', 'optima-build' ),
			) + ( $missing ? array( 'link' => admin_url( 'admin.php?page=optima&tab=business' ), 'link_text' => __( 'Business details', 'optima-build' ) ) : array() );
		}

		// 5. Direct answers on pages: what the key pages actually print.
		$scan = self::answer_scan();
		$hub  = post_type_exists( 'faq' ) ? (int) ( wp_count_posts( 'faq' )->publish ?? 0 ) : 0;
		if ( null === $scan ) {
			$out[] = array( 'label' => __( 'Direct answers', 'optima-build' ), 'value' => __( 'Checking', 'optima-build' ), 'state' => 'warn', 'note' => __( 'Looking through the main pages for questions and answers. Refresh in a minute.', 'optima-build' ) );
		} elseif ( ! $scan['checked'] ) {
			$out[] = array( 'label' => __( 'Direct answers', 'optima-build' ), 'value' => __( 'Could not check', 'optima-build' ), 'state' => 'warn', 'note' => __( 'The site could not load its own pages (a password or firewall on staging does this). It tries again in 30 minutes.', 'optima-build' ) );
		} else {
			$good  = $scan['found'] >= min( 3, $scan['checked'] ) || $hub >= 5;
			$bits  = array(
				/* translators: 1: pages with answers, 2: pages checked */
				sprintf( __( '%1$d of %2$d main pages answer questions', 'optima-build' ), $scan['found'], $scan['checked'] ),
			);
			if ( $hub ) {
				/* translators: %d: FAQ count */
				$bits[] = sprintf( _n( '%d FAQ', '%d FAQs', $hub, 'optima-build' ), $hub );
			}
			/* translators: %s: time since the check */
			$bits[] = sprintf( __( 'checked %s ago', 'optima-build' ), human_time_diff( (int) $scan['time'] ) );
			$out[]  = array(
				'label' => __( 'Direct answers', 'optima-build' ),
				/* translators: 1: pages with answers, 2: pages checked */
				'value' => sprintf( __( '%1$d of %2$d pages', 'optima-build' ), $scan['found'], $scan['checked'] ),
				'state' => $good ? 'good' : 'warn',
				'note'  => ucfirst( implode( ', ', $bits ) ) . '.' . ( $good ? '' : ' ' . __( 'AI tools quote short, direct answers. Add questions to the pages that bring in business.', 'optima-build' ) ),
			) + ( $good ? array() : array( 'link' => $set, 'link_text' => $set_tx ) );
		}

		// 6. Fresh content.
		$last = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'orderby' => 'modified', 'order' => 'DESC' ) );
		if ( $last ) {
			$days  = (int) floor( ( time() - (int) get_post_modified_time( 'U', true, $last[0] ) ) / DAY_IN_SECONDS );
			$state = $days <= 60 ? 'good' : ( $days <= 180 ? 'warn' : 'bad' );
			/* translators: %d: days */
			$out[] = array( 'label' => __( 'Fresh content', 'optima-build' ), 'value' => 0 === $days ? __( 'Today', 'optima-build' ) : sprintf( _n( '%d day ago', '%d days ago', $days, 'optima-build' ), $days ), 'state' => $state, 'note' => 'good' === $state ? __( 'A post was published or updated recently.', 'optima-build' ) : __( 'AI tools favor current pages. Publish or refresh a post.', 'optima-build' ) );
		} else {
			$out[] = array( 'label' => __( 'Fresh content', 'optima-build' ), 'value' => __( 'No posts', 'optima-build' ), 'state' => 'warn', 'note' => __( 'Posts that answer customers\' questions are what AI tools cite most.', 'optima-build' ) );
		}

		// 7. Named authors with a bio.
		$recent = get_posts( array( 'numberposts' => 10, 'post_status' => 'publish' ) );
		if ( $recent ) {
			$with  = count( array_filter( $recent, static fn( $p ) => '' !== trim( (string) get_the_author_meta( 'description', (int) $p->post_author ) ) ) );
			$share = $with / count( $recent );
			$out[] = array(
				'label' => __( 'Real authors', 'optima-build' ),
				/* translators: 1: posts with a bio, 2: posts checked */
				'value' => sprintf( __( '%1$d of %2$d', 'optima-build' ), $with, count( $recent ) ),
				'state' => $share >= 0.8 ? 'good' : 'warn',
				'note'  => $share >= 0.8 ? __( 'Recent posts are signed by a person with a bio.', 'optima-build' ) : __( 'Add a short bio to each author\'s profile (Users, Profile, Biographical Info).', 'optima-build' ),
			) + ( $share >= 0.8 ? array() : array( 'link' => admin_url( 'users.php' ), 'link_text' => __( 'Users', 'optima-build' ) ) );
		}
		return $out;
	}

	/** @return string[] Key AI crawlers that robots.txt sends away from the whole site. */
	private static function blocked_ai_bots(): array {
		$public = '1' === (string) get_option( 'blog_public' );
		$text   = "User-agent: *\n" . ( $public ? "Disallow: /wp-admin/\n" : "Disallow: /\n" );
		$text   = (string) apply_filters( 'robots_txt', $text, $public );
		$groups = array();
		$agents = array();
		$rules  = false;
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( preg_replace( '/#.*/', '', $line ) );
			if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
				if ( $rules ) {
					$agents = array();
					$rules  = false;
				}
				$agents[] = strtolower( trim( $m[1] ) );
			} elseif ( preg_match( '/^(dis)?allow:\s*(.*)$/i', $line, $m ) ) {
				$rules = true;
				foreach ( $agents as $a ) {
					$groups[ $a ][] = array( '' !== $m[1], trim( $m[2] ) );
				}
			}
		}
		$blocked = array();
		foreach ( array( 'OAI-SearchBot', 'PerplexityBot', 'Claude-SearchBot', 'GPTBot' ) as $bot ) {
			$g = $groups[ strtolower( $bot ) ] ?? ( $groups['*'] ?? array() );
			$all_out = false;
			$root_in = false;
			foreach ( $g as list( $dis, $path ) ) {
				if ( $dis && '/' === $path ) {
					$all_out = true;
				}
				if ( ! $dis && '/' === $path ) {
					$root_in = true;
				}
			}
			if ( $all_out && ! $root_in ) {
				$blocked[] = $bot;
			}
		}
		return $blocked;
	}
}
