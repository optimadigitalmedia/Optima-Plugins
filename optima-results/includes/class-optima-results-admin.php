<?php
/**
 * Optima Build -> Case results: the state, the disclaimer wording, where the
 * results stand, and how to put the grid on a page.
 *
 * @package Optima_Results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Results_Admin {

	const SLUG = 'optima-results';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_RESULTS_FILE ), array( __CLASS__, 'action_link' ) );
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

	public static function url(): string {
		return 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Case results', 'optima-results' ), __( 'Case results', 'optima-results' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		// The list sits in the Build menu too, so the sidebar keeps one Optima section.
		if ( 'options-general.php' !== self::parent() ) {
			$list = 'edit.php?post_type=' . Optima_Results::POST_TYPE;
			global $submenu;
			remove_menu_page( $list );
			unset( $submenu[ $list ] ); // Its leftover sub-list would otherwise claim the screen.
			add_submenu_page( self::parent(), __( 'All case results', 'optima-results' ), __( 'All case results', 'optima-results' ), 'edit_posts', $list );
		}
	}

	/** Keep the Build menu open while editing an entry. */
	public static function highlight( $file ) {
		global $submenu_file;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'options-general.php' !== self::parent() && $screen && Optima_Results::POST_TYPE === $screen->post_type && empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$submenu_file = 'edit.php?post_type=' . Optima_Results::POST_TYPE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::parent();
		}
		return $file;
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_RESULTS_URL . 'assets/optima-admin.css' );
			wp_enqueue_style( 'optima-results', OPTIMA_RESULTS_URL . 'assets/optima-results.css', array(), OPTIMA_RESULTS_VERSION );
			wp_enqueue_style( 'optima-results-editor', OPTIMA_RESULTS_URL . 'assets/results-editor.css', array( 'optima-results' ), OPTIMA_RESULTS_VERSION );
			wp_enqueue_script( 'optima-results-settings', OPTIMA_RESULTS_URL . 'assets/results-settings.js', array(), OPTIMA_RESULTS_VERSION, true );
			$defaults = array();
			foreach ( array_keys( Optima_Results::states() ) as $state ) {
				$defaults[ $state ] = Optima_Results::default_disclaimer( $state );
			}
			wp_localize_script( 'optima-results-settings', 'optimaResultsDefaults', $defaults );
		}
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'optima-results' ) . '</a>' );
		return $links;
	}

	public static function register(): void {
		register_setting( 'optima_results_group', Optima_Results::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in    = (array) $in;
		$state = sanitize_key( (string) ( $in['state'] ?? '' ) );
		$state = isset( Optima_Results::states()[ $state ] ) ? $state : 'nc';
		$text  = trim( sanitize_textarea_field( (string) ( $in['disclaimer'] ?? '' ) ) );
		// Saving the state's own wording unchanged keeps it following that state.
		if ( $text === Optima_Results::default_disclaimer( $state ) ) {
			$text = '';
		}
		return array(
			'state'      => $state,
			'disclaimer' => $text,
		);
	}

	/** Counts of approved, waiting and draft results. */
	private static function counts(): array {
		$live    = 0;
		$waiting = 0;
		$ids     = get_posts(
			array(
				'post_type'      => Optima_Results::POST_TYPE,
				'post_status'    => array( 'publish', 'pending', 'draft', 'future' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$approved = Optima_Results::is_approved( '1' === (string) get_post_meta( $id, Optima_Results::META['approved'], true ), (string) get_post_meta( $id, Optima_Results::META['approver'], true ) );
			if ( 'publish' === get_post_status( $id ) && $approved ) {
				++$live;
			} elseif ( ! $approved ) {
				++$waiting;
			}
		}
		return array(
			'live'    => $live,
			'waiting' => $waiting,
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = Optima_Results::settings();
		$o      = Optima_Results::OPTION;
		$counts = self::counts();
		$list   = admin_url( 'edit.php?post_type=' . Optima_Results::POST_TYPE );
		$new    = admin_url( 'post-new.php?post_type=' . Optima_Results::POST_TYPE );
		$states = Optima_Results::states();

		Optima_Admin_UI::open(
			__( 'Case results', 'optima-results' ),
			__( 'Show the firm\'s case results with the firm\'s approval on every one and the disclaimer printed next to each result.', 'optima-results' ),
			__( 'Results and disclaimers', 'optima-results' )
		);
		settings_errors( $o );
		?>
		<div class="sh-grid sh-grid-3">
			<section class="sh-box sh-check is-<?php echo $counts['live'] ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'On the site', 'optima-results' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d result', '%d results', $counts['live'], 'optima-results' ), $counts['live'] ) ); ?></p>
				<p class="sh-note"><a href="<?php echo esc_url( $list ); ?>"><?php esc_html_e( 'See all case results', 'optima-results' ); ?></a></p>
			</section>
			<section class="sh-box sh-check is-<?php echo $counts['waiting'] ? 'warn' : 'good'; ?>">
				<p class="sh-label"><?php esc_html_e( 'Waiting for the firm', 'optima-results' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d result', '%d results', $counts['waiting'], 'optima-results' ), $counts['waiting'] ) ); ?></p>
				<p class="sh-note"><?php esc_html_e( 'A result goes live once the firm ticks "Approved by the firm" and adds the approver\'s name.', 'optima-results' ); ?></p>
			</section>
			<section class="sh-box sh-check is-good">
				<p class="sh-label"><?php esc_html_e( 'Disclaimer', 'optima-results' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php esc_html_e( 'On every result', 'optima-results' ); ?></p>
				<p class="sh-note"><?php esc_html_e( 'Printed beside each result in the grid. It cannot be switched off for a single result.', 'optima-results' ); ?></p>
			</section>
		</div>

		<div class="sr-settings">
			<form method="post" action="options.php" class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Disclaimer', 'optima-results' ); ?></h2>
				<p><?php esc_html_e( 'Added to every result on its own. Have the firm approve the wording before any result goes live.', 'optima-results' ); ?></p>
				<?php settings_fields( 'optima_results_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sr-state"><?php esc_html_e( 'State rule set', 'optima-results' ); ?></label></th>
						<td>
							<select id="sr-state" name="<?php echo esc_attr( $o ); ?>[state]">
								<?php foreach ( $states as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['state'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Sets the starting wording. North Carolina, Virginia and Florida each have their own advertising rules, so the firm checks the final text.', 'optima-results' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sr-disclaimer"><?php esc_html_e( 'Disclaimer wording', 'optima-results' ); ?></label></th>
						<td>
							<textarea id="sr-disclaimer" class="large-text" rows="5" name="<?php echo esc_attr( $o ); ?>[disclaimer]" placeholder="<?php echo esc_attr( Optima_Results::default_disclaimer( $s['state'] ) ); ?>"><?php echo esc_textarea( $s['disclaimer'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Leave blank to use the state\'s starting wording shown in gray. A blank disclaimer is never printed; the starting wording is used instead.', 'optima-results' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save disclaimer', 'optima-results' ) ); ?>
			</form>

			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'How a result looks on the site', 'optima-results' ); ?></h2>
				<p><?php esc_html_e( 'A sample card in the site\'s own fonts and colors. Your saved disclaimer is shown under it.', 'optima-results' ); ?></p>
				<div class="sr-sample">
					<span class="sr-sample-tag"><?php esc_html_e( 'Sample data', 'optima-results' ); ?></span>
					<?php
					echo '<div class="optima-results">' . Optima_Results_Render::card( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside card().
						array(
							'headline'    => __( 'Settlement for a client hurt in a rear-end crash', 'optima-results' ),
							'amount'      => '',
							'type_label'  => __( 'Settlement', 'optima-results' ),
							'description' => __( 'Sample firm represented a driver whose car was struck from behind at a stoplight. The case settled before trial.', 'optima-results' ),
							'date'        => '',
							'area'        => __( 'Car accidents', 'optima-results' ),
							'area_slug'   => '',
						)
					) . '</div>';
					?>
				</div>
			</section>
		</div>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Put the results on a page', 'optima-results' ); ?></h2>
			<p><?php esc_html_e( 'Add a shortcode block to any page. Visitors can filter the grid by practice area.', 'optima-results' ); ?></p>
			<table class="sh-table sr-shortcodes">
				<tr><td>[optima_results]</td><td><?php esc_html_e( 'Every approved result, with practice-area filters.', 'optima-results' ); ?></td></tr>
				<tr><td>[optima_results area="car-accidents"]</td><td><?php esc_html_e( 'Only one practice area, for that practice area\'s page. Use the slug from Case results, Practice areas.', 'optima-results' ); ?></td></tr>
				<tr><td>[optima_results heading="Case results" limit="6"]</td><td><?php esc_html_e( 'A heading above the grid and a cap on how many show.', 'optima-results' ); ?></td></tr>
				<tr><td>[optima_results filters="no"]</td><td><?php esc_html_e( 'The grid without the filter buttons.', 'optima-results' ); ?></td></tr>
				<tr><td>[optima_result id="12"]</td><td><?php esc_html_e( 'One result by its ID, with the disclaimer.', 'optima-results' ); ?></td></tr>
			</table>
			<div class="sh-links">
				<a class="button button-primary" href="<?php echo esc_url( $new ); ?>"><?php esc_html_e( 'Add a case result', 'optima-results' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . Optima_Results::TAXONOMY . '&post_type=' . Optima_Results::POST_TYPE ) ); ?>"><?php esc_html_e( 'Practice areas', 'optima-results' ); ?></a>
			</div>
		</section>
		<?php
		Optima_Admin_UI::close();
	}
}
