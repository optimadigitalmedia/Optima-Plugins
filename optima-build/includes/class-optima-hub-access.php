<?php
/**
 * Who may change features, and which plan the site is on.
 *
 * Operators are the people who build and look after the site. By default they
 * are the administrators on the agency's own domain (@optimadigitalmedia.com)
 * and on the agency's web-team partner domain. The partner domain is stored
 * only as a SHA-256 hash, so this public repo never names it.
 * A site can narrow or change that in wp-config.php, so a client admin cannot
 * add themselves from the dashboard:
 *
 *   define( 'OPTIMA_HUB_OPERATORS', 'name@example.com, @example.com' );
 *
 * An exact address is one person (who must be able to manage plugins). An entry
 * starting with @ is a whole domain: any user whose address ends with it and who
 * can manage the site's settings. Everyone else with dashboard access sees what
 * is on and can ask for more.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Access {

	const PLAN_OPTION = 'optima_hub_plan';

	/** Plans in order, so a feature included in a lower plan is in every higher one. */
	const PLANS = array(
		'core'        => 'Core',
		'growth'      => 'Growth',
		'portfolio'   => 'Full Portfolio',
	);

	/** The default operators: administrators on the agency's own domain. */
	const DEFAULT_OPERATORS = '@optimadigitalmedia.com';

	/** Also operators by default: administrators on the web-team partner domain (sha256 of "@domain"). */
	const DEFAULT_OPERATOR_DOMAIN_HASHES = array( '323a677ca3e24b054780fe53c9065fd4f9909a8b72428d5dab031c3184b82120' );

	/** @return string[] Lower-case operator emails and @domains. */
	public static function operators(): array {
		$raw = defined( 'OPTIMA_HUB_OPERATORS' ) ? (string) OPTIMA_HUB_OPERATORS : self::DEFAULT_OPERATORS;
		$out = array();
		foreach ( explode( ',', $raw ) as $entry ) {
			$entry = strtolower( trim( $entry ) );
			if ( is_email( $entry ) || preg_match( '/^@[a-z0-9-]+(\.[a-z0-9-]+)+$/', $entry ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * True for an operator: a listed address who can also manage plugins, or a
	 * user on a listed @domain who can manage the site's settings.
	 */
	public static function is_operator( ?WP_User $user = null ): bool {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		$email = strtolower( (string) $user->user_email );
		if ( ! defined( 'OPTIMA_HUB_OPERATORS' ) && false !== strpos( $email, '@' ) ) {
			$domain = substr( $email, strrpos( $email, '@' ) );
			if ( in_array( hash( 'sha256', $domain ), self::DEFAULT_OPERATOR_DOMAIN_HASHES, true ) && user_can( $user, 'manage_options' ) ) {
				return true;
			}
		}
		foreach ( self::operators() as $entry ) {
			if ( '@' === $entry[0] ) {
				if ( substr( $email, -strlen( $entry ) ) === $entry && user_can( $user, 'manage_options' ) ) {
					return true;
				}
			} elseif ( $email === $entry && user_can( $user, 'activate_plugins' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Plugin installs are possible on this site (not locked by the host). */
	public static function can_install(): bool {
		return ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) && current_user_can( 'install_plugins' );
	}

	public static function plan(): string {
		$plan = (string) get_option( self::PLAN_OPTION, '' );
		return isset( self::PLANS[ $plan ] ) ? $plan : '';
	}

	public static function set_plan( string $plan ): void {
		update_option( self::PLAN_OPTION, isset( self::PLANS[ $plan ] ) ? $plan : '', false );
	}

	/**
	 * Is a feature with this tier part of the site's plan?
	 *
	 * @param string $tier core | growth | portfolio | addon.
	 */
	public static function included( string $tier ): bool {
		$plan = self::plan();
		if ( '' === $plan ) {
			return false;
		}
		// Full Portfolio includes every add-on.
		if ( 'addon' === $tier ) {
			return 'portfolio' === $plan;
		}
		if ( ! isset( self::PLANS[ $tier ] ) ) {
			return false;
		}
		$order = array_keys( self::PLANS );
		return array_search( $tier, $order, true ) <= array_search( $plan, $order, true );
	}

	/** The label a feature card shows for its tier. */
	public static function tier_label( string $tier ): string {
		if ( self::included( $tier ) ) {
			return __( 'Included in your plan', 'optima-build' );
		}
		if ( isset( self::PLANS[ $tier ] ) ) {
			/* translators: %s: plan name */
			return sprintf( __( 'Included with %s', 'optima-build' ), self::PLANS[ $tier ] );
		}
		return __( 'Add-on', 'optima-build' );
	}
}
