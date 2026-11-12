<?php
/**
 * How long saved conversations are kept, and WordPress's own privacy tools
 * (Tools > Export Personal Data and Erase Personal Data).
 *
 * Retention: a daily job deletes saved conversations older than the Chat
 * screen's setting (90 days by default, the suite's form-entry policy). A site
 * can keep them, but only with a reason written on the screen. The job runs in
 * batches and is cleared when the plugin is switched off.
 *
 * Privacy requests are matched by the email the visitor typed in the chat.
 * Each saved conversation is one visitor's, so erasing deletes it whole. A
 * visitor who left only a phone number cannot be matched by email; delete that
 * conversation by hand from Chat questions.
 *
 * @package Optima_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Chat_Privacy {

	const GROUP = 'optima-chat';
	const PER   = 50;
	const PURGE = 'optima_chat_purge';
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

	/** Plugin switched off: no job left behind. */
	public static function deactivate(): void {
		wp_unschedule_hook( self::PURGE );
	}

	/** Delete saved conversations older than the setting. Returns how many went. */
	public static function purge(): int {
		$days = Optima_Chat::retention_days();
		if ( $days < 1 ) {
			return 0;
		}
		$before  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted = 0;
		for ( $i = 0; $i < self::MAX; $i++ ) {
			$ids = get_posts(
				array(
					'post_type'        => Optima_Chat::CPT,
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

	/** Saved conversations left with this email, a page at a time. @return int[] */
	public static function ids( string $email, int $page = 1 ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => Optima_Chat::CPT,
				'post_status'    => array( 'any', 'trash' ), // Trashed conversations still hold the visitor's details.
				'posts_per_page' => self::PER,
				'paged'          => max( 1, $page ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_key'       => '_optima_chat_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- privacy requests are rare.
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( $id ) => strtolower( (string) get_post_meta( $id, '_optima_chat_email', true ) ) === $email ) );
	}

	public static function exporter( array $exporters ): array {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'Chat questions', 'optima-chat' ),
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
					'name'  => __( 'Sent', 'optima-chat' ),
					'value' => $post ? (string) $post->post_date : '',
				),
				array(
					'name'  => __( 'Name', 'optima-chat' ),
					'value' => (string) get_post_meta( $id, '_optima_chat_name', true ),
				),
				array(
					'name'  => __( 'Email', 'optima-chat' ),
					'value' => (string) get_post_meta( $id, '_optima_chat_email', true ),
				),
				array(
					'name'  => __( 'Phone', 'optima-chat' ),
					'value' => (string) get_post_meta( $id, '_optima_chat_phone', true ),
				),
				array(
					'name'  => __( 'Page', 'optima-chat' ),
					'value' => (string) get_post_meta( $id, '_optima_chat_page', true ),
				),
				array(
					'name'  => __( 'Shown at the start of the chat', 'optima-chat' ),
					'value' => (string) get_post_meta( $id, '_optima_chat_disclosure', true ),
				),
				array(
					'name'  => __( 'Conversation', 'optima-chat' ),
					'value' => $post ? (string) $post->post_content : '',
				),
			);
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Chat questions', 'optima-chat' ),
				'item_id'     => 'chat-' . $id,
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
			'eraser_friendly_name' => __( 'Chat questions', 'optima-chat' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/** Deleted conversations drop out of the list, so every pass reads the first page. */
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
}
