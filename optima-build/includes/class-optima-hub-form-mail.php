<?php
/**
 * Form emails sent: whether Gravity Forms notifications actually went out.
 *
 * A Contact form can say "sent" while its notice never reaches the inbox, and
 * nobody knows until someone checks by hand. "Form emails go to" only shows
 * where the emails are addressed. This notes the result of every
 * notification Gravity Forms tries to send, and Site health reads it.
 *
 * What is kept, in one option that is not autoloaded: the form id, the
 * notification's name, sent or failed, the error with every email address
 * taken out, and the time. The last 50 tries, plus a count per form per day
 * for 30 days. Never the recipient, subject, message, entry or anything a
 * visitor typed.
 *
 * Kind "test" rows are kept for a plain test email; nothing in Optima Build
 * writes one yet (the Email screen keeps its own last test).
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Form_Mail {

	const OPTION = 'optima_hub_form_mail';      // { since, log: [ {k, f, n, ok, e, t} ], tally: { form: { Y-m-d: [sent, failed] } }, last_fail }.
	const CAP    = 50;                         // Tries kept in the log.
	const DAYS   = 30;                         // Days kept in the per-form count.

	/** @var array|null The Gravity Forms notification being sent right now: { f: form id, n: name }. */
	private static $sending = null;

	/** @var string The wp_mail error for that notification, addresses taken out. */
	private static $error = '';

	public static function boot(): void {
		// Late, so a filter that cancels the email has already had its say.
		add_filter( 'gform_pre_send_email', array( __CLASS__, 'before_send' ), 9999, 4 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'mail_failed' ) );
		add_action( 'gform_send_email_failed', array( __CLASS__, 'gf_failed' ), 10, 3 );
		add_action( 'gform_after_email', array( __CLASS__, 'after_send' ), 10, 13 );
	}

	/* ---- Noting each try ----------------------------------------------- */

	/**
	 * Gravity Forms is about to send one notification. Note which one, so a
	 * wp_mail failure during it can be counted against it.
	 *
	 * @param array  $email        to, subject, message, headers, attachments, abort_email.
	 * @param string $format       html or text.
	 * @param array  $notification The notification settings (name, id).
	 * @param array  $entry        The entry. Only its form id is read.
	 */
	public static function before_send( $email, $format = 'html', $notification = array(), $entry = array() ) {
		self::$error   = '';
		self::$sending = null;
		if ( is_array( $email ) && empty( $email['abort_email'] ) ) {
			self::$sending = array(
				'f' => (int) ( is_array( $entry ) ? ( $entry['form_id'] ?? 0 ) : 0 ),
				'n' => (string) ( is_array( $notification ) ? ( $notification['name'] ?? '' ) : '' ),
			);
		}
		return $email;
	}

	/** wp_mail failed. Kept only while a Gravity Forms notification is going out. */
	public static function mail_failed( $error ): void {
		if ( null === self::$sending ) {
			return;
		}
		self::$error = self::clean_error( self::message( $error ) );
	}

	/**
	 * Gravity Forms says the email failed. Used when wp_mail gave no reason.
	 *
	 * @param mixed $error A WP_Error or a message.
	 */
	public static function gf_failed( $error = null, $email = array(), $entry = array() ): void {
		if ( '' === self::$error ) {
			self::$error = self::clean_error( self::message( $error ) );
		}
	}

	/**
	 * Gravity Forms finished one email. Its arguments are $is_success, $to,
	 * $subject, $message, $headers, $attachments, $message_format, $from,
	 * $from_name, $bcc, $reply_to, $entry and $cc. Only the first and the
	 * entry's form id are read; older versions pass fewer, so each is checked.
	 */
	public static function after_send(): void {
		$args    = func_get_args();
		$result  = func_num_args() > 0 ? $args[0] : false;
		$entry   = func_num_args() > 11 && is_array( $args[11] ) ? $args[11] : array();
		$ok      = ! self::is_error( $result ) && (bool) $result;
		$sending = self::$sending ?? array( 'f' => 0, 'n' => '' );
		$form    = $sending['f'] ? (int) $sending['f'] : (int) ( $entry['form_id'] ?? 0 );
		$error   = self::$error;
		if ( ! $ok && '' === $error && self::is_error( $result ) ) {
			$error = self::clean_error( self::message( $result ) );
		}
		self::$sending = null;
		self::$error   = '';
		// The nightly form test reads its own emails and never counts here (Optima_Hub_Form_Test).
		if ( class_exists( 'Optima_Hub_Form_Test' ) && Optima_Hub_Form_Test::note_email( (string) $sending['n'], $ok, $error ) ) {
			return;
		}
		self::record( 'form', $form, (string) $sending['n'], $ok, $error );
	}

	private static function is_error( $thing ): bool {
		return function_exists( 'is_wp_error' ) ? is_wp_error( $thing ) : ( is_object( $thing ) && method_exists( $thing, 'get_error_message' ) );
	}

	/** The message from a WP_Error or a string, or ''. */
	private static function message( $error ): string {
		if ( is_object( $error ) && method_exists( $error, 'get_error_message' ) ) {
			return (string) $error->get_error_message();
		}
		return is_string( $error ) ? $error : '';
	}

	/** An error message with every email address taken out, one line, 160 characters at most. */
	public static function clean_error( string $error ): string {
		// An address, with or without <angle brackets>. A full stop after it stays.
		$error = (string) preg_replace( '/<?[^\s<>"\'(),;:\[\]@]+@[^\s<>"\'(),;:\[\]@.]+(?:\.[^\s<>"\'(),;:\[\]@.]+)*>?/', '[address]', $error );
		$error = (string) preg_replace( '/<[^>]*>/', ' ', $error );
		$error = trim( (string) preg_replace( '/\s+/', ' ', $error ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 160 ) : substr( $error, 0, 160 );
	}

	/** What is kept, with defaults. */
	public static function data(): array {
		$d = get_option( self::OPTION, array() );
		$d = is_array( $d ) ? $d : array();
		return array(
			'since'     => (int) ( $d['since'] ?? 0 ),
			'log'       => is_array( $d['log'] ?? null ) ? array_values( $d['log'] ) : array(),
			'tally'     => is_array( $d['tally'] ?? null ) ? $d['tally'] : array(),
			'last_fail' => is_array( $d['last_fail'] ?? null ) ? $d['last_fail'] : array(),
		);
	}

	/**
	 * Note one try. $kind is "form" (a Gravity Forms notification) or "test"
	 * (a plain test email). Tests go in the log only, never the counts.
	 */
	public static function record( string $kind, int $form, string $name, bool $ok, string $error = '', ?int $now = null ): void {
		$now  = $now ?? time();
		$data = self::data();
		$kind = 'test' === $kind ? 'test' : 'form';
		$name = trim( (string) preg_replace( '/[<>]/', '', $name ) );
		$row  = array(
			'k'  => $kind,
			'f'  => max( 0, $form ),
			'n'  => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 80 ) : substr( $name, 0, 80 ),
			'ok' => $ok,
			'e'  => $ok ? '' : self::clean_error( $error ),
			't'  => $now,
		);
		array_unshift( $data['log'], $row );
		$data['log'] = array_slice( $data['log'], 0, self::CAP );
		if ( 'form' === $kind ) {
			$day = gmdate( 'Y-m-d', $now );
			$f   = (string) $row['f'];
			$cur = $data['tally'][ $f ][ $day ] ?? array( 0, 0 );
			$cur[ $ok ? 0 : 1 ]          = (int) ( $cur[ $ok ? 0 : 1 ] ?? 0 ) + 1;
			$data['tally'][ $f ][ $day ] = array( (int) ( $cur[0] ?? 0 ), (int) ( $cur[1] ?? 0 ) );
			$cut = gmdate( 'Y-m-d', $now - self::DAYS * 86400 );
			foreach ( $data['tally'] as $id => $days ) {
				$days = array_filter( (array) $days, static fn( $d ) => (string) $d >= $cut, ARRAY_FILTER_USE_KEY );
				if ( $days ) {
					$data['tally'][ $id ] = $days;
				} else {
					unset( $data['tally'][ $id ] );
				}
			}
			if ( ! $ok ) {
				$data['last_fail'] = $row;
			}
		}
		if ( ! $data['since'] ) {
			$data['since'] = $now;
		}
		update_option( self::OPTION, $data, false );
	}

	/* ---- Counting ------------------------------------------------------ */

	/**
	 * Form emails sent and failed in the last 7 and 30 days (whole days, UTC,
	 * today included), and whether any failed in the last 24 hours.
	 *
	 * @return array{sent7:int, failed7:int, sent30:int, failed30:int, failed24:bool}
	 */
	public static function counts( array $data, int $now ): array {
		$from7  = gmdate( 'Y-m-d', $now - 6 * 86400 );
		$from30 = gmdate( 'Y-m-d', $now - ( self::DAYS - 1 ) * 86400 );
		$c      = array( 'sent7' => 0, 'failed7' => 0, 'sent30' => 0, 'failed30' => 0, 'failed24' => false );
		foreach ( (array) $data['tally'] as $days ) {
			foreach ( (array) $days as $day => $n ) {
				$day = (string) $day;
				if ( $day >= $from30 ) {
					$c['sent30']   += (int) ( $n[0] ?? 0 );
					$c['failed30'] += (int) ( $n[1] ?? 0 );
				}
				if ( $day >= $from7 ) {
					$c['sent7']   += (int) ( $n[0] ?? 0 );
					$c['failed7'] += (int) ( $n[1] ?? 0 );
				}
			}
		}
		$day_ago = $now - 86400;
		foreach ( (array) $data['log'] as $row ) {
			if ( 'form' === ( $row['k'] ?? 'form' ) && empty( $row['ok'] ) && (int) ( $row['t'] ?? 0 ) >= $day_ago ) {
				$c['failed24'] = true;
			}
		}
		if ( ! empty( $data['last_fail'] ) && (int) ( $data['last_fail']['t'] ?? 0 ) >= $day_ago ) {
			$c['failed24'] = true;
		}
		return $c;
	}

	/** The newest test in the log, or null. */
	public static function last_test( array $data ): ?array {
		foreach ( (array) $data['log'] as $row ) {
			if ( 'test' === ( $row['k'] ?? '' ) ) {
				return $row;
			}
		}
		return null;
	}

	/* ---- The Site health row ------------------------------------------- */

	/**
	 * The "Form emails sent" row, or null when Gravity Forms is not running.
	 * Entries are counted only when no form email was noted in 30 days.
	 */
	public static function row(): ?array {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_forms' ) ) {
			return null;
		}
		$now  = time();
		$data = self::data();
		if ( ! $data['since'] ) {
			// Counting starts now, so entries from before this never read as missing emails.
			$data['since'] = $now;
			update_option( self::OPTION, $data, false );
		}
		$c       = self::counts( $data, $now );
		$entries = null;
		if ( ! $c['sent30'] && ! $c['failed30'] ) {
			$entries = self::entries_since( max( $now - self::DAYS * 86400, $data['since'] ) );
		}
		$title = static function ( int $id ): string {
			$form = $id && method_exists( 'GFAPI', 'get_form' ) ? GFAPI::get_form( $id ) : false;
			/* translators: %d: form id */
			return is_array( $form ) && '' !== (string) ( $form['title'] ?? '' ) ? (string) $form['title'] : sprintf( __( 'Form %d', 'optima-build' ), $id );
		};
		return self::build_row( $data, $now, $entries, $title );
	}

	/** Entries (not spam, not trash) since a time, or null when Gravity Forms can't say. */
	private static function entries_since( int $from ): ?int {
		// Home builds the checks more than once a page, so count once per page.
		static $memo = array();
		if ( array_key_exists( $from, $memo ) ) {
			return $memo[ $from ];
		}
		$n = 0;
		if ( method_exists( 'GFAPI', 'count_entries' ) && $from < time() ) {
			try {
				$n = (array) GFAPI::get_forms() ? GFAPI::count_entries( 0, array( 'status' => 'active', 'start_date' => wp_date( 'Y-m-d H:i:s', $from ) ) ) : 0;
				$n = is_numeric( $n ) ? (int) $n : null;
			} catch ( Throwable $e ) {
				$n = null;
			}
		}
		$memo[ $from ] = $n;
		return $n;
	}

	/**
	 * The row from what is kept. Pure apart from the words, so it can be tested.
	 *
	 * @param int|null $entries Entries since counting started, within 30 days. Only read when nothing was noted in 30 days.
	 * @param callable $title   Form id to form title.
	 */
	public static function build_row( array $data, int $now, ?int $entries, callable $title ): array {
		$c    = self::counts( $data, $now );
		$row  = array( 'label' => __( 'Form emails sent', 'optima-build' ) );
		$test = self::last_test( $data );
		$tail = '';
		if ( $test && (int) $test['t'] >= $now - 7 * 86400 ) {
			$tail = ' ' . ( ! empty( $test['ok'] )
				/* translators: %s: date */
				? sprintf( __( 'Last test: sent %s.', 'optima-build' ), self::day( (int) $test['t'] ) )
				/* translators: 1: date, 2: the error */
				: sprintf( __( 'Last test failed %1$s: %2$s.', 'optima-build' ), self::day( (int) $test['t'] ), '' !== (string) $test['e'] ? rtrim( (string) $test['e'], '.' ) : __( 'no reason given', 'optima-build' ) ) );
		}
		$test_failed = $test && empty( $test['ok'] ) && (int) $test['t'] >= $now - 7 * 86400;

		if ( $c['failed7'] ) {
			$total = $c['sent7'] + $c['failed7'];
			$last  = $data['last_fail'];
			$form  = (int) ( $last['f'] ?? 0 );
			/* translators: 1: failed, 2: tried */
			$note = sprintf( __( '%1$d of %2$d failed.', 'optima-build' ), $c['failed7'], $total );
			if ( $last ) {
				$bits = array_filter( array( $title( $form ), (string) ( $last['n'] ?? '' ), self::day( (int) ( $last['t'] ?? $now ) ) ), static fn( $b ) => '' !== $b );
				$why  = '' !== (string) ( $last['e'] ?? '' ) ? rtrim( (string) $last['e'], '.' ) : __( 'no reason given', 'optima-build' );
				/* translators: 1: form, notification and date, 2: the error */
				$note .= ' ' . sprintf( __( 'Last: %1$s: %2$s.', 'optima-build' ), implode( ', ', $bits ), $why );
			}
			$note .= ' ' . __( 'Check the Site email row, then the form\'s notifications.', 'optima-build' );
			return $row + array(
				/* translators: 1: failed, 2: tried */
				'value'     => sprintf( __( '%1$d of %2$d failed', 'optima-build' ), $c['failed7'], $total ),
				'state'     => $c['failed24'] ? 'bad' : 'warn',
				'note'      => $note . $tail,
				'link'      => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=notification&id=' . $form ),
				'link_text' => __( 'Notifications', 'optima-build' ),
			);
		}
		$failed_test = array(
			'value' => __( 'Test failed', 'optima-build' ),
			'state' => 'warn',
		);
		if ( $c['sent7'] ) {
			return $row + ( $test_failed ? $failed_test : array(
				/* translators: %d: emails sent */
				'value' => sprintf( _n( '%d sent', '%d sent', $c['sent7'], 'optima-build' ), $c['sent7'] ),
				'state' => 'good',
			) ) + array(
				/* translators: %d: emails sent */
				'note' => sprintf( _n( '%d sent in the last 7 days, none failed.', '%d sent in the last 7 days, none failed.', $c['sent7'], 'optima-build' ), $c['sent7'] ) . $tail,
			);
		}
		if ( $c['sent30'] || $c['failed30'] ) {
			return $row + ( $test_failed ? $failed_test : array(
				'value' => __( 'None this week', 'optima-build' ),
				'state' => 'good',
			) ) + array(
				/* translators: %d: emails sent */
				'note' => sprintf( _n( 'No form emails in the last 7 days. %d sent in the last 30.', 'No form emails in the last 7 days. %d sent in the last 30.', $c['sent30'], 'optima-build' ), $c['sent30'] ) . $tail,
			);
		}
		if ( $entries ) {
			$full = (int) $data['since'] <= $now - self::DAYS * 86400;
			$lead = $full
				? __( 'No form emails in 30 days', 'optima-build' )
				/* translators: %s: date counting started */
				: sprintf( __( 'No form emails since %s', 'optima-build' ), self::day( (int) $data['since'] ) );
			return $row + array(
				'value'     => __( 'None sent', 'optima-build' ),
				'state'     => 'warn',
				/* translators: 1: "No form emails in 30 days" or "since" a date, 2: entries */
				'note'      => sprintf( _n( '%1$s, but the forms got %2$d entry. Notifications may be off.', '%1$s, but the forms got %2$d entries. Notifications may be off.', $entries, 'optima-build' ), $lead, $entries ) . $tail,
				'link'      => admin_url( 'admin.php?page=gf_edit_forms' ),
				'link_text' => __( 'Forms', 'optima-build' ),
			);
		}
		return $row + ( $test_failed ? $failed_test : array(
			'value' => __( 'None yet', 'optima-build' ),
			'state' => 'good',
		) ) + array(
			'note' => __( 'No form emails yet. Each one is counted here when it goes out.', 'optima-build' ) . $tail,
		);
	}

	/** A short date, like "Nov 5". */
	private static function day( int $t ): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( 'M j', $t ) : gmdate( 'M j', $t );
	}
}
