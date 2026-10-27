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
	}

	/** @return array<string, mixed> */
	public static function settings(): array {
		$s = (array) get_option( self::OPTION, array() );
		return array(
			'enabled'   => ! empty( $s['enabled'] ),
			'name'      => (string) ( $s['name'] ?? '' ),
			'team'      => (string) ( $s['team'] ?? '' ),
			'launcher'  => (string) ( $s['launcher'] ?? '' ),
			'teaser'    => ! isset( $s['teaser'] ) || ! empty( $s['teaser'] ),
			'teaser_by' => (string) ( $s['teaser_by'] ?? '' ),
			'notify'    => (string) ( $s['notify'] ?? get_option( 'admin_email' ) ),
			'alert'     => (string) ( $s['alert'] ?? '' ),
			'book_url'  => (string) ( $s['book_url'] ?? '' ),
			'work_url'  => (string) ( $s['work_url'] ?? '' ),
			'photo_1'   => (string) ( $s['photo_1'] ?? '' ),
			'photo_2'   => (string) ( $s['photo_2'] ?? '' ),
			'ai_url'    => (string) ( $s['ai_url'] ?? '' ),
			'ai_key'    => (string) ( $s['ai_key'] ?? '' ),
		);
	}

	/** The connection key: wp-config's OPTIMA_CHAT_AI_KEY wins over the setting. */
	public static function ai_key(): string {
		return defined( 'OPTIMA_CHAT_AI_KEY' ) ? (string) OPTIMA_CHAT_AI_KEY : self::settings()['ai_key'];
	}

	public static function ai_connected(): bool {
		$url = self::settings()['ai_url'];
		return '' !== self::ai_key() && 0 === strpos( $url, 'https://' );
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

	/** On every public page while the chat is switched on. */
	public static function assets(): void {
		$s = self::settings();
		if ( ! $s['enabled'] || is_admin() || ! (bool) apply_filters( 'optima_chat_show', true ) ) {
			return;
		}
		wp_enqueue_style( 'optima-chat', OPTIMA_CHAT_URL . 'assets/chat.css', array(), OPTIMA_CHAT_VERSION );
		wp_enqueue_script( 'optima-chat', OPTIMA_CHAT_URL . 'assets/chat.js', array(), OPTIMA_CHAT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		$book = '' !== $s['book_url'] ? $s['book_url'] : ( function_exists( 'optima_booking_url' ) ? optima_booking_url() : home_url( '/contact/' ) );
		$name = '' !== $s['name'] ? $s['name'] : sprintf( /* translators: %s: site name */ __( '%s assistant', 'optima-chat' ), self::short_site_name() );
		wp_localize_script(
			'optima-chat',
			'optimaChatConfig',
			array(
				'rest'     => esc_url_raw( rest_url( self::NS ) ),
				'name'     => $name,
				'team'     => self::team(),
				'hasTeam'  => '' !== trim( $s['team'] ),
				'launcher' => '' !== $s['launcher'] ? $s['launcher'] : ( '' !== trim( $s['team'] ) ? sprintf( /* translators: %s: team names */ __( 'Ask %s', 'optima-chat' ), str_replace( ' and ', ' & ', $s['team'] ) ) : __( 'Ask us', 'optima-chat' ) ),
				'teaser'   => $s['teaser'],
				'teaserBy' => $s['teaser_by'],
				'photos'   => array_values( array_filter( array( $s['photo_1'], $s['photo_2'] ) ) ),
				'bookUrl'  => esc_url_raw( $book ),
				'workUrl'  => esc_url_raw( $s['work_url'] ),
				'ai'       => self::ai_connected(),
			)
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
		if ( '' !== (string) $req->get_param( 'website' ) ) { // Honeypot: people never fill it.
			return array( 'ok' => true );
		}
		if ( self::too_many( 'lead', 5 ) ) {
			return new WP_Error( 'optima_chat_slow', __( 'Too many messages in a row. Please try again in a few minutes.', 'optima-chat' ), array( 'status' => 429 ) );
		}
		$name  = sanitize_text_field( mb_substr( (string) $req->get_param( 'name' ), 0, 100 ) );
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		$phone = self::phone( (string) $req->get_param( 'phone' ) );
		if ( '' === $name ) {
			return new WP_Error( 'optima_chat_name', __( 'Add your name so we know who we are talking to.', 'optima-chat' ), array( 'status' => 400 ) );
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
		$history = self::clean_history( $req->get_param( 'history' ) );
		$page    = esc_url_raw( (string) $req->get_param( 'page' ) );
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
		if ( '' === $question || ! self::ai_connected() ) {
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
