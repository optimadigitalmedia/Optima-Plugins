<?php
/**
 * The Events hub: its own sidebar item with the Optima arrow, holding the
 * Overview (numbers, Needs you, Coming up), the Events, Sessions and Speakers
 * lists, the spreadsheet import, and settings. Anyone who can edit posts sees
 * all of it; only administrators change the settings.
 *
 * @package Optima_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Events_Admin {

	const SLUG = 'optima-events';

	/** Unfinished events listed one by one under Needs you; the rest share one line. */
	const UNFINISHED_SHOWN = 3;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_optima_events_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_optima_events_import', array( __CLASS__, 'import' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_EVENTS_FILE ), array( __CLASS__, 'action_link' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'toolbar' ), 91 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'toolbar_style' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'toolbar_style' ) );
	}

	/**
	 * Events is a tool the owner uses every week, so it has its own place in
	 * the sidebar with the Optima arrow, instead of sitting
	 * inside the Build menu with the site's settings. Partner sites
	 * (OPTIMA_HUB_BRAND false) get a plain calendar icon.
	 */
	public static function parent(): string {
		return self::SLUG;
	}

	/** The hub, or one of its views ('import', 'settings'). */
	public static function url( array $args = array() ): string {
		$view = $args['view'] ?? '';
		unset( $args['view'] );
		$base = admin_url( 'admin.php?page=' . self::SLUG . ( in_array( $view, array( 'import', 'settings', 'signups', 'volunteers' ), true ) ? '-' . $view : '' ) );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/** One event's door list. */
	public static function signups_url( int $event_id = 0 ): string {
		return self::url( array_filter( array( 'view' => 'signups', 'event' => $event_id ) ) );
	}

	/** The same arrow the Optima menu uses, so the two read as one family. */
	private static function icon(): string {
		if ( ! Optima_Admin_UI::branded() ) {
			return 'dashicons-calendar-alt';
		}
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#1272C4" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon data URI.
	}

	/**
	 * Back to the screen a form came from. The admin menu is not built on
	 * admin-post.php, so parent() cannot be trusted there; the referer can.
	 */
	private static function back( string $msg, string $hash = '' ): void {
		$ref = wp_get_referer();
		$url = ( $ref && false !== strpos( $ref, 'page=' . self::SLUG ) ) ? remove_query_arg( 'optima-events', $ref ) : admin_url( 'admin.php?page=' . self::SLUG );
		$url = preg_replace( '/#.*$/', '', $url );
		wp_safe_redirect( add_query_arg( 'optima-events', $msg, $url ) . $hash );
		exit;
	}

	public static function menu(): void {
		add_menu_page( __( 'Optima Events', 'optima-events' ), __( 'Events', 'optima-events' ), 'edit_posts', self::SLUG, array( __CLASS__, 'render' ), self::icon(), '3.1' );
		add_submenu_page( self::SLUG, __( 'Optima Events', 'optima-events' ), __( 'Overview', 'optima-events' ), 'edit_posts', self::SLUG, array( __CLASS__, 'render' ) );
		global $submenu;
		$lists = array(
			Optima_Events::EVENT   => __( 'All events', 'optima-events' ),
			Optima_Events::SESSION => __( 'Sessions', 'optima-events' ),
			Optima_Events::SPEAKER => __( 'Speakers', 'optima-events' ),
			Optima_Events_Vendors::TYPE => __( 'Vendors', 'optima-events' ),
		);
		foreach ( $lists as $type => $label ) {
			$list = 'edit.php?post_type=' . $type;
			remove_menu_page( $list );
			unset( $submenu[ $list ] ); // Its leftover sub-list would otherwise claim the screen.
			add_submenu_page( self::SLUG, $label, $label, 'edit_posts', $list );
		}
		add_submenu_page( self::SLUG, __( 'Sign-ups and check-in', 'optima-events' ), __( 'Sign-ups', 'optima-events' ), 'edit_posts', self::SLUG . '-signups', array( __CLASS__, 'render_signups' ) );
		add_submenu_page( self::SLUG, __( 'Volunteer shifts', 'optima-events' ), __( 'Volunteers', 'optima-events' ), 'edit_posts', self::SLUG . '-volunteers', array( __CLASS__, 'render_volunteers' ) );
		add_submenu_page( self::SLUG, __( 'Bring in a spreadsheet', 'optima-events' ), __( 'Import', 'optima-events' ), 'edit_posts', self::SLUG . '-import', array( __CLASS__, 'render_import' ) );
		add_submenu_page( self::SLUG, __( 'Showing events and settings', 'optima-events' ), __( 'Settings', 'optima-events' ), 'edit_posts', self::SLUG . '-settings', array( __CLASS__, 'render_settings' ) );
		// Tickets, members and applications add their screens here, before Import.
		do_action( 'optima_events_menu', self::SLUG );
	}

	/** Keep the Events menu open while editing an event, session or speaker. */
	public static function highlight( $file ) {
		global $submenu_file;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->post_type, Optima_Events::post_types(), true ) && empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$submenu_file = 'edit.php?post_type=' . $screen->post_type; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::SLUG;
		}
		return $file;
	}

	/**
	 * Events in the toolbar, right after Optima, with the same arrow: the
	 * overview, what needs attention, and quick adds.
	 */
	public static function toolbar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$branded = Optima_Admin_UI::branded();
		$count   = is_admin() || is_admin_bar_showing() ? count( self::needs_you() ) : 0;
		$title   = ( $branded ? '<span class="se-bar-mark" aria-hidden="true"></span>' : '<span class="ab-icon dashicons dashicons-calendar-alt" aria-hidden="true"></span>' )
			. '<span class="se-bar-label">' . esc_html__( 'Events', 'optima-events' ) . '</span>'
			. ( $count ? ' <span class="se-bar-count" aria-label="' . esc_attr( sprintf( /* translators: %d: number of items. */ _n( '%d thing needs you', '%d things need you', $count, 'optima-events' ), $count ) ) . '">' . (int) $count . '</span>' : '' );
		$bar->add_node(
			array(
				'id'    => 'optima-events',
				'title' => $title,
				'href'  => self::url(),
			)
		);
		$items = array(
			'optima-events-overview' => array( __( 'Overview', 'optima-events' ), self::url() ),
			'optima-events-needs'    => array(
				$count
					/* translators: %d: number of items. */
					? sprintf( _n( 'Needs you (%d)', 'Needs you (%d)', $count, 'optima-events' ), $count )
					: __( 'Needs you', 'optima-events' ),
				self::url() . '#se-needs',
			),
			'optima-events-add'      => array( __( 'Add event', 'optima-events' ), admin_url( 'post-new.php?post_type=' . Optima_Events::EVENT ) ),
			'optima-events-session'  => array( __( 'Add session', 'optima-events' ), admin_url( 'post-new.php?post_type=' . Optima_Events::SESSION ) ),
			'optima-events-speaker'  => array( __( 'Add speaker', 'optima-events' ), admin_url( 'post-new.php?post_type=' . Optima_Events::SPEAKER ) ),
		);
		foreach ( $items as $id => $item ) {
			$bar->add_node(
				array(
					'parent' => 'optima-events',
					'id'     => $id,
					'title'  => esc_html( $item[0] ),
					'href'   => $item[1],
				)
			);
		}
	}

	/** The arrow and the count beside "Events" in the toolbar. */
	public static function toolbar_style(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar #wp-admin-bar-optima-events>.ab-item{display:flex;align-items:center;gap:6px}'
			. '#wpadminbar .se-bar-mark{display:inline-block;width:11px;height:14px;background:#1272C4;clip-path:polygon(35% 0,100% 0,100% 65%,76% 41%,17% 100%,0 83%,59% 24%)}'
			. '#wpadminbar .se-bar-count{display:inline-block;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#0F63AB;color:#fff;font-size:11px;line-height:18px;text-align:center}'
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		Optima_Admin_UI::enqueue( OPTIMA_EVENTS_URL . 'assets/optima-admin.css' );
		wp_enqueue_style( 'optima-events-admin', OPTIMA_EVENTS_URL . 'assets/events-admin.css', array( 'optima-admin-kit' ), OPTIMA_EVENTS_VERSION );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Events', 'optima-events' ) . '</a>' );
		return $links;
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change event settings.', 'optima-events' ), 403 );
		}
		check_admin_referer( 'optima_events_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key is cleaned below.
		$in  = isset( $_POST['optima_events'] ) ? (array) wp_unslash( $_POST['optima_events'] ) : array();
		$old = Optima_Events::settings();
		$new = array(
			'event_slug'   => sanitize_title( (string) ( $in['event_slug'] ?? '' ) ),
			'session_slug' => sanitize_title( (string) ( $in['session_slug'] ?? '' ) ),
			'speaker_slug' => sanitize_title( (string) ( $in['speaker_slug'] ?? '' ) ),
			'currency'     => strtoupper( sanitize_text_field( (string) ( $in['currency'] ?? '' ) ) ),
			'schema'       => ! empty( $in['schema'] ) ? 1 : 0,
		);
		foreach ( array( 'event_slug', 'session_slug', 'speaker_slug' ) as $key ) {
			if ( '' === $new[ $key ] ) {
				$new[ $key ] = $old[ $key ];
			}
		}
		// Three types cannot share one address.
		$slugs = array( $new['event_slug'], $new['session_slug'], $new['speaker_slug'] );
		if ( count( array_unique( $slugs ) ) < 3 ) {
			self::back( 'sameslug' );
		}
		if ( ! preg_match( '/^[A-Z]{3}$/', $new['currency'] ) ) {
			$new['currency'] = $old['currency'];
		}
		update_option( Optima_Events::OPTION, $new );
		self::back( 'saved' );
	}

	/** Run an import (or its preview) and come back to the screen with the report. */
	public static function import(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to import events.', 'optima-events' ), 403 );
		}
		check_admin_referer( 'optima_events_import' );
		$paths = array();
		foreach ( array( 'events', 'speakers', 'sessions', 'vendors' ) as $kind ) {
			$file = $_FILES[ 'se_' . $kind ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the temp path is used, after is_uploaded_file().
			if ( is_array( $file ) && UPLOAD_ERR_OK === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) && is_uploaded_file( (string) $file['tmp_name'] ) ) {
				$paths[ $kind ] = (string) $file['tmp_name'];
			}
		}
		if ( ! $paths ) {
			self::back( 'nofile', '#se-import' );
		}
		$dry = empty( $_POST['se_apply'] );
		if ( ! $dry && ! current_user_can( 'upload_files' ) ) {
			$dry = true;
		}
		$report = ( new Optima_Events_Import( $dry ) )->run( $paths );
		set_transient( 'optima_events_import_' . get_current_user_id(), $report, HOUR_IN_SECONDS );
		self::back( $dry ? 'previewed' : 'imported', '#se-import' );
	}

	/** The last import report for this user, once. */
	private static function import_report(): void {
		$key    = 'optima_events_import_' . get_current_user_id();
		$report = get_transient( $key );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( $key );
		$labels = array(
			'events'   => __( 'events', 'optima-events' ),
			'speakers' => __( 'speakers', 'optima-events' ),
			'sessions' => __( 'sessions', 'optima-events' ),
			'vendors'  => __( 'vendors', 'optima-events' ),
		);
		$lines  = array();
		foreach ( $labels as $kind => $label ) {
			$c = (int) $report['created'][ $kind ];
			$u = (int) $report['updated'][ $kind ];
			if ( $c || $u ) {
				$lines[] = $report['dry']
					/* translators: 1: count new, 2: count updated, 3: "events" etc. */
					? sprintf( __( '%1$d new and %2$d updated %3$s', 'optima-events' ), $c, $u, $label )
					/* translators: 1: count added, 2: count updated, 3: "events" etc. */
					: sprintf( __( '%1$d added and %2$d updated %3$s', 'optima-events' ), $c, $u, $label );
			}
		}
		echo '<div class="se-report ' . ( $report['dry'] ? 'is-preview' : 'is-done' ) . '">';
		echo '<p class="se-report-head">' . esc_html( $report['dry'] ? __( 'Preview. Nothing has changed yet.', 'optima-events' ) : __( 'Import finished.', 'optima-events' ) ) . '</p>';
		echo '<p>' . esc_html( $lines ? implode( ', ', $lines ) . '.' : __( 'No rows to bring in.', 'optima-events' ) ) . '</p>';
		if ( $report['notes'] ) {
			echo '<details' . ( count( $report['notes'] ) < 8 ? ' open' : '' ) . '><summary>';
			/* translators: %d: number of notes. */
			echo esc_html( sprintf( _n( '%d note to check', '%d notes to check', count( $report['notes'] ), 'optima-events' ), count( $report['notes'] ) ) );
			echo '</summary><ul>';
			foreach ( $report['notes'] as $n ) {
				echo '<li>' . esc_html( $n ) . '</li>';
			}
			echo '</ul></details>';
		}
		if ( $report['dry'] ) {
			echo '<p>' . esc_html__( 'If that looks right, choose the same files again, tick "Bring them in", and import.', 'optima-events' ) . '</p>';
		}
		echo '</div>';
	}

	private static function message(): void {
		$msg   = isset( $_GET['optima-events'] ) ? sanitize_key( wp_unslash( $_GET['optima-events'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'saved'    => array( 'success', __( 'Settings saved. Page addresses update on the next visit.', 'optima-events' ) ),
			'sameslug' => array( 'error', __( 'Events, sessions and speakers each need their own address word. Nothing was saved.', 'optima-events' ) ),
			'nofile'   => array( 'error', __( 'Choose at least one spreadsheet file to import.', 'optima-events' ) ),
		);
		if ( isset( $texts[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $texts[ $msg ][0] ), esc_html( $texts[ $msg ][1] ) );
		}
	}

	/**
	 * Everything waiting on a person, most useful first. Other Optima
	 * features add theirs with the optima_events_needs_you filter.
	 *
	 * @return array<int,array{text:string,url:string,action:string}>
	 */
	public static function needs_you(): array {
		$items  = array();
		$drafts = get_posts(
			array(
				'post_type'      => Optima_Events::SESSION,
				'post_status'    => array( 'draft', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		if ( $drafts ) {
			$items[] = array(
				/* translators: %d: number of draft sessions. */
				'text'   => sprintf( _n( '%d session is a draft. Check its day and time, then publish it.', '%d sessions are drafts. Check their day and time, then publish them.', count( $drafts ), 'optima-events' ), count( $drafts ) ),
				'url'    => admin_url( 'edit.php?post_type=' . Optima_Events::SESSION . '&post_status=draft' ),
				'action' => __( 'Check sessions', 'optima-events' ),
			);
		}

		$upcoming = Optima_Events::event_ids( 'upcoming' );
		$event_drafts = get_posts(
			array(
				'post_type'      => Optima_Events::EVENT,
				'post_status'    => array( 'draft', 'pending' ),
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);
		// The soonest few unfinished events get a line each; the rest are counted
		// in one line, so a year-long calendar does not bury everything else.
		$unfinished = 0;
		foreach ( array_merge( $upcoming, array_map( 'intval', $event_drafts ) ) as $id ) {
			$e    = Optima_Events::event( $id );
			$todo = array();
			foreach ( Optima_Events::checks( $id ) as $c ) {
				if ( ! $c[1] ) {
					$todo[] = lcfirst( $c[0] );
				}
			}
			if ( $todo && ++$unfinished > self::UNFINISHED_SHOWN ) {
				$todo = array();
			}
			if ( $todo ) {
				$items[] = array(
					/* translators: 1: event name, 2: list of missing items. */
					'text'   => sprintf( __( '%1$s still needs %2$s.', 'optima-events' ), get_the_title( $id ), implode( ', ', $todo ) ),
					'url'    => (string) get_edit_post_link( $id, 'raw' ),
					'action' => __( 'Finish event', 'optima-events' ),
				);
			}
			if ( 'onsale' === $e['availability'] && '' !== $e['sales_end'] && $e['sales_end'] < Optima_Events::now()->format( 'Y-m-d' ) ) {
				$items[] = array(
					/* translators: %s: event name. */
					'text'   => sprintf( __( 'Ticket sales for %s were set to close, but it still says On sale.', 'optima-events' ), get_the_title( $id ) ),
					'url'    => (string) get_edit_post_link( $id, 'raw' ),
					'action' => __( 'Update tickets', 'optima-events' ),
				);
			}
			$untimed = 0;
			foreach ( Optima_Events::session_ids( $id ) as $sid ) {
				$sd = Optima_Events::session( $sid );
				if ( '' === $sd['date'] || '' === $sd['start'] ) {
					++$untimed;
				}
			}
			if ( $untimed ) {
				$items[] = array(
					/* translators: 1: number of sessions, 2: event name. */
					'text'   => sprintf( _n( '%1$d session in %2$s has no day or time yet.', '%1$d sessions in %2$s have no day or time yet.', $untimed, 'optima-events' ), $untimed, get_the_title( $id ) ),
					'url'    => admin_url( 'edit.php?post_type=' . Optima_Events::SESSION . '&se_event=' . $id ),
					'action' => __( 'Set times', 'optima-events' ),
				);
			}
		}
		if ( $unfinished > self::UNFINISHED_SHOWN ) {
			$rest    = $unfinished - self::UNFINISHED_SHOWN;
			$items[] = array(
				/* translators: %d: number of events. */
				'text'   => sprintf( _n( '%d more event still needs details. Coming up, below, shows what each one is missing.', '%d more events still need details. Coming up, below, shows what each one is missing.', $rest, 'optima-events' ), $rest ),
				'url'    => '#se-coming-up',
				'action' => __( 'See them', 'optima-events' ),
			);
		}

		$no_photo = array_filter(
			Optima_Events::speaker_ids(),
			static fn( $pid ) => ! has_post_thumbnail( $pid ) && ! get_post_meta( $pid, Optima_Events_Photos::NONE, true )
		);
		if ( $no_photo ) {
			$names   = array_map( 'get_the_title', array_slice( array_values( $no_photo ), 0, 3 ) );
			$more    = count( $no_photo ) > 3 ? ' ' . sprintf( /* translators: %d: how many more. */ __( 'and %d more', 'optima-events' ), count( $no_photo ) - 3 ) : '';
			$items[] = array(
				/* translators: 1: names, 2: "and 3 more" or nothing. */
				'text'   => sprintf( __( 'No photo yet: %1$s%2$s.', 'optima-events' ), implode( ', ', $names ), $more ),
				'url'    => admin_url( 'edit.php?post_type=' . Optima_Events::SPEAKER ),
				'action' => __( 'Add photos', 'optima-events' ),
			);
		}
		return (array) apply_filters( 'optima_events_needs_you', $items );
	}

	public static function render_import(): void {
		self::render( 'import' );
	}

	public static function render_settings(): void {
		self::render( 'settings' );
	}

	public static function render_signups(): void {
		self::render( 'signups' );
	}

	public static function render_volunteers(): void {
		self::render( 'volunteers' );
	}

	public static function render( string $view = 'overview' ): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$view = in_array( $view, array( 'overview', 'import', 'settings', 'signups', 'volunteers' ), true ) ? $view : 'overview';

		Optima_Admin_UI::open(
			__( 'Your events', 'optima-events' ),
			__( 'Everything about your events in one place: what is coming up, what needs you, and every event, session and speaker. Change something here and the site follows.', 'optima-events' ),
			__( 'Events', 'optima-events' )
		);
		self::message();
		echo '<div class="se-admin">';
		if ( 'signups' === $view ) {
			Optima_Events_RSVP_Admin::view();
		} elseif ( 'volunteers' === $view ) {
			Optima_Events_Volunteers::view();
		} elseif ( 'import' === $view ) {
			self::view_import();
		} elseif ( 'settings' === $view ) {
			self::view_settings();
		} else {
			self::view_overview();
		}
		echo '</div>';
		Optima_Admin_UI::close();
	}

	private static function view_overview(): void {
		$upcoming = Optima_Events::event_ids( 'upcoming' );
		$drafts   = get_posts(
			array(
				'post_type'      => Optima_Events::EVENT,
				'post_status'    => array( 'draft', 'pending', 'future' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
			)
		);
		$sessions = 0;
		foreach ( $upcoming as $id ) {
			$sessions += count( Optima_Events::session_ids( $id ) );
		}
		$needs = self::needs_you();
		$next  = $upcoming ? Optima_Events::event( $upcoming[0] ) : null;
		?>
		<section class="sh-box">
			<div class="sh-stats">
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) count( $upcoming ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'upcoming events', 'optima-events' ); ?></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) $sessions; ?></p>
					<p class="sh-note"><?php esc_html_e( 'sessions in them', 'optima-events' ); ?></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) count( Optima_Events::speaker_ids() ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'speakers', 'optima-events' ); ?></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) count( $needs ); ?></p>
					<p class="sh-note"><a href="#se-needs"><?php esc_html_e( 'things need you', 'optima-events' ); ?> &rsaquo;</a></p>
				</div>
				<?php if ( $next ) : ?>
					<div class="sh-stat">
						<p class="sh-num sh-num-text"><?php echo esc_html( Optima_Events::dates_label( $next ) ); ?></p>
						<p class="sh-note"><?php echo esc_html( get_the_title( $upcoming[0] ) ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</section>

		<section class="sh-box" id="se-needs">
			<h2 class="sh-title"><?php esc_html_e( 'Needs you', 'optima-events' ); ?></h2>
			<?php if ( $needs ) : ?>
				<ul class="se-needs">
					<?php foreach ( $needs as $n ) : ?>
						<li><span><?php echo esc_html( $n['text'] ); ?></span><a class="button" href="<?php echo esc_url( $n['url'] ); ?>"><?php echo esc_html( $n['action'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="se-allclear"><?php esc_html_e( 'Nothing is waiting on you. Every upcoming event is ready to share.', 'optima-events' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="sh-box" id="se-coming-up">
			<div class="se-box-head">
				<h2 class="sh-title"><?php esc_html_e( 'Coming up', 'optima-events' ); ?></h2>
				<span class="se-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Optima_Events::EVENT ) ); ?>"><?php esc_html_e( 'Add event', 'optima-events' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Optima_Events::SESSION ) ); ?>"><?php esc_html_e( 'Add session', 'optima-events' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Optima_Events::SPEAKER ) ); ?>"><?php esc_html_e( 'Add speaker', 'optima-events' ); ?></a>
				</span>
			</div>
			<?php self::table( array_merge( $upcoming, array_map( 'intval', $drafts ) ) ); ?>
		</section>
		<?php
		do_action( 'optima_events_hub_overview' );
	}

	private static function view_import(): void {
		?>
		<section class="sh-box" id="se-import">
			<h2 class="sh-title"><?php esc_html_e( 'Bring in a spreadsheet', 'optima-events' ); ?></h2>
			<p><?php esc_html_e( 'Add many events, speakers and sessions at once from CSV files saved from a spreadsheet. Each run is a preview first, so nothing changes until you say so. Running the same files again updates what is there instead of adding copies.', 'optima-events' ); ?></p>
			<?php self::import_report(); ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="se-import">
				<input type="hidden" name="action" value="optima_events_import">
				<?php wp_nonce_field( 'optima_events_import' ); ?>
				<p class="se-field"><label for="se-f-events"><?php esc_html_e( 'Events file (optional)', 'optima-events' ); ?></label><input type="file" id="se-f-events" name="se_events" accept=".csv,text/csv"></p>
				<p class="se-field"><label for="se-f-speakers"><?php esc_html_e( 'Speakers file (optional)', 'optima-events' ); ?></label><input type="file" id="se-f-speakers" name="se_speakers" accept=".csv,text/csv"></p>
				<p class="se-field"><label for="se-f-sessions"><?php esc_html_e( 'Sessions file (optional)', 'optima-events' ); ?></label><input type="file" id="se-f-sessions" name="se_sessions" accept=".csv,text/csv"></p>
				<p class="se-field"><label for="se-f-vendors"><?php esc_html_e( 'Vendors file (optional)', 'optima-events' ); ?></label><input type="file" id="se-f-vendors" name="se_vendors" accept=".csv,text/csv"></p>
				<p class="se-field"><label for="se-apply"><input type="checkbox" id="se-apply" name="se_apply" value="1"> <?php esc_html_e( 'Bring them in (leave unticked to preview)', 'optima-events' ); ?></label></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'optima-events' ); ?></button></p>
			</form>
			<p class="sh-note"><?php esc_html_e( 'Photos: put a file name already in the Media Library, or a web address, in the photo column. Columns are listed in the plugin README.', 'optima-events' ); ?></p>
		</section>
		<?php
	}

	private static function view_settings(): void {
		$s = Optima_Events::settings();
		?>
		<div class="se-admin-cols">
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Showing events on the site', 'optima-events' ); ?></h2>
				<p><?php esc_html_e( 'Each event already has its own page with the details, schedule and speakers. Paste these on any page to list them there.', 'optima-events' ); ?></p>
				<table class="sh-table">
					<tr><td>[optima_events]</td><td><?php esc_html_e( 'Upcoming events, soonest first.', 'optima-events' ); ?></td></tr>
					<tr><td>[optima_events show="past" limit="6"]</td><td><?php esc_html_e( 'The six most recent past events.', 'optima-events' ); ?></td></tr>
					<tr><td>[optima_event id="12"]</td><td><?php esc_html_e( 'One event’s details and ticket button. Without an id, the next event.', 'optima-events' ); ?></td></tr>
					<tr><td>[optima_schedule event="12"]</td><td><?php esc_html_e( 'The schedule, day by day. Add layout="grid" for rooms side by side.', 'optima-events' ); ?></td></tr>
					<tr><td>[optima_speakers event="12"]</td><td><?php esc_html_e( 'Speaker cards for one event. Without event, every speaker.', 'optima-events' ); ?></td></tr>
				</table>
				<p class="sh-note"><?php esc_html_e( 'The event id is the number in the address bar when you edit the event (post=12).', 'optima-events' ); ?></p>
			</section>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<section class="sh-box">
					<h2 class="sh-title"><?php esc_html_e( 'Settings', 'optima-events' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="se-settings">
						<input type="hidden" name="action" value="optima_events_settings">
						<?php wp_nonce_field( 'optima_events_settings' ); ?>
						<p class="se-field">
							<label for="se-set-event"><?php esc_html_e( 'Event page address', 'optima-events' ); ?></label>
							<span class="se-slug"><?php echo esc_html( home_url( '/' ) ); ?><input type="text" id="se-set-event" name="optima_events[event_slug]" value="<?php echo esc_attr( $s['event_slug'] ); ?>">/name</span>
						</p>
						<p class="se-field">
							<label for="se-set-session"><?php esc_html_e( 'Session page address', 'optima-events' ); ?></label>
							<span class="se-slug"><?php echo esc_html( home_url( '/' ) ); ?><input type="text" id="se-set-session" name="optima_events[session_slug]" value="<?php echo esc_attr( $s['session_slug'] ); ?>">/name</span>
						</p>
						<p class="se-field">
							<label for="se-set-speaker"><?php esc_html_e( 'Speaker page address', 'optima-events' ); ?></label>
							<span class="se-slug"><?php echo esc_html( home_url( '/' ) ); ?><input type="text" id="se-set-speaker" name="optima_events[speaker_slug]" value="<?php echo esc_attr( $s['speaker_slug'] ); ?>">/name</span>
						</p>
						<p class="se-field">
							<label for="se-set-currency"><?php esc_html_e( 'Currency code', 'optima-events' ); ?></label>
							<input type="text" id="se-set-currency" name="optima_events[currency]" value="<?php echo esc_attr( $s['currency'] ); ?>" maxlength="3" class="se-short-input">
						</p>
						<p class="se-field">
							<label for="se-set-schema"><input type="checkbox" id="se-set-schema" name="optima_events[schema]" value="1" <?php checked( $s['schema'] ); ?>> <?php esc_html_e( 'Add event schema for Google', 'optima-events' ); ?></label>
							<span class="sh-note"><?php esc_html_e( 'Turn off only if the theme already prints its own event schema.', 'optima-events' ); ?></span>
						</p>
						<p><button type="submit" class="button"><?php esc_html_e( 'Save settings', 'optima-events' ); ?></button></p>
					</form>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/** The upcoming and draft events with what each still needs. */
	private static function table( array $ids ): void {
		if ( ! $ids ) {
			echo '<p class="se-empty-admin">' . esc_html__( 'No upcoming events yet. Add the first one and it gets a page of its own.', 'optima-events' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped se-overview">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Event', 'optima-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'When', 'optima-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tickets', 'optima-events' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Still needed', 'optima-events' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $ids as $id ) :
					$e      = Optima_Events::event( $id );
					$todo   = array();
					foreach ( Optima_Events::checks( $id ) as $c ) {
						if ( ! $c[1] ) {
							$todo[] = $c[0];
						}
					}
					$status = get_post_status( $id );
					?>
					<tr>
						<td>
							<a class="se-row-title" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
							<?php if ( 'publish' !== $status ) : ?>
								<span class="se-chip"><?php echo esc_html( 'future' === $status ? __( 'Scheduled', 'optima-events' ) : __( 'Draft', 'optima-events' ) ); ?></span>
							<?php endif; ?>
							<?php if ( 'scheduled' !== $e['status'] ) : ?>
								<span class="se-chip is-warn"><?php echo esc_html( Optima_Events::statuses()[ $e['status'] ] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( Optima_Events::dates_label( $e ) ); ?></td>
						<td>
							<?php if ( $e['signup'] ) : ?>
								<?php $sc = Optima_Events_RSVP::counts( $id ); ?>
								<?php /* translators: %d: people signed up. */ ?>
								<a href="<?php echo esc_url( self::signups_url( $id ) ); ?>"><?php echo esc_html( sprintf( _n( '%d signed up', '%d signed up', $sc['going'], 'optima-events' ), $sc['going'] ) ); ?></a><br>
							<?php endif; ?>
							<?php echo esc_html( Optima_Events::availabilities()[ $e['availability'] ] ); ?>
							<?php $price = Optima_Events::price_label( $e ); ?>
							<?php if ( '' !== $price ) : ?>
								<br><span class="sh-note"><?php echo esc_html( $price ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $todo ) : ?>
								<?php echo esc_html( implode( ', ', $todo ) ); ?>
							<?php else : ?>
								<span class="se-chip is-done"><?php esc_html_e( 'Ready', 'optima-events' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
