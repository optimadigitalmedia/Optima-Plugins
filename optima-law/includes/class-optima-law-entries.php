<?php
/**
 * Form entries that delete themselves: each night, Gravity Forms entries
 * older than the firm's limit (90 days by default) are deleted from the
 * website, with the files uploaded with them.
 *
 * OFF by default on Optima sites. The decision whether law-firm sites delete
 * form entries is still open (QUESTIONS Q10 in the internal repo); until it is
 * made, nothing is deleted anywhere unless an operator turns it on under
 * Optima → Build → Form entries.
 *
 * What stays safe:
 * - The first delete waits FIRST_WAIT days after deleting is turned on, so
 *   switching it on never removes old entries the same night. The Inbox shows
 *   the start date until then. Turning it off and on again starts the wait over.
 * - Starred entries are kept. Starring an entry in Gravity Forms is how a
 *   person says "keep this one".
 * - A form that emails no one is skipped: its entries are the only copy.
 * - A form with Gravity Forms' own retention setting is left to Gravity Forms.
 * - Forms the firm chose to keep are skipped.
 *
 * Assumes GFAPI::get_forms() returns the active forms, each with 'id',
 * 'title', 'notifications' (keyed arrays with 'isActive', 'event', 'to') and
 * optionally 'personalData' => [ 'retention' => [ 'policy' => retain|trash|delete ] ].
 * Assumes GFAPI::get_entries( $form_id, $search, null, $paging ) returns entry
 * arrays with 'id', 'date_created' (UTC, Y-m-d H:i:s) and 'is_starred', and
 * GFAPI::delete_entry( $id ) deletes the entry and its uploaded files.
 *
 * @package Optima_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Law_Entries {

	const OPTION     = 'optima_law_entries';
	const STATE      = 'optima_law_entries_state'; // on_since, last_run.
	const LOG        = 'optima_law_entries_log';
	const HOOK       = 'optima_law_entries_nightly';
	const FIRST_WAIT = 14;                          // Days' notice before the first delete.
	const DAYS       = array( 30, 60, 90, 180, 365 );
	const PER_RUN    = 500;                         // Entries per form per night, so a backlog never times out.

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'nightly' ) );
	}

	/* ---- Settings ----------------------------------------------------- */

	/** @return array{on:bool,days:int,keep:int[]} Off until an operator turns it on. */
	public static function settings(): array {
		$s    = get_option( self::OPTION, array() );
		$s    = is_array( $s ) ? $s : array();
		$days = (int) ( $s['days'] ?? 90 );
		return array(
			'on'   => ! empty( $s['on'] ),
			'days' => in_array( $days, self::DAYS, true ) ? $days : 90,
			'keep' => array_values( array_filter( array_map( 'intval', (array) ( $s['keep'] ?? array() ) ) ) ),
		);
	}

	/** Days entries are kept, or 0 when entries are never deleted. The privacy policy reads this. */
	public static function days(): int {
		$s = self::settings();
		return $s['on'] ? $s['days'] : 0;
	}

	/** @return array{on_since?:int,last_run?:int} */
	private static function state(): array {
		$state = get_option( self::STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * When deleting was turned on. Set the first time it is asked for while
	 * deleting is on (the save sets it; a run sets it when the setting came
	 * some other way), so the notice always starts from that moment.
	 */
	public static function on_since( int $now ): int {
		$state = self::state();
		if ( empty( $state['on_since'] ) ) {
			$state['on_since'] = $now;
			update_option( self::STATE, $state, false );
		}
		return (int) $state['on_since'];
	}

	/** Start the notice over (deleting turned off, or turned on again). */
	public static function reset_notice(): void {
		$state = self::state();
		unset( $state['on_since'] );
		update_option( self::STATE, $state, false );
	}

	/** The first night entries can be deleted. */
	public static function starts( int $on_since ): int {
		return $on_since + self::FIRST_WAIT * DAY_IN_SECONDS;
	}

	public static function cutoff( int $now, int $days ): int {
		return $now - $days * DAY_IN_SECONDS;
	}

	/** While deleting is on and the notice is running, the first delete date; else 0. */
	public static function pending_start( int $now ): int {
		if ( ! self::settings()['on'] || ! self::gravity() ) {
			return 0;
		}
		$starts = self::starts( self::on_since( $now ) );
		return $now < $starts ? $starts : 0;
	}

	/* ---- Which forms and which entries -------------------------------- */

	/** True when the form sends at least one email on submission. */
	public static function emails_someone( array $form ): bool {
		foreach ( (array) ( $form['notifications'] ?? array() ) as $n ) {
			if ( ! is_array( $n ) ) {
				continue;
			}
			$active = ! array_key_exists( 'isActive', $n ) || (bool) $n['isActive'];
			$event  = (string) ( $n['event'] ?? 'form_submission' );
			$to     = trim( (string) ( $n['to'] ?? '' ) );
			if ( $active && ( '' === $event || 'form_submission' === $event ) && '' !== $to ) {
				return true;
			}
		}
		return false;
	}

	/** Gravity Forms' own retention setting, when it deletes or trashes entries. */
	public static function own_policy( array $form ): bool {
		$policy = (string) ( $form['personalData']['retention']['policy'] ?? 'retain' );
		return in_array( $policy, array( 'trash', 'delete' ), true );
	}

	/**
	 * What happens to each form's entries.
	 *
	 * @return array<int,array{title:string,does:string}> does: delete | kept | no-email | gravity
	 */
	public static function plan( array $forms, array $settings ): array {
		$out = array();
		foreach ( $forms as $form ) {
			$id = (int) ( $form['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			if ( in_array( $id, $settings['keep'], true ) ) {
				$does = 'kept';
			} elseif ( self::own_policy( $form ) ) {
				$does = 'gravity';
			} elseif ( ! self::emails_someone( $form ) ) {
				$does = 'no-email';
			} else {
				$does = 'delete';
			}
			$out[ $id ] = array(
				'title' => (string) ( $form['title'] ?? '' ),
				'does'  => $does,
			);
		}
		return $out;
	}

	/** True when this entry is past the limit and not starred. */
	public static function expired( array $entry, int $cutoff ): bool {
		if ( ! empty( $entry['is_starred'] ) ) {
			return false;
		}
		$at = strtotime( (string) ( $entry['date_created'] ?? '' ) . ' UTC' );
		return false !== $at && $at > 0 && $at < $cutoff;
	}

	/* ---- The nightly run ---------------------------------------------- */

	/** Scheduled only while deleting is on; off clears the schedule. */
	public static function schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		$next = wp_next_scheduled( self::HOOK );
		if ( self::settings()['on'] && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		} elseif ( ! self::settings()['on'] && $next ) {
			self::unschedule();
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function gravity(): bool {
		return class_exists( 'GFAPI' ) && method_exists( 'GFAPI', 'get_forms' );
	}

	/** @return array<int,array> Active forms, keyed by id. */
	public static function forms(): array {
		if ( ! self::gravity() ) {
			return array();
		}
		$out = array();
		foreach ( (array) GFAPI::get_forms() as $form ) {
			if ( is_array( $form ) && ! empty( $form['id'] ) ) {
				$full                     = method_exists( 'GFAPI', 'get_form' ) ? GFAPI::get_form( (int) $form['id'] ) : false;
				$out[ (int) $form['id'] ] = is_array( $full ) ? $full : $form;
			}
		}
		return $out;
	}

	/** The scheduled job. WordPress passes an empty string to a cron action with no arguments, so run() is not hooked directly. */
	public static function nightly(): void {
		self::run();
	}

	/**
	 * Delete the entries past the limit. Returns how many went, by form.
	 *
	 * @return array<int,int>
	 */
	public static function run( int $now = 0 ): array {
		$now      = $now ? $now : time();
		$settings = self::settings();
		if ( ! $settings['on'] || ! self::gravity() ) {
			return array();
		}
		if ( $now < self::starts( self::on_since( $now ) ) ) {
			return array();
		}
		$cutoff  = self::cutoff( $now, $settings['days'] );
		$deleted = array();
		foreach ( self::plan( self::forms(), $settings ) as $form_id => $row ) {
			if ( 'delete' === $row['does'] ) {
				$deleted[ $form_id ] = self::clear_form( $form_id, $cutoff );
			}
		}
		$state             = self::state();
		$state['last_run'] = $now;
		update_option( self::STATE, $state, false );
		self::remember( $now, $deleted, $settings['days'] );
		return $deleted;
	}

	/** Delete one form's old entries in every status (active, spam, trash), a page at a time. */
	public static function clear_form( int $form_id, int $cutoff ): int {
		$count = 0;
		$end   = gmdate( 'Y-m-d H:i:s', $cutoff );
		foreach ( array( 'active', 'spam', 'trash' ) as $status ) {
			$skip = 0;
			while ( $count < self::PER_RUN ) {
				$search  = array(
					'status'        => $status,
					'end_date'      => $end,
					'field_filters' => array( array( 'key' => 'is_starred', 'value' => 0 ) ),
				);
				$entries = GFAPI::get_entries( $form_id, $search, array( 'key' => 'date_created', 'direction' => 'ASC' ), array( 'offset' => $skip, 'page_size' => 50 ) );
				if ( ! is_array( $entries ) || ! $entries ) {
					break;
				}
				$gone = 0;
				foreach ( $entries as $entry ) {
					if ( $count >= self::PER_RUN ) {
						break 2;
					}
					// Checked again here: the search's date reading and the star filter are Gravity Forms' own.
					if ( self::expired( (array) $entry, $cutoff ) && true === GFAPI::delete_entry( (int) $entry['id'] ) ) {
						++$gone;
						++$count;
					} else {
						++$skip; // Left in place, so the next page starts after it.
					}
				}
				if ( ! $gone ) {
					break;
				}
			}
		}
		return $count;
	}

	/** Keep a short record of each night that deleted something. Counts only, never names. */
	private static function remember( int $now, array $deleted, int $days ): void {
		$total = array_sum( $deleted );
		if ( ! $total ) {
			return;
		}
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'at'    => $now,
				'total' => $total,
				'forms' => $deleted,
				'days'  => $days,
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 30 ), false );
	}

	public static function log(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function last_run(): int {
		return (int) ( self::state()['last_run'] ?? 0 );
	}
}

if ( ! function_exists( 'optima_law_entry_days' ) ) {
	/** Days form entries are kept on the website, or 0 when they are never deleted (Optima Core's privacy policy reads this). */
	function optima_law_entry_days(): int {
		return Optima_Law_Entries::days();
	}
}
