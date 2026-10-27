<?php
/**
 * Optima → Business → Site check (settings) and Site checks (the inbox).
 *
 * @package Optima_Sitecheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Sitecheck_Admin {

	const SLUG = 'optima-sitecheck';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_optima_ssc_send', array( __CLASS__, 'send_now' ) );
		add_action( 'admin_post_optima_ssc_page', array( __CLASS__, 'create_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_SITECHECK_FILE ), array( __CLASS__, 'action_link' ) );
		add_filter( 'manage_' . Optima_Sitecheck::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Optima_Sitecheck::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'add_meta_boxes_' . Optima_Sitecheck::CPT, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . Optima_Sitecheck::CPT, array( __CLASS__, 'save_followed' ) );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'states' ), 10, 2 );
		add_filter( 'optima_admin_nav_group', array( __CLASS__, 'nav_group' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'optima-build', 'recon-build', 'site-build', 'optima' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	public static function url(): string {
		return 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** Site checks nobody has followed up on yet. */
	public static function waiting(): int {
		$q = new WP_Query(
			array(
				'post_type'      => Optima_Sitecheck::CPT,
				'post_status'    => 'private',
				'meta_key'       => '_ssc_followed', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '0', // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 99,
				'no_found_rows'  => true,
			)
		);
		return count( $q->posts );
	}

	public static function menu(): void {
		$parent = self::parent();
		$n      = self::waiting();
		$bubble = $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';
		add_submenu_page( $parent, __( 'Site checks', 'optima-sitecheck' ), __( 'Site checks', 'optima-sitecheck' ) . $bubble, 'edit_posts', 'edit.php?post_type=' . Optima_Sitecheck::CPT );
		add_submenu_page( $parent, __( 'Site check', 'optima-sitecheck' ), __( 'Site check', 'optima-sitecheck' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/** Site checks are leads, so they sit with the business screens. */
	public static function nav_group( string $group, string $slug ): string {
		return false !== strpos( $slug, 'sitecheck' ) ? 'business' : $group;
	}

	public static function highlight( $file ) {
		global $submenu_file;
		$screen = get_current_screen();
		if ( $screen && Optima_Sitecheck::CPT === $screen->post_type ) {
			$submenu_file = 'edit.php?post_type=' . Optima_Sitecheck::CPT; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::parent();
		}
		return $file;
	}

	public static function states( array $states, WP_Post $post ): array {
		return Optima_Sitecheck::CPT === $post->post_type ? array() : $states;
	}

	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( false !== strpos( $hook, self::SLUG ) || ( $screen && Optima_Sitecheck::CPT === $screen->post_type ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_SITECHECK_URL . 'assets/optima-admin.css' );
			wp_enqueue_style( 'optima-sitecheck', OPTIMA_SITECHECK_URL . 'assets/sitecheck.css', array(), OPTIMA_SITECHECK_VERSION );
		}
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'optima-sitecheck' ) . '</a>' );
		return $links;
	}

	/* ---------- the inbox ---------- */

	public static function columns( array $cols ): array {
		return array(
			'cb'         => $cols['cb'] ?? '',
			'title'      => __( 'Site', 'optima-sitecheck' ),
			'ssc_from'   => __( 'From', 'optima-sitecheck' ),
			'ssc_score'  => __( 'Passed', 'optima-sitecheck' ),
			'ssc_status' => __( 'Report', 'optima-sitecheck' ),
			'ssc_done'   => __( 'Followed up', 'optima-sitecheck' ),
			'date'       => __( 'Checked', 'optima-sitecheck' ),
		);
	}

	public static function status_label( int $id ): array {
		$labels = array(
			'running'     => array( __( 'Running', 'optima-sitecheck' ), 'warn' ),
			'held'        => array( __( 'Waiting for review', 'optima-sitecheck' ), 'warn' ),
			'sent'        => array( __( 'Sent', 'optima-sitecheck' ), 'good' ),
			'not_sent'    => array( __( 'Not sent', 'optima-sitecheck' ), 'bad' ),
			'unreachable' => array( __( 'Couldn\'t reach the site', 'optima-sitecheck' ), 'bad' ),
		);
		return $labels[ (string) get_post_meta( $id, '_ssc_status', true ) ] ?? $labels['running'];
	}

	public static function column( string $col, int $id ): void {
		if ( 'ssc_from' === $col ) {
			$email = (string) get_post_meta( $id, '_ssc_email', true );
			echo esc_html( (string) get_post_meta( $id, '_ssc_name', true ) );
			if ( '' !== $email ) {
				echo '<br><a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
			}
		} elseif ( 'ssc_score' === $col ) {
			$total = (int) get_post_meta( $id, '_ssc_total', true );
			echo $total ? esc_html( (int) get_post_meta( $id, '_ssc_passed', true ) . ' / ' . $total ) : '&nbsp;';
		} elseif ( 'ssc_status' === $col ) {
			list( $label, $tone ) = self::status_label( $id );
			echo '<span class="sh-pill is-' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
		} elseif ( 'ssc_done' === $col ) {
			echo get_post_meta( $id, '_ssc_followed', true ) ? '<span class="sh-pill is-good">' . esc_html__( 'Yes', 'optima-sitecheck' ) . '</span>' : '<span class="sh-pill is-warn">' . esc_html__( 'Not yet', 'optima-sitecheck' ) . '</span>';
		}
	}

	public static function meta_box(): void {
		remove_meta_box( 'submitdiv', Optima_Sitecheck::CPT, 'side' );
		add_meta_box( 'optima-ssc-report', __( 'Report', 'optima-sitecheck' ), array( __CLASS__, 'report_box' ), Optima_Sitecheck::CPT, 'normal', 'high' );
		add_meta_box( 'optima-ssc-follow', __( 'Follow up', 'optima-sitecheck' ), array( __CLASS__, 'follow_box' ), Optima_Sitecheck::CPT, 'side', 'high' );
	}

	public static function report_box( WP_Post $post ): void {
		$rows = Optima_Sitecheck::rows( $post->ID );
		list( $label ) = self::status_label( $post->ID );
		$url = (string) get_post_meta( $post->ID, '_ssc_url', true );
		echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a> &middot; ' . esc_html( $label ) . '</p>';
		if ( null === $rows ) {
			echo '<p>' . esc_html__( 'The check hasn\'t finished. If the visitor left the page, it finishes on its own within ten minutes.', 'optima-sitecheck' ) . '</p>';
			return;
		}
		$r = Optima_Sitecheck::summary( $post->ID, $rows );
		echo '<p><strong>' . esc_html( $r['passed'] . ' / ' . $r['total'] ) . '</strong> &middot; ' . esc_html( $r['headline'] ) . '</p>';
		echo '<div class="ssc ssc-admin">' . Optima_Sitecheck_Report::rows_html( $rows ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	}

	public static function follow_box( WP_Post $post ): void {
		$name  = (string) get_post_meta( $post->ID, '_ssc_name', true );
		$email = (string) get_post_meta( $post->ID, '_ssc_email', true );
		$subj  = rawurlencode( sprintf( /* translators: %s: site */ __( 'Your site check for %s', 'optima-sitecheck' ), $post->post_title ) );
		$sent  = (int) get_post_meta( $post->ID, '_ssc_sent', true );
		$ready = null !== Optima_Sitecheck::rows( $post->ID );
		wp_nonce_field( 'optima_ssc_followed', 'optima_ssc_nonce' );
		?>
		<p><strong><?php echo esc_html( $name ); ?></strong><br><?php echo esc_html( $email ); ?></p>
		<?php if ( $ready ) : ?>
			<p><a class="button<?php echo $sent ? '' : ' button-primary'; ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=optima_ssc_send&id=' . $post->ID ), 'optima_ssc_send_' . $post->ID ) ); ?>"><?php echo $sent ? esc_html__( 'Send the report again', 'optima-sitecheck' ) : esc_html__( 'Send report', 'optima-sitecheck' ); ?></a></p>
			<?php if ( $sent ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Emailed %s.', 'optima-sitecheck' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sent ) ) ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<p><a class="button" href="<?php echo esc_url( 'mailto:' . $email . '?subject=' . $subj ); ?>"><?php esc_html_e( 'Reply by email', 'optima-sitecheck' ); ?></a></p>
		<p><label><input type="checkbox" name="optima_ssc_followed" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_ssc_followed', true ) ); ?>> <?php esc_html_e( 'Followed up', 'optima-sitecheck' ); ?></label></p>
		<?php submit_button( __( 'Save', 'optima-sitecheck' ), 'secondary', 'save', false ); ?>
		<?php
	}

	public static function save_followed( int $id ): void {
		if ( ! isset( $_POST['optima_ssc_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['optima_ssc_nonce'] ), 'optima_ssc_followed' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		update_post_meta( $id, '_ssc_followed', empty( $_POST['optima_ssc_followed'] ) ? 0 : 1 );
	}

	public static function send_now(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'optima_ssc_send_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) || Optima_Sitecheck::CPT !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'optima-sitecheck' ) );
		}
		$ok = Optima_Sitecheck_Report::send( $id );
		if ( $ok ) {
			update_post_meta( $id, '_ssc_status', 'sent' );
		}
		wp_safe_redirect( add_query_arg( 'ssc_sent', $ok ? '1' : '0', admin_url( 'post.php?post=' . $id . '&action=edit' ) ) );
		exit;
	}

	public static function notice(): void {
		if ( ! isset( $_GET['ssc_sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only.
			return;
		}
		$ok = '1' === $_GET['ssc_sent']; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . ( $ok ? esc_html__( 'Report sent.', 'optima-sitecheck' ) : esc_html__( 'The email didn\'t go out. Check the site\'s email setup, then try again.', 'optima-sitecheck' ) ) . '</p></div>';
	}

	/* ---------- settings ---------- */

	public static function register(): void {
		register_setting( 'optima_sitecheck_group', Optima_Sitecheck::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in  = (array) $in;
		$old = Optima_Sitecheck::settings();
		$key = trim( (string) ( $in['psi_key'] ?? '' ) );
		if ( '' === $key ) {
			$key = $old['psi_key']; // Blank keeps the saved key; it is never shown back.
		}
		if ( ! empty( $in['psi_key_clear'] ) ) {
			$key = '';
		}
		return array(
			'notify'     => sanitize_email( (string) ( $in['notify'] ?? '' ) ),
			'lead_name'  => sanitize_text_field( (string) ( $in['lead_name'] ?? '' ) ),
			'lead_photo' => esc_url_raw( (string) ( $in['lead_photo'] ?? '' ) ),
			'book_url'   => esc_url_raw( (string) ( $in['book_url'] ?? '' ) ),
			'psi_key'    => sanitize_text_field( $key ),
			'hold'       => ! empty( $in['hold'] ) ? 1 : 0,
			'page'       => $old['page'],
		);
	}

	public static function create_page(): void {
		check_admin_referer( 'optima_ssc_page' );
		if ( ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'optima-sitecheck' ) );
		}
		$s  = Optima_Sitecheck::settings();
		$id = $s['page'] && get_post( $s['page'] ) ? $s['page'] : 0;
		if ( ! $id ) {
			$id = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => __( 'Free website check', 'optima-sitecheck' ),
					'post_name'    => 'site-check',
					'post_excerpt' => __( 'Enter your web address and get a free check of your site\'s speed, security, search basics, phone layout and AI readiness in about a minute.', 'optima-sitecheck' ),
					'post_content' => '<!-- wp:paragraph --><p>' . esc_html__( 'Enter your website address and we\'ll check eight things that decide whether people and Google can find you, trust you and reach you. You\'ll see the results here in about a minute, and we\'ll email you the full report.', 'optima-sitecheck' ) . '</p><!-- /wp:paragraph --><!-- wp:shortcode -->[optima_site_check]<!-- /wp:shortcode -->',
				)
			);
			$opt         = (array) get_option( Optima_Sitecheck::OPTION, array() );
			$opt['page'] = $id;
			update_option( Optima_Sitecheck::OPTION, $opt );
		}
		wp_safe_redirect( $id ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : self::url() );
		exit;
	}

	private static function row( string $label, string $field, string $help = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fields escaped by caller.
	}

	private static function text( string $key, string $value, string $placeholder = '', string $type = 'text' ): string {
		return '<input type="' . esc_attr( $type ) . '" class="regular-text" name="' . esc_attr( Optima_Sitecheck::OPTION . '[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = Optima_Sitecheck::settings();
		$o    = Optima_Sitecheck::OPTION;
		$page = $s['page'] ? get_post( $s['page'] ) : null;
		$live = $page && 'publish' === $page->post_status;
		$key  = '' !== Optima_Sitecheck::psi_key();
		$n    = self::waiting();
		Optima_Admin_UI::open( __( 'Free site check', 'optima-sitecheck' ), __( 'Visitors enter their web address and get a free check of eight things: speed, security, page titles, business info for Google, phone layout, AI crawlers, the sitemap and photo sizes. They see the result on the page and get the full report by email. Every request lands in Site checks.', 'optima-sitecheck' ), __( 'Site check', 'optima-sitecheck' ) );
		settings_errors( $o );
		?>
		<div class="sh-grid sh-grid-3">
			<section class="sh-box sh-check is-<?php echo $live ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'The page', 'optima-sitecheck' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo $live ? esc_html__( 'Live', 'optima-sitecheck' ) : ( $page ? esc_html__( 'Draft', 'optima-sitecheck' ) : esc_html__( 'Not made yet', 'optima-sitecheck' ) ); ?></p>
				<p class="sh-note">
					<?php if ( $page ) : ?>
						<a href="<?php echo esc_url( (string) get_edit_post_link( $page->ID ) ); ?>"><?php esc_html_e( 'Edit the page', 'optima-sitecheck' ); ?> &rsaquo;</a>
						<?php if ( $live ) : ?> &middot; <a href="<?php echo esc_url( (string) get_permalink( $page ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'optima-sitecheck' ); ?></a><?php endif; ?>
					<?php else : ?>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=optima_ssc_page' ), 'optima_ssc_page' ) ); ?>"><?php esc_html_e( 'Make the page (as a draft)', 'optima-sitecheck' ); ?></a>
					<?php endif; ?>
				</p>
			</section>
			<section class="sh-box sh-check is-<?php echo $key ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'Google\'s speed tool', 'optima-sitecheck' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo $key ? esc_html__( 'Key saved', 'optima-sitecheck' ) : esc_html__( 'No key yet', 'optima-sitecheck' ); ?></p>
				<p class="sh-note"><?php echo $key ? esc_html__( 'Speed checks run on your own Google allowance.', 'optima-sitecheck' ) : esc_html__( 'Without a key, Google often turns speed checks away and speed is left out of the score. Add a free key before the page goes live.', 'optima-sitecheck' ); ?></p>
			</section>
			<section class="sh-box sh-check is-<?php echo $n ? 'warn' : 'good'; ?>">
				<p class="sh-label"><?php esc_html_e( 'Waiting for follow-up', 'optima-sitecheck' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( (string) $n ); ?></p>
				<p class="sh-note"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Optima_Sitecheck::CPT ) ); ?>"><?php esc_html_e( 'Open Site checks', 'optima-sitecheck' ); ?> &rsaquo;</a></p>
			</section>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( 'optima_sitecheck_group' ); ?>
			<div class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Who follows up', 'optima-sitecheck' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::row( __( 'Email new checks to', 'optima-sitecheck' ), self::text( 'notify', $s['notify'], get_option( 'admin_email' ), 'email' ), __( 'Gets a copy of every check, and is the reply address on the report.', 'optima-sitecheck' ) );
					self::row( __( 'Who follows up', 'optima-sitecheck' ), self::text( 'lead_name', $s['lead_name'], 'Your account lead' ), __( 'The report says "Reply to this email and your account lead will walk you through them." Blank: "we\'ll walk you through them." Only name someone who has agreed to follow up.', 'optima-sitecheck' ) );
					self::row( __( 'Their photo', 'optima-sitecheck' ), self::text( 'lead_photo', $s['lead_photo'], 'https://', 'url' ), __( 'Optional. A JPG or PNG link (email apps can\'t show WebP). Shows next to the follow-up line.', 'optima-sitecheck' ) );
					self::row( __( 'Booking page', 'optima-sitecheck' ), self::text( 'book_url', $s['book_url'], Optima_Sitecheck::book_url(), 'url' ), __( 'Where "Book a call" goes. Blank uses the site\'s booking page, or Contact.', 'optima-sitecheck' ) );
					self::row( __( 'Review before sending', 'optima-sitecheck' ), '<label><input type="checkbox" name="' . esc_attr( $o ) . '[hold]" value="1" ' . checked( $s['hold'], true, false ) . '> ' . esc_html__( 'Hold each report until someone opens it and clicks Send report', 'optima-sitecheck' ) . '</label>', __( 'Off: the report emails right away. The visitor sees the results on the page either way.', 'optima-sitecheck' ) );
					?>
				</table>
			</div>
			<div class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Google\'s speed tool', 'optima-sitecheck' ); ?></h2>
				<p><?php esc_html_e( 'A free PageSpeed Insights key from Google Cloud (APIs & Services → Credentials → Create API key, then enable the PageSpeed Insights API). Without one, Google shares a small allowance across everyone and often turns the check away.', 'optima-sitecheck' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					if ( defined( 'OPTIMA_SITECHECK_PSI_KEY' ) ) {
						self::row( __( 'Key', 'optima-sitecheck' ), esc_html__( 'Set in wp-config.php (OPTIMA_SITECHECK_PSI_KEY).', 'optima-sitecheck' ) );
					} else {
						self::row(
							__( 'Key', 'optima-sitecheck' ),
							self::text( 'psi_key', '', '' !== $s['psi_key'] ? __( 'Saved. Leave blank to keep it.', 'optima-sitecheck' ) : '', 'password' )
							. ( '' !== $s['psi_key'] ? '<br><label><input type="checkbox" name="' . esc_attr( $o ) . '[psi_key_clear]" value="1"> ' . esc_html__( 'Remove the saved key', 'optima-sitecheck' ) . '</label>' : '' )
						);
					}
					?>
				</table>
			</div>
			<?php submit_button(); ?>
		</form>
		<div class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Putting it on another page', 'optima-sitecheck' ); ?></h2>
			<p><?php esc_html_e( 'Add a Shortcode block with [optima_site_check]. Use [optima_site_check sample="no"] to leave out the sample report.', 'optima-sitecheck' ); ?></p>
		</div>
		<?php
		Optima_Admin_UI::close();
	}
}
