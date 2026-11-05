<?php
/**
 * The hub's own screens: Updates and Features (Build, operators only), Request
 * a change (Inbox) and Site health. Overview and Inbox have their own classes.
 *
 * Placement follows the dashboard standard (one Build section per site): the
 * screens go inside the site's existing Build menu when there is one (the
 * Optima Digital theme's "Optima Build", Recon's "Recon Build", or Optima Core's
 * "Optima" menu), and only otherwise create a top-level "Site Build" section.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Admin {

	const PAGE        = 'site-build-features';
	const PLAN_ACTION = 'optima_hub_plan';

	/** Existing Build menus, in the order we prefer them. */
	const PARENTS = array( 'optima-build', 'recon-build', 'optima' );

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 15 );
		add_action( 'admin_init', array( __CLASS__, 'operators_only' ) );
		add_filter( 'update_plugin_complete_actions', array( __CLASS__, 'back_to_build' ) );
		add_filter( 'update_theme_complete_actions', array( __CLASS__, 'back_to_build' ) );
		add_filter( 'optima_admin_nav_hint', array( __CLASS__, 'nav_hint' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_' . self::PLAN_ACTION, array( __CLASS__, 'save_plan' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_HUB_FILE ), array( __CLASS__, 'action_link' ) );
	}

	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( self::PARENTS as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'site-build';
	}

	/**
	 * The admin page slug the screen actually lives at: "site-build" when the
	 * hub made its own section, otherwise PAGE inside another Build menu.
	 * Saved when the menu is built, because admin-post.php redirects run
	 * before any menu exists.
	 */
	public static function page_slug(): string {
		$slug = (string) get_option( 'optima_hub_page', self::PAGE );
		return in_array( $slug, array( self::PAGE, 'site-build' ), true ) ? $slug : self::PAGE;
	}

	public static function url( string $tab = 'features', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::page_slug(), 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu(): void {
		$parent = self::parent();
		if ( 'site-build' === $parent ) {
			$label = self::branded() ? __( 'Optima', 'optima-build' ) : __( 'Site Build', 'optima-build' );
			$icon  = self::branded()
				? 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#1272C4" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>' ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				: 'dashicons-admin-generic';
			add_menu_page( $label, $label, Optima_Hub_Lock::VIEW, 'site-build', array( __CLASS__, 'render' ), $icon, 3 );
			add_submenu_page( 'site-build', __( 'Features', 'optima-build' ), __( 'Features', 'optima-build' ), Optima_Hub_Lock::VIEW, 'site-build', array( __CLASS__, 'render' ) );
		}
		add_submenu_page( $parent, __( 'Features', 'optima-build' ), __( 'Features', 'optima-build' ), Optima_Hub_Lock::VIEW, self::PAGE, array( __CLASS__, 'render' ) );
		if ( 'site-build' === $parent ) {
			remove_submenu_page( 'site-build', self::PAGE ); // The section's first item already opens this screen.
		}
		// The other two tabs get their own sidebar entries, so nobody has to find the tabs first.
		$base = 'site-build' === $parent ? 'site-build' : self::PAGE;
		add_submenu_page( $parent, __( 'Request a change', 'optima-build' ), __( 'Request a change', 'optima-build' ), Optima_Hub_Lock::VIEW, $base . '&tab=requests', array( __CLASS__, 'render' ) );
		add_submenu_page( $parent, __( 'Site health', 'optima-build' ), __( 'Site health', 'optima-build' ), Optima_Hub_Lock::VIEW, $base . '&tab=health', array( __CLASS__, 'render' ) );
		add_submenu_page( $parent, __( 'Updates', 'optima-build' ), __( 'Updates', 'optima-build' ), Optima_Hub_Lock::VIEW, $base . '&tab=updates', array( __CLASS__, 'render' ) );
		$slug = 'site-build' === $parent ? 'site-build' : self::PAGE;
		if ( get_option( 'optima_hub_page' ) !== $slug ) {
			update_option( 'optima_hub_page', $slug, false );
		}
	}

	/**
	 * Build is for operators (plugin-first rule 9). Anyone else who opens
	 * Updates, Features, Design or Email by its address lands on Overview instead.
	 */
	public static function operators_only(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable
		$hub = in_array( $page, array( self::PAGE, 'site-build' ), true ) && in_array( $tab, array( '', 'features', 'updates' ), true );
		if ( ( $hub || in_array( $page, array( Optima_Hub_Design::PAGE, Optima_Hub_Mail::PAGE ), true ) ) && ! wp_doing_ajax() && ! Optima_Hub_Access::is_operator() ) {
			wp_safe_redirect( Optima_Hub_Overview::url() );
			exit;
		}
	}

	/** After a theme or plugin update from Build, the way back is to Build. */
	public static function back_to_build( $actions ) {
		if ( Optima_Hub_Access::is_operator() && is_array( $actions ) ) {
			$actions['optima_build'] = '<a href="' . esc_url( self::url( 'updates' ) ) . '">' . esc_html__( 'Back to Build', 'optima-build' ) . '</a>';
		}
		return $actions;
	}

	/** Live hints on the hub's own pills in the shared section nav. */
	public static function nav_hint( string $hint, string $slug ): string {
		if ( ! in_array( strtok( $slug, '&' ), array( self::PAGE, 'site-build' ), true ) ) {
			return $hint;
		}
		if ( false !== strpos( $slug, 'tab=health' ) ) {
			$ai = Optima_Hub_Health::ai_checks();
			/* translators: 1: checks passed, 2: total */
			return sprintf( __( 'AI %1$d/%2$d', 'optima-build' ), count( array_filter( $ai, static fn( $c ) => 'good' === $c['state'] ) ), count( $ai ) );
		}
		if ( false !== strpos( $slug, 'tab=requests' ) ) {
			$open = Optima_Hub_Inbox::requests()['count'];
			/* translators: %d: open requests */
			return $open ? sprintf( __( '%d open', 'optima-build' ), $open ) : '';
		}
		if ( false !== strpos( $slug, 'tab=updates' ) ) {
			$waiting = 0;
			foreach ( array_keys( Optima_Hub_Catalog::features() ) as $f ) {
				$waiting += '' !== ( Optima_Hub_Features::status( $f )['update'] ?? '' ) ? 1 : 0;
			}
			/* translators: %d: updates waiting */
			return $waiting ? sprintf( __( '%d ready', 'optima-build' ), $waiting ) : '';
		}
		$on = 0;
		foreach ( array_keys( Optima_Hub_Catalog::features() ) as $f ) {
			$on += 'on' === Optima_Hub_Features::status( $f )['state'] ? 1 : 0;
		}
		/* translators: %d: features on */
		return sprintf( __( '%d on', 'optima-build' ), $on );
	}

	/** Where each feature's own settings live, when it has a screen. */
	private static function settings_url( string $slug ): string {
		$pages = array(
			'optima-core'          => 'optima',
			'optima-aeo'           => 'optima-ai-search',
			'optima-optimize'      => 'optima-optimize',
			'optima-analytics'     => 'optima-analytics',
			'optima-chat'          => 'optima-chat',
			'optima-cards'         => 'optima-cards',
			'optima-reviews'       => 'optima-reviews-settings',
			'optima-redirects'     => 'optima-redirects',
			'optima-accessibility' => 'optima-accessibility',
			'optima-hours'         => 'optima-hours',
			'optima-contact'       => 'optima-contact',
			'optima-results'       => 'optima-results',
			'optima-team'          => 'optima-team',
			'optima-forms-guard'   => class_exists( 'Optima_Core_Admin' ) ? 'optima&tab=protect' : 'optima-forms-guard',
		);
		global $_registered_pages;
		$page = $pages[ $slug ] ?? '';
		if ( '' === $page || ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		$base = strtok( $page, '&' );
		foreach ( array_keys( (array) $_registered_pages ) as $hook ) {
			if ( substr( (string) $hook, -strlen( '_page_' . $base ) ) === '_page_' . $base ) {
				return admin_url( 'admin.php?page=' . $page );
			}
		}
		return '';
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Features', 'optima-build' ) . '</a>' );
		return $links;
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::PAGE ) || 'toplevel_page_site-build' === $hook ) {
			Optima_Admin_UI::enqueue( OPTIMA_HUB_URL . 'assets/optima-admin.css' );
		}
	}

	public static function save_plan(): void {
		check_admin_referer( self::PLAN_ACTION );
		if ( ! Optima_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can set its plan.', 'optima-build' ), 403 );
		}
		Optima_Hub_Access::set_plan( sanitize_key( wp_unslash( (string) ( $_POST['plan'] ?? '' ) ) ) );
		set_transient( 'optima_hub_notice_' . get_current_user_id(), array( 'message' => __( 'Plan saved.', 'optima-build' ), 'type' => 'success' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( 'features' ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( Optima_Hub_Lock::VIEW ) ) {
			return;
		}
		$tab  = sanitize_key( (string) ( $_GET['tab'] ?? 'features' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'features' => __( 'Features', 'optima-build' ),
			'requests' => __( 'Request a change', 'optima-build' ),
			'health'   => __( 'Site health', 'optima-build' ),
			'updates'  => __( 'Updates', 'optima-build' ),
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'features';
		$ledes = array(
			'features' => __( 'Every feature this site can use: what is on, what is ready to turn on, and the version of each.', 'optima-build' ),
			'requests' => Optima_Hub_Access::is_operator()
				? __( 'Every change request for this site and where it stands. Log one that came in by email so it is tracked here too.', 'optima-build' )
				: __( 'Tell the web team what you would like changed. Each request shows here with its status.', 'optima-build' ),
			'health'   => __( 'Every check on this site, scored by group. Copy the whole report for a chat in one click.', 'optima-build' ),
			'updates'  => __( 'Everything to do for an update, top to bottom. Every button comes back to this screen.', 'optima-build' ),
		);
		// The eyebrow names the screen's group (Build, Inbox, Site health), as the sidebar does.
		Optima_Admin_UI::open( $tabs[ $tab ], $ledes[ $tab ] );
		?>
			<?php self::notice(); ?>
			<?php
			if ( 'requests' === $tab ) {
				self::requests();
			} elseif ( 'health' === $tab ) {
				self::health();
			} elseif ( 'updates' === $tab ) {
				Optima_Hub_Updates::render();
			} else {
				self::features();
			}
			?>
		<?php
		Optima_Admin_UI::close();
	}

	/**
	 * Show the Optima mark and sign-off. On by default; partner-side or
	 * white-label sites turn it off in wp-config.php:
	 *   define( 'OPTIMA_HUB_BRAND', false );
	 */
	public static function branded(): bool {
		return ! defined( 'OPTIMA_HUB_BRAND' ) || (bool) OPTIMA_HUB_BRAND;
	}

	private static function notice(): void {
		$key    = 'optima_hub_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), esc_html( $notice['message'] ) );
		}
	}

	/* ------------------------------------------------------------------ */

	private static function features(): void {
		$operator = Optima_Hub_Access::is_operator();
		$features = Optima_Hub_Catalog::features();
		$plan     = Optima_Hub_Access::plan();
		$states   = array(
			'missing' => __( 'Not installed', 'optima-build' ),
			'off'     => __( 'Off', 'optima-build' ),
			'on'      => __( 'On', 'optima-build' ),
		);
		?>
		<?php
		// Sort every feature into one of three groups.
		$groups = array( 'on' => array(), 'ready' => array(), 'unused' => array() );
		foreach ( $features as $slug => $f ) {
			$st = Optima_Hub_Features::status( $slug );
			if ( $f['hidden'] && 'missing' === $st['state'] ) {
				continue; // One client's own plugin: only listed where it is installed.
			}
			$key                    = 'on' === $st['state'] ? 'on' : ( $f['blocked'] ? 'unused' : 'ready' );
			$groups[ $key ][ $slug ] = array( 'f' => $f, 's' => $st );
		}
		// The theme's setup file can say which features this client needs, in install
		// order, and which it doesn't. Everything else ready to add sorts by name.
		$fplan   = Optima_Hub_Setup::feature_plan();
		$picked  = array();
		$skipped = array();
		foreach ( $fplan['use'] as $slug ) {
			if ( isset( $groups['ready'][ $slug ] ) ) {
				$picked[ $slug ] = $groups['ready'][ $slug ];
				unset( $groups['ready'][ $slug ] );
			}
		}
		foreach ( $fplan['skip'] as $slug ) {
			if ( isset( $groups['ready'][ $slug ] ) ) {
				$skipped[ $slug ] = $groups['ready'][ $slug ];
				unset( $groups['ready'][ $slug ] );
			}
		}
		uasort( $groups['ready'], static fn( $a, $b ) => strcasecmp( $a['f']['name'], $b['f']['name'] ) );
		uasort( $skipped, static fn( $a, $b ) => strcasecmp( $a['f']['name'], $b['f']['name'] ) );
		$groups = array( 'on' => $groups['on'], 'picked' => $picked, 'ready' => $groups['ready'], 'skip' => $skipped, 'unused' => $groups['unused'] );
		$ai    = Optima_Hub_Health::ai_checks();
		$ready = count( array_filter( $ai, static fn( $c ) => 'good' === $c['state'] ) );
		?>
		<?php
		$plan_name = $plan ? ( Optima_Hub_Access::PLANS[ $plan ] ?? __( 'not set', 'optima-build' ) ) : __( 'not set', 'optima-build' );
		$ready_n   = count( $groups['picked'] ) + count( $groups['ready'] );
		Optima_Hub_Features::ensure_fresh();
		?>
		<section class="sh-box">
			<p class="sh-value" style="margin:0">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: features on, 2: ready to turn on, 3: plan name, 4: AI search checks passed, 5: total */
						__( '%1$d features on, %2$d ready to turn on. Plan: %3$s. Ready for AI search: %4$d of %5$d.', 'optima-build' ),
						count( $groups['on'] ),
						$ready_n,
						$plan_name,
						$ready,
						count( $ai )
					)
				);
				?>
			</p>
			<?php if ( $operator ) : ?>
				<div class="sh-ops" style="margin-top:12px">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::PLAN_ACTION ); ?>">
						<?php wp_nonce_field( self::PLAN_ACTION ); ?>
						<label for="sh-plan"><?php esc_html_e( 'This site\'s plan', 'optima-build' ); ?></label>
						<select id="sh-plan" name="plan">
							<option value=""><?php esc_html_e( 'Plan not set', 'optima-build' ); ?></option>
							<?php foreach ( Optima_Hub_Access::PLANS as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $plan, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Save plan', 'optima-build' ); ?></button>
					</form>
				</div>
				<?php if ( ! Optima_Hub_Access::can_install() ) : ?>
					<p class="sh-note"><?php esc_html_e( 'This site blocks plugin installs from the dashboard, so features that are not installed yet need to be uploaded by hand.', 'optima-build' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<?php
		$titles = array(
			'on'     => __( 'On this site', 'optima-build' ),
			'picked' => __( 'Recommended for this site, in install order', 'optima-build' ),
			'ready'  => $groups['picked'] ? __( 'Also available', 'optima-build' ) : ( $operator ? __( 'Ready to turn on', 'optima-build' ) : __( 'Available to add', 'optima-build' ) ),
			'skip'   => __( 'Not needed for this site', 'optima-build' ),
		);
		foreach ( $titles as $key => $title ) :
			if ( ! $groups[ $key ] ) {
				continue;
			}
			$step = 0;
			?>
			<div class="sh-section-head">
				<p class="sh-eyebrow"><?php echo esc_html( $title . ' · ' . count( $groups[ $key ] ) ); ?></p>
			</div>
			<div class="sh-grid<?php echo 'skip' === $key ? ' sh-skip' : ''; ?>">
				<?php foreach ( $groups[ $key ] as $slug => $row ) : ?>
					<?php self::card( $slug, $row['f'], $row['s'], $operator, 'picked' === $key ? ++$step : 0 ); ?>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		<style>.sh-skip .sh-card{opacity:.55}.sh-skip .sh-card:hover,.sh-skip .sh-card:focus-within{opacity:1}.sh-order{font-weight:600}.sh-card-foot{align-items:center}.sh-more{position:relative;margin-left:auto}.sh-more>summary{cursor:pointer;list-style:none;font-size:13px;font-weight:600;color:var(--sh-accent-text);padding:6px 4px}.sh-more>summary::-webkit-details-marker{display:none}.sh-more[open]>form{position:absolute;right:0;z-index:2;background:#fff;border:1px solid var(--sh-border);border-radius:12px;padding:6px;box-shadow:var(--sh-shadow-up);white-space:nowrap}</style>

		<?php if ( $groups['unused'] ) : ?>
			<section class="sh-box sh-unused">
				<p class="sh-label"><?php esc_html_e( 'Not used on this site', 'optima-build' ); ?></p>
				<ul>
					<?php foreach ( $groups['unused'] as $row ) : ?>
						<li><strong><?php echo esc_html( $row['f']['name'] ); ?></strong> <span><?php echo esc_html( $row['f']['block_note'] ? $row['f']['block_note'] : __( 'The theme already does this.', 'optima-build' ) ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php
		$log = Optima_Hub_Features::recent_log();
		if ( $operator && $log ) :
			$verbs = array( 'on' => __( 'Turned on', 'optima-build' ), 'off' => __( 'Turned off', 'optima-build' ), 'back' => __( 'Went back a version', 'optima-build' ), 'channel' => __( 'Release channel', 'optima-build' ), 'update' => __( 'Updated', 'optima-build' ) );
			?>
			<section class="sh-box" id="sh-log">
				<p class="sh-label"><?php esc_html_e( 'Recent changes', 'optima-build' ); ?></p>
				<table class="sh-table">
					<tbody>
					<?php foreach ( array_slice( $log, 0, 10 ) as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, g:i a', (int) $row['time'] ) ); ?></td>
							<td><?php echo esc_html( $row['who'] ); ?></td>
							<?php $what = 'channel' === $row['do'] ? ( 'test' === $row['note'] ? __( 'Test', 'optima-build' ) : __( 'Stable', 'optima-build' ) ) : ( 'theme' === $row['slug'] ? __( 'Theme', 'optima-build' ) : ( 'optima-build' === $row['slug'] ? __( 'Optima Build', 'optima-build' ) : ( $features[ $row['slug'] ]['name'] ?? $row['slug'] ) ) ); ?>
							<td><?php echo esc_html( ( $verbs[ $row['do'] ] ?? $row['do'] ) . ': ' . $what ); ?></td>
							<td><?php echo $row['ok'] ? esc_html__( 'Done', 'optima-build' ) : esc_html( __( 'Failed: ', 'optima-build' ) . $row['note'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>
		<?php
	}


	/** One feature card. */
	private static function card( string $slug, array $f, array $s, bool $operator, int $step = 0 ): void {
		$states   = array(
			'missing' => __( 'Not installed', 'optima-build' ),
			'off'     => __( 'Off', 'optima-build' ),
			'on'      => __( 'On', 'optima-build' ),
		);
		$included = Optima_Hub_Access::included( $f['tier'] );
		?>
		<article class="sh-card is-<?php echo esc_attr( $s['state'] ); ?>" data-feature="<?php echo esc_attr( $slug ); ?>">
			<div class="sh-cardhead">
				<h2><?php if ( $step ) : ?><span class="sh-order"><?php echo esc_html( $step . '. ' ); ?></span><?php endif; ?><?php echo esc_html( $f['name'] ); ?></h2>
				<span class="sh-pill is-<?php echo esc_attr( $s['state'] ); ?>"><?php echo esc_html( $states[ $s['state'] ] ); ?></span>
			</div>
			<p class="sh-summary"><?php echo esc_html( $f['summary'] ); ?></p>
			<p class="sh-meta">
				<span class="sh-tier<?php echo $included ? ' is-included' : ''; ?>"><?php echo esc_html( Optima_Hub_Access::tier_label( $f['tier'] ) ); ?></span>
				<?php if ( $f['law'] ) : ?><span class="sh-tag"><?php esc_html_e( 'Built for law firms', 'optima-build' ); ?></span><?php endif; ?>
				<?php if ( $s['version'] ) : ?><span class="sh-ver"><?php echo esc_html( 'v' . $s['version'] ); ?></span><?php endif; ?>
				<?php if ( $s['update'] ) : ?>
					<span class="sh-tag is-update"><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Update ready: v%s', 'optima-build' ), $s['update'] ) ); ?></span>
					<?php if ( ! empty( $s['test'] ) ) : ?><span class="sh-tag is-held"><?php esc_html_e( 'Test release', 'optima-build' ); ?></span><?php endif; ?>
				<?php elseif ( $s['version'] && $s['checked'] ) : ?>
					<span class="sh-tag is-current"><?php esc_html_e( 'Up to date', 'optima-build' ); ?></span>
				<?php endif; ?>
				<?php if ( Optima_Hub_Features::held( $slug ) ) : ?><span class="sh-tag is-held"><?php esc_html_e( 'Updates paused', 'optima-build' ); ?></span><?php endif; ?>
			</p>
			<div class="sh-actions sh-card-foot">
				<?php
				if ( $operator ) {
					if ( 'on' === $s['state'] ) {
						$settings = self::settings_url( $slug );
						if ( '' !== $settings ) {
							echo '<a class="button button-primary" href="' . esc_url( $settings ) . '">' . esc_html__( 'Settings', 'optima-build' ) . '</a>';
						}
						echo Optima_Hub_Features::button( $slug, 'off', __( 'Turn off', 'optima-build' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						if ( $s['update'] ) {
							echo Optima_Hub_Features::button( $slug, 'update', __( 'Update now', 'optima-build' ), 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						if ( $s['previous'] ) {
							// Rolling back is rare: it sits in a small menu, behind a confirm step.
							echo '<details class="sh-more"><summary>' . esc_html__( 'More', 'optima-build' ) . '</summary>';
							echo str_replace( // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
								'<button type="submit"',
								'<button type="submit" onclick="return confirm(' . esc_attr( wp_json_encode( sprintf( /* translators: %s: version */ __( 'Go back to v%s? Automatic updates for it pause until a newer release comes out.', 'optima-build' ), $s['previous'] ) ) ) . ')"',
								Optima_Hub_Features::button( $slug, 'back', sprintf( /* translators: %s: version */ __( 'Go back to v%s', 'optima-build' ), $s['previous'] ), 'button sh-quiet' )
							);
							echo '</details>';
						}
					} else {
						echo Optima_Hub_Features::button( $slug, 'on', __( 'Turn on', 'optima-build' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
						if ( 'off' === $s['state'] && $s['update'] ) {
							echo Optima_Hub_Features::button( $slug, 'update', __( 'Update now', 'optima-build' ), 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
					}
				}
				?>
			</div>
		</article>
		<?php
	}

	/* ------------------------------------------------------------------ */

	private static function requests(): void {
		$operator = Optima_Hub_Access::is_operator();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- prefill only.
		$from = isset( $_GET['from'] ) ? esc_url_raw( rawurldecode( wp_unslash( (string) $_GET['from'] ) ) ) : '';
		$ask  = isset( $_GET['ask'] ) ? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['ask'] ) ) ) : '';
		// phpcs:enable
		$items = Optima_Hub_Requests::all();
		ob_start();
		?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="sh-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( Optima_Hub_Requests::ACTION ); ?>">
					<input type="hidden" name="do" value="new">
					<?php wp_nonce_field( Optima_Hub_Requests::ACTION ); ?>
					<label for="sh-what"><?php echo esc_html( $operator ? __( 'What was asked for?', 'optima-build' ) : __( 'What would you like changed?', 'optima-build' ) ); ?></label>
					<textarea id="sh-what" name="what" rows="5" required><?php echo esc_textarea( $ask ); ?></textarea>
					<label for="sh-page"><?php esc_html_e( 'Which page? (optional)', 'optima-build' ); ?></label>
					<input id="sh-page" name="page" type="url" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" value="<?php echo esc_attr( $from ); ?>">
					<label for="sh-when"><?php esc_html_e( 'How soon?', 'optima-build' ); ?></label>
					<select id="sh-when" name="when">
						<?php foreach ( Optima_Hub_Requests::WHEN as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( current_user_can( 'upload_files' ) ) : ?>
						<label for="sh-file"><?php esc_html_e( 'A screenshot or file (optional)', 'optima-build' ); ?></label>
						<input id="sh-file" name="file" type="file">
					<?php endif; ?>
					<p class="submit"><button type="submit" class="button button-primary"><?php echo esc_html( $operator ? __( 'Log request', 'optima-build' ) : __( 'Send request', 'optima-build' ) ); ?></button></p>
				</form>
		<?php
		$form = (string) ob_get_clean();
		?>
		<?php if ( $operator ) : ?>
			<details class="sh-box sh-log-request"<?php echo '' !== $ask ? ' open' : ''; ?>>
				<summary class="button button-primary"><?php esc_html_e( 'Log a request', 'optima-build' ); ?></summary>
				<p class="sh-note"><?php esc_html_e( 'For a request that came in by email or phone, so every request is tracked in one place.', 'optima-build' ); ?></p>
				<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above. ?>
			</details>
			<style>.sh .sh-log-request>summary{display:inline-flex;list-style:none}.sh .sh-log-request>summary::-webkit-details-marker{display:none}.sh .sh-log-request:not([open]){background:transparent;border:0;box-shadow:none;padding:0}</style>
		<?php endif; ?>
		<div class="sh-grid<?php echo $operator ? '' : ' sh-grid-2'; ?>">
			<?php if ( ! $operator ) : ?>
				<section class="sh-box">
					<p class="sh-label"><?php esc_html_e( 'New request', 'optima-build' ); ?></p>
					<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above. ?>
				</section>
			<?php endif; ?>
			<section class="sh-box">
				<p class="sh-label"><?php echo esc_html( $operator ? __( 'Requests', 'optima-build' ) : __( 'Your requests', 'optima-build' ) ); ?></p>
				<?php if ( ! $items ) : ?>
					<p class="sh-note"><?php esc_html_e( 'No requests yet. Each one shows here with its status.', 'optima-build' ); ?></p>
				<?php else : ?>
					<ul class="sh-requests">
						<?php foreach ( $items as $post ) : ?>
							<?php
							$status = (string) get_post_meta( $post->ID, Optima_Hub_Requests::META_STATUS, true );
							$status = isset( Optima_Hub_Requests::STATUSES[ $status ] ) ? $status : 'received';
							$page   = (string) get_post_meta( $post->ID, Optima_Hub_Requests::META_PAGE, true );
							?>
							<li>
								<div class="sh-cardhead">
									<strong><?php echo esc_html( get_the_title( $post ) ); ?></strong>
									<?php if ( $operator ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
											<input type="hidden" name="action" value="<?php echo esc_attr( Optima_Hub_Requests::ACTION ); ?>">
											<input type="hidden" name="do" value="status">
											<input type="hidden" name="request" value="<?php echo (int) $post->ID; ?>">
											<?php wp_nonce_field( Optima_Hub_Requests::ACTION ); ?>
											<label class="screen-reader-text" for="sh-status-<?php echo (int) $post->ID; ?>"><?php esc_html_e( 'Status', 'optima-build' ); ?></label>
											<select id="sh-status-<?php echo (int) $post->ID; ?>" name="status" onchange="this.form.submit()">
												<?php foreach ( Optima_Hub_Requests::STATUSES as $key => $label ) : ?>
													<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
											<noscript><button type="submit" class="button sh-quiet"><?php esc_html_e( 'Save', 'optima-build' ); ?></button></noscript>
										</form>
									<?php else : ?>
										<span class="sh-pill is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( Optima_Hub_Requests::STATUSES[ $status ] ); ?></span>
									<?php endif; ?>
								</div>
								<p class="sh-note"><?php echo esc_html( get_the_date( 'M j, Y', $post ) . ' · ' . get_the_author_meta( 'display_name', (int) $post->post_author ) ); ?><?php echo $page ? ' · <a href="' . esc_url( $page ) . '">' . esc_html__( 'page', 'optima-build' ) . '</a>' : ''; ?></p>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */

	private static function health(): void {
		$ai     = Optima_Hub_Health::ai_checks();
		$score  = count( array_filter( $ai, static fn( $c ) => 'good' === $c['state'] ) );
		$basics = Optima_Hub_Health::checks();
		$search = self::search_checks();
		$bgood  = count( array_filter( $basics, static fn( $c ) => 'good' === $c['state'] ) );
		$sgood  = count( array_filter( $search, static fn( $c ) => 'good' === $c['status'] ) );
		$status = Optima_Hub_Overview::status();
		?>
		<style>
			/* Compact, so the whole report fits on one screen (and one screenshot). */
			.sh-health .sh-grid-3{grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px}
			.sh-health .sh-box{padding:12px 14px; border-radius:12px}
			.sh-health .sh-label{font-size:10.5px}
			.sh-health .sh-check .sh-value{font-size:15px; margin-top:4px}
			.sh-health .sh-note{font-size:12.5px; line-height:1.4; margin-top:4px}
			.sh-health .sh-section-head{margin:18px 0 8px}
			.sh-health .sh-section-head h2{font-size:17px}
			.sh-health .sh-section-head .sh-note,.sh-health .sh-meter{display:none}
			.sh-health .sh-score{display:flex; flex-wrap:wrap; align-items:center; gap:10px 16px; padding:14px 18px}
			.sh-health .sh-score .sh-value{margin:0; font-weight:600; font-size:15px}
			.sh-health .sh-score ul{display:flex; flex-wrap:wrap; gap:6px 18px; margin:0; padding:0; list-style:none; font-size:13px}
			.sh-health .sh-score ul a{text-decoration:none; color:var(--sh-accent-text); font-weight:600}
			.sh-health .sh-score .sh-copy-wrap{margin-left:auto; display:flex; align-items:center; gap:10px}
			.sh-health details.sh-list-more{margin-top:4px; font-size:12.5px}
			.sh-health details.sh-list-more summary{cursor:pointer; color:var(--sh-accent-text); font-weight:600}
		</style>
		<div class="sh-health">
		<section class="sh-box sh-score">
			<div class="sh-status is-<?php echo esc_attr( $status['state'] ); ?>"><span class="sh-dot" aria-hidden="true"></span><p class="sh-value"><?php echo esc_html( $status['text'] ); ?></p></div>
			<ul>
				<li><a href="#basics"><?php echo esc_html( sprintf( /* translators: 1: passed, 2: total */ __( 'Basics %1$d of %2$d', 'optima-build' ), $bgood, count( $basics ) ) ); ?></a></li>
				<?php if ( $search ) : ?>
					<li><a href="#search"><?php echo esc_html( sprintf( /* translators: 1: passed, 2: total */ __( 'Search %1$d of %2$d', 'optima-build' ), $sgood, count( $search ) ) ); ?></a></li>
				<?php endif; ?>
				<li><a href="#ai-search"><?php echo esc_html( sprintf( /* translators: 1: passed, 2: total */ __( 'AI search %1$d of %2$d', 'optima-build' ), $score, count( $ai ) ) ); ?></a></li>
			</ul>
			<div class="sh-copy-wrap"><?php self::copy_button(); ?></div>
		</section>
		<div class="sh-section-head" id="basics">
			<p class="sh-eyebrow"><?php esc_html_e( 'Basics', 'optima-build' ); ?></p>
			<h2><?php echo esc_html( sprintf( /* translators: 1: passed, 2: total */ __( '%1$d of %2$d good', 'optima-build' ), $bgood, count( $basics ) ) ); ?></h2>
		</div>
		<?php self::check_grid( $basics ); ?>
		<?php
		if ( $search ) {
			?>
			<div class="sh-section-head" id="search">
				<p class="sh-eyebrow"><?php esc_html_e( 'Search listing', 'optima-build' ); ?></p>
				<h2><?php
					/* translators: 1: checks passed, 2: total checks */
					echo esc_html( sprintf( __( '%1$d of %2$d set', 'optima-build' ), $sgood, count( $search ) ) );
				?></h2>
				<p class="sh-note"><?php esc_html_e( 'How the site shows up in Google results and when someone shares a page.', 'optima-build' ); ?></p>
			</div>
			<?php
			Optima_Core_Admin::render_checks( $search );
		}
		?>
		<div class="sh-section-head" id="ai-search">
			<p class="sh-eyebrow"><?php esc_html_e( 'Ready for AI search', 'optima-build' ); ?></p>
			<h2><?php
				/* translators: 1: checks passed, 2: total checks */
				echo esc_html( sprintf( __( '%1$d of %2$d ready', 'optima-build' ), $score, count( $ai ) ) );
			?></h2>
			<p class="sh-note"><?php esc_html_e( 'More people ask ChatGPT, Google\'s AI answers, Perplexity or Claude instead of searching. These are the things those tools need to find this site, trust it, and name it in their answers.', 'optima-build' ); ?></p>
		</div>
		<?php
		self::check_grid( $ai );
		echo '</div>';
	}

	/** Optima Core's search checks, minus the two the AI search list already covers. */
	public static function search_checks(): array {
		if ( ! class_exists( 'Optima_Core_Admin' ) || ! method_exists( 'Optima_Core_Admin', 'render_checks' ) ) {
			return array();
		}
		return array_values(
			array_filter(
				Optima_Core_Admin::checks(),
				static fn( $c ) => ! in_array( $c['label'], array( 'Business details', 'Search listing (schema)' ), true )
			)
		);
	}

	/**
	 * The Copy for Claude button: the whole Site health report as plain text,
	 * one click. Used on Site health and on Build, Updates.
	 */
	public static function copy_button( string $class = 'button' ): void {
		static $n = 0;
		++$n;
		$id = 'sh-copy-' . $n;
		?>
		<button type="button" class="<?php echo esc_attr( $class ); ?>" data-sh-copy="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy for Claude', 'optima-build' ); ?></button>
		<span class="sh-note" id="<?php echo esc_attr( $id ); ?>-note" aria-live="polite"></span>
		<textarea id="<?php echo esc_attr( $id ); ?>-text" hidden readonly aria-hidden="true"><?php echo esc_textarea( self::report_text() ); ?></textarea>
		<script>
		( function () {
			var b = document.querySelector( '[data-sh-copy="<?php echo esc_js( $id ); ?>"]' );
			if ( ! b ) { return; }
			b.addEventListener( 'click', function () {
				var t = document.getElementById( '<?php echo esc_js( $id ); ?>-text' ), note = document.getElementById( '<?php echo esc_js( $id ); ?>-note' );
				var done = function () { note.textContent = <?php echo wp_json_encode( __( 'Copied. Paste it into the chat.', 'optima-build' ) ); ?>; };
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( t.value ).then( done );
				} else {
					t.hidden = false; t.select(); document.execCommand( 'copy' ); t.hidden = true; done();
				}
			} );
		} )();
		</script>
		<?php
	}

	/** The whole Site health report as plain text (every check, the plugins, the theme). */
	public static function report_text(): string {
		return self::health_report( Optima_Hub_Health::checks(), self::search_checks(), Optima_Hub_Health::ai_checks() );
	}

	/**
	 * The whole Site health page as plain text: one line per check, then the
	 * active Optima plugins with versions. Built for pasting into a chat.
	 */
	private static function health_report( array $basics, array $search, array $ai ): string {
		$mark  = static fn( string $state ): string => 'good' === $state ? '[ok]  ' : ( 'bad' === $state ? '[fix] ' : '[look]' );
		$lines = array(
			sprintf( 'Optima Build site health: %s (%s)', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), home_url( '/' ) ),
			'Checked ' . wp_date( 'Y-m-d H:i' ) . ', plan: ' . (string) get_option( Optima_Hub_Access::PLAN_OPTION, '' ),
			'',
			'BASICS',
		);
		foreach ( $basics as $c ) {
			$lines[] = $mark( $c['state'] ) . ' ' . $c['label'] . ': ' . $c['value'] . '. ' . $c['note'];
		}
		if ( $search ) {
			$lines[] = '';
			$lines[] = 'SEARCH LISTING';
			foreach ( $search as $c ) {
				$state   = 'good' === $c['status'] ? 'good' : ( 'bad' === $c['status'] ? 'bad' : 'warn' );
				$items   = ! empty( $c['items'] ) ? ' (' . implode( ', ', array_map( 'get_the_title', (array) $c['items'] ) ) . ')' : '';
				$lines[] = $mark( $state ) . ' ' . $c['label'] . ': ' . $c['message'] . $items;
			}
		}
		$lines[] = '';
		$lines[] = 'READY FOR AI SEARCH';
		foreach ( $ai as $c ) {
			$lines[] = $mark( $c['state'] ) . ' ' . $c['label'] . ': ' . $c['value'] . '. ' . $c['note'];
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$lines[] = '';
		$lines[] = 'PLUGINS';
		foreach ( get_plugins() as $file => $data ) {
			$on      = is_plugin_active( $file );
			$lines[] = ( $on ? 'on   ' : 'off  ' ) . $data['Name'] . ' ' . $data['Version'];
		}
		$lines[] = '';
		$lines[] = 'Theme: ' . wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ) . ', WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION;
		return implode( "\n", $lines );
	}

	/** @param array<int, array{label:string, value:string, state:string, note:string, link?:string, link_text?:string}> $checks */
	private static function check_grid( array $checks ): void {
		?>
		<div class="sh-grid sh-grid-3">
			<?php foreach ( $checks as $c ) : ?>
				<section class="sh-box sh-check is-<?php echo esc_attr( $c['state'] ); ?>">
					<p class="sh-label"><?php echo esc_html( $c['label'] ); ?></p>
					<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( $c['value'] ); ?></p>
					<p class="sh-note"><?php echo esc_html( $c['note'] ); ?></p>
					<?php if ( ! empty( $c['list'] ) ) : ?>
						<details class="sh-list-more"><summary><?php esc_html_e( 'Which ones', 'optima-build' ); ?></summary>
							<ul class="sh-list"><?php foreach ( (array) $c['list'] as $line ) : ?><li><?php echo esc_html( (string) $line ); ?></li><?php endforeach; ?></ul>
						</details>
					<?php endif; ?>
					<?php if ( ! empty( $c['link'] ) && Optima_Hub_Access::is_operator() ) : ?>
						<p class="sh-note"><a href="<?php echo esc_url( $c['link'] ); ?>"><?php echo esc_html( $c['link_text'] ?? __( 'Fix this', 'optima-build' ) ); ?> &rsaquo;</a></p>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
