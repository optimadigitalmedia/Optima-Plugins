<?php
/**
 * Saved secrets that can stop working without anyone touching them.
 *
 * The Email token or app password and the theme key are stored encrypted with
 * a key made from wp_salt( 'auth' ). When a host rotates the WordPress security
 * keys, the stored value can no longer be read and the screens used to fall
 * back to "not set", silently, while form email went out through the host and
 * landed in junk.
 *
 * So each secret keeps a short salt fingerprint (the first 8 hex characters of
 * sha256( wp_salt( 'auth' ) ), never the salt) from when it was saved. When the
 * fingerprint differs, or the value will not decrypt, the secret reads as
 * "can't be read" instead of "not set". Nothing here logs or shows a secret or
 * the salt.
 *
 * This is also the one place the cipher lives (seal() and open()), in the same
 * format the Email and theme key screens have always written ('e:' base64 of
 * IV and AES-256-CBC, or 'p:' base64 without openssl), so values saved before
 * this class existed read the same.
 *
 * The Email settings are watched from here (the optima_mail option), so the
 * Email screen itself does not change: a new token or password records the
 * fingerprint, and a cleared one drops it.
 *
 * Plain logic only, so it runs without WordPress (tools/tests/build-secret-health.php).
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Secret {

	/** Fingerprints by secret: mail, theme. */
	const PRINTS = 'optima_hub_secret_prints';

	/** The Email settings option (Optima_Hub_Mail::OPTION) and the context its key is made with. */
	const MAIL_OPTION  = 'optima_mail';
	const MAIL_CONTEXT = 'optima-mail';

	const NONE       = 'none';
	const OK         = 'ok';
	const UNREADABLE = 'unreadable';

	/** @var array<string,array{state:string,plain:string}> One answer per secret and stored value, per request. */
	private static $cache = array();

	public static function boot(): void {
		add_action( 'add_option_' . self::MAIL_OPTION, array( __CLASS__, 'mail_added' ), 10, 2 );
		add_action( 'update_option_' . self::MAIL_OPTION, array( __CLASS__, 'mail_updated' ), 10, 2 );
		add_action( 'delete_option_' . self::MAIL_OPTION, array( __CLASS__, 'mail_deleted' ) );
	}

	/* ---------- the cipher ---------- */

	/** The AES key for one context ('optima-mail', 'optima-theme'). */
	private static function key( string $context ): string {
		return hash( 'sha256', wp_salt( 'auth' ) . $context, true );
	}

	/** Encrypt a secret for storing. */
	public static function seal( string $plain, string $context ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'p:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv = random_bytes( 16 );
		return 'e:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', self::key( $context ), OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** Decrypt a stored secret, or '' when it can't be read. */
	public static function open( string $stored, string $context ): string {
		if ( '' === $stored ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'p:' ) ) {
			return $raw;
		}
		if ( ! function_exists( 'openssl_decrypt' ) || strlen( $raw ) < 17 ) {
			return '';
		}
		$out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key( $context ), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $out ? '' : $out;
	}

	/* ---------- fingerprints ---------- */

	/** The first 8 hex characters of sha256 of the auth salt. Never the salt itself. */
	public static function fingerprint(): string {
		return substr( hash( 'sha256', (string) wp_salt( 'auth' ) ), 0, 8 );
	}

	/** Call right after a secret is saved: remembers which security keys it was made with. */
	public static function saved( string $name ): void {
		$prints          = self::prints();
		$prints[ $name ] = self::fingerprint();
		update_option( self::PRINTS, $prints, false );
		self::$cache = array();
	}

	/** Call when a secret is removed. */
	public static function forgot( string $name ): void {
		$prints = self::prints();
		if ( isset( $prints[ $name ] ) ) {
			unset( $prints[ $name ] );
			update_option( self::PRINTS, $prints, false );
		}
		self::$cache = array();
	}

	/**
	 * Read one stored secret.
	 *
	 * @param string   $name    mail or theme.
	 * @param string   $stored  The option value: '', 'e:...' (encrypted) or 'p:...' (no openssl).
	 * @param callable $decrypt decrypt( string ): string.
	 * @return array{state:string,plain:string} state is none, ok or unreadable.
	 */
	public static function read( string $name, string $stored, callable $decrypt ): array {
		if ( '' === $stored ) {
			return array( 'state' => self::NONE, 'plain' => '' );
		}
		$ck = $name . ':' . md5( $stored );
		if ( isset( self::$cache[ $ck ] ) ) {
			return self::$cache[ $ck ];
		}
		$prints = self::prints();
		$saved  = (string) ( $prints[ $name ] ?? '' );
		$now    = self::fingerprint();
		$sealed = 0 === strpos( $stored, 'e:' );
		if ( $sealed && '' !== $saved && ! hash_equals( $saved, $now ) ) {
			// The security keys changed since it was saved: don't even try.
			return self::$cache[ $ck ] = array( 'state' => self::UNREADABLE, 'plain' => '' );
		}
		$plain = (string) $decrypt( $stored );
		// A wrong key can, now and then, decrypt to noise. Every secret here is plain text.
		if ( '' === $plain || ! preg_match( '/^[\x20-\x7E]+$/', $plain ) ) {
			return self::$cache[ $ck ] = array( 'state' => self::UNREADABLE, 'plain' => '' );
		}
		if ( $sealed && '' === $saved ) {
			// Saved before fingerprints existed and still readable: note the keys it works with.
			$prints[ $name ] = $now;
			update_option( self::PRINTS, $prints, false );
		}
		return self::$cache[ $ck ] = array( 'state' => self::OK, 'plain' => $plain );
	}

	/** Forget this request's answers (tests, and after a save). */
	public static function reset(): void {
		self::$cache = array();
	}

	/** @return array<string,string> */
	private static function prints(): array {
		$p = get_option( self::PRINTS, array() );
		return is_array( $p ) ? $p : array();
	}

	/* ---------- the Email settings, watched from here ---------- */

	/** The stored Email token or password, as saved in the Email settings. */
	private static function mail_stored( $value ): string {
		return is_array( $value ) && is_string( $value['password'] ?? null ) ? (string) $value['password'] : '';
	}

	public static function mail_added( $option, $value ): void {
		if ( '' !== self::mail_stored( $value ) ) {
			self::saved( 'mail' );
		}
	}

	public static function mail_updated( $old, $value ): void {
		$before = self::mail_stored( $old );
		$after  = self::mail_stored( $value );
		if ( $before === $after ) {
			return;
		}
		if ( '' === $after ) {
			self::forgot( 'mail' );
		} else {
			self::saved( 'mail' );
		}
	}

	public static function mail_deleted(): void {
		self::forgot( 'mail' );
	}

	/** Whether the Email token or password is stored and can be read: none, ok or unreadable. */
	public static function mail_state(): string {
		if ( defined( 'OPTIMA_MAIL_PASSWORD' ) && '' !== (string) OPTIMA_MAIL_PASSWORD ) {
			return self::OK;
		}
		$stored = self::mail_stored( get_option( self::MAIL_OPTION, array() ) );
		return self::read( 'mail', $stored, static fn( string $v ): string => self::open( $v, self::MAIL_CONTEXT ) )['state'];
	}

	/* ---------- the Site health rows ---------- */

	/** Site email when the saved token or password can't be read. */
	public static function mail_row( string $url ): array {
		return array(
			'label'     => __( 'Site email', 'optima-build' ),
			'value'     => __( 'Can\'t be read', 'optima-build' ),
			'state'     => 'bad',
			'note'      => __( 'The saved email token or password can\'t be read anymore. This happens when the site\'s security keys change. Paste it again on Email and send a test.', 'optima-build' ),
			'link'      => $url,
			'link_text' => __( 'Paste it again', 'optima-build' ),
		);
	}

	/** Theme updates when the saved key can't be read. */
	public static function theme_row( string $url ): array {
		return array(
			'label'     => __( 'Theme updates', 'optima-build' ),
			'value'     => __( 'Stopped', 'optima-build' ),
			'state'     => 'bad',
			'note'      => __( 'Theme updates stopped: the saved key can\'t be read. Paste it again.', 'optima-build' ),
			'link'      => $url,
			'link_text' => __( 'Paste the key', 'optima-build' ),
		);
	}
}
