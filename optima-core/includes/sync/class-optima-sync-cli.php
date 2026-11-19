<?php
/**
 * WP-CLI: `wp optima sync status`, `wp optima sync run [--dry-run]`,
 * `wp optima sync create [<item>...] [--all] [--restore] [--dry-run]` and
 * `wp optima sync set-status [<item>...] [--all] [--dry-run]`,
 * `wp optima sync retire [<item>...] [--all] [--redirect] [--dry-run]` and
 * `wp optima sync undo`. `run` syncs only the safe rows; anything edited in
 * WordPress is left for a person on the Approved copy screen. `create` adds
 * the missing pages and forms it is named (or all of them), the same way the
 * screen's "Create the ticked items" does. `set-status` and `retire` are the
 * screen's status and "Pages the theme retires" buttons: `status` lists them,
 * and nothing changes until one of these is run with item ids or --all.
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
			} elseif ( Optima_Sync::EARLY === $row['state'] ) {
				$note = 'Published, the theme says draft: wp optima sync set-status ' . $row['id'];
			} elseif ( Optima_Sync::READY === $row['state'] ) {
				$note = 'Draft, the theme says publish: wp optima sync set-status ' . $row['id'];
			} elseif ( Optima_Sync::RETIRE === $row['state'] ) {
				$note = trim( ( '' !== $row['reason'] ? $row['reason'] . '. ' : '' ) . 'Trash it: wp optima sync retire ' . $row['id'] . ( ! empty( $row['redirect_ok'] ) ? ' --redirect' : '' ) );
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
	 * Give pages the status the theme names (draft or publish).
	 *
	 * Back to draft for a page published early, published for a page the theme
	 * publishes. Never run on its own.
	 *
	 * ## OPTIONS
	 *
	 * [<item>...]
	 * : Item ids from `status`, e.g. page/services/injury#status.
	 *
	 * [--all]
	 * : Every page whose status differs from the theme's.
	 *
	 * [--dry-run]
	 * : List what would change, change nothing.
	 *
	 * @subcommand set-status
	 * @param array $args  Positional.
	 * @param array $assoc Flags.
	 */
	public function set_status( $args, $assoc ) {
		$ids = $this->pick( $args, $assoc, 'status', array( Optima_Sync::EARLY, Optima_Sync::READY ) );
		$this->go( $ids, $assoc, 'status', $ids, 'status change(s)' );
	}

	/**
	 * Move the pages the theme retires to the trash (never deleted).
	 *
	 * Undo brings them back. Refused for the front page, the posts page, the
	 * privacy page, any page the theme lists as live, and a page with pages
	 * under it that the theme does not retire.
	 *
	 * ## OPTIONS
	 *
	 * [<item>...]
	 * : Item ids from `status`, e.g. retire/page/services/old-page.
	 *
	 * [--all]
	 * : Every page the theme retires that is still on the site.
	 *
	 * [--redirect]
	 * : Also add each page's 301 to the address the theme names (needs Optima Redirects).
	 *
	 * [--dry-run]
	 * : List what would change, change nothing.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Flags.
	 */
	public function retire( $args, $assoc ) {
		$ids   = $this->pick( $args, $assoc, 'retire', array( Optima_Sync::RETIRE ) );
		$extra = array();
		if ( ! empty( $assoc['redirect'] ) ) {
			foreach ( $ids as $id ) {
				$extra[] = 'redirect:' . $id;
			}
		}
		$this->go( $ids, $assoc, 'retire', array_merge( $ids, $extra ), 'page(s) to the trash' );
	}

	/** The ids a status or retire command acts on, with a line for each. */
	private function pick( $args, $assoc, $kind, array $states ) {
		$ids = array();
		foreach ( Optima_Sync::plan()['rows'] as $row ) {
			if ( $kind !== $row['kind'] || ! in_array( $row['state'], $states, true ) ) {
				continue;
			}
			if ( empty( $assoc['all'] ) && ! in_array( $row['id'], $args, true ) ) {
				continue;
			}
			if ( ! empty( $row['blocked'] ) ) {
				WP_CLI::warning( $row['id'] . ': ' . $row['blocked'] );
				continue;
			}
			$what = 'retire' === $kind ? 'trash' . ( ! empty( $assoc['redirect'] ) && ! empty( $row['redirect_ok'] ) ? ' and 301 to ' . $row['redirect'] : '' ) : 'change the status from ' . $row['raw'] . ' to ' . $row['theme'];
			WP_CLI::log( ( ! empty( $assoc['dry-run'] ) ? 'Would ' : 'Will ' ) . $what . ': ' . $row['id'] );
			if ( 'retire' === $kind && ! empty( $assoc['redirect'] ) && '' !== $row['redirect'] && empty( $row['redirect_ok'] ) ) {
				WP_CLI::warning( $row['redirect_note'] );
			}
			$ids[] = $row['id'];
		}
		return $ids;
	}

	/** Run a status or retire change and report it. */
	private function go( array $ids, $assoc, $mode, array $send, $noun ) {
		if ( ! $ids ) {
			WP_CLI::success( 'Nothing to change.' );
			return;
		}
		if ( ! empty( $assoc['dry-run'] ) ) {
			WP_CLI::success( 'Dry run: ' . count( $ids ) . ' ' . $noun . ', nothing changed.' );
			return;
		}
		$result = Optima_Sync::apply( '', $mode, $send, 'cli' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		foreach ( $result['notes'] as $note ) {
			WP_CLI::warning( $note );
		}
		WP_CLI::success( 'Done: ' . $result['written'] . ' ' . $noun . '. Undo with: wp optima sync undo' );
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
