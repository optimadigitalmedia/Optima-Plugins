<?php
/**
 * WP-CLI: `wp optima sync status`, `wp optima sync run [--dry-run]`,
 * `wp optima sync create [<item>...] [--all] [--restore] [--dry-run]` and
 * `wp optima sync undo`. `run` syncs only the safe rows; anything edited in
 * WordPress is left for a person on the Approved copy screen. `create` adds
 * the missing pages and forms it is named (or all of them), the same way the
 * screen's "Create the ticked items" does.
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
			$note = '';
			if ( ! empty( $row['blocked'] ) ) {
				$note = $row['blocked'];
			} elseif ( ! empty( $row['trashed'] ) ) {
				$note = 'In the trash: use create --restore';
			} elseif ( Optima_Sync::OFF === $row['state'] ) {
				$note = 'Gravity Forms is not active';
			} elseif ( Optima_Sync::MISSING === $row['state'] && in_array( $row['kind'], array( 'create', 'form' ), true ) ) {
				$note = 'Can be created: wp optima sync create ' . $row['id'];
			} elseif ( ! empty( $row['covered'] ) ) {
				$note = 'Filled when its page is created';
			}
			$rows[] = array( 'item' => $row['id'], 'state' => $row['state'], 'note' => $note );
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'item', 'state', 'note' ) );
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

	/**
	 * Create the missing pages and Gravity Forms the theme can add.
	 *
	 * ## OPTIONS
	 *
	 * [<item>...]
	 * : Item ids from `status`, e.g. page/services/injury#create or form/contact.
	 *
	 * [--all]
	 * : Every page and form the theme can add.
	 *
	 * [--restore]
	 * : Bring the named pages back from the trash instead (a page whose address is in the trash is never created twice).
	 *
	 * [--dry-run]
	 * : List what would be added, change nothing.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Flags.
	 */
	public function create( $args, $assoc ) {
		$restore = ! empty( $assoc['restore'] );
		$plan    = Optima_Sync::plan();
		$ids     = array();
		foreach ( $plan['rows'] as $row ) {
			if ( Optima_Sync::MISSING !== $row['state'] || ! in_array( $row['kind'], array( 'create', 'form' ), true ) ) {
				continue;
			}
			if ( ! empty( $assoc['all'] ) || in_array( $row['id'], $args, true ) ) {
				if ( ! empty( $row['blocked'] ) ) {
					WP_CLI::warning( $row['id'] . ': ' . $row['blocked'] );
					continue;
				}
				if ( 'create' === $row['kind'] && ( ! empty( $row['trashed'] ) xor $restore ) ) {
					WP_CLI::warning( $row['id'] . ( $restore ? ': not in the trash, use create without --restore.' : ': a page with this address is in the trash. Use --restore, or empty it from the trash first.' ) );
					continue;
				}
				if ( 'form' === $row['kind'] && $restore ) {
					continue;
				}
				$ids[] = $row['id'];
				WP_CLI::log( ( ! empty( $assoc['dry-run'] ) ? 'Would add: ' : 'Adding: ' ) . $row['id'] . ( ! empty( $row['author_note'] ) ? ' (' . $row['author_note'] . ')' : '' ) );
			}
		}
		if ( ! $ids ) {
			WP_CLI::success( 'Nothing to add.' );
			return;
		}
		if ( ! empty( $assoc['dry-run'] ) ) {
			WP_CLI::success( count( $ids ) . ' item(s) would be added.' );
			return;
		}
		if ( ! get_current_user_id() ) {
			// Pages are written as an administrator, so their markup is saved as the theme ships it.
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) );
			if ( $admins ) {
				wp_set_current_user( (int) $admins[0] );
			}
		}
		$result = Optima_Sync::apply( '', $restore ? 'restore' : 'create', $ids, 'cli' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		foreach ( $result['notes'] as $note ) {
			WP_CLI::warning( $note );
		}
		WP_CLI::success( $result['written'] . ' item(s) ' . ( $restore ? 'restored.' : 'added. Undo with: wp optima sync undo' ) );
	}

	/**
	 * Undo the last sync (the same as the screen's "Undo the last sync").
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Flags.
	 */
	public function undo( $args, $assoc ) {
		$result = Optima_Sync::undo( 'cli' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		foreach ( $result['skipped'] as $label ) {
			WP_CLI::warning( 'Left alone, changed since: ' . $label );
		}
		WP_CLI::success( $result['restored'] . ' item(s) put back.' );
	}
}
