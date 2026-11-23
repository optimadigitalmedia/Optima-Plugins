<?php
/**
 * Upload pipeline (WordPress glue).
 *
 * @package Optima_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks new uploads and runs the optimization engine on them.
 *
 * Gated by the optimize_uploads setting, which defaults OFF. Until Optima turns
 * it on for a site, the callback returns immediately and nothing is optimized.
 * It also stands down while NitroPack is active (Optima_Optimize::images_paused()).
 * Optimization happens in place; the attachment metadata is returned unchanged.
 */
final class Optima_Optimize_Pipeline {

	/**
	 * Attachment meta key holding the optimization summary.
	 *
	 * @var string
	 */
	const META_KEY = '_optima_optimize';

	/**
	 * MIME types the pipeline processes.
	 *
	 * @var string[]
	 */
	const SUPPORTED = array( 'image/jpeg', 'image/png', 'image/gif' );

	/**
	 * Register the WordPress hook. Safe to call when the flag is OFF.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_generate_metadata' ), 20, 2 );
		add_filter( 'big_image_size_threshold', array( $this, 'filter_big_image_threshold' ) );
	}

	/**
	 * Cap the WordPress "big image" dimension so full-size originals are scaled
	 * down (with correct recorded metadata) when optimization is on. Returns the
	 * unchanged threshold while the flag is OFF.
	 *
	 * @param mixed $threshold Current threshold in pixels.
	 * @return mixed
	 */
	public function filter_big_image_threshold( $threshold ) {
		$settings = get_option( 'optima_optimize_settings', array() );
		if ( empty( $settings['optimize_uploads'] ) || Optima_Optimize::images_paused() ) {
			return $threshold;
		}
		$max = isset( $settings['max_dimension'] ) ? (int) $settings['max_dimension'] : 1920;
		return $max > 0 ? $max : $threshold;
	}

	/**
	 * Optimize a freshly uploaded image and its generated sizes.
	 *
	 * @param mixed $metadata      Attachment metadata from WordPress.
	 * @param mixed $attachment_id Attachment post ID.
	 * @return mixed The metadata, unchanged (optimization happens in place).
	 */
	public function on_generate_metadata( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}

		$settings = get_option( 'optima_optimize_settings', array() );
		// NitroPack optimizes images itself; never run two optimizers on one site.
		if ( empty( $settings['optimize_uploads'] ) || Optima_Optimize::images_paused() ) {
			return $metadata;
		}

		$mime_type = (string) get_post_mime_type( (int) $attachment_id );
		if ( ! in_array( $mime_type, self::SUPPORTED, true ) ) {
			return $metadata;
		}

		if ( get_post_meta( (int) $attachment_id, self::META_KEY, true ) ) {
			return $metadata; // Already processed; keep it idempotent.
		}

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return $metadata;
		}

		$base_dir      = trailingslashit( $uploads['basedir'] );
		$original_path = $base_dir . $metadata['file'];
		$subdir        = trailingslashit( dirname( $metadata['file'] ) );

		$size_paths = array();
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$size_paths[] = $base_dir . $subdir . $size['file'];
				}
			}
		}

		$preset        = isset( $settings['preset'] ) ? (string) $settings['preset'] : 'balanced';
		$generate_webp = ! empty( $settings['generate_webp'] );
		$generate_avif = ! empty( $settings['generate_avif'] );
		$max_setting   = isset( $settings['max_dimension'] ) ? (int) $settings['max_dimension'] : 1920;
		$max_dimension = $max_setting > 0 ? $max_setting : null;

		$originals = new Optima_Optimize_Originals(
			$uploads['basedir'],
			$uploads['basedir'] . '/optima-optimize-originals'
		);
		$processor = new Optima_Optimize_Attachment_Processor(
			Optima_Optimize_Engine_Factory::make(),
			$originals,
			$preset,
			$max_dimension,
			$max_dimension
		);

		$summary = $processor->process( $original_path, $size_paths, $mime_type, $generate_webp, $generate_avif );
		update_post_meta( (int) $attachment_id, self::META_KEY, $summary );

		// An image older than a few minutes had its files rewritten in place (a
		// replaced file, regenerated sizes): ask Optima Core to drop Cloudflare's
		// copies. A fresh upload has nothing cached yet.
		$created = (int) strtotime( (string) get_post_field( 'post_date_gmt', (int) $attachment_id ) . ' UTC' );
		if ( $created && $created < time() - 10 * MINUTE_IN_SECONDS ) {
			do_action( 'optima_core_purge_urls', self::urls( (string) $uploads['baseurl'], $metadata, $generate_webp, $generate_avif ), 'image' );
		}

		return $metadata;
	}

	/**
	 * Every address of one image: the file, its scaled original, each size,
	 * and the WebP and AVIF twins this plugin writes beside them.
	 *
	 * @param string $baseurl  The uploads base URL.
	 * @param array  $metadata Attachment metadata (file, sizes, original_image).
	 * @return string[]
	 */
	public static function urls( string $baseurl, array $metadata, bool $webp, bool $avif ): array {
		if ( empty( $metadata['file'] ) ) {
			return array();
		}
		$base  = rtrim( $baseurl, '/' ) . '/';
		$dir   = dirname( (string) $metadata['file'] );
		$dir   = '.' === $dir ? '' : $dir . '/';
		$files = array( $base . $metadata['file'] );
		if ( ! empty( $metadata['original_image'] ) ) {
			$files[] = $base . $dir . $metadata['original_image'];
		}
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $base . $dir . $size['file'];
			}
		}
		$out = array();
		foreach ( array_unique( $files ) as $url ) {
			$out[] = $url;
			if ( $webp ) {
				$out[] = $url . '.webp';
			}
			if ( $avif ) {
				$out[] = $url . '.avif';
			}
		}
		return $out;
	}
}
