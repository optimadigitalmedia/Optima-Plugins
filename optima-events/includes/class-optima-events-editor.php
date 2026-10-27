<?php
/**
 * The edit screens for events, sessions and speakers: one plain form under
 * the title, in the classic screen so every field is a labelled input that
 * matches the Optima design, plus list columns that show what matters.
 *
 * Saving checks the nonce and edit_post, so anyone who can edit posts
 * (Editors included) can keep events current.
 *
 * @package Optima_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Events_Editor {

	const NONCE = 'optima_events_nonce';

	public static function boot(): void {
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'after_title' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'save_post_' . Optima_Events::EVENT, array( __CLASS__, 'save_event' ), 10, 2 );
		add_action( 'save_post_' . Optima_Events::SESSION, array( __CLASS__, 'save_session' ), 10, 2 );
		add_action( 'save_post_' . Optima_Events::SPEAKER, array( __CLASS__, 'save_speaker' ), 10, 2 );

		add_filter( 'manage_' . Optima_Events::EVENT . '_posts_columns', array( __CLASS__, 'event_columns' ) );
		add_action( 'manage_' . Optima_Events::EVENT . '_posts_custom_column', array( __CLASS__, 'event_column' ), 10, 2 );
		add_filter( 'manage_' . Optima_Events::SESSION . '_posts_columns', array( __CLASS__, 'session_columns' ) );
		add_action( 'manage_' . Optima_Events::SESSION . '_posts_custom_column', array( __CLASS__, 'session_column' ), 10, 2 );
		add_filter( 'manage_' . Optima_Events::SPEAKER . '_posts_columns', array( __CLASS__, 'speaker_columns' ) );
		add_action( 'manage_' . Optima_Events::SPEAKER . '_posts_custom_column', array( __CLASS__, 'speaker_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'session_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_query' ) );
	}

	public static function classic( $use, $post_type ) {
		return in_array( $post_type, Optima_Events::post_types(), true ) ? false : $use;
	}

	public static function title_placeholder( $text, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $text;
		}
		$words = array(
			Optima_Events::EVENT   => __( 'Event name', 'optima-events' ),
			Optima_Events::SESSION => __( 'Session title', 'optima-events' ),
			Optima_Events::SPEAKER => __( 'Full name', 'optima-events' ),
		);
		return $words[ $post->post_type ] ?? $text;
	}

	public static function assets( $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, Optima_Events::post_types(), true ) ) {
			return;
		}
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_EVENTS_URL . 'assets/optima-admin.css' );
			wp_enqueue_style( 'optima-events-admin', OPTIMA_EVENTS_URL . 'assets/events-admin.css', array( 'optima-admin' ), OPTIMA_EVENTS_VERSION );
		} elseif ( 'edit.php' === $hook ) {
			// The list screens show the Optima tabs too, so they need the kit.
			Optima_Admin_UI::enqueue( OPTIMA_EVENTS_URL . 'assets/optima-admin.css' );
			wp_enqueue_style( 'optima-events-admin', OPTIMA_EVENTS_URL . 'assets/events-admin.css', array( 'optima-admin' ), OPTIMA_EVENTS_VERSION );
		}
	}

	public static function boxes( $post_type ): void {
		if ( Optima_Events::EVENT === $post_type ) {
			add_meta_box( 'optima-event-details', __( 'Event details', 'optima-events' ), array( __CLASS__, 'render_event' ), $post_type, 'optima_events_top', 'high' );
			add_meta_box( 'optima-event-ready', __( 'Ready to share', 'optima-events' ), array( __CLASS__, 'render_ready' ), $post_type, 'side', 'high' );
			add_meta_box( 'optima-event-schedule', __( 'Schedule', 'optima-events' ), array( __CLASS__, 'render_schedule_box' ), $post_type, 'side', 'default' );
		} elseif ( Optima_Events::SESSION === $post_type ) {
			add_meta_box( 'optima-session-details', __( 'Session details', 'optima-events' ), array( __CLASS__, 'render_session' ), $post_type, 'optima_events_top', 'high' );
		} elseif ( Optima_Events::SPEAKER === $post_type ) {
			add_meta_box( 'optima-speaker-details', __( 'Speaker details', 'optima-events' ), array( __CLASS__, 'render_speaker' ), $post_type, 'optima_events_top', 'high' );
		}
	}

	/** The details form straight under the title, then a heading for the editor. */
	public static function after_title( $post ): void {
		if ( ! in_array( $post->post_type, Optima_Events::post_types(), true ) ) {
			return;
		}
		echo '<div class="se-top">';
		do_meta_boxes( $post->post_type, 'optima_events_top', $post );
		echo '</div>';
		$heads = array(
			Optima_Events::EVENT   => array( __( 'About the event', 'optima-events' ), __( 'Shows on the event page under the details.', 'optima-events' ) ),
			Optima_Events::SESSION => array( __( 'About the session', 'optima-events' ), __( 'Shows in the schedule when someone opens the session.', 'optima-events' ) ),
			Optima_Events::SPEAKER => array( __( 'Full bio (optional)', 'optima-events' ), __( 'Shows on the speaker page under the short bio.', 'optima-events' ) ),
		);
		$h = $heads[ $post->post_type ];
		echo '<h2 class="se-editor-head">' . esc_html( $h[0] ) . '<span>' . esc_html( $h[1] ) . '</span></h2>';
	}

	/* ---------- event ---------- */

	public static function render_event( $post ): void {
		$e = Optima_Events::event( (int) $post->ID );
		wp_nonce_field( 'optima_events_save', self::NONCE );
		$n = 'optima_event';
		?>
		<div class="se-form">
			<fieldset class="se-group">
				<legend><?php esc_html_e( 'When', 'optima-events' ); ?></legend>
				<div class="se-grid">
					<?php
					self::field( 'date', $n, 'start_date', __( 'Starts on', 'optima-events' ), $e['start_date'] );
					self::field( 'time', $n, 'start_time', __( 'At', 'optima-events' ), $e['start_time'] );
					self::field( 'date', $n, 'end_date', __( 'Ends on (if a different day)', 'optima-events' ), $e['end_date'] );
					self::field( 'time', $n, 'end_time', __( 'At', 'optima-events' ), $e['end_time'] );
					?>
				</div>
				<?php self::select( $n, 'status', __( 'Status', 'optima-events' ), Optima_Events::statuses(), $e['status'] ); ?>
			</fieldset>

			<fieldset class="se-group">
				<legend><?php esc_html_e( 'Where', 'optima-events' ); ?></legend>
				<?php self::select( $n, 'mode', __( 'How people attend', 'optima-events' ), Optima_Events::modes(), $e['mode'] ); ?>
				<div class="se-grid">
					<?php
					self::field( 'text', $n, 'venue', __( 'Venue name', 'optima-events' ), $e['venue'], 'se-wide' );
					self::field( 'text', $n, 'street', __( 'Street address', 'optima-events' ), $e['street'], 'se-wide' );
					self::field( 'text', $n, 'city', __( 'City', 'optima-events' ), $e['city'] );
					self::field( 'text', $n, 'region', __( 'State', 'optima-events' ), $e['region'] );
					self::field( 'text', $n, 'postal', __( 'ZIP code', 'optima-events' ), $e['postal'] );
					self::field( 'text', $n, 'country', __( 'Country code', 'optima-events' ), $e['country'] );
					self::field( 'url', $n, 'online_url', __( 'Online link (for online events)', 'optima-events' ), $e['online_url'], 'se-wide' );
					?>
				</div>
			</fieldset>

			<fieldset class="se-group">
				<legend><?php esc_html_e( 'Tickets', 'optima-events' ); ?></legend>
				<?php self::select( $n, 'availability', __( 'Tickets are', 'optima-events' ), Optima_Events::availabilities(), $e['availability'] ); ?>
				<div class="se-grid">
					<?php
					self::field( 'url', $n, 'ticket_url', __( 'Ticket link', 'optima-events' ), $e['ticket_url'], 'se-wide', __( 'Where the button goes: your ticket page, Acuity, or a partner’s checkout.', 'optima-events' ) );
					self::field( 'text', $n, 'ticket_label', __( 'Button words (optional)', 'optima-events' ), $e['ticket_label'], 'se-wide', __( 'Leave empty for "Get tickets".', 'optima-events' ) );
					self::field( 'text', $n, 'price_from', __( 'Price', 'optima-events' ), $e['price_from'], '', __( 'The lowest ticket price.', 'optima-events' ) );
					self::field( 'text', $n, 'price_to', __( 'Up to (optional)', 'optima-events' ), $e['price_to'], '', __( 'The highest, if prices vary.', 'optima-events' ) );
					self::field( 'date', $n, 'sales_start', __( 'Sales open (optional)', 'optima-events' ), $e['sales_start'] );
					self::field( 'date', $n, 'sales_end', __( 'Sales close (optional)', 'optima-events' ), $e['sales_end'] );
					?>
				</div>
				<div class="se-signup-set">
					<p class="se-field se-wide">
						<label for="se-signup"><input type="checkbox" id="se-signup" name="optima_event[signup]" value="1" <?php checked( (bool) $e['signup'] ); ?>> <?php esc_html_e( 'Take sign-ups on this site', 'optima-events' ); ?></label>
						<span class="se-help"><?php esc_html_e( 'People sign up on the event page and get a ticket with a QR code by email. Scan it at the door to check them in. Uses Capacity below, and Sales open and close above. For a free event, or one paid at the door.', 'optima-events' ); ?></span>
					</p>
					<?php self::field( 'number', $n, 'signup_guests', __( 'Guests each person may bring', 'optima-events' ), (string) $e['signup_guests'], '', __( '0 for none, up to 9.', 'optima-events' ) ); ?>
				</div>
			</fieldset>

			<fieldset class="se-group">
				<legend><?php esc_html_e( 'What to know', 'optima-events' ); ?></legend>
				<div class="se-grid">
					<?php
					self::field( 'number', $n, 'min_age', __( 'Minimum age (0 for all ages)', 'optima-events' ), (string) $e['min_age'] );
					self::field( 'number', $n, 'capacity', __( 'Capacity (optional)', 'optima-events' ), (string) $e['capacity'], '', __( 'With sign-ups on, people join a waitlist once it is full.', 'optima-events' ) );
					?>
				</div>
				<p class="se-field se-wide">
					<label for="se-know"><?php esc_html_e( 'One line each: parking, what to bring, gate times, house rules', 'optima-events' ); ?></label>
					<textarea id="se-know" name="optima_event[know]" rows="5"><?php echo esc_textarea( $e['know'] ); ?></textarea>
				</p>
				<?php self::select( $n, 'schema_type', __( 'For Google, this event is a', 'optima-events' ), Optima_Events::schema_types(), $e['schema_type'] ); ?>
			</fieldset>
		</div>
		<?php
	}

	public static function render_ready( $post ): void {
		$checks = Optima_Events::checks( (int) $post->ID );
		echo '<ul class="se-checks">';
		foreach ( $checks as $c ) {
			printf(
				'<li class="%1$s"><span class="se-tick" aria-hidden="true"></span>%2$s<span class="screen-reader-text"> %3$s</span></li>',
				esc_attr( $c[1] ? 'is-done' : 'is-todo' ),
				esc_html( $c[0] ),
				esc_html( $c[1] ? __( '(done)', 'optima-events' ) : __( '(still needed)', 'optima-events' ) )
			);
		}
		echo '</ul><p class="se-note">' . esc_html__( 'Updates when you save.', 'optima-events' ) . '</p>';
	}

	public static function render_schedule_box( $post ): void {
		$ids = 'auto-draft' === $post->post_status ? array() : Optima_Events::session_ids( (int) $post->ID );
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the event first, then add its sessions here.', 'optima-events' ) . '</p>';
			return;
		}
		/* translators: %d: number of sessions. */
		echo '<p>' . esc_html( sprintf( _n( '%d session in the schedule.', '%d sessions in the schedule.', count( $ids ), 'optima-events' ), count( $ids ) ) ) . '</p>';
		$add  = add_query_arg(
			array(
				'post_type'   => Optima_Events::SESSION,
				'se_event'    => (int) $post->ID,
			),
			admin_url( 'post-new.php' )
		);
		$list = add_query_arg(
			array(
				'post_type'   => Optima_Events::SESSION,
				'se_event'    => (int) $post->ID,
			),
			admin_url( 'edit.php' )
		);
		echo '<p><a class="button" href="' . esc_url( $add ) . '">' . esc_html__( 'Add a session', 'optima-events' ) . '</a> ';
		if ( $ids ) {
			echo '<a href="' . esc_url( $list ) . '">' . esc_html__( 'See them all', 'optima-events' ) . '</a>';
		}
		echo '</p>';
	}

	public static function save_event( $post_id, $post ): void {
		if ( ! self::can_save( (int) $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in can_save(); every key is cleaned in sanitize_event().
		$raw = isset( $_POST['optima_event'] ) ? (array) wp_unslash( $_POST['optima_event'] ) : array();
		Optima_Events::save_event( (int) $post_id, $raw );
	}

	/* ---------- session ---------- */

	public static function render_session( $post ): void {
		$s = Optima_Events::session( (int) $post->ID );
		// A new session opened from an event's Schedule box starts on that event.
		if ( ! $s['event'] && isset( $_GET['se_event'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefill only.
			$s['event'] = absint( $_GET['se_event'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( $s['event'] && '' === $s['date'] ) {
			$s['date'] = Optima_Events::event( $s['event'] )['start_date'];
		}
		wp_nonce_field( 'optima_events_save', self::NONCE );
		$n      = 'optima_session';
		$events = get_posts(
			array(
				'post_type'      => Optima_Events::EVENT,
				'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$choices = array( 0 => __( 'Choose an event', 'optima-events' ) );
		foreach ( $events as $ev ) {
			$label                = get_the_title( $ev );
			$dates                = Optima_Events::dates_label( Optima_Events::event( $ev->ID ) );
			$choices[ $ev->ID ]   = '' !== $dates ? $label . ' (' . $dates . ')' : $label;
		}
		$speakers = get_posts(
			array(
				'post_type'      => Optima_Events::SPEAKER,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<div class="se-form">
			<fieldset class="se-group">
				<legend><?php esc_html_e( 'Where it sits in the schedule', 'optima-events' ); ?></legend>
				<?php self::select( $n, 'event', __( 'Event', 'optima-events' ), $choices, (string) $s['event'] ); ?>
				<div class="se-grid">
					<?php
					self::field( 'date', $n, 'date', __( 'Day', 'optima-events' ), $s['date'] );
					self::field( 'time', $n, 'start', __( 'Starts', 'optima-events' ), $s['start'] );
					self::field( 'time', $n, 'end', __( 'Ends', 'optima-events' ), $s['end'] );
					self::field( 'text', $n, 'room', __( 'Room, stage or tent', 'optima-events' ), $s['room'] );
					self::field( 'text', $n, 'track', __( 'Track (optional)', 'optima-events' ), $s['track'] );
					self::field( 'number', $n, 'capacity', __( 'Seats (optional)', 'optima-events' ), (string) $s['capacity'] );
					?>
				</div>
				<?php self::select( $n, 'kind', __( 'Kind of session', 'optima-events' ), Optima_Events::kinds(), $s['kind'] ); ?>
			</fieldset>
			<fieldset class="se-group">
				<legend><?php esc_html_e( 'Led by', 'optima-events' ); ?></legend>
				<?php if ( $speakers ) : ?>
					<div class="se-people">
						<?php foreach ( $speakers as $sp ) : ?>
							<label for="se-sp-<?php echo (int) $sp->ID; ?>">
								<input type="checkbox" id="se-sp-<?php echo (int) $sp->ID; ?>" name="optima_session[speakers][]" value="<?php echo (int) $sp->ID; ?>" <?php checked( in_array( (int) $sp->ID, $s['speakers'], true ) ); ?>>
								<?php echo esc_html( get_the_title( $sp ) ); ?>
								<?php if ( 'publish' !== $sp->post_status ) : ?>
									<span class="se-note"><?php esc_html_e( '(not published)', 'optima-events' ); ?></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="se-note"><?php esc_html_e( 'No speakers yet. Add them under Speakers, then tick them here.', 'optima-events' ); ?></p>
				<?php endif; ?>
			</fieldset>
		</div>
		<?php
	}

	public static function save_session( $post_id, $post ): void {
		if ( ! self::can_save( (int) $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in can_save(); cleaned in sanitize_session().
		$raw = isset( $_POST['optima_session'] ) ? (array) wp_unslash( $_POST['optima_session'] ) : array();
		Optima_Events::save_session( (int) $post_id, $raw );
	}

	/* ---------- speaker ---------- */

	public static function render_speaker( $post ): void {
		$p = Optima_Events::speaker( (int) $post->ID );
		wp_nonce_field( 'optima_events_save', self::NONCE );
		$n = 'optima_speaker';
		?>
		<div class="se-form">
			<div class="se-grid">
				<?php
				self::field( 'text', $n, 'role', __( 'What they do', 'optima-events' ), $p['role'], 'se-wide', __( 'For example, Somatic educator and author.', 'optima-events' ) );
				self::field( 'url', $n, 'website', __( 'Website', 'optima-events' ), $p['website'], 'se-wide' );
				?>
			</div>
			<p class="se-field se-wide">
				<label for="se-short"><?php esc_html_e( 'Short bio (two or three sentences)', 'optima-events' ); ?></label>
				<textarea id="se-short" name="optima_speaker[short]" rows="4"><?php echo esc_textarea( $p['short'] ); ?></textarea>
			</p>
			<p class="se-field se-wide">
				<label for="se-links"><?php esc_html_e( 'Social profiles, one link per line', 'optima-events' ); ?></label>
				<textarea id="se-links" name="optima_speaker[links]" rows="3"><?php echo esc_textarea( implode( "\n", $p['links'] ) ); ?></textarea>
			</p>
			<p class="se-note"><?php esc_html_e( 'The photo is the featured image. Their sessions are listed on their page automatically.', 'optima-events' ); ?></p>
		</div>
		<?php
	}

	public static function save_speaker( $post_id, $post ): void {
		if ( ! self::can_save( (int) $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in can_save(); cleaned in sanitize_speaker().
		$raw = isset( $_POST['optima_speaker'] ) ? (array) wp_unslash( $_POST['optima_speaker'] ) : array();
		update_post_meta( (int) $post_id, '_optima_speaker', Optima_Events::sanitize_speaker( $raw ) );
	}

	/* ---------- list screens ---------- */

	public static function event_columns( array $cols ): array {
		return self::insert_after_title(
			$cols,
			array(
				'se_when'    => __( 'When', 'optima-events' ),
				'se_tickets' => __( 'Tickets', 'optima-events' ),
				'se_ready'   => __( 'Ready', 'optima-events' ),
			)
		);
	}

	public static function event_column( $col, $post_id ): void {
		$e = Optima_Events::event( (int) $post_id );
		if ( 'se_when' === $col ) {
			echo esc_html( Optima_Events::dates_label( $e ) );
			if ( Optima_Events::is_past( $e ) ) {
				echo ' <span class="se-chip is-past">' . esc_html__( 'Past', 'optima-events' ) . '</span>';
			}
		} elseif ( 'se_tickets' === $col ) {
			echo esc_html( Optima_Events::availabilities()[ $e['availability'] ] );
			$price = Optima_Events::price_label( $e );
			if ( '' !== $price ) {
				echo '<br><span class="se-note">' . esc_html( $price ) . '</span>';
			}
		} elseif ( 'se_ready' === $col ) {
			self::count_chip( Optima_Events::checks( (int) $post_id ) );
		}
	}

	public static function session_columns( array $cols ): array {
		return self::insert_after_title(
			$cols,
			array(
				'se_event'    => __( 'Event', 'optima-events' ),
				'se_slot'     => __( 'Day and time', 'optima-events' ),
				'se_room'     => __( 'Room', 'optima-events' ),
				'se_speakers' => __( 'Led by', 'optima-events' ),
			)
		);
	}

	public static function session_column( $col, $post_id ): void {
		$s = Optima_Events::session( (int) $post_id );
		if ( 'se_event' === $col ) {
			echo $s['event'] ? esc_html( get_the_title( $s['event'] ) ) : '<span class="se-note">' . esc_html__( 'No event', 'optima-events' ) . '</span>';
		} elseif ( 'se_slot' === $col ) {
			echo esc_html( trim( Optima_Events::day_label( $s['date'] ) . ' · ' . Optima_Events::session_time_label( $s ), ' ·' ) );
		} elseif ( 'se_room' === $col ) {
			echo esc_html( $s['room'] );
		} elseif ( 'se_speakers' === $col ) {
			echo esc_html( implode( ', ', array_map( 'get_the_title', $s['speakers'] ) ) );
		}
	}

	public static function speaker_columns( array $cols ): array {
		$out = array();
		foreach ( $cols as $key => $label ) {
			if ( 'title' === $key ) {
				$out['se_photo'] = '<span class="screen-reader-text">' . esc_html__( 'Photo', 'optima-events' ) . '</span>';
				$out[ $key ]     = __( 'Name', 'optima-events' );
				$out['se_role']  = __( 'What they do', 'optima-events' );
				$out['se_count'] = __( 'Sessions', 'optima-events' );
				continue;
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	public static function speaker_column( $col, $post_id ): void {
		if ( 'se_photo' === $col ) {
			echo get_the_post_thumbnail( (int) $post_id, array( 44, 44 ), array( 'class' => 'se-thumb', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
		} elseif ( 'se_role' === $col ) {
			echo esc_html( Optima_Events::speaker( (int) $post_id )['role'] );
		} elseif ( 'se_count' === $col ) {
			echo (int) count( Optima_Events::sessions_for_speaker( (int) $post_id ) );
		}
	}

	/** An event filter on the Sessions list. */
	public static function session_filter( $post_type ): void {
		if ( Optima_Events::SESSION !== $post_type ) {
			return;
		}
		$current = isset( $_GET['se_event'] ) ? absint( $_GET['se_event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
		$events  = get_posts(
			array(
				'post_type'      => Optima_Events::EVENT,
				'post_status'    => 'any',
				'posts_per_page' => 200,
			)
		);
		echo '<label class="screen-reader-text" for="se-filter-event">' . esc_html__( 'Show sessions of', 'optima-events' ) . '</label>';
		echo '<select id="se-filter-event" name="se_event"><option value="0">' . esc_html__( 'All events', 'optima-events' ) . '</option>';
		foreach ( $events as $ev ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $ev->ID, selected( $current, (int) $ev->ID, false ), esc_html( get_the_title( $ev ) ) );
		}
		echo '</select>';
	}

	/** List order: events by date, sessions by slot, speakers by site order. */
	public static function list_query( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$type = $query->get( 'post_type' );
		if ( Optima_Events::SESSION === $type && ! empty( $_GET['se_event'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
			$query->set(
				'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small table.
				array(
					array(
						'key'   => '_optima_session_event',
						'value' => absint( $_GET['se_event'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					),
				)
			);
		}
		if ( $query->get( 'orderby' ) ) {
			return;
		}
		if ( Optima_Events::EVENT === $type ) {
			$query->set( 'meta_key', '_optima_event_start' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', 'DESC' );
		} elseif ( Optima_Events::SESSION === $type ) {
			$query->set( 'meta_key', '_optima_session_start' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', 'ASC' );
		} elseif ( Optima_Events::SPEAKER === $type ) {
			$query->set(
				'orderby',
				array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				)
			);
		}
	}

	/* ---------- helpers ---------- */

	private static function can_save( int $post_id ): bool {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'optima_events_save' ) ) {
			return false;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	private static function insert_after_title( array $cols, array $add ): array {
		$out = array();
		foreach ( $cols as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out = array_merge( $out, $add );
			}
		}
		unset( $out['date'] );
		return $out;
	}

	private static function count_chip( array $checks ): void {
		$done = count( array_filter( wp_list_pluck( $checks, 1 ) ) );
		/* translators: 1: checks done, 2: total checks. */
		echo '<span class="se-chip' . ( count( $checks ) === $done ? ' is-done' : '' ) . '">' . esc_html( sprintf( __( '%1$d of %2$d', 'optima-events' ), $done, count( $checks ) ) ) . '</span>';
	}

	/** One labelled input. */
	private static function field( string $type, string $group, string $key, string $label, string $value, string $class = '', string $help = '' ): void {
		$id = 'se-' . $group . '-' . $key;
		echo '<p class="se-field ' . esc_attr( $class ) . '">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		$extra = 'number' === $type ? ' min="0" step="1"' : '';
		if ( 'number' === $type && '0' === $value ) {
			$value = '';
		}
		printf(
			'<input type="%1$s" id="%2$s" name="%3$s[%4$s]" value="%5$s"%6$s%7$s>',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( $group ),
			esc_attr( $key ),
			esc_attr( $value ),
			$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed string.
			'' !== $help ? ' aria-describedby="' . esc_attr( $id ) . '-help"' : ''
		);
		if ( '' !== $help ) {
			echo '<span class="se-help" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</span>';
		}
		echo '</p>';
	}

	/** One labelled select. */
	private static function select( string $group, string $key, string $label, array $choices, string $value ): void {
		$id = 'se-' . $group . '-' . $key;
		echo '<p class="se-field se-wide"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $group . '[' . $key . ']' ) . '">';
		foreach ( $choices as $k => $text ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( (string) $k ), selected( (string) $k, $value, false ), esc_html( $text ) );
		}
		echo '</select></p>';
	}
}
