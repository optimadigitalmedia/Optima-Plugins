<?php
/**
 * Tells Optima when a live site is hidden from search engines.
 *
 * a client site site, Oct 2026: the live site carried "Discourage search engines"
 * (blog_public 0) over from staging for days and nobody at Optima knew. Now a
 * live site with that box ticked emails Optima at once when the setting
 * changes, then once a day's check finds it still on, at most once a week,
 * and shows administrators a notice. Staging and local copies are left alone.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Watch {

	const HOOK   = 'optima_hub_daily_watch';
	const SENT   = 'optima_hub_hidden_alert_sent'; // Time of the last "hidden from Google" email.
	const REPEAT = 7 * DAY_IN_SECONDS;

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'check' ) );
		add_action( 'update_option_blog_public', array( __CLASS__, 'check' ), 10, 0 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/** Deactivation: stop the daily check. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** A live site that asks search engines to stay away. */
	public static function hidden(): bool {
		return '0' === (string) get_option( 'blog_public' ) && Optima_Hub_Health::is_live();
	}

	/** Who hears about it. Partner sites set OPTIMA_HUB_REQUEST_EMAIL so nothing goes to a Optima address. */
	public static function recipient(): string {
		$to = defined( 'OPTIMA_HUB_REQUEST_EMAIL' ) ? (string) OPTIMA_HUB_REQUEST_EMAIL : 'sean@optimadigitalmedia.com';
		return (string) apply_filters( 'optima_build_alert_email', $to );
	}

	/**
	 * Email the alert when the live site is hidden and no alert went out in
	 * the last week. Once the site is visible again the clock resets, so the
	 * next time it is hidden the email goes out straight away.
	 *
	 * @return bool Whether an email was sent.
	 */
	public static function check(): bool {
		if ( ! self::hidden() ) {
			delete_option( self::SENT );
			return false;
		}
		$last = (int) get_option( self::SENT, 0 );
		if ( $last && time() - $last < self::REPEAT ) {
			return false;
		}
		$to = self::recipient();
		if ( '' === $to ) {
			return false;
		}
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$site = '' !== $site ? $site : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		/* translators: %s: site name */
		$subject = sprintf( __( '%s is hidden from Google', 'optima-build' ), $site );
		$body    = implode(
			"\n\n",
			array(
				/* translators: %s: site address */
				sprintf( __( 'The live site at %s is set to ask search engines to stay away. Google drops pages it is asked to skip, so the site can fall out of search within days.', 'optima-build' ), home_url( '/' ) ),
				__( 'To fix it, open Settings, Reading, untick "Discourage search engines from indexing this site", and save:', 'optima-build' ) . "\n" . admin_url( 'options-reading.php' ),
				__( 'This email comes once a week while the setting stays on. Optima Build sent it.', 'optima-build' ),
			)
		);
		$sent = wp_mail( $to, $subject, $body );
		if ( $sent ) {
			update_option( self::SENT, time(), false );
		}
		return (bool) $sent;
	}

	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::hidden() ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'This live site is hidden from Google.', 'optima-build' ),
			esc_html__( 'Settings, Reading asks search engines to stay away, so the site can drop out of search.', 'optima-build' ),
			esc_url( admin_url( 'options-reading.php' ) ),
			esc_html__( 'Fix it in Settings, Reading', 'optima-build' )
		);
	}
}
