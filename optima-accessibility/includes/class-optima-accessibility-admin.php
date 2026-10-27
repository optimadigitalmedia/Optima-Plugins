<?php
/**
 * Build -> Accessibility: the weekly results, the at-launch checklist, the
 * statement builder and the footer-link helper.
 *
 * @package Optima_Accessibility
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Accessibility_Admin {

	const SLUG = 'optima-accessibility';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_ACCESSIBILITY_FILE ), array( __CLASS__, 'action_link' ) );
		add_filter( 'optima_admin_nav_hint', array( __CLASS__, 'nav_hint' ), 10, 2 );
		foreach ( array( 'statement', 'run', 'launch', 'footer', 'menu_add' ) as $action ) {
			add_action( 'admin_post_optima_a11y_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/** Nest under the site's Build section; fall back to Settings. */
	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'optima-build', 'recon-build', 'site-build', 'optima' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	public static function url( array $args = array() ): string {
		$base = 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Accessibility', 'optima-accessibility' ), __( 'Accessibility', 'optima-accessibility' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_ACCESSIBILITY_URL . 'assets/optima-admin.css' );
			wp_enqueue_style( 'optima-accessibility-admin', OPTIMA_ACCESSIBILITY_URL . 'assets/admin.css', array( 'optima-admin' ), OPTIMA_ACCESSIBILITY_VERSION );
		}
	}

	/** Count of weekly checks to fix, for the shared Optima section nav. */
	public static function to_fix(): int {
		$r = Optima_Accessibility_Checks::results();
		if ( empty( $r['time'] ) ) {
			return -1;
		}
		$st = $r['statement'] ?? array();
		return ( $r['images'] ? 1 : 0 ) + ( $r['headings'] ? 1 : 0 ) + ( $r['forms'] ? 1 : 0 )
			+ ( ( ! empty( $st['exists'] ) && ! empty( $st['linked'] ) && ! empty( $st['reviewed'] ) ) ? 0 : 1 );
	}

	public static function nav_hint( $hint, $slug ) {
		if ( self::SLUG !== $slug ) {
			return $hint;
		}
		$n = self::to_fix();
		if ( $n < 0 ) {
			return __( 'Not checked', 'optima-accessibility' );
		}
		/* translators: %d: checks to fix */
		return $n ? sprintf( _n( '%d to fix', '%d to fix', $n, 'optima-accessibility' ), $n ) : __( 'All pass', 'optima-accessibility' );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'optima-accessibility' ) . '</a>' );
		return $links;
	}

	/* ---------------------------------------------------------------- saves */

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change this.', 'optima-accessibility' ), 403 );
		}
		check_admin_referer( 'optima_a11y_' . $action );
	}

	private static function back( string $msg, string $anchor = '' ): void {
		// admin-post.php builds no menu, so go back to the screen the form came from.
		$ref  = wp_get_referer();
		$base = $ref ? strtok( remove_query_arg( 'msg', $ref ), '#' ) : self::url();
		wp_safe_redirect( add_query_arg( 'msg', $msg, $base ) . ( '' !== $anchor ? '#' . $anchor : '' ) );
		exit;
	}

	private static function valid_date( string $date ): string {
		$d = DateTime::createFromFormat( '!Y-m-d', $date );
		return ( $d && $d->format( 'Y-m-d' ) === $date ) ? $date : '';
	}

	/** Save the statement settings and create or update the page. */
	public static function handle_statement(): void {
		self::guard( 'statement' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$in = isset( $_POST['sa'] ) && is_array( $_POST['sa'] ) ? wp_unslash( $_POST['sa'] ) : array();
		// phpcs:enable
		$s                  = Optima_Accessibility::settings();
		$s['email']         = sanitize_email( (string) ( $in['email'] ?? '' ) );
		$s['phone']         = sanitize_text_field( (string) ( $in['phone'] ?? '' ) );
		$s['response_days'] = min( 30, absint( $in['response_days'] ?? 0 ) );
		$s['other_formats'] = empty( $in['other_formats'] ) ? 0 : 1;
		$s['last_reviewed'] = self::valid_date( sanitize_text_field( (string) ( $in['last_reviewed'] ?? '' ) ) );
		if ( '' === $s['last_reviewed'] ) {
			$s['last_reviewed'] = current_time( 'Y-m-d' );
		}
		update_option( Optima_Accessibility::OPTION, $s );
		Optima_Accessibility::ensure_page();
		self::refresh_statement_result();
		self::back( empty( Optima_Accessibility::settings()['own_page'] ) ? 'statement' : 'statement_own', 'sa-statement' );
	}

	/** Run the weekly checks now. */
	public static function handle_run(): void {
		self::guard( 'run' );
		Optima_Accessibility_Checks::run();
		self::back( 'run', 'sa-weekly' );
	}

	/** Save the at-launch checklist. */
	public static function handle_launch(): void {
		self::guard( 'launch' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$in = isset( $_POST['launch'] ) && is_array( $_POST['launch'] ) ? wp_unslash( $_POST['launch'] ) : array();
		// phpcs:enable
		$old = Optima_Accessibility_Checks::launch();
		$out = array();
		foreach ( array_keys( Optima_Accessibility_Checks::launch_items() ) as $key ) {
			$row  = (array) ( $in[ $key ] ?? array() );
			$done = empty( $row['done'] ) ? 0 : 1;
			$date = self::valid_date( sanitize_text_field( (string) ( $row['date'] ?? '' ) ) );
			$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			if ( $done && '' === $date ) {
				$date = current_time( 'Y-m-d' );
			}
			if ( $done && '' === $name ) {
				$name = wp_get_current_user()->display_name;
			}
			if ( ! $done && $old[ $key ]['done'] ) {
				$date = '';
				$name = '';
			}
			$out[ $key ] = array(
				'done' => $done,
				'date' => $date,
				'name' => $name,
			);
		}
		update_option( Optima_Accessibility_Checks::LAUNCH, $out, false );
		self::back( 'launch', 'sa-launch' );
	}

	/** Turn the automatic footer link on or off. */
	public static function handle_footer(): void {
		self::guard( 'footer' );
		$s                = Optima_Accessibility::settings();
		$s['footer_link'] = empty( $_POST['footer_link'] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		update_option( Optima_Accessibility::OPTION, $s );
		self::refresh_statement_result();
		self::back( 'footer', 'sa-footer' );
	}

	/** Add the statement page to a menu. */
	public static function handle_menu_add(): void {
		self::guard( 'menu_add' );
		$menu = absint( $_POST['menu'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$page = Optima_Accessibility::page();
		if ( ! $menu || ! $page || ! wp_get_nav_menu_object( $menu ) ) {
			self::back( 'menu_fail', 'sa-footer' );
		}
		foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
			if ( 'post_type' === $item->type && (int) $item->object_id === $page->ID ) {
				self::back( 'menu_already', 'sa-footer' );
			}
		}
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-object-id' => $page->ID,
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-title'     => __( 'Accessibility', 'optima-accessibility' ),
				'menu-item-status'    => 'publish',
			)
		);
		self::refresh_statement_result();
		self::back( 'menu', 'sa-footer' );
	}

	/** Keep the statement row current after a change on this screen. */
	private static function refresh_statement_result(): void {
		$results = Optima_Accessibility_Checks::results();
		if ( $results ) {
			$results['statement'] = Optima_Accessibility_Checks::statement_check();
			update_option( Optima_Accessibility_Checks::RESULTS, $results, false );
		}
	}

	/* --------------------------------------------------------------- screen */

	private static function messages(): void {
		$msg  = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$list = array(
			'statement'    => array( 'success', __( 'Saved. The Accessibility page is published with the statement.', 'optima-accessibility' ) ),
			'statement_own' => array( 'success', __( 'Saved. This site already has its own Accessibility page, so we kept its words exactly as they are and use it as the statement.', 'optima-accessibility' ) ),
			'run'          => array( 'success', __( 'Checks finished. Results are below.', 'optima-accessibility' ) ),
			'launch'       => array( 'success', __( 'At-launch checklist saved.', 'optima-accessibility' ) ),
			'footer'       => array( 'success', __( 'Footer link setting saved.', 'optima-accessibility' ) ),
			'menu'         => array( 'success', __( 'The Accessibility page was added to that menu.', 'optima-accessibility' ) ),
			'menu_already' => array( 'info', __( 'That menu already links to the Accessibility page.', 'optima-accessibility' ) ),
			'menu_fail'    => array( 'error', __( 'Pick a menu, and create the Accessibility page first.', 'optima-accessibility' ) ),
		);
		if ( isset( $list[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $list[ $msg ][0] ), esc_html( $list[ $msg ][1] ) );
		}
	}

	private static function edit_link( int $id ): string {
		$title = get_the_title( $id );
		$title = '' !== $title ? $title : __( '(no title)', 'optima-accessibility' );
		$edit  = get_edit_post_link( $id );
		return $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
	}

	/**
	 * One weekly row.
	 *
	 * @param string $title   Check name.
	 * @param string $state   pass, fix or none.
	 * @param string $detail  One line under the name.
	 * @param array  $items   Lines for the details list (already escaped HTML).
	 */
	private static function row( string $title, string $state, string $detail, array $items = array() ): void {
		$pill = array(
			'pass' => array( 'is-done', __( 'Pass', 'optima-accessibility' ) ),
			'fix'  => array( 'is-received', __( 'Fix', 'optima-accessibility' ) ),
			'none' => array( '', __( 'Not checked', 'optima-accessibility' ) ),
		)[ $state ];
		?>
		<li class="sa-row is-<?php echo esc_attr( $state ); ?>">
			<span class="sa-icon" aria-hidden="true"><?php echo 'pass' === $state ? '&#10003;' : ( 'fix' === $state ? '!' : '&#8211;' ); ?></span>
			<div class="sa-body">
				<p class="sa-name"><?php echo esc_html( $title ); ?></p>
				<p class="sa-detail"><?php echo esc_html( $detail ); ?></p>
				<?php if ( $items ) : ?>
					<details class="sa-more">
						<summary><?php esc_html_e( 'Show where', 'optima-accessibility' ); ?></summary>
						<ul>
							<?php foreach ( $items as $line ) : ?>
								<li><?php echo wp_kses_post( $line ); ?></li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			</div>
			<span class="sh-pill <?php echo esc_attr( $pill[0] ); ?>"><?php echo esc_html( $pill[1] ); ?></span>
		</li>
		<?php
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = Optima_Accessibility::settings();
		$page    = Optima_Accessibility::page();
		$url     = Optima_Accessibility::page_url();
		$results = Optima_Accessibility_Checks::results();
		$ran     = ! empty( $results['time'] );
		$launch  = Optima_Accessibility_Checks::launch();
		$items   = Optima_Accessibility_Checks::launch_items();
		$st      = $results['statement'] ?? Optima_Accessibility_Checks::statement_check();

		$to_fix = max( 0, self::to_fix() );
		$launch_done = count( array_filter( wp_list_pluck( $launch, 'done' ) ) );

		Optima_Admin_UI::open(
			__( 'Accessibility', 'optima-accessibility' ),
			__( 'A plain statement page that tells visitors how to report a barrier, weekly checks for the common issues a computer can find, and a short checklist for what needs a person at launch.', 'optima-accessibility' ),
			__( 'Site health · Accessibility', 'optima-accessibility' )
		);
		self::messages();
		?>
		<div class="sh-grid sh-grid-3">
			<section class="sh-box sh-check is-<?php echo $url ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'Statement page', 'optima-accessibility' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo $url ? esc_html__( 'Published', 'optima-accessibility' ) : esc_html__( 'Not created yet', 'optima-accessibility' ); ?></p>
				<p class="sh-note">
					<?php if ( $url ) : ?>
						<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'View the page', 'optima-accessibility' ); ?></a>
					<?php else : ?>
						<a href="#sa-statement"><?php esc_html_e( 'Fill in the statement below to create it', 'optima-accessibility' ); ?></a>
					<?php endif; ?>
				</p>
			</section>
			<section class="sh-box sh-check is-<?php echo ! $ran ? 'warn' : ( $to_fix ? 'warn' : 'good' ); ?>">
				<p class="sh-label"><?php esc_html_e( 'Weekly checks', 'optima-accessibility' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span>
					<?php
					if ( ! $ran ) {
						esc_html_e( 'Not run yet', 'optima-accessibility' );
					} elseif ( $to_fix ) {
						/* translators: %d: number of checks */
						echo esc_html( sprintf( _n( '%d check to fix', '%d checks to fix', $to_fix, 'optima-accessibility' ), $to_fix ) );
					} else {
						esc_html_e( 'All four pass', 'optima-accessibility' );
					}
					?>
				</p>
				<p class="sh-note">
					<?php
					$next = wp_next_scheduled( Optima_Accessibility_Checks::CRON );
					if ( $next ) {
						/* translators: %s: date */
						echo esc_html( sprintf( __( 'Next run %s', 'optima-accessibility' ), wp_date( get_option( 'date_format' ), $next ) ) );
					} else {
						esc_html_e( 'Runs once a week.', 'optima-accessibility' );
					}
					?>
				</p>
			</section>
			<section class="sh-box sh-check is-<?php echo count( $items ) === $launch_done ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'At launch', 'optima-accessibility' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span>
					<?php
					/* translators: 1: done, 2: total */
					echo esc_html( sprintf( __( '%1$d of %2$d reviewed', 'optima-accessibility' ), $launch_done, count( $items ) ) );
					?>
				</p>
				<p class="sh-note"><a href="#sa-launch"><?php esc_html_e( 'Color contrast and keyboard use', 'optima-accessibility' ); ?></a></p>
			</section>
		</div>

		<?php /* ------------------------------------------------ Weekly */ ?>
		<div class="sh-section-head sa-head" id="sa-weekly">
			<div>
				<p class="sh-eyebrow"><?php esc_html_e( 'Weekly', 'optima-accessibility' ); ?></p>
				<h2><?php esc_html_e( 'Weekly checks', 'optima-accessibility' ); ?></h2>
				<p class="sh-note">
					<?php
					if ( $ran ) {
						printf(
							/* translators: 1: date, 2: number of pages and posts */
							esc_html( _n( 'Checked %1$s across %2$d published page or post.', 'Checked %1$s across %2$d published pages and posts.', (int) $results['scanned'], 'optima-accessibility' ) ),
							esc_html( wp_date( get_option( 'date_format' ), (int) $results['time'] ) ),
							(int) $results['scanned']
						);
					} else {
						esc_html_e( 'The first run happens within the hour, or run it now.', 'optima-accessibility' );
					}
					?>
				</p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="optima_a11y_run">
				<?php wp_nonce_field( 'optima_a11y_run' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Run the checks now', 'optima-accessibility' ); ?></button>
			</form>
		</div>
		<section class="sh-box">
			<ul class="sa-rows">
				<?php
				// Image descriptions.
				if ( ! $ran ) {
					self::row( __( 'Image descriptions', 'optima-accessibility' ), 'none', __( 'Every image has alt text, or is marked as decorative', 'optima-accessibility' ) );
				} elseif ( $results['images'] ) {
					$total = 0;
					$lines = array();
					foreach ( $results['images'] as $hit ) {
						$total += (int) $hit['count'] + (int) $hit['featured'];
						$bits   = array();
						if ( $hit['count'] ) {
							/* translators: %d: number of images */
							$bits[] = sprintf( _n( '%d image with no alt text', '%d images with no alt text', (int) $hit['count'], 'optima-accessibility' ), (int) $hit['count'] );
						}
						if ( $hit['featured'] ) {
							$bits[] = __( 'the featured image has no description', 'optima-accessibility' );
						}
						$lines[] = self::edit_link( (int) $hit['id'] ) . ': ' . esc_html( implode( ', ', $bits ) );
					}
					/* translators: 1: images, 2: pages */
					self::row( __( 'Image descriptions', 'optima-accessibility' ), 'fix', sprintf( _n( '%1$d image on %2$d page has no alt text', '%1$d images on %2$d pages have no alt text', $total, 'optima-accessibility' ), $total, count( $results['images'] ) ), $lines );
				} else {
					self::row( __( 'Image descriptions', 'optima-accessibility' ), 'pass', __( 'Every image has alt text, or is marked as decorative', 'optima-accessibility' ) );
				}

				// Heading order.
				if ( ! $ran ) {
					self::row( __( 'Heading order', 'optima-accessibility' ), 'none', __( 'Headings step down one level at a time', 'optima-accessibility' ) );
				} elseif ( $results['headings'] ) {
					$lines = array();
					foreach ( $results['headings'] as $hit ) {
						$bits = array();
						foreach ( $hit['skips'] as $skip ) {
							$bits[] = 1 === (int) $skip['to']
								? __( 'a second main heading (H1) in the content', 'optima-accessibility' )
								/* translators: 1: from level, 2: to level */
								: sprintf( __( 'jumps from H%1$d to H%2$d', 'optima-accessibility' ), (int) $skip['from'], (int) $skip['to'] );
						}
						$lines[] = self::edit_link( (int) $hit['id'] ) . ': ' . esc_html( implode( ', ', array_unique( $bits ) ) );
					}
					/* translators: %d: pages */
					self::row( __( 'Heading order', 'optima-accessibility' ), 'fix', sprintf( _n( '%d page skips a heading level', '%d pages skip a heading level', count( $lines ), 'optima-accessibility' ), count( $lines ) ), $lines );
				} else {
					self::row( __( 'Heading order', 'optima-accessibility' ), 'pass', __( 'Headings step down one level at a time', 'optima-accessibility' ) );
				}

				// Form labels.
				if ( ! $ran ) {
					self::row( __( 'Form labels', 'optima-accessibility' ), 'none', __( 'Every form field has a label', 'optima-accessibility' ) );
				} elseif ( $results['forms'] ) {
					$lines = array();
					$total = 0;
					foreach ( $results['forms'] as $hit ) {
						$total  += count( $hit['fields'] );
						$lines[] = self::edit_link( (int) $hit['id'] ) . ': ' . esc_html( implode( ', ', $hit['fields'] ) );
					}
					/* translators: %d: fields */
					self::row( __( 'Form labels', 'optima-accessibility' ), 'fix', sprintf( _n( '%d form field has no label', '%d form fields have no label', $total, 'optima-accessibility' ), $total ), $lines );
				} else {
					self::row( __( 'Form labels', 'optima-accessibility' ), 'pass', __( 'Every form field we can see has a label', 'optima-accessibility' ) );
				}

				// Statement page.
				if ( ! $st['exists'] ) {
					self::row( __( 'Statement page', 'optima-accessibility' ), 'fix', __( 'No published Accessibility page yet', 'optima-accessibility' ) );
				} elseif ( ! $st['linked'] ) {
					self::row( __( 'Statement page', 'optima-accessibility' ), 'fix', __( 'Published, but no link to it was found on the site', 'optima-accessibility' ) );
				} elseif ( ! $st['reviewed'] ) {
					self::row( __( 'Statement page', 'optima-accessibility' ), 'fix', __( 'Linked, but not reviewed in the last year', 'optima-accessibility' ) );
				} else {
					self::row( __( 'Statement page', 'optima-accessibility' ), 'pass', __( 'Published, linked and reviewed this year', 'optima-accessibility' ) );
				}
				?>
			</ul>
			<p class="sh-note sa-foot"><?php esc_html_e( 'Automated checks catch the common issues. We review color contrast and keyboard use by hand at launch, since those need a real browser.', 'optima-accessibility' ); ?></p>
		</section>

		<?php /* ---------------------------------------------- At launch */ ?>
		<div class="sh-section-head" id="sa-launch">
			<p class="sh-eyebrow"><?php esc_html_e( 'At launch', 'optima-accessibility' ); ?></p>
			<h2><?php esc_html_e( 'Checks a person does in a real browser', 'optima-accessibility' ); ?></h2>
			<p class="sh-note"><?php esc_html_e( 'Tick each one when it is done, with the date and who did it. Redo them after a redesign or a new page template.', 'optima-accessibility' ); ?></p>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-box">
			<input type="hidden" name="action" value="optima_a11y_launch">
			<?php wp_nonce_field( 'optima_a11y_launch' ); ?>
			<ul class="sa-rows">
				<?php foreach ( $items as $key => $item ) : ?>
					<?php $row = $launch[ $key ]; ?>
					<li class="sa-row sa-launch is-<?php echo $row['done'] ? 'pass' : 'none'; ?>">
						<label class="sa-tick" for="sa-<?php echo esc_attr( $key ); ?>-done">
							<input type="checkbox" id="sa-<?php echo esc_attr( $key ); ?>-done" name="launch[<?php echo esc_attr( $key ); ?>][done]" value="1" <?php checked( $row['done'] ); ?>>
							<span class="screen-reader-text">
								<?php
								/* translators: %s: check name */
								echo esc_html( sprintf( __( '%s reviewed', 'optima-accessibility' ), $item['title'] ) );
								?>
							</span>
						</label>
						<div class="sa-body">
							<p class="sa-name"><?php echo esc_html( $item['title'] ); ?></p>
							<p class="sa-detail"><?php echo esc_html( $item['how'] ); ?></p>
							<div class="sa-by">
								<label for="sa-<?php echo esc_attr( $key ); ?>-date"><?php esc_html_e( 'Date', 'optima-accessibility' ); ?>
									<input type="date" id="sa-<?php echo esc_attr( $key ); ?>-date" name="launch[<?php echo esc_attr( $key ); ?>][date]" value="<?php echo esc_attr( $row['date'] ); ?>">
								</label>
								<label for="sa-<?php echo esc_attr( $key ); ?>-name"><?php esc_html_e( 'Reviewed by', 'optima-accessibility' ); ?>
									<input type="text" id="sa-<?php echo esc_attr( $key ); ?>-name" name="launch[<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $row['name'] ); ?>" placeholder="<?php echo esc_attr( wp_get_current_user()->display_name ); ?>">
								</label>
							</div>
						</div>
						<span class="sh-pill <?php echo $row['done'] ? 'is-done' : ''; ?>"><?php echo $row['done'] ? esc_html__( 'Done', 'optima-accessibility' ) : esc_html__( 'To do', 'optima-accessibility' ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="sh-note sa-foot"><?php esc_html_e( 'When both are ticked, the statement adds a line saying color contrast and keyboard use are reviewed by hand.', 'optima-accessibility' ); ?></p>
			<?php submit_button( __( 'Save the checklist', 'optima-accessibility' ) ); ?>
		</form>
		<script>
		// Ticking a check fills in today and the signed-in name right away, the
		// same values a save would use, so the row never looks half done.
		document.querySelectorAll( '.sa-launch input[type="checkbox"]' ).forEach( function ( box ) {
			box.addEventListener( 'change', function () {
				var row  = box.closest( '.sa-launch' );
				var date = row.querySelector( 'input[type="date"]' );
				var name = row.querySelector( 'input[type="text"]' );
				var pill = row.querySelector( '.sh-pill' );
				if ( box.checked ) {
					if ( ! date.value ) { date.value = <?php echo wp_json_encode( current_time( 'Y-m-d' ) ); ?>; }
					if ( ! name.value ) { name.value = name.placeholder; }
				}
				pill.classList.toggle( 'is-done', box.checked );
				pill.textContent = box.checked ? <?php echo wp_json_encode( __( 'Done once saved', 'optima-accessibility' ) ); ?> : <?php echo wp_json_encode( __( 'To do', 'optima-accessibility' ) ); ?>;
			} );
		} );
		</script>

		<?php /* ---------------------------------------------- Statement */ ?>
		<div class="sh-section-head" id="sa-statement">
			<p class="sh-eyebrow"><?php esc_html_e( 'Statement', 'optima-accessibility' ); ?></p>
			<h2><?php esc_html_e( 'The accessibility statement', 'optima-accessibility' ); ?></h2>
			<p class="sh-note"><?php esc_html_e( 'Saving creates the Accessibility page, or updates it if it already exists. The wording is plain and honest: it says the site aims for the standard and how to report a barrier, and never claims full compliance.', 'optima-accessibility' ); ?></p>
			<?php if ( ! empty( Optima_Accessibility::settings()['own_page'] ) ) : ?>
				<p class="sh-note"><strong><?php esc_html_e( 'This site uses its own Accessibility page.', 'optima-accessibility' ); ?></strong> <?php esc_html_e( 'Its words stay exactly as written. Edit that page to change them; the settings below are kept for reference only.', 'optima-accessibility' ); ?></p>
			<?php endif; ?>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-box">
			<input type="hidden" name="action" value="optima_a11y_statement">
			<?php wp_nonce_field( 'optima_a11y_statement' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Business name', 'optima-accessibility' ); ?></th>
					<td><strong><?php echo esc_html( Optima_Accessibility::business_name() ); ?></strong>
						<p class="description">
							<?php
							echo Optima_Accessibility::has_core()
								? esc_html__( 'From the business info in Optima Core. Change it there.', 'optima-accessibility' )
								: esc_html__( 'The site title, from Settings, General.', 'optima-accessibility' );
							?>
						</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="sa-email"><?php esc_html_e( 'Email for barrier reports', 'optima-accessibility' ); ?></label></th>
					<td><input id="sa-email" type="email" class="regular-text" name="sa[email]" value="<?php echo esc_attr( $s['email'] ); ?>" placeholder="<?php echo esc_attr( Optima_Accessibility::email() ); ?>">
						<p class="description"><?php esc_html_e( 'Leave blank to use the email in the business info. Use an inbox someone reads.', 'optima-accessibility' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="sa-phone"><?php esc_html_e( 'Phone', 'optima-accessibility' ); ?></label></th>
					<td><input id="sa-phone" type="tel" class="regular-text" name="sa[phone]" value="<?php echo esc_attr( $s['phone'] ); ?>" placeholder="<?php echo esc_attr( Optima_Accessibility::phone() ); ?>">
						<p class="description"><?php esc_html_e( 'Leave blank to use the phone in the business info. A second way to reach you helps people who cannot use email.', 'optima-accessibility' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="sa-days"><?php esc_html_e( 'Reply within', 'optima-accessibility' ); ?></label></th>
					<td><input id="sa-days" type="number" min="0" max="30" step="1" class="small-text" name="sa[response_days]" value="<?php echo esc_attr( (string) (int) $s['response_days'] ); ?>"> <?php esc_html_e( 'business days', 'optima-accessibility' ); ?>
						<p class="description"><?php esc_html_e( 'The time you can really keep to. Leave at 0 to leave the reply time out of the statement.', 'optima-accessibility' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Other formats', 'optima-accessibility' ); ?></th>
					<td><label for="sa-formats"><input id="sa-formats" type="checkbox" name="sa[other_formats]" value="1" <?php checked( $s['other_formats'] ); ?>> <?php esc_html_e( 'Say we can send any page in another format', 'optima-accessibility' ); ?></label>
						<p class="description"><?php esc_html_e( 'Only turn this on if the business will really do it when asked.', 'optima-accessibility' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Standard', 'optima-accessibility' ); ?></th>
					<td><strong><?php echo esc_html( Optima_Accessibility::STANDARD ); ?></strong>
						<p class="description"><?php esc_html_e( 'The statement says the site aims to meet this standard.', 'optima-accessibility' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="sa-reviewed"><?php esc_html_e( 'Last reviewed', 'optima-accessibility' ); ?></label></th>
					<td><input id="sa-reviewed" type="date" name="sa[last_reviewed]" value="<?php echo esc_attr( '' !== $s['last_reviewed'] ? $s['last_reviewed'] : current_time( 'Y-m-d' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Shown at the bottom of the statement as the month and year. Review it at least once a year.', 'optima-accessibility' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button( $page ? __( 'Save and update the page', 'optima-accessibility' ) : __( 'Save and create the page', 'optima-accessibility' ) ); ?>
		</form>

		<?php /* ------------------------------------------------- Footer */ ?>
		<div class="sh-section-head" id="sa-footer">
			<p class="sh-eyebrow"><?php esc_html_e( 'Footer link', 'optima-accessibility' ); ?></p>
			<h2><?php esc_html_e( 'Link to the statement from every page', 'optima-accessibility' ); ?></h2>
			<p class="sh-note"><?php esc_html_e( 'Visitors expect it in the footer, next to the privacy policy. Use whichever way fits the theme.', 'optima-accessibility' ); ?></p>
		</div>
		<div class="sh-grid sh-grid-3">
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Add it to a menu', 'optima-accessibility' ); ?></h2>
				<?php $menus = wp_get_nav_menus(); ?>
				<?php if ( ! $page ) : ?>
					<p><?php esc_html_e( 'Create the statement page first.', 'optima-accessibility' ); ?></p>
				<?php elseif ( ! $menus ) : ?>
					<p><?php esc_html_e( 'This site has no classic menus. Use the shortcode instead.', 'optima-accessibility' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Pick the footer menu. It counts as linked once that menu is shown in a menu location.', 'optima-accessibility' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sa-inline-form">
						<input type="hidden" name="action" value="optima_a11y_menu_add">
						<?php wp_nonce_field( 'optima_a11y_menu_add' ); ?>
						<label for="sa-menu" class="screen-reader-text"><?php esc_html_e( 'Menu', 'optima-accessibility' ); ?></label>
						<select id="sa-menu" name="menu">
							<?php foreach ( $menus as $menu ) : ?>
								<option value="<?php echo esc_attr( (string) $menu->term_id ); ?>" <?php selected( false !== stripos( $menu->name, 'footer' ) ); ?>><?php echo esc_html( $menu->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Add the link', 'optima-accessibility' ); ?></button>
					</form>
				<?php endif; ?>
			</section>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Use the shortcode', 'optima-accessibility' ); ?></h2>
				<p><?php esc_html_e( 'Put this in a footer widget, a Shortcode block in the footer template, or any page.', 'optima-accessibility' ); ?></p>
				<p><code>[<?php echo esc_html( Optima_Accessibility::LINK_CODE ); ?>]</code></p>
			</section>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Print it automatically', 'optima-accessibility' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="optima_a11y_footer">
					<?php wp_nonce_field( 'optima_a11y_footer' ); ?>
					<p><label for="sa-footer-link"><input id="sa-footer-link" type="checkbox" name="footer_link" value="1" <?php checked( $s['footer_link'] ); ?>> <?php esc_html_e( 'Add a small link at the very bottom of every page', 'optima-accessibility' ); ?></label></p>
					<p class="description"><?php esc_html_e( 'For themes with no menu or widget in the footer. It sits below the theme footer.', 'optima-accessibility' ); ?></p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'optima-accessibility' ); ?></button></p>
				</form>
			</section>
		</div>
		<?php
		Optima_Admin_UI::close();
	}
}
