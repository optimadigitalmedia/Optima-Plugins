<?php
/**
 * Optima Chat: the widget, the questions inbox, and the AI connection.
 *
 * Two modes, no rebuild between them:
 * - Collect (until an AI server is connected): the scripted paths (what it
 *   costs, our work, book a call, existing clients) answer right away, and any
 *   other question is saved to the inbox with the visitor's name and email,
 *   and emailed to the team, who answer personally.
 * - AI: free-text questions go to the AI server (Optima's, outside WordPress,
 *   so no AI key ever sits on a client site). If it fails or is slow, the
 *   chat falls back to collecting the question. Every conversation that
 *   leaves contact details is saved either way.
 *
 * AI server contract (see README): POST {question, history, page, site}
 * with "Authorization: Bearer <connection key>", answering
 * {"answer": "...", "handoff": false}.
 *
 * Disclosure: the panel opens on one line saying the visitor is talking to an
 * automated assistant (and, by default, that it gives no legal advice and
 * makes no one a client). The wording is a setting, and the text shown is
 * saved with every lead.
 *
 * Law-firm sites (Optima Playbook 5.3 and 5.8; Florida Bar Ethics Opinion 24-1):
 * - The window always shows LAW_NOTICE first. It is a constant, so no setting
 *   can remove or change it. Wording the firm approves (the Disclosure setting)
 *   shows under it, never in place of it.
 * - AI answers stay off (collect mode) until an operator turns them on for the
 *   site on the Chat screen, even when an AI server is connected.
 * - A lead holds only a name, a phone number and a short description (up to
 *   LAW_NOTE_MAX characters). The server drops everything else the browser sends.
 *
 * Loading: on page load the site gets only the button (static markup from
 * button(), chat-button.css and the small chat-loader.js). The full widget
 * (chat.js, chat.css) loads on the first tap, click or key press on the
 * button. Nothing per visitor goes into the page, and the REST routes take no
 * nonce, so cached pages stay correct. The panel only ever opens when the
 * visitor asks for it.
 *
 * @package Optima_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Chat {

	const OPTION = 'optima_chat';
	const CPT    = 'optima_chat_q';
	const NS     = 'optima-chat/v1';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_type' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'button' ), 5 );
	}

	/** @return array<string, mixed> */
	public static function settings(): array {
		$s = (array) get_option( self::OPTION, array() );
		return array(
			'enabled'   => ! empty( $s['enabled'] ),
			'name'      => (string) ( $s['name'] ?? '' ),
			'team'      => (string) ( $s['team'] ?? '' ),
			'launcher'  => (string) ( $s['launcher'] ?? '' ),
			'notify'    => (string) ( $s['notify'] ?? get_option( 'admin_email' ) ),
			'alert'     => (string) ( $s['alert'] ?? '' ),
			'book_url'  => (string) ( $s['book_url'] ?? '' ),
			'work_url'  => (string) ( $s['work_url'] ?? '' ),
			'photo_1'   => (string) ( $s['photo_1'] ?? '' ),
			'photo_2'   => (string) ( $s['photo_2'] ?? '' ),
			'ai_url'    => (string) ( $s['ai_url'] ?? '' ),
			'ai_key'    => (string) ( $s['ai_key'] ?? '' ),
			// The line visitors see first. Blank means the starting wording.
			'disclosure'       => (string) ( $s['disclosure'] ?? '' ),
			// When an operator last saved the disclosure on the Chat screen (0: never), and who.
			'disclosure_saved' => (int) ( $s['disclosure_saved'] ?? 0 ),
			'disclosure_by'    => (int) ( $s['disclosure_by'] ?? 0 ),
			// Law-firm sites only: AI answers, off until an operator turns them on (who and when).
			'law_ai'           => ! empty( $s['law_ai'] ),
			'law_ai_by'        => (int) ( $s['law_ai_by'] ?? 0 ),
			'law_ai_at'        => (int) ( $s['law_ai_at'] ?? 0 ),
			// Days a saved conversation is kept; 0 keeps them, with a reason.
			'retention'        => isset( $s['retention'] ) ? max( 0, (int) $s['retention'] ) : self::RETENTION_DAYS,
			'retention_reason' => (string) ( $s['retention_reason'] ?? '' ),
		);
	}

	/** The suite's form-entry policy: personal details go after 90 days unless the site records why it keeps them. */
	const RETENTION_DAYS = 90;

	/** Days to keep saved conversations, or 0 to keep them (only with a recorded reason). */
	public static function retention_days(): int {
		$s = self::settings();
		if ( 0 === $s['retention'] && '' === trim( $s['retention_reason'] ) ) {
			return self::RETENTION_DAYS;
		}
		return $s['retention'];
	}

	/** The starting wording. The firm approves the exact text before launch. */
	public static function default_disclosure(): string {
		return __( 'You are chatting with an automated assistant. It can\'t give legal advice, and chatting doesn\'t make you a client of the firm.', 'optima-chat' );
	}

	/**
	 * The fixed line every law-firm site's chat window shows first. One string,
	 * never translated or filtered, so no setting can take it away. Placeholder
	 * wording until counsel approves it (decided Nov 12, Q426-62).
	 */
	const LAW_NOTICE = "AI assistant, not a lawyer. It can't give legal advice. Please don't share confidential details here.";

	/** Longest description a law-firm site's chat takes, in characters. */
	const LAW_NOTE_MAX = 500;

	/**
	 * The lines at the top of the chat, in order. A law-firm site: the fixed
	 * line, then the firm's approved wording when an operator saved some. Any
	 * other site: the saved wording, or the starting wording.
	 *
	 * @return string[]
	 */
	public static function disclosure_lines(): array {
		$text = trim( self::settings()['disclosure'] );
		if ( self::is_law() ) {
			return '' !== $text && $text !== self::LAW_NOTICE ? array( self::LAW_NOTICE, $text ) : array( self::LAW_NOTICE );
		}
		return array( '' !== $text ? $text : self::default_disclosure() );
	}

	/** The disclosure shown at the top of the chat, as one string, saved with each lead. */
	public static function disclosure(): string {
		return implode( ' ', self::disclosure_lines() );
	}

	/**
	 * Is this a law-firm site? Optima People's setting when it is on (it reads
	 * the Optima Core business type in "auto"), else the business type itself
	 * (LegalService, Attorney or a law firm). Filter: optima_chat_is_law.
	 */
	public static function is_law(): bool {
		$law = false;
		if ( class_exists( 'Optima_Team' ) && method_exists( 'Optima_Team', 'is_law' ) ) {
			$law = Optima_Team::is_law();
		} elseif ( function_exists( 'optima_core_business' ) ) {
			$type = (string) ( ( (array) optima_core_business() )['business_type'] ?? '' );
			$law  = (bool) preg_match( '/legal|attorney|lawyer|law ?firm/i', $type );
		}
		return (bool) apply_filters( 'optima_chat_is_law', $law );
	}

	/**
	 * Switched on. (Until 0.2.4.11's law rules, a law-firm site also waited for
	 * an operator to save the disclosure; the fixed line now does that job.)
	 */
	public static function visible(): bool {
		return self::settings()['enabled'];
	}

	/** The connection key: wp-config's OPTIMA_CHAT_AI_KEY wins over the setting. */
	public static function ai_key(): string {
		return defined( 'OPTIMA_CHAT_AI_KEY' ) ? (string) OPTIMA_CHAT_AI_KEY : self::settings()['ai_key'];
	}

	public static function ai_connected(): bool {
		$url = self::settings()['ai_url'];
		return '' !== self::ai_key() && 0 === strpos( $url, 'https://' );
	}

	/** AI answers go out: connected, and on a law-firm site an operator has turned them on. */
	public static function ai_active(): bool {
		return self::ai_connected() && ( ! self::is_law() || self::settings()['law_ai'] );
	}

	/** "the Optima team", or "our team" when no names are set. */
	public static function team(): string {
		$t = trim( self::settings()['team'] );
		return '' !== $t ? $t : __( 'our team', 'optima-chat' );
	}

	public static function register_type(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => __( 'Chat questions', 'optima-chat' ),
					'singular_name' => __( 'Chat question', 'optima-chat' ),
					'edit_item'     => __( 'Chat question', 'optima-chat' ),
					'search_items'  => __( 'Search questions', 'optima-chat' ),
					'not_found'     => __( 'No questions yet. Every question a visitor leaves contact details for lands here.', 'optima-chat' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}

	/** Set by assets() when this page shows the chat button. */
	private static $show = false;

	/**
	 * On every public page while the chat is switched on: only the button's
	 * small stylesheet and the loader. chat.js and chat.css load on the first tap.
	 */
	public static function assets(): void {
		if ( ! self::visible() || is_admin() || ! (bool) apply_filters( 'optima_chat_show', true ) ) {
			return;
		}
		self::$show = true;
		wp_enqueue_style( 'optima-chat-button', OPTIMA_CHAT_URL . 'assets/chat-button.css', array(), OPTIMA_CHAT_VERSION );
		wp_enqueue_script( 'optima-chat', OPTIMA_CHAT_URL . 'assets/chat-loader.js', array(), OPTIMA_CHAT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/**
	 * What the widget needs, the same for every visitor (no nonce, nothing
	 * personal), so it can sit in cached HTML.
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$s    = self::settings();
		$book = '' !== $s['book_url'] ? $s['book_url'] : ( function_exists( 'optima_booking_url' ) ? optima_booking_url() : home_url( '/contact/' ) );
		$name = '' !== $s['name'] ? $s['name'] : sprintf( /* translators: %s: site name */ __( '%s assistant', 'optima-chat' ), self::short_site_name() );
		return array(
			'rest'     => esc_url_raw( rest_url( self::NS ) ),
			'name'     => $name,
			'team'     => self::team(),
			'hasTeam'  => '' !== trim( $s['team'] ),
			'launcher' => '' !== $s['launcher'] ? $s['launcher'] : ( '' !== trim( $s['team'] ) ? sprintf( /* translators: %s: team names */ __( 'Ask %s', 'optima-chat' ), str_replace( ' and ', ' & ', $s['team'] ) ) : __( 'Ask us', 'optima-chat' ) ),
			'photos'   => array_values( array_filter( array( $s['photo_1'], $s['photo_2'] ) ) ),
			'bookUrl'  => esc_url_raw( $book ),
			'workUrl'  => esc_url_raw( $s['work_url'] ),
			'ai'       => self::ai_active(),
			'law'      => self::is_law(),
			'noteMax'  => self::LAW_NOTE_MAX,
			'disclose' => self::disclosure_lines(),
		);
	}

	/**
	 * The button, printed in the footer: a real <button> at its final size, so
	 * nothing moves when the full widget takes it over. The widget's settings
	 * ride in data attributes (no inline script).
	 */
	public static function button(): void {
		if ( ! self::$show ) {
			return;
		}
		$c   = self::config();
		$css = add_query_arg( 'ver', OPTIMA_CHAT_VERSION, OPTIMA_CHAT_URL . 'assets/chat.css' );
		$js  = add_query_arg( 'ver', OPTIMA_CHAT_VERSION, OPTIMA_CHAT_URL . 'assets/chat.js' );
		$img = '';
		foreach ( $c['photos'] as $src ) {
			$img .= '<img src="' . esc_url( $src ) . '" alt="" width="24" height="24" loading="lazy" decoding="async">';
		}
		printf(
			'<div id="optima-chat" class="oc-root" data-config="%1$s" data-css="%2$s" data-js="%3$s"><button type="button" class="oc-launcher oc-pulse" aria-label="%4$s" aria-haspopup="dialog" aria-expanded="false"><span class="oc-stack oc-stack-sm" aria-hidden="true"><span class="oc-ai"><span class="oc-mark"></span></span>%5$s</span><span class="oc-launcher-label">%6$s</span></button></div>' . "\n",
			esc_attr( (string) wp_json_encode( $c ) ),
			esc_url( $css ),
			esc_url( $js ),
			esc_attr( $c['launcher'] ),
			$img, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			esc_html( $c['launcher'] )
		);
	}

	private static function short_site_name(): string {
		$n = (string) get_bloginfo( 'name' );
		$n = preg_split( '/\s*[|\-–]\s*/u', $n )[0];
		return trim( preg_replace( '/\s+(Media|&).*$/i', '', $n ) ) ?: $n;
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/lead',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'lead' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ask' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** At most $max requests per visitor IP per 10 minutes, per bucket. */
	private static function too_many( string $bucket, int $max ): bool {
		$ip  = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key = 'optima_chat_' . $bucket . '_' . md5( $ip . wp_salt( 'nonce' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return true;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}

	/** Clean a transcript sent by the widget: [{from, text}], newest last, capped. */
	private static function clean_history( $history ): array {
		$out = array();
		foreach ( array_slice( (array) $history, -30 ) as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$text = trim( wp_strip_all_tags( (string) ( $m['text'] ?? '' ) ) );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array(
				'from' => 'user' === ( $m['from'] ?? '' ) ? 'user' : 'bot',
				'text' => mb_substr( $text, 0, 1500 ),
			);
		}
		return $out;
	}

	/** Save a conversation with contact details, and email the team. */
	public static function lead( WP_REST_Request $req ) {
		if ( ! self::visible() ) { // Switched off.
			return new WP_Error( 'optima_chat_off', __( 'The chat is not available right now. Please call or email us instead.', 'optima-chat' ), array( 'status' => 403 ) );
		}
		if ( '' !== (string) $req->get_param( 'website' ) ) { // Honeypot: people never fill it.
			return array( 'ok' => true );
		}
		if ( self::too_many( 'lead', 5 ) ) {
			return new WP_Error( 'optima_chat_slow', __( 'Too many messages in a row. Please try again in a few minutes.', 'optima-chat' ), array( 'status' => 429 ) );
		}
		$law   = self::is_law();
		$name  = sanitize_text_field( mb_substr( (string) $req->get_param( 'name' ), 0, 100 ) );
		$email = $law ? '' : sanitize_email( (string) $req->get_param( 'email' ) ); // Law-firm site: no email, ever.
		$phone = self::phone( (string) $req->get_param( 'phone' ) );
		if ( '' === $name ) {
			return new WP_Error( 'optima_chat_name', __( 'Add your name so we know who we are talking to.', 'optima-chat' ), array( 'status' => 400 ) );
		}
		if ( $law && '' === $phone ) {
			return new WP_Error( 'optima_chat_phone', __( 'Add a phone number so we can reach you.', 'optima-chat' ), array( 'status' => 400 ) );
		}
		if ( '' === $phone && ! is_email( $email ) ) {
			return new WP_Error( 'optima_chat_email', __( 'Add a phone number or an email so we can reply.', 'optima-chat' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			$email = '';
		}
		$kinds   = array(
			'question' => __( 'Question', 'optima-chat' ),
			'change'   => __( 'Change request', 'optima-chat' ),
			'person'   => __( 'Wants a person', 'optima-chat' ),
			'market'   => __( 'Market check', 'optima-chat' ),
		);
		$kind    = sanitize_key( (string) $req->get_param( 'kind' ) );
		$kind    = isset( $kinds[ $kind ] ) ? $kind : 'question';
		if ( $law ) {
			// A law-firm site keeps a name, a phone number and a short description,
			// nothing else: the transcript, email and any other field are dropped.
			$note    = trim( mb_substr( sanitize_textarea_field( mb_substr( (string) $req->get_param( 'description' ), 0, self::LAW_NOTE_MAX * 2 ) ), 0, self::LAW_NOTE_MAX ) );
			$history = '' !== $note ? array( array( 'from' => 'user', 'text' => $note ) ) : array();
			$page    = esc_url_raw( (string) preg_replace( '/[?#].*$/s', '', (string) $req->get_param( 'page' ) ) ); // The page, without its query string.
		} else {
			$history = self::clean_history( $req->get_param( 'history' ) );
			$page    = esc_url_raw( (string) $req->get_param( 'page' ) );
		}
		$first   = '';
		foreach ( array_reverse( $history ) as $m ) {
			if ( 'user' === $m['from'] ) {
				$first = $m['text'];
				break;
			}
		}
		$title = $kinds[ $kind ] . ': ' . ( '' !== $first ? wp_trim_words( $first, 12 ) : $name );
		$lines = array();
		foreach ( $history as $m ) {
			$lines[] = ( 'user' === $m['from'] ? $name : __( 'Assistant', 'optima-chat' ) ) . ': ' . $m['text'];
		}
		$id = wp_insert_post(
			array(
				'post_type'    => self::CPT,
				'post_status'  => 'private',
				'post_title'   => $title,
				'post_content' => implode( "\n\n", $lines ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'optima_chat_save', __( 'Something went wrong saving that. Please email us instead.', 'optima-chat' ), array( 'status' => 500 ) );
		}
		update_post_meta( $id, '_optima_chat_name', $name );
		update_post_meta( $id, '_optima_chat_email', $email );
		update_post_meta( $id, '_optima_chat_phone', $phone );
		update_post_meta( $id, '_optima_chat_kind', $kind );
		update_post_meta( $id, '_optima_chat_page', $page );
		update_post_meta( $id, '_optima_chat_answered', 0 );
		// What the visitor was told at the start: the wording on the site when they wrote.
		update_post_meta( $id, '_optima_chat_disclosure', self::disclosure() );

		$contact = trim( ( '' !== $phone ? __( 'Text back:', 'optima-chat' ) . ' ' . self::pretty_phone( $phone ) . ' ' : '' ) . ( '' !== $email ? '<' . $email . '>' : '' ) );
		$to      = sanitize_email( self::settings()['notify'] );
		if ( is_email( $to ) ) {
			$body = $kinds[ $kind ] . "\n" . $name . ' ' . $contact . "\n" . ( '' !== $phone ? __( 'Tap to text:', 'optima-chat' ) . ' sms:+' . $phone . "\n" : '' ) . ( $page ? __( 'Page:', 'optima-chat' ) . ' ' . $page . "\n" : '' )
				. "\n" . implode( "\n\n", $lines ) . "\n\n" . __( 'Open the inbox:', 'optima-chat' ) . ' ' . admin_url( 'edit.php?post_type=' . self::CPT );
			wp_mail( $to, sprintf( /* translators: %s: question title */ __( 'New chat: %s', 'optima-chat' ), $title ), $body, '' !== $email ? array( 'Reply-To: ' . $name . ' <' . $email . '>' ) : array() );
		}
		// A short alert for a phone: the carrier's email-to-text address, or any address.
		$alert = sanitize_email( self::settings()['alert'] );
		if ( is_email( $alert ) ) {
			$short = $name . ': ' . wp_trim_words( '' !== $first ? $first : $kinds[ $kind ], 14 ) . ( '' !== $phone ? ' | ' . __( 'Text', 'optima-chat' ) . ' ' . self::pretty_phone( $phone ) : ( '' !== $email ? ' | ' . $email : '' ) );
			wp_mail( $alert, __( 'Site chat', 'optima-chat' ), mb_substr( $short, 0, 150 ) );
		}
		do_action( 'optima_chat_lead', $id, $kind, $name, $email );
		return array( 'ok' => true );
	}

	/** Digits with the US country code, or '' when it is not a phone number. */
	public static function phone( string $raw ): string {
		$d = preg_replace( '/\D/', '', $raw );
		if ( 10 === strlen( $d ) ) {
			$d = '1' . $d;
		}
		return ( 11 === strlen( $d ) && '1' === $d[0] ) ? $d : '';
	}

	/** 19195551234 becomes (919) 555-1234. */
	public static function pretty_phone( string $d ): string {
		return 11 === strlen( $d ) ? sprintf( '(%s) %s-%s', substr( $d, 1, 3 ), substr( $d, 4, 3 ), substr( $d, 7 ) ) : $d;
	}

	/** Pass a free-text question to the AI server. Falls back to collecting. */
	public static function ask( WP_REST_Request $req ) {
		$question = trim( wp_strip_all_tags( mb_substr( (string) $req->get_param( 'question' ), 0, 1000 ) ) );
		// A law-firm site answers with AI only after an operator turned it on.
		if ( '' === $question || ! self::ai_active() || ! self::visible() ) {
			return array( 'answer' => '', 'collect' => true );
		}
		if ( self::too_many( 'ask', 20 ) ) {
			return array( 'answer' => '', 'collect' => true );
		}
		$res = wp_remote_post(
			self::settings()['ai_url'],
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . self::ai_key(),
				),
				'body'    => wp_json_encode(
					array(
						'question' => $question,
						'history'  => self::clean_history( $req->get_param( 'history' ) ),
						'page'     => esc_url_raw( (string) $req->get_param( 'page' ) ),
						'site'     => home_url( '/' ),
					)
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array( 'answer' => '', 'collect' => true );
		}
		$data   = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$answer = is_array( $data ) ? trim( wp_strip_all_tags( (string) ( $data['answer'] ?? '' ) ) ) : '';
		if ( '' === $answer ) {
			return array( 'answer' => '', 'collect' => true );
		}
		return array(
			'answer'  => mb_substr( $answer, 0, 2000 ),
			'handoff' => ! empty( $data['handoff'] ),
			'collect' => false,
		);
	}
}
