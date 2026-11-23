<?php
/**
 * Optima Mail: the site's email (form notices, password resets, Optima checks)
 * goes out through a real mail service instead of the host's mail server.
 *
 * Managed hosts like WP Engine send from their own servers, which fail the
 * sender domain's SPF, so the mail lands in junk or vanishes. Here the site
 * signs in to a real mail server and sends as an address on its own domain.
 *
 * Three routes, in the order the screen offers them:
 *  - Cloudflare Email Service (the default for a new setup): the domain's DNS
 *    is on Cloudflare, Cloudflare adds the sending records (on cf-bounce), and
 *    the site signs in to smtp.mx.cloudflare.net on port 465 (implicit TLS)
 *    with the user name "api_token" and a Cloudflare API token that has
 *    Email Sending: Edit. The From address must be on a domain added in
 *    Cloudflare, Email, Email Sending.
 *  - Google: one Gmail or Google Workspace address and a Google app password
 *    (smtp.gmail.com, port 587, STARTTLS). A site saved before routes existed
 *    (no 'service' key) keeps this route.
 *  - Other service: any SMTP relay, with the server, port and user typed in.
 *
 * Whatever "From" a form set (a client's own address, say) becomes the
 * Reply-To, so replies still reach the right person.
 *
 * The token or password is stored encrypted with the site's own salts, never
 * shown again, and can live in wp-config.php instead:
 *   define( 'OPTIMA_MAIL_PASSWORD', 'the Cloudflare token or Google app password' );
 *   define( 'OPTIMA_MAIL_SERVICE', 'cloudflare' ); // optional: cloudflare, google, other
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Mail {

	const PAGE   = 'optima-mail';
	const OPTION = 'optima_mail';
	const SAVE   = 'optima_mail_save';
	const TEST   = 'optima_mail_test';
	const HOST   = 'smtp.gmail.com';
	const PORT   = 587;

	/**
	 * How the site can send, in the order the screen lists them. A null host,
	 * port or user is typed on the screen; a null user on Google means the
	 * send-from address is the user name.
	 */
	const SERVICES = array(
		'cloudflare' => array( 'label' => 'Cloudflare Email Service', 'name' => 'Cloudflare', 'host' => 'smtp.mx.cloudflare.net', 'port' => 465, 'user' => 'api_token' ),
		'google'     => array( 'label' => 'Google (Gmail or Workspace)', 'name' => 'Google', 'host' => 'smtp.gmail.com', 'port' => 587, 'user' => null ),
		'other'      => array( 'label' => 'Other mail service (SMTP)', 'name' => '', 'host' => null, 'port' => null, 'user' => null ),
	);

	/** The route a new setup starts on. */
	const DEFAULT_SERVICE = 'cloudflare';

	/** @var string The last wp_mail error during a test send. */
	private static $error = '';

	public static function boot(): void {
		add_action( 'phpmailer_init', array( __CLASS__, 'mailer' ), 1000 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_' . self::SAVE, array( __CLASS__, 'save' ) );
		add_action( 'admin_post_' . self::TEST, array( __CLASS__, 'test' ) );
		add_filter( 'optima_admin_nav_hint', array( __CLASS__, 'nav_hint' ), 10, 2 );
		add_filter( 'gform_pre_send_email', array( __CLASS__, 'brand_form' ), 20, 4 );
	}

	/** @return array{service:string, host:string, port:int, user:string, email:string, name:string, password:string, accent:string, last:array} */
	private static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		if ( isset( $s['service'] ) ) {
			// An unknown value never strands a site: it sends through Google as before.
			$service = isset( self::SERVICES[ (string) $s['service'] ] ) ? (string) $s['service'] : 'google';
		} elseif ( '' !== (string) ( $s['email'] ?? '' ) || '' !== (string) ( $s['password'] ?? '' ) ) {
			$service = 'google'; // Saved before routes existed: it was Google then.
		} else {
			$service = self::DEFAULT_SERVICE;
		}
		if ( defined( 'OPTIMA_MAIL_SERVICE' ) && isset( self::SERVICES[ (string) OPTIMA_MAIL_SERVICE ] ) ) {
			$service = (string) OPTIMA_MAIL_SERVICE;
		}
		return array(
			'service'  => $service,
			'host'     => (string) ( $s['host'] ?? '' ),
			'port'     => (int) ( $s['port'] ?? 0 ),
			'user'     => (string) ( $s['user'] ?? '' ),
			'email'    => (string) ( $s['email'] ?? '' ),
			'name'     => (string) ( $s['name'] ?? '' ),
			'password' => (string) ( $s['password'] ?? '' ),
			'accent'   => (string) ( $s['accent'] ?? '' ),
			'last'     => is_array( $s['last'] ?? null ) ? $s['last'] : array(),
		);
	}

	/** The route the site sends through: cloudflare, google or other. */
	public static function service(): string {
		return self::settings()['service'];
	}

	/** The short name of the route for sentences: Cloudflare, Google, or the typed server. */
	public static function route_name(): string {
		$s    = self::settings();
		$name = (string) self::SERVICES[ $s['service'] ]['name'];
		if ( '' !== $name ) {
			return $name;
		}
		return '' !== $s['host'] ? $s['host'] : __( 'your mail service', 'optima-build' );
	}

	/**
	 * Where and as whom the site signs in.
	 *
	 * @return array{host:string, port:int, user:string}
	 */
	private static function server(): array {
		$s   = self::settings();
		$def = self::SERVICES[ $s['service'] ];
		if ( 'other' === $s['service'] ) {
			$port = in_array( $s['port'], array( 465, 587, 2525 ), true ) ? $s['port'] : 587;
			return array(
				'host' => $s['host'],
				'port' => $port,
				'user' => $s['user'],
			);
		}
		return array(
			'host' => (string) $def['host'],
			'port' => (int) $def['port'],
			'user' => null === $def['user'] ? $s['email'] : (string) $def['user'],
		);
	}

	private static function password(): string {
		if ( defined( 'OPTIMA_MAIL_PASSWORD' ) && '' !== (string) OPTIMA_MAIL_PASSWORD ) {
			return (string) OPTIMA_MAIL_PASSWORD;
		}
		$stored = self::settings()['password'];
		if ( class_exists( 'Optima_Hub_Secret' ) ) {
			// Reads as empty when the host changed the site's security keys since it was saved.
			return Optima_Hub_Secret::read( 'mail', $stored, static fn( string $v ): string => self::decrypt( $v ) )['plain'];
		}
		return self::decrypt( $stored );
	}

	/** Set up: an address, a token or password, and a server to sign in to. */
	public static function ready(): bool {
		$server = self::server();
		return is_email( self::settings()['email'] ) && '' !== self::password() && '' !== $server['host'] && '' !== $server['user'];
	}

	/**
	 * Settings after the route changes. A Google app password is not a
	 * Cloudflare token (and the other way round), so the saved secret goes, and
	 * the last test no longer says anything about the new route.
	 *
	 * @param array  $s       Saved settings.
	 * @param string $service The route picked on the screen.
	 */
	public static function with_service( array $s, string $service ): array {
		if ( ! isset( self::SERVICES[ $service ] ) || $service === ( $s['service'] ?? '' ) ) {
			return $s;
		}
		$s['service']  = $service;
		$s['password'] = '';
		$s['last']     = array();
		$s['host']     = '';
		$s['port']     = 0;
		$s['user']     = '';
		return $s;
	}

	/**
	 * The registrable part of a host name: example.com for forms.example.com,
	 * example.co.uk for www.example.co.uk. Good enough for a warning; no
	 * public suffix list.
	 */
	public static function registrable_domain( string $host ): string {
		$host   = strtolower( trim( $host, ". \t\n" ) );
		$labels = array_values( array_filter( explode( '.', $host ), 'strlen' ) );
		$n      = count( $labels );
		if ( $n <= 2 ) {
			return implode( '.', $labels );
		}
		$two   = $labels[ $n - 2 ] . '.' . $labels[ $n - 1 ];
		$takes = ( 2 === strlen( $labels[ $n - 1 ] ) && in_array( $labels[ $n - 2 ], array( 'co', 'com', 'net', 'org', 'gov', 'ac', 'edu', 'ltd', 'plc' ), true ) ) ? 3 : 2;
		return 3 === $takes ? $labels[ $n - 3 ] . '.' . $two : $two;
	}

	/**
	 * Is the send-from address on the site's own domain? Cloudflare only sends
	 * from a domain added in Email Sending, which for us is the site's domain.
	 *
	 * @param string $email The send-from address.
	 * @param string $home  The site address (defaults to home_url()).
	 */
	public static function from_on_site_domain( string $email, string $home = '' ): bool {
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return false;
		}
		$home = '' !== $home ? $home : home_url( '/' );
		$site = self::registrable_domain( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		return '' !== $site && self::registrable_domain( substr( $email, $at + 1 ) ) === $site;
	}

	/** The site's own domain, for placeholders and the checklist. */
	private static function site_domain(): string {
		return self::registrable_domain( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/* ---------------------------------------------------------------- */

	/** Route every wp_mail through the chosen service once the screen is filled in. */
	public static function mailer( $phpmailer ): void {
		if ( ! self::ready() ) {
			return;
		}
		$s      = self::settings();
		$server = self::server();
		$from   = $phpmailer->From;
		$name   = $phpmailer->FromName;

		$phpmailer->isSMTP();
		$phpmailer->Host       = (string) apply_filters( 'optima_mail_host', $server['host'] );
		$phpmailer->Port       = (int) apply_filters( 'optima_mail_port', $server['port'] );
		// Port 465 is implicit TLS (Cloudflare takes nothing else); 587 is STARTTLS.
		$phpmailer->SMTPSecure = 465 === (int) $phpmailer->Port ? 'ssl' : 'tls';
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Username   = $server['user'];
		$phpmailer->Password   = self::password();

		// The site always sends as the saved address (Google only allows the
		// signed-in address; Cloudflare only a domain added in Email Sending). A
		// different From the email asked for (a form's "send as the business
		// owner") becomes the Reply-To, unless the email already set one.
		if ( $from && strtolower( $from ) !== strtolower( $s['email'] ) && is_email( $from ) && ! $phpmailer->getReplyToAddresses() && ! self::is_default_from( $from ) ) {
			$phpmailer->addReplyTo( $from, $name );
		}
		$phpmailer->setFrom( $s['email'], '' !== $s['name'] ? $s['name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), false );
		$phpmailer->Sender = $s['email'];
	}

	/** WordPress's own made-up wordpress@domain sender is not worth replying to. */
	private static function is_default_from( string $from ): bool {
		return 0 === strpos( strtolower( $from ), 'wordpress@' );
	}

	/* ---------------------------------------------------------------- */

	public static function menu(): void {
		add_submenu_page( Optima_Hub_Admin::parent(), __( 'Email', 'optima-build' ), __( 'Email', 'optima-build' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::PAGE ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_HUB_URL . 'assets/optima-admin.css' );
		}
	}

	public static function nav_hint( string $hint, string $slug ): string {
		if ( self::PAGE === $slug ) {
			return self::ready() ? __( 'On', 'optima-build' ) : __( 'Not set up', 'optima-build' );
		}
		return $hint;
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/** What the Email card on Build says when nothing is set up. */
	public static function not_set_up_text(): string {
		if ( class_exists( 'Optima_Hub_Secret' ) && 'unreadable' === Optima_Hub_Secret::mail_state() ) {
			return __( 'The saved token can\'t be read: the host changed the site\'s security keys. Paste the token again, then Send test.', 'optima-build' );
		}
		return __( 'Not set up: form emails go out from the host and often land in junk. Connect Cloudflare email so they come from your domain.', 'optima-build' );
	}

	/** One sentence naming the route: "Sending through Cloudflare as forms@example.com." */
	public static function summary(): string {
		if ( ! self::ready() ) {
			return self::not_set_up_text();
		}
		/* translators: 1: Cloudflare, Google or the mail server, 2: email address */
		return sprintf( __( 'Sending through %1$s as %2$s.', 'optima-build' ), self::route_name(), self::settings()['email'] );
	}

	/** One line for Site health. */
	public static function check(): array {
		$s = self::settings();
		if ( ! self::ready() ) {
			return array(
				'label'     => __( 'Site email', 'optima-build' ),
				'value'     => __( 'Host mail', 'optima-build' ),
				'state'     => 'warn',
				'note'      => self::not_set_up_text(),
				'link'      => self::url(),
				'link_text' => __( 'Set up Email', 'optima-build' ),
			);
		}
		$last = $s['last'];
		$bad  = isset( $last['ok'] ) && ! $last['ok'];
		if ( $bad ) {
			$note = __( 'The last test did not go out: ', 'optima-build' ) . self::explain( (string) ( $last['error'] ?? '' ), $s['service'] );
		} elseif ( empty( $last ) ) {
			$note = self::summary() . ' ' . __( 'Send a test to confirm it arrives.', 'optima-build' );
		} else {
			$note = self::summary();
		}
		return array(
			'label'     => __( 'Site email', 'optima-build' ),
			/* translators: %s: Cloudflare, Google or the mail server */
			'value'     => $bad ? __( 'Last test failed', 'optima-build' ) : sprintf( __( 'Through %s', 'optima-build' ), self::route_name() ),
			'state'     => $bad ? 'bad' : ( empty( $last ) ? 'warn' : 'good' ),
			'note'      => $note,
			'link'      => self::url(),
			'link_text' => __( 'Email settings', 'optima-build' ),
		);
	}

	/* ---------------------------------------------------------------- */

	public static function save(): void {
		check_admin_referer( self::SAVE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only site admins can change the site email.', 'optima-build' ), 403 );
		}
		$s       = self::settings();
		$email   = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );
		$pass    = preg_replace( '/\s+/', '', wp_unslash( (string) ( $_POST['password'] ?? '' ) ) );
		$service = sanitize_key( wp_unslash( (string) ( $_POST['service'] ?? $s['service'] ) ) );
		$clear   = ! empty( $_POST['clear'] );

		if ( $clear ) {
			delete_option( self::OPTION );
			self::notice( __( 'Email turned off. The site sends through the host again.', 'optima-build' ), 'success' );
			self::back();
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			self::notice( __( 'That email address does not look right.', 'optima-build' ), 'error' );
			self::back();
		}
		$changed = ! defined( 'OPTIMA_MAIL_SERVICE' ) && isset( self::SERVICES[ $service ] ) && $service !== $s['service'];
		if ( $changed ) {
			$s = self::with_service( $s, $service );
		} elseif ( 'other' === $s['service'] ) {
			// The server fields belong to the route they were typed for.
			$s['host'] = strtolower( preg_replace( '/[^a-zA-Z0-9.-]/', '', wp_unslash( (string) ( $_POST['host'] ?? '' ) ) ) );
			$port      = (int) ( $_POST['port'] ?? 0 );
			$s['port'] = in_array( $port, array( 465, 587, 2525 ), true ) ? $port : 0;
			$s['user'] = sanitize_text_field( wp_unslash( (string) ( $_POST['user'] ?? '' ) ) );
		}
		$s['email'] = $email;
		$s['name']  = sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) );
		$accent      = sanitize_hex_color( wp_unslash( (string) ( $_POST['accent'] ?? '' ) ) );
		// Only keep a color someone changed; the detected one stays automatic.
		$s['accent'] = ( $accent && strtolower( $accent ) !== strtolower( self::detected_accent() ) ) ? $accent : '';
		if ( '' !== $pass ) {
			$s['password'] = self::encrypt( $pass );
			$s['last']     = array();
		}
		update_option( self::OPTION, $s, false );

		if ( self::ready() ) {
			$msg = __( 'Saved. Send a test to make sure it arrives.', 'optima-build' );
		} elseif ( 'cloudflare' === $s['service'] ) {
			$msg = __( 'Saved. Add a send-from address on your domain and the Cloudflare API token to switch it on.', 'optima-build' );
		} elseif ( 'google' === $s['service'] ) {
			$msg = __( 'Saved. Add the Google address and its app password to switch it on.', 'optima-build' );
		} else {
			$msg = __( 'Saved. Add the mail server, user name, password and send-from address to switch it on.', 'optima-build' );
		}
		if ( $changed ) {
			$msg .= ' ' . __( 'The route changed, so the saved token or password was cleared.', 'optima-build' );
		}
		if ( 'google' !== $s['service'] && '' !== $email && ! self::from_on_site_domain( $email ) ) {
			/* translators: %s: the site's domain */
			$msg .= ' ' . sprintf( __( 'Check the send-from address: it is not on %s, so the mail service may refuse it unless that domain is added there too.', 'optima-build' ), self::site_domain() );
		}
		self::notice( $msg, 'success' );
		self::back();
	}

	public static function test(): void {
		check_admin_referer( self::TEST );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only site admins can send a test.', 'optima-build' ), 403 );
		}
		$to = sanitize_email( wp_unslash( (string) ( $_POST['to'] ?? '' ) ) );
		if ( ! is_email( $to ) ) {
			self::notice( __( 'Enter the address the test should go to.', 'optima-build' ), 'error' );
			self::back();
		}
		add_action(
			'wp_mail_failed',
			static function ( $error ) {
				self::$error = is_wp_error( $error ) ? $error->get_error_message() : '';
			}
		);
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$html = static fn() => 'text/html';
		add_filter( 'wp_mail_content_type', $html );
		/* translators: %s: site name */
		$ok = wp_mail( $to, sprintf( __( 'Your website can send email: %s', 'optima-build' ), $site ), self::test_body( $site ) );
		remove_filter( 'wp_mail_content_type', $html );

		$s         = self::settings();
		$s['last'] = array( 'ok' => (bool) $ok, 'to' => $to, 'at' => time(), 'error' => $ok ? '' : self::$error );
		update_option( self::OPTION, $s, false );

		if ( $ok ) {
			/* translators: %s: email address */
			self::notice( sprintf( __( 'Sent to %s. Check that inbox (and junk, the first time).', 'optima-build' ), $to ), 'success' );
		} else {
			/* translators: %s: Cloudflare, Google or the mail server */
			self::notice( sprintf( __( '%s did not accept it: ', 'optima-build' ), self::route_name() ) . self::explain( self::$error, self::service() ), 'error' );
		}
		self::back();
	}

	/**
	 * The test email: a small card in the site's own colors, with its icon,
	 * that says plainly what just worked. Inline styles only, so it renders
	 * the same in Gmail, Apple Mail and Outlook.
	 */
	private static function test_body( string $site ): string {
		$s    = self::settings();
		$url  = home_url( '/' );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		/* translators: 1: site address, 2: sending address, 3: Cloudflare, Google or the mail server */
		$text = sprintf( __( 'This came from %1$s through %3$s, sent as %2$s. Form messages, booking notes and password resets will arrive the same way, in the inbox instead of junk.', 'optima-build' ), $host, $s['email'], self::route_name() );
		return self::card(
			$site,
			__( 'Your website can send email.', 'optima-build' ),
			'<p style="margin:0;text-align:center">' . esc_html( $text ) . '</p>',
			array( array( __( 'Visit the site', 'optima-build' ), $url ) ),
			Optima_Hub_Admin::branded() ? __( 'Sent from the Optima Email screen. Built and looked after by Optima Digital.', 'optima-build' ) : __( 'Sent from the Email screen.', 'optima-build' )
		);
	}

	/**
	 * One email card in the site's own color with its icon: the test email and
	 * every form message use it. Inline styles only, so it renders the same in
	 * Gmail, Apple Mail and Outlook.
	 *
	 * @param string $eyebrow Small line above the title (the site name).
	 * @param string $title   The headline.
	 * @param string $body    Body HTML, already escaped by the caller.
	 * @param array  $buttons Each [ label, url ]; the first is filled, the rest outlined.
	 * @param string $footer  Plain text for the footer line.
	 */
	private static function card( string $eyebrow, string $title, string $body, array $buttons, string $footer ): string {
		$accent = self::accent();
		$ink    = self::ink( $accent );
		$icon   = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 160 ) : '';
		ob_start();
		?>
<!doctype html><html><body style="margin:0;padding:0;background:#F6F8FB"><!-- optima-card -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F8FB;padding:32px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:18px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#141A24">
<tr><td style="height:8px;background:<?php echo esc_attr( $accent ); ?>"></td></tr>
<tr><td style="padding:32px 32px 8px;text-align:center">
<?php if ( $icon ) : ?><img src="<?php echo esc_url( $icon ); ?>" width="72" height="72" alt="" style="display:block;margin:0 auto 18px;border-radius:50%"><?php endif; ?>
<p style="margin:0 0 6px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#9AA6B8"><?php echo esc_html( $eyebrow ); ?></p>
<h1 style="margin:0;font-size:24px;line-height:1.25;font-weight:600;letter-spacing:-.02em"><?php echo esc_html( $title ); ?></h1>
</td></tr>
<tr><td style="padding:16px 32px 8px;font-size:16px;line-height:1.6;color:#3A3A3C"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?></td></tr>
<?php if ( $buttons ) : ?>
<tr><td style="padding:20px 32px 32px;text-align:center">
<?php
foreach ( array_values( $buttons ) as $i => $b ) {
	$style = 0 === $i
		? 'background:' . $accent . ';color:' . $ink . ';border:2px solid ' . $accent
		: 'background:#FFFFFF;color:#141A24;border:2px solid ' . $accent;
	echo '<a href="' . esc_url( (string) $b[1], array( 'http', 'https', 'tel', 'sms', 'mailto' ) ) . '" style="display:inline-block;margin:4px;' . esc_attr( $style ) . ';text-decoration:none;font-weight:600;font-size:15px;padding:10px 22px;border-radius:980px">' . esc_html( (string) $b[0] ) . '</a>';
}
?>
</td></tr>
<?php endif; ?>
<tr><td style="padding:16px 32px;border-top:1px solid #EEF2F7;font-size:12px;color:#9AA6B8;text-align:center"><?php echo esc_html( $footer ); ?></td></tr>
</table></td></tr></table></body></html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Text color for a button filled with $hex: white when it reads at 4.5:1
	 * (WCAG AA), else near-black. Carolina blue and other light colors get dark text.
	 */
	private static function ink( string $hex ): string {
		$lum = 0.0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $w ) {
			$c    = hexdec( substr( ltrim( $hex, '#' ), $i * 2, 2 ) ) / 255;
			$lum += $w * ( $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4 );
		}
		return 1.05 / ( $lum + 0.05 ) >= 4.5 ? '#FFFFFF' : '#141A24';
	}

	/**
	 * Gravity Forms messages arrive in the same card as the test email, in the
	 * site's color with its icon, with Call and Text buttons for the first phone
	 * number in the message and Email back for the first email address (business
	 * notifications only, never a confirmation sent to the customer). Only HTML
	 * notifications, only while Optima Email is set up, and never twice. Off
	 * switch: optima_mail_brand_forms.
	 *
	 * @param array  $email        to, subject, message, headers, attachments, abort_email.
	 * @param string $format       html or text.
	 * @param array  $notification The notification settings.
	 * @param array  $entry        The entry.
	 */
	public static function brand_form( $email, $format = 'html', $notification = array(), $entry = array() ) {
		if ( ! is_array( $email ) || 'html' !== $format || ! self::ready() || ! apply_filters( 'optima_mail_brand_forms', true, $notification, $entry ) ) {
			return $email;
		}
		$message = (string) ( $email['message'] ?? '' );
		if ( '' === trim( $message ) || false !== strpos( $message, '<!-- optima-card -->' ) ) {
			return $email;
		}
		// Gravity Forms may already wrap the message in <html><body>: keep only what is inside.
		if ( preg_match( '#<body[^>]*>(.*)</body>#is', $message, $inner ) ) {
			$message = $inner[1];
		}
		$message = preg_replace( '#</?(html|head|body)[^>]*>|<head>.*?</head>#is', '', $message );
		// Call, Text and Email back are for the business. A message going to the
		// person who filled in the form (a confirmation) gets no contact buttons.
		$to_customer = 'field' === ( $notification['toType'] ?? '' );
		$buttons     = array();
		if ( ! $to_customer && ( preg_match( '#href=["\']tel:([+0-9().\s-]{7,})["\']#i', $message, $m ) || preg_match( '#(\(?\d{3}\)?[\s.-]?\d{3}[\s.-]\d{4})#', wp_strip_all_tags( $message ), $m ) ) ) {
			$phone  = trim( $m[1] );
			$digits = preg_replace( '/[^0-9+]/', '', $m[1] );
			if ( strlen( ltrim( $digits, '+' ) ) >= 10 ) {
				$buttons[] = array( __( 'Call', 'optima-build' ), 'tel:' . $digits );
				$buttons[] = array( __( 'Text', 'optima-build' ), 'sms:' . $digits );
			}
		}
		// The visitor's address: a mailto link, else the first plain address in
		// the text that is not the site's own sending address.
		$reply = '';
		if ( preg_match( '#href=["\']mailto:([^"\'?]+)#i', $message, $m ) ) {
			$reply = rawurldecode( $m[1] );
		} elseif ( preg_match_all( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', wp_strip_all_tags( str_replace( '<', ' <', $message ) ), $all ) ) {
			$own = array_map( 'strtolower', array( self::settings()['email'], (string) get_option( 'admin_email' ) ) );
			foreach ( $all[0] as $addr ) {
				if ( ! in_array( strtolower( $addr ), $own, true ) ) {
					$reply = $addr;
					break;
				}
			}
		}
		if ( ! $to_customer && is_email( $reply ) ) {
			$buttons[] = array( __( 'Email back', 'optima-build' ), 'mailto:' . $reply );
		}
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$footer = Optima_Hub_Admin::branded()
			/* translators: %s: site address */
			? sprintf( __( 'Sent from a form on %s. Website by Optima Digital.', 'optima-build' ), (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) )
			/* translators: %s: site address */
			: sprintf( __( 'Sent from a form on %s.', 'optima-build' ), (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$title = wp_specialchars_decode( (string) ( $email['subject'] ?? '' ), ENT_QUOTES );
		// The number already has its Call button, and mail apps turn a number in
		// the headline into a big link, so the headline drops it.
		if ( isset( $phone ) && '' !== $phone ) {
			$title = trim( preg_replace( '/\s*[(\[]?\s*' . preg_quote( $phone, '/' ) . '\s*[)\]]?/', '', $title ), " \t-:|," ) ?: $title;
		}
		$email['message'] = self::card( $site, $title, wp_kses_post( $message ), $buttons, $footer );
		return $email;
	}

	/**
	 * Colors to click on the Email screen: the block theme's own palette
	 * (grays, near-white and near-black left out), the Cards and Reviews
	 * colors, and Optima's azure. Hex code => name.
	 *
	 * @return array<string,string>
	 */
	private static function swatches(): array {
		$out = array();
		$add = static function ( $hex, $name ) use ( &$out ) {
			$hex = strtoupper( (string) $hex );
			if ( ! preg_match( '/^#[0-9A-F]{6}$/', $hex ) || isset( $out[ $hex ] ) ) {
				return;
			}
			$rgb = array_map( 'hexdec', str_split( substr( $hex, 1 ), 2 ) );
			$max = max( $rgb );
			$min = min( $rgb );
			if ( $max - $min < 24 || $max < 40 || $min > 235 ) {
				return; // Grays, near-black and near-white make poor buttons.
			}
			if ( '#FFFFFF' !== self::ink( $hex ) ) {
				return; // Only colors white button text reads on at 4.5:1 (WCAG AA).
			}
			$out[ $hex ] = (string) $name;
		};
		$add( self::detected_accent(), __( 'Detected', 'optima-build' ) );
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palettes = (array) wp_get_global_settings( array( 'color', 'palette' ) );
			foreach ( array( 'custom', 'theme' ) as $origin ) {
				foreach ( (array) ( $palettes[ $origin ] ?? array() ) as $c ) {
					$add( $c['color'] ?? '', $c['name'] ?? ( $c['slug'] ?? '' ) );
				}
			}
		}
		foreach ( array( 'optima_cards_settings', 'optima_reviews_brand', 'optima_reviews_settings' ) as $opt ) {
			$o = get_option( $opt );
			if ( is_array( $o ) ) {
				$add( $o['accent'] ?? '', __( 'Brand', 'optima-build' ) );
			}
		}
		$add( '#0F63AB', 'Optima' );
		return array_slice( $out, 0, 16, true );
	}

	/**
	 * The site's brand color for other Optima plugins (the cookie notice uses
	 * it): the Email screen's pick, else the detected brand color.
	 */
	public static function brand_color(): string {
		return self::accent();
	}

	/** The email color: the one picked on this screen, else the detected brand color. */
	private static function accent(): string {
		$picked = self::settings()['accent'];
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $picked ) ? $picked : self::detected_accent();
	}

	/**
	 * The site's brand color: the optima_brand_accent filter, Optima Cards, Optima
	 * Reviews, a block theme's accent/primary/brand color, then Optima's own
	 * darker azure.
	 */
	private static function detected_accent(): string {
		$hex      = '/^#[0-9a-fA-F]{6}$/';
		$filtered = (string) apply_filters( 'optima_brand_accent', '' );
		if ( preg_match( $hex, $filtered ) ) {
			return $filtered;
		}
		foreach ( array( 'optima_cards_settings', 'optima_reviews_brand', 'optima_reviews_settings' ) as $opt ) {
			$o = get_option( $opt );
			if ( is_array( $o ) && ! empty( $o['accent'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $o['accent'] ) ) {
				return (string) $o['accent'];
			}
		}
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palette = (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
			foreach ( array( 'accent', 'primary', 'brand' ) as $want ) {
				foreach ( $palette as $c ) {
					if ( ( $c['slug'] ?? '' ) === $want && preg_match( $hex, (string) ( $c['color'] ?? '' ) ) ) {
						return (string) $c['color'];
					}
				}
			}
		}
		return '#0F63AB';
	}

	/**
	 * Turn the mail server's error text into the fix.
	 *
	 * @param string $error   What PHPMailer reported.
	 * @param string $service The route: cloudflare, google or other.
	 */
	private static function explain( string $error, string $service = '' ): string {
		$service = '' !== $service ? $service : self::service();
		if ( 'cloudflare' === $service ) {
			if ( preg_match( '/535|authenticat|password|credentials/i', $error ) ) {
				return __( 'Cloudflare turned down the API token (535, sign-in failed). The token is wrong, or it is missing the Email Sending: Edit permission. Make a new one with that permission and paste it in.', 'optima-build' );
			}
			if ( preg_match( '/5\.7\.1|sender denied/i', $error ) ) {
				return __( 'Cloudflare would not send as this address (550 5.7.1, sender denied). Its domain is not added in Cloudflare, Email, Email Sending. Add the domain there, or use an address on a domain that is.', 'optima-build' );
			}
			if ( preg_match( '/connect|timed out|could not/i', $error ) ) {
				return __( 'the site could not reach smtp.mx.cloudflare.net on port 465. Ask the host whether outbound port 465 is open.', 'optima-build' );
			}
		} elseif ( 'google' === $service ) {
			if ( preg_match( '/authenticat|password|535|534|Username and Password/i', $error ) ) {
				return __( 'the address or app password is wrong. Make a new app password for this address and paste it in.', 'optima-build' );
			}
			if ( preg_match( '/connect|timed out|could not/i', $error ) ) {
				return __( 'the site could not reach Google\'s mail server. Ask the host whether port 587 is open.', 'optima-build' );
			}
		} else {
			if ( preg_match( '/authenticat|password|535|534/i', $error ) ) {
				return __( 'the user name or password is wrong. Make a new sending key in the service and paste it in.', 'optima-build' );
			}
			if ( preg_match( '/not verified|sender|550|553|554/i', $error ) ) {
				return __( 'the service would not send as this address. Use an address on a domain the service has verified.', 'optima-build' );
			}
			if ( preg_match( '/connect|timed out|could not/i', $error ) ) {
				return __( 'the site could not reach the mail server. Check the server name and port, and ask the host whether that port is open.', 'optima-build' );
			}
		}
		return '' !== $error ? $error : __( 'no reason given.', 'optima-build' );
	}

	private static function notice( string $message, string $type ): void {
		set_transient( 'optima_mail_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
	}

	private static function back(): void {
		// A test sent from Build, Updates goes back there, with the result as its notice.
		if ( 'updates' === sanitize_key( wp_unslash( (string) ( $_POST['back'] ?? '' ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the caller.
			$n = get_transient( 'optima_mail_notice_' . get_current_user_id() );
			if ( is_array( $n ) ) {
				delete_transient( 'optima_mail_notice_' . get_current_user_id() );
				set_transient( 'optima_hub_notice_' . get_current_user_id(), $n, MINUTE_IN_SECONDS );
			}
			wp_safe_redirect( Optima_Hub_Admin::url( 'updates' ) . '#sh-up-mail' );
			exit;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/* ---------------------------------------------------------------- */

	/**
	 * What the domain's DNS says today, for the Cloudflare checklist: the
	 * DMARC record (Cloudflare adds a p=reject one only when there is none) and
	 * whether Cloudflare's cf-bounce records are in. Cached ten minutes.
	 *
	 * @return array{dmarc:?string, cf:?bool} null means the lookup could not run.
	 */
	private static function dns_status( string $domain ): array {
		$out = array( 'dmarc' => null, 'cf' => null );
		if ( '' === $domain || ! function_exists( 'dns_get_record' ) ) {
			return $out;
		}
		$key    = 'optima_mail_dns_' . md5( $domain );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$txt = @dns_get_record( '_dmarc.' . $domain, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failed lookup is reported as unknown.
		if ( is_array( $txt ) ) {
			$out['dmarc'] = '';
			foreach ( $txt as $r ) {
				$v = (string) ( $r['txt'] ?? '' );
				if ( 0 === stripos( $v, 'v=DMARC1' ) ) {
					$out['dmarc'] = $v;
					break;
				}
			}
		}
		$mx = @dns_get_record( 'cf-bounce.' . $domain, DNS_MX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failed lookup is reported as unknown.
		if ( is_array( $mx ) ) {
			$out['cf'] = false;
			foreach ( $mx as $r ) {
				if ( false !== stripos( (string) ( $r['target'] ?? '' ), 'mx.cloudflare.net' ) ) {
					$out['cf'] = true;
					break;
				}
			}
		}
		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/** The Cloudflare setup steps, with what the domain's DNS shows today. */
	private static function cloudflare_steps( string $domain ): void {
		$dns = self::dns_status( $domain );
		?>
		<ol class="sm-steps">
			<li>
				<?php /* translators: %s: the site's domain */ echo esc_html( sprintf( __( 'Make sure %s uses Cloudflare for its DNS, in the Cloudflare account that will send the email.', 'optima-build' ), $domain ) ); ?>
			</li>
			<li>
				<?php esc_html_e( 'Check DMARC first. In Cloudflare, open Email, then Email Sending, and add the domain. Cloudflare adds its own records on cf-bounce (an MX, an SPF record with include:_spf.mx.cloudflare.net, and DKIM at cf-bounce._domainkey). It adds a DMARC record only when the domain has none, and that one is p=reject.', 'optima-build' ); ?>
				<?php if ( null !== $dns['dmarc'] ) : ?>
					<span class="sh-note" style="display:block">
						<?php
						echo esc_html(
							'' !== $dns['dmarc']
								/* translators: %s: the DMARC record */
								? sprintf( __( 'DMARC found: %s. Cloudflare leaves it alone.', 'optima-build' ), $dns['dmarc'] )
								: __( 'No DMARC record found, so Cloudflare would add p=reject. If the domain\'s staff mail is not DKIM-signed yet, add v=DMARC1; p=none first.', 'optima-build' )
						);
						?>
					</span>
				<?php endif; ?>
				<?php if ( null !== $dns['cf'] ) : ?>
					<span class="sh-note" style="display:block"><?php echo esc_html( $dns['cf'] ? __( 'Cloudflare\'s cf-bounce records are in.', 'optima-build' ) : __( 'Cloudflare\'s cf-bounce records are not in yet.', 'optima-build' ) ); ?></span>
				<?php endif; ?>
			</li>
			<li><?php esc_html_e( 'In Cloudflare, create an API token with the Email Sending: Edit permission. It is shown once: keep it in the password manager.', 'optima-build' ); ?></li>
			<li><?php /* translators: %s: example address */ echo esc_html( sprintf( __( 'Paste the token below with a send-from address on your domain, such as %s. Save, then Send test.', 'optima-build' ), 'forms@' . $domain ) ); ?></li>
			<li><?php esc_html_e( 'Open the test in the inbox, show the original message, and check it says spf=pass, dkim=pass and dmarc=pass.', 'optima-build' ); ?></li>
		</ol>
		<p class="sh-note" style="margin-top:8px"><?php esc_html_e( 'Cloudflare email sending needs the Workers Paid plan and is still in beta.', 'optima-build' ); ?></p>
		<?php
	}

	/** The Send test form, and what the last failure means. */
	private static function test_section( array $last, string $service ): void {
		?>
		<section class="sh-box" style="margin-top:14px">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-actions" style="margin-top:0">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST ); ?>">
				<?php wp_nonce_field( self::TEST ); ?>
				<label><?php esc_html_e( 'Send a test to', 'optima-build' ); ?>
					<input type="email" name="to" value="<?php echo esc_attr( (string) ( $last['to'] ?? wp_get_current_user()->user_email ) ); ?>">
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Send test', 'optima-build' ); ?></button>
			</form>
			<?php if ( ! empty( $last['error'] ) ) : ?>
				<p class="sh-note" style="margin-top:10px"><?php /* translators: %s: Cloudflare, Google or the mail server */ echo esc_html( sprintf( __( '%s said: ', 'optima-build' ), self::route_name() ) . $last['error'] ); ?></p>
				<p class="sh-note"><?php echo esc_html( __( 'What to do: ', 'optima-build' ) . self::explain( (string) $last['error'], $service ) ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = self::settings();
		$ready     = self::ready();
		$service   = $s['service'];
		$domain    = self::site_domain();
		$server    = self::server();
		$in_config = defined( 'OPTIMA_MAIL_PASSWORD' ) && '' !== (string) OPTIMA_MAIL_PASSWORD;
		Optima_Admin_UI::open( __( 'Email', 'optima-build' ), __( 'Send the site\'s email (form notices, password resets) through Cloudflare from your own domain, so it reaches the inbox instead of junk.', 'optima-build' ) );

		$key    = 'optima_mail_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), esc_html( $notice['message'] ) );
		}
		if ( function_exists( 'wp_mail_smtp' ) || defined( 'WPMS_PLUGIN_VER' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP Mail SMTP is also installed. Optima Email takes over once it is set up here, so you can delete WP Mail SMTP.', 'optima-build' ) . '</p></div>';
		}
		$last = $s['last'];
		// Rule 52: open on what needs doing. Set it up, then test it, then nothing.
		$todo = ! $ready ? 'setup' : ( empty( $last ) || empty( $last['ok'] ) ? 'test' : 'done' );
		?>
		<style>
			.sm-steps{margin:0; padding-left:20px; display:grid; gap:6px}
			.sm-row{display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px}
			.sm-row label{display:grid; gap:6px; font-weight:600}
			.sm-row input, .sm-row select{width:100%}
			.sm-color{display:grid; gap:6px; align-content:start}
			.sm-color > label{font-weight:600}
			.sm-color-row{display:flex; gap:8px; align-items:center}
			.sm-chip{flex:0 0 36px; height:36px; border-radius:8px; border:1px solid #DDE4EE}
			.sm-swatches{display:flex; flex-wrap:wrap; gap:6px}
			.sm-swatch{width:28px; height:28px; padding:0; border-radius:50%; border:2px solid #fff; box-shadow:0 0 0 1px #c3c4c7; cursor:pointer}
			.sm-swatch:hover,.sm-swatch:focus{box-shadow:0 0 0 2px #0F63AB; outline:none}
			.sm-actions{display:flex; flex-wrap:wrap; gap:10px; align-items:end; margin-top:14px}
			.sm-actions label{display:grid; gap:6px; font-weight:600; flex:1 1 240px}
		</style>
		<div class="sh-grid sh-grid-2">
			<section class="sh-box sh-check is-<?php echo esc_attr( $ready ? ( isset( $last['ok'] ) && ! $last['ok'] ? 'bad' : 'good' ) : 'warn' ); ?>">
				<p class="sh-label"><?php esc_html_e( 'Status', 'optima-build' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( $ready ? self::summary() : __( 'Not set up: the host sends', 'optima-build' ) ); ?></p>
				<p class="sh-note">
					<?php
					if ( ! empty( $last['at'] ) ) {
						/* translators: 1: result, 2: address, 3: date */
						echo esc_html( sprintf( __( 'Last test %1$s to %2$s, %3$s.', 'optima-build' ), $last['ok'] ? __( 'sent', 'optima-build' ) : __( 'failed', 'optima-build' ), $last['to'], wp_date( 'M j, g:i a', (int) $last['at'] ) ) );
					} else {
						esc_html_e( 'No test sent yet.', 'optima-build' );
					}
					?>
				</p>
			</section>
			<section class="sh-box">
				<?php if ( 'done' === $todo ) : ?>
					<p class="sh-label"><?php esc_html_e( 'Next step', 'optima-build' ); ?></p>
					<p><?php esc_html_e( 'Nothing to do. The last test arrived.', 'optima-build' ); ?></p>
				<?php elseif ( 'test' === $todo ) : ?>
					<p class="sh-label"><?php esc_html_e( 'Next step', 'optima-build' ); ?></p>
					<p><?php echo esc_html( empty( $last ) ? __( 'Send a test below, then check it arrives with spf=pass, dkim=pass and dmarc=pass.', 'optima-build' ) : __( 'The last test did not go out. Fix what it says below, then send another.', 'optima-build' ) ); ?></p>
				<?php elseif ( 'cloudflare' === $service ) : ?>
					<p class="sh-label"><?php esc_html_e( 'Set up Cloudflare email', 'optima-build' ); ?></p>
					<?php self::cloudflare_steps( $domain ); ?>
				<?php elseif ( 'google' === $service ) : ?>
					<p class="sh-label"><?php esc_html_e( 'Set it up once', 'optima-build' ); ?></p>
					<ol class="sm-steps">
						<li><?php esc_html_e( 'Sign in to Google as the address the site should send from.', 'optima-build' ); ?></li>
						<li><?php echo wp_kses( __( 'Open <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a>, name it after this site, and copy the 16 letters.', 'optima-build' ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ); ?></li>
						<li><?php esc_html_e( 'Paste them below, Save, then Send test.', 'optima-build' ); ?></li>
					</ol>
				<?php else : ?>
					<p class="sh-label"><?php esc_html_e( 'Set it up once', 'optima-build' ); ?></p>
					<ol class="sm-steps">
						<li><?php esc_html_e( 'In the mail service, add the site\'s domain and add the DNS records it lists. Wait until it shows as verified.', 'optima-build' ); ?></li>
						<li><?php esc_html_e( 'Make a key with sending access only. It is shown once.', 'optima-build' ); ?></li>
						<li><?php esc_html_e( 'Fill in the server, user name, key and a send-from address on that domain below, Save, then Send test.', 'optima-build' ); ?></li>
					</ol>
				<?php endif; ?>
			</section>
		</div>

		<?php
		if ( 'test' === $todo ) {
			self::test_section( $last, $service );
		}
		?>

		<section class="sh-box" style="margin-top:14px">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>">
				<?php wp_nonce_field( self::SAVE ); ?>
				<div class="sm-row">
					<label><?php esc_html_e( 'Send through', 'optima-build' ); ?>
						<select name="service" <?php disabled( defined( 'OPTIMA_MAIL_SERVICE' ) ); ?>>
							<?php foreach ( self::SERVICES as $id => $svc ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $service, $id ); ?>><?php echo esc_html( 'cloudflare' === $id ? $svc['label'] . ' ' . __( '(recommended)', 'optima-build' ) : $svc['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="sh-note" style="font-weight:400"><?php echo esc_html( defined( 'OPTIMA_MAIL_SERVICE' ) ? __( 'Set in wp-config.php (OPTIMA_MAIL_SERVICE).', 'optima-build' ) : __( 'Changed it? Save once to see its fields. A new route clears the saved token or password.', 'optima-build' ) ); ?></span>
					</label>
					<?php if ( 'other' === $service ) : ?>
						<label><?php esc_html_e( 'Mail server', 'optima-build' ); ?>
							<input type="text" name="host" value="<?php echo esc_attr( $s['host'] ); ?>" placeholder="smtp.example.com" autocomplete="off" spellcheck="false">
						</label>
						<label><?php esc_html_e( 'Port', 'optima-build' ); ?>
							<select name="port">
								<?php foreach ( array( 587, 465, 2525 ) as $pt ) : ?>
									<option value="<?php echo esc_attr( (string) $pt ); ?>" <?php selected( (int) $server['port'], $pt ); ?>><?php echo esc_html( (string) $pt ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label><?php esc_html_e( 'User name', 'optima-build' ); ?>
							<input type="text" name="user" value="<?php echo esc_attr( $s['user'] ); ?>" autocomplete="off" spellcheck="false">
						</label>
					<?php endif; ?>
					<label>
						<?php
						if ( 'cloudflare' === $service ) {
							esc_html_e( 'Send from (an address on your domain)', 'optima-build' );
						} elseif ( 'google' === $service ) {
							esc_html_e( 'Send from (Gmail or Google Workspace)', 'optima-build' );
						} else {
							esc_html_e( 'Send from (an address on a domain the service verified)', 'optima-build' );
						}
						?>
						<input type="email" name="email" value="<?php echo esc_attr( $s['email'] ); ?>" placeholder="<?php echo esc_attr( 'google' === $service ? 'name@gmail.com' : 'forms@' . $domain ); ?>" autocomplete="off">
						<?php if ( 'google' !== $service && '' !== $s['email'] && ! self::from_on_site_domain( $s['email'] ) ) : ?>
							<span class="sh-note" style="font-weight:400"><?php /* translators: %s: the site's domain */ echo esc_html( sprintf( __( 'This address is not on %s. The mail service only sends from a domain added there, so use an address on your own domain.', 'optima-build' ), $domain ) ); ?></span>
						<?php endif; ?>
					</label>
					<label>
						<?php
						if ( 'cloudflare' === $service ) {
							esc_html_e( 'Cloudflare API token', 'optima-build' );
						} elseif ( 'google' === $service ) {
							esc_html_e( 'App password', 'optima-build' );
						} else {
							esc_html_e( 'Password or key', 'optima-build' );
						}
						?>
						<input type="password" name="password" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $in_config ? __( 'Set in wp-config.php', 'optima-build' ) : ( '' !== $s['password'] ? __( 'Saved. Paste a new one to replace it.', 'optima-build' ) : ( 'google' === $service ? 'abcd efgh ijkl mnop' : '' ) ) ); ?>" <?php disabled( $in_config ); ?>>
						<?php if ( 'cloudflare' === $service ) : ?>
							<span class="sh-note" style="font-weight:400"><?php /* translators: 1: server, 2: port */ echo esc_html( sprintf( __( 'Needs the Email Sending: Edit permission. The site signs in to %1$s on port %2$d as api_token.', 'optima-build' ), $server['host'], $server['port'] ) ); ?></span>
						<?php endif; ?>
					</label>
					<label><?php esc_html_e( 'Sender name', 'optima-build' ); ?>
						<input type="text" name="name" value="<?php echo esc_attr( $s['name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>">
					</label>
					<div class="sm-color">
						<label for="sm-accent"><?php esc_html_e( 'Email color', 'optima-build' ); ?></label>
						<span class="sm-color-row">
							<span class="sm-chip" id="sm-chip" style="background:<?php echo esc_attr( self::accent() ); ?>" aria-hidden="true"></span>
							<input type="text" id="sm-accent" name="accent" value="<?php echo esc_attr( self::accent() ); ?>" maxlength="7" pattern="#[0-9a-fA-F]{6}" placeholder="#A04E10" spellcheck="false">
						</span>
						<span class="sm-swatches">
							<?php foreach ( self::swatches() as $hex => $name ) : ?>
								<button type="button" class="sm-swatch" data-c="<?php echo esc_attr( $hex ); ?>" style="background:<?php echo esc_attr( $hex ); ?>" title="<?php echo esc_attr( $name . ' ' . $hex ); ?>" aria-label="<?php echo esc_attr( $name . ' ' . $hex ); ?>"></button>
							<?php endforeach; ?>
						</span>
						<span class="sh-note"><?php esc_html_e( 'Click one of the site\'s colors, or type a hex code.', 'optima-build' ); ?></span>
					</div>
				</div>
				<script>
				( function () {
					var input = document.getElementById( 'sm-accent' ), chip = document.getElementById( 'sm-chip' );
					function show() { if ( /^#[0-9a-fA-F]{6}$/.test( input.value ) ) { chip.style.background = input.value; } }
					input.addEventListener( 'input', show );
					document.querySelectorAll( '.sm-swatch' ).forEach( function ( b ) {
						b.addEventListener( 'click', function () { input.value = b.getAttribute( 'data-c' ); show(); } );
					} );
				} )();
				</script>
				<p class="sh-note" style="margin-top:10px"><?php esc_html_e( 'Every email the site sends goes out as this address. When a form asks to send as someone else, that address becomes the reply-to, so replies still reach them.', 'optima-build' ); ?></p>
				<div class="sm-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'optima-build' ); ?></button>
					<?php if ( '' !== $s['email'] || '' !== $s['password'] ) : ?>
						<button type="submit" name="clear" value="1" class="button" onclick="return confirm('<?php echo esc_js( __( 'Turn off Email and go back to the host\'s mail?', 'optima-build' ) ); ?>');"><?php esc_html_e( 'Turn off', 'optima-build' ); ?></button>
					<?php endif; ?>
				</div>
			</form>
		</section>

		<?php
		if ( 'test' !== $todo ) {
			self::test_section( $last, $service );
		}
		Optima_Admin_UI::close();
	}

	/* ---------------------------------------------------------------- */

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'optima-mail', true );
	}

	private static function encrypt( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'p:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv = random_bytes( 16 );
		return 'e:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decrypt( string $stored ): string {
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
		$out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $out ? '' : $out;
	}
}
