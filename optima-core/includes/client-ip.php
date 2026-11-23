<?php
/**
 * The visitor's real address, behind Cloudflare or not.
 *
 * Behind Cloudflare, PHP's REMOTE_ADDR is one of Cloudflare's servers, so every
 * visitor looks like the same few addresses: one busy hour locks everyone out
 * of a rate limit, and a guesser never gets locked. Cloudflare sends the
 * visitor's address in the CF-Connecting-IP header, but anyone can send that
 * header to a site, so it is trusted only when the request really came from
 * one of Cloudflare's published address ranges
 * (https://www.cloudflare.com/ips-v4 and /ips-v6, read on the date in
 * CLOUDFLARE_READ). Anything else uses REMOTE_ADDR.
 *
 * Another proxy in front of the site (a host's own load balancer, for
 * example) is added with the `optima_core_proxy_ranges` filter (string[] of
 * CIDR ranges). When REMOTE_ADDR is one of those, the address is the
 * right-most entry in X-Forwarded-For that is not itself a trusted proxy,
 * so an address a visitor wrote into the header is never used.
 *
 * Every Optima plugin that counts visitors (failed logins, chat, site check,
 * review notes, partner clicks) calls optima_client_ip(). The
 * `optima_core_client_ip` filter (string, $source) has the last word.
 *
 * Only the address is read here. Nothing is stored.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Client_IP {

	/** When Cloudflare's published ranges below were read. */
	const CLOUDFLARE_READ = '2026-11-23';

	/** Cloudflare's published ranges (https://www.cloudflare.com/ips-v4 and /ips-v6). */
	const CLOUDFLARE = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/** Where the address came from: cloudflare, proxy or server. */
	const FROM_CLOUDFLARE = 'cloudflare';
	const FROM_PROXY      = 'proxy';
	const FROM_SERVER     = 'server';

	/** Whether $ip sits inside the CIDR range $cidr (IPv4 or IPv6). */
	public static function in_range( string $ip, string $cidr ): bool {
		$parts = explode( '/', trim( $cidr ), 2 );
		$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad range is just not a match.
		$addr  = @inet_pton( trim( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $net || false === $addr || strlen( $net ) !== strlen( $addr ) ) {
			return false;
		}
		$bits = isset( $parts[1] ) && '' !== $parts[1] ? (int) $parts[1] : strlen( $net ) * 8;
		if ( $bits < 0 || $bits > strlen( $net ) * 8 ) {
			return false;
		}
		$whole = intdiv( $bits, 8 );
		if ( 0 !== strncmp( $net, $addr, $whole ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $net[ $whole ] ) & $mask ) === ( ord( $addr[ $whole ] ) & $mask );
	}

	/** @param string[] $ranges */
	public static function in_any( string $ip, array $ranges ): bool {
		foreach ( $ranges as $cidr ) {
			if ( self::in_range( $ip, (string) $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/** Proxies added for this site (optima_core_proxy_ranges), never Cloudflare's own list. */
	public static function extra_ranges(): array {
		$extra = function_exists( 'apply_filters' ) ? apply_filters( 'optima_core_proxy_ranges', array() ) : array();
		return array_values( array_filter( array_map( 'strval', (array) $extra ) ) );
	}

	private static function valid( string $ip ): string {
		$ip = trim( $ip );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * The address and where it came from, before the final filter.
	 *
	 * @param array $server $_SERVER, or a copy of it for tests.
	 * @return array{0:string, 1:string} [ address, source ]
	 */
	public static function resolve( array $server ): array {
		$remote = self::valid( (string) ( $server['REMOTE_ADDR'] ?? '' ) );
		if ( '' === $remote ) {
			return array( '', self::FROM_SERVER );
		}
		if ( self::in_any( $remote, self::CLOUDFLARE ) ) {
			$cf = self::valid( (string) ( $server['HTTP_CF_CONNECTING_IP'] ?? '' ) );
			if ( '' !== $cf ) {
				return array( $cf, self::FROM_CLOUDFLARE );
			}
		}
		$extra = self::extra_ranges();
		if ( $extra && self::in_any( $remote, $extra ) ) {
			$trusted = array_merge( self::CLOUDFLARE, $extra );
			$chain   = array_reverse( array_map( 'trim', explode( ',', (string) ( $server['HTTP_X_FORWARDED_FOR'] ?? '' ) ) ) );
			foreach ( $chain as $hop ) {
				$hop = self::valid( $hop );
				if ( '' === $hop ) {
					break; // A broken entry: nothing to its left can be trusted.
				}
				if ( ! self::in_any( $hop, $trusted ) ) {
					return array( $hop, self::FROM_PROXY );
				}
			}
		}
		return array( $remote, self::FROM_SERVER );
	}

	/** Where this request's address came from: cloudflare, proxy or server. */
	public static function source(): string {
		return self::resolve( $_SERVER )[1]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated as an IP in resolve().
	}

	/**
	 * A Cloudflare header arrived from an address outside Cloudflare's ranges
	 * and outside every added proxy, so it was ignored. Either a spoofed header,
	 * or a proxy in between that the site has not named yet.
	 */
	public static function header_ignored(): bool {
		if ( empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return false;
		}
		return self::FROM_SERVER === self::source();
	}
}

/**
 * The visitor's address: Cloudflare's header when the request came from
 * Cloudflare, the forwarded address when it came from a proxy the site added,
 * REMOTE_ADDR otherwise. '' when there is no valid address.
 */
function optima_client_ip(): string {
	list( $ip, $source ) = Optima_Core_Client_IP::resolve( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated as an IP in resolve().
	$ip = function_exists( 'apply_filters' ) ? (string) apply_filters( 'optima_core_client_ip', $ip, $source ) : $ip;
	return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/** Where optima_client_ip() took the address from: cloudflare, proxy or server. */
function optima_client_ip_source(): string {
	return Optima_Core_Client_IP::source();
}
