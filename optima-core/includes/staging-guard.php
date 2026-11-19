<?php
/**
 * Staging guard: a staging copy never reaches real people (plugin-first rule 19).
 *
 * A staging copy is made from the live site, so it carries the live site's
 * clients, form entries and keys, and its scheduled jobs keep running. On a
 * staging copy:
 * - email goes only to operators (the addresses Optima Build's operator check
 *   recognizes, see Optima_Core_Operators) and to the site's own admin email.
 *   Mail to anyone else is dropped, and so are Cc and Bcc copies to them. More
 *   addresses or @domains can be added with `optima_core_staging_mail_to`.
 * - calls to outside services that send or charge (newsletter, email
 *   marketing, texting, payments, Zapier) are refused, so a test sign-up never
 *   joins the real list and a test payment never charges a card. The list can
 *   be changed with `optima_core_staging_blocked_hosts`.
 * - a notice at the top of every dashboard screen says so.
 *
 * A copy counts as staging when WordPress says so (WP_ENVIRONMENT_TYPE staging,
 * development or local) or when its address is a host's staging address (the
 * same suffixes Optima Build's Site health uses). In wp-config.php,
 * define( 'OPTIMA_STAGING', false ) marks a site live (the guard is off) and
 * define( 'OPTIMA_STAGING', true ) marks it staging. A site that needs the guard
 * off on staging records why in its decisions log; the `optima_core_staging_guard`
 * filter (bool) turns it off in code.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Staging {

	const LOG = 'optima_core_staging_held'; // { mail: n, calls: n, last: time }.

	/** Host staging addresses (kept equal to Optima_Hub_Health::STAGING_HOSTS). */
	const HOSTS = array( '.wpenginepowered.com', '.wpengine.com', '.kinsta.cloud', '.flywheelsites.com', '.pantheonsite.io', '.cloudwaysapps.com' );

	/** Outside services that send messages or take money. Matched against the end of the host. */
	const BLOCKED = array( 'api.beehiiv.com', 'api.mailchimp.com', 'list-manage.com', 'api.sendgrid.com', 'api.mailgun.net', 'api.postmarkapp.com', 'api.twilio.com', 'api.stripe.com', 'api-m.paypal.com', 'api.paypal.com', 'hooks.zapier.com', 'api.hubapi.com', 'api.convertkit.com', 'a.klaviyo.com' );

	public static function init() {
		if ( ! self::active() ) {
			return;
		}
		add_filter( 'wp_mail', array( __CLASS__, 'mail_args' ), 999 );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'stop_empty_mail' ), 999, 2 );
		add_filter( 'pre_http_request', array( __CLASS__, 'stop_calls' ), 999, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/** The guard runs: this is a staging copy and nothing turned the guard off. */
	public static function active(): bool {
		return self::is_staging() && (bool) apply_filters( 'optima_core_staging_guard', true );
	}

	public static function is_staging( string $env = '', string $host = '' ): bool {
		if ( defined( 'OPTIMA_STAGING' ) ) {
			return (bool) OPTIMA_STAGING;
		}
		$env  = '' !== $env ? $env : ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' );
		$host = '' !== $host ? strtolower( $host ) : strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( in_array( $env, array( 'staging', 'development', 'local' ), true ) ) {
			return true;
		}
		foreach ( self::HOSTS as $suffix ) {
			if ( '' !== $host && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/** True when an address may get mail from a staging copy. */
	public static function allowed( string $address ): bool {
		if ( preg_match( '/<([^>]+)>/', $address, $m ) ) {
			$address = $m[1];
		}
		$address = strtolower( trim( $address ) );
		if ( '' === $address ) {
			return false;
		}
		if ( Optima_Core_Operators::is_operator_address( $address ) ) {
			return true;
		}
		$admin = strtolower( trim( (string) get_option( 'admin_email', '' ) ) );
		if ( '' !== $admin && $address === $admin ) {
			return true;
		}
		foreach ( (array) apply_filters( 'optima_core_staging_mail_to', array() ) as $entry ) {
			$entry = strtolower( trim( (string) $entry ) );
			if ( '' === $entry ) {
				continue;
			}
			if ( false === strpos( $entry, '@' ) ) {
				$entry = '@' . $entry; // A bare domain.
			}
			if ( '@' === $entry[0] ? substr( $address, -strlen( $entry ) ) === $entry : $address === $entry ) {
				return true;
			}
		}
		return false;
	}

	/** Keep only allowed recipients, in To, Cc and Bcc. */
	public static function mail_args( $args ) {
		$args   = is_array( $args ) ? $args : array();
		$to     = is_array( $args['to'] ?? '' ) ? $args['to'] : explode( ',', (string) ( $args['to'] ?? '' ) );
		$to     = array_values( array_filter( array_map( 'trim', array_map( 'strval', $to ) ) ) );
		$kept   = array_values( array_filter( $to, array( __CLASS__, 'allowed' ) ) );
		$before = count( $to );
		$args['to'] = $kept;
		$headers    = is_array( $args['headers'] ?? '' ) ? $args['headers'] : preg_split( "/\r\n|\n/", (string) ( $args['headers'] ?? '' ) );
		$clean      = array();
		$dropped    = false;
		foreach ( (array) $headers as $line ) {
			if ( preg_match( '/^\s*(cc|bcc)\s*:\s*(.*)$/i', (string) $line, $m ) ) {
				$all  = array_filter( array_map( 'trim', explode( ',', $m[2] ) ) );
				$keep = array_filter( $all, array( __CLASS__, 'allowed' ) );
				$dropped = $dropped || count( $keep ) < count( $all );
				if ( $keep ) {
					$clean[] = $m[1] . ': ' . implode( ', ', $keep );
				}
				continue;
			}
			if ( '' !== trim( (string) $line ) ) {
				$clean[] = $line;
			}
		}
		$args['headers'] = $clean;
		if ( count( $kept ) < $before || $dropped ) {
			self::count( 'mail' );
		}
		return $args;
	}

	/** With nobody left to send to, the message is not sent at all. */
	public static function stop_empty_mail( $return, $atts ) {
		if ( null !== $return ) {
			return $return;
		}
		$to = is_array( $atts['to'] ?? '' ) ? $atts['to'] : array_filter( explode( ',', (string) ( $atts['to'] ?? '' ) ) );
		return array_filter( array_map( 'trim', array_map( 'strval', (array) $to ) ) ) ? null : false;
	}

	/** Refuse calls to services that send messages or take money. */
	public static function stop_calls( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		foreach ( (array) apply_filters( 'optima_core_staging_blocked_hosts', self::BLOCKED ) as $blocked ) {
			$blocked = strtolower( (string) $blocked );
			if ( '' !== $host && '' !== $blocked && ( $host === $blocked || substr( $host, -strlen( '.' . $blocked ) ) === '.' . $blocked ) ) {
				self::count( 'calls' );
				return new WP_Error( 'optima_staging', 'This is a staging copy, so outside services that send messages or take payments are switched off.' );
			}
		}
		return $pre;
	}

	/** How much the guard held back: { mail, calls, last }. */
	public static function held(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	private static function count( string $what ) {
		$log          = self::held();
		$log[ $what ] = (int) ( $log[ $what ] ?? 0 ) + 1;
		$log['last']  = time();
		update_option( self::LOG, $log, false );
	}

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Staging copy.', 'optima-core' ) . '</strong> ' . esc_html__( 'Emails go only to your web team and the site\'s admin email, and newsletter, texting and payment services are off, so no real client is reached.', 'optima-core' ) . '</p></div>';
	}
}
