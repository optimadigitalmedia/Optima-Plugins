<?php
/**
 * Failed-login limit (plugin-first rule 19: login attempts limited).
 *
 * Counts failed logins per visitor address and per username. After 5 failures
 * inside 15 minutes, that address (or that username, from any address) is
 * refused for 15 minutes, even with the right password. The login screen only
 * ever says one plain sentence, so it never tells a guesser whether a username
 * exists.
 *
 * Counts live in transients named by a salted hash of the address or username,
 * so the database never holds a visitor's IP or a guessed name in the clear.
 * A good login clears both counts.
 *
 * It is the `login_limit` switch of Core's protection set
 * (Optima_Core_Protection): on unless switched off on Search and AI, Spam
 * protection, or in wp-config.php with
 * define( 'OPTIMA_CORE_PROTECTION_OFF', 'login_limit' ).
 *
 * Filters:
 *  - `optima_core_login_limit_max` (int, 5): failures before the lockout.
 *  - `optima_core_login_limit_window` (int seconds, 900): how far back failures count.
 *  - `optima_core_login_limit_lockout` (int seconds, 900): how long a lockout lasts.
 *  - `optima_core_login_limit_skip` (bool, false; $username, $ip): leave this attempt alone.
 *  - `optima_core_login_limit_proxy_header` (string, ''): the $_SERVER key of a
 *    proxy header to trust outright for the visitor's address. Left empty, the
 *    address is optima_client_ip() (includes/client-ip.php), which already
 *    trusts Cloudflare's header only when the request came from Cloudflare's
 *    own ranges, so a site behind Cloudflare needs nothing set. Set it only
 *    when the site really sits behind another proxy that sends that header:
 *    anyone can send a header to a site that is not behind it.
 *  - `optima_core_login_limit_ip` (string): the address used, last word.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Login_Limit {

	const CODE = 'optima_login_limited';

	/** Core's own failure codes whose messages would say whether a username exists. */
	const TELLING = array( 'invalid_username', 'invalid_email', 'incorrect_password' );

	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'authenticate' ), 99, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'failed' ), 10, 2 );
		add_action( 'wp_login', array( __CLASS__, 'succeeded' ), 10, 1 );
	}

	public static function on(): bool {
		return class_exists( 'Optima_Core_Protection' ) && Optima_Core_Protection::on( 'login_limit' );
	}

	public static function max(): int {
		return max( 1, (int) apply_filters( 'optima_core_login_limit_max', 5 ) );
	}

	public static function window(): int {
		return max( 60, (int) apply_filters( 'optima_core_login_limit_window', 15 * MINUTE_IN_SECONDS ) );
	}

	public static function lockout(): int {
		return max( 60, (int) apply_filters( 'optima_core_login_limit_lockout', 15 * MINUTE_IN_SECONDS ) );
	}

	/** The visitor's address: optima_client_ip(), or the proxy header the site chose to trust. */
	public static function ip(): string {
		$ip     = function_exists( 'optima_client_ip' ) ? optima_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$header = (string) apply_filters( 'optima_core_login_limit_proxy_header', '' );
		if ( '' !== $header && ! empty( $_SERVER[ $header ] ) ) {
			$from = trim( explode( ',', (string) $_SERVER[ $header ] )[0] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( false !== filter_var( $from, FILTER_VALIDATE_IP ) ) {
				$ip = $from;
			}
		}
		return (string) apply_filters( 'optima_core_login_limit_ip', $ip );
	}

	/** Transient name for one address or username: a salted hash, never the value itself. */
	public static function key( string $kind, string $value ): string {
		$value = 'user' === $kind ? strtolower( trim( $value ) ) : trim( $value );
		return 'optima_ll_' . substr( hash_hmac( 'sha256', $kind . '|' . $value, wp_salt( 'auth' ) ), 0, 32 );
	}

	/** @return string[] The keys this attempt counts against (address, and username when given). */
	private static function keys( string $username ): array {
		$keys = array();
		$ip   = self::ip();
		if ( '' !== $ip ) {
			$keys[] = self::key( 'ip', $ip );
		}
		if ( '' !== trim( $username ) ) {
			$keys[] = self::key( 'user', $username );
		}
		return $keys;
	}

	private static function skip( string $username ): bool {
		return ! self::on() || (bool) apply_filters( 'optima_core_login_limit_skip', false, $username, self::ip() );
	}

	public static function locked( string $username ): bool {
		foreach ( self::keys( $username ) as $key ) {
			if ( false !== get_transient( $key . '_lock' ) ) {
				return true;
			}
		}
		return false;
	}

	/** One message for every failed or refused login. */
	public static function message(): string {
		return __( 'That login did not work. Check your details and try again, or wait a few minutes if it keeps failing.', 'optima-core' );
	}

	/**
	 * Runs after WordPress checked the password (priority 99), so a locked
	 * address or username is refused even with the right one, and Core's
	 * telling messages become the one plain sentence.
	 *
	 * @param WP_User|WP_Error|null $user
	 */
	public static function authenticate( $user, $username = '', $password = '' ) {
		$username = is_string( $username ) ? $username : '';
		if ( self::skip( $username ) ) {
			return $user;
		}
		if ( ( '' !== $username || '' !== (string) $password ) && self::locked( $username ) ) {
			return new WP_Error( self::CODE, self::message() );
		}
		if ( $user instanceof WP_Error && array_intersect( (array) $user->get_error_codes(), self::TELLING ) ) {
			return new WP_Error( (string) $user->get_error_code(), self::message() );
		}
		return $user;
	}

	/** wp_login_failed: count the failure; the fifth inside the window starts a lockout. */
	public static function failed( $username, $error = null ) {
		$username = is_string( $username ) ? $username : '';
		if ( self::skip( $username ) ) {
			return;
		}
		if ( $error instanceof WP_Error && self::CODE === $error->get_error_code() ) {
			return; // Already locked: a refused try does not stretch the lockout.
		}
		$now = time();
		foreach ( self::keys( $username ) as $key ) {
			$row = get_transient( $key );
			$row = is_array( $row ) && isset( $row['n'], $row['first'] ) && ( $now - (int) $row['first'] ) < self::window()
				? $row
				: array( 'n' => 0, 'first' => $now );
			$row['n'] = (int) $row['n'] + 1;
			if ( $row['n'] >= self::max() ) {
				set_transient( $key . '_lock', $now, self::lockout() );
				delete_transient( $key );
				continue;
			}
			set_transient( $key, $row, self::window() );
		}
	}

	/** wp_login: a good login clears the address's and the username's counts. */
	public static function succeeded( $username ) {
		foreach ( self::keys( is_string( $username ) ? $username : '' ) as $key ) {
			delete_transient( $key );
		}
	}
}
