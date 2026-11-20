<?php
/**
 * What Law has waiting on a person, in the shape Optima Build's Inbox uses.
 *
 * Optima → Inbox (Optima_Hub_Inbox::kinds()) reads each feature's own store
 * when that feature's class exists; nothing is stored here. Optima Build adds
 * these rows with:
 *
 *   if ( class_exists( 'Optima_Law_Inbox' ) ) {
 *       $kinds = array_merge( $kinds, Optima_Law_Inbox::kinds() );
 *   }
 *
 * Each kind: label, who it waits on ("you" or "optima"), count, the screen
 * that handles it, the link words, the capability that opens it, and up to
 * SHOW rows (title, note, url). Only kinds with something waiting are listed.
 *
 * @package Optima_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Law_Inbox {

	/** Rows shown per kind; the count always covers every item (the same as the Inbox). */
	const SHOW = 5;

	/** @var array|null Built once per request. */
	private static $kinds = null;

	/** Forget the cached rows (tests, or after a save on the same request). */
	public static function reset(): void {
		self::$kinds = null;
	}

	/** Rows for a list of post IDs, each linking to its edit screen. */
	private static function post_rows( array $ids, string $note ): array {
		$rows = array();
		foreach ( array_slice( $ids, 0, self::SHOW ) as $id ) {
			$rows[] = array(
				'title' => (string) get_the_title( (int) $id ),
				'note'  => $note,
				'url'   => (string) get_edit_post_link( (int) $id, 'raw' ),
			);
		}
		return $rows;
	}

	/**
	 * @return array<string, array{label:string, who:string, count:int, url:string, link:string, cap:string, rows:array}>
	 */
	public static function kinds(): array {
		if ( null !== self::$kinds ) {
			return self::$kinds;
		}
		$kinds = array();

		// Disclaimer lines on the site that the firm has not approved word for word.
		$lines = Optima_Law_Disclaimers::waiting();
		if ( $lines ) {
			$rows = array();
			foreach ( array_slice( $lines, 0, self::SHOW ) as $line ) {
				$rows[] = array(
					'title' => (string) Optima_Law_Disclaimers::lines()[ $line ]['label'],
					'note'  => Optima_Law_Disclaimers::marked( Optima_Law_Disclaimers::text( $line ) ) ? __( 'Waits for counsel\'s wording', 'optima-law' ) : ( 'changed' === Optima_Law_Disclaimers::status( $line ) ? __( 'Changed since approval', 'optima-law' ) : __( 'Needs the firm\'s approval', 'optima-law' ) ),
					'url'   => Optima_Law_Admin::url() . '#optima-law-' . $line,
				);
			}
			$kinds['law-disclaimers'] = array(
				'label' => __( 'Disclaimers to approve', 'optima-law' ),
				'who'   => 'you',
				'count' => count( $lines ),
				'url'   => Optima_Law_Admin::url(),
				'link'  => __( 'Open Disclaimers', 'optima-law' ),
				'cap'   => 'manage_options',
				'rows'  => $rows,
			);
		}

		// Pages and posts held for the firm's approval, and live ones edited since.
		$q = Optima_Law_Approvals::queues();
		if ( $q['waiting'] ) {
			$kinds['law-approvals'] = array(
				'label' => __( 'Waiting for the firm\'s approval', 'optima-law' ),
				'who'   => 'you',
				'count' => count( $q['waiting'] ),
				'url'   => Optima_Law_Approvals_Admin::url(),
				'link'  => __( 'Open Firm approvals', 'optima-law' ),
				'cap'   => 'manage_options',
				'rows'  => self::post_rows( $q['waiting'], __( 'Pending review until an attorney approves it', 'optima-law' ) ),
			);
		}
		if ( $q['edited'] ) {
			$kinds['law-edited'] = array(
				'label' => __( 'Edited since approval', 'optima-law' ),
				'who'   => 'you',
				'count' => count( $q['edited'] ),
				'url'   => Optima_Law_Approvals_Admin::url(),
				'link'  => __( 'Open Firm approvals', 'optima-law' ),
				'cap'   => 'manage_options',
				'rows'  => self::post_rows( $q['edited'], __( 'Live; the firm has not seen the change', 'optima-law' ) ),
			);
		}

		// Pages due for an attorney's review (Team's Reviewed by record, on a schedule).
		$due = Optima_Law_Reviews::due();
		if ( $due && Optima_Law_Reviews::attorneys() ) {
			$months = Optima_Law_Reviews::settings()['months'];
			$kinds['law-reviews'] = array(
				'label' => __( 'Pages due for an attorney\'s review', 'optima-law' ),
				'who'   => 'you',
				'count' => count( $due ),
				'url'   => Optima_Law_Reviews_Admin::url(),
				'link'  => __( 'Open Attorney sign-offs', 'optima-law' ),
				'cap'   => 'edit_pages',
				/* translators: %d: months */
				'rows'  => self::post_rows( $due, sprintf( __( 'Not reviewed in the last %d months', 'optima-law' ), $months ) ),
			);
		}

		// Deleting form entries was just turned on: the start date, so anyone can star what has to stay.
		$starts = Optima_Law_Entries::pending_start( time() );
		if ( $starts ) {
			$days = Optima_Law_Entries::settings()['days'];
			$kinds['law-entries'] = array(
				'label' => __( 'Form entries start deleting', 'optima-law' ),
				'who'   => 'optima',
				'count' => 1,
				'url'   => Optima_Law_Entries_Admin::url(),
				'link'  => __( 'Open Form entries', 'optima-law' ),
				'cap'   => 'manage_options',
				'rows'  => array(
					array(
						/* translators: 1: days, 2: date */
						'title' => sprintf( __( 'Entries older than %1$d days are deleted from %2$s', 'optima-law' ), $days, wp_date( (string) get_option( 'date_format' ), $starts ) ),
						'note'  => __( 'Star any old entry that has to stay, in Gravity Forms.', 'optima-law' ),
						'url'   => Optima_Law_Entries_Admin::url(),
					),
				),
			);
		}

		self::$kinds = $kinds;
		return $kinds;
	}

	/** Everything Law has waiting, all kinds together. */
	public static function count(): int {
		return (int) array_sum( array_column( self::kinds(), 'count' ) );
	}
}
