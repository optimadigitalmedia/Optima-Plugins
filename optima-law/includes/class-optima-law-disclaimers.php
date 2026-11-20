<?php
/**
 * The firm's disclaimer lines: the wording, where each line shows, and the
 * firm's approval of the exact wording.
 *
 * Starting wording comes per state (North Carolina, Virginia, Florida, DC).
 * Louisiana and South Carolina have no state wording written here: every line
 * starts with the general wording marked [VERIFY: counsel], the same marker
 * Optima Case results and Optima Reviews use, and a line still carrying the
 * marker can never be approved. Starting wording is a starting point the firm
 * edits and approves, never a statement that any wording satisfies a bar
 * rule. A line always prints something: a blank setting falls back to the
 * starting wording, so a page never loses its disclaimer. An approval belongs
 * to one exact wording; changing the words after approval marks the line
 * "Changed since approval" until the firm approves again. Every approval is
 * kept as the firm's advertising record.
 *
 * @package Optima_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Law_Disclaimers {

	const OPTION = 'optima_law_disclaimers';
	const LOG    = 'optima_law_approval_log';

	/** The marker a placeholder carries until counsel replaces it. */
	const VERIFY = '[VERIFY: counsel]';

	/** States with their own starting wording. */
	public static function states(): array {
		return array(
			'nc'    => __( 'North Carolina', 'optima-law' ),
			'va'    => __( 'Virginia', 'optima-law' ),
			'fl'    => __( 'Florida', 'optima-law' ),
			'dc'    => __( 'Washington, DC', 'optima-law' ),
			'la'    => __( 'Louisiana (wording waits for counsel)', 'optima-law' ),
			'sc'    => __( 'South Carolina (wording waits for counsel)', 'optima-law' ),
			'other' => __( 'Another state', 'optima-law' ),
		);
	}

	/** States whose starting wording is a placeholder for counsel, not state wording. */
	public static function counsel_states(): array {
		return array( 'la', 'sc' );
	}

	/** Places a line can show. */
	public static function places(): array {
		return array(
			'footer'  => __( 'The footer of every page', 'optima-law' ),
			'forms'   => __( 'Under every form', 'optima-law' ),
			'results' => __( 'Next to case results', 'optima-law' ),
			'reviews' => __( 'Under reviews', 'optima-law' ),
		);
	}

	/**
	 * The lines, in the order the screen shows them.
	 *
	 * @return array<string,array{label:string,help:string,places:string[]}>
	 */
	public static function lines(): array {
		return array(
			'relationship' => array(
				'label'  => __( 'Not legal advice', 'optima-law' ),
				'help'   => __( 'Says the website is general information and that contacting the firm does not make it the visitor\'s lawyer.', 'optima-law' ),
				'places' => array( 'footer' ),
			),
			'forms'        => array(
				'label'  => __( 'Before someone sends a form', 'optima-law' ),
				'help'   => __( 'Asks people not to send confidential details through the website.', 'optima-law' ),
				'places' => array( 'forms' ),
			),
			'advertising'  => array(
				'label'  => __( 'Attorney advertising', 'optima-law' ),
				'help'   => __( 'A short label some firms show on their website. The firm decides whether it shows.', 'optima-law' ),
				'places' => array( 'footer' ),
			),
			'results'      => array(
				'label'  => __( 'Prior results', 'optima-law' ),
				'help'   => __( 'Printed next to every case result. Replaces the wording on the Case results screen while this plugin is on.', 'optima-law' ),
				'places' => array( 'results' ),
			),
			'reviews'      => array(
				'label'  => __( 'Reviews & testimonials', 'optima-law' ),
				'help'   => __( 'Printed under the reviews on the site. Replaces the wording on the Reviews screen while this plugin is on.', 'optima-law' ),
				'places' => array( 'reviews' ),
			),
		);
	}

	/** Starting wording for a line in a state. */
	public static function starting( string $line, string $state ): string {
		$results = array(
			'nc'    => __( 'Prior results do not guarantee a similar outcome. Every case is different and depends on its own facts.', 'optima-law' ),
			'va'    => __( 'Prior results do not guarantee a similar outcome. Each case depends on its own facts and circumstances.', 'optima-law' ),
			'fl'    => __( 'Prior results do not guarantee a similar outcome. Every case is different, and the result in any case depends on its own facts and the law that applies.', 'optima-law' ),
			'dc'    => __( 'Prior results do not guarantee a similar outcome. Every case depends on its own facts.', 'optima-law' ),
			'other' => __( 'Prior results do not guarantee a similar outcome.', 'optima-law' ),
		);
		$text    = array(
			'relationship' => __( 'The information on this website is general information, not legal advice. Contacting us does not create an attorney-client relationship.', 'optima-law' ),
			'forms'        => __( 'Please don\'t include confidential details in this form. Sending it doesn\'t make us your lawyers.', 'optima-law' ),
			'advertising'  => __( 'Attorney advertising.', 'optima-law' ),
			'results'      => $results[ $state ] ?? $results['other'],
			'reviews'      => __( 'These reviews reflect the experiences of individual clients. Every case is different, and prior results do not guarantee a similar outcome.', 'optima-law' ),
		);
		$out     = $text[ $line ] ?? '';
		// No state wording is written for these states: the general line, visibly marked for counsel.
		if ( '' !== $out && in_array( $state, self::counsel_states(), true ) ) {
			$out = self::VERIFY . ' ' . $out;
		}
		/** Filter the starting wording for a line and state. */
		return (string) apply_filters( 'optima_law_starting_wording', $out, $line, $state );
	}

	/** True when a wording still carries the counsel marker. */
	public static function marked( string $text ): bool {
		return false !== strpos( $text, self::VERIFY );
	}

	/**
	 * The state to start from before one is saved here: the one saved under
	 * Case results when it is one of ours, else North Carolina.
	 */
	public static function default_state(): string {
		$results = get_option( 'optima_results', array() );
		$state   = is_array( $results ) ? (string) ( $results['state'] ?? '' ) : '';
		return isset( self::states()[ $state ] ) ? $state : 'nc';
	}

	/** Everything saved, with defaults filled in. */
	public static function settings(): array {
		$s     = get_option( self::OPTION, array() );
		$s     = is_array( $s ) ? $s : array();
		$state = isset( $s['state'], self::states()[ $s['state'] ] ) ? (string) $s['state'] : self::default_state();
		$lines = array();
		foreach ( self::lines() as $key => $def ) {
			$saved         = isset( $s['lines'][ $key ] ) && is_array( $s['lines'][ $key ] ) ? $s['lines'][ $key ] : null;
			$lines[ $key ] = array(
				'text'   => (string) ( $saved['text'] ?? '' ),
				'places' => null === $saved ? $def['places'] : array_values( array_intersect( (array) ( $saved['places'] ?? array() ), array_keys( self::places() ) ) ),
			);
		}
		return array(
			'state'     => $state,
			'lines'     => $lines,
			'approvals' => isset( $s['approvals'] ) && is_array( $s['approvals'] ) ? $s['approvals'] : array(),
		);
	}

	/** The wording a line prints. Never empty. */
	public static function text( string $line ): string {
		$s     = self::settings();
		$start = self::starting( $line, $s['state'] );
		$text  = trim( (string) ( $s['lines'][ $line ]['text'] ?? '' ) );
		$text  = '' !== $text ? $text : $start;
		/** Filter the wording a line prints. An empty return falls back to the starting wording. */
		$out = trim( (string) apply_filters( 'optima_law_disclaimer_text', $text, $line ) );
		return '' !== $out ? $out : $start;
	}

	/** Where a line shows. */
	public static function places_for( string $line ): array {
		return self::settings()['lines'][ $line ]['places'] ?? array();
	}

	/** Lines that show in a place, in screen order. */
	public static function in_place( string $place ): array {
		$out = array();
		foreach ( array_keys( self::lines() ) as $key ) {
			if ( in_array( $place, self::places_for( $key ), true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/** A fingerprint of one exact wording, ignoring extra spaces. */
	public static function fingerprint( string $text ): string {
		return md5( trim( (string) preg_replace( '/\s+/', ' ', $text ) ) );
	}

	/**
	 * Where a line stands with the firm. Wording still marked for counsel
	 * always waits, whatever was approved before.
	 *
	 * @return string approved | changed | waiting
	 */
	public static function status( string $line ): string {
		$text     = self::text( $line );
		$approval = self::settings()['approvals'][ $line ] ?? null;
		if ( self::marked( $text ) || ! is_array( $approval ) || empty( $approval['hash'] ) ) {
			return 'waiting';
		}
		return hash_equals( (string) $approval['hash'], self::fingerprint( $text ) ) ? 'approved' : 'changed';
	}

	/** The approval on file for a line, or an empty array. */
	public static function approval( string $line ): array {
		$a = self::settings()['approvals'][ $line ] ?? array();
		return is_array( $a ) ? $a : array();
	}

	/** Lines that show somewhere and still need the firm's approval of their current wording. */
	public static function waiting(): array {
		$out = array();
		foreach ( array_keys( self::lines() ) as $key ) {
			if ( self::places_for( $key ) && 'approved' !== self::status( $key ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/** Lines that show somewhere and still print the counsel marker. */
	public static function marked_lines(): array {
		$out = array();
		foreach ( array_keys( self::lines() ) as $key ) {
			if ( self::places_for( $key ) && self::marked( self::text( $key ) ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Record the firm's approval of a line's current wording, and keep it in the log.
	 * Refused without a name, and for wording that still carries the counsel marker.
	 *
	 * @param string $line    Line key.
	 * @param string $by      The approver's name, as the firm gives it.
	 * @param int    $user_id Who recorded it in WordPress.
	 */
	public static function approve( string $line, string $by, int $user_id ): bool {
		$by = trim( $by );
		if ( '' === $by || ! isset( self::lines()[ $line ] ) ) {
			return false;
		}
		$text = self::text( $line );
		if ( self::marked( $text ) ) {
			return false;
		}
		$now = time();
		$s   = get_option( self::OPTION, array() );
		$s   = is_array( $s ) ? $s : array();

		$s['approvals'][ $line ] = array(
			'hash' => self::fingerprint( $text ),
			'by'   => $by,
			'at'   => $now,
			'user' => $user_id,
		);
		update_option( self::OPTION, $s );

		$log   = get_option( self::LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'line'   => $line,
			'text'   => $text,
			'state'  => self::settings()['state'],
			'places' => self::places_for( $line ),
			'by'     => $by,
			'at'     => $now,
			'user'   => $user_id,
		);
		update_option( self::LOG, $log, false );
		/** A disclaimer line was approved. */
		do_action( 'optima_law_disclaimer_approved', $line, $by, $text );
		return true;
	}

	/** Every approval on record, newest first. */
	public static function log(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}
}
