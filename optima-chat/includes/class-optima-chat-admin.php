<?php
/**
 * Optima Build -> Chat (settings) and Chat questions (the inbox).
 *
 * @package Optima_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Chat_Admin {

	const SLUG = 'optima-chat';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_CHAT_FILE ), array( __CLASS__, 'action_link' ) );
		add_filter( 'manage_' . Optima_Chat::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Optima_Chat::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'add_meta_boxes_' . Optima_Chat::CPT, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . Optima_Chat::CPT, array( __CLASS__, 'save_answered' ) );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'states' ), 10, 2 );
	}

	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'optima-build', 'recon-build', 'site-build', 'optima' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	public static function url(): string {
		return 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function unanswered(): int {
		$q = new WP_Query(
			array(
				'post_type'      => Optima_Chat::CPT,
				'post_status'    => 'private',
				'meta_key'       => '_optima_chat_answered', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 99,
				'no_found_rows'  => true,
			)
		);
		return count( $q->posts );
	}

	public static function menu(): void {
		$parent = self::parent();
		$n      = self::unanswered();
		$bubble = $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';
		add_submenu_page( $parent, __( 'Chat questions', 'optima-chat' ), __( 'Chat questions', 'optima-chat' ) . $bubble, 'edit_posts', 'edit.php?post_type=' . Optima_Chat::CPT );
		add_submenu_page( $parent, __( 'Chat', 'optima-chat' ), __( 'Chat', 'optima-chat' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/** Keep the Build section open while reading a question. */
	public static function highlight( $file ) {
		global $submenu_file;
		$screen = get_current_screen();
		if ( $screen && Optima_Chat::CPT === $screen->post_type ) {
			$submenu_file = 'edit.php?post_type=' . Optima_Chat::CPT; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::parent();
		}
		return $file;
	}

	/** Every question is private, so the "Private" tag says nothing. */
	public static function states( array $states, WP_Post $post ): array {
		return Optima_Chat::CPT === $post->post_type ? array() : $states;
	}

	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( false !== strpos( $hook, self::SLUG ) || ( $screen && Optima_Chat::CPT === $screen->post_type ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_CHAT_URL . 'assets/optima-admin.css' );
		}
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'optima-chat' ) . '</a>' );
		return $links;
	}

	public static function columns( array $cols ): array {
		return array(
			'cb'         => $cols['cb'] ?? '',
			'title'      => __( 'Question', 'optima-chat' ),
			'optima_from' => __( 'From', 'optima-chat' ),
			'optima_kind' => __( 'Type', 'optima-chat' ),
			'optima_done' => __( 'Answered', 'optima-chat' ),
			// The day it was asked. WordPress's own date column says "Last Modified" for a private post.
			'optima_asked' => __( 'Asked', 'optima-chat' ),
		);
	}

	public static function column( string $col, int $id ): void {
		if ( 'optima_asked' === $col ) {
			echo esc_html( get_the_date( 'M j, Y g:i a', $id ) );
		} elseif ( 'optima_from' === $col ) {
			$email = (string) get_post_meta( $id, '_optima_chat_email', true );
			$phone = (string) get_post_meta( $id, '_optima_chat_phone', true );
			$name  = (string) get_post_meta( $id, '_optima_chat_name', true );
			if ( '' === $name . $email . $phone ) {
				echo '<span class="description">' . esc_html__( 'No contact left', 'optima-chat' ) . '</span>';
			}
			echo esc_html( $name );
			if ( '' !== $phone ) {
				echo '<br><a href="' . esc_url( 'sms:+' . $phone, array( 'sms' ) ) . '">' . esc_html( Optima_Chat::pretty_phone( $phone ) ) . '</a>';
			}
			if ( '' !== $email ) {
				echo '<br><a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
			}
		} elseif ( 'optima_kind' === $col ) {
			$k = (string) get_post_meta( $id, '_optima_chat_kind', true );
			$k = '' !== $k ? $k : 'question'; // Saved before kinds existed: a question.
			echo esc_html( array( 'question' => __( 'Question', 'optima-chat' ), 'change' => __( 'Change request', 'optima-chat' ), 'person' => __( 'Wants a person', 'optima-chat' ) )[ $k ] ?? $k );
		} elseif ( 'optima_done' === $col ) {
			echo get_post_meta( $id, '_optima_chat_answered', true ) ? '<span class="sh-pill is-good">' . esc_html__( 'Yes', 'optima-chat' ) . '</span>' : '<span class="sh-pill is-warn">' . esc_html__( 'Not yet', 'optima-chat' ) . '</span>';
		}
	}

	public static function meta_box(): void {
		remove_meta_box( 'submitdiv', Optima_Chat::CPT, 'side' );
		add_meta_box( 'optima-chat-convo', __( 'Conversation', 'optima-chat' ), array( __CLASS__, 'convo_box' ), Optima_Chat::CPT, 'normal', 'high' );
		add_meta_box( 'optima-chat-reply', __( 'Reply', 'optima-chat' ), array( __CLASS__, 'reply_box' ), Optima_Chat::CPT, 'side', 'high' );
	}

	public static function convo_box( WP_Post $post ): void {
		$page = (string) get_post_meta( $post->ID, '_optima_chat_page', true );
		if ( $page ) {
			echo '<p>' . esc_html__( 'Asked on', 'optima-chat' ) . ' <a href="' . esc_url( $page ) . '" target="_blank" rel="noopener">' . esc_html( $page ) . '</a></p>';
		}
		$told = (string) get_post_meta( $post->ID, '_optima_chat_disclosure', true );
		if ( '' !== $told ) {
			echo '<p class="description">' . esc_html__( 'Shown at the start of the chat:', 'optima-chat' ) . ' ' . esc_html( $told ) . '</p>';
		}
		echo '<div style="white-space:pre-wrap;line-height:1.6">' . esc_html( $post->post_content ) . '</div>';
	}

	public static function reply_box( WP_Post $post ): void {
		$name  = (string) get_post_meta( $post->ID, '_optima_chat_name', true );
		$email = (string) get_post_meta( $post->ID, '_optima_chat_email', true );
		$subj  = rawurlencode( __( 'Your question', 'optima-chat' ) . ': ' . $post->post_title );
		wp_nonce_field( 'optima_chat_answered', 'optima_chat_nonce' );
		?>
		<?php $phone = (string) get_post_meta( $post->ID, '_optima_chat_phone', true ); ?>
		<p><strong><?php echo esc_html( $name ); ?></strong><?php echo '' !== $phone ? '<br>' . esc_html( Optima_Chat::pretty_phone( $phone ) ) : ''; ?><?php echo '' !== $email ? '<br>' . esc_html( $email ) : ''; ?></p>
		<?php if ( '' !== $phone ) : ?><p><a class="button button-primary" href="<?php echo esc_url( 'sms:+' . $phone, array( 'sms' ) ); ?>"><?php esc_html_e( 'Text back', 'optima-chat' ); ?></a></p><?php endif; ?>
		<?php if ( '' !== $email ) : ?><p><a class="button<?php echo '' === $phone ? ' button-primary' : ''; ?>" href="<?php echo esc_url( 'mailto:' . $email . '?subject=' . $subj ); ?>"><?php esc_html_e( 'Reply by email', 'optima-chat' ); ?></a></p><?php endif; ?>
		<p><label><input type="checkbox" name="optima_chat_answered" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_optima_chat_answered', true ) ); ?>> <?php esc_html_e( 'Answered', 'optima-chat' ); ?></label></p>
		<?php submit_button( __( 'Save', 'optima-chat' ), 'secondary', 'save', false ); ?>
		<?php
	}

	public static function save_answered( int $id ): void {
		if ( ! isset( $_POST['optima_chat_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['optima_chat_nonce'] ), 'optima_chat_answered' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		update_post_meta( $id, '_optima_chat_answered', empty( $_POST['optima_chat_answered'] ) ? 0 : 1 );
	}

	public static function register(): void {
		register_setting( 'optima_chat_group', Optima_Chat::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in  = (array) $in;
		$url = esc_url_raw( trim( (string) ( $in['ai_url'] ?? '' ) ) );
		if ( '' !== $url && 0 !== strpos( $url, 'https://' ) ) {
			add_settings_error( Optima_Chat::OPTION, 'ai_url', __( 'The AI server address must start with https://.', 'optima-chat' ) );
			$url = '';
		}
		$key = trim( (string) ( $in['ai_key'] ?? '' ) );
		if ( '' === $key ) {
			$key = Optima_Chat::settings()['ai_key']; // Blank keeps the saved key; it is never shown back.
		}
		if ( ! empty( $in['ai_key_clear'] ) ) {
			$key = '';
		}

		// The disclosure. Saving it on this screen as an operator stamps who and
		// when (on a law-firm site it is the firm's approved wording, shown under
		// the fixed line); a fill from the theme's setup file or any other
		// update_option() keeps the earlier stamp.
		$old        = Optima_Chat::settings();
		$disclosure = trim( mb_substr( sanitize_textarea_field( (string) ( $in['disclosure'] ?? $old['disclosure'] ) ), 0, 400 ) );
		$saved      = $old['disclosure_saved'];
		$by         = $old['disclosure_by'];
		$form       = isset( $_POST['option_page'] ) && 'optima_chat_group' === sanitize_key( wp_unslash( $_POST['option_page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- options.php checked the nonce.
		$from_form  = $form && array_key_exists( 'disclosure', $in );
		$operator   = self::is_operator();
		if ( $from_form && $operator && ( 0 === $saved || $disclosure !== $old['disclosure'] ) ) {
			$saved = time();
			$by    = get_current_user_id();
		}

		// AI answers on a law-firm site: only an operator, ticking the box on this
		// screen, turns them on or off. Anyone else's save keeps what was there.
		$law_ai    = $old['law_ai'];
		$law_ai_by = $old['law_ai_by'];
		$law_ai_at = $old['law_ai_at'];
		if ( $form && $operator && Optima_Chat::is_law() ) {
			$want = ! empty( $in['law_ai'] );
			if ( $want !== $law_ai ) {
				$law_ai    = $want;
				$law_ai_by = $want ? get_current_user_id() : 0;
				$law_ai_at = $want ? time() : 0;
			}
		}

		// Retention: one of the offered lengths, or keep (0) with a reason.
		$days   = isset( $in['retention'] ) ? (int) $in['retention'] : $old['retention'];
		$days   = in_array( $days, self::RETENTION_CHOICES, true ) ? $days : Optima_Chat::RETENTION_DAYS;
		$reason = trim( sanitize_text_field( (string) ( $in['retention_reason'] ?? '' ) ) );
		if ( 0 === $days && '' === $reason ) {
			add_settings_error( Optima_Chat::OPTION, 'retention', __( 'To keep conversations, write down why. Until then they are deleted after 90 days.', 'optima-chat' ) );
			$days = Optima_Chat::RETENTION_DAYS;
		}

		return array(
			'enabled'   => ! empty( $in['enabled'] ) ? 1 : 0,
			'name'      => sanitize_text_field( (string) ( $in['name'] ?? '' ) ),
			'team'      => sanitize_text_field( (string) ( $in['team'] ?? '' ) ),
			'launcher'  => sanitize_text_field( (string) ( $in['launcher'] ?? '' ) ),
			'notify'    => sanitize_email( (string) ( $in['notify'] ?? '' ) ),
			'alert'     => sanitize_email( (string) ( $in['alert'] ?? '' ) ),
			'book_url'  => esc_url_raw( (string) ( $in['book_url'] ?? '' ) ),
			'work_url'  => esc_url_raw( (string) ( $in['work_url'] ?? '' ) ),
			'photo_1'   => esc_url_raw( (string) ( $in['photo_1'] ?? '' ) ),
			'photo_2'   => esc_url_raw( (string) ( $in['photo_2'] ?? '' ) ),
			'ai_url'    => $url,
			'ai_key'    => sanitize_text_field( $key ),
			'disclosure'       => $disclosure,
			'disclosure_saved' => $saved,
			'disclosure_by'    => $by,
			'law_ai'           => $law_ai ? 1 : 0,
			'law_ai_by'        => $law_ai_by,
			'law_ai_at'        => $law_ai_at,
			'retention'        => $days,
			'retention_reason' => 0 === $days ? $reason : '',
		);
	}

	/** An Optima operator (the web team), or an administrator where Optima Build is not installed. */
	private static function is_operator(): bool {
		return class_exists( 'Optima_Hub_Access' ) ? Optima_Hub_Access::is_operator() : current_user_can( 'manage_options' );
	}

	/** Days a conversation can be kept for; 0 keeps it (with a reason). */
	const RETENTION_CHOICES = array( 30, 60, 90, 180, 365, 0 );

	private static function row( string $label, string $field, string $help = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fields escaped by caller.
	}

	/** The name each field is read out as (every input has a label). */
	const LABELS = array(
		'team'      => 'Team names',
		'name'      => 'Assistant name',
		'launcher'  => 'Button text',
		'photo_1'   => 'First photo',
		'photo_2'   => 'Second photo',
		'notify'    => 'Email new questions to',
		'alert'     => 'Text me new questions',
		'book_url'  => 'Booking page',
		'work_url'  => 'Our work page',
		'ai_url'    => 'AI server address',
		'ai_key'    => 'Connection key',
		'retention_reason' => 'Why they are kept',
	);

	private static function text( string $key, string $value, string $placeholder = '', string $type = 'text' ): string {
		return '<input type="' . esc_attr( $type ) . '" class="regular-text" id="optima-chat-' . esc_attr( $key ) . '" aria-label="' . esc_attr( self::LABELS[ $key ] ?? $key ) . '" name="' . esc_attr( Optima_Chat::OPTION . '[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s  = Optima_Chat::settings();
		$o  = Optima_Chat::OPTION;
		$ai = Optima_Chat::ai_active();
		$n  = self::unanswered();
		$law  = Optima_Chat::is_law();
		Optima_Admin_UI::open( __( 'Chat', 'optima-chat' ), __( 'A chat in the corner of the site. It answers the common questions, books calls and takes change requests, and saves every visitor question to Chat questions.', 'optima-chat' ), __( 'Chat', 'optima-chat' ) );
		settings_errors( $o );
		?>
		<?php if ( $law ) : ?>
		<section class="sh-box sh-check is-good">
			<p class="sh-label"><?php esc_html_e( 'Law-firm site', 'optima-chat' ); ?></p>
			<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php esc_html_e( 'Law-firm rules are on', 'optima-chat' ); ?></p>
			<p class="sh-note">
				<?php
				printf(
					/* translators: %d: characters */
					esc_html__( 'The chat window always shows the fixed line below. The chat takes only a name, a phone number and a short description (up to %d characters): no email, no other fields, no files. AI answers stay off unless the web team turns them on under AI connection.', 'optima-chat' ),
					(int) Optima_Chat::LAW_NOTE_MAX
				);
				?>
			</p>
		</section>
		<?php endif; ?>
		<div class="sh-grid sh-grid-3">
			<section class="sh-box sh-check is-<?php echo $s['enabled'] ? 'good' : 'off'; ?>">
				<p class="sh-label"><?php esc_html_e( 'On the site', 'optima-chat' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo $s['enabled'] ? esc_html__( 'Showing', 'optima-chat' ) : esc_html__( 'Hidden', 'optima-chat' ); ?></p>
				<p class="sh-note"><?php echo $s['enabled'] ? esc_html__( 'Visitors see the chat button on every page. The chat opens only when they tap it.', 'optima-chat' ) : esc_html__( 'Fill in the details below, then tick Show the chat on the site.', 'optima-chat' ); ?></p>
			</section>
			<section class="sh-box sh-check is-<?php echo $ai ? 'good' : 'off'; ?>">
				<p class="sh-label"><?php esc_html_e( 'Answers', 'optima-chat' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo $ai ? esc_html__( 'AI connected', 'optima-chat' ) : esc_html__( 'Collecting questions', 'optima-chat' ); ?></p>
				<p class="sh-note"><?php echo $ai ? esc_html__( 'Free-text questions get an AI answer. If the AI server is down, questions are collected instead.', 'optima-chat' ) : ( $law ? esc_html__( 'No AI answers. Visitors leave a name, a phone number and a short description for the firm to call back.', 'optima-chat' ) : esc_html__( 'Common questions are answered right away. Anything else is saved with the visitor\'s email for you to answer.', 'optima-chat' ) ); ?></p>
			</section>
			<section class="sh-box sh-check is-<?php echo $n ? 'warn' : 'good'; ?>">
				<p class="sh-label"><?php esc_html_e( 'Waiting for an answer', 'optima-chat' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( (string) $n ); ?></p>
				<p class="sh-note"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Optima_Chat::CPT ) ); ?>"><?php esc_html_e( 'Open Chat questions', 'optima-chat' ); ?> &rsaquo;</a></p>
			</section>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( 'optima_chat_group' ); ?>
			<div class="sh-box" id="optima-chat-disclosure-box">
				<h2 class="sh-title"><?php esc_html_e( 'What visitors see first', 'optima-chat' ); ?></h2>
				<p><?php esc_html_e( 'The top of the chat, shown before the visitor types anything, and saved with every conversation.', 'optima-chat' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$by   = $s['disclosure_by'] ? get_userdata( $s['disclosure_by'] ) : false;
					$when = $s['disclosure_saved']
						? sprintf(
							/* translators: 1: person, 2: date */
							__( 'Saved by %1$s on %2$s.', 'optima-chat' ),
							$by ? $by->display_name : __( 'an operator', 'optima-chat' ),
							wp_date( get_option( 'date_format' ), $s['disclosure_saved'] )
						)
						: ( $law ? __( 'Nothing added yet. Only the fixed line shows.', 'optima-chat' ) : __( 'Not saved yet. The starting wording shows until it is.', 'optima-chat' ) );
					if ( $law ) {
						// The fixed line is a constant in the plugin: shown here, never editable.
						self::row(
							__( 'Fixed line', 'optima-chat' ),
							'<p><strong>' . esc_html( Optima_Chat::LAW_NOTICE ) . '</strong></p>',
							__( 'Always first in the chat window on a law-firm site. It can\'t be removed or changed here. Placeholder wording until counsel approves it.', 'optima-chat' )
						);
						self::row(
							__( 'Firm-approved wording', 'optima-chat' ),
							'<textarea class="large-text" rows="3" id="optima-chat-disclosure" aria-label="' . esc_attr__( 'Firm-approved wording', 'optima-chat' ) . '" name="' . esc_attr( $o ) . '[disclosure]">' . esc_textarea( $s['disclosure'] ) . '</textarea>'
								. '<p class="description">' . esc_html__( 'Optional. Wording the firm has approved shows under the fixed line, never in place of it. Leave blank for the fixed line alone.', 'optima-chat' ) . '</p>',
							$when
						);
					} else {
						self::row(
							__( 'Disclosure', 'optima-chat' ),
							'<textarea class="large-text" rows="3" id="optima-chat-disclosure" aria-label="' . esc_attr__( 'Disclosure', 'optima-chat' ) . '" name="' . esc_attr( $o ) . '[disclosure]">' . esc_textarea( Optima_Chat::disclosure() ) . '</textarea>'
								. '<p class="description"><strong>' . esc_html__( 'Starting wording: the firm approves the exact text.', 'optima-chat' ) . '</strong> ' . esc_html__( 'The starting wording says the visitor is talking to an automated assistant, that it can\'t give legal advice, and that chatting doesn\'t make them a client. Blank goes back to it.', 'optima-chat' ) . '</p>',
							$when
						);
					}
					?>
				</table>
			</div>
			<div class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'How it looks', 'optima-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::row( __( 'Show the chat on the site', 'optima-chat' ), '<label class="sh-target"><input type="checkbox" name="' . esc_attr( $o ) . '[enabled]" value="1" ' . checked( $s['enabled'], true, false ) . '> ' . esc_html__( 'Show the chat button on every page', 'optima-chat' ) . '</label>' );
					self::row( __( 'Team names', 'optima-chat' ), self::text( 'team', $s['team'], 'the Optima team' ), __( 'Who answers. Used in the button ("Ask our team") and the replies. Blank: "our team".', 'optima-chat' ) );
					self::row( __( 'Assistant name', 'optima-chat' ), self::text( 'name', $s['name'], __( 'Site name + assistant', 'optima-chat' ) ) );
					self::row( __( 'Button text', 'optima-chat' ), self::text( 'launcher', $s['launcher'], __( 'Ask + team names', 'optima-chat' ) ) );
					self::row( __( 'Photos', 'optima-chat' ), self::text( 'photo_1', $s['photo_1'], 'https://', 'url' ) . '<br>' . self::text( 'photo_2', $s['photo_2'], 'https://', 'url' ), __( 'Optional. Paste the Media links of the people who answer. They show on the button and in the chat.', 'optima-chat' ) );
					?>
				</table>
			</div>
			<div class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Where it sends people', 'optima-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::row( __( 'Email new questions to', 'optima-chat' ), self::text( 'notify', $s['notify'], get_option( 'admin_email' ), 'email' ) );
					self::row( __( 'Text me new questions', 'optima-chat' ), self::text( 'alert', $s['alert'], '9195551234@vtext.com', 'email' ), __( 'A short text with the question and the number to text back. Use your cell number at your carrier: @vtext.com (Verizon), @tmomail.net (T-Mobile), @msg.fi.google.com (Google Fi). AT&T stopped this in 2025. Blank: no text.', 'optima-chat' ) );
					self::row( __( 'Booking page', 'optima-chat' ), self::text( 'book_url', $s['book_url'], function_exists( 'optima_booking_url' ) ? optima_booking_url() : home_url( '/contact/' ), 'url' ), __( 'Blank uses the site\'s booking page, or Contact.', 'optima-chat' ) );
					self::row( __( 'Our work page', 'optima-chat' ), self::text( 'work_url', $s['work_url'], 'https://', 'url' ), __( 'Blank hides "Show me your work".', 'optima-chat' ) );
					?>
				</table>
			</div>
			<div class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'How long conversations are kept', 'optima-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$choices = '';
					foreach ( self::RETENTION_CHOICES as $d ) {
						/* translators: %d: days */
						$label    = 0 === $d ? __( 'Keep them (write down why)', 'optima-chat' ) : sprintf( _n( '%d day', '%d days', $d, 'optima-chat' ), $d );
						$choices .= '<option value="' . esc_attr( (string) $d ) . '"' . selected( $s['retention'], $d, false ) . '>' . esc_html( $label ) . '</option>';
					}
					self::row(
						__( 'Delete saved conversations after', 'optima-chat' ),
						'<select id="optima-chat-retention" aria-label="' . esc_attr__( 'Delete saved conversations after', 'optima-chat' ) . '" name="' . esc_attr( $o ) . '[retention]">' . $choices . '</select>',
						__( 'Each conversation holds a visitor\'s name, email or phone and what they wrote. A daily job deletes the older ones. 90 days matches how long form entries are kept.', 'optima-chat' )
					);
					self::row( __( 'Why they are kept', 'optima-chat' ), self::text( 'retention_reason', $s['retention_reason'], __( 'Only when keeping them', 'optima-chat' ) ), __( 'Needed to keep them. Record the same reason in the site\'s decisions log.', 'optima-chat' ) );
					?>
				</table>
				<p class="sh-note"><?php esc_html_e( 'Tools, Export Personal Data and Erase Personal Data find a visitor\'s conversations by the email they gave. A conversation left with only a phone number is deleted by hand from Chat questions.', 'optima-chat' ); ?></p>
			</div>
			<div class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'AI connection', 'optima-chat' ); ?></h2>
				<?php $wired = Optima_Chat::ai_connected(); ?>
				<div class="sh-status <?php echo $wired ? '' : 'is-off'; ?>"><span class="sh-dot" aria-hidden="true"></span><p class="sh-value"><?php echo $wired ? esc_html__( 'Connected', 'optima-chat' ) : esc_html__( 'Not connected', 'optima-chat' ); ?></p></div>
				<p class="sh-note"><?php echo $wired ? esc_html__( 'Optima\'s AI server answers free-text questions. The AI company\'s key stays on that server and never touches this site.', 'optima-chat' ) : esc_html__( 'Free-text questions are collected for you to answer. The web team connects the AI server when this site gets it.', 'optima-chat' ); ?></p>
				<?php if ( $law ) : ?>
					<?php
					$on_by = $s['law_ai_by'] ? get_userdata( $s['law_ai_by'] ) : false;
					$op    = self::is_operator();
					?>
					<div class="sh-check is-<?php echo $s['law_ai'] ? 'warn' : 'off'; ?>" id="optima-chat-law-ai">
						<p class="sh-label"><?php esc_html_e( 'AI answers on this law-firm site', 'optima-chat' ); ?></p>
						<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo $s['law_ai'] ? esc_html__( 'On', 'optima-chat' ) : esc_html__( 'Off: collecting only', 'optima-chat' ); ?></p>
						<p class="sh-note"><strong><?php esc_html_e( 'A chatbot that answers legal questions is the firm\'s responsibility.', 'optima-chat' ); ?></strong> <?php esc_html_e( 'The firm answers for what the assistant tells a visitor, the same as for anyone on its staff (Florida Bar Ethics Opinion 24-1 says so plainly). Turn this on only when the firm has approved AI answers in writing.', 'optima-chat' ); ?></p>
						<p><label class="sh-target"><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[law_ai]" value="1" <?php checked( $s['law_ai'] ); ?> <?php disabled( ! $op ); ?>> <?php esc_html_e( 'Give AI answers on this site', 'optima-chat' ); ?></label></p>
						<p class="sh-note">
							<?php
							if ( $s['law_ai'] && $s['law_ai_at'] ) {
								printf(
									/* translators: 1: person, 2: date */
									esc_html__( 'Turned on by %1$s on %2$s.', 'optima-chat' ),
									esc_html( $on_by ? $on_by->display_name : __( 'an operator', 'optima-chat' ) ),
									esc_html( wp_date( get_option( 'date_format' ), $s['law_ai_at'] ) )
								);
								echo ' ';
							}
							echo $op ? esc_html__( 'Answers also need the AI server connected below.', 'optima-chat' ) : esc_html__( 'Only the web team can change this.', 'optima-chat' );
							?>
						</p>
					</div>
				<?php endif; ?>
				<?php Optima_Admin_UI::dev_open(); ?>
				<table class="form-table" role="presentation">
					<?php
					self::row( __( 'AI server address', 'optima-chat' ), self::text( 'ai_url', $s['ai_url'], 'https://', 'url' ) );
					if ( defined( 'OPTIMA_CHAT_AI_KEY' ) ) {
						self::row( __( 'Connection key', 'optima-chat' ), esc_html__( 'Set in wp-config.php (OPTIMA_CHAT_AI_KEY).', 'optima-chat' ) );
					} else {
						// The key belongs in wp-config.php. A key saved here before keeps working until it is removed.
						self::row(
							__( 'Connection key', 'optima-chat' ),
							( '' !== $s['ai_key']
								? esc_html__( 'Saved on this screen earlier. Move it to wp-config.php (OPTIMA_CHAT_AI_KEY), then remove it here.', 'optima-chat' ) . '<br><label class="sh-target"><input type="checkbox" name="' . esc_attr( $o ) . '[ai_key_clear]" value="1"> ' . esc_html__( 'Remove the saved key', 'optima-chat' ) . '</label>'
								: esc_html__( 'Not set. Define OPTIMA_CHAT_AI_KEY in wp-config.php.', 'optima-chat' ) )
						);
					}
					?>
				</table>
				<?php Optima_Admin_UI::dev_close(); ?>
			</div>
			<?php submit_button( __( 'Save', 'optima-chat' ) ); ?>
		</form>
		<?php
		Optima_Admin_UI::close();
	}
}
