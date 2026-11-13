<?php
/**
 * Optima Build -> Team: who is on the site, how complete each profile is, and
 * the few site-wide choices.
 *
 * @package Optima_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Team_Admin {

	const SLUG = 'optima-team';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'one_way_in' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_TEAM_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/**
	 * One way to add a person (dashboard audit, People): WordPress's own Add New
	 * for a profile opens Add a person, which reads the setup file. The address
	 * keeps working; it just lands there.
	 */
	public static function one_way_in(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
		if ( Optima_Team::POST_TYPE === sanitize_key( wp_unslash( (string) ( $_GET['post_type'] ?? '' ) ) ) && class_exists( 'Optima_Team_Add' ) ) {
			wp_safe_redirect( Optima_Team_Add::url() );
			exit;
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

	public static function url(): string {
		return 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'People', 'optima-team' ), __( 'People', 'optima-team' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		// The list sits in the Build menu too, so the sidebar keeps one Optima section.
		if ( 'options-general.php' !== self::parent() ) {
			$list = 'edit.php?post_type=' . Optima_Team::POST_TYPE;
			global $submenu;
			remove_menu_page( $list );
			unset( $submenu[ $list ] ); // Its leftover sub-list would otherwise claim the screen.
			add_submenu_page( self::parent(), __( 'Profiles', 'optima-team' ), __( 'Profiles', 'optima-team' ), 'edit_posts', $list );
		}
	}

	/** Keep the Build menu open while editing an entry. */
	public static function highlight( $file ) {
		global $submenu_file;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'options-general.php' !== self::parent() && $screen && Optima_Team::POST_TYPE === $screen->post_type && empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$submenu_file = 'edit.php?post_type=' . Optima_Team::POST_TYPE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::parent();
		}
		return $file;
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_TEAM_URL . 'assets/optima-admin.css' );
		}
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'optima-team' ) . '</a>' );
		return $links;
	}

	public static function register(): void {
		register_setting( 'optima_team_group', Optima_Team::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in  = (array) $in;
		$law = (string) ( $in['law'] ?? 'auto' );
		return array(
			'law'     => in_array( $law, array( 'auto', 'on', 'off' ), true ) ? $law : 'auto',
			'contact' => ! empty( $in['contact'] ) ? 1 : 0,
			'columns' => min( 4, max( 2, absint( $in['columns'] ?? 3 ) ) ),
			'bylines' => ! empty( $in['bylines'] ) ? 1 : 0,
		);
	}

	/**
	 * Problems first (rule 52): pages whose "Reviewed by" line is not showing
	 * because the page changed after the review, or the record is missing the
	 * reviewer or the date.
	 */
	private static function reviews_box(): void {
		if ( ! class_exists( 'Optima_Team_Reviewed' ) ) {
			return;
		}
		$pages = Optima_Team_Reviewed::needs_attention();
		if ( ! $pages ) {
			return;
		}
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Reviews to fix', 'optima-team' ); ?></h2>
			<p><?php esc_html_e( 'These pages show no "Reviewed by" line until the review is complete and covers the words on the page now. Open a page, check it with the reviewer, then set the date and tick that they read the page as it is now.', 'optima-team' ); ?></p>
			<div class="sh-scroll"><table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Page', 'optima-team' ); ?></th><th scope="col"><?php esc_html_e( 'Reviewer', 'optima-team' ); ?></th><th scope="col"><?php esc_html_e( 'Reviewed on', 'optima-team' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'optima-team' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $pages as $id => $status ) : ?>
					<?php $r = Optima_Team_Reviewed::get( (int) $id ); ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $id ) ); ?>"><strong><?php echo esc_html( '' !== get_the_title( (int) $id ) ? wp_strip_all_tags( get_the_title( (int) $id ) ) : __( '(no title)', 'optima-team' ) ); ?></strong></a></td>
						<td><?php echo esc_html( $r['who'] ? wp_strip_all_tags( get_the_title( $r['who'] ) ) : __( 'Not set', 'optima-team' ) ); ?></td>
						<td><?php echo esc_html( '' !== $r['when'] ? $r['when'] : __( 'Not set', 'optima-team' ) ); ?></td>
						<td><span class="sh-pill is-received"><?php echo esc_html( 'stale' === $status ? __( 'Review out of date', 'optima-team' ) : __( 'Review incomplete', 'optima-team' ) ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		</section>
		<?php
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// The Add a person walkthrough is a tab of this screen (no sidebar address of its own).
		if ( Optima_Team_Add::is_open() ) {
			Optima_Team_Add::render();
			return;
		}
		if ( Optima_Team_Offboard::is_open() ) {
			Optima_Team_Offboard::render();
			return;
		}
		$s      = Optima_Team::settings();
		$o      = Optima_Team::OPTION;
		$people = get_posts(
			array(
				'post_type'      => Optima_Team::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 200,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'  => true,
			)
		);
		$complete = 0;
		foreach ( $people as $person ) {
			$checks = Optima_Team::checks( (int) $person->ID );
			if ( count( array_filter( wp_list_pluck( $checks, 2 ) ) ) === count( $checks ) ) {
				++$complete;
			}
		}
		$law     = Optima_Team::is_law();
		$archive = get_post_type_archive_link( Optima_Team::POST_TYPE );

		Optima_Admin_UI::open(
			__( 'People', 'optima-team' ),
			__( 'One profile per person. It feeds the team page, the profile page, bylines and the Person schema, so a name, title or photo changes in one place. Real people with real credentials are what visitors and search engines look for first.', 'optima-team' ),
			__( 'People', 'optima-team' )
		);
		settings_errors( $o );
		self::reviews_box();
		?>
		<div class="sh-grid sh-grid-3">
			<section class="sh-box sh-check is-<?php echo $people ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'People', 'optima-team' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( (string) count( $people ) ); ?></p>
				<p class="sh-note"><?php echo $people ? esc_html__( 'Each one has one page: their profile under /team/, or the bio page the profile names.', 'optima-team' ) : esc_html__( 'Add the first person to start the team page.', 'optima-team' ); ?></p>
			</section>
			<section class="sh-box sh-check is-<?php echo ( $people && count( $people ) === $complete ) ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'Complete profiles', 'optima-team' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span>
					<?php
					/* translators: 1: complete profiles, 2: all profiles. */
					echo esc_html( sprintf( __( '%1$d of %2$d', 'optima-team' ), $complete, count( $people ) ) );
					?>
				</p>
				<p class="sh-note"><?php esc_html_e( 'Complete means a real photo, a role and bio, a byline, education and profile links.', 'optima-team' ); ?></p>
			</section>
			<?php
			// Green only means good: Off because the business type is not set yet needs a person; Off by choice is grey.
			$type     = (string) ( Optima_Admin_UI::business()['business_type'] ?? '' );
			$law_need = ! $law && 'auto' === $s['law'] && '' === $type;
			$law_cls  = $law ? 'good' : ( $law_need ? 'needs' : 'off' );
			?>
			<section class="sh-box sh-check is-<?php echo esc_attr( $law_cls ); ?>">
				<p class="sh-label"><?php esc_html_e( 'Law firm details', 'optima-team' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( $law ? __( 'On', 'optima-team' ) : ( $law_need ? __( 'Needs you', 'optima-team' ) : __( 'Off', 'optima-team' ) ) ); ?></p>
				<p class="sh-note">
					<?php if ( $law_need ) : ?>
						<?php esc_html_e( 'The business type is not set, so bar admissions stay hidden.', 'optima-team' ); ?> <a href="<?php echo esc_url( Optima_Admin_UI::details_url() ); ?>"><?php esc_html_e( 'Set it in Details', 'optima-team' ); ?> &rsaquo;</a>
					<?php else : ?>
						<?php echo esc_html( $law ? __( 'Bar admissions show for anyone marked as an attorney.', 'optima-team' ) : __( 'Bar admissions are hidden. Turn this on below for a law firm.', 'optima-team' ) ); ?>
					<?php endif; ?>
				</p>
			</section>
		</div>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'The team', 'optima-team' ); ?></h2>
			<p><?php esc_html_e( 'People show in order of the Order number on each profile, lowest first, then by name. Open a person to fill in their credentials.', 'optima-team' ); ?></p>
			<?php if ( $people ) : ?>
				<div class="sh-scroll"><table class="widefat striped">
					<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'optima-team' ); ?></th><th scope="col"><?php esc_html_e( 'Role', 'optima-team' ); ?></th><th scope="col"><?php esc_html_e( 'Feeds', 'optima-team' ); ?></th><th scope="col"><?php esc_html_e( 'Trust signals', 'optima-team' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'optima-team' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $people as $person ) : ?>
						<?php
						$checks = Optima_Team::checks( (int) $person->ID );
						$done   = count( array_filter( wp_list_pluck( $checks, 2 ) ) );
						$todo   = array();
						foreach ( $checks as $check ) {
							if ( ! $check[2] ) {
								$todo[] = $check[0];
							}
						}
						$c     = Optima_Team_Links::counts( (int) $person->ID );
						$u     = Optima_Team_Links::urls( (int) $person->ID );
						$feeds = array();
						/* translators: %d: posts */
						$feeds[] = '' !== $u['posts'] ? '<a href="' . esc_url( $u['posts'] ) . '">' . esc_html( sprintf( _n( '%d post', '%d posts', $c['posts'], 'optima-team' ), $c['posts'] ) ) . '</a>' : esc_html__( 'no byline', 'optima-team' );
						if ( post_type_exists( 'optima_result' ) ) {
							/* translators: %d: case results */
							$feeds[] = '<a href="' . esc_url( $u['results'] ) . '">' . esc_html( sprintf( _n( '%d result', '%d results', $c['results'], 'optima-team' ), $c['results'] ) ) . '</a>';
						}
						/* translators: %d: reviewed pages */
						$feeds[] = '<a href="' . esc_url( $u['reviewed'] ) . '">' . esc_html( sprintf( _n( '%d reviewed page', '%d reviewed pages', $c['reviewed'], 'optima-team' ), $c['reviewed'] ) ) . '</a>';
						?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $person->ID ) ); ?>"><strong><?php echo esc_html( '' !== $person->post_title ? $person->post_title : __( '(no name)', 'optima-team' ) ); ?></strong></a></td>
							<td><?php echo esc_html( Optima_Team::profile( (int) $person->ID )['role'] ); ?></td>
							<td><?php echo wp_kses_post( implode( ' · ', $feeds ) ); ?></td>
							<td>
								<span class="sh-pill <?php echo $done === count( $checks ) ? 'is-done' : 'is-received'; ?>">
									<?php
									/* translators: 1: checks done, 2: total. */
									echo esc_html( sprintf( __( '%1$d of %2$d', 'optima-team' ), $done, count( $checks ) ) );
									?>
								</span>
								<?php if ( $todo ) : ?>
									<span class="description"><?php /* translators: %s: list of missing items. */ echo esc_html( sprintf( __( 'Add: %s', 'optima-team' ), strtolower( implode( ', ', $todo ) ) ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( 'publish' === $person->post_status ? __( 'Live', 'optima-team' ) : __( 'Draft', 'optima-team' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
			<p class="sh-actions" style="margin-top:14px">
				<a class="button button-primary" href="<?php echo esc_url( Optima_Team_Add::url() ); ?>"><?php esc_html_e( 'Add a person', 'optima-team' ); ?></a>
				<?php if ( $people && $archive ) : ?>
					<a class="button sh-quiet" href="<?php echo esc_url( $archive ); ?>"><?php esc_html_e( 'View the team page', 'optima-team' ); ?></a>
				<?php endif; ?>
			</p>
		</section>

		<?php Optima_Team_Offboard::former_box(); ?>

		<form method="post" action="options.php" class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Settings', 'optima-team' ); ?></h2>
			<?php settings_fields( 'optima_team_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Law firm details', 'optima-team' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Law firm details', 'optima-team' ); ?></legend>
							<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[law]" value="auto" <?php checked( 'auto', $s['law'] ); ?>> <?php esc_html_e( 'Decide from the business type in Optima Core (LegalService or Attorney turns it on)', 'optima-team' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[law]" value="on" <?php checked( 'on', $s['law'] ); ?>> <?php esc_html_e( 'On: show bar admissions for attorneys', 'optima-team' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[law]" value="off" <?php checked( 'off', $s['law'] ); ?>> <?php esc_html_e( 'Off', 'optima-team' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email and phone', 'optima-team' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[contact]" value="1" <?php checked( $s['contact'] ); ?>> <?php esc_html_e( 'Show each person\'s email and phone on their profile page', 'optima-team' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off: they stay in the schema only, and visitors use the main contact details.', 'optima-team' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Bylines', 'optima-team' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[bylines]" value="1" <?php checked( $s['bylines'] ); ?>> <?php esc_html_e( 'Author names on blog posts link to the writer\'s team profile', 'optima-team' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="st-columns"><?php esc_html_e( 'Grid columns on wide screens', 'optima-team' ); ?></label></th>
					<td><select id="st-columns" name="<?php echo esc_attr( $o ); ?>[columns]">
						<?php foreach ( array( 2, 3, 4 ) as $n ) : ?>
							<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( (int) $s['columns'], $n ); ?>><?php echo esc_html( (string) $n ); ?></option>
						<?php endforeach; ?>
					</select>
						<p class="description"><?php esc_html_e( 'Phones always show one column.', 'optima-team' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save', 'optima-team' ) ); ?>
		</form>

		<?php Optima_Admin_UI::dev_open(); ?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Show the team on a page', 'optima-team' ); ?></h2>
			<p><?php esc_html_e( 'Paste this shortcode where the team grid should go, for example on the About page. Each card links to that person\'s profile.', 'optima-team' ); ?></p>
			<table class="sh-table">
				<tr><td>[optima_team]</td><td><?php esc_html_e( 'Everyone who is live, in order.', 'optima-team' ); ?></td></tr>
				<tr><td>[optima_team columns="2"]</td><td><?php esc_html_e( 'A different number of columns for this grid.', 'optima-team' ); ?></td></tr>
				<tr><td>[optima_team ids="12,15"]</td><td><?php esc_html_e( 'Only these people, in this order. The ID is in the address bar when you edit a person.', 'optima-team' ); ?></td></tr>
				<tr><td>[optima_team bio="no"]</td><td><?php esc_html_e( 'Photo, name and role only.', 'optima-team' ); ?></td></tr>
			</table>
			<p class="description"><?php esc_html_e( 'With Optima Analytics on, profile opens, calls and emails report as team_profile_click, team_profile_view, team_call and team_email.', 'optima-team' ); ?></p>
		</section>
		<?php Optima_Admin_UI::dev_close(); ?>
		<?php
		Optima_Admin_UI::close();
	}
}
