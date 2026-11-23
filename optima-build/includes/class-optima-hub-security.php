<?php
/**
 * The Security group on Site health (plugin-first rule 19, the security
 * baseline): failed logins limited, XML-RPC off, file editing off, admins
 * limited to named people with two-step login, daily backups, the
 * browser security headers (missing or sent twice), and where the visitor's
 * address comes from (Optima Core's optima_client_ip()).
 *
 * Every row only reads. The switches live in Optima Core (Spam protection,
 * Optima_Core_Protection, includes/protection.php); this reads them when
 * Optima Core is running and says so when it is not. Nothing here turns a
 * setting on or off, and no header is added or changed. Each row is "ok" or
 * "look", never a fix, except the failed-login row, which keeps its own
 * wording from Optima_Hub_Health::login_limit().
 *
 * Only people who can manage the site see the group, because it lists admins
 * by display name (never email).
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Security {

	const HEADERS    = 'optima_hub_security_headers'; // Transient: { at, ok, local?, missing[], twice[] }.
	const HOOK       = 'optima_hub_security_headers';
	const LAST_LOGIN = 'optima_hub_last_login';       // User meta on administrators: time of the last sign-in.
	const SINCE      = 'optima_hub_security_since';   // When sign-ins started being noted here.

	const IDLE_DAYS = 180;

	/** Names that belong to nobody in particular. */
	const GENERIC = '/^(admin|administrator|test|user)\d*$/i';

	/** Two-step login plugins, by plugin folder. */
	const TWO_STEP = array(
		'two-factor'                         => 'Two Factor',
		'wordfence'                          => 'Wordfence',
		'wordfence-login-security'           => 'Wordfence Login Security',
		'wp-2fa'                             => 'WP 2FA',
		'miniorange-2-factor-authentication' => 'miniOrange',
		'better-wp-security'                 => 'Solid Security',
		'ithemes-security-pro'               => 'Solid Security',
	);

	/** The four headers, in the order they are listed. */
	const HEADER_NAMES = array( 'Strict-Transport-Security', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy' );

	public static function boot(): void {
		add_action( 'wp_login', array( __CLASS__, 'note_login' ), 10, 2 );
		add_action( self::HOOK, array( __CLASS__, 'scan_headers' ) );
		// Refreshed with the daily check.
		add_action( Optima_Hub_Watch::HOOK, array( __CLASS__, 'scan_headers' ) );
	}

	/** Where Optima Core's switches are. */
	private static function protect_url(): string {
		return admin_url( 'admin.php?page=optima&tab=protect' );
	}

	/** Optima Core's protection switches are running. */
	private static function core(): bool {
		return class_exists( 'Optima_Core_Protection' ) && method_exists( 'Optima_Core_Protection', 'on' );
	}

	/** When Optima started noting admin sign-ins. Set the first time it is asked. */
	private static function since(): int {
		$since = (int) get_option( self::SINCE, 0 );
		if ( ! $since ) {
			$since = time();
			update_option( self::SINCE, $since, false );
		}
		return $since;
	}

	/** Note when an administrator signs in. Nothing is kept for anyone else. */
	public static function note_login( $login, $user = null ): void {
		if ( $user instanceof WP_User && in_array( 'administrator', (array) $user->roles, true ) ) {
			update_user_meta( $user->ID, self::LAST_LOGIN, time() );
		}
	}

	/* ---- The rows ------------------------------------------------------ */

	/**
	 * The rows, for people who can see them: admins' names are listed, so only
	 * people who can manage the site get this group and its count.
	 */
	public static function visible_rows(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		return self::rows();
	}

	/**
	 * @return array<int, array{label:string, value:string, state:string, note:string, link?:string, link_text?:string}>
	 */
	public static function rows(): array {
		$core   = self::core();
		$admins = self::admins();
		$out    = array( Optima_Hub_Health::login_limit() );
		$out[]  = self::xmlrpc_row( (bool) apply_filters( 'xmlrpc_enabled', true ), $core && method_exists( 'Optima_Core_Protection', 'xmlrpc_needed' ) && Optima_Core_Protection::xmlrpc_needed(), $core, self::protect_url() );
		$out[]  = self::file_edit_row( self::file_edit_off( $core ), $core, self::protect_url() );
		$out[]  = self::admins_row( $admins, time(), self::since() );
		$out[]  = self::two_step_row( $admins, self::two_step_plugins() );
		$out[]  = self::backups_row( self::backups_by() );
		$scan   = self::headers_scan();
		$out[]  = self::headers_row( $scan, time(), $core, $core && Optima_Core_Protection::on( 'browser_headers' ), self::protect_url() );
		$twice  = self::twice_row( $scan, $core && Optima_Core_Protection::on( 'browser_headers' ), self::protect_url() );
		if ( $twice ) {
			$out[] = $twice;
		}
		$out[] = self::visitor_ip_row(
			function_exists( 'optima_client_ip_source' ) ? optima_client_ip_source() : null,
			class_exists( 'Optima_Core_Client_IP' ) && Optima_Core_Client_IP::header_ignored(),
			class_exists( 'Optima_Core_Client_IP' ) ? Optima_Core_Client_IP::CLOUDFLARE_READ : ''
		);
		return $out;
	}

	/** File editing is off: wp-config.php, or Optima Core's "Turn off the code editor" switch. */
	private static function file_edit_off( bool $core ): bool {
		if ( ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			return true;
		}
		if ( ! $core ) {
			return false;
		}
		if ( method_exists( 'Optima_Core_Protection', 'editor_locked' ) ) {
			return (bool) Optima_Core_Protection::editor_locked();
		}
		return Optima_Core_Protection::on( 'file_edit_off' );
	}

	/** Whether wp-config.php forces one of Optima Core's switches off. */
	private static function forced_off( string $key ): bool {
		return self::core() && method_exists( 'Optima_Core_Protection', 'forced_off' ) && in_array( $key, Optima_Core_Protection::forced_off(), true );
	}

	public static function xmlrpc_row( bool $enabled, bool $jetpack, bool $core, string $protect ): array {
		$row = array( 'label' => __( 'XML-RPC', 'optima-build' ) );
		if ( ! $enabled ) {
			return $row + array(
				'value' => __( 'Off', 'optima-build' ),
				'state' => 'good',
				'note'  => __( 'Bots can\'t use it to guess passwords.', 'optima-build' ),
			);
		}
		if ( $jetpack ) {
			return $row + array(
				'value' => __( 'On for Jetpack', 'optima-build' ),
				'state' => 'good',
				'note'  => __( 'Jetpack needs XML-RPC, so it stays on.', 'optima-build' ),
			);
		}
		if ( $core && self::forced_off( 'xmlrpc_off' ) ) {
			return $row + array(
				'value' => __( 'On', 'optima-build' ),
				'state' => 'warn',
				'note'  => __( 'wp-config.php keeps XML-RPC on (OPTIMA_CORE_PROTECTION_OFF). The site\'s decisions log should say why.', 'optima-build' ),
			);
		}
		return $row + array(
			'value' => __( 'On', 'optima-build' ),
			'state' => 'warn',
			'note'  => $core
				? __( 'XML-RPC (an old way to post from apps) lets bots guess passwords. Turn it off in Spam protection.', 'optima-build' )
				: __( 'XML-RPC (an old way to post from apps) lets bots guess passwords. Turn on Optima Core to switch it off.', 'optima-build' ),
		) + ( $core ? array( 'link' => $protect, 'link_text' => __( 'Spam protection', 'optima-build' ) ) : array() );
	}

	public static function file_edit_row( bool $off, bool $core = false, string $protect = '' ): array {
		$row = array(
			'label' => __( 'File editing', 'optima-build' ),
			'value' => $off ? __( 'Off', 'optima-build' ) : __( 'On', 'optima-build' ),
			'state' => $off ? 'good' : 'warn',
		);
		if ( $off ) {
			return $row + array( 'note' => __( 'Nobody can change theme or plugin code from inside WordPress.', 'optima-build' ) );
		}
		if ( $core && self::forced_off( 'file_edit_off' ) ) {
			return $row + array( 'note' => __( 'wp-config.php keeps the code editor on (OPTIMA_CORE_PROTECTION_OFF). The site\'s decisions log should say why.', 'optima-build' ) );
		}
		return $row + array(
			'note' => $core
				? __( 'Admins can change theme and plugin code in WordPress. Turn on "Turn off the code editor" in Spam protection.', 'optima-build' )
				: __( 'Admins can change theme and plugin code in WordPress. Turn on Optima Core to switch the code editor off.', 'optima-build' ),
		) + ( $core && '' !== $protect ? array( 'link' => $protect, 'link_text' => __( 'Spam protection', 'optima-build' ) ) : array() );
	}

	/**
	 * Administrators, by display name. Email addresses are never read.
	 *
	 * @return array<int, array{name:string, login:string, last:int, registered:int, two_step:?bool}>
	 */
	public static function admins(): array {
		$out     = array();
		$plugins = self::two_step_plugins();
		foreach ( get_users( array( 'role' => 'administrator', 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_login', 'user_registered' ) ) ) as $u ) {
			$id    = (int) $u->ID;
			$out[] = array(
				'name'       => (string) $u->display_name,
				'login'      => (string) $u->user_login,
				'last'       => self::last_login( $id ),
				'registered' => (int) strtotime( (string) $u->user_registered . ' UTC' ),
				'two_step'   => $plugins ? self::uses_two_step( $id, $plugins ) : null,
			);
		}
		return $out;
	}

	/** The latest sign-in anything on the site recorded, or 0. */
	private static function last_login( int $id ): int {
		$last = (int) get_user_meta( $id, self::LAST_LOGIN, true );
		foreach ( array( 'last_login', 'wp-last-login' ) as $key ) {
			$v    = get_user_meta( $id, $key, true );
			$last = max( $last, is_numeric( $v ) ? (int) $v : (int) strtotime( (string) $v ) );
		}
		$tokens = get_user_meta( $id, 'session_tokens', true );
		foreach ( is_array( $tokens ) ? $tokens : array() as $t ) {
			$last = max( $last, (int) ( $t['login'] ?? 0 ) );
		}
		return $last;
	}

	/**
	 * @param array $admins From admins().
	 * @param int   $since  When sign-ins started being noted here.
	 */
	public static function admins_row( array $admins, int $now, int $since ): array {
		$idle  = $now - self::IDLE_DAYS * DAY_IN_SECONDS;
		$flags = array();
		foreach ( $admins as $a ) {
			if ( preg_match( self::GENERIC, trim( $a['name'] ) ) || preg_match( self::GENERIC, trim( $a['login'] ) ) ) {
				/* translators: %s: display name */
				$flags[] = sprintf( __( '%s (a generic name)', 'optima-build' ), $a['name'] );
			} elseif ( is_email( trim( $a['name'] ) ) ) {
				// Never print the address itself: the report gets copied out of WordPress.
				$flags[] = __( 'an admin whose name shows an email address (Users, set a first and last name)', 'optima-build' );
			} elseif ( $a['last'] ? $a['last'] < $idle : ( $since < $idle && $a['registered'] < $idle ) ) {
				/* translators: %s: display name */
				$flags[] = sprintf( __( '%s (no sign-in in 180 days)', 'optima-build' ), $a['name'] );
			}
		}
		$names = implode( '; ', array_column( $admins, 'name' ) );
		$row   = array(
			'label' => __( 'Admins', 'optima-build' ),
			/* translators: %d: number of admins */
			'value' => sprintf( _n( '%d admin', '%d admins', count( $admins ), 'optima-build' ), count( $admins ) ),
			'state' => $flags ? 'warn' : 'good',
		);
		if ( ! $flags ) {
			/* translators: %s: display names */
			return $row + array( 'note' => sprintf( __( 'Each one is a named person: %s.', 'optima-build' ), $names ) );
		}
		return $row + array(
			/* translators: %s: flagged admins */
			'note'      => sprintf( __( 'Check these: %s. Each admin should be a named person who still needs it.', 'optima-build' ), implode( '; ', $flags ) ),
			'link'      => admin_url( 'users.php?role=administrator' ),
			'link_text' => __( 'Users', 'optima-build' ),
		);
	}

	/** Names of the two-step login plugins that are on. */
	public static function two_step_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$on = array();
		foreach ( $active as $file ) {
			$dir = strtok( (string) $file, '/' );
			if ( isset( self::TWO_STEP[ $dir ] ) ) {
				$on[ self::TWO_STEP[ $dir ] ] = true;
			}
		}
		if ( class_exists( 'Two_Factor_Core' ) ) {
			$on['Two Factor'] = true;
		}
		return array_keys( $on );
	}

	/**
	 * Whether one admin has two-step login on: true, false, or null when no
	 * plugin that is on says. Other plugins can answer with the
	 * optima_hub_two_step_user filter.
	 */
	private static function uses_two_step( int $id, array $plugins ): ?bool {
		$answers = array();
		try {
			if ( class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' ) ) {
				$answers[] = (bool) Two_Factor_Core::is_user_using_two_factor( $id );
			}
			if ( class_exists( '\WordfenceLS\Controller_Users' ) && method_exists( '\WordfenceLS\Controller_Users', 'shared' ) ) {
				$wf   = \WordfenceLS\Controller_Users::shared();
				$user = get_userdata( $id );
				if ( $user && method_exists( $wf, 'has_2fa_active' ) ) {
					$answers[] = (bool) $wf->has_2fa_active( $user );
				}
			}
			if ( class_exists( '\WP2FA\Admin\Helpers\User_Helper' ) && method_exists( '\WP2FA\Admin\Helpers\User_Helper', 'get_enabled_method_for_user' ) ) {
				$user = get_userdata( $id );
				if ( $user ) {
					$answers[] = '' !== (string) \WP2FA\Admin\Helpers\User_Helper::get_enabled_method_for_user( $user );
				}
			}
			if ( class_exists( 'ITSEC_Two_Factor' ) && method_exists( 'ITSEC_Two_Factor', 'get_instance' ) ) {
				$itsec = ITSEC_Two_Factor::get_instance();
				if ( method_exists( $itsec, 'is_user_using_two_factor' ) ) {
					$answers[] = (bool) $itsec->is_user_using_two_factor( $id );
				}
			}
		} catch ( Throwable $e ) {
			$answers[] = null;
		}
		/**
		 * Lets another two-step login plugin say whether this user has it on.
		 *
		 * @param bool|null $answer  Null when not known.
		 * @param int       $user_id The administrator.
		 */
		$extra = apply_filters( 'optima_hub_two_step_user', null, $id );
		if ( null !== $extra ) {
			$answers[] = (bool) $extra;
		}
		if ( in_array( true, $answers, true ) ) {
			return true;
		}
		// Every plugin that is on gave an answer, and every answer was no.
		$covered = count( array_filter( $answers, static fn( $a ) => false === $a ) );
		return $answers && $covered >= count( $plugins ) && ! in_array( null, $answers, true ) ? false : null;
	}

	/**
	 * @param array    $admins  From admins(), with two_step true, false or null.
	 * @param string[] $plugins Two-step login plugins that are on.
	 */
	public static function two_step_row( array $admins, array $plugins ): array {
		$row = array( 'label' => __( 'Two-step login', 'optima-build' ) );
		if ( ! $plugins ) {
			return $row + array(
				'value'     => __( 'None found', 'optima-build' ),
				'state'     => 'warn',
				'note'      => __( 'No two-step login plugin is on. Add Two Factor and turn it on for every admin.', 'optima-build' ),
				'link'      => admin_url( 'plugin-install.php?s=two-factor&tab=search&type=term' ),
				'link_text' => __( 'Add Two Factor', 'optima-build' ),
			);
		}
		$names   = implode( ', ', $plugins );
		$off     = array_values( array_filter( $admins, static fn( $a ) => false === $a['two_step'] ) );
		$unknown = array_filter( $admins, static fn( $a ) => null === $a['two_step'] );
		if ( $off ) {
			return $row + array(
				/* translators: %d: number of admins */
				'value'     => sprintf( _n( 'Off for %d', 'Off for %d', count( $off ), 'optima-build' ), count( $off ) ),
				'state'     => 'warn',
				/* translators: 1: plugin names, 2: display names */
				'note'      => sprintf( __( '%1$s is on, but these admins haven\'t set it up: %2$s.', 'optima-build' ), $names, implode( ', ', array_column( $off, 'name' ) ) ),
				'link'      => admin_url( 'users.php?role=administrator' ),
				'link_text' => __( 'Users', 'optima-build' ),
			);
		}
		if ( $unknown || ! $admins ) {
			return $row + array(
				'value' => __( 'Can\'t tell from here', 'optima-build' ),
				'state' => 'warn',
				/* translators: %s: plugin names */
				'note'  => sprintf( __( '%s is on, but it doesn\'t say which admins use it. Check on its own screen.', 'optima-build' ), $names ),
			);
		}
		return $row + array(
			/* translators: %d: number of admins */
			'value' => sprintf( _n( 'On for %d', 'On for all %d', count( $admins ), 'optima-build' ), count( $admins ) ),
			'state' => 'good',
			/* translators: %s: plugin names */
			'note'  => sprintf( __( 'Every admin signs in with a second step (%s).', 'optima-build' ), $names ),
		);
	}

	/** Who backs the site up, when the site can tell: WP Engine, or a name from the optima_hub_backups filter. */
	public static function backups_by(): string {
		$wpe = ( function_exists( 'is_wpe' ) && is_wpe() ) || defined( 'WPE_APIKEY' ) || ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR . '/wpengine-common' ) );
		/**
		 * The host or plugin that backs this site up every day, when it is not WP Engine.
		 *
		 * @param string $by Empty when not known.
		 */
		return (string) apply_filters( 'optima_hub_backups', $wpe ? 'WP Engine' : '' );
	}

	public static function backups_row( string $by ): array {
		$row = array( 'label' => __( 'Backups', 'optima-build' ) );
		if ( '' !== $by ) {
			/* translators: %s: host or plugin */
			return $row + array( 'value' => __( 'Daily', 'optima-build' ), 'state' => 'good', 'note' => sprintf( __( '%s backs up daily.', 'optima-build' ), $by ) );
		}
		return $row + array(
			'value' => __( 'Not known', 'optima-build' ),
			'state' => 'warn',
			'note'  => __( 'This site can\'t see its backups. Confirm the host or a backup plugin saves a copy every day.', 'optima-build' ),
		);
	}

	/* ---- Security headers ---------------------------------------------- */

	/**
	 * Which of the four headers a response leaves out. A Content-Security-Policy
	 * with frame-ancestors counts for X-Frame-Options.
	 *
	 * @param array<string,string> $headers Header names in any case.
	 * @return string[]
	 */
	public static function header_gaps( array $headers ): array {
		$h   = array_change_key_case( $headers, CASE_LOWER );
		$has = array(
			'Strict-Transport-Security' => isset( $h['strict-transport-security'] ),
			'X-Content-Type-Options'    => isset( $h['x-content-type-options'] ),
			'X-Frame-Options'           => isset( $h['x-frame-options'] ) || (bool) preg_match( '/frame-ancestors/i', (string) ( $h['content-security-policy'] ?? '' ) ),
			'Referrer-Policy'           => isset( $h['referrer-policy'] ),
		);
		return array_keys( array_filter( $has, static fn( $v ) => ! $v ) );
	}

	/**
	 * Which of the four headers the response carries twice: the same header
	 * line sent twice (the host or CDN adds one after PHP sent its own), or one
	 * line whose value repeats ("nosniff, nosniff"). A comma inside
	 * Strict-Transport-Security, X-Content-Type-Options or X-Frame-Options is
	 * always a join of two values; Referrer-Policy may list fallbacks, so only
	 * a repeated value counts there.
	 *
	 * @param array<string,string|string[]> $headers Header names in any case; a header sent twice is an array.
	 * @return string[]
	 */
	public static function header_twice( array $headers ): array {
		$h   = array_change_key_case( $headers, CASE_LOWER );
		$out = array();
		foreach ( self::HEADER_NAMES as $name ) {
			$key = strtolower( $name );
			if ( ! isset( $h[ $key ] ) ) {
				continue;
			}
			$value = $h[ $key ];
			if ( is_array( $value ) ) {
				if ( count( $value ) > 1 ) {
					$out[] = $name;
				}
				continue;
			}
			$parts = array_values( array_filter( array_map( static fn( $p ) => strtolower( trim( $p ) ), explode( ',', (string) $value ) ), 'strlen' ) );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			if ( 'Referrer-Policy' !== $name || count( $parts ) !== count( array_unique( $parts ) ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Load the live home page's headers once a day and keep what is missing and
	 * what arrives twice. Only reads. The host and the CDN (WP Engine,
	 * Cloudflare) add their headers after PHP has sent the page, so only this
	 * outside look sees what a visitor's browser gets. Skipped on a copy on
	 * someone's own computer, where no host or CDN sits in front.
	 */
	public static function scan_headers(): void {
		if ( Optima_Hub_Health::is_local_host() ) {
			set_transient( self::HEADERS, array( 'at' => time(), 'ok' => false, 'local' => true, 'missing' => array(), 'twice' => array() ), DAY_IN_SECONDS );
			return;
		}
		$r = wp_safe_remote_head( home_url( '/' ), array( 'timeout' => 5, 'redirection' => 3, 'sslverify' => Optima_Hub_Health::self_sslverify() ) );
		if ( is_wp_error( $r ) || ! (int) wp_remote_retrieve_response_code( $r ) ) {
			set_transient( self::HEADERS, array( 'at' => time(), 'ok' => false, 'missing' => array(), 'twice' => array() ), HOUR_IN_SECONDS );
			return;
		}
		$h   = array();
		$raw = array();
		foreach ( wp_remote_retrieve_headers( $r ) as $name => $value ) {
			$h[ strtolower( (string) $name ) ]   = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			$raw[ strtolower( (string) $name ) ] = is_array( $value ) ? array_map( 'strval', $value ) : (string) $value;
		}
		set_transient(
			self::HEADERS,
			array(
				'at'      => time(),
				'ok'      => true,
				'missing' => self::header_gaps( $h ),
				'twice'   => self::header_twice( $raw ),
			),
			DAY_IN_SECONDS
		);
	}

	/** The last header check, or null while the first one runs in the background. */
	public static function headers_scan(): ?array {
		$scan = get_transient( self::HEADERS );
		if ( is_array( $scan ) ) {
			return $scan;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
			spawn_cron();
		}
		return null;
	}

	/**
	 * @param array|null $scan       From headers_scan().
	 * @param bool       $core       Optima Core is running.
	 * @param bool       $core_sends Optima Core's "Send basic browser protections" is on.
	 */
	public static function headers_row( ?array $scan, int $now, bool $core, bool $core_sends, string $protect ): array {
		$row = array( 'label' => __( 'Security headers', 'optima-build' ) );
		if ( null === $scan ) {
			return $row + array( 'value' => __( 'Checking', 'optima-build' ), 'state' => 'good', 'note' => __( 'Checking the home page now. Reload in a minute.', 'optima-build' ) );
		}
		if ( ! empty( $scan['local'] ) ) {
			return $row + array(
				'value' => __( 'Not checked here', 'optima-build' ),
				'state' => 'good',
				'note'  => __( 'This copy runs on someone\'s own computer, with no host or CDN in front, so there is nothing to check. The live site and staging are checked once a day.', 'optima-build' ),
			);
		}
		if ( empty( $scan['ok'] ) ) {
			return $row + array(
				'value' => __( 'Couldn\'t check', 'optima-build' ),
				'state' => 'warn',
				'note'  => __( 'The site couldn\'t load its own home page, often a staging password. It tries again in an hour.', 'optima-build' ),
			);
		}
		/* translators: %s: time since, e.g. "3 hours" */
		$when    = sprintf( __( 'Checked %s ago.', 'optima-build' ), human_time_diff( (int) $scan['at'], $now ) );
		$missing = array_values( (array) ( $scan['missing'] ?? array() ) );
		if ( ! $missing ) {
			return $row + array( 'value' => __( 'All 4 sent', 'optima-build' ), 'state' => 'good', 'note' => __( 'The home page sends all four browser protections.', 'optima-build' ) . ' ' . $when );
		}
		$fix = $core && ! $core_sends
			? __( 'Turn on "Send basic browser protections" in Spam protection.', 'optima-build' )
			: __( 'Ask the host to add them.', 'optima-build' );
		return $row + array(
			/* translators: %d: number of headers */
			'value' => sprintf( _n( '%d missing', '%d missing', count( $missing ), 'optima-build' ), count( $missing ) ),
			'state' => 'warn',
			/* translators: %s: header names */
			'note'  => sprintf( __( 'Missing: %s.', 'optima-build' ), implode( ', ', $missing ) ) . ' ' . $fix . ' ' . $when,
		) + ( $core && ! $core_sends ? array( 'link' => $protect, 'link_text' => __( 'Spam protection', 'optima-build' ) ) : array() );
	}

	/**
	 * Headers the home page sends twice. A browser may read a doubled header
	 * wrongly or ignore it, so one side should send each header: the host or
	 * CDN, or Optima Core's "Send basic browser protections" switch. Null when
	 * the daily check has no answer yet (the headers row already says so).
	 *
	 * @param array|null $scan       From headers_scan().
	 * @param bool       $core_sends Optima Core's "Send basic browser protections" is on.
	 */
	public static function twice_row( ?array $scan, bool $core_sends, string $protect ): ?array {
		if ( null === $scan || empty( $scan['ok'] ) ) {
			return null;
		}
		$row   = array( 'label' => __( 'Headers sent twice', 'optima-build' ) );
		$twice = array_values( (array) ( $scan['twice'] ?? array() ) );
		if ( ! $twice ) {
			return $row + array(
				'value' => __( 'None', 'optima-build' ),
				'state' => 'good',
				'note'  => __( 'Each browser protection arrives once.', 'optima-build' ),
			);
		}
		return $row + array(
			/* translators: %d: number of headers */
			'value' => sprintf( _n( '%d header', '%d headers', count( $twice ), 'optima-build' ), count( $twice ) ),
			'state' => 'warn',
			/* translators: %s: header names */
			'note'  => sprintf( __( 'Sent twice: %s. Pick one owner: the host/CDN or Optima Core\'s switch.', 'optima-build' ), implode( ', ', $twice ) )
				. ( $core_sends ? ' ' . __( 'Optima Core\'s "Send basic browser protections" is on, and the host or CDN adds the same header after it.', 'optima-build' ) : '' ),
		) + ( $core_sends && '' !== $protect ? array( 'link' => $protect, 'link_text' => __( 'Spam protection', 'optima-build' ) ) : array() );
	}

	/**
	 * Where the visitor's address comes from, for this request: Cloudflare's
	 * header (only when the request came from Cloudflare's published ranges),
	 * a proxy the site added, or the server. Failed logins, chat, the site
	 * check and the other rate limits all count by that address.
	 *
	 * @param string|null $source  From optima_client_ip_source(), null without Optima Core.
	 * @param bool        $ignored A Cloudflare header arrived from outside Cloudflare's ranges.
	 * @param string      $read    The date Cloudflare's ranges were read.
	 */
	public static function visitor_ip_row( ?string $source, bool $ignored, string $read ): array {
		$row   = array( 'label' => __( 'Visitor address', 'optima-build' ) );
		/* translators: %s: date */
		$dated = '' !== $read ? ' ' . sprintf( __( 'Cloudflare\'s address list was read on %s.', 'optima-build' ), $read ) : '';
		if ( null === $source ) {
			return $row + array(
				'value' => __( 'From the server', 'optima-build' ),
				'state' => 'warn',
				'note'  => __( 'Optima Core finds the real visitor behind Cloudflare, and this site does not run a version that does. Behind Cloudflare, every visitor would count as the same few addresses. Update Optima Core.', 'optima-build' ),
			);
		}
		if ( 'cloudflare' === $source ) {
			return $row + array(
				'value' => __( 'From Cloudflare\'s header', 'optima-build' ),
				'state' => 'good',
				'note'  => __( 'This request came through Cloudflare, so each visitor is counted by their own address, read from Cloudflare\'s header.', 'optima-build' ) . $dated,
			);
		}
		if ( 'proxy' === $source ) {
			return $row + array(
				'value' => __( 'From the proxy\'s forwarded list', 'optima-build' ),
				'state' => 'good',
				'note'  => __( 'This request came through a proxy the site named (optima_core_proxy_ranges), so each visitor is counted by the address it forwarded.', 'optima-build' ),
			);
		}
		if ( $ignored ) {
			return $row + array(
				'value' => __( 'From the server', 'optima-build' ),
				'state' => 'warn',
				'note'  => __( 'A Cloudflare header came from an address outside Cloudflare\'s ranges, so it was not trusted. If another proxy sits between Cloudflare and the site, add its ranges with the optima_core_proxy_ranges filter.', 'optima-build' ) . $dated,
			);
		}
		return $row + array(
			'value' => __( 'From the server', 'optima-build' ),
			'state' => 'good',
			'note'  => __( 'This request came straight to the server, so each visitor is counted by the address the server sees.', 'optima-build' ),
		);
	}
}
