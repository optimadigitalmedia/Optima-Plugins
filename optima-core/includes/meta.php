<?php
/**
 * Registers post meta for every field in the registry (so fields are available
 * over REST and to the block editor), and provides the sanitizer map shared
 * with the meta box.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Meta {

	public static function register_all() {
		foreach ( Optima_Core_Registry::all() as $slug => $type ) {
			foreach ( $type['fields'] as $key => $field ) {
				register_post_meta(
					$slug,
					optima_core_meta_key( $key ),
					array(
						'single'            => true,
						'type'              => 'string',
						'show_in_rest'      => true,
						'sanitize_callback' => Optima_Core_Controls::sanitizer_for( $field['control'] ),
						'auth_callback'     => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);
			}
		}
	}

	/**
	 * Strict, options-aware sanitize used by the meta box on save.
	 *
	 * @param string $control text|textarea|richtext|number|url|select|checkbox|image.
	 * @param mixed  $value   Raw submitted value.
	 * @param array  $options For select: allowed value => label.
	 * @return string
	 */
	public static function sanitize( $control, $value, array $options = array() ) {
		return Optima_Core_Controls::sanitize( $control, $value, $options );
	}
}
