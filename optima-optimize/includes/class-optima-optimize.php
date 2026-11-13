<?php
/**
 * Core bootstrap for Optima Optimize.
 *
 * The optimization engine, originals store, upload pipeline, WebP/AVIF
 * delivery, and the batch sweep of the existing library are all loaded. The
 * pipeline and delivery register their hooks but are gated by the
 * optimize_uploads and rewrite_picture settings, both default OFF. The batch
 * sweep is admin-triggered and confirmation-gated. Activating this build with
 * default settings still changes nothing on a site.
 *
 * @package Optima_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Engine and originals store.
require_once OPTIMA_OPTIMIZE_DIR . 'includes/engine/interface-optima-optimize-engine.php';
require_once OPTIMA_OPTIMIZE_DIR . 'includes/engine/class-optima-optimize-request.php';
require_once OPTIMA_OPTIMIZE_DIR . 'includes/engine/class-optima-optimize-result.php';
require_once OPTIMA_OPTIMIZE_DIR . 'includes/engine/class-optima-optimize-presets.php';
require_once OPTIMA_OPTIMIZE_DIR . 'includes/engine/class-optima-optimize-imagick-engine.php';
require_once OPTIMA_OPTIMIZE_DIR . 'includes/engine/class-optima-optimize-null-engine.php';
require_once OPTIMA_OPTIMIZE_DIR . 'includes/engine/class-optima-optimize-engine-factory.php';
require_once OPTIMA_OPTIMIZE_DIR . 'includes/originals/class-optima-optimize-originals.php';

// Upload pipeline (Stage C). Hook registered below; gated by the OFF-by-default flag.
require_once OPTIMA_OPTIMIZE_DIR . 'includes/pipeline/class-optima-optimize-attachment-processor.php';
require_once OPTIMA_OPTIMIZE_DIR . 'includes/pipeline/class-optima-optimize-pipeline.php';

// WebP delivery (Stage D). Hooks registered below; gated by the OFF-by-default flag.
require_once OPTIMA_OPTIMIZE_DIR . 'includes/delivery/class-optima-optimize-picture-builder.php';
require_once OPTIMA_OPTIMIZE_DIR . 'includes/delivery/class-optima-optimize-delivery.php';

// Batch sweep of the existing library (Stage E). Admin-triggered; cron worker.
require_once OPTIMA_OPTIMIZE_DIR . 'includes/batch/class-optima-optimize-batch.php';

/**
 * Main plugin class (singleton).
 */
final class Optima_Optimize {

	/**
	 * Option key holding the installed plugin version, used for idempotent
	 * activation and future upgrade routines.
	 */
	const VERSION_OPTION = 'optima_optimize_version';

	/**
	 * Option key holding plugin settings (preset, toggles). Populated with
	 * safe defaults on activation; managed by the Stage F settings page.
	 */
	const SETTINGS_OPTION = 'optima_optimize_settings';

