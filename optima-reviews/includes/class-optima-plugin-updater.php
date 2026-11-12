<?php
/**
 * Self-updating for the Optima plugin suite.
 *
 * Uses WordPress core's own update mechanism for plugins hosted outside
 * wordpress.org: a plugin declares an `Update URI:` header, and core then runs
 * the `update_plugins_<hostname>` filter for it on every update check. There is
 * no third-party updater library and nothing extra to install on a client site.
 * Updates appear on the normal Plugins screen, and WordPress's own per-plugin
 * "enable auto-updates" toggle works as it does for any other plugin.
 *
 * Releases live on the Optima Plugins repo. Each plugin is tagged and released
 * on its own, as `<slug>-v<version>`, carrying a `<slug>.zip` asset.
 *
 * Release channels: a release can be published as a GitHub pre-release (a
 * test release). A site on the stable channel (the default) never sees those
 * and only updates to normal releases. A site on the test channel (the
 * agency's own staging site) also sees test releases and takes the newest
 * version from either list, so it gets each release before the client sites.
 * The channel is Optima Build's setting (Build, Updates, Release channel) or
 * the OPTIMA_HUB_CHANNEL constant in wp-config.php; without Optima Build a
 * site is always on stable.
 *
 * Download safety: a package is only ever taken from this repo's own release
 * downloads (DOWNLOAD_PREFIX); an offer pointing anywhere else is dropped, and
 * an update of an Optima plugin from anywhere else is refused. When the version
 * list carries a sha256 for the zip (the Release workflow records one for each
 * release), the downloaded file must match it before WordPress unpacks it; a
 * mismatch stops the install. A release listed without a sha256 (one published
 * before checksums were recorded) still installs, and the site logs that it did.
 *
 * This file ships identically inside every plugin in the suite so each one can
 * update itself even when the others are inactive. The first copy to load wins;
 * they are released together and kept in sync. The source copy is
 * tools/updater/class-optima-plugin-updater.php, and the Lint workflow fails
 * when a plugin's copy differs from it.
 *
 * @package Optima_Plugins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every Optima plugin ships this file. The class sits inside a conditional on
// purpose: a top-level class can be bound by OPcache when the file loads, even
// behind an early return, which crashed a site when another copy had already
// declared it ("Cannot declare class Optima_Plugin_Updater"). Inside the `if`,
// only the first copy declares it and every other copy is skipped.
if ( ! class_exists( 'Optima_Plugin_Updater', false ) ) :

/**
 * Points one plugin at its latest GitHub release.
 */
final class Optima_Plugin_Updater {

	/**
	 * Repository holding the suite, as owner/name.
	 *
	 * @var string
	 */
	const REPO = 'optimadigitalmedia/Optima-Plugins';

	/**
	 * How long a release lookup is cached. WordPress checks for updates roughly
	 * twice a day, and the unauthenticated GitHub API allows 60 requests an hour
	 * per IP, so this keeps a busy multisite well inside the limit.
	 *
	 * @var int
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * One shared copy of the release list for every Optima plugin on the site.
	 * Each plugin used to download the same list on its own, one after
	 * another, and a page that checked them all could wait past the host's
	 * 60-second limit (a 502 "Bad gateway" on WP Engine). Now the first plugin
	 * to ask downloads it once and the rest read it.
	 * @var string
	 */
	const FEED_KEY = 'optima_updater_feed';

	/** The version list the Release workflow publishes (see .github/workflows/release.yml). */
	const FEED_URL = 'https://raw.githubusercontent.com/optimadigitalmedia/Optima-Plugins/version-list/releases.json';

	/**
	 * Every package must start with this: the repo's own release downloads.
	 * A version list that names any other address is ignored for that release.
	 */
	const DOWNLOAD_PREFIX = 'https://github.com/' . self::REPO . '/releases/download/';

	/** This copy pins download addresses and checks each zip's sha256 (Optima Build can look for it). */
	const CHECKSUMS = true;

	/**
	 * Plugin files (folder/file.php) this copy serves, so a download for one of
	 * them from anywhere but DOWNLOAD_PREFIX can be refused.
	 * @var array<string, string>
	 */
	private static $plugins = array();

	/**
	 * Whether the download check is hooked yet (once per request, however many
	 * plugins boot this copy).
	 * @var bool
	 */
	private static $download_hooked = false;

