<?php
/**
 * WP-CLI: `wp optima sync status` and `wp optima sync run [--dry-run]`.
 * The command line runs only the safe rows; anything edited in WordPress is
 * left for a person on the Site Sync screen.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Sync_CLI {

	/**
	 * Show every manifest item and its state.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Include rows that are already current.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Flags.
	 */
	public function status( $args, $assoc ) {
		$plan = Optima_Sync::plan();
		if ( ! $plan['manifest'] ) {
			WP_CLI::log( 'This theme ships no optima-sync.json. Nothing to sync.' );
			return;
		}
		foreach ( $plan['errors'] as $error ) {
			WP_CLI::warning( $error );
		}
		$rows = array();
		foreach ( $plan['rows'] as $row ) {
			if ( Optima_Sync::CURRENT === $row['state'] && empty( $assoc['all'] ) ) {
				continue;
			}
			$rows[] = array( 'item' => $row['id'], 'state' => $row['state'] );
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'item', 'state' ) );
		} else {
			WP_CLI::success( 'Everything is in sync.' );
		}
	}

	/**
	 * Sync every row nobody has edited in WordPress.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would change, change nothing.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Flags.
	 */
	public function run( $args, $assoc ) {
		$plan  = Optima_Sync::plan();
		$ready = array_filter(
			$plan['rows'],
			static function ( $r ) {
				return Optima_Sync::UPDATE === $r['state'];
			}
		);
		foreach ( $ready as $row ) {
			WP_CLI::log( ( ! empty( $assoc['dry-run'] ) ? 'Would sync: ' : 'Syncing: ' ) . $row['id'] );
		}
		if ( ! empty( $assoc['dry-run'] ) ) {
			WP_CLI::success( count( $ready ) . ' item(s) would sync.' );
			return;
		}
		$result = Optima_Sync::apply( '', 'safe', array(), 'auto' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( $result['written'] . ' item(s) synced.' );
	}
}