	/**
	 * Singleton instance.
	 *
	 * @var Optima_Optimize|null
	 */
	private static $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return Optima_Optimize
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Wire hooks. Kept intentionally empty of features in Stage A.
	 *
	 * @return void
	 */
	private function init() {
		// Priority 20: after the theme's Build section (9) and the Site Build hub (15).
		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'redirect_old_urls' ), 1 );
		add_action( 'admin_post_optima_optimize_save', array( $this, 'handle_save_settings' ) );

		// Register the upload pipeline. Its callback is a no-op until the
		// optimize_uploads setting is turned on for the site.
		( new Optima_Optimize_Pipeline() )->register();

		// Register WebP delivery. Its filters are no-ops until the
		// rewrite_picture setting is turned on for the site.
		( new Optima_Optimize_Delivery() )->register();

		// Register the batch sweep (admin page, handlers, cron worker).
		( new Optima_Optimize_Batch() )->register();
	}

	/**
	 * Activation routine. Idempotent by design (per the suite's self-healing
	 * setup pattern): safe to run on every activation and on version bumps.
	 *
	 * NEVER performs destructive operations. Originals-preservation is a
	 * vision-level invariant (vision doc §3.4).
	 *
	 * @return void
	 */
	public static function activate() {
		// Grant the gate capability to administrators. Stage F may narrow this.
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( OPTIMA_OPTIMIZE_CAP ) ) {
			$role->add_cap( OPTIMA_OPTIMIZE_CAP );
		}

		// Seed settings with safe defaults only if absent (idempotent).
		if ( false === get_option( self::SETTINGS_OPTION ) ) {
			add_option(
				self::SETTINGS_OPTION,
				array(
					'preset'           => 'balanced', // lossy | balanced | lossless.
					'optimize_uploads' => false,      // Off until enabled per site.
					'generate_webp'    => false,      // Off until enabled per site.
					'generate_avif'    => false,      // Off until enabled per site.
					'rewrite_picture'  => false,      // Off until enabled per site.
					'max_dimension'    => 1920,       // Full-size longest-edge cap in pixels.
					'jquery_footer'    => false,      // Off until enabled per site.
				)
			);
		}

		update_option( self::VERSION_OPTION, OPTIMA_OPTIMIZE_VERSION );
	}

	/**
	 * Deactivation routine. Removes nothing: settings, stats, and (above all)
	 * original image files are preserved. Cleanup of options happens only in
	 * uninstall.php; image originals are never removed by any lifecycle hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Stop and unschedule any running batch sweep. No media is touched.
		$timestamp = wp_next_scheduled( 'optima_optimize_run_batch' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'optima_optimize_run_batch' );
		}
		delete_option( 'optima_optimize_sweep_running' );
	}

	/**
	 * Register the Optima-gated admin page.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_submenu_page(
			self::parent(),
			__( 'Image optimizer', 'optima-optimize' ),
			__( 'Image optimizer', 'optima-optimize' ),
			OPTIMA_OPTIMIZE_CAP,
			'optima-optimize',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * The site's Build section (the theme's Optima Build, Recon Build, or the
	 * Site Build hub), so every Optima screen lives in one place. Tools only
	 * when there is no Build section at all.
	 *
	 * @return string
	 */
	public static function parent() {
		global $admin_page_hooks;
		foreach ( array( 'optima-build', 'recon-build', 'site-build', 'optima' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'tools.php';
	}

	/**
	 * Admin URL of one of the optimizer's screens, wherever it lives.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $page = 'optima-optimize', $args = array() ) {
		$base = 'tools.php' === get_option( 'optima_optimize_parent', 'tools.php' ) ? 'tools.php' : 'admin.php';
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( $base ) );
	}

	/**
	 * Remember where the screens live (redirects run before menus exist), and
	 * send old Tools bookmarks to the new place.
	 *
	 * @return void
	 */
	public static function redirect_old_urls() {
		global $pagenow;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'tools.php' === $pagenow && in_array( $page, array( 'optima-optimize', 'optima-optimize-sweep' ), true ) && 'tools.php' !== get_option( 'optima_optimize_parent', 'tools.php' ) ) {
			$args = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			unset( $args['page'] );
			wp_safe_redirect( self::url( $page, $args ) );
			exit;
		}
		add_action(
			'admin_menu',
			static function () {
				$parent = self::parent();
				if ( get_option( 'optima_optimize_parent' ) !== $parent ) {
					update_option( 'optima_optimize_parent', $parent, false );
				}
			},
			21
		);
	}

	/**
	 * Current settings merged over the seeded defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'preset'           => 'balanced',
			'optimize_uploads' => false,
			'generate_webp'    => false,
			'generate_avif'    => false,
			'rewrite_picture'  => false,
			'max_dimension'    => 1920,
			'jquery_footer'    => false,
		);
		$saved    = get_option( self::SETTINGS_OPTION, array() );
		return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
	}

	/**
	 * True when image optimization is paused because NitroPack runs on this site.
	 *
	 * NitroPack optimizes and serves images itself, and two image optimizers on
	 * one site fight over the same files (Optima Playbook 5.28: never two tools
	 * on one job). While it is active, new uploads are not optimized, the
	 * library sweep does not run, and the smaller copies are not served. The
	 * settings are kept, so removing NitroPack picks up where the site was.
	 * The optima_optimize_pause_images filter can override the check.
	 *
	 * @return bool
	 */
	public static function images_paused() {
		/**
		 * Filters whether Optima Optimize pauses its image work.
		 *
		 * @param bool $paused True while NitroPack is active.
		 */
		return (bool) apply_filters( 'optima_optimize_pause_images', Optima_Optimize_Speed::nitropack() );
	}

	/**
	 * Persist settings from the admin form. Capability- and nonce-gated.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( OPTIMA_OPTIMIZE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'optima-optimize' ) );
		}
		check_admin_referer( 'optima_optimize_settings' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_admin_referer.
		$preset = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : 'balanced';
		$max    = isset( $_POST['max_dimension'] ) ? absint( wp_unslash( $_POST['max_dimension'] ) ) : 1920;
		if ( $max > 0 ) {
			$max = max( 320, min( 8000, $max ) );
		}

		$settings = array(
			'preset'           => Optima_Optimize_Presets::normalize( $preset ),
			'optimize_uploads' => ! empty( $_POST['optimize_uploads'] ),
			'generate_webp'    => ! empty( $_POST['generate_webp'] ),
			'generate_avif'    => ! empty( $_POST['generate_avif'] ),
			'rewrite_picture'  => ! empty( $_POST['rewrite_picture'] ),
			'max_dimension'    => $max,
			'jquery_footer'    => ! empty( $_POST['jquery_footer'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( self::SETTINGS_OPTION, $settings );

		wp_safe_redirect( self::url( 'optima-optimize', array( 'updated' => '1' ) ) );
		exit;
	}

	/**
	 * One plain sentence on where jQuery loads on this site, and why.
	 *
	 * @param array{move:bool,why:string} $jq From Optima_Optimize_Speed::jquery_state().
	 * @return string
	 */
	private static function jquery_sentence( array $jq ) {
		switch ( $jq['why'] ) {
			case 'off':
				return __( 'jQuery stays in the head because the speed fixes are switched off for this site in code.', 'optima-optimize' );
			case 'elementor':
				return __( 'jQuery stays in the head because this site runs Elementor, whose widgets call jQuery inside the page.', 'optima-optimize' );
			case 'nitropack':
				return __( 'jQuery stays in the head because this site runs NitroPack, which handles script loading itself.', 'optima-optimize' );
			case 'constant':
				return $jq['move']
					? __( 'jQuery moves to the end of the page because wp-config.php turns it on (OPTIMA_JQUERY_IN_FOOTER).', 'optima-optimize' )
					: __( 'jQuery stays in the head because wp-config.php turns the move off (OPTIMA_JQUERY_IN_FOOTER).', 'optima-optimize' );
			case 'filter':
				return $jq['move']
					? __( 'jQuery moves to the end of the page because code on this site turns it on (the optima_jquery_in_footer filter).', 'optima-optimize' )
					: __( 'jQuery stays in the head because code on this site turns the move off (the optima_jquery_in_footer filter).', 'optima-optimize' );
			case 'setting':
				return __( 'jQuery and the scripts that need it load at the end of the page, so they do not hold up the first paint.', 'optima-optimize' );
			default:
				return __( 'jQuery stays in the head. Turn this on only when no page calls jQuery inside its content, such as a form embed or a page-builder widget.', 'optima-optimize' );
		}
	}

	/**
	 * Report the server's image capabilities so a tester knows what will work.
	 *
	 * @return array{imagick:bool,webp:bool,avif:bool}
	 */
	private static function capabilities() {
		$imagick = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$formats = $imagick ? array_map( 'strtoupper', Imagick::queryFormats() ) : array();
		return array(
			'imagick' => $imagick,
			'webp'    => in_array( 'WEBP', $formats, true ),
			'avif'    => in_array( 'AVIF', $formats, true ),
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( OPTIMA_OPTIMIZE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'optima-optimize' ) );
		}

		$s    = self::settings();
		$caps = self::capabilities();

		$toggles = array(
			'optimize_uploads' => __( 'Optimize new uploads (compress, cap dimensions, back up the original first)', 'optima-optimize' ),
			'generate_webp'    => __( 'Generate WebP copies', 'optima-optimize' ),
			'generate_avif'    => __( 'Generate AVIF copies (smaller than WebP; needs server AVIF support)', 'optima-optimize' ),
			'rewrite_picture'  => __( 'Serve the smaller copies to visitors (wrap images in <picture>)', 'optima-optimize' ),
		);
		?>
		<?php Optima_Admin_UI::open( __( 'Image optimizer', 'optima-optimize' ), __( 'Every photo you upload is resized and compressed so the site stays fast. The original is kept as a backup.', 'optima-optimize' ), __( 'Images', 'optima-optimize' ) ); ?>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag after a nonce-checked save/redirect. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'optima-optimize' ); ?></p></div>
			<?php endif; ?>

			<?php if ( self::images_paused() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Image optimization is paused because NitroPack already optimizes this site\'s images; your settings are kept for when it is removed.', 'optima-optimize' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'optima_optimize_settings' ); ?>
				<input type="hidden" name="action" value="optima_optimize_save" />

				<section class="sh-box">
					<h2><?php esc_html_e( 'Settings', 'optima-optimize' ); ?></h2>
					<p><?php esc_html_e( 'Everything is off until you turn it on here. Originals are always backed up before any image is touched, so every change can be undone.', 'optima-optimize' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'What to do', 'optima-optimize' ); ?></th>
							<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'What to do', 'optima-optimize' ); ?></legend>
								<?php foreach ( $toggles as $key => $label ) : ?>
									<p><label class="sh-target"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?> /> <?php echo esc_html( $label ); ?></label></p>
								<?php endforeach; ?>
							</fieldset></td>
						</tr>
						<tr>
							<th scope="row"><label for="oo-preset"><?php esc_html_e( 'Quality', 'optima-optimize' ); ?></label></th>
							<td><select id="oo-preset" name="preset">
								<?php
								$presets = array(
									'lossy'    => __( 'Lossy (smallest files)', 'optima-optimize' ),
									'balanced' => __( 'Balanced (recommended)', 'optima-optimize' ),
									'lossless' => __( 'Lossless (largest, highest quality)', 'optima-optimize' ),
								);
								foreach ( $presets as $key => $label ) :
									?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['preset'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Balanced suits almost every site.', 'optima-optimize' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="oo-max"><?php esc_html_e( 'Largest image size', 'optima-optimize' ); ?></label></th>
							<td><input id="oo-max" type="number" name="max_dimension" min="0" max="8000" step="10" value="<?php echo esc_attr( (string) $s['max_dimension'] ); ?>" /> <?php esc_html_e( 'pixels', 'optima-optimize' ); ?>
							<p class="description"><?php esc_html_e( 'New uploads wider or taller than this are scaled down. 0 turns the limit off.', 'optima-optimize' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Page speed', 'optima-optimize' ); ?></th>
							<td>
								<?php
								$jq     = Optima_Optimize_Speed::jquery_state();
								$locked = ! in_array( $jq['why'], array( 'setting', 'default' ), true );
								?>
								<p><label class="sh-target"><input type="checkbox" name="jquery_footer" value="1" <?php checked( ! empty( $s['jquery_footer'] ) ); ?> <?php disabled( $locked ); ?> /> <?php esc_html_e( 'Move jQuery to the end of the page', 'optima-optimize' ); ?></label></p>
								<?php if ( $locked && ! empty( $s['jquery_footer'] ) ) : ?>
									<input type="hidden" name="jquery_footer" value="1" />
								<?php endif; ?>
								<p class="description"><?php echo esc_html( self::jquery_sentence( $jq ) ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save', 'optima-optimize' ) ); ?>
				</section>
			</form>

			<div class="sh-grid sh-grid-2">
				<section class="sh-box">
					<h2><?php esc_html_e( 'This server', 'optima-optimize' ); ?></h2>
					<p><?php esc_html_e( 'What the hosting can do with images.', 'optima-optimize' ); ?></p>
					<table class="widefat striped">
						<tbody>
							<tr><td><?php esc_html_e( 'Imagick image engine', 'optima-optimize' ); ?></td><td><span class="sh-pill <?php echo $caps['imagick'] ? 'is-done' : 'is-off'; ?>"><?php echo esc_html( $caps['imagick'] ? __( 'Yes', 'optima-optimize' ) : __( 'No', 'optima-optimize' ) ); ?></span></td></tr>
							<tr><td><?php esc_html_e( 'Can create WebP', 'optima-optimize' ); ?></td><td><span class="sh-pill <?php echo $caps['webp'] ? 'is-done' : 'is-off'; ?>"><?php echo esc_html( $caps['webp'] ? __( 'Yes', 'optima-optimize' ) : __( 'No', 'optima-optimize' ) ); ?></span></td></tr>
							<tr><td><?php esc_html_e( 'Can create AVIF', 'optima-optimize' ); ?></td><td><span class="sh-pill <?php echo $caps['avif'] ? 'is-done' : 'is-off'; ?>"><?php echo esc_html( $caps['avif'] ? __( 'Yes', 'optima-optimize' ) : __( 'No', 'optima-optimize' ) ); ?></span></td></tr>
						</tbody>
					</table>
					<?php if ( ! $caps['avif'] ) : ?>
						<p class="sh-note"><?php esc_html_e( 'This server cannot create AVIF, so AVIF copies are skipped even if that option is on. WebP still works.', 'optima-optimize' ); ?></p>
					<?php endif; ?>
				</section>
				<section class="sh-box">
				<h2><?php esc_html_e( 'Images already in the library', 'optima-optimize' ); ?></h2>
				<p><?php esc_html_e( 'New uploads are handled automatically once the options above are on. Photos uploaded before that can be optimized in one pass.', 'optima-optimize' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( self::url( 'optima-optimize-sweep' ) ); ?>"><?php esc_html_e( 'Optimize existing images', 'optima-optimize' ); ?></a></p>
			</section>
			</div>
		<?php Optima_Admin_UI::close(); ?>
		<?php
	}
}
