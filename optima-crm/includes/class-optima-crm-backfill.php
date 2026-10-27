<?php
/**
 * Events, Customer list: whether the sync is on, and one button that brings
 * in everyone from before it was switched on (sign-ups, volunteers, vendors,
 * presenters, members and paid shop tickets), tagged the same way. Running it
 * twice changes nothing: each person is matched by email.
 *
 * @package Optima_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_CRM_Backfill {

	const PAGE = 'optima-events-crm';

	public static function boot(): void {
		add_action( 'optima_events_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_optima_crm_backfill', array( __CLASS__, 'run' ) );
	}

	public static function menu( $parent ): void {
		add_submenu_page( (string) $parent, __( 'Customer list', 'optima-crm' ), __( 'Customer list', 'optima-crm' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ui = class_exists( 'Optima_Admin_UI' );
		if ( $ui ) {
			Optima_Admin_UI::open( __( 'Customer list', 'optima-crm' ), __( 'Everyone who buys, signs up, volunteers, applies or joins, in one list in FluentCRM, tagged by what they did, the event and its region.', 'optima-crm' ), __( 'Events', 'optima-crm' ) );
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Customer list', 'optima-crm' ) . '</h1>';
		}
		$done = get_transient( 'optima_crm_backfill_' . get_current_user_id() );
		if ( is_array( $done ) ) {
			delete_transient( 'optima_crm_backfill_' . get_current_user_id() );
			$parts = array();
			foreach ( $done as $what => $n ) {
				$parts[] = $what . ': ' . number_format_i18n( (int) $n );
			}
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Done. Added or updated:', 'optima-crm' ) . ' ' . esc_html( implode( ' · ', $parts ) ) . '</p></div>';
		}
		echo '<section class="sh-box"><h2 class="sh-title">' . esc_html__( 'Status', 'optima-crm' ) . '</h2>';
		if ( ! Optima_CRM::ready() ) {
			echo '<p>' . esc_html__( 'FluentCRM is not active, so nothing is sent yet. Install and activate FluentCRM (free) and this page takes it from there.', 'optima-crm' ) . '</p></section>';
		} else {
			echo '<p>' . esc_html__( 'On. New tickets, sign-ups, volunteers, vendor and presenter applications and membership changes go to FluentCRM as they happen.', 'optima-crm' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=fluentcrm-admin#/subscribers' ) ) . '">' . esc_html__( 'Open the contacts', 'optima-crm' ) . '</a></p>';
			echo '<p class="sh-note">' . esc_html__( 'New people are added as transactional: they get receipts and event notes, and join marketing emails only when they subscribe. People already in the list keep their status.', 'optima-crm' ) . '</p></section>';
			echo '<section class="sh-box"><h2 class="sh-title">' . esc_html__( 'Bring in everyone so far', 'optima-crm' ) . '</h2><p>' . esc_html__( 'Adds the people from before the sync was on, tagged the same way. Safe to run again: people are matched by email.', 'optima-crm' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="optima_crm_backfill">';
			wp_nonce_field( 'optima_crm_backfill' );
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Bring them in', 'optima-crm' ) . '</button></p></form></section>';
		}
		echo '<section class="sh-box"><h2 class="sh-title">' . esc_html__( 'Tags it uses', 'optima-crm' ) . '</h2><p>' . esc_html__( 'Attendee, Waitlist, Volunteer, Vendor, Presenter, Ticket buyer, Member applicant, Member, Plan: (plan name), Event: (event name), Region: (region name), Ticket: (ticket type).', 'optima-crm' ) . '</p></section>';
		if ( $ui ) {
			Optima_Admin_UI::close();
		} else {
			echo '</div>';
		}
	}

	public static function run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'optima-crm' ), 403 );
		}
		check_admin_referer( 'optima_crm_backfill' );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a one-off bulk run.
		}
		$count = array();
		$add   = static function ( string $label, bool $ok ) use ( &$count ) {
			$count[ $label ] = ( $count[ $label ] ?? 0 ) + ( $ok ? 1 : 0 );
		};
		global $wpdb;

		if ( class_exists( 'Optima_Events_RSVP' ) ) {
			$rows = $wpdb->get_results( 'SELECT event_id, name, email, status FROM ' . Optima_Events_RSVP::table() . " WHERE status IN ('going','waitlist') LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $r ) {
				$role = 'going' === $r['status'] ? 'attendee' : 'waitlist';
				$add( __( 'sign-ups', 'optima-crm' ), Optima_CRM::save( array( 'email' => $r['email'], 'name' => $r['name'] ), array_merge( array( 'going' === $r['status'] ? __( 'Attendee', 'optima-crm' ) : __( 'Waitlist', 'optima-crm' ) ), Optima_CRM::event_tags( (int) $r['event_id'] ) ), 'events-' . $role ) );
			}
		}
		if ( class_exists( 'Optima_Events_Volunteers' ) ) {
			$rows = $wpdb->get_results( 'SELECT event_id, email, MIN(name) AS name, MIN(phone) AS phone FROM ' . Optima_Events_Volunteers::table() . " WHERE status = 'going' GROUP BY event_id, email LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $r ) {
				$add( __( 'volunteers', 'optima-crm' ), Optima_CRM::save( array( 'email' => $r['email'], 'name' => $r['name'], 'phone' => $r['phone'] ), array_merge( array( __( 'Volunteer', 'optima-crm' ) ), Optima_CRM::event_tags( (int) $r['event_id'] ) ), 'events-volunteer' ) );
			}
		}
		if ( class_exists( 'Optima_Events_Vendors' ) ) {
			$ids = get_posts( array( 'post_type' => Optima_Events_Vendors::TYPE, 'post_status' => array( 'publish', 'pending' ), 'posts_per_page' => 2000, 'fields' => 'ids' ) );
			foreach ( $ids as $id ) {
				$priv = get_post_meta( (int) $id, Optima_Events_Vendors::PRIVATE, true );
				$priv = is_array( $priv ) ? $priv : array();
				$tags = array( __( 'Vendor', 'optima-crm' ) );
				foreach ( Optima_Events_Vendors::vendor( (int) $id )['events'] as $event ) {
					$tags = array_merge( $tags, Optima_CRM::event_tags( (int) $event ) );
				}
				$add( __( 'vendors', 'optima-crm' ), Optima_CRM::save( array( 'email' => (string) ( $priv['email'] ?? '' ), 'name' => (string) ( $priv['contact'] ?? '' ), 'phone' => (string) ( $priv['phone'] ?? '' ), 'company' => get_the_title( (int) $id ) ), $tags, 'events-vendor' ) );
			}
		}
		if ( class_exists( 'Optima_Events_Presenters' ) ) {
			$ids = get_posts( array( 'post_type' => 'optima_speaker', 'post_status' => array( 'publish', 'pending' ), 'posts_per_page' => 2000, 'fields' => 'ids', 'meta_key' => Optima_Events_Presenters::USER ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a one-off run.
			foreach ( $ids as $id ) {
				$user = get_userdata( (int) get_post_meta( (int) $id, Optima_Events_Presenters::USER, true ) );
				if ( ! $user ) {
					continue;
				}
				$tags = array( __( 'Presenter', 'optima-crm' ) );
				foreach ( Optima_Events::sessions_for_speaker( (int) $id ) as $sid ) {
					$tags = array_merge( $tags, Optima_CRM::event_tags( Optima_Events::session( (int) $sid )['event'] ) );
				}
				$add( __( 'presenters', 'optima-crm' ), Optima_CRM::save( array( 'email' => $user->user_email, 'name' => get_the_title( (int) $id ) ), $tags, 'events-presenter' ) );
			}
		}
		if ( class_exists( 'Optima_Members' ) ) {
			foreach ( Optima_Members::ids( array( 'applied', 'active' ) ) as $uid ) {
				Optima_CRM::member( $uid, Optima_Members::member( $uid )['status'], '' );
				$add( __( 'members', 'optima-crm' ), true );
			}
		}
		if ( class_exists( 'Optima_Events_Woo' ) && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'limit' => 2000 ) );
			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					$pid   = (int) $item->get_product_id();
					$event = (int) get_post_meta( $pid, Optima_Events_Woo::EVENT, true );
					if ( $event ) {
						Optima_CRM::ticket( $order, $event, $pid, (int) $item->get_quantity() );
						$add( __( 'ticket lines', 'optima-crm' ), true );
					}
				}
			}
		}
		set_transient( 'optima_crm_backfill_' . get_current_user_id(), $count, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}
}
