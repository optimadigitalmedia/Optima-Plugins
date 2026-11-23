<?php
/**
 * Cloudflare Turnstile, an optional extra layer for Gravity Forms. Off unless
 * wp-config.php names both keys (Cloudflare dashboard, Turnstile, add the
 * site's domain, "Managed" or "Invisible"):
 *   define( 'OPTIMA_TURNSTILE_SITE_KEY', '...' );
 *   define( 'OPTIMA_TURNSTILE_SECRET', '...' );
 * The keys live only in wp-config.php: never in the database, and the secret
 * is never printed.
 *
 * With both set, every Gravity Form gets the Turnstile check (it shows only
 * when Cloudflare needs the visitor to tick a box), and Cloudflare's script
 * (https://challenges.cloudflare.com/turnstile/v0/api.js) loads deferred,
 * only on pages that show a form. When the form is sent, the site asks
 * Cloudflare (https://challenges.cloudflare.com/turnstile/v0/siteverify)
 * whether the answer is good, passing the visitor's address from Optima Core's
 * optima_client_ip(). A missing or failed answer marks the entry as spam, the
 * same way the content layer does: the visitor sees the normal confirmation,
 * no notification goes out, and the entry waits in the form's Spam folder in
 * case a real person was caught. It never stops a form with an error. When
 * Cloudflare can't be reached, the entry is let through and the other layers
 * still run.
 *
 * Turnstile is a security check, not tracking or advertising, so it runs
 * without cookie consent, like the site's own form protection (see
 * visitor-data.json).
 *
 * Filters: `optima_forms_guard_turnstile` (bool, $form) skips one form.
 *
 * @package Optima_Forms_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Forms_Guard_Turnstile {

	const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	const FIELD  = 'cf-turnstile-response';
	const HANDLE = 'optima-turnstile';

	/** Answers already checked in this request, so one submission is checked once. @var array<string,bool|null> */
	private static $checked = array();

	public static function boot(): void {
		if ( ! self::on() ) {
			return;
		}
		add_filter( 'gform_get_form_filter', array( __CLASS__, 'inject' ), 11, 2 );
		add_filter( 'gform_entry_is_spam', array( __CLASS__, 'is_spam' ), 9, 3 );
	}

	/** Both keys are set in wp-config.php. */
	public static function on(): bool {
		return '' !== self::site_key() && '' !== self::secret();
	}

	public static function site_key(): string {
		return defined( 'OPTIMA_TURNSTILE_SITE_KEY' ) ? trim( (string) constant( 'OPTIMA_TURNSTILE_SITE_KEY' ) ) : '';
	}

	private static function secret(): string {
		return defined( 'OPTIMA_TURNSTILE_SECRET' ) ? trim( (string) constant( 'OPTIMA_TURNSTILE_SECRET' ) ) : '';
	}

	private static function applies( $form ): bool {
		return self::on() && ! empty( $form['id'] ) && (bool) apply_filters( 'optima_forms_guard_turnstile', true, $form );
	}

	/** The widget, above the form's submit button, and the script for this page. */
	public static function inject( $form_string, $form ) {
		if ( ! is_string( $form_string ) || ! self::applies( $form ) || false !== strpos( $form_string, 'cf-turnstile' ) ) {
			return $form_string;
		}
		self::enqueue();
		$widget = '<div class="optima-turnstile"><div class="cf-turnstile" data-sitekey="' . esc_attr( self::site_key() ) . '" data-appearance="interaction-only" data-response-field-name="' . esc_attr( self::FIELD ) . '"></div></div>';
		foreach ( array( '<div class="gform_footer', '<div class="gform-footer', '<div class="gform-page-footer', '<div class="gform_page_footer' ) as $footer ) {
			$pos = strpos( $form_string, $footer );
			if ( false !== $pos ) {
				return substr( $form_string, 0, $pos ) . $widget . substr( $form_string, $pos );
			}
		}
		$pos = strripos( $form_string, '</form>' );
		return false === $pos ? $form_string . $widget : substr( $form_string, 0, $pos ) . $widget . substr( $form_string, $pos );
	}

	/** Cloudflare's script, deferred, only on a page that shows a form. A form redrawn by AJAX gets its widget back. */
	private static function enqueue(): void {
		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			// No version: Cloudflare serves this one address and asks that it is loaded as is.
			wp_register_script( self::HANDLE, self::SCRIPT, array(), null, array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			// Runs before the deferred script (a "before" script keeps it deferred).
			wp_add_inline_script(
				self::HANDLE,
				"(function(){function r(){if(!window.turnstile){return;}document.querySelectorAll('.optima-turnstile .cf-turnstile').forEach(function(el){if(!el.querySelector('iframe')){try{window.turnstile.render(el);}catch(e){}}});}if(window.jQuery){window.jQuery(document).on('gform_post_render',r);}})();",
				'before'
			);
		}
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * gform_entry_is_spam: a missing or failed Turnstile answer marks the
	 * entry as spam. Runs before the content layer, and counts in the same
	 * monthly blocked total.
	 */
	public static function is_spam( $is_spam, $form, $entry ) {
		if ( $is_spam || ! self::applies( $form ) ) {
			return $is_spam;
		}
		$token = isset( $_POST[ self::FIELD ] ) ? trim( (string) wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- sent to Cloudflare only.
		$ip    = function_exists( 'optima_client_ip' ) ? optima_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sent to Cloudflare only.
		if ( false === self::verify( $token, $ip ) ) {
			Optima_Forms_Guard::record_block( $form );
			return true;
		}
		return $is_spam;
	}

	/**
	 * Ask Cloudflare whether a Turnstile answer is good.
	 *
	 * @return bool|null True good, false missing or failed, null when Cloudflare couldn't be reached.
	 */
	public static function verify( string $token, string $ip = '' ) {
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return false;
		}
		$key = md5( $token );
		if ( array_key_exists( $key, self::$checked ) ) {
			return self::$checked[ $key ];
		}
		$body = array(
			'secret'   => self::secret(),
			'response' => $token,
		);
		if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$body['remoteip'] = $ip;
		}
		$r = wp_safe_remote_post( self::VERIFY, array( 'timeout' => 8, 'body' => $body ) );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			self::$checked[ $key ] = null;
			return null;
		}
		$data                  = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		self::$checked[ $key ] = is_array( $data ) && ! empty( $data['success'] );
		return self::$checked[ $key ];
	}

	/** One line for the Spam protection screen: whether the layer is on, and how to turn it on. */
	public static function status(): array {
		if ( self::on() ) {
			return array(
				'label' => 'Cloudflare Turnstile',
				'value' => 'On',
				'state' => 'good',
				'note'  => 'Every form also asks Cloudflare whether the visitor is a person. A visitor sees a box to tick only when Cloudflare is unsure. A failed check sends the message to the form\'s Spam folder.',
			);
		}
		return array(
			'label' => 'Cloudflare Turnstile',
			'value' => 'Off',
			'state' => 'good',
			'note'  => 'An optional extra check from Cloudflare. To turn it on, add OPTIMA_TURNSTILE_SITE_KEY and OPTIMA_TURNSTILE_SECRET to wp-config.php.',
		);
	}
}
