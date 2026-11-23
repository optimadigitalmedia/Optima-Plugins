<?php
/**
 * Purge the host's page cache after the site's look changes.
 *
 * When the active theme (or its parent) or any Optima plugin is updated or
 * replaced, cached pages still point at the old style and script versions
 * until someone purges by hand. Core purges WP Engine's caches (object cache,
 * page cache and CDN: every purge method WP Engine's "Clear All Caches"
 * button uses that the install has) and WP Rocket's, where a site runs it,
 * right after the update. A theme that purged on its own can drop that code.
 * Off for a site with the `optima_core_purge_after_update` filter (bool, $why).
 *
 * Cloudflare, by URL, when the site's wp-config.php names a zone and a token
 * that may only purge it:
 *   define( 'OPTIMA_CF_ZONE_ID', '...' );     // The zone's ID (Overview screen).
 *   define( 'OPTIMA_CF_PURGE_TOKEN', '...' ); // An API token with Zone, Cache Purge, Purge on that zone only.
 * Both live only in wp-config.php: never in the database, never printed, never
 * logged. Without them Cloudflare is not purged (purge it by hand).
 * Then Core asks Cloudflare to drop:
 *  - the home page and the theme's style and script files, after a theme or
 *    Optima plugin update;
 *  - an image's URLs (every size, and its WebP and AVIF twins), when Optima
 *    Optimize rewrites it or the file is replaced in place. Any plugin can ask
 *    with do_action( 'optima_core_purge_urls', $urls, $why ); the URLs are
 *    gathered and sent once, at the end of the request, 30 to a call.
 * A staging copy never calls Cloudflare. A failed call is written to the PHP
 * error log at most once an hour. Filter `optima_core_cloudflare_purge_urls`
 * (string[], $why) changes the list.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Cache {

	const LAST = 'optima_core_cache_purged'; // { at, why, hosts[] }.

	/** Cloudflare's purge endpoint; {zone} is the zone ID. */
	const CF_ENDPOINT = 'https://api.cloudflare.com/client/v4/zones/{zone}/purge_cache';

	/** URLs per purge call (Cloudflare's limit for purge by URL on every plan). */
	const CF_BATCH = 30;

	/** Transient: a failed Cloudflare purge was already logged this hour. */
	const CF_LOGGED = 'optima_core_cf_purge_logged';

	/** At most this many theme files are sent after an update. */
	const MAX_THEME_FILES = 120;

	/** URLs gathered in this request, sent at shutdown. @var array<string,string> url => why */
	private static $queue = array();

	public static function init() {
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 20, 2 );
		add_action( 'optima_core_purge_urls', array( __CLASS__, 'queue_urls' ), 10, 2 );
		// A file replaced in place (Enable Media Replace keeps the address).
		add_action( 'enable-media-replace-upload-done', array( __CLASS__, 'media_replaced' ), 10, 3 );
	}

	/**
	 * What an update touched that changes how pages look, or '' for nothing.
	 *
	 * @param array  $extra      The upgrader's hook_extra.
	 * @param string $installed  The folder an upload installed into (theme or plugin), when known.
	 * @param string $stylesheet The active theme's folder.
	 * @param string $template   The active parent theme's folder.
	 */
	public static function reason( array $extra, string $installed, string $stylesheet, string $template ) {
		$type   = (string) ( $extra['type'] ?? '' );
		$action = (string) ( $extra['action'] ?? '' );
		if ( 'theme' === $type ) {
			$themes = array_map( 'strval', (array) ( $extra['themes'] ?? array() ) );
			if ( isset( $extra['theme'] ) ) {
				$themes[] = (string) $extra['theme'];
			}
			if ( 'install' === $action && '' !== $installed ) {
				$themes[] = $installed;
			}
			return array_intersect( $themes, array( $stylesheet, $template ) ) ? 'theme' : '';
		}
		if ( 'plugin' === $type ) {
			$plugins = array_map( 'strval', (array) ( $extra['plugins'] ?? array() ) );
			if ( isset( $extra['plugin'] ) ) {
				$plugins[] = (string) $extra['plugin'];
			}
			if ( 'install' === $action && '' !== $installed ) {
				$plugins[] = $installed . '/';
			}
			foreach ( $plugins as $file ) {
				if ( 0 === strpos( $file, 'optima-' ) ) {
					return 'plugin';
				}
			}
		}
		return '';
	}

	public static function after_upgrade( $upgrader, $extra ) {
		$installed = '';
		if ( is_object( $upgrader ) && isset( $upgrader->result ) && is_array( $upgrader->result ) ) {
			$installed = (string) ( $upgrader->result['destination_name'] ?? '' );
		}
		$why = self::reason( is_array( $extra ) ? $extra : array(), $installed, (string) get_stylesheet(), (string) get_template() );
		if ( '' !== $why && (bool) apply_filters( 'optima_core_purge_after_update', true, $why ) ) {
			self::purge( $why );
		}
	}

	/** Purge every page cache this plugin can reach. Returns the names of the caches purged. */
	public static function purge( string $why = 'manual' ) {
		$hosts = array();
		if ( class_exists( 'WpeCommon' ) ) {
			// What WP Engine's "Clear All Caches" button calls (object cache, CDN,
			// page cache), plus the full page-cache purge where the install has it.
			// Each is called only when this install's WpeCommon has it.
			foreach ( array( 'purge_memcached', 'clear_maxcdn_cache', 'purge_varnish_cache', 'purge_varnish_cache_all' ) as $method ) {
				if ( method_exists( 'WpeCommon', $method ) ) {
					call_user_func( array( 'WpeCommon', $method ) );
				}
			}
			$hosts[] = 'WP Engine';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$hosts[] = 'WP Rocket';
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( self::cloudflare_ready() ) {
			$cf = self::cloudflare_purge( self::look_urls(), $why );
			if ( $cf['sent'] > 0 && 0 === $cf['failed'] ) {
				$hosts[] = 'Cloudflare';
			}
		}
		update_option(
			self::LAST,
			array(
				'at'    => time(),
				'why'   => $why,
				'hosts' => $hosts,
			),
			false
		);
		do_action( 'optima_core_caches_purged', $hosts, $why );
		return $hosts;
	}

	/* ---- Cloudflare, by URL ------------------------------------------- */

	/** A staging copy never calls Cloudflare. */
	private static function staging(): bool {
		return class_exists( 'Optima_Core_Staging' ) && Optima_Core_Staging::is_staging();
	}

	/**
	 * The zone and token from wp-config.php, or null. Never read from the
	 * database and never returned to a screen.
	 *
	 * @return array{zone:string, token:string}|null
	 */
	private static function credentials(): ?array {
		if ( ! defined( 'OPTIMA_CF_ZONE_ID' ) || ! defined( 'OPTIMA_CF_PURGE_TOKEN' ) ) {
			return null;
		}
		$zone  = strtolower( trim( (string) constant( 'OPTIMA_CF_ZONE_ID' ) ) );
		$token = trim( (string) constant( 'OPTIMA_CF_PURGE_TOKEN' ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $zone ) || '' === $token ) {
			return null;
		}
		return array( 'zone' => $zone, 'token' => $token );
	}

	/** Cloudflare purge is set up in wp-config.php and this is not a staging copy. */
	public static function cloudflare_ready(): bool {
		return null !== self::credentials() && ! self::staging();
	}

	/**
	 * The home page and the active theme's (and parent's) style and script
	 * files, as full URLs.
	 *
	 * @return string[]
	 */
	public static function look_urls(): array {
		$urls = array( home_url( '/' ) );
		if ( function_exists( 'get_stylesheet_uri' ) ) {
			$urls[] = get_stylesheet_uri();
		}
		$dirs = array();
		if ( function_exists( 'get_stylesheet_directory' ) ) {
			$dirs[ get_stylesheet_directory() ] = get_stylesheet_directory_uri();
			$dirs[ get_template_directory() ]   = get_template_directory_uri();
		}
		foreach ( $dirs as $dir => $uri ) {
			foreach ( self::asset_files( (string) $dir ) as $rel ) {
				$urls[] = rtrim( (string) $uri, '/' ) . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $rel );
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * A theme folder's .css and .js files, relative to it, skipping vendor
	 * folders. At most MAX_THEME_FILES.
	 *
	 * @return string[]
	 */
	public static function asset_files( string $dir ): array {
		$dir = rtrim( $dir, '/\\' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array();
		}
		$out = array();
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					static function ( $file ) {
						return ! ( $file->isDir() && in_array( $file->getFilename(), array( 'node_modules', 'vendor', '.git', 'src' ), true ) );
					}
				)
			);
			foreach ( $it as $file ) {
				if ( $file->isFile() && preg_match( '/\.(css|js)$/i', $file->getFilename() ) ) {
					$out[] = ltrim( substr( $file->getPathname(), strlen( $dir ) ), '/\\' );
					if ( count( $out ) >= self::MAX_THEME_FILES ) {
						break;
					}
				}
			}
		} catch ( Exception $e ) {
			return $out;
		}
		sort( $out );
		return $out;
	}

	/**
	 * optima_core_purge_urls: gather URLs for one Cloudflare purge at the end
	 * of the request.
	 *
	 * @param string[]|string $urls
	 */
	public static function queue_urls( $urls, $why = '' ): void {
		if ( ! self::cloudflare_ready() ) {
			return;
		}
		$was = (bool) self::$queue;
		foreach ( (array) $urls as $url ) {
			$url = trim( (string) $url );
			if ( '' !== $url && ! isset( self::$queue[ $url ] ) ) {
				self::$queue[ $url ] = '' !== (string) $why ? (string) $why : 'urls';
			}
		}
		if ( ! $was && self::$queue ) {
			add_action( 'shutdown', array( __CLASS__, 'send_queue' ) );
		}
	}

	/** shutdown: send what was gathered. */
	public static function send_queue(): void {
		$queue       = self::$queue;
		self::$queue = array();
		if ( $queue ) {
			self::cloudflare_purge( array_keys( $queue ), (string) reset( $queue ) );
		}
	}

	/** Enable Media Replace finished: the file kept its address, so drop the old copies. */
	public static function media_replaced( $target_url = '', $source_url = '', $post_id = 0 ): void {
		$urls = array_filter( array( (string) $target_url, (string) $source_url ) );
		$id   = (int) $post_id;
		if ( $id && function_exists( 'wp_get_attachment_url' ) ) {
			$urls = array_merge( $urls, self::attachment_urls( $id ) );
		}
		self::queue_urls( $urls, 'media' );
	}

	/**
	 * Every URL of one attachment: the file and each size, with any WebP and
	 * AVIF twins Optima Optimize wrote beside them.
	 *
	 * @return string[]
	 */
	public static function attachment_urls( int $id ): array {
		$main = (string) wp_get_attachment_url( $id );
		if ( '' === $main ) {
			return array();
		}
		$urls = array( $main );
		$meta = wp_get_attachment_metadata( $id );
		$base = trailingslashit( dirname( $main ) );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['original_image'] ) ) {
				$urls[] = $base . $meta['original_image'];
			}
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$urls[] = $base . $size['file'];
				}
			}
		}
		foreach ( $urls as $url ) {
			if ( preg_match( '/\.(jpe?g|png)$/i', $url ) ) {
				$urls[] = $url . '.webp';
				$urls[] = $url . '.avif';
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Ask Cloudflare to drop these URLs from its cache, 30 to a call. Only
	 * http(s) URLs on the site's own domain are sent.
	 *
	 * @param string[] $urls
	 * @return array{sent:int, failed:int} Calls made, and calls that failed.
	 */
	public static function cloudflare_purge( array $urls, string $why = '' ): array {
		$done = array( 'sent' => 0, 'failed' => 0 );
		$cred = self::credentials();
		if ( null === $cred || self::staging() ) {
			return $done;
		}
		$site = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$site = preg_replace( '/^www\./', '', $site );
		$keep = array();
		foreach ( (array) apply_filters( 'optima_core_cloudflare_purge_urls', $urls, $why ) as $url ) {
			$url  = trim( (string) $url );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( ! preg_match( '#^https?://#i', $url ) || '' === $host || '' === $site ) {
				continue;
			}
			if ( $host === $site || str_ends_with( $host, '.' . $site ) ) {
				$keep[ $url ] = true;
			}
		}
		$endpoint = str_replace( '{zone}', $cred['zone'], self::CF_ENDPOINT );
		foreach ( array_chunk( array_keys( $keep ), self::CF_BATCH ) as $chunk ) {
			$r = wp_safe_remote_post(
				$endpoint,
				array(
					'timeout' => 10,
					'headers' => array(
						'Authorization' => 'Bearer ' . $cred['token'],
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( array( 'files' => array_values( $chunk ) ) ),
				)
			);
			++$done['sent'];
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			$body = is_wp_error( $r ) ? null : json_decode( (string) wp_remote_retrieve_body( $r ), true );
			if ( 200 === $code && is_array( $body ) && ! empty( $body['success'] ) ) {
				continue;
			}
			++$done['failed'];
			self::log_failure( $r, $code, is_array( $body ) ? $body : array() );
		}
		if ( $done['sent'] ) {
			do_action( 'optima_core_cloudflare_purged', $done, $why );
		}
		return $done;
	}

	/** One line in the PHP error log, at most once an hour. Never the token. */
	private static function log_failure( $response, int $code, array $body ): void {
		if ( false !== get_transient( self::CF_LOGGED ) ) {
			return;
		}
		set_transient( self::CF_LOGGED, 1, HOUR_IN_SECONDS );
		$why = is_wp_error( $response ) ? $response->get_error_code() : 'HTTP ' . $code;
		$err = isset( $body['errors'][0]['code'] ) ? ' (Cloudflare error ' . (int) $body['errors'][0]['code'] . ')' : '';
		error_log( 'Optima Core: the Cloudflare cache purge failed: ' . $why . $err . '. Check OPTIMA_CF_ZONE_ID and the purge token\'s permissions. Not logged again for an hour.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
