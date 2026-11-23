<?php
/**
 * Outside plugins: every active plugin that is not an Optima plugin and not
 * Gravity Forms, for Site health (plugin-first rule 51).
 *
 * Gravity Forms is the one outside plugin every Optima site runs by default.
 * Any other needs a dated entry in the site's decisions log naming the job.
 * Build cannot read that log, so the theme's optima-setup.json carries the
 * same list as "approved_plugins" (read by Optima_Hub_Setup::approved_plugins()).
 * A plugin on that list shows as approved; any other shows as outside the
 * standard. The row is a look, never a fix.
 *
 * Reads only the plugin list WordPress already has. Admin screens only;
 * nothing here reaches visitors.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Plugins {

	/** The repo Optima plugins update from (their Update URI). */
	const OPTIMA_REPOS = array( 'optimadigitalmedia/optima-plugins' );

	/** Hosts in a plugin's Author URI or Plugin URI that mean Optima made it (companion plugins have no Update URI). */
	const OPTIMA_HOSTS = array( 'optimadigitalmedia.com' );

	/** Hosts that mean Gravity Forms or one of its official add-ons. */
	const GRAVITY_HOSTS = array( 'gravityforms.com', 'rocketgenius.com' );

	/** The row label. */
	public static function label(): string {
		return __( 'Outside plugins', 'optima-build' );
	}

	/** A plugin's folder name ("wp-rocket" for wp-rocket/wp-rocket.php, "hello" for hello.php). */
	public static function slug( string $file ): string {
		$dir = dirname( $file );
		return strtolower( '.' === $dir || '' === $dir ? basename( $file, '.php' ) : $dir );
	}

	/**
	 * Which kind of plugin this is: optima, gravity or outside.
	 *
	 * @param string $file The plugin file, as get_plugins() keys it.
	 * @param array  $data The plugin's headers, as get_plugins() returns them.
	 */
	public static function classify( string $file, array $data ): string {
		$slug   = self::slug( $file );
		$update = strtolower( (string) ( $data['UpdateURI'] ?? '' ) );
		$author = strtolower( trim( (string) ( $data['Author'] ?? '' ) ) );
		$hosts  = array();
		foreach ( array( 'AuthorURI', 'PluginURI' ) as $key ) {
			$host = strtolower( (string) parse_url( (string) ( $data[ $key ] ?? '' ), PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain host read, no WordPress needed.
			if ( '' !== $host ) {
				$hosts[] = preg_replace( '/^www\./', '', $host );
			}
		}
		$on = static function ( array $list ) use ( $hosts ): bool {
			foreach ( $hosts as $host ) {
				foreach ( $list as $want ) {
					if ( $host === $want || str_ends_with( $host, '.' . $want ) ) {
						return true;
					}
				}
			}
			return false;
		};

		foreach ( self::OPTIMA_REPOS as $repo ) {
			if ( false !== strpos( $update, $repo ) ) {
				return 'optima';
			}
		}
		if ( 0 === strpos( $author, 'optima digital' ) || $on( self::OPTIMA_HOSTS ) ) {
			return 'optima';
		}
		if ( 'gravityforms' === $slug || $on( self::GRAVITY_HOSTS ) || in_array( $author, array( 'gravity forms', 'rocketgenius' ), true ) ) {
			return 'gravity';
		}
		return 'outside';
	}

	/**
	 * The Site health row for a plugin list. No WordPress needed, so the test
	 * can call it straight.
	 *
	 * @param array $plugins  file => headers, as get_plugins() returns them.
	 * @param array $active   The active plugin files.
	 * @param array $approved slug => entry, from Optima_Hub_Setup::approved_plugins().
	 * @return array{label:string, value:string, state:string, note:string, list?:array, link?:string, link_text?:string}
	 */
	public static function row( array $plugins, array $active, array $approved ): array {
		$waiting = array();
		$okayed  = array();
		foreach ( $plugins as $file => $data ) {
			if ( ! in_array( $file, $active, true ) ) {
				continue;
			}
			if ( 'outside' !== self::classify( (string) $file, (array) $data ) ) {
				continue;
			}
			$name = trim( (string) ( $data['Name'] ?? '' ) );
			$name = '' !== $name ? $name : self::slug( (string) $file );
			if ( isset( $approved[ self::slug( (string) $file ) ] ) ) {
				$okayed[] = $name;
			} else {
				$waiting[] = $name;
			}
		}

		if ( ! $waiting && ! $okayed ) {
			return array(
				'label' => self::label(),
				'value' => __( 'None', 'optima-build' ),
				'state' => 'good',
				'note'  => __( 'Only Optima plugins and Gravity Forms are on.', 'optima-build' ),
			);
		}
		if ( ! $waiting ) {
			return array(
				'label' => self::label(),
				/* translators: %d: number of plugins */
				'value' => sprintf( _n( '%d approved', '%d approved', count( $okayed ), 'optima-build' ), count( $okayed ) ),
				'state' => 'good',
				/* translators: %s: plugin names */
				'note'  => sprintf( _n( 'One plugin is not an Optima plugin or Gravity Forms, and it is on this site\'s approved list: %s.', 'These plugins are not Optima plugins or Gravity Forms, and each one is on this site\'s approved list: %s.', count( $okayed ), 'optima-build' ), implode( ', ', $okayed ) ),
				'list'  => $okayed,
			);
		}
		$items = array();
		foreach ( $waiting as $name ) {
			/* translators: %s: plugin name */
			$items[] = sprintf( __( '%s: outside the standard', 'optima-build' ), $name );
		}
		foreach ( $okayed as $name ) {
			/* translators: %s: plugin name */
			$items[] = sprintf( __( '%s: approved', 'optima-build' ), $name );
		}
		/* translators: %s: plugin names */
		$note  = sprintf( _n( 'Outside the standard: %s.', 'Outside the standard: %s.', count( $waiting ), 'optima-build' ), implode( ', ', $waiting ) );
		$note .= ' ' . __( 'Keep one by adding a dated entry to the site\'s decisions log and its folder name to approved_plugins in optima-setup.json. Otherwise turn it off.', 'optima-build' );
		return array(
			'label'     => self::label(),
			/* translators: %d: number of plugins */
			'value'     => sprintf( _n( '%d outside the standard', '%d outside the standard', count( $waiting ), 'optima-build' ), count( $waiting ) ),
			'state'     => 'warn',
			'note'      => $note,
			'list'      => $items,
			'link'      => admin_url( 'plugins.php' ),
			'link_text' => __( 'Plugins', 'optima-build' ),
		);
	}

	/** The row for this site: its active plugins against the theme's approved list. */
	public static function check(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = (array) get_plugins();
		$active  = array();
		foreach ( array_keys( $plugins ) as $file ) {
			if ( is_plugin_active( $file ) ) {
				$active[] = $file;
			}
		}
		$approved = class_exists( 'Optima_Hub_Setup' ) ? Optima_Hub_Setup::approved_plugins() : array();
		return self::row( $plugins, $active, $approved );
	}
}
