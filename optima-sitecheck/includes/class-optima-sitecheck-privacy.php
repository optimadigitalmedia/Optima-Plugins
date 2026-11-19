<?php
/**
 * How long saved site checks are kept, and WordPress's own privacy tools
 * (Tools > Export Personal Data and Erase Personal Data).
 *
 * Retention: a daily job deletes saved checks older than the Site check
 * screen's setting (90 days by default, the suite's form-entry policy). A site
 * can keep them, but only with a reason written on the screen. The job runs in
 * batches and is cleared when the plugin is switched off.
 *
 * Privacy requests are matched by the email the visitor gave for the report.
 * Each saved check is one visitor's, so erasing deletes it whole.
 *
 * @package Optima_Sitecheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Sitecheck_Privacy {

	const GROUP = 'optima-sitecheck';
	const PER   = 50;
	const PURGE = 'optima_sitecheck_purge';
	const BATCH = 100;
	const MAX   = 5; // Batches per run; more waiting means another run ten minutes later.

	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
		add_action( self::PURGE, array( __CLASS__, 'purge' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/** One daily run, set up again after the plugin is switched back on. */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::PURGE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE );
		}
	}

	/** Plugin switched off: no clean-up, finishing or speed jobs left behind. */
	public static function deactivate(): void {
		wp_unschedule_hook( self::PURGE );
		Optima_Sitecheck::deactivate();
	}

	/** Delete saved checks older than the setting. Returns how many went. */
	public static function purge(): int {
		$days = Optima_Sitecheck::retention_days();
		if ( $days < 1 ) {
			return 0;
		}
		$before  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted = 0;
		for ( $i = 0; $i < self::MAX; $i++ ) {
			$ids = get_posts(
				array(
					'post_type'        => Optima_Sitecheck::CPT,
					'post_status'      => array( 'any', 'trash' ),
					'posts_per_page'   => self::BATCH,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'date_query'       => array(
						array(
							'column' => 'post_date_gmt',
							'before' => $before,
						),
					),
				)
			);
			foreach ( (array) $ids as $id ) {
				$deleted += wp_delete_post( (int) $id, true ) ? 1 : 0;
			}
			if ( count( (array) $ids ) < self::BATCH ) {
				return $deleted;
			}
		}
		// A long backlog: carry on shortly instead of holding this run open.
		wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, self::PURGE );
		return $deleted;
	}

	/** Saved checks left with this email, a page at a time. @return int[] */
	public static function ids( string $email, int $page = 1 ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => Optima_Sitecheck::CPT,
				'post_status'    => array( 'any', 'trash' ), // Trashed checks still hold the visitor's details.
				'posts_per_page' => self::PER,
				'paged'          => max( 1, $page ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_key'       => '_ssc_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- privacy requests are rare.
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( $id ) => strtolower( (string) get_post_meta( $id, '_ssc_email', true ) ) === $email ) );
	}

	public static function exporter( array $exporters ): array {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'Site checks', 'optima-sitecheck' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email, int $page = 1 ): array {
		$ids  = self::ids( $email, $page );
		$data = array();
		foreach ( $ids as $id ) {
			$post   = get_post( $id );
			$fields = array(
				array(
					'name'  => __( 'Requested', 'optima-sitecheck' ),
					'value' => $post ? (string) $post->post_date : '',
				),
				array(
					'name'  => __( 'Name', 'optima-sitecheck' ),
					'value' => (string) get_post_meta( $id, '_ssc_name', true ),
				),
				array(
					'name'  => __( 'Email', 'optima-sitecheck' ),
					'value' => (string) get_post_meta( $id, '_ssc_email', true ),
				),
				array(
					'name'  => __( 'Website checked', 'optima-sitecheck' ),
					'value' => (string) get_post_meta( $id, '_ssc_url', true ),
				),
				array(
					'name'  => __( 'Page the check was run from', 'optima-sitecheck' ),
					'value' => (string) get_post_meta( $id, '_ssc_page', true ),
				),
				array(
					'name'  => __( 'Report', 'optima-sitecheck' ),
					'value' => self::report_text( $id ),
				),
			);
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Site checks', 'optima-sitecheck' ),
				'item_id'     => 'site-check-' . $id,
				'data'        => array_values( array_filter( $fields, static fn( $f ) => '' !== $f['value'] ) ),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PER,
		);
	}

	public static function eraser( array $erasers ): array {
		$erasers[ self::GROUP ] = array(
			'eraser_friendly_name' => __( 'Site checks', 'optima-sitecheck' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/** Deleted checks drop out of the list, so every pass reads the first page. */
	public static function erase_data( string $email, int $page = 1 ): array {
		$ids     = self::ids( $email, 1 );
		$removed = false;
		foreach ( $ids as $id ) {
			$removed = (bool) wp_delete_post( $id, true ) || $removed;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PER,
		);
	}

	/** The report as plain lines: "Speed: pass. The main content shows in ..." */
	private static function report_text( int $id ): string {
		$rows = Optima_Sitecheck::rows( $id );
		if ( null === $rows ) {
			return '';
		}
		$lines = array();
		foreach ( $rows as $r ) {
			$lines[] = (string) ( $r['title'] ?? '' ) . ': ' . (string) ( $r['status'] ?? '' ) . '. ' . (string) ( $r['text'] ?? '' );
		}
		return implode( "\n", $lines );
	}
}
