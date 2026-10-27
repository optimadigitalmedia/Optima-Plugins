<?php
/**
 * Optima Build -> Analytics: the Tag Manager container, the consent choice and
 * the cookie notice.
 *
 * @package Optima_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Analytics_Admin {

	const SLUG = 'optima-analytics';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_ANALYTICS_FILE ), array( __CLASS__, 'action_link' ) );
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
		add_submenu_page( self::parent(), __( 'Analytics', 'optima-analytics' ), __( 'Analytics', 'optima-analytics' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_ANALYTICS_URL . 'assets/optima-admin.css' );
		}
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'optima-analytics' ) . '</a>' );
		return $links;
	}

	public static function register(): void {
		register_setting( 'optima_analytics_group', Optima_Analytics::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in  = (array) $in;
		$gtm = strtoupper( preg_replace( '/\s+/', '', (string) ( $in['gtm'] ?? '' ) ) );
		$ga4 = strtoupper( preg_replace( '/\s+/', '', (string) ( $in['ga4'] ?? '' ) ) );
		if ( '' !== $gtm && ! Optima_Analytics::valid_gtm( $gtm ) ) {
			add_settings_error( Optima_Analytics::OPTION, 'gtm', __( 'That Tag Manager ID does not look right. It starts with GTM- (Tag Manager, Admin, Container ID).', 'optima-analytics' ) );
			$gtm = Optima_Analytics::settings()['gtm'];
		}
		if ( '' !== $ga4 && ! Optima_Analytics::valid_ga4( $ga4 ) ) {
			add_settings_error( Optima_Analytics::OPTION, 'ga4', __( 'That GA4 ID does not look right. It starts with G- (GA4, Admin, Data streams).', 'optima-analytics' ) );
			$ga4 = Optima_Analytics::settings()['ga4'];
		}
		return array(
			'gtm'          => $gtm,
			'ga4'          => $ga4,
			'mode'         => 'optin' === ( $in['mode'] ?? '' ) ? 'optin' : 'optout',
			'ads'          => ! empty( $in['ads'] ) ? 1 : 0,
			'skip_editors' => ! empty( $in['skip_editors'] ) ? 1 : 0,
			'late'         => ! empty( $in['late'] ) ? 1 : 0,
			'notice'       => sanitize_textarea_field( (string) ( $in['notice'] ?? '' ) ),
			'privacy_page' => absint( $in['privacy_page'] ?? 0 ),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = Optima_Analytics::settings();
		$o   = Optima_Analytics::OPTION;
		$tag = Optima_Analytics::tag();
		Optima_Admin_UI::open(
			__( 'Analytics', 'optima-analytics' ),
			__( 'Loads Google Tag Manager on the live site, with Google consent mode set up first and a small cookie notice for visitors.', 'optima-analytics' ),
			__( 'Analytics', 'optima-analytics' )
		);
		settings_errors( $o );
		?>
		<div class="sh-grid sh-grid-3">
			<section class="sh-box sh-check is-<?php echo $tag ? 'good' : 'warn'; ?>">
				<p class="sh-label"><?php esc_html_e( 'Tracking', 'optima-analytics' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( 'gtm' === $tag ? $s['gtm'] : ( 'ga4' === $tag ? $s['ga4'] : __( 'Not set up', 'optima-analytics' ) ) ); ?></p>
				<p class="sh-note"><?php echo esc_html( 'gtm' === $tag ? __( 'Tag Manager loads on the live site. Tags and GA4 are set in the container.', 'optima-analytics' ) : ( 'ga4' === $tag ? __( 'GA4 loads directly. Add a Tag Manager container to manage tags in one place.', 'optima-analytics' ) : __( 'Add the Tag Manager container ID below.', 'optima-analytics' ) ) ); ?></p>
			</section>
			<section class="sh-box sh-check is-good">
				<p class="sh-label"><?php esc_html_e( 'Consent', 'optima-analytics' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( 'optin' === $s['mode'] ? __( 'Ask first', 'optima-analytics' ) : __( 'Count, with opt out', 'optima-analytics' ) ); ?></p>
				<p class="sh-note"><?php esc_html_e( 'Google consent mode is set before any tag runs. Browsers that send Global Privacy Control count as a no.', 'optima-analytics' ); ?></p>
			</section>
			<section class="sh-box sh-check is-good">
				<p class="sh-label"><?php esc_html_e( 'Where it runs', 'optima-analytics' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php esc_html_e( 'Live site only', 'optima-analytics' ); ?></p>
				<p class="sh-note"><?php esc_html_e( 'Never on staging or local copies, and never for people editing the site, so the numbers stay clean.', 'optima-analytics' ); ?></p>
			</section>
		</div>

		<form method="post" action="options.php" class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Tag', 'optima-analytics' ); ?></h2>
			<?php settings_fields( 'optima_analytics_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sa-gtm"><?php esc_html_e( 'Tag Manager container ID', 'optima-analytics' ); ?></label></th>
					<td><input id="sa-gtm" type="text" class="regular-text code" placeholder="GTM-XXXXXXX" name="<?php echo esc_attr( $o ); ?>[gtm]" value="<?php echo esc_attr( $s['gtm'] ); ?>">
						<p class="description"><?php esc_html_e( 'Our standard for every site. Tag Manager, Admin, Container ID. Put the GA4 tag and any conversion tags inside the container.', 'optima-analytics' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="sa-ga4"><?php esc_html_e( 'GA4 measurement ID (only without Tag Manager)', 'optima-analytics' ); ?></label></th>
					<td><input id="sa-ga4" type="text" class="regular-text code" placeholder="G-XXXXXXXXXX" name="<?php echo esc_attr( $o ); ?>[ga4]" value="<?php echo esc_attr( $s['ga4'] ); ?>">
						<p class="description"><?php esc_html_e( 'Ignored while a Tag Manager ID is set, so the visit is never counted twice.', 'optima-analytics' ); ?></p></td>
				</tr>
			</table>

			<h2 class="sh-title"><?php esc_html_e( 'Consent and the cookie notice', 'optima-analytics' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Before a visitor chooses', 'optima-analytics' ); ?></th>
					<td>
						<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[mode]" value="optout" <?php checked( 'optout', $s['mode'] ); ?>> <?php esc_html_e( 'Count the visit, with a "No thanks" button (our US standard)', 'optima-analytics' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[mode]" value="optin" <?php checked( 'optin', $s['mode'] ); ?>> <?php esc_html_e( 'Count nothing until they say yes', 'optima-analytics' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Ad signals', 'optima-analytics' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[ads]" value="1" <?php checked( $s['ads'] ); ?>> <?php esc_html_e( 'This site runs paid ads, so ad measurement follows the same choice', 'optima-analytics' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off: ad cookies stay off for everyone.', 'optima-analytics' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="sa-notice"><?php esc_html_e( 'Notice text', 'optima-analytics' ); ?></label></th>
					<td><textarea id="sa-notice" class="large-text" rows="2" name="<?php echo esc_attr( $o ); ?>[notice]" placeholder="<?php echo esc_attr( Optima_Analytics::default_notice( $s['mode'] ) ); ?>"><?php echo esc_textarea( $s['notice'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Leave blank for the standard wording shown.', 'optima-analytics' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="sa-privacy"><?php esc_html_e( 'Privacy policy page', 'optima-analytics' ); ?></label></th>
					<td><?php wp_dropdown_pages( array( 'name' => esc_attr( $o ) . '[privacy_page]', 'id' => 'sa-privacy', 'selected' => (int) $s['privacy_page'], 'show_option_none' => esc_html__( 'None', 'optima-analytics' ), 'option_none_value' => '0' ) ); ?>
						<p class="description"><?php esc_html_e( 'Linked from the notice. Put [optima_privacy_choices] in the footer or on that page so visitors can change their mind.', 'optima-analytics' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'People editing the site', 'optima-analytics' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[skip_editors]" value="1" <?php checked( $s['skip_editors'] ); ?>> <?php esc_html_e( 'Do not count visits from anyone logged in who can edit', 'optima-analytics' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Page speed', 'optima-analytics' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[late]" value="1" <?php checked( $s['late'] ); ?>> <?php esc_html_e( 'Load Tag Manager after the page shows (recommended)', 'optima-analytics' ); ?></label>
					<p class="description"><?php esc_html_e( 'Tag Manager starts once the page has loaded, or at the visitor\'s first tap, scroll or key. The page shows faster on phones. A visitor who leaves in the first second or two is not counted.', 'optima-analytics' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Events other Optima features send', 'optima-analytics' ); ?></h2>
			<p><?php esc_html_e( 'The digital business cards and the link page report their views and taps here. In Tag Manager, one Custom Event trigger catches them all: event name ^(card|links)_ with "Use regex matching" ticked, feeding a GA4 Event tag whose event name is {{Event}}.', 'optima-analytics' ); ?></p>
			<p><code>card_view</code> <code>card_save_contact</code> <code>card_call</code> <code>card_email</code> <code>card_book</code> <code>card_links_click</code> <code>links_click</code> <code>links_post_click</code> <code>links_card_click</code></p>
			<p class="description"><?php esc_html_e( 'optima_consent_update fires when a visitor answers the cookie notice. It is for Tag Manager only; leave it out of GA4.', 'optima-analytics' ); ?></p>
			<p class="description"><?php esc_html_e( 'Each event carries link_label, and link_url when it is a link. In Tag Manager, add both as Data Layer Variables and send them as event parameters.', 'optima-analytics' ); ?></p>
		</section>
		<?php
		Optima_Admin_UI::close();
	}
}
