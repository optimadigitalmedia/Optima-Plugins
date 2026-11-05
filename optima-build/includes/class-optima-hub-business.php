<?php
/**
 * Your business -> Details on a site without Optima Core (the agency's own
 * site): the business facts form, so Your business always opens with Details
 * (dashboard audit, Chat). Same address (admin.php?page=optima), same option
 * (optima_business) and same field names as Optima Core's Details tab, so
 * turning Core on later finds every value where it expects it. With Core on,
 * this class does nothing: Core's Details is the one place (rule 45).
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Business {

	const PAGE   = 'optima';
	const OPTION = 'optima_business';
	const ACTION = 'optima_hub_business';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 15 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Only when Optima Core is not running: Core owns Details whenever it is on. */
	private static function active(): bool {
		return ! class_exists( 'Optima_Core_Business' );
	}

	/** Field => label, in the groups Core's Details uses. */
	public static function groups(): array {
		return array(
			__( 'Name', 'optima-build' )    => array( 'name' => __( 'Business name', 'optima-build' ) ),
			__( 'Contact', 'optima-build' ) => array(
				'phone' => __( 'Phone', 'optima-build' ),
				'email' => __( 'Email', 'optima-build' ),
			),
			__( 'Address', 'optima-build' ) => array(
				'street'  => __( 'Street address', 'optima-build' ),
				'city'    => __( 'City', 'optima-build' ),
				'region'  => __( 'State', 'optima-build' ),
				'postal'  => __( 'ZIP code', 'optima-build' ),
				'country' => __( 'Country code', 'optima-build' ),
			),
		);
	}

	public static function menu(): void {
		if ( ! self::active() ) {
			return;
		}
		add_submenu_page( Optima_Hub_Admin::parent(), __( 'Details', 'optima-build' ), __( 'Details', 'optima-build' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( self::active() && '_page_' . self::PAGE === substr( $hook, -strlen( '_page_' . self::PAGE ) ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_HUB_URL . 'assets/optima-admin.css' );
		}
	}

	public static function save(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only site admins can change the business details.', 'optima-build' ), 403 );
		}
		$in  = (array) wp_unslash( $_POST['biz'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$all = (array) get_option( self::OPTION, array() ); // Keeps every key this form does not show (hours, schema type...).
		foreach ( self::groups() as $fields ) {
			foreach ( array_keys( $fields ) as $key ) {
				$all[ $key ] = 'email' === $key ? sanitize_email( (string) ( $in[ $key ] ?? '' ) ) : sanitize_text_field( (string) ( $in[ $key ] ?? '' ) );
			}
		}
		update_option( self::OPTION, $all );
		set_transient( 'optima_hub_notice_' . get_current_user_id(), array( 'message' => __( 'Details saved. Every screen that shows them reads them from here.', 'optima-build' ), 'type' => 'success' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$v = Optima_Admin_UI::business();
		Optima_Admin_UI::open( __( 'Details', 'optima-build' ), __( 'The business name, phone, email and address, typed once here. Every other screen shows them read-only with a link back to this one.', 'optima-build' ) );
		$notice = get_transient( 'optima_hub_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'optima_hub_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $notice['message'] ) );
		}
		Optima_Admin_UI::old_facts_box();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sh-box">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( self::groups() as $group => $fields ) {
			echo '<tr><th colspan="2" scope="rowgroup"><span class="sh-label">' . esc_html( $group ) . '</span></th></tr>';
			foreach ( $fields as $key => $label ) {
				printf(
					'<tr><th scope="row"><label for="optima-%1$s">%2$s</label></th><td><input type="%3$s" id="optima-%1$s" name="biz[%1$s]" value="%4$s" class="regular-text"></td></tr>',
					esc_attr( $key ),
					esc_html( $label ),
					'email' === $key ? 'email' : ( 'phone' === $key ? 'tel' : 'text' ),
					esc_attr( $v[ $key ] ?? '' )
				);
			}
		}
		echo '</tbody></table>';
		echo '<p class="sh-note">' . esc_html__( 'Shows on: the footer, the contact page, call buttons, digital cards, the review page and the search listing (schema).', 'optima-build' ) . '</p>';
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'optima-build' ) . '</button></p>';
		echo '</form>';
		Optima_Admin_UI::close();
	}
}
