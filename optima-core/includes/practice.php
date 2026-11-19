<?php
/**
 * A post's (or page's) primary practice area: one slug from the shared
 * practice list, stored as post meta `_optima_primary_practice`.
 *
 * The theme reads it to list related guides on a practice page (a WP_Query on
 * this meta key), and a practice page can name its own practice here when its
 * page slug is not the practice slug. The list is the shared one every law
 * site uses (law-site blueprint, taxonomy): a slug is added there first, never
 * invented on one site. A firm picks only the slugs it really takes.
 *
 * The editor shows a small "Primary practice" box in the sidebar of posts and
 * pages (a classic meta box, like the Optima SEO box). The meta is also
 * registered for the REST API, so the block editor and theme queries can use it.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Practice {

	const META = '_optima_primary_practice';

	/** The shared practice list: slug => name on the site. */
	const SLUGS = array(
		'car-accidents'        => 'Car accidents',
		'truck-accidents'      => 'Truck accidents',
		'motorcycle-accidents' => 'Motorcycle accidents',
		'pedestrian-accidents' => 'Pedestrian accidents',
		'bicycle-accidents'    => 'Bicycle accidents',
		'rideshare-accidents'  => 'Uber and Lyft accidents',
		'bus-accidents'        => 'Bus accidents',
		'wrongful-death'       => 'Wrongful death',
		'brain-injury'         => 'Brain injury',
		'spinal-cord-injury'   => 'Spinal cord injury',
		'burn-injury'          => 'Burn injury',
		'catastrophic-injury'  => 'Catastrophic injury',
		'premises-liability'   => 'Slip and fall and unsafe property',
		'dog-bites'            => 'Dog bites',
		'medical-malpractice'  => 'Medical malpractice',
		'nursing-home-abuse'   => 'Nursing home abuse',
		'product-liability'    => 'Defective products',
		'workers-compensation' => "Workers' compensation",
		'maritime-injury'      => 'Maritime and boating injury',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 6 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
	}

	/** @return array<string,string> slug => name. Filter `optima_core_practices` to add a slug the shared list gained. */
	public static function all() {
		return (array) apply_filters( 'optima_core_practices', self::SLUGS );
	}

	/** Post types that carry the field. Filter `optima_core_practice_post_types`. */
	public static function post_types() {
		return array_values( array_filter( (array) apply_filters( 'optima_core_practice_post_types', array( 'post', 'page' ) ), 'post_type_exists' ) );
	}

	/** A known slug, or ''. */
	public static function clean( $value ) {
		$value = sanitize_key( (string) $value );
		return array_key_exists( $value, self::all() ) ? $value : '';
	}

	public static function register_meta() {
		foreach ( self::post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::META,
				array(
					'single'            => true,
					'type'              => 'string',
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'clean' ),
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', (int) $post_id );
					},
				)
			);
		}
	}

	public static function add() {
		foreach ( self::post_types() as $post_type ) {
			add_meta_box( 'optima-practice', 'Primary practice', array( __CLASS__, 'render' ), $post_type, 'side', 'default' );
		}
	}

	public static function render( $post ) {
		wp_nonce_field( 'optima_practice_save', 'optima_practice_nonce' );
		$value = (string) get_post_meta( $post->ID, self::META, true );
		echo '<p style="margin-top:0"><label for="optima-practice-field" class="screen-reader-text">Primary practice</label>';
		echo '<select id="optima-practice-field" name="optima_practice" style="width:100%">';
		echo '<option value="">Not set</option>';
		if ( '' !== $value && ! array_key_exists( $value, self::all() ) ) {
			echo '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( $value ) . '</option>';
		}
		foreach ( self::all() as $slug => $name ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $value, $slug, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="description">The case type this is mainly about. A practice page lists it under related guides; on a practice page it names the page\'s own practice.</p>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['optima_practice_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['optima_practice_nonce'] ) ), 'optima_practice_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}
		$value = isset( $_POST['optima_practice'] ) ? self::clean( wp_unslash( $_POST['optima_practice'] ) ) : '';
		// A slug saved earlier that left the list stays until someone picks another.
		$saved = (string) get_post_meta( $post_id, self::META, true );
		if ( '' === $value && isset( $_POST['optima_practice'] ) && $saved === sanitize_key( wp_unslash( $_POST['optima_practice'] ) ) && '' !== $saved ) {
			return;
		}
		if ( '' === $value ) {
			delete_post_meta( $post_id, self::META );
		} else {
			update_post_meta( $post_id, self::META, $value );
		}
	}
}

if ( ! function_exists( 'optima_primary_practice' ) ) {
	/**
	 * A post's or page's primary practice slug, or ''.
	 *
	 * @param int $post_id Defaults to the current post.
	 */
	function optima_primary_practice( $post_id = 0 ) {
		$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
		return $post_id ? (string) get_post_meta( $post_id, Optima_Core_Practice::META, true ) : '';
	}
}
