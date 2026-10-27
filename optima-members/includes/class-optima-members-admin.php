<?php
/**
 * The Members hub: its own sidebar item with the Optima arrow, beside Events.
 * Overview (numbers, applications to read, renewals coming up), the members
 * list, a spreadsheet import for members moving from another system, and
 * settings. Member records are personal, so only people who can list users
 * (administrators) see any of it.
 *
 * @package Optima_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Members_Admin {

	const SLUG = 'optima-members';
	const CAP  = 'list_users';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_optima_members_decide', array( __CLASS__, 'decide' ) );
		add_action( 'admin_post_optima_members_update', array( __CLASS__, 'update' ) );
		add_action( 'admin_post_optima_members_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_optima_members_import', array( __CLASS__, 'import' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_MEMBERS_FILE ), array( __CLASS__, 'action_link' ) );
		add_filter( 'optima_events_needs_you', array( __CLASS__, 'needs_you' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'toolbar' ), 92 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_box' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'new_page_parent' ), 10, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/** The hub, or one of its views ('members', 'import', 'settings'). */
	public static function url( string $view = '', array $args = array() ): string {
		$base = admin_url( 'admin.php?page=' . self::SLUG . ( in_array( $view, array( 'members', 'import', 'settings' ), true ) ? '-' . $view : '' ) );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	private static function icon(): string {
		if ( ! Optima_Admin_UI::branded() ) {
			return 'dashicons-groups';
		}
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#1272C4" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon data URI.
	}

	public static function menu(): void {
		add_menu_page( __( 'Optima Members', 'optima-members' ), __( 'Members', 'optima-members' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ), self::icon(), '3.2' );
		add_submenu_page( self::SLUG, __( 'Optima Members', 'optima-members' ), __( 'Overview', 'optima-members' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
		add_submenu_page( self::SLUG, __( 'All members', 'optima-members' ), __( 'All members', 'optima-members' ), self::CAP, self::SLUG . '-members', array( __CLASS__, 'render_members' ) );
		add_submenu_page( self::SLUG, __( 'Bring members in', 'optima-members' ), __( 'Import', 'optima-members' ), self::CAP, self::SLUG . '-import', array( __CLASS__, 'render_import' ) );
		add_submenu_page( self::SLUG, __( 'Membership settings', 'optima-members' ), __( 'Settings', 'optima-members' ), self::CAP, self::SLUG . '-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		Optima_Admin_UI::enqueue( OPTIMA_MEMBERS_URL . 'assets/optima-admin.css' );
		wp_enqueue_style( 'optima-members-admin', OPTIMA_MEMBERS_URL . 'assets/members-admin.css', array( 'optima-admin' ), OPTIMA_MEMBERS_VERSION );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Members', 'optima-members' ) . '</a>' );
		return $links;
	}

	private static function back( string $msg, string $hash = '' ): void {
		$ref = wp_get_referer();
		$url = ( $ref && false !== strpos( $ref, 'page=' . self::SLUG ) ) ? remove_query_arg( array( 'optima-members', 'member' ), $ref ) : self::url();
		$url = preg_replace( '/#.*$/', '', $url );
		wp_safe_redirect( add_query_arg( 'optima-members', $msg, $url ) . $hash );
		exit;
	}

	/* ---------- Needs you and the toolbar ---------- */

	/** @return array<int,array{text:string,url:string,action:string}> */
	public static function waiting(): array {
		if ( ! current_user_can( self::CAP ) ) {
			return array();
		}
		$items   = array();
		$applied = count( Optima_Members::ids( 'applied' ) );
		if ( $applied ) {
			$items[] = array(
				/* translators: %d: number of applications. */
				'text'   => sprintf( _n( '%d membership application to read.', '%d membership applications to read.', $applied, 'optima-members' ), $applied ),
				'url'    => self::url() . '#sm-applications',
				'action' => __( 'Read applications', 'optima-members' ),
			);
		}
		$due = count( Optima_Members::renewals_due() );
		if ( $due ) {
			$items[] = array(
				/* translators: %d: number of members. */
				'text'   => sprintf( _n( '%d membership renews within 30 days or is past due.', '%d memberships renew within 30 days or are past due.', $due, 'optima-members' ), $due ),
				'url'    => self::url() . '#sm-renewals',
				'action' => __( 'See renewals', 'optima-members' ),
			);
		}
		return $items;
	}

	/** Membership items join the Events Needs you list, so one screen shows everything. */
	public static function needs_you( $items ): array {
		return array_merge( (array) $items, self::waiting() );
	}

	/** "Members" under the Events toolbar menu when Events is there, else its own item. */
	public static function toolbar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$applied = count( Optima_Members::ids( 'applied' ) );
		$title   = $applied
			/* translators: %d: number of applications. */
			? sprintf( _n( 'Members (%d to read)', 'Members (%d to read)', $applied, 'optima-members' ), $applied )
			: __( 'Members', 'optima-members' );
		$bar->add_node(
			array(
				'parent' => $bar->get_node( 'optima-events' ) ? 'optima-events' : false,
				'id'     => 'optima-members',
				'title'  => esc_html( $title ),
				'href'   => self::url(),
			)
		);
	}

	/* ---------- members-only box on pages and posts ---------- */

	public static function box(): void {
		foreach ( array( 'page', 'post' ) as $type ) {
			add_meta_box( 'optima-members-only', __( 'Members only', 'optima-members' ), array( __CLASS__, 'render_box' ), $type, 'side' );
		}
	}

	public static function render_box( $post ): void {
		$id = (int) $post->ID;
		wp_nonce_field( 'optima_members_only_' . $id, '_sm_only' );
		$by = Optima_Members::locked_by( $id );
		if ( Optima_Members::home_id() === $id ) {
			echo '<p><strong>' . esc_html__( 'This is the members home.', 'optima-members' ) . '</strong> ' . esc_html__( 'It and every page under it are for members only.', 'optima-members' ) . '</p>';
		} elseif ( $by ) {
			/* translators: %s: the parent page's title. */
			echo '<p>' . esc_html( sprintf( __( 'Members only, because it sits under "%s".', 'optima-members' ), get_the_title( $by ) ) ) . '</p>';
		} else {
			echo '<p><label><input type="checkbox" name="sm_members_only" value="1" ' . checked( Optima_Members::is_members_only( $id ), true, false ) . '> ' . esc_html__( 'Only members can read this', 'optima-members' ) . '</label></p>';
		}
		$plans = Optima_Members::plans();
		if ( count( $plans ) > 1 ) {
			$own = Optima_Members::own_plans( $id );
			echo '<fieldset class="sm-box-plans"><legend>' . esc_html__( 'Only for these plans (optional)', 'optima-members' ) . '</legend>';
			foreach ( $plans as $slug => $plan ) {
				echo '<label style="display:block"><input type="checkbox" name="sm_plans[]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, $own, true ), true, false ) . '> ' . esc_html( $plan['name'] ) . '</label>';
			}
			echo '</fieldset>';
			$inherited = $own ? array() : Optima_Members::plans_for( $id );
			if ( $inherited ) {
				/* translators: %s: plan names. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Now: %s only, from a parent page.', 'optima-members' ), Optima_Members::plan_names( $inherited ) ) ) . '</p>';
			}
		}
		echo '<p class="description">' . esc_html__( 'Everyone else sees a short note with the sign-in form and a link to join, and search engines leave it out. Tick no plans and every member can read it.', 'optima-members' ) . '</p>';
	}

	public static function save_box( $post_id, $post ): void {
		if ( ! isset( $_POST['_sm_only'] ) || wp_is_post_revision( $post_id ) || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sm_only'] ) ), 'optima_members_only_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! Optima_Members::locked_by( (int) $post_id ) && Optima_Members::home_id() !== (int) $post_id ) {
			Optima_Members::set_members_only( (int) $post_id, ! empty( $_POST['sm_members_only'] ) );
		}
		$plans = isset( $_POST['sm_plans'] ) ? array_map( 'sanitize_title', (array) wp_unslash( $_POST['sm_plans'] ) ) : array();
		Optima_Members::set_plans( (int) $post_id, $plans );
		// A plan limit on a page nobody locked would do nothing, so it locks the page too.
		if ( $plans && ! Optima_Members::locked( (int) $post_id ) ) {
			Optima_Members::set_members_only( (int) $post_id, true );
		}
	}

	/* ---------- actions ---------- */

	/** Approve an application, or mark it not accepted. */
	public static function decide(): void {
		$user_id = isset( $_POST['member'] ) ? absint( $_POST['member'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'optima_members_decide_' . $user_id );
		if ( ! current_user_can( self::CAP ) || ! $user_id || ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to decide on applications.', 'optima-members' ), 403 );
		}
		if ( ! empty( $_POST['approve'] ) ) {
			$plan = isset( $_POST['plan'] ) ? sanitize_title( wp_unslash( $_POST['plan'] ) ) : '';
			if ( isset( Optima_Members::plans()[ $plan ] ) ) {
				Optima_Members::save( $user_id, array( 'plan' => $plan ) );
			}
			Optima_Members::set_status( $user_id, 'active' );
			self::welcome( $user_id );
			self::back( 'approved', '#sm-applications' );
		}
		// No email goes out: the owner reaches out personally if they want to.
		Optima_Members::set_status( $user_id, 'declined' );
		self::back( 'declined', '#sm-applications' );
	}

	private static function welcome( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$next = trim( (string) get_option( 'optima_members_next_step', '' ) );
		/* translators: %s: site name. */
		$subject = sprintf( __( 'Welcome, you are a member of %s', 'optima-members' ), $site );
		$body    = __( 'Your membership application has been approved. Welcome.', 'optima-members' ) . "\n\n";
		if ( '' !== $next ) {
			$body .= $next . "\n\n";
		}
		$body .= __( 'Sign in any time to see members-only pages and events:', 'optima-members' ) . "\n" . Optima_Members::page_url() . "\n\n"
			. __( 'If you have not set a password yet, set one here:', 'optima-members' ) . "\n" . wp_lostpassword_url( Optima_Members::page_url() );
		wp_mail( $user->user_email, $subject, $body );
	}

	/** Change a member's status, plan, renewal date or note, or record a payment. */
	public static function update(): void {
		$user_id = isset( $_POST['member'] ) ? absint( $_POST['member'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'optima_members_update_' . $user_id );
		if ( ! current_user_can( self::CAP ) || ! $user_id || ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to change members.', 'optima-members' ), 403 );
		}
		if ( ! empty( $_POST['paid'] ) ) {
			Optima_Members::paid( $user_id );
			self::back( 'paid', '#sm-member-' . $user_id );
		}
		Optima_Members::save(
			$user_id,
			array(
				'plan'   => isset( $_POST['plan'] ) ? sanitize_title( wp_unslash( $_POST['plan'] ) ) : '',
				'renews' => isset( $_POST['renews'] ) ? sanitize_text_field( wp_unslash( $_POST['renews'] ) ) : '',
				'note'   => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
			)
		);
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		Optima_Members::set_status( $user_id, $status );
		self::back( 'saved', '#sm-member-' . $user_id );
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change membership settings.', 'optima-members' ), 403 );
		}
		check_admin_referer( 'optima_members_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in save_settings().
		$in = isset( $_POST['optima_members'] ) ? (array) wp_unslash( $_POST['optima_members'] ) : array();
		Optima_Members::save_settings( $in );
		update_option( 'optima_members_questions', sanitize_textarea_field( (string) ( $in['questions'] ?? '' ) ), false );
		update_option( 'optima_members_next_step', sanitize_textarea_field( (string) ( $in['next_step'] ?? '' ) ), false );
		update_option( Optima_Members_Invitations::OPTION, sanitize_textarea_field( (string) ( $in['invitation_questions'] ?? '' ) ), false );
		self::back( 'settings' );
	}

	public static function import(): void {
		if ( ! current_user_can( self::CAP ) || ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to bring members in.', 'optima-members' ), 403 );
		}
		check_admin_referer( 'optima_members_import' );
		$file = $_FILES['sm_file']['tmp_name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a temporary upload path, read as CSV only.
		if ( '' === $file || ! is_uploaded_file( $file ) ) {
			self::back( 'nofile' );
		}
		$report = Optima_Members_Import::run( $file, empty( $_POST['sm_apply'] ), ! empty( $_POST['sm_welcome'] ) );
		set_transient( 'optima_members_import_' . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS );
		self::back( 'imported' );
	}

	/* ---------- privacy: export and erase on request ---------- */

	public static function exporter( array $exporters ): array {
		$exporters['optima-members'] = array(
			'exporter_friendly_name' => __( 'Membership', 'optima-members' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user ) {
			$m = Optima_Members::member( $user->ID );
			if ( '' !== $m['status'] ) {
				$rows = array(
					array(
						'name'  => __( 'Status', 'optima-members' ),
						'value' => Optima_Members::statuses()[ $m['status'] ] ?? $m['status'],
					),
				);
				foreach ( array( 'plan', 'applied', 'since', 'renews', 'paid' ) as $k ) {
					if ( '' !== $m[ $k ] ) {
						$rows[] = array(
							'name'  => ucfirst( $k ),
							'value' => $m[ $k ],
						);
					}
				}
				foreach ( $m['answers'] as $q => $a ) {
					$rows[] = array(
						'name'  => $q,
						'value' => $a,
					);
				}
				$data[] = array(
					'group_id'    => 'optima-members',
					'group_label' => __( 'Membership', 'optima-members' ),
					'item_id'     => 'member-' . $user->ID,
					'data'        => $rows,
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function eraser( array $erasers ): array {
		$erasers['optima-members'] = array(
			'eraser_friendly_name' => __( 'Membership', 'optima-members' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	public static function erase_data( string $email ): array {
		$user    = get_user_by( 'email', $email );
		$removed = false;
		if ( $user && '' !== Optima_Members::member( $user->ID )['status'] ) {
			delete_user_meta( $user->ID, Optima_Members::META );
			delete_user_meta( $user->ID, Optima_Members::STATUS );
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/* ---------- screens ---------- */

	public static function render_members(): void {
		self::render( 'members' );
	}

	public static function render_import(): void {
		self::render( 'import' );
	}

	public static function render_settings(): void {
		self::render( 'settings' );
	}

	public static function render( string $view = 'overview' ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$view = in_array( $view, array( 'overview', 'members', 'import', 'settings' ), true ) ? $view : 'overview';
		Optima_Admin_UI::open(
			__( 'Your members', 'optima-members' ),
			__( 'Who has applied, who is a member and when each membership renews. Approve someone here and members-only pages and events open for them.', 'optima-members' ),
			__( 'Members', 'optima-members' )
		);
		self::message();
		echo '<div class="sm-admin">';
		if ( 'members' === $view ) {
			self::view_members();
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

	private static function message(): void {
		$msg   = isset( $_GET['optima-members'] ) ? sanitize_key( wp_unslash( $_GET['optima-members'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$texts = array(
			'approved' => __( 'Approved. They have a welcome email with a link to sign in.', 'optima-members' ),
			'declined' => __( 'Marked not accepted. No email was sent, so reach out yourself if you want to.', 'optima-members' ),
			'saved'    => __( 'Saved.', 'optima-members' ),
			'paid'     => __( 'Payment recorded. The renewal date moved forward one period.', 'optima-members' ),
			'settings' => __( 'Settings saved.', 'optima-members' ),
			'nofile'   => __( 'Choose a CSV file first.', 'optima-members' ),
		);
		if ( isset( $texts[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $texts[ $msg ] ) . '</p></div>';
		}
	}

	private static function view_overview(): void {
		$active  = Optima_Members::ids( 'active' );
		$current = array_filter( $active, static fn( $id ) => Optima_Members::is_member( $id ) );
		$applied = Optima_Members::ids( 'applied' );
		$due     = Optima_Members::renewals_due();
		$waiting = self::waiting();
		?>
		<section class="sh-box">
			<div class="sh-stats">
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( $current ); ?></p><p class="sh-note"><?php esc_html_e( 'members', 'optima-members' ); ?></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( $applied ); ?></p><p class="sh-note"><a href="#sm-applications"><?php esc_html_e( 'applications to read', 'optima-members' ); ?> &rsaquo;</a></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( $due ); ?></p><p class="sh-note"><a href="#sm-renewals"><?php esc_html_e( 'renewals in the next 30 days', 'optima-members' ); ?> &rsaquo;</a></p></div>
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( Optima_Members::plans() ); ?></p><p class="sh-note"><a href="<?php echo esc_url( self::url( 'settings' ) ); ?>"><?php esc_html_e( 'plans', 'optima-members' ); ?> &rsaquo;</a></p></div>
			</div>
		</section>

		<?php self::area(); ?>

		<?php if ( ! Optima_Members::plans() || ! Optima_Members::settings()['page'] ) : ?>
			<section class="sh-box sm-setup">
				<h2 class="sh-title"><?php esc_html_e( 'Two steps to open the membership', 'optima-members' ); ?></h2>
				<ol>
					<?php if ( ! Optima_Members::settings()['page'] ) : ?>
						<li><?php esc_html_e( 'Make a Membership page and put [optima_membership] on it. It shows the application to new people and the account to members.', 'optima-members' ); ?></li>
					<?php endif; ?>
					<?php if ( ! Optima_Members::plans() ) : ?>
						<li><?php esc_html_e( 'Add your plans and application questions under Settings.', 'optima-members' ); ?> <a href="<?php echo esc_url( self::url( 'settings' ) ); ?>"><?php esc_html_e( 'Open Settings', 'optima-members' ); ?></a></li>
					<?php endif; ?>
				</ol>
			</section>
		<?php endif; ?>

		<section class="sh-box" id="sm-applications">
			<h2 class="sh-title"><?php esc_html_e( 'Applications to read', 'optima-members' ); ?></h2>
			<?php if ( $applied ) : ?>
				<ul class="sm-apps">
					<?php foreach ( $applied as $id ) : ?>
						<?php self::application( $id ); ?>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="sm-allclear"><?php esc_html_e( 'No applications waiting. New ones show up here and in Events, Needs you.', 'optima-members' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="sh-box" id="sm-renewals">
			<div class="sm-box-head">
				<h2 class="sh-title"><?php esc_html_e( 'Renewing soon', 'optima-members' ); ?></h2>
				<a class="button" href="<?php echo esc_url( self::url( 'members' ) ); ?>"><?php esc_html_e( 'All members', 'optima-members' ); ?></a>
			</div>
			<?php if ( $due ) : ?>
				<?php self::table( $due ); ?>
			<?php else : ?>
				<p class="sm-allclear"><?php esc_html_e( 'No memberships renew in the next 30 days.', 'optima-members' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
		unset( $waiting );
	}

	/**
	 * What members can open: the members home, each page under it, and any
	 * other members-only page, with who each one is for. Kai's map of the
	 * members area, and the place to start a new members page.
	 */
	private static function area(): void {
		$home = Optima_Members::home_id();
		$ids  = Optima_Members::locked_ids();
		?>
		<section class="sh-box" id="sm-area">
			<div class="sm-box-head">
				<h2 class="sh-title"><?php esc_html_e( 'The members area', 'optima-members' ); ?></h2>
				<?php if ( $home ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page&sm_parent=' . $home ) ); ?>"><?php esc_html_e( 'Add a members page', 'optima-members' ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( ! $home ) : ?>
				<p><?php esc_html_e( 'Choose a members home in Settings: the page members land on after signing in. Every page under it is members only and shows up on it as a link.', 'optima-members' ); ?> <a href="<?php echo esc_url( self::url( 'settings' ) ); ?>"><?php esc_html_e( 'Open Settings', 'optima-members' ); ?></a></p>
			<?php endif; ?>
			<?php if ( $ids ) : ?>
				<table class="sh-table sm-area">
					<thead><tr><th><?php esc_html_e( 'Page', 'optima-members' ); ?></th><th><?php esc_html_e( 'Who can open it', 'optima-members' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php foreach ( $ids as $id ) : ?>
							<?php $plans = Optima_Members::plans_for( $id ); ?>
							<tr>
								<td><?php echo $id === $home ? '<strong>' . esc_html( get_the_title( $id ) ) . '</strong> <span class="sh-note">' . esc_html__( '(members home)', 'optima-members' ) . '</span>' : esc_html( ( wp_get_post_parent_id( $id ) ? '↳ ' : '' ) . get_the_title( $id ) ); ?></td>
								<td><?php echo esc_html( $plans ? Optima_Members::plan_names( $plans ) : __( 'Every member', 'optima-members' ) ); ?></td>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'optima-members' ); ?></a> &middot; <a href="<?php echo esc_url( (string) get_permalink( $id ) ); ?>"><?php esc_html_e( 'View', 'optima-members' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( $home ) : ?>
				<p class="sm-allclear"><?php esc_html_e( 'No members pages yet. Add one and it shows up on the members home.', 'optima-members' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/** "Add a members page" starts a page with the members home as its parent. */
	public static function new_page_parent( $data, $postarr ) {
		$parent = isset( $_GET['sm_parent'] ) ? absint( $_GET['sm_parent'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only sets the default parent of a new draft.
		if ( $parent && 'page' === ( $data['post_type'] ?? '' ) && 'auto-draft' === ( $data['post_status'] ?? '' ) && empty( $postarr['ID'] ) && Optima_Members::home_id() === $parent && current_user_can( 'edit_pages' ) ) {
			$data['post_parent'] = $parent;
		}
		return $data;
	}

	/** One application: who, when, their answers, and the decision. */
	private static function application( int $id ): void {
		$user = get_userdata( $id );
		if ( ! $user ) {
			return;
		}
		$m     = Optima_Members::member( $id );
		$plans = Optima_Members::plans();
		?>
		<li class="sm-app">
			<details>
				<summary>
					<span class="sm-app-name"><?php echo esc_html( $user->display_name ); ?></span>
					<span class="sm-app-meta">
						<?php
						/* translators: %s: date applied. */
						echo esc_html( sprintf( __( 'Applied %s', 'optima-members' ), Optima_Members::date_label( $m['applied'] ) ) );
						if ( '' !== $m['plan'] && '' !== Optima_Members::plan_label( $m['plan'] ) ) {
							echo ' &middot; ' . esc_html( Optima_Members::plan_label( $m['plan'] ) );
						}
						?>
					</span>
					<span class="sm-app-open"><?php esc_html_e( 'Read', 'optima-members' ); ?></span>
				</summary>
				<dl class="sm-answers">
					<div><dt><?php esc_html_e( 'Email', 'optima-members' ); ?></dt><dd><a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></dd></div>
					<?php foreach ( $m['answers'] as $q => $a ) : ?>
						<div><dt><?php echo esc_html( $q ); ?></dt><dd><?php echo '' !== $a ? nl2br( esc_html( $a ) ) : '<span class="sm-none">' . esc_html__( 'No answer', 'optima-members' ) . '</span>'; ?></dd></div>
					<?php endforeach; ?>
				</dl>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-decide">
					<input type="hidden" name="action" value="optima_members_decide">
					<input type="hidden" name="member" value="<?php echo (int) $id; ?>">
					<?php wp_nonce_field( 'optima_members_decide_' . $id ); ?>
					<?php if ( $plans ) : ?>
						<label><?php esc_html_e( 'Plan', 'optima-members' ); ?>
							<select name="plan">
								<?php foreach ( $plans as $slug => $p ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $m['plan'], $slug ); ?>><?php echo esc_html( $p['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
					<button type="submit" name="approve" value="1" class="button button-primary"><?php esc_html_e( 'Approve and welcome', 'optima-members' ); ?></button>
					<button type="submit" name="decline" value="1" class="button sm-quiet"><?php esc_html_e( 'Not accepted', 'optima-members' ); ?></button>
				</form>
				<p class="sh-note"><?php esc_html_e( 'Approving sends a welcome email with how to sign in, and your next-step note from Settings. Not accepted sends nothing.', 'optima-members' ); ?></p>
			</details>
		</li>
		<?php
	}

	private static function view_members(): void {
		$ids = Optima_Members::ids( array( 'active', 'paused', 'ended', 'declined' ) );
		?>
		<section class="sh-box">
			<div class="sm-box-head">
				<h2 class="sh-title"><?php esc_html_e( 'All members', 'optima-members' ); ?></h2>
				<a class="button" href="<?php echo esc_url( self::url( 'import' ) ); ?>"><?php esc_html_e( 'Bring members in', 'optima-members' ); ?></a>
			</div>
			<?php if ( $ids ) : ?>
				<?php self::table( $ids ); ?>
			<?php else : ?>
				<p class="sm-allclear"><?php esc_html_e( 'No members yet. Approve an application, or bring your current members in from a spreadsheet.', 'optima-members' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Members with their status, plan and renewal date, each editable in place. */
	private static function table( array $ids ): void {
		$plans = Optima_Members::plans();
		?>
		<div class="sm-table-wrap">
			<table class="widefat striped sm-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Member', 'optima-members' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'optima-members' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Plan', 'optima-members' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Renews', 'optima-members' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Save', 'optima-members' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $ids as $id ) :
						$user = get_userdata( $id );
						if ( ! $user ) {
							continue;
						}
						$m    = Optima_Members::member( $id );
						$form = 'sm-f-' . $id;
						$late = 'active' === $m['status'] && '' !== $m['renews'] && $m['renews'] < Optima_Members::today();
						?>
						<tr id="sm-member-<?php echo (int) $id; ?>">
							<td>
								<strong><?php echo esc_html( $user->display_name ); ?></strong><br>
								<span class="sh-note"><?php echo esc_html( $user->user_email ); ?></span>
								<?php if ( '' !== $m['since'] ) : ?>
									<?php /* translators: %s: date. */ ?>
									<br><span class="sh-note"><?php echo esc_html( sprintf( __( 'Since %s', 'optima-members' ), Optima_Members::date_label( $m['since'] ) ) ); ?></span>
								<?php endif; ?>
								<form id="<?php echo esc_attr( $form ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="optima_members_update">
									<input type="hidden" name="member" value="<?php echo (int) $id; ?>">
									<?php wp_nonce_field( 'optima_members_update_' . $id ); ?>
								</form>
							</td>
							<td>
								<label class="screen-reader-text" for="sm-s-<?php echo (int) $id; ?>"><?php esc_html_e( 'Status', 'optima-members' ); ?></label>
								<select id="sm-s-<?php echo (int) $id; ?>" name="status" form="<?php echo esc_attr( $form ); ?>">
									<?php foreach ( Optima_Members::statuses() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $m['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( $late ) : ?>
									<br><span class="sm-chip is-warn"><?php esc_html_e( 'Past due', 'optima-members' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<label class="screen-reader-text" for="sm-p-<?php echo (int) $id; ?>"><?php esc_html_e( 'Plan', 'optima-members' ); ?></label>
								<select id="sm-p-<?php echo (int) $id; ?>" name="plan" form="<?php echo esc_attr( $form ); ?>">
									<option value=""><?php esc_html_e( 'No plan', 'optima-members' ); ?></option>
									<?php foreach ( $plans as $slug => $p ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $m['plan'], $slug ); ?>><?php echo esc_html( $p['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<label class="screen-reader-text" for="sm-r-<?php echo (int) $id; ?>"><?php esc_html_e( 'Renews', 'optima-members' ); ?></label>
								<input type="date" id="sm-r-<?php echo (int) $id; ?>" name="renews" value="<?php echo esc_attr( $m['renews'] ); ?>" form="<?php echo esc_attr( $form ); ?>">
								<?php if ( '' !== $m['paid'] ) : ?>
									<?php /* translators: %s: date. */ ?>
									<br><span class="sh-note"><?php echo esc_html( sprintf( __( 'Last paid %s', 'optima-members' ), Optima_Members::date_label( $m['paid'] ) ) ); ?></span>
								<?php endif; ?>
							</td>
							<td class="sm-row-actions">
								<button type="submit" class="button" form="<?php echo esc_attr( $form ); ?>"><?php esc_html_e( 'Save', 'optima-members' ); ?></button>
								<button type="submit" class="button sm-quiet" name="paid" value="1" form="<?php echo esc_attr( $form ); ?>"><?php esc_html_e( 'Paid today', 'optima-members' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="sh-note"><?php esc_html_e( 'Paid today moves the renewal date forward one plan period. Until card billing is added, record payments here as they come in.', 'optima-members' ); ?></p>
		<?php
	}

	private static function view_import(): void {
		$report = get_transient( 'optima_members_import_' . get_current_user_id() );
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Bring members in', 'optima-members' ); ?></h2>
			<p><?php esc_html_e( 'Moving members from another system, such as Squarespace? Export their orders or members as a CSV and bring the file in here. Each person becomes an active member on the plan named in the file, with a renewal date one period after their latest payment. Running the same file again changes nothing.', 'optima-members' ); ?></p>
			<?php if ( is_array( $report ) ) : ?>
				<?php delete_transient( 'optima_members_import_' . get_current_user_id() ); ?>
				<div class="sm-report">
					<p><strong><?php echo esc_html( $report['dry'] ? __( 'Preview. Nothing has changed yet.', 'optima-members' ) : __( 'Import finished.', 'optima-members' ) ); ?></strong></p>
					<?php /* translators: 1: new members, 2: updated members. */ ?>
					<p><?php echo esc_html( sprintf( $report['dry'] ? __( '%1$d new and %2$d updated members.', 'optima-members' ) : __( '%1$d added and %2$d updated members.', 'optima-members' ), $report['created'], $report['updated'] ) ); ?></p>
					<?php if ( $report['notes'] ) : ?>
						<ul>
							<?php foreach ( $report['notes'] as $n ) : ?>
								<li><?php echo esc_html( $n ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( $report['dry'] ) : ?>
						<p><?php esc_html_e( 'If that looks right, choose the same file again, tick "Bring them in", and import.', 'optima-members' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-import">
				<input type="hidden" name="action" value="optima_members_import">
				<?php wp_nonce_field( 'optima_members_import' ); ?>
				<p class="sm-field"><label for="sm-file"><?php esc_html_e( 'Members file (CSV)', 'optima-members' ); ?></label><input type="file" id="sm-file" name="sm_file" accept=".csv,text/csv"></p>
				<p class="sm-field"><label><input type="checkbox" name="sm_welcome" value="1"> <?php esc_html_e( 'Email each new member a link to set their password', 'optima-members' ); ?></label><span class="sh-note"><?php esc_html_e( 'Leave unticked to bring them in quietly and invite them later.', 'optima-members' ); ?></span></p>
				<p class="sm-field"><label><input type="checkbox" id="sm-apply" name="sm_apply" value="1"> <?php esc_html_e( 'Bring them in (leave unticked to preview)', 'optima-members' ); ?></label></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'optima-members' ); ?></button></p>
			</form>
			<p class="sh-note"><?php esc_html_e( 'Columns are matched by name: Email, and First Name and Last Name or Name, Pricing Plan or Plan, Order Date or Date, Total, and an optional Renews. A person who appears on several rows keeps their latest payment. Plans are matched to the plans in Settings by name.', 'optima-members' ); ?></p>
		</section>
		<?php
	}

	private static function view_settings(): void {
		$s         = Optima_Members::settings();
		$questions = (string) get_option( 'optima_members_questions', '' );
		$next      = (string) get_option( 'optima_members_next_step', '' );
		?>
		<div class="sm-admin-cols">
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<section class="sh-box">
					<h2 class="sh-title"><?php esc_html_e( 'Settings', 'optima-members' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-settings">
						<input type="hidden" name="action" value="optima_members_settings">
						<?php wp_nonce_field( 'optima_members_settings' ); ?>
						<p class="sm-field">
							<label for="sm-set-page"><?php esc_html_e( 'Membership page', 'optima-members' ); ?></label>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'optima_members[page]',
									'id'                => 'sm-set-page',
									'selected'          => (int) $s['page'],
									'show_option_none'  => esc_html__( 'Choose a page', 'optima-members' ),
									'option_none_value' => '0',
								)
							);
							?>
							<span class="sh-note"><?php esc_html_e( 'The page with [optima_membership] on it. Join links and emails point here.', 'optima-members' ); ?></span>
						</p>
						<p class="sm-field">
							<label for="sm-set-home"><?php esc_html_e( 'Members home', 'optima-members' ); ?></label>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'optima_members[home]',
									'id'                => 'sm-set-home',
									'selected'          => (int) $s['home'],
									'show_option_none'  => esc_html__( 'None yet', 'optima-members' ),
									'option_none_value' => '0',
								)
							);
							?>
							<span class="sh-note"><?php esc_html_e( 'Where members land after signing in. It and every page under it are members only, and it lists those pages for each member. Use a different page from the membership page.', 'optima-members' ); ?></span>
						</p>
						<p class="sm-field">
							<label for="sm-set-plans"><?php esc_html_e( 'Plans, one per line', 'optima-members' ); ?></label>
							<textarea id="sm-set-plans" name="optima_members[plans]" rows="4" class="large-text code" placeholder="Club Member | 120 | year"><?php echo esc_textarea( $s['plans'] ); ?></textarea>
							<span class="sh-note"><?php esc_html_e( 'Name | price | month, year or once. People choose one when they apply, and you can change it when you approve them.', 'optima-members' ); ?></span>
						</p>
						<p class="sm-field">
							<label for="sm-set-next"><?php esc_html_e( 'Next step after approval', 'optima-members' ); ?></label>
							<textarea id="sm-set-next" name="optima_members[next_step]" rows="3" class="large-text"><?php echo esc_textarea( $next ); ?></textarea>
							<span class="sh-note"><?php esc_html_e( 'Goes in the welcome email and on the member page until their first payment is recorded. For example, how and where to pay dues.', 'optima-members' ); ?></span>
						</p>
						<p class="sm-field">
							<label for="sm-set-agree"><?php esc_html_e( 'Community agreements link (optional)', 'optima-members' ); ?></label>
							<input type="url" id="sm-set-agree" name="optima_members[agreements_url]" value="<?php echo esc_attr( $s['agreements_url'] ); ?>" class="regular-text">
							<span class="sh-note"><?php esc_html_e( 'When set, applicants must tick that they have read them.', 'optima-members' ); ?></span>
						</p>
						<p class="sm-field">
							<label for="sm-set-age"><?php esc_html_e( 'Minimum age (optional)', 'optima-members' ); ?></label>
							<input type="number" id="sm-set-age" name="optima_members[min_age]" value="<?php echo (int) $s['min_age']; ?>" min="0" max="99" class="small-text">
							<span class="sh-note"><?php esc_html_e( 'When set, applicants must confirm their age. 0 leaves it out.', 'optima-members' ); ?></span>
						</p>
						<p class="sm-field">
							<label for="sm-set-notify"><?php esc_html_e( 'Send new applications to', 'optima-members' ); ?></label>
							<input type="email" id="sm-set-notify" name="optima_members[notify]" value="<?php echo esc_attr( $s['notify'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
							<span class="sh-note"><?php esc_html_e( 'The email names the applicant only. Their answers stay on the site.', 'optima-members' ); ?></span>
						</p>
						<p class="sm-field">
							<label for="sm-set-q"><?php esc_html_e( 'Application questions, one per line', 'optima-members' ); ?></label>
							<textarea id="sm-set-q" name="optima_members[questions]" rows="14" class="large-text code" placeholder="<?php echo esc_attr( Optima_Members_Form::default_questions() ); ?>"><?php echo esc_textarea( $questions ); ?></textarea>
							<span class="sh-note"><?php esc_html_e( 'Type | Question | extra. Add * after the type to make it required. Types: heading, text, email, tel, date, url, textarea, yesno, choice (options in extra, separated by ;), agree (a statement; extra is the tick-box words), check, address. First name, last name and email are always asked. Left empty, three short questions are used.', 'optima-members' ); ?></span>
						</p>
						<p class="sm-field">
							<label for="sm-set-iq"><?php esc_html_e( 'Invitation questions, one per line', 'optima-members' ); ?></label>
							<textarea id="sm-set-iq" name="optima_members[invitation_questions]" rows="8" class="large-text code" placeholder="<?php echo esc_attr( Optima_Members_Invitations::default_questions() ); ?>"><?php echo esc_textarea( (string) get_option( Optima_Members_Invitations::OPTION, '' ) ); ?></textarea>
							<span class="sh-note"><?php esc_html_e( 'What members answer when they post an invitation with [optima_invitations]. Same format as the application. The title is always asked. Left empty, four short questions are used.', 'optima-members' ); ?></span>
						</p>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'optima-members' ); ?></button></p>
					</form>
				</section>
			<?php endif; ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Using it on the site', 'optima-members' ); ?></h2>
				<table class="sh-table">
					<tr><td>[optima_membership]</td><td><?php esc_html_e( 'The membership page: the application for new people, the account for members.', 'optima-members' ); ?></td></tr>
					<tr><td>[optima_members_only] … [/optima_members_only]</td><td><?php esc_html_e( 'Hides the part in between from everyone but members. Add plan="VIP Member" to limit it to a plan.', 'optima-members' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Members only box', 'optima-members' ); ?></td><td><?php esc_html_e( 'On any page, post or event. Ticked, only members can read it or buy tickets. Tick plans to limit a page to them.', 'optima-members' ); ?></td></tr>
					<tr><td>[optima_member_signin]</td><td><?php esc_html_e( 'The sign-in form, anywhere on the site.', 'optima-members' ); ?></td></tr>
					<tr><td>[optima_member_link]</td><td><?php esc_html_e( 'A link that reads "Member sign in", or "Members home" once a member is signed in. In a menu, add a custom link to #optima-member-link.', 'optima-members' ); ?></td></tr>
					<tr><td>[optima_invitations]</td><td><?php esc_html_e( 'Member invitations: the ones you have published, a form to post one, and each member\'s own with a way to take it down. Put it on a members page. New ones wait under Members, Invitations.', 'optima-members' ); ?></td></tr>
					<tr><td>[optima_members_home]</td><td><?php esc_html_e( 'The list of members pages and events. The members home adds it under its own words by itself; place it to put the list somewhere else on the page.', 'optima-members' ); ?></td></tr>
				</table>
				<p class="sh-note"><?php esc_html_e( 'Members sign in on the site, land on the members home and never see the dashboard. The WordPress password page carries the site\'s logo.', 'optima-members' ); ?></p>
			</section>
		</div>
		<?php
	}
}
