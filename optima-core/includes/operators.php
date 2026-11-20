<?php
/**
 * Who the operators are, for Core's own checks (the staging mail guard and the
 * Change log). Operators are the people who build and look after the site.
 *
 * The one source is Optima Build's operator check (Optima_Hub_Access): the
 * administrators on the agency's own domain and on the agency's web-team
 * partner domain, or whatever OPTIMA_HUB_OPERATORS in wp-config.php names.
 * Core asks Build when Build is running. Without Build, Core falls back to the
 * same defaults Build ships, so a site that loses Build mid-update behaves the
 * same. The partner domain is stored only as a SHA-256 hash of "@domain", so
 * this public repo never names it; tools/tests/staging-guard.php fails when
 * this copy and Build's drift apart.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Operators {

	/** Build's defaults, used only when Optima Build is not loaded. Keep equal to Optima_Hub_Access. */
	const DEFAULT_OPERATORS             = '@optimadigitalmedia.com';
	const DEFAULT_OPERATOR_DOMAIN_HASHES = array( '323a677ca3e24b054780fe53c9065fd4f9909a8b72428d5dab031c3184b82120' );

	/** @return string[] Lower-case operator emails and @domains, from Build when it runs. */
	public static function entries(): array {
		if ( class_exists( 'Optima_Hub_Access' ) && method_exists( 'Optima_Hub_Access', 'operators' ) ) {
			return (array) Optima_Hub_Access::operators();
		}
		$raw = defined( 'OPTIMA_HUB_OPERATORS' ) ? (string) OPTIMA_HUB_OPERATORS : self::DEFAULT_OPERATORS;
		$out = array();
		foreach ( explode( ',', $raw ) as $entry ) {
			$entry = strtolower( trim( $entry ) );
			if ( preg_match( '/^[^@\s]+@[a-z0-9-]+(\.[a-z0-9-]+)+$/', $entry ) || preg_match( '/^@[a-z0-9-]+(\.[a-z0-9-]+)+$/', $entry ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/** @return string[] The partner-domain hashes, or none when wp-config names the operators. */
	public static function hashes(): array {
		if ( defined( 'OPTIMA_HUB_OPERATORS' ) ) {
			return array();
		}
		if ( class_exists( 'Optima_Hub_Access' ) && defined( 'Optima_Hub_Access::DEFAULT_OPERATOR_DOMAIN_HASHES' ) ) {
			return (array) Optima_Hub_Access::DEFAULT_OPERATOR_DOMAIN_HASHES;
		}
		return self::DEFAULT_OPERATOR_DOMAIN_HASHES;
	}

	/**
	 * True when an email address belongs to an operator: an exact address on the
	 * list, an address on a listed @domain, or one on the hashed partner domain.
	 * No role check: this is for addresses (mail), not for signed-in people.
	 */
	public static function is_operator_address( string $address ): bool {
		$address = strtolower( trim( $address ) );
		$at      = strrpos( $address, '@' );
		if ( false === $at || 0 === $at ) {
			return false;
		}
		$domain = substr( $address, $at );
		if ( in_array( hash( 'sha256', $domain ), self::hashes(), true ) ) {
			return true;
		}
		foreach ( self::entries() as $entry ) {
			if ( '@' === $entry[0] ? $domain === $entry : $address === $entry ) {
				return true;
			}
		}
		return false;
	}

	/** True for a signed-in operator. Build's own check when Build runs, the same rule otherwise. */
	public static function is_operator( $user = null ): bool {
		if ( class_exists( 'Optima_Hub_Access' ) && method_exists( 'Optima_Hub_Access', 'is_operator' ) ) {
			return (bool) Optima_Hub_Access::is_operator( $user instanceof WP_User ? $user : null );
		}
		$user = $user instanceof WP_User ? $user : ( function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null );
		if ( ! $user || ! $user->exists() || ! user_can( $user, 'manage_options' ) ) {
			return false;
		}
		return self::is_operator_address( (string) $user->user_email );
	}
}
