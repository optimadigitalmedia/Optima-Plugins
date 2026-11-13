<?php
/**
 * The list of features, and the releases each one can be installed from.
 *
 * The list is the catalog the Release workflow publishes on the repo's
 * `version-list` branch, fetched and cached for 12 hours, so a new feature
 * shows up on every site without a hub release. It goes through the release
 * channels like a plugin does: each release run writes `catalog-test.json`
 * from the commit it releases, and only Promote copies that to `catalog.json`.
 * A site on the test channel reads the first, every other site the second.
 * The copy bundled with the plugin is the fallback when neither can be read.
 *
 * Releases are read from the same GitHub releases the updater uses: tags named
 * `<slug>-v<version>`, each carrying a `<slug>.zip` asset from this repo's own
 * release downloads (DOWNLOAD_PREFIX); an asset listed anywhere else is skipped.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Catalog {

	const REPO          = 'optimadigitalmedia/Optima-Plugins';
	const CATALOG_BASE  = 'https://raw.githubusercontent.com/optimadigitalmedia/Optima-Plugins/version-list/';
	const DOWNLOAD_PREFIX = 'https://github.com/' . self::REPO . '/releases/download/';
	const CATALOG_KEY   = 'optima_hub_catalog';
	const RELEASES_KEY  = 'optima_hub_releases';
	const CATALOG_TTL   = 12 * HOUR_IN_SECONDS;
	const RELEASES_TTL  = 6 * HOUR_IN_SECONDS;

	/**
	 * Every feature, keyed by plugin slug.
	 *
	 * @return array<string, array{slug:string, name:string, summary:string, tier:string, law:bool, requires:string[], hidden:bool}>
	 */
	public static function features(): array {
		$data = get_transient( self::catalog_key() );
		if ( ! is_array( $data ) ) {
			$data     = null;
			$response = wp_remote_get( self::catalog_url(), array( 'timeout' => 8, 'headers' => array( 'User-Agent' => 'OptimaBuild/' . OPTIMA_HUB_VERSION ) ) );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( is_array( $decoded ) && ! empty( $decoded['features'] ) ) {
					$data = $decoded;
				}
			}
			if ( null === $data ) {
				$local = json_decode( (string) file_get_contents( OPTIMA_HUB_DIR . 'catalog.json' ), true );
				$data  = is_array( $local ) ? $local : array( 'features' => array() );
				set_transient( self::catalog_key(), $data, HOUR_IN_SECONDS ); // Retry sooner after a failure.
			} else {
				set_transient( self::catalog_key(), $data, self::CATALOG_TTL );
			}
		}

		$out = array();
		// A theme listed in not_with_themes can still offer a feature by declaring
		// add_theme_support( '<slug>' ), e.g. Optima Digital and Recon opt in to
		// optima-core for Site Sync once they turn off its duplicate tags.
		foreach ( (array) ( $data['features'] ?? array() ) as $row ) {
			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
			if ( '' === $slug || 'optima-build' === $slug ) {
				continue;
			}
			$out[ $slug ] = array(
				'slug'     => $slug,
				'name'     => sanitize_text_field( (string) ( $row['name'] ?? $slug ) ),
				'summary'  => sanitize_text_field( (string) ( $row['summary'] ?? '' ) ),
				'tier'     => sanitize_key( (string) ( $row['tier'] ?? 'addon' ) ),
				'law'      => ! empty( $row['law'] ),
				'requires' => array_map( 'sanitize_key', (array) ( $row['requires'] ?? array() ) ),
				'hidden'   => ! empty( $row['client_specific'] ),
				'blocked'  => in_array( get_template(), array_map( 'sanitize_key', (array) ( $row['not_with_themes'] ?? array() ) ), true ) && ! current_theme_supports( $slug ),
				'block_note' => sanitize_text_field( (string) ( $row['not_with_note'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * The catalog address for this site's channel: catalog-test.json on the
	 * test channel, catalog.json (what Promote last sent out) on stable.
	 */
	public static function catalog_url(): string {
		return self::CATALOG_BASE . ( 'test' === Optima_Hub_Access::channel() ? 'catalog-test.json' : 'catalog.json' );
	}

	/** The catalog cache key for this site's channel (stable keeps the old key). */
	private static function catalog_key(): string {
		return self::CATALOG_KEY . ( 'test' === Optima_Hub_Access::channel() ? '__test' : '' );
	}

	/** Whether an address is one of this repo's release downloads. */
	public static function is_release_url( string $url ): bool {
		return 0 === strpos( $url, self::DOWNLOAD_PREFIX )
			&& 1 === preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.zip$#', substr( $url, strlen( self::DOWNLOAD_PREFIX ) ) )
			&& false === strpos( $url, '..' );
	}

	/** A sha256 as 64 lower-case hex characters ("sha256:" prefix allowed), or ''. */
	public static function clean_sha256( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		if ( 0 === strpos( $value, 'sha256:' ) ) {
			$value = substr( $value, 7 );
		}
		return 1 === preg_match( '/^[0-9a-f]{64}$/', $value ) ? $value : '';
	}

	/**
	 * Installable releases for one plugin, newest first. On the test channel
	 * test releases (GitHub pre-releases) are in the list too, flagged
	 * 'prerelease'; on stable they never are.
	 *
	 * @return array<int, array{version:string, package:string, prerelease:bool, sha256:string}>
	 */
	public static function releases( string $slug ): array {
		$all = self::all_releases();
		return isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : array();
	}

	/** True when the last release lookup could not reach GitHub. */
	public static function unreachable(): bool {
		return ! empty( self::all_releases()['_unreachable'] );
	}

	/** @return array<string, array<int, array{version:string, package:string}>> */
	/** The cache key for this site's channel (stable keeps the old key). */
	private static function releases_key(): string {
		return self::RELEASES_KEY . ( 'test' === Optima_Hub_Access::channel() ? '__test' : '' );
	}

	private static function all_releases(): array {
		$with_test = 'test' === Optima_Hub_Access::channel();
		$cached    = get_transient( self::releases_key() );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out  = array();
		$data = array();
		// First choice: the version list the plugin updater already downloaded,
		// then the same file fetched here. Both skip GitHub's API, whose
		// 60-calls-an-hour limit shared hosts run out of.
		$shared = get_transient( 'optima_updater_feed' );
		if ( is_array( $shared ) && ! empty( $shared['ok'] ) && ! empty( $shared['releases'] ) ) {
			$data = (array) $shared['releases'];
		} else {
			$response = wp_remote_get( 'https://raw.githubusercontent.com/' . self::REPO . '/version-list/releases.json', array( 'timeout' => 10, 'headers' => array( 'User-Agent' => 'OptimaBuild/' . OPTIMA_HUB_VERSION ) ) );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				$data    = is_array( $decoded ) ? $decoded : array();
			}
		}
		// Fallback: the API, up to three pages of 100. GitHub sorts by tag name,
		// not date, so a plugin's releases can be spread across pages.
		$use_api = ! $data;
		for ( $page = 1; $use_api && $page <= 3; $page++ ) {
			$response = wp_remote_get(
				'https://api.github.com/repos/' . self::REPO . '/releases?per_page=100&page=' . $page,
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'OptimaBuild/' . OPTIMA_HUB_VERSION,
					),
				)
			);
			$batch = ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
			if ( ! is_array( $batch ) ) {
				break;
			}
			$data = array_merge( $data, $batch );
			if ( count( $batch ) < 100 ) {
				break;
			}
		}
		if ( ! $data ) {
			set_transient( self::releases_key(), array( '_unreachable' => true ), 15 * MINUTE_IN_SECONDS );
			return array( '_unreachable' => true );
		}
		foreach ( $data as $release ) {
			if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ( ! empty( $release['prerelease'] ) && ! $with_test ) ) {
				continue;
			}
			if ( ! preg_match( '/^(optima-[a-z0-9-]+)-v(\d[\w.\-]*)$/', (string) ( $release['tag_name'] ?? '' ), $m ) ) {
				continue;
			}
			foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
				if ( ( $asset['name'] ?? '' ) === $m[1] . '.zip' && ! empty( $asset['browser_download_url'] ) && self::is_release_url( (string) $asset['browser_download_url'] ) ) {
					$out[ $m[1] ][] = array(
						'version'    => $m[2],
						'package'    => (string) $asset['browser_download_url'],
						'prerelease' => ! empty( $release['prerelease'] ),
						// The version list writes sha256; GitHub's API (the fallback) writes digest.
						'sha256'     => self::clean_sha256( $asset['sha256'] ?? ( $asset['digest'] ?? '' ) ),
					);
				}
			}
		}
		foreach ( $out as &$list ) {
			usort( $list, static fn( $a, $b ) => version_compare( $b['version'], $a['version'] ) );
		}
		unset( $list );
		set_transient( self::releases_key(), $out, self::RELEASES_TTL );
		return $out;
	}

	public static function flush(): void {
		delete_transient( self::CATALOG_KEY );
		delete_transient( self::CATALOG_KEY . '__test' );
		delete_transient( self::RELEASES_KEY );
		delete_transient( self::RELEASES_KEY . '__test' );
	}
}
