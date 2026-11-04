<?php
/**
 * Site lock: only operators edit the site (on by default).
 *
 * A signed-in user who is not an operator (Optima_Hub_Access::is_operator())
 * keeps their account and role but, while the lock is on, cannot edit, publish
 * or delete posts, pages or any other post type, upload files, change settings
 * (Optima's or the theme's), manage users, or install, activate or update
 * plugins and themes. They still see Overview, Site health and Inbox, and send
 * requests. Nothing in the database changes: the capabilities are held back
 * as each check runs (user_has_cap), so turning the lock off restores them at once.
 *
 * Off switches: the toggle on Optima -> Build (operators only), or
 *   add_filter( 'optima_build_site_lock', '__return_false' );
 * The lock never applies to WP-CLI or cron, and never to an operator.
 *
 * Optima screens open to everyone who could edit before (Overview, Site health,
 * Inbox) use the capability VIEW, given to anyone whose role can edit posts,
 * so the lock does not hide them and subscribers never see them.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Lock {

	const OPTION = 'optima_hub_site_lock';
	const ACTION = 'optima_hub_site_lock';

	/** Capability for the Optima screens everyone who could edit may open. */
	const VIEW = 'optima_view_hub';

	/** Capabilities held back from a non-operator, by prefix, while the lock is on. */
	const PREFIXES = '/^(edit|publish|delete|create|manage|install|activate|update|switch|upload|moderate|promote|remove|import|export|resume|unfiltered)_/';

	/** And these, by name. */
	const NAMES = array( 'customize', 'edit_theme_options', 'unfiltered_upload', 'import', 'export' );

	/** @var bool Inside our own capability check (the operator test asks about capabilities too). */
	private static $busy = false;

	/** @var array<int,bool> Operator answer per user, for this request. */
	private static $operators = array();

	public static function boot(): void {
		add_filter( 'user_has_cap', array( __CLASS__, 'caps' ), 999, 4 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
	}

	/** Is the lock on? The option (on unless an operator turned it off), then the filter. */
	public static function enabled(): bool {
		return (bool) apply_filters( 'optima_build_site_lock', '0' !== (string) get_option( self::OPTION, '1' ) );
	}

	/** WP-CLI and cron run as the site, never locked. */
	private static function exempt(): bool {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron();
	}

	/**
	 * @param array<string,bool> $allcaps What the user's role grants.
	 * @param string[]           $caps    The primitive capabilities being checked.
	 * @param array              $args    The original check.
	 * @param WP_User|null       $user    Whose capabilities.
	 */
	public static function caps( $allcaps, $caps, $args, $user ) {
		if ( self::$busy || ! is_array( $allcaps ) || ! ( $user instanceof WP_User ) || ! $user->exists() ) {
			return $allcaps;
		}
		// Anyone whose role can edit may open Overview, Site health and Inbox, lock or no lock.
		if ( ! empty( $allcaps['edit_posts'] ) ) {
			$allcaps[ self::VIEW ] = true;
		}
		self::$busy = true;
		$locked     = ! self::exempt() && self::enabled();
		if ( $locked ) {
			if ( ! isset( self::$operators[ $user->ID ] ) ) {
				self::$operators[ $user->ID ] = Optima_Hub_Access::is_operator( $user );
			}
			$locked = ! self::$operators[ $user->ID ];
		}
		self::$busy = false;
		if ( ! $locked ) {
			return $allcaps;
		}
		foreach ( array_merge( array_keys( $allcaps ), (array) $caps ) as $cap ) {
			$cap = (string) $cap;
			if ( self::VIEW !== $cap && ( in_array( $cap, self::NAMES, true ) || preg_match( self::PREFIXES, $cap ) ) ) {
				$allcaps[ $cap ] = false;
			}
		}
		return $allcaps;
	}

	/** Is this user held back right now? (For screens that explain why.) */
	public static function applies( ?WP_User $user = null ): bool {
		$user = $user ? $user : wp_get_current_user();
		return $user instanceof WP_User && $user->exists() && ! self::exempt() && self::enabled() && ! Optima_Hub_Access::is_operator( $user );
	}

	/** The toggle on Build, operators only. */
	public static function save(): void {
		check_admin_referer( self::ACTION );
		if ( ! Optima_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can change the site lock.', 'optima-build' ), 403 );
		}
		$on = ! empty( $_POST['on'] );
		update_option( self::OPTION, $on ? '1' : '0', true );
		set_transient(
			'optima_hub_notice_' . get_current_user_id(),
			array(
				'message' => $on ? __( 'Site lock is on. Only operators can edit the site.', 'optima-build' ) : __( 'Site lock is off. Everyone edits as their role allows.', 'optima-build' ),
				'type'    => 'success',
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( Optima_Hub_Admin::url( 'updates' ) . '#sh-lock' );
		exit;
	}

	/** The Site lock card on Build. */
	public static function card(): void {
		if ( ! Optima_Hub_Access::is_operator() ) {
			return;
		}
		$option = '0' !== (string) get_option( self::OPTION, '1' );
		$on     = self::enabled();
		?>
		<section class="sh-card" id="sh-lock">
			<h2><?php esc_html_e( 'Site lock', 'optima-build' ); ?></h2>
			<p class="sh-note">
				<?php
				echo esc_html(
					$on
						? __( 'On. Only operators can edit pages and posts, change settings, or add and update plugins and themes. Everyone else sees Overview, Site health and Inbox, and sends requests.', 'optima-build' )
						: __( 'Off. Everyone edits the site as their WordPress role allows.', 'optima-build' )
				);
				?>
			</p>
			<?php if ( $on !== $option ) : ?>
				<p class="sh-note"><?php esc_html_e( 'Set in code with the optima_build_site_lock filter, so this switch has no effect until that changes.', 'optima-build' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-inline">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="on" value="<?php echo $option ? '' : '1'; ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<button type="submit" class="button"><?php echo esc_html( $option ? __( 'Turn the site lock off', 'optima-build' ) : __( 'Turn the site lock on', 'optima-build' ) ); ?></button>
			</form>
		</section>
		<?php
	}
}
