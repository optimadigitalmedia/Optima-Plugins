<?php
/**
 * Spam protection and the security baseline for client sites (plugin-first
 * rule 19), every switch on unless switched off:
 *
 *  - Email guard. Contact emails print in plain text (mailto links, visible
 *    text, the JSON-LD `email`), and scrapers harvest them. One output buffer,
 *    started late on template_redirect, rewrites the finished HTML so every
 *    source (theme, block bindings, plugins, schema) is covered at once:
 *      * href="mailto:..." and visible text: the address becomes a mix of
 *        decimal and hex character references (antispambot() style, but the
 *        same output every time so cached pages stay stable). Browsers show
 *        and click them as normal.
 *      * <script type="application/ld+json">: "@" inside an email becomes the
 *        JSON escape \u0040. The JSON stays valid and decodes to the same
 *        value, so Google reads the same address.
 *      * Left alone: other scripts, <style>, <textarea>, HTML comments, CDATA,
 *        and every attribute other than a mailto href (so <input value>,
 *        data-*, meta content are untouched). <noscript> holds ordinary
 *        markup for visitors without JavaScript, so it is guarded like the
 *        rest of the page.
 *    A page cache stores what this buffer returns, so cached pages are already
 *    guarded. On any error the original HTML is sent unchanged.
 *    Filter: `optima_core_email_guard` (bool).
 *
 *  - Close comments and pingbacks. comments_open and pings_open return false
 *    outside wp-admin, the X-Pingback header goes, and the pingback XML-RPC
 *    methods are removed. No comment is deleted; switching it off restores
 *    exactly what WordPress did before.
 *    Filter: `optima_core_close_comments` (bool).
 *
 *  - Hide login names, XML-RPC off, basic browser protections (nosniff,
 *    SAMEORIGIN framing, a referrer policy, HSTS on HTTPS; a header the host or
 *    Cloudflare already sends is kept), and the code editor off (also on WP
 *    Engine, whose wp-config.php sets DISALLOW_FILE_EDIT to false: the edit
 *    capabilities are taken away too). Filters: `optima_core_hide_users`,
 *    `optima_core_xmlrpc_needed`, `optima_core_browser_headers`.
 *
 *  - Limit failed logins: 5 failures in 15 minutes from one address or for one
 *    username lock it out for 15 minutes (Optima_Core_Login_Limit, login-limit.php,
 *    which lists its filters).
 *
 * A site that needs one of these on (rule 19: the reason goes in the site's
 * decisions log) turns it off on Optima, Search and AI, Spam protection, or in
 * wp-config.php, which the screen cannot undo:
 *
 *   define( 'OPTIMA_CORE_PROTECTION_OFF', 'xmlrpc_off, file_edit_off, login_limit' );
 *
 * or with the `optima_core_protection_on` filter ($on, $key).
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Protection {

	const OPTION = 'optima_core_protection';

	/** Simple email shape: local part, @, dotted domain with a letter TLD. */
	const EMAIL = '[A-Za-z0-9._%+\-]+@[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,24}';

	/**
	 * Raw-text elements carried across a mid-page flush, so the next chunk
	 * knows it starts inside one.
	 *
	 * @var string
	 */
	private static $open = '';

	/** @return array<string,string> setting key => label */
	public static function fields() {
		return array(
			'email_guard'     => 'Hide email addresses from spam bots',
			'close_comments'  => 'Close comments and pingbacks',
			'hide_users'      => 'Hide login names from visitors',
			'xmlrpc_off'      => 'Turn off XML-RPC',
			'browser_headers' => 'Send basic browser protections',
			'file_edit_off'   => 'Turn off the code editor',
			'login_limit'     => 'Limit failed logins',
		);
	}

	/** @return array<string,string> Each switch is "1" (on) or "0" (off). All default on. */
	public static function get() {
		$defaults = array_fill_keys( array_keys( self::fields() ), '1' );
		$saved    = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public static function on( $key ) {
		$all = self::get();
		$on  = isset( $all[ $key ] ) && '1' === (string) $all[ $key ] && ! in_array( (string) $key, self::forced_off(), true );
		return (bool) apply_filters( 'optima_core_protection_on', $on, (string) $key );
	}

	/** Switches wp-config.php turns off (OPTIMA_CORE_PROTECTION_OFF, a comma list of keys). */
	public static function forced_off(): array {
		if ( ! defined( 'OPTIMA_CORE_PROTECTION_OFF' ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', strtolower( (string) OPTIMA_CORE_PROTECTION_OFF ) ) ) ) );
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_guard' ), 9999 );
		add_action( 'init', array( __CLASS__, 'maybe_close_comments' ), 20 );
		add_action( 'init', array( __CLASS__, 'maybe_harden' ), 20 );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_lock_editor' ), 0 );
	}

	/**
	 * Plugin-first rule 19: no editing theme or plugin code from wp-admin. Same as
	 * DISALLOW_FILE_EDIT in wp-config.php, set from here so nobody has to edit that file.
	 * Some hosts (WP Engine) put DISALLOW_FILE_EDIT false in wp-config.php, and a constant
	 * can't be changed once set, so the switch also takes the editing capabilities away.
	 */
	public static function maybe_lock_editor() {
		if ( ! self::on( 'file_edit_off' ) ) {
			return;
		}
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}
		add_filter( 'map_meta_cap', array( __CLASS__, 'deny_editor' ), 10, 2 );
	}

	/** True when the switch keeps the code editors off. */
	public static function editor_locked() {
		return self::on( 'file_edit_off' );
	}

	/**
	 * @param string[] $caps Capabilities the check needs.
	 * @param string   $cap  The capability asked for.
	 */
	public static function deny_editor( $caps, $cap ) {
		return in_array( $cap, array( 'edit_themes', 'edit_plugins', 'edit_files' ), true ) ? array( 'do_not_allow' ) : $caps;
	}

	public static function register_settings() {
		register_setting(
			'optima_protection_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/** Unchecked boxes are not posted, so every switch is stored as "1" or "0". */
	public static function sanitize( $input ) {
		$input = (array) $input;
		$clean = array();
		foreach ( self::fields() as $key => $label ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? '0' : '1';
		}
		return $clean;
	}

	/* ---------- Email guard ---------- */

	/** Is this a front-end HTML page the guard should rewrite? */
	public static function guard_applies() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || ( function_exists( 'is_favicon' ) && is_favicon() ) ) {
			return false;
		}
		if ( '' !== (string) get_query_var( 'sitemap' ) || '' !== (string) get_query_var( 'sitemap-stylesheet' ) ) {
			return false;
		}
		if ( ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) || ( function_exists( 'wp_is_xml_request' ) && wp_is_xml_request() ) ) {
			return false;
		}
		return true;
	}

	public static function maybe_guard() {
		if ( ! (bool) apply_filters( 'optima_core_email_guard', self::on( 'email_guard' ) ) ) {
			return;
		}
		if ( ! self::guard_applies() ) {
			return;
		}
		self::$open = '';
		ob_start( array( __CLASS__, 'buffer' ) );
	}

	/**
	 * Output-buffer callback. Never breaks the page: anything unexpected sends
	 * the original HTML.
	 *
	 * @param string $buffer The page (or a chunk of it, after a mid-page flush).
	 * @return string
	 */
	public static function buffer( $buffer ) {
		if ( ! is_string( $buffer ) || '' === $buffer || false === strpos( $buffer, '@' ) ) {
			return $buffer;
		}
		if ( ! self::is_html_response( $buffer ) ) {
			return $buffer;
		}
		try {
			$out = self::guard_html( $buffer );
			return is_string( $out ) ? $out : $buffer;
		} catch ( \Throwable $e ) {
			return $buffer;
		}
	}

	/** Content type is HTML (or unset) and the body is not JSON or XML. */
	private static function is_html_response( $buffer ) {
		if ( function_exists( 'headers_list' ) ) {
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'content-type:' ) ) {
					if ( ! preg_match( '#text/html|application/xhtml\+xml#i', $header ) ) {
						return false;
					}
				}
			}
		}
		$start = ltrim( substr( $buffer, 0, 64 ) );
		if ( '' !== $start && ( '{' === $start[0] || '[' === $start[0] || 0 === strncasecmp( $start, '<?xml', 5 ) ) ) {
			return false;
		}
		return false !== strpos( $buffer, '<' ) || '' !== self::$open;
	}

	/**
	 * Guard every email in an HTML string. Pure string work, so the test
	 * harness can call it without WordPress. Idempotent: an encoded address
	 * has no "@" left to match.
	 *
	 * @param string $html Page HTML (or a chunk, continuing self::$open).
	 * @return string|null Null when a regex fails (caller sends the original).
	 */
	public static function guard_html( $html ) {
		if ( false === strpos( $html, '@' ) ) {
			return $html;
		}
		$out = '';
		$pos = 0;
		$len = strlen( $html );

		// Still inside a raw-text element from the previous chunk.
		if ( '' !== self::$open ) {
			$close = self::find_close( $html, 0, self::$open );
			if ( null === $close ) {
				return $html; // The whole chunk is inside it.
			}
			$out       .= 'ldjson' === self::$open ? self::guard_json( substr( $html, 0, $close ) ) : substr( $html, 0, $close );
			$pos        = $close;
			self::$open = '';
		}

		// Protected regions: comments, CDATA, and the raw-text elements.
		$pattern = '#<!--|<!\[CDATA\[|<(script|style|textarea|title|xmp|iframe|noembed|noframes|plaintext)\b([^>]*)>#i';
		while ( $pos < $len ) {
			if ( ! preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
				$text = self::guard_markup( substr( $html, $pos ) );
				if ( null === $text ) {
					return null;
				}
				$out .= $text;
				break;
			}
			$at   = $m[0][1];
			$text = self::guard_markup( substr( $html, $pos, $at - $pos ) );
			if ( null === $text ) {
				return null;
			}
			$out .= $text;

			if ( '<!--' === $m[0][0] || 0 === strncasecmp( $m[0][0], '<![CDATA[', 9 ) ) {
				$end_tok = '<!--' === $m[0][0] ? '-->' : ']]>';
				$end     = strpos( $html, $end_tok, $at + strlen( $m[0][0] ) );
				$end     = false === $end ? $len : $end + strlen( $end_tok );
				$out    .= substr( $html, $at, $end - $at );
				$pos     = $end;
				continue;
			}

			$tag     = strtolower( $m[1][0] );
			$open    = $m[0][0];
			$body_at = $at + strlen( $open );
			$out    .= $open;

			$kind = $tag;
			if ( 'script' === $tag && preg_match( '#\btype\s*=\s*(["\']?)\s*application/ld\+json\s*\1#i', $m[2][0] ) ) {
				$kind = 'ldjson';
			}
			$close = self::find_close( $html, $body_at, $kind );
			if ( null === $close ) {
				// Runs past the end of this chunk; carry the state forward.
				$body       = substr( $html, $body_at );
				$out       .= 'ldjson' === $kind ? self::guard_json( $body ) : self::raw_body( $tag, $body );
				self::$open = $kind;
				break;
			}
			$body = substr( $html, $body_at, $close - $body_at );
			$out .= 'ldjson' === $kind ? self::guard_json( $body ) : self::raw_body( $tag, $body );
			$pos  = $close;
		}
		return $out;
	}

	/** The title is shown text (entities decode there); everything else raw stays as is. */
	private static function raw_body( $tag, $body ) {
		if ( 'title' === $tag ) {
			$guarded = self::guard_text( $body );
			return null === $guarded ? $body : $guarded;
		}
		return $body;
	}

	/** Offset of the closing tag for a raw-text element, or null when not in this chunk. */
	private static function find_close( $html, $from, $kind ) {
		$tag = 'ldjson' === $kind ? 'script' : $kind;
		if ( 'plaintext' === $tag ) {
			return null; // Never closes.
		}
		if ( preg_match( '#</' . $tag . '\s*>#i', $html, $m, PREG_OFFSET_CAPTURE, $from ) ) {
			return $m[0][1];
		}
		return null;
	}

	/**
	 * Ordinary markup: split into tags and text in one pass. Text gets its
	 * emails encoded; inside tags only an href="mailto:..." value is touched.
	 */
	private static function guard_markup( $chunk ) {
		if ( '' === $chunk || false === strpos( $chunk, '@' ) ) {
			return $chunk;
		}
		return preg_replace_callback(
			'#(<[A-Za-z](?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>)|([^<]++)|(<)#',
			static function ( $m ) {
				if ( isset( $m[1] ) && '' !== $m[1] ) {
					return self::guard_tag( $m[1] );
				}
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					$t = self::guard_text( $m[2] );
					return null === $t ? $m[2] : $t;
				}
				return $m[0];
			},
			$chunk
		);
	}

	/** An opening tag: encode the address in a mailto href, leave every other attribute. */
	private static function guard_tag( $tag ) {
		if ( false === strpos( $tag, '@' ) || false === stripos( $tag, 'mailto:' ) ) {
			return $tag;
		}
		$out = preg_replace_callback(
			'#(\shref\s*=\s*)(?:"(mailto:[^"]*)"|\'(mailto:[^\']*)\'|(mailto:[^\s>"\']+))#i',
			static function ( $m ) {
				$quote = '';
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					$value = $m[2];
					$quote = '"';
				} elseif ( isset( $m[3] ) && '' !== $m[3] ) {
					$value = $m[3];
					$quote = "'";
				} else {
					$value = $m[4];
				}
				$guarded = self::guard_text( $value );
				return $m[1] . $quote . ( null === $guarded ? $value : $guarded ) . $quote;
			},
			$tag
		);
		return null === $out ? $tag : $out;
	}

	/** Encode every email address in a run of text. */
	public static function guard_text( $text ) {
		if ( false === strpos( $text, '@' ) ) {
			return $text;
		}
		return preg_replace_callback(
			'#(?<![A-Za-z0-9._%+\-&\#])' . self::EMAIL . '(?![A-Za-z0-9\-])#',
			static function ( $m ) {
				return self::encode( $m[0] );
			},
			$text
		);
	}

	/**
	 * Mixed decimal and hex character references, like antispambot(), but the
	 * same output every time (stable cached pages). "@" is always encoded.
	 */
	public static function encode( $email ) {
		$out = '';
		$len = strlen( $email );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $email[ $i ];
			if ( '@' === $c ) {
				$out .= '&#64;';
				continue;
			}
			switch ( $i % 3 ) {
				case 0:
					$out .= '&#' . ord( $c ) . ';';
					break;
				case 1:
					$out .= '&#x' . dechex( ord( $c ) ) . ';';
					break;
				default:
					$out .= $c;
			}
		}
		return $out;
	}

	/**
	 * JSON-LD: escape "@" inside email addresses as \u0040. Outside strings
	 * JSON has no letters next to "@", and the escape is inserted after a
	 * whole character, never inside another escape, so the JSON stays valid.
	 */
	public static function guard_json( $json ) {
		if ( false === strpos( $json, '@' ) ) {
			return $json;
		}
		$out = preg_replace(
			'#(?<=[A-Za-z0-9._%+\-])@(?=[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,24}(?![A-Za-z0-9\-]))#',
			'\\\\u0040',
			$json
		);
		return null === $out ? $json : $out;
	}

	/* ---------- Comments and pingbacks ---------- */

	public static function maybe_close_comments() {
		if ( ! (bool) apply_filters( 'optima_core_close_comments', self::on( 'close_comments' ) ) ) {
			return;
		}
		if ( ! is_admin() ) {
			add_filter( 'comments_open', '__return_false', 20 );
			add_filter( 'pings_open', '__return_false', 20 );
			// The head keeps the comment-feed link WordPress printed before (feed_links_extra, wp_head 3).
			add_action( 'wp_head', array( __CLASS__, 'feed_links_open' ), 2 );
			add_action( 'wp_head', array( __CLASS__, 'feed_links_close' ), 4 );
		}
		add_filter( 'wp_headers', array( __CLASS__, 'drop_pingback_header' ) );
		add_filter( 'xmlrpc_methods', array( __CLASS__, 'drop_pingback_methods' ) );
	}

	/** Let feed_links_extra see the post's real comment status, so its feed link stays. */
	public static function feed_links_open() {
		remove_filter( 'comments_open', '__return_false', 20 );
		remove_filter( 'pings_open', '__return_false', 20 );
	}

	public static function feed_links_close() {
		add_filter( 'comments_open', '__return_false', 20 );
		add_filter( 'pings_open', '__return_false', 20 );
	}

	public static function drop_pingback_header( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	public static function drop_pingback_methods( $methods ) {
		if ( is_array( $methods ) ) {
			unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		}
		return $methods;
	}

	/* ---------- Hardening ---------- */

	/**
	 * The security baseline (plugin-first rule 19). Visitors who are not signed in cannot list login names, XML-RPC
	 * is off unless Jetpack needs it, and every page sends the basic browser protections. Signed-in
	 * editors and the block editor are unaffected. Optima Build's account privacy also closes the
	 * users routes, and a theme that still does any of this is harmless: the same filters run twice
	 * with the same result.
	 */
	public static function maybe_harden() {
		if ( self::on( 'hide_users' ) && (bool) apply_filters( 'optima_core_hide_users', true ) ) {
			add_filter( 'rest_endpoints', array( __CLASS__, 'hide_user_routes' ) );
		}
		if ( self::on( 'xmlrpc_off' ) && ! self::xmlrpc_needed() ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
			add_filter( 'wp_headers', array( __CLASS__, 'drop_pingback_header' ), 20 );
		}
		if ( self::on( 'browser_headers' ) ) {
			add_filter( 'wp_headers', array( __CLASS__, 'browser_headers' ) );
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
	}

	/** Jetpack and the WordPress mobile apps talk to the site over XML-RPC. */
	public static function xmlrpc_needed() {
		return (bool) apply_filters( 'optima_core_xmlrpc_needed', class_exists( 'Jetpack' ) || defined( 'JETPACK__VERSION' ) );
	}

	/** The /wp/v2/users routes list every author's login name. Hidden from visitors only. */
	public static function hide_user_routes( $endpoints ) {
		if ( ! is_array( $endpoints ) || ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( (string) $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/** Headers already set by the host (WP Engine, Cloudflare) are left as they are. */
	public static function browser_headers( $headers ) {
		$headers = is_array( $headers ) ? $headers : array();
		$add     = array(
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options'        => 'SAMEORIGIN',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
		);
		if ( function_exists( 'is_ssl' ) && is_ssl() ) {
			$add['Strict-Transport-Security'] = 'max-age=31536000';
		}
		foreach ( (array) apply_filters( 'optima_core_browser_headers', $add ) as $name => $value ) {
			if ( ! isset( $headers[ $name ] ) ) {
				$headers[ $name ] = $value;
			}
		}
		return $headers;
	}

	/* ---------- Settings tab ---------- */

	public static function render_tab() {
		$values = self::get();
		$notes  = array(
			'email_guard'     => 'Email addresses on your pages are coded so spam bots miss them. People still see and click them.',
			'close_comments'  => 'Turns off new comments and pingbacks, which mostly bring spam. Saved comments stay.',
			'hide_users'      => 'Visitors cannot look up login names, which are half of a password guess.',
			'xmlrpc_off'      => 'XML-RPC is an old way to post from other apps that bots use to guess passwords. It stays on with Jetpack.',
			'browser_headers' => 'Tells browsers to use a secure connection and not let other sites frame your pages. Hides the WordPress version.',
			'file_edit_off'   => 'Hides the theme and plugin code editors in WordPress, so a stolen login can\'t change site code.',
			'login_limit'     => 'After 5 failed logins in 15 minutes, that address or username waits 15 minutes before it can try again. The login screen never says whether a username exists.',
		);
		// What needs doing first (rule 52): any switch of the baseline that is off.
		$forced = self::forced_off();
		$off    = array();
		foreach ( self::fields() as $key => $label ) {
			if ( ! self::on( $key ) ) {
				$off[] = $label;
			}
		}
		echo '<section class="sh-box"><div class="sh-status' . ( $off ? ' is-warn' : '' ) . '"><span class="sh-dot" aria-hidden="true"></span><p class="sh-value">'
			. esc_html( $off ? sprintf( _n( '%d switch is off: %s.', '%d switches are off: %s.', count( $off ), 'optima-core' ), count( $off ), implode( ', ', $off ) ) : __( 'Every switch is on.', 'optima-core' ) )
			. '</p></div>';
		if ( $off ) {
			echo '<p class="sh-note">' . esc_html__( 'The security baseline has every switch on. A site that needs one off records why in its decisions log.', 'optima-core' ) . '</p>';
		}
		echo '</section>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'optima_protection_group' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( self::fields() as $key => $label ) {
			$id   = 'optima-protect-' . $key;
			$name = self::OPTION . '[' . $key . ']';
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			if ( in_array( $key, $forced, true ) ) {
				// wp-config.php turns it off; keep the saved value so removing the line restores it.
				printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $values[ $key ] ) );
				echo '<p>' . esc_html__( 'Off in wp-config.php (OPTIMA_CORE_PROTECTION_OFF).', 'optima-core' ) . '</p>';
			} else {
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( '1', $values[ $key ], false ),
					esc_html__( 'On', 'optima-core' )
				);
			}
			echo '<p class="description">' . esc_html( $notes[ $key ] ) . '</p>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}
}
