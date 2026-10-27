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
			'date'       => __( 'Asked', 'optima-chat' ),
		);
	}

	public static function column( string $col, int $id ): void {
		if ( 'optima_from' === $col ) {
			$email = (string) get_post_meta( $id, '_optima_chat_email', true );
			$phone = (string) get_post_meta( $id, '_optima_chat_phone', true );
			echo esc_html( (string) get_post_meta( $id, '_optima_chat_name', true ) );
			if ( '' !== $phone ) {
				echo '<br><a href="' . esc_url( 'sms:+' . $phone, array( 'sms' ) ) . '">' . esc_html( Optima_Chat::pretty_phone( $phone ) ) . '</a>';
			}
			if ( '' !== $email ) {
				echo '<br><a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
			}
		} elseif ( 'optima_kind' === $col ) {
			$k = (string) get_post_meta( $id, '_optima_chat_kind', true );
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
		return array(
			'enabled'   => ! empty( $in['enabled'] ) ? 1 : 0,
			'name'      => sanitize_text_field( (string) ( $in['name'] ?? '' ) ),
			'team'      => sanitize_text_field( (string) ( $in['team'] ?? '' ) ),
			'launcher'  => sanitize_text_field( (string) ( $in['launcher'] ?? '' ) ),
			'teaser'    => ! empty( $in['teaser'] ) ? 1 : 0,
			'teaser_by' => sanitize_text_field( (string) ( $in['teaser_by'] ?? '' ) ),
			'notify'    => sanitize_email( (string) ( $in['notify'] ?? '' ) ),
			'alert'     => sanitize_email( (string) ( $in['alert'] ?? '' ) ),
			'book_url'  => esc_url_raw( (string) ( $in['book_url'] ?? '' ) ),
			'work_url'  => esc_url_raw( (string) ( $in['work_url'] ?? '' ) ),
			'photo_1'   => esc_url_raw( (string) ( $in['photo_1'] ?? '' ) ),
			'photo_2'   => esc_url_raw( (string) ( $in['photo_2'] ?? '' ) ),
			'ai_url'    => $url,
			'ai_key'    => sanitize_text_field( $key ),
		);
	}

	private static function row( string $label, string $field, string $help = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fields escaped by caller.
	}

	private static function text( string $key, string $value, string $placeholder = '', string $type = 'text' ): string {
		return '<input type="' . esc_attr( $type ) . '" class="regular-text" name="' . esc_attr( Optima_Chat::OPTION . '[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s  = Optima_Chat::settings();
		$o  = Optima_Chat::OPTION;
		$ai = Optima_Chat::ai_connected();
		$n  = self::unanswered();
		Optima_Admin_UI::open( __( 'Chat assistant', 'optima-chat' ), __( 'A chat in the corner of the site. It answers the common questions, books calls and takes change requests, and saves every visitor question to Chat questions.', 'optima-chat' ), __( 'Chat', 'optima-chat' ) );
		settings_errors( $o );
		?>
		<div class="sh-grid sh-grid-3">
			<section class="sh-box sh-check is-<?php echo $s['enabled'] ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'On the site', 'optima-chat' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo $s['enabled'] ? esc_html__( 'Showing', 'optima-chat' ) : esc_html__( 'Hidden', 'optima-chat' ); ?></p>
				<p class="sh-note"><?php echo $s['enabled'] ? esc_html__( 'Visitors see the chat button on every page.', 'optima-chat' ) : esc_html__( 'Fill in the details below, then tick Show the chat on the site.', 'optima-chat' ); ?></p>
			</section>
			<section class="sh-box sh-check is-<?php echo $ai ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'Answers', 'optima-chat' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo $ai ? esc_html__( 'AI connected', 'optima-chat' ) : esc_html__( 'Collecting questions', 'optima-chat' ); ?></p>
				<p class="sh-note"><?php echo $ai ? esc_html__( 'Free-text questions get an AI answer. If the AI server is down, questions are collected instead.', 'optima-chat' ) : esc_html__( 'Common questions are answered right away. Anything else is saved with the visitor\'s email for you to answer.', 'optima-chat' ); ?></p>
			</section>
			<section class="sh-box sh-check is-<?php echo $n ? 'warn' : 'good'; ?>">
				<p class="sh-label"><?php esc_html_e( 'Waiting for an answer', 'optima-chat' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( (string) $n ); ?></p>
				<p class="sh-note"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Optima_Chat::CPT ) ); ?>"><?php esc_html_e( 'Open Chat questions', 'optima-chat' ); ?> &rsaquo;</a></p>
			</section>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( 'optima_chat_group' ); ?>
			<div class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'How it looks', 'optima-chat' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::row( __( 'Show the chat on the site', 'optima-chat' ), '<label><input type="checkbox" name="' . esc_attr( $o ) . '[enabled]" value="1" ' . checked( $s['enabled'], true, false ) . '> ' . esc_html__( 'Show the chat button on every page', 'optima-chat' ) . '</label>' );
					self::row( __( 'Team names', 'optima-chat' ), self::text( 'team', $s['team'], 'the Optima team' ), __( 'Who answers. Used in the button ("Ask our team") and the replies. Blank: "our team".', 'optima-chat' ) );
					self::row( __( 'Assistant name', 'optima-chat' ), self::text( 'name', $s['name'], __( 'Site name + assistant', 'optima-chat' ) ) );
					self::row( __( 'Button text', 'optima-chat' ), self::text( 'launcher', $s['launcher'], __( 'Ask + team names', 'optima-chat' ) ) );
					self::row( __( 'Photos', 'optima-chat' ), self::text( 'photo_1', $s['photo_1'], 'https://', 'url' ) . '<br>' . self::text( 'photo_2', $s['photo_2'], 'https://', 'url' ), __( 'Optional. Paste the Media links of the people who answer. They show on the button and in the chat.', 'optima-chat' ) );
					self::row( __( 'Greeting card', 'optima-chat' ), '<label><input type="checkbox" name="' . esc_attr( $o ) . '[teaser]" value="1" ' . checked( $s['teaser'], true, false ) . '> ' . esc_html__( 'Show a small greeting after a few seconds, once per visit', 'optima-chat' ) . '</label><br>' . self::text( 'teaser_by', $s['teaser_by'], 'Your team at Optima' ), __( 'Who the greeting is from.', 'optima-chat' ) );
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
				<h2 class="sh-title"><?php esc_html_e( 'AI connection', 'optima-chat' ); ?></h2>
				<p><?php esc_html_e( 'Optima\'s AI server answers free-text questions. The AI company\'s key stays on that server and never touches this site. Leave blank to keep collecting questions.', 'optima-chat' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					self::row( __( 'AI server address', 'optima-chat' ), self::text( 'ai_url', $s['ai_url'], 'https://', 'url' ) );
					if ( defined( 'OPTIMA_CHAT_AI_KEY' ) ) {
						self::row( __( 'Connection key', 'optima-chat' ), esc_html__( 'Set in wp-config.php (OPTIMA_CHAT_AI_KEY).', 'optima-chat' ) );
					} else {
						self::row(
							__( 'Connection key', 'optima-chat' ),
							self::text( 'ai_key', '', '' !== $s['ai_key'] ? __( 'Saved. Leave blank to keep it.', 'optima-chat' ) : '', 'password' )
							. ( '' !== $s['ai_key'] ? '<br><label><input type="checkbox" name="' . esc_attr( $o ) . '[ai_key_clear]" value="1"> ' . esc_html__( 'Remove the saved key', 'optima-chat' ) . '</label>' : '' ),
							__( 'The key Optima\'s server gives this site. Or define OPTIMA_CHAT_AI_KEY in wp-config.php.', 'optima-chat' )
						);
					}
					?>
				</table>
			</div>
			<?php submit_button(); ?>
		</form>
		<?php
		Optima_Admin_UI::close();
	}
}
