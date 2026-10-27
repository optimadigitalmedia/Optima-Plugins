<?php
/**
 * A single, dependency-free meta box per content type (no ACF). Renders one
 * input per registered field and saves them on save_post. The block editor
 * reads the same meta over REST; this box is the simple, always-works UI.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Meta_Box {

	public static function add() {
		foreach ( Optima_Core_Registry::all() as $slug => $type ) {
			if ( empty( $type['fields'] ) ) {
				continue;
			}
			add_meta_box(
				'optima-core-fields',
				$type['singular'] . ' Details',
				array( __CLASS__, 'render' ),
				$slug,
				'normal',
				'high'
			);
		}
	}

	public static function render( $post ) {
		$type = Optima_Core_Registry::get( $post->post_type );
		if ( ! $type ) {
			return;
		}
		wp_nonce_field( 'optima_core_save_meta', 'optima_core_meta_nonce' );

		echo '<div class="optima-fieldset"><div class="optima-section__body" style="border:0;padding:0;">';
		foreach ( $type['fields'] as $key => $field ) {
			$value = get_post_meta( $post->ID, optima_core_meta_key( $key ), true );
			Optima_Core_Controls::render( 'optima_meta[' . $key . ']', 'optima-field-' . $key, $field, $value );
		}
		echo '</div></div>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['optima_core_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['optima_core_meta_nonce'] ) ), 'optima_core_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$type = Optima_Core_Registry::get( $post->post_type );
		if ( ! $type || empty( $type['fields'] ) ) {
			return;
		}

		$submitted = isset( $_POST['optima_meta'] ) ? (array) wp_unslash( $_POST['optima_meta'] ) : array();

		foreach ( $type['fields'] as $key => $field ) {
			$raw   = isset( $submitted[ $key ] ) ? $submitted[ $key ] : '';
			$clean = Optima_Core_Meta::sanitize( $field['control'], $raw, $field['options'] );

			if ( '' === $clean ) {
				delete_post_meta( $post_id, optima_core_meta_key( $key ) );
			} else {
				update_post_meta( $post_id, optima_core_meta_key( $key ), $clean );
			}
		}
	}
}
