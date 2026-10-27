<?php
/**
 * Optima Core — the one control renderer.
 *
 * Every field UI in the platform (type meta boxes, page fieldsets, settings
 * groups) draws its inputs through here, so an editor sees the same control for
 * the same kind of value everywhere, and a new control is added once.
 *
 * Controls: text | textarea | richtext | number | url | select | checkbox | image
 *
 *   image    stores an attachment ID. Renders a preview, "Choose image" opens
 *            the media library (upload or pick), "Remove" clears it.
 *   richtext a small visual editor (bold, italic, links, lists). Stored as
 *            filtered HTML (wp_kses_post).
 *   checkbox stores "1" or nothing.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Controls {

	/** @var string[] */
	const CONTROLS = array( 'text', 'textarea', 'richtext', 'number', 'url', 'select', 'checkbox', 'image' );

	/**
	 * Normalize a field definition to a predictable shape.
	 *
	 * @param string $key   Field key.
	 * @param array  $field Raw definition.
	 * @return array
	 */
	public static function normalize( $key, $field ) {
		$field = wp_parse_args(
			(array) $field,
			array(
				'label'   => ucfirst( str_replace( '_', ' ', $key ) ),
				'control' => 'text',
				'options' => array(),
				'help'    => '',
				'rows'    => 3,
				'width'   => 'full', // full | half. Layout hint for the meta box grid.
			)
		);
		if ( ! in_array( $field['control'], self::CONTROLS, true ) ) {
			$field['control'] = 'text';
		}
		return $field;
	}

	/**
	 * Strict sanitizer used on every save path.
	 *
	 * @param string $control Control name.
	 * @param mixed  $value   Raw submitted value.
	 * @param array  $options For select: allowed value => label.
	 * @return string
	 */
	public static function sanitize( $control, $value, array $options = array() ) {
		switch ( $control ) {
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'richtext':
				return trim( wp_kses_post( (string) $value ) );
			case 'url':
				return esc_url_raw( $value );
			case 'number':
				return ( '' === $value || ! is_numeric( $value ) ) ? '' : (string) ( 0 + $value );
			case 'select':
				return array_key_exists( $value, $options ) ? $value : '';
			case 'checkbox':
				return empty( $value ) ? '' : '1';
			case 'image':
				$id = absint( $value );
				return $id ? (string) $id : '';
			case 'text':
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Sanitizer callable for register_post_meta / register_setting.
	 *
	 * @param string $control Control name.
	 * @return callable
	 */
	public static function sanitizer_for( $control ) {
		return function ( $value ) use ( $control ) {
			return self::sanitize( $control, $value );
		};
	}

	/**
	 * Print one labelled control.
	 *
	 * @param string $name  Input name attribute.
	 * @param string $id    Input id attribute.
	 * @param array  $field Normalized field definition.
	 * @param string $value Current value.
	 */
	public static function render( $name, $id, array $field, $value ) {
		$value = (string) $value;
		echo '<div class="optima-control optima-control--' . esc_attr( $field['control'] ) . ' optima-control--' . esc_attr( $field['width'] ) . '">';
		if ( 'checkbox' !== $field['control'] ) {
			echo '<label class="optima-control__label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
		}

		switch ( $field['control'] ) {
			case 'textarea':
				printf(
					'<textarea id="%s" name="%s" rows="%d" class="large-text">%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $field['rows'],
					esc_textarea( $value )
				);
				break;

			case 'richtext':
				wp_editor(
					$value,
					$id,
					array(
						'textarea_name' => $name,
						'textarea_rows' => max( 4, (int) $field['rows'] ),
						'media_buttons' => false,
						'teeny'         => true,
						'quicktags'     => false,
						'tinymce'       => array(
							'toolbar1' => 'bold,italic,link,unlink,bullist,numlist,undo,redo',
							'toolbar2' => '',
						),
					)
				);
				break;

			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				echo '<option value="">' . esc_html__( '— Select —', 'optima-core' ) . '</option>';
				foreach ( $field['options'] as $opt_val => $opt_label ) {
					echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( $value, (string) $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
				}
				echo '</select>';
				break;

			case 'checkbox':
				printf(
					'<label class="optima-control__check" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( '1', $value, false ),
					esc_html( $field['label'] )
				);
				break;

			case 'number':
				printf(
					'<input type="number" step="any" id="%s" name="%s" value="%s" class="small-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'url':
				printf(
					'<input type="url" id="%s" name="%s" value="%s" class="large-text" placeholder="https://" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			case 'image':
				self::render_image( $name, $id, $value );
				break;

			case 'text':
			default:
				printf(
					'<input type="text" id="%s" name="%s" value="%s" class="large-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
		}

		if ( '' !== $field['help'] ) {
			echo '<p class="optima-control__help description">' . esc_html( $field['help'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The media-library picker. A hidden input carries the attachment ID; the
	 * JS in assets/admin.js opens wp.media and swaps the preview.
	 */
	private static function render_image( $name, $id, $value ) {
		$att_id  = absint( $value );
		$preview = $att_id ? wp_get_attachment_image( $att_id, 'medium', false, array( 'class' => 'optima-image__img' ) ) : '';
		$file    = $att_id ? wp_basename( get_attached_file( $att_id ) ) : '';

		echo '<div class="optima-image' . ( $att_id ? ' has-image' : '' ) . '" data-optima-image>';
		printf( '<input type="hidden" id="%s" name="%s" value="%s" data-optima-image-id />', esc_attr( $id ), esc_attr( $name ), esc_attr( $att_id ? $att_id : '' ) );
		echo '<div class="optima-image__preview" data-optima-image-preview>' . $preview . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image escapes.
		echo '<div class="optima-image__actions">';
		echo '<button type="button" class="button button-secondary" data-optima-image-choose>' . esc_html__( 'Choose image', 'optima-core' ) . '</button> ';
		echo '<button type="button" class="button-link optima-image__remove" data-optima-image-remove>' . esc_html__( 'Remove', 'optima-core' ) . '</button>';
		echo '<span class="optima-image__file" data-optima-image-file>' . esc_html( $file ) . '</span>';
		echo '</div></div>';
	}

	/**
	 * Enqueue the admin CSS/JS (media picker, section layout) on screens that
	 * draw controls: post edit screens and the Optima admin pages.
	 */
	public static function enqueue_admin( $hook ) {
		$screens = array( 'post.php', 'post-new.php', 'toplevel_page_optima', 'optima_page_optima' );
		$on_optima = ( false !== strpos( (string) $hook, 'page_optima' ) ) || ( isset( $_GET['page'] ) && 'optima' === sanitize_key( wp_unslash( $_GET['page'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $hook, $screens, true ) && ! $on_optima ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'optima-core-admin', plugins_url( 'assets/admin.css', OPTIMA_CORE_FILE ), array(), OPTIMA_CORE_VERSION );
		wp_enqueue_script( 'optima-core-admin', plugins_url( 'assets/admin.js', OPTIMA_CORE_FILE ), array( 'jquery', 'media-editor' ), OPTIMA_CORE_VERSION, true );
		wp_localize_script(
			'optima-core-admin',
			'optimaCoreAdmin',
			array(
				'chooseTitle'  => __( 'Choose an image', 'optima-core' ),
				'chooseButton' => __( 'Use this image', 'optima-core' ),
			)
		);
	}
}
