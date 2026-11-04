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
			$open = count( array_filter( Optima_Hub_Requests::all(), static fn( $p ) => 'done' !== get_post_meta( $p->ID, Optima_Hub_Requests::META_STATUS, true ) ) );
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
			'optima-core'      => 'optima',
			'optima-aeo'       => 'optima-ai-search',
			'optima-optimize'  => 'optima-optimize',
			'optima-analytics' => 'optima-analytics',
			'optima-chat'      => 'optima-chat',
			'optima-cards'     => 'optima-cards',
			'optima-reviews'   => 'optima-reviews-settings',
		);
		global $_registered_pages;
		$page = $pages[ $slug ] ?? '';
		if ( '' === $page || ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		foreach ( array_keys( (array) $_registered_pages ) as $hook ) {
			if ( substr( (string) $hook, -strlen( '_page_' . $page ) ) === '_page_' . $page ) {
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
		// The eyebrow names the screen's group (Build, Inbox, Site health), as the sidebar does.
		Optima_Admin_UI::open( $tabs[ $tab ] );
		?>
			<?php self::notice(); ?>
			<?php
			if ( 'requests' === $tab ) {
				self::requests();
			} elseif ( 'health' === $tab ) {
				self::health();
			} elseif ( 'updates' === $tab ) {
				self::updates();
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

	/**
	 * Release channel card on Build, Updates (operators only). Test: this site
	 * takes test releases (GitHub pre-releases) as soon as they are published.
	 * Stable: only normal releases, after the test site checked them. Theme
	 * updates are not affected; they follow the theme's own repo.
	 */
	private static function channel_card(): void {
		$test  = 'test' === Optima_Hub_Access::channel();
		$fixed = Optima_Hub_Access::channel_fixed();
		?>
		<section class="sh-card">
			<h2><?php esc_html_e( 'Release channel', 'optima-build' ); ?></h2>
			<p>
				<strong><?php echo esc_html( $test ? __( 'Test', 'optima-build' ) : __( 'Stable', 'optima-build' ) ); ?>:</strong>
				<?php
				echo esc_html(
					$test
						? __( 'this site gets each release first, before the client sites.', 'optima-build' )
						: __( 'this site gets releases after they are checked on the test site.', 'optima-build' )
				);
				?>
			</p>
			<p class="sh-note"><?php esc_html_e( 'Plugin updates only. Theme updates come from the theme\'s own repo either way.', 'optima-build' ); ?></p>
			<?php if ( $fixed ) : ?>
				<p class="sh-note"><?php esc_html_e( 'Set in wp-config.php (OPTIMA_HUB_CHANNEL), so it cannot be changed here.', 'optima-build' ); ?></p>
			<?php else : ?>
				<?php
				echo Optima_Hub_Features::button( // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
					'',
					'channel',
					$test ? __( 'Switch to Stable', 'optima-build' ) : __( 'Switch to Test', 'optima-build' ),
					'button'
				);
				?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Updates, the first view of Build: every update on one screen, the same on
	 * every site, each with one button that comes back here. New versions of
	 * features and Optima Build, the theme (and its key), filling features from
	 * the theme's setup file, and applying approved copy (Site Sync, or the
	 * theme's Content updates screen).
	 */
	private static function updates(): void {
		$operator = Optima_Hub_Access::is_operator();
		$content  = Optima_Hub_Menu::content_screen();
		$features = Optima_Hub_Catalog::features();
		$ready    = array();
		foreach ( array_keys( $features ) as $slug ) {
			$st = Optima_Hub_Features::status( $slug );
			if ( '' !== $st['version'] && '' !== $st['update'] ) {
				$ready[ $slug ] = $st;
			}
		}
		$updates    = get_site_transient( 'update_plugins' );
		$build_new  = $updates->response[ plugin_basename( OPTIMA_HUB_FILE ) ]->new_version ?? '';
		$build_test = ! empty( $updates->response[ plugin_basename( OPTIMA_HUB_FILE ) ]->prerelease );
		?>
		<section class="sh-card">
			<h2><?php esc_html_e( 'Features and Optima Build', 'optima-build' ); ?></h2>
			<p class="sh-note"><?php esc_html_e( 'Features update themselves. Check now to see a release published in the last few hours.', 'optima-build' ); ?></p>
			<?php if ( $operator ) : ?>
				<?php echo Optima_Hub_Features::button( '', 'refresh', __( 'Check for new versions', 'optima-build' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
			<?php endif; ?>
			<?php if ( $ready ) : ?>
				<ul class="sh-waiting" style="margin-top:14px">
					<?php foreach ( $ready as $slug => $st ) : ?>
						<li>
							<div>
								<strong><?php echo esc_html( $features[ $slug ]['name'] ); ?></strong>
								<p class="sh-note">
									<?php
									/* translators: 1: installed version, 2: new version */
									echo esc_html( sprintf( __( 'v%1$s, update ready: v%2$s', 'optima-build' ), $st['version'], $st['update'] ) );
									?>
									<?php if ( ! empty( $st['test'] ) ) : ?>
										<span class="sh-tag is-held"><?php esc_html_e( 'Test release', 'optima-build' ); ?></span>
									<?php endif; ?>
								</p>
							</div>
							<?php $url = $operator ? Optima_Hub_Features::update_url( $slug ) : ''; ?>
							<?php if ( '' !== $url ) : ?>
								<a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Update now', 'optima-build' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="sh-note"><?php esc_html_e( 'Every feature is on its newest version.', 'optima-build' ); ?></p>
			<?php endif; ?>
			<p class="sh-note">
				<?php
				/* translators: %s: Optima Build version */
				echo esc_html( sprintf( __( 'Optima Build %s', 'optima-build' ), OPTIMA_HUB_VERSION ) );
				?>
				<?php if ( $build_new && version_compare( $build_new, OPTIMA_HUB_VERSION, '>' ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'plugins.php?plugin_status=upgrade' ) ); ?>">
						<?php
						/* translators: %s: newer Optima Build version */
						echo esc_html( sprintf( __( '%s ready on the Plugins screen', 'optima-build' ), $build_new ) );
						?>
					</a>
					<?php if ( $build_test ) : ?>
						<span class="sh-tag is-held"><?php esc_html_e( 'Test release', 'optima-build' ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</p>
		</section>

		<?php if ( $operator ) : ?>
			<?php self::channel_card(); ?>
		<?php endif; ?>

		<?php Optima_Hub_Themes::panel( $operator ); ?>

		<?php if ( Optima_Hub_Setup::data() ) : ?>
			<section class="sh-card">
				<h2><?php esc_html_e( 'Fill from the theme', 'optima-build' ); ?></h2>
				<p class="sh-note"><?php esc_html_e( 'The theme carries this site\'s facts (team, hours, call buttons, which features it uses). Fill again after a theme update brings new facts. Only blank or default fields change.', 'optima-build' ); ?></p>
				<?php if ( $operator ) : ?>
					<?php echo Optima_Hub_Features::button( '', 'refill', __( 'Fill from theme again', 'optima-build' ), 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<section class="sh-card">
			<h2><?php esc_html_e( 'Approved copy', 'optima-build' ); ?></h2>
			<p class="sh-note"><?php esc_html_e( 'New words come from this site\'s repo: approve the page in content/, release the theme, then apply it here. Every change keeps a revision.', 'optima-build' ); ?></p>
			<?php if ( $content ) : ?>
				<p>
					<a class="button" href="<?php echo esc_url( $content['url'] ); ?>"><?php echo esc_html( $content['label'] ); ?></a>
					<span class="sh-note">
						<?php
						/* translators: %s: who provides the screen */
						echo esc_html( sprintf( __( 'Provided by %s.', 'optima-build' ), $content['by'] ) );
						?>
					</span>
				</p>
			<?php else : ?>
				<p class="sh-note"><?php esc_html_e( 'Turn on Business info and SEO (Optima Core) to apply approved copy from the theme.', 'optima-build' ); ?></p>
			<?php endif; ?>
		</section>
		<?php Optima_Hub_Lock::card(); ?>
		<?php $setup = Optima_Hub_Menu::theme_setup_screen(); ?>
		<?php if ( $setup ) : ?>
		<section class="sh-card">
			<h2><?php esc_html_e( 'Theme setup', 'optima-build' ); ?></h2>
			<p class="sh-note"><?php esc_html_e( 'The theme\'s own one-time setup: pages, menus and starter content it needs.', 'optima-build' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( $setup['url'] ); ?>"><?php echo esc_html( $setup['label'] ); ?></a></p>
		</section>
		<?php endif; ?>
		<?php
	}

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
		<section class="sh-box sh-overview">
			<div class="sh-stats">
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) count( $groups['on'] ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'features on', 'optima-build' ); ?></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num"><?php echo (int) ( count( $groups['picked'] ) + count( $groups['ready'] ) ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'ready to add', 'optima-build' ); ?></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num"><?php echo esc_html( $ready . '/' . count( $ai ) ); ?></p>
					<p class="sh-note"><a href="<?php echo esc_url( self::url( 'health' ) . '#ai-search' ); ?>"><?php esc_html_e( 'ready for AI search', 'optima-build' ); ?> &rsaquo;</a></p>
				</div>
				<div class="sh-stat">
					<p class="sh-num sh-num-text"><?php echo esc_html( $plan ? Optima_Hub_Access::PLANS[ $plan ] ?? __( 'Not set', 'optima-build' ) : __( 'Not set', 'optima-build' ) ); ?></p>
					<p class="sh-note"><?php esc_html_e( 'this site\'s plan', 'optima-build' ); ?></p>
				</div>
			</div>
			<?php if ( $operator ) : ?>
				<div class="sh-ops">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::PLAN_ACTION ); ?>">
						<?php wp_nonce_field( self::PLAN_ACTION ); ?>
						<label for="sh-plan" class="screen-reader-text"><?php esc_html_e( 'This site\'s plan', 'optima-build' ); ?></label>
						<select id="sh-plan" name="plan">
							<option value=""><?php esc_html_e( 'Plan not set', 'optima-build' ); ?></option>
							<?php foreach ( Optima_Hub_Access::PLANS as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $plan, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Save plan', 'optima-build' ); ?></button>
					</form>
					<a class="button sh-quiet" href="<?php echo esc_url( self::url( 'updates' ) ); ?>"><?php esc_html_e( 'Updates', 'optima-build' ); ?></a>
					<?php
					$installed = array_filter( array_map( array( 'Optima_Hub_Features', 'status' ), array_keys( Optima_Hub_Catalog::features() ) ), static fn( $st ) => '' !== $st['version'] );
					$waiting   = count( array_filter( $installed, static fn( $st ) => '' !== $st['update'] ) );
					$checked   = $installed ? max( array_column( $installed, 'checked' ) ) : 0;
					Optima_Hub_Features::refresh_if_stale( $checked );
					?>
					<p class="sh-inline sh-note">
						<?php $offline = ! $waiting && Optima_Hub_Catalog::unreachable(); ?>
						<span class="sh-tag <?php echo ( $waiting || $offline ) ? 'is-update' : 'is-current'; ?>"><?php
							echo esc_html(
								$waiting
									/* translators: %d: features with an update */
									? sprintf( _n( '%d update ready', '%d updates ready', $waiting, 'optima-build' ), $waiting )
									: ( $offline
										// The check failed, so "up to date" would be a guess.
										? __( 'Could not reach GitHub. Try again in 15 minutes.', 'optima-build' )
										: __( 'Everything is up to date', 'optima-build' ) )
							);
						?></span>
						<?php if ( $checked ) : ?>
							<?php
							/* translators: %s: time since, e.g. "5 minutes" */
							echo esc_html( sprintf( __( 'Checked %s ago', 'optima-build' ), human_time_diff( $checked ) ) );
							?>
						<?php endif; ?>
						<?php
						// Optima Build updates itself from the Plugins screen, so its own version sits here.
						$updates   = get_site_transient( 'update_plugins' );
						$build_new = $updates->response[ plugin_basename( OPTIMA_HUB_FILE ) ]->new_version ?? '';
						?>
						<span class="sh-build-ver">
							<?php
							/* translators: %s: Optima Build version */
							echo esc_html( sprintf( __( 'Optima Build %s', 'optima-build' ), OPTIMA_HUB_VERSION ) );
							?>
							<?php if ( $build_new && version_compare( $build_new, OPTIMA_HUB_VERSION, '>' ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'plugins.php?plugin_status=upgrade' ) ); ?>">
									<?php
									/* translators: %s: newer Optima Build version */
									echo esc_html( sprintf( __( '%s ready on the Plugins screen', 'optima-build' ), $build_new ) );
									?>
								</a>
							<?php endif; ?>
						</span>
					</p>
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
		<style>.sh-skip .sh-card{opacity:.55}.sh-skip .sh-card:hover,.sh-skip .sh-card:focus-within{opacity:1}.sh-order{font-weight:600}.sh-build-ver{margin-left:12px;white-space:nowrap}</style>

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
			$verbs = array( 'on' => __( 'Turned on', 'optima-build' ), 'off' => __( 'Turned off', 'optima-build' ), 'back' => __( 'Went back a version', 'optima-build' ), 'channel' => __( 'Release channel', 'optima-build' ) );
			?>
			<section class="sh-box">
				<p class="sh-label"><?php esc_html_e( 'Recent changes', 'optima-build' ); ?></p>
				<table class="sh-table">
					<tbody>
					<?php foreach ( array_slice( $log, 0, 10 ) as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, g:i a', (int) $row['time'] ) ); ?></td>
							<td><?php echo esc_html( $row['who'] ); ?></td>
							<?php $what = 'channel' === $row['do'] ? ( 'test' === $row['note'] ? __( 'Test', 'optima-build' ) : __( 'Stable', 'optima-build' ) ) : ( $features[ $row['slug'] ]['name'] ?? $row['slug'] ); ?>
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
			<div class="sh-actions">
				<?php
				if ( $operator ) {
					if ( 'on' !== $s['state'] && $s['update'] ) {
						$update = Optima_Hub_Features::update_url( $slug );
						if ( '' !== $update ) {
							echo '<a class="button button-primary" href="' . esc_url( $update ) . '">' . esc_html__( 'Update now', 'optima-build' ) . '</a>';
						}
					}
					if ( 'on' === $s['state'] ) {
						$update = $s['update'] ? Optima_Hub_Features::update_url( $slug ) : '';
						if ( '' !== $update ) {
							echo '<a class="button button-primary" href="' . esc_url( $update ) . '">' . esc_html__( 'Update now', 'optima-build' ) . '</a>';
						}
						$settings = self::settings_url( $slug );
						if ( '' !== $settings ) {
							echo '<a class="button' . ( '' === $update ? ' button-primary' : '' ) . '" href="' . esc_url( $settings ) . '">' . esc_html__( 'Settings', 'optima-build' ) . '</a>';
						}
						echo Optima_Hub_Features::button( $slug, 'off', __( 'Turn off', 'optima-build' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						if ( $s['previous'] ) {
							echo Optima_Hub_Features::button( $slug, 'back', sprintf( /* translators: %s: version */ __( 'Go back to v%s', 'optima-build' ), $s['previous'] ), 'button sh-quiet' ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
					} else {
						echo Optima_Hub_Features::button( $slug, 'on', __( 'Turn on', 'optima-build' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
				} elseif ( 'on' === $s['state'] && '' !== self::settings_url( $slug ) ) {
					echo '<a class="button" href="' . esc_url( self::settings_url( $slug ) ) . '">' . esc_html__( 'Settings', 'optima-build' ) . '</a>';
				} elseif ( 'on' !== $s['state'] ) {
					$ask = sprintf( /* translators: %s: feature */ __( 'Please add %s to our site.', 'optima-build' ), $f['name'] );
					echo '<a class="button" href="' . esc_url( self::url( 'requests', array( 'ask' => rawurlencode( $ask ) ) ) ) . '">' . esc_html__( 'Ask us to add this', 'optima-build' ) . '</a>';
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
		?>
		<div class="sh-grid sh-grid-2">
			<section class="sh-box">
				<p class="sh-label"><?php esc_html_e( 'New request', 'optima-build' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="sh-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( Optima_Hub_Requests::ACTION ); ?>">
					<input type="hidden" name="do" value="new">
					<?php wp_nonce_field( Optima_Hub_Requests::ACTION ); ?>
					<label for="sh-what"><?php esc_html_e( 'What would you like changed?', 'optima-build' ); ?></label>
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
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Send request', 'optima-build' ); ?></button></p>
				</form>
			</section>

			<section class="sh-box">
				<p class="sh-label"><?php esc_html_e( 'Your requests', 'optima-build' ); ?></p>
				<?php $items = Optima_Hub_Requests::all(); ?>
				<?php if ( ! $items ) : ?>
					<p class="sh-note"><?php esc_html_e( 'No requests yet. Anything you send shows up here with its status.', 'optima-build' ); ?></p>
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
									<span class="sh-pill is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( Optima_Hub_Requests::STATUSES[ $status ] ); ?></span>
								</div>
								<p class="sh-note"><?php echo esc_html( get_the_date( 'M j, Y', $post ) . ' · ' . get_the_author_meta( 'display_name', (int) $post->post_author ) ); ?><?php echo $page ? ' · <a href="' . esc_url( $page ) . '">' . esc_html__( 'page', 'optima-build' ) . '</a>' : ''; ?></p>
								<?php if ( $operator ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
										<input type="hidden" name="action" value="<?php echo esc_attr( Optima_Hub_Requests::ACTION ); ?>">
										<input type="hidden" name="do" value="status">
										<input type="hidden" name="request" value="<?php echo (int) $post->ID; ?>">
										<?php wp_nonce_field( Optima_Hub_Requests::ACTION ); ?>
										<select name="status" aria-label="<?php esc_attr_e( 'Status', 'optima-build' ); ?>">
											<?php foreach ( Optima_Hub_Requests::STATUSES as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button sh-quiet"><?php esc_html_e( 'Update', 'optima-build' ); ?></button>
									</form>
								<?php endif; ?>
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
		$search = array();
		if ( class_exists( 'Optima_Core_Admin' ) && method_exists( 'Optima_Core_Admin', 'render_checks' ) ) {
			// Optima Core's search checks, minus the two the AI search list below already covers.
			$search = array_values(
				array_filter(
					Optima_Core_Admin::checks(),
					static fn( $c ) => ! in_array( $c['label'], array( 'Business details', 'Search listing (schema)' ), true )
				)
			);
		}
		?>
		<style>
			/* Compact, so the whole report fits on one screen (and one screenshot). */
			.sh-health .sh-grid-3{grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:10px}
			.sh-health .sh-box{padding:12px 14px; border-radius:12px}
			.sh-health .sh-label{font-size:10.5px}
			.sh-health .sh-check .sh-value{font-size:15px; margin-top:4px}
			.sh-health .sh-note{font-size:12.5px; line-height:1.4; margin-top:4px}
			.sh-health .sh-section-head{margin:18px 0 8px}
			.sh-health .sh-section-head h2{font-size:17px}
			.sh-health .sh-section-head .sh-note,.sh-health .sh-meter{display:none}
			.sh-copy{display:flex; flex-wrap:wrap; align-items:center; gap:10px 14px; margin:12px 0 14px}
			.sh-copy .sh-note{margin:0}
		</style>
		<div class="sh-health">
		<div class="sh-copy">
			<button type="button" class="button button-primary" id="sh-copy-report"><?php esc_html_e( 'Copy for Claude', 'optima-build' ); ?></button>
			<p class="sh-note" id="sh-copy-note"><?php esc_html_e( 'Copies every check on this page, plus each Optima plugin and its version, as plain text to paste into a chat.', 'optima-build' ); ?></p>
			<textarea id="sh-copy-text" hidden readonly><?php echo esc_textarea( self::health_report( $basics, $search, $ai ) ); ?></textarea>
		</div>
		<script>
		document.getElementById( 'sh-copy-report' ).addEventListener( 'click', function () {
			var text = document.getElementById( 'sh-copy-text' ).value, note = document.getElementById( 'sh-copy-note' );
			var done = function () { note.textContent = <?php echo wp_json_encode( __( 'Copied. Paste it into the chat.', 'optima-build' ) ); ?>; };
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( done );
			} else {
				var t = document.getElementById( 'sh-copy-text' ); t.hidden = false; t.select(); document.execCommand( 'copy' ); t.hidden = true; done();
			}
		} );
		</script>
		<?php self::check_grid( $basics ); ?>
		<?php
		if ( $search ) {
			$good = count( array_filter( $search, static fn( $c ) => 'good' === $c['status'] ) );
			?>
			<div class="sh-section-head" id="search">
				<p class="sh-eyebrow"><?php esc_html_e( 'Search listing', 'optima-build' ); ?></p>
				<h2><?php
					/* translators: 1: checks passed, 2: total checks */
					echo esc_html( sprintf( __( '%1$d of %2$d set', 'optima-build' ), $good, count( $search ) ) );
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
			<div class="sh-meter" role="img" aria-label="<?php echo esc_attr( sprintf( '%d / %d', $score, count( $ai ) ) ); ?>"><span style="width:<?php echo esc_attr( (string) round( 100 * $score / max( 1, count( $ai ) ) ) ); ?>%"></span></div>
		</div>
		<?php
		self::check_grid( $ai );
		echo '</div>';
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
					<?php if ( ! empty( $c['link'] ) && Optima_Hub_Access::is_operator() ) : ?>
						<p class="sh-note"><a href="<?php echo esc_url( $c['link'] ); ?>"><?php echo esc_html( $c['link_text'] ?? __( 'Fix this', 'optima-build' ) ); ?> &rsaquo;</a></p>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
