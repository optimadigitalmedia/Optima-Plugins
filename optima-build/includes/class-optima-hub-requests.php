<?php
/**
 * Request a change: a short form in the dashboard that emails the team and
 * keeps a list on the site, so the client can see each request and where it
 * stands (Received, In progress, Done).
 *
 * The email goes to sean@optimadigitalmedia.com unless the site sets its own:
 *   define( 'OPTIMA_HUB_REQUEST_EMAIL', 'someone@example.com' );
 * Partner-side sites must set this, so nothing routes to a Optima address.
 *
 * A "Request a change" link in the admin bar opens the form with the page the
 * person was looking at already filled in.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Requests {

	const TYPE        = 'optima_hub_request';
	const ACTION      = 'optima_hub_request';
	const META_PAGE   = '_optima_hub_page';
	const META_WHEN   = '_optima_hub_when';
	const META_STATUS = '_optima_hub_status';
	const META_FILE   = '_optima_hub_file';

	const STATUSES = array(
		'received' => 'Received',
		'progress' => 'In progress',
		'done'     => 'Done',
	);

	const WHEN = array(
		'whenever' => 'Whenever you can',
		'week'     => 'This week',
		'soon'     => 'As soon as possible',
	);

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'bar_style' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'bar_style' ) );
	}

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array( 'name' => __( 'Change requests', 'optima-build' ) ),
				'public'       => false,
				'show_ui'      => false,
				'rewrite'      => false,
				'supports'     => array( 'title', 'editor', 'author' ),
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap' => true,
			)
		);
	}

	public static function recipient(): string {
		$to = defined( 'OPTIMA_HUB_REQUEST_EMAIL' ) ? (string) OPTIMA_HUB_REQUEST_EMAIL : 'sean@optimadigitalmedia.com';
		return (string) apply_filters( 'optima_hub_request_recipient', $to );
	}

	/**
	 * A Optima menu in the toolbar: the arrow and "Optima" (or "Site Build" on
	 * unbranded sites), opening Features, with Site health and Request a
	 * change under it.
	 */
	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$branded = Optima_Hub_Admin::branded();
		$label   = $branded ? __( 'Optima', 'optima-build' ) : __( 'Site Build', 'optima-build' );
		$title   = ( $branded ? '<span class="optima-bar-mark" aria-hidden="true"></span>' : '' ) . '<span class="optima-bar-label">' . esc_html( $label ) . '</span>';
		$here    = is_admin() ? '' : home_url( add_query_arg( array() ) );
		$bar->add_node(
			array(
				'id'    => 'optima-hub',
				'title' => $title,
				'href'  => Optima_Hub_Admin::url( 'features' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'optima-hub',
				'id'     => 'optima-hub-features',
				'title'  => __( 'Features', 'optima-build' ),
				'href'   => Optima_Hub_Admin::url( 'features' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'optima-hub',
				'id'     => 'optima-hub-health',
				'title'  => __( 'Site health', 'optima-build' ),
				'href'   => Optima_Hub_Admin::url( 'health' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'optima-hub',
				'id'     => 'optima-hub-request',
				'title'  => __( 'Request a change', 'optima-build' ),
				'href'   => Optima_Hub_Admin::url( 'requests', $here ? array( 'from' => rawurlencode( $here ) ) : array() ),
			)
		);
	}

	/** The azure arrow beside "Optima" in the toolbar. */
	public static function bar_style(): void {
		if ( ! is_admin_bar_showing() || ! Optima_Hub_Admin::branded() ) {
			return;
		}
		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar #wp-admin-bar-optima-hub>.ab-item{display:flex;align-items:center;gap:6px}'
			. '#wpadminbar .optima-bar-mark{display:inline-block;width:11px;height:14px;background:#1272C4;clip-path:polygon(35% 0,100% 0,100% 65%,76% 41%,17% 100%,0 83%,59% 24%)}'
		);
	}

	/** @return WP_Post[] */
	public static function all(): array {
		return get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	public static function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'optima-build' ), 403 );
		}
		$do = sanitize_key( wp_unslash( (string) ( $_POST['do'] ?? 'new' ) ) );

		if ( 'status' === $do ) {
			if ( ! Optima_Hub_Access::is_operator() ) {
				wp_die( esc_html__( 'Only the people who look after this site can change a request\'s status.', 'optima-build' ), 403 );
			}
			$id     = (int) ( $_POST['request'] ?? 0 );
			$status = sanitize_key( wp_unslash( (string) ( $_POST['status'] ?? '' ) ) );
			if ( self::TYPE === get_post_type( $id ) && isset( self::STATUSES[ $status ] ) ) {
				update_post_meta( $id, self::META_STATUS, $status );
			}
			self::back( __( 'Status updated.', 'optima-build' ) );
		}

		$what = trim( sanitize_textarea_field( wp_unslash( (string) ( $_POST['what'] ?? '' ) ) ) );
		$page = esc_url_raw( trim( wp_unslash( (string) ( $_POST['page'] ?? '' ) ) ) );
		$when = sanitize_key( wp_unslash( (string) ( $_POST['when'] ?? 'whenever' ) ) );
		$when = isset( self::WHEN[ $when ] ) ? $when : 'whenever';
		if ( '' === $what ) {
			self::back( __( 'Tell us what you need changed, then send it again.', 'optima-build' ), 'error' );
		}

		$user  = wp_get_current_user();
		$title = wp_trim_words( $what, 10, '…' );
		$id    = wp_insert_post(
			array(
				'post_type'    => self::TYPE,
				'post_status'  => 'private',
				'post_title'   => $title,
				'post_content' => $what,
				'post_author'  => $user->ID,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			self::back( __( 'The request could not be saved. Please email us instead.', 'optima-build' ), 'error' );
		}
		update_post_meta( $id, self::META_PAGE, $page );
		update_post_meta( $id, self::META_WHEN, $when );
		update_post_meta( $id, self::META_STATUS, 'received' );

		$attachments = array();
		if ( ! empty( $_FILES['file']['name'] ) && current_user_can( 'upload_files' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$file_id = media_handle_upload( 'file', 0 );
			if ( ! is_wp_error( $file_id ) ) {
				update_post_meta( $id, self::META_FILE, (int) $file_id );
				$attachments[] = get_attached_file( $file_id );
			}
		}

		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array(
			/* translators: 1: person, 2: site */
			sprintf( __( 'From: %1$s on %2$s', 'optima-build' ), $user->display_name . ' <' . $user->user_email . '>', $site ),
			/* translators: %s: timing */
			sprintf( __( 'When: %s', 'optima-build' ), self::WHEN[ $when ] ),
			$page ? sprintf( /* translators: %s: page URL */ __( 'Page: %s', 'optima-build' ), $page ) : __( 'Page: not given', 'optima-build' ),
			'',
			$what,
			'',
			/* translators: %s: admin URL */
			sprintf( __( 'Request list: %s', 'optima-build' ), Optima_Hub_Admin::url( 'requests' ) ),
		);
		wp_mail(
			self::recipient(),
			/* translators: 1: site, 2: short title */
			sprintf( __( '[%1$s] Change request: %2$s', 'optima-build' ), $site, $title ),
			implode( "\n", $lines ),
			array( 'Reply-To: ' . $user->display_name . ' <' . $user->user_email . '>' ),
			$attachments
		);
		self::back( __( 'Sent. We will reply by email, and you can follow it in the list below.', 'optima-build' ) );
	}

	private static function back( string $message, string $type = 'success' ): void {
		set_transient( 'optima_hub_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Optima_Hub_Admin::url( 'requests' ) );
		exit;
	}
}