	/**
	 * The shared list for this request, so each copy reads it at most once.
	 * @var array|null
	 */
	private static $feed = null;

	/**
	 * Whether the last download worked. A failed one is retried after 15
	 * minutes instead of being remembered as "no update" for six hours.
	 * @var bool
	 */
	private static $feed_ok = true;

	/** This copy knows about release channels (Optima Build's Site health checks for it). */
	const CHANNELS = true;

	/**
	 * Absolute path to the plugin's main file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Plugin folder name, e.g. "optima-seo".
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Constructor.
	 *
	 * @param string $file Absolute path to the plugin's main file.
	 * @param string $slug Plugin folder name.
	 */
	private function __construct( string $file, string $slug ) {
		$this->file = $file;
		$this->slug = $slug;
	}

	/**
	 * Wire a plugin up to the release feed.
	 *
	 * Call from the plugin's main file:
	 * `Optima_Plugin_Updater::boot( __FILE__, 'optima-seo' );`
	 *
	 * @param string $file Absolute path to the plugin's main file (__FILE__).
	 * @param string $slug Plugin folder name.
	 * @return void
	 */
	public static function boot( string $file, string $slug ): void {
		$updater = new self( $file, $slug );

		// Core derives this filter name from the Update URI header's hostname.
		add_filter( 'update_plugins_github.com', array( $updater, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $updater, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $updater, 'flush' ), 10, 0 );
		add_filter( 'auto_update_plugin', array( $updater, 'auto_update' ), 10, 2 );

		self::$plugins[ plugin_basename( $file ) ] = $slug;
		if ( ! self::$download_hooked ) {
			self::$download_hooked = true;
			add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 4 );
		}
	}

	/**
	 * Install this plugin's updates automatically, so a release reaches the
	 * whole roster on WordPress's own twice-daily check with nobody logging in
	 * to press Update. Only ever answers for this plugin's own update offers;
	 * every other plugin's decision passes through untouched.
	 *
	 * Opt a site out by defining OPTIMA_DISABLE_AUTO_UPDATES as true in
	 * wp-config.php; updates then wait on the Plugins screen as before.
	 *
	 * @param bool|null $update Whether to auto-update, as decided so far.
	 * @param object    $item   The update offer, carrying the id set by check().
	 * @return bool|null
	 */
	public function auto_update( $update, $item ) {
		if ( ! isset( $item->id ) || 'github.com/' . self::REPO . '/' . $this->slug !== $item->id ) {
			return $update;
		}
		if ( defined( 'OPTIMA_DISABLE_AUTO_UPDATES' ) && OPTIMA_DISABLE_AUTO_UPDATES ) {
			return $update;
		}
		// Only ever install on its own a package from this repo's releases.
		if ( ! isset( $item->package ) || ! self::is_release_url( (string) $item->package ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether an address is one of this repo's release downloads.
	 *
	 * @param string $url Package address.
	 * @return bool
	 */
	public static function is_release_url( string $url ): bool {
		if ( 0 !== strpos( $url, self::DOWNLOAD_PREFIX ) ) {
			return false;
		}
		// <tag>/<file>.zip and nothing else: no dot segments, query or fragment.
		return 1 === preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.zip$#', substr( $url, strlen( self::DOWNLOAD_PREFIX ) ) )
			&& false === strpos( $url, '..' );
	}

	/**
	 * Check a download before WordPress unpacks it (the upgrader_pre_download
	 * filter, hooked once for every Optima plugin).
	 *
	 * - A package from this repo's releases with a sha256 in the version list:
	 *   downloaded here and checked. A mismatch deletes the file and stops the
	 *   install with an error.
	 * - The same without a sha256 (an older release): WordPress downloads it as
	 *   usual, and the site logs that no checksum was checked.
	 * - An update of an Optima plugin from any other web address: refused.
	 * - Anything else (other plugins, themes, a zip uploaded by hand): untouched.
	 *
	 * @param mixed  $reply      False, or what an earlier filter returned.
	 * @param string $package    The package address or path.
	 * @param mixed  $upgrader   The WP_Upgrader running.
	 * @param array  $hook_extra What is being upgraded.
	 * @return mixed False to let WordPress download, a file path, or WP_Error.
	 */
	public static function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) || '' === $package ) {
			return $reply;
		}
		if ( ! self::is_release_url( $package ) ) {
			$plugin = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
			if ( '' !== $plugin && isset( self::$plugins[ $plugin ] ) && preg_match( '#^[a-z][a-z0-9+.-]*://#i', $package ) ) {
				self::log( 'Refused an update of ' . self::$plugins[ $plugin ] . ' from ' . $package . ': not a release of ' . self::REPO . '.' );
				return new WP_Error( 'optima_updater_source', 'This update was refused: Optima plugins only install from the Optima Plugins releases.' );
			}
			return $reply;
		}

		$expected = self::expected_sha256( $package );
		if ( '' === $expected ) {
			self::log( 'No sha256 listed for ' . $package . '; installing it without a checksum check (a release from before checksums were recorded).' );
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
			$upgrader->skin->feedback( 'downloading_package', $package );
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$actual = (string) hash_file( 'sha256', $file );
		if ( ! hash_equals( $expected, $actual ) ) {
			wp_delete_file( $file );
			self::log( 'Checksum mismatch for ' . $package . ': the version list says ' . $expected . ', the download is ' . $actual . '. Install stopped.' );
			return new WP_Error( 'optima_updater_checksum', 'This update was stopped: the downloaded file does not match the checksum published with the release. Nothing was changed. Try again later; if it keeps failing, tell the web team.' );
		}
		return $file;
	}

	/**
	 * The sha256 the version list gives for one package address, or '' when it
	 * gives none (or the address is not in the list).
	 *
	 * @param string $package Package address.
	 * @return string Lower-case hex, or ''.
	 */
	public static function expected_sha256( string $package ): string {
		foreach ( self::feed() as $release ) {
			foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
				if ( is_array( $asset ) && isset( $asset['browser_download_url'] ) && $package === $asset['browser_download_url'] ) {
					return self::clean_sha256( $asset['sha256'] ?? '' );
				}
			}
		}
		return '';
	}

	/**
	 * A sha256 as 64 lower-case hex characters, or '' for anything else.
	 * GitHub's API writes it as "sha256:<hex>"; the version list as plain hex.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean_sha256( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		if ( 0 === strpos( $value, 'sha256:' ) ) {
			$value = substr( $value, 7 );
		}
		return 1 === preg_match( '/^[0-9a-f]{64}$/', $value ) ? $value : '';
	}

	/**
	 * Note an update safety event in the PHP error log, and let Optima Build
	 * (or anything else) pick it up through the optima_updater_log action.
	 *
	 * @param string $message What happened.
	 * @return void
	 */
	private static function log( string $message ): void {
		error_log( 'Optima updater: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- an update safety record, kept on purpose.
		if ( function_exists( 'do_action' ) ) {
			do_action( 'optima_updater_log', $message );
		}
	}

	/**
	 * Report the newest release. WordPress offers it as an update when it is
	 * ahead of what is installed, and lists the plugin as up to date otherwise.
	 *
	 * @param array|false $update      Update payload from a previous filter.
	 * @param array       $plugin_data The plugin's headers, including Version.
	 * @param string      $plugin_file Plugin file relative to the plugins dir.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( $this->file ) !== $plugin_file ) {
			return $update; // A different Optima plugin is asking; leave it alone.
		}

		$installed = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
		$release   = $this->latest_release();

		if ( '' === $installed || null === $release ) {
			return $update;
		}
		// Answer even when nothing is newer: WordPress then lists the plugin as
		// up to date (no_update), which is what lets the Plugins screen show its
		// auto-update state instead of a blank column.
		return array(
			'id'           => 'github.com/' . self::REPO . '/' . $this->slug,
			'slug'         => $this->slug,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $release['package'],
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
			// Optima Build labels these "Test release" in its update queue.
			'prerelease'   => ! empty( $release['prerelease'] ),
		);
	}

	/**
	 * The site's release channel: 'test' or 'stable'.
	 *
	 * Asks Optima Build when it is active and knows about channels; anything
	 * else (no Build, an older Build, an unknown value) is 'stable', so a site
	 * only ever sees test releases when someone chose that on purpose.
	 *
	 * @return string
	 */
	public static function channel(): string {
		$channel = 'stable';
		if ( class_exists( 'Optima_Hub_Access', false ) && method_exists( 'Optima_Hub_Access', 'channel' ) ) {
			$channel = (string) Optima_Hub_Access::channel();
		}
		return 'test' === $channel ? 'test' : 'stable';
	}

	/**
	 * Pick the newest installable release of one plugin from the release list.
	 *
	 * Drafts are always skipped. Pre-releases (test releases) count only when
	 * $with_test is true; otherwise the answer is exactly what a site got
	 * before release channels existed.
	 *
	 * @param array  $releases  Release list (tag_name, draft, prerelease, body, assets).
	 * @param string $slug      Plugin folder name.
	 * @param bool   $with_test Also consider pre-releases.
	 * Only a zip under DOWNLOAD_PREFIX counts; a release whose zip is listed at
	 * any other address is skipped as not installable.
	 *
	 * @return array{version:string,package:string,notes:string,prerelease:bool,sha256:string}|null
	 */
	public static function pick( array $releases, string $slug, bool $with_test ): ?array {
		// GitHub sorts by tag name as text ("v0.4.9" before "v0.4.11"), so
		// compare version numbers and keep the highest.
		$prefix = $slug . '-v';
		$best   = null;
		foreach ( $releases as $release ) {
			if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
				continue;
			}
			if ( ! empty( $release['draft'] ) ) {
				continue;
			}
			if ( ! empty( $release['prerelease'] ) && ! $with_test ) {
				continue;
			}
			if ( 0 !== strpos( (string) $release['tag_name'], $prefix ) ) {
				continue;
			}

			$asset = self::zip_asset( $release, $slug );
			if ( null === $asset ) {
				continue; // Tagged but no built asset from this repo; not installable.
			}

			$version = substr( (string) $release['tag_name'], strlen( $prefix ) );
			if ( null === $best || version_compare( $version, $best['version'], '>' ) ) {
				$best = array(
					'version'    => $version,
					'package'    => $asset['url'],
					'notes'      => isset( $release['body'] ) ? (string) $release['body'] : '',
					'prerelease' => ! empty( $release['prerelease'] ),
					'sha256'     => $asset['sha256'],
				);
			}
		}

		return $best;
	}

	/**
	 * Fill the "View details" modal so it shows the release notes instead of an
	 * error. Only answers for this plugin's slug.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( null === $release ) {
			return $result;
		}

		$headers = get_file_data(
			$this->file,
			array(
				'Name'        => 'Plugin Name',
				'Author'      => 'Author',
				'RequiresPHP' => 'Requires PHP',
				'RequiresWP'  => 'Requires at least',
			)
		);

		return (object) array(
			'name'          => $headers['Name'],
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => $headers['Author'],
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $headers['RequiresWP'],
			'requires_php'  => $headers['RequiresPHP'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( $release['notes'] ) ),
			),
		);
	}

	/**
	 * Drop the cached lookup after any plugin install or update, so a fresh
	 * check runs instead of re-offering a version that was just applied.
	 *
	 * @return void
	 */
	public function flush(): void {
		delete_transient( self::cache_key_for( $this->slug, 'stable' ) );
		delete_transient( self::cache_key_for( $this->slug, 'test' ) );
	}

	/**
	 * The newest published release for this plugin.
	 *
	 * @return array{version:string,package:string,notes:string,prerelease:bool,sha256:string}|null
	 *         Null when there is no usable release or the lookup failed.
	 */
	private function latest_release(): ?array {
		$cached = get_transient( $this->cache_key() );
		if ( is_array( $cached ) ) {
			// An empty array is a cached "nothing to offer", which keeps a repo
			// with no releases yet from being polled on every check.
			return array() === $cached ? null : $cached;
		}

		$release = $this->fetch_release();
		if ( self::$feed_ok ) {
			set_transient( $this->cache_key(), ( null === $release ) ? array() : $release, self::CACHE_TTL );
		}

		return $release;
	}

	/**
	 * Ask GitHub for this plugin's releases and pick the newest usable one for
	 * this site's channel.
	 *
	 * @return array{version:string,package:string,notes:string,prerelease:bool,sha256:string}|null
	 */
	private function fetch_release(): ?array {
		return self::pick( self::feed(), $this->slug, 'test' === self::channel() );
	}

	/**
	 * Every release in the suite, newest first, trimmed to what the updater
	 * reads. Downloaded once and shared by all Optima plugins.
	 *
	 * @return array<int, array>
	 */
	private static function feed(): array {
		if ( is_array( self::$feed ) ) {
			return self::$feed;
		}
		$cached = get_transient( self::FEED_KEY );
		if ( is_array( $cached ) && isset( $cached['ok'], $cached['releases'] ) ) {
			self::$feed_ok = (bool) $cached['ok'];
			return self::$feed = (array) $cached['releases'];
		}
		// First choice: the version list the Release workflow writes to the
		// version-list branch. It is a plain download, so it is not held to GitHub's
		// 60-calls-an-hour API limit that shared hosts run out of.
		$data = null;
		$response = wp_remote_get( self::FEED_URL, array( 'timeout' => 10, 'headers' => array( 'User-Agent' => 'OptimaPluginUpdater' ) ) );
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			$data    = ( is_array( $decoded ) && $decoded ) ? $decoded : null;
		}
		$ok = is_array( $data );

		// Fallback: the API. GitHub pages the list 100 at a time and sorts it by
		// tag name, not by date, so a plugin's newest release can sit on a later
		// page. Read up to three pages (300 releases); stop at the first short page.
		for ( $page = 1; ! $ok && $page <= 3; $page++ ) {
			$response = wp_remote_get(
				'https://api.github.com/repos/' . self::REPO . '/releases?per_page=100&page=' . $page,
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						// GitHub rejects API requests that do not identify themselves.
						'User-Agent' => 'OptimaPluginUpdater',
					),
				)
			);
			$batch = ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
			if ( ! is_array( $batch ) ) {
				$ok = 1 < $page; // A later page failing still leaves a usable list.
				break;
			}
			$data = array_merge( (array) $data, $batch );
			if ( count( $batch ) < 100 ) {
				$ok = true;
				break;
			}
			$ok = 3 === $page;
		}
		$data = (array) $data;
		$list = array();
		foreach ( $ok ? $data : array() as $release ) {
			if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
				continue;
			}
			$assets = array();
			foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) ) {
					// The version list writes sha256; GitHub's API (the fallback) writes digest.
					$assets[] = array(
						'name'                 => (string) $asset['name'],
						'browser_download_url' => (string) $asset['browser_download_url'],
						'sha256'               => self::clean_sha256( $asset['sha256'] ?? ( $asset['digest'] ?? '' ) ),
					);
				}
			}
			$list[] = array(
				'tag_name'   => (string) $release['tag_name'],
				'draft'      => ! empty( $release['draft'] ),
				'prerelease' => ! empty( $release['prerelease'] ),
				'body'       => substr( (string) ( $release['body'] ?? '' ), 0, 1500 ),
				'assets'     => $assets,
			);
		}
		set_transient( self::FEED_KEY, array( 'ok' => $ok, 'releases' => $list ), $ok ? self::CACHE_TTL : 15 * MINUTE_IN_SECONDS );
		self::$feed_ok = $ok;
		return self::$feed = $list;
	}

	/**
	 * The download address and sha256 of a release's plugin zip.
	 *
	 * @param array  $release Release payload from the version list or the GitHub API.
	 * @param string $slug    Plugin folder name.
	 * @return array{url:string,sha256:string}|null Null when the release carries
	 *         no matching asset under DOWNLOAD_PREFIX.
	 */
	private static function zip_asset( array $release, string $slug ): ?array {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return null;
		}
		foreach ( $release['assets'] as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['name'], $asset['browser_download_url'] ) || $slug . '.zip' !== $asset['name'] ) {
				continue;
			}
			$url = (string) $asset['browser_download_url'];
			if ( ! self::is_release_url( $url ) ) {
				continue;
			}
			return array(
				'url'    => $url,
				'sha256' => self::clean_sha256( $asset['sha256'] ?? ( $asset['digest'] ?? '' ) ),
			);
		}
		return null;
	}

	/**
	 * Transient key for this plugin's cached lookup on this site's channel.
	 *
	 * @return string
	 */
	private function cache_key(): string {
		return self::cache_key_for( $this->slug, self::channel() );
	}

	/**
	 * Transient key for one plugin's cached lookup on one channel. The stable
	 * key is the one every earlier copy used, so switching to stable (or
	 * running an older copy) reads the same answer as before.
	 *
	 * @param string $slug    Plugin folder name.
	 * @param string $channel 'stable' or 'test'.
	 * @return string
	 */
	public static function cache_key_for( string $slug, string $channel ): string {
		return 'optima_updater_' . $slug . ( 'test' === $channel ? '__test' : '' );
	}
}

endif;
