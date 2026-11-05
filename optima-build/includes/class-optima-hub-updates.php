<?php
/**
 * Build -> Updates: one screen with everything to do for an update
 * (dashboard release 3, "One Update Screen").
 *
 * Ten blocks in update order: site status, plugin updates, theme, fill from
 * theme again, approved copy, release channel, email, what Site health flags,
 * site lock, and the last update. Each block shows one state (Needs you,
 * Ready, Done) and every button posts to admin-post.php and comes back here.
 * Nothing here is a new feature: each block reads the code that already does
 * the job (Optima_Hub_Features, Optima_Hub_Themes, Optima_Hub_Setup,
 * Optima_Sync, Optima_Hub_Mail, Optima_Hub_Health, Optima_Hub_Lock).
 *
 * The release list asks GitHub again when it is older than an hour, so this
 * screen and Overview never say "nothing waiting" while an update is.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Updates {

	/** The words for each state. */
	private static function state_words(): array {
		return array(
			'needs' => __( 'Needs you', 'optima-build' ),
			'ready' => __( 'Ready', 'optima-build' ),
			'done'  => __( 'Done', 'optima-build' ),
			'log'   => __( 'Log', 'optima-build' ),
		);
	}

	/** Open one block: title and its state. */
	private static function open_block( string $title, string $state, string $id ): void {
		$words = self::state_words();
		echo '<section class="sh-box sh-step-block is-' . esc_attr( $state ) . '" id="' . esc_attr( $id ) . '">';
		echo '<div class="sh-step-head"><h2>' . esc_html( $title ) . '</h2><span class="sh-pill is-' . esc_attr( 'log' === $state ? 'log' : ( 'done' === $state ? 'done' : $state ) ) . '">' . esc_html( $words[ $state ] ?? '' ) . '</span></div>';
	}

	private static function close_block(): void {
		echo '</section>';
	}

	/** A button that posts to Optima_Hub_Features::handle() and returns here. */
	private static function action( string $slug, string $do, string $label, bool $primary = false ): string {
		return Optima_Hub_Features::button( $slug, $do, $label, $primary ? 'button button-primary' : 'button' );
	}

	public static function render(): void {
		if ( ! Optima_Hub_Access::is_operator() ) {
			return; // Build is for operators (rule 9); Optima_Hub_Admin sends anyone else to Overview.
		}
		Optima_Hub_Features::ensure_fresh();
		self::status_block();
		self::plugins_block();
		self::theme_block();
		self::fill_block();
		self::copy_block();
		self::channel_block();
		self::email_block();
		self::health_block();
		self::lock_block();
		self::log_block();
	}

	/* 1. Site status ---------------------------------------------------- */

	private static function status_block(): void {
		$s     = Optima_Hub_Overview::status();
		$inbox = Optima_Hub_Inbox::count();
		$state = ( 'good' !== $s['state'] || $inbox ) ? 'needs' : 'done';
		self::open_block( __( 'Site status', 'optima-build' ), $state, 'sh-up-status' );
		$line = $s['text'];
		if ( $inbox ) {
			/* translators: %d: items in the Inbox */
			$line .= ' ' . sprintf( _n( '%d Inbox item.', '%d Inbox items.', $inbox, 'optima-build' ), $inbox );
		}
		echo '<p>' . esc_html( $line ) . ' <span class="sh-note">' . esc_html( Optima_Hub_Features::checked_words() ) . '</span></p>';
		echo '<div class="sh-btns">';
		Optima_Hub_Admin::copy_button( 'button button-primary' );
		echo self::action( '', 'recheck', __( 'Check again', 'optima-build' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo '<a class="button sh-quiet" href="' . esc_url( Optima_Hub_Admin::url( 'health' ) ) . '">' . esc_html__( 'Site health', 'optima-build' ) . ' &rsaquo;</a>';
		echo '</div>';
		self::close_block();
	}

	/* 2. Plugin updates ------------------------------------------------- */

	private static function plugins_block(): void {
		$features = Optima_Hub_Catalog::features();
		$ready    = array();
		$current  = array();
		foreach ( $features as $slug => $f ) {
			$st = Optima_Hub_Features::status( $slug );
			if ( '' === $st['version'] ) {
				continue;
			}
			if ( '' !== $st['update'] ) {
				$ready[ $slug ] = array( 'name' => (string) $f['name'], 'st' => $st );
			} else {
				$current[ $slug ] = array( 'name' => (string) $f['name'], 'st' => $st );
			}
		}
		$build = Optima_Hub_Features::status( 'optima-build' );
		if ( '' !== $build['update'] && version_compare( $build['update'], OPTIMA_HUB_VERSION, '>' ) ) {
			$ready = array( 'optima-build' => array( 'name' => __( 'Optima Build', 'optima-build' ), 'st' => $build ) ) + $ready;
		}
		$count = count( $ready );
		self::open_block( __( 'Plugin updates', 'optima-build' ), $count ? 'needs' : 'done', 'sh-up-plugins' );
		$offline = ! $count && Optima_Hub_Catalog::unreachable();
		echo '<p>';
		if ( $offline ) {
			esc_html_e( 'Could not reach GitHub, so this list may be old. Try again in 15 minutes.', 'optima-build' );
		} elseif ( $count ) {
			/* translators: %d: updates ready */
			echo esc_html( sprintf( _n( '%d update ready.', '%d updates ready.', $count, 'optima-build' ), $count ) );
		} else {
			esc_html_e( 'Every feature and Optima Build are on their newest version.', 'optima-build' );
		}
		echo ' <span class="sh-note">' . esc_html( Optima_Hub_Features::checked_words() ) . '</span></p>';
		echo '<ul class="sh-waiting">';
		foreach ( $ready as $slug => $row ) {
			echo '<li><div><strong>' . esc_html( $row['name'] ) . '</strong>';
			/* translators: 1: installed version, 2: new version */
			echo '<p class="sh-note">' . esc_html( sprintf( __( 'v%1$s to v%2$s', 'optima-build' ), $row['st']['version'], $row['st']['update'] ) );
			if ( ! empty( $row['st']['test'] ) ) {
				echo ' <span class="sh-tag is-held">' . esc_html__( 'Test release', 'optima-build' ) . '</span>';
			}
			echo '</p></div>';
			echo self::action( $slug, 'update', __( 'Update now', 'optima-build' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			echo '</li>';
		}
		if ( ! isset( $ready['optima-build'] ) ) {
			/* translators: %s: version */
			echo '<li><div><strong>' . esc_html__( 'Optima Build', 'optima-build' ) . '</strong><p class="sh-note">' . esc_html( sprintf( __( 'v%s', 'optima-build' ), OPTIMA_HUB_VERSION ) ) . '</p></div><span class="sh-tag is-current">' . esc_html__( 'Up to date', 'optima-build' ) . '</span></li>';
		}
		echo '</ul>';
		if ( $current ) {
			/* translators: %d: features */
			echo '<details><summary>' . esc_html( sprintf( _n( '%d more feature, on its newest version', '%d more features, all on their newest version', count( $current ), 'optima-build' ), count( $current ) ) ) . '</summary><ul class="sh-waiting" style="margin-top:8px">';
			foreach ( $current as $row ) {
				echo '<li><span>' . esc_html( $row['name'] ) . '</span><span class="sh-ver">' . esc_html( 'v' . $row['st']['version'] ) . '</span></li>';
			}
			echo '</ul></details>';
		}
		echo '<div class="sh-btns">' . self::action( '', 'refresh', __( 'Check for new versions', 'optima-build' ), ! $count ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		self::close_block();
	}

	/* 3. Theme ---------------------------------------------------------- */

	private static function theme_block(): void {
		$theme = wp_get_theme();
		$src   = Optima_Hub_Themes::source( $theme );
		if ( ! $src ) {
			self::open_block( __( 'Theme', 'optima-build' ), 'needs', 'sh-theme' );
			/* translators: 1: theme name, 2: version. */
			echo '<p>' . esc_html( sprintf( __( '%1$s %2$s. This theme is updated by uploading it; it does not update itself from its repo yet.', 'optima-build' ), (string) $theme->get( 'Name' ), (string) $theme->get( 'Version' ) ) ) . '</p>';
			self::theme_dev_note( false );
			self::close_block();
			return;
		}
		$has_key = '' !== Optima_Hub_Themes::token();
		$rel     = $has_key ? Optima_Hub_Themes::latest( $src ) : null;
		$updates = get_site_transient( 'update_themes' );
		$ready   = is_object( $updates ) && isset( $updates->response[ $src['stylesheet'] ]['new_version'] ) ? (string) $updates->response[ $src['stylesheet'] ]['new_version'] : '';
		$newer   = '' !== $ready ? $ready : ( ( $rel && '' === $rel['error'] && version_compare( $rel['version'], $src['version'], '>' ) ) ? $rel['version'] : '' );
		$problem = '';
		if ( ! $has_key ) {
			$problem = __( 'It needs its read-only key to update itself.', 'optima-build' );
		} elseif ( $rel && 'key' === $rel['error'] ) {
			$problem = __( 'GitHub turned the key down. Save a new one.', 'optima-build' );
		} elseif ( $rel && 'offline' === $rel['error'] ) {
			$problem = __( 'Could not reach GitHub. Try again in 15 minutes.', 'optima-build' );
		}
		$state = '' !== $problem ? 'needs' : ( '' !== $newer ? 'ready' : 'done' );
		self::open_block( __( 'Theme', 'optima-build' ), $state, 'sh-theme' );
		echo '<p><strong>' . esc_html( $src['name'] . ' ' . $src['version'] ) . '</strong>. ';
		/* translators: %s: owner/repo */
		echo esc_html( sprintf( __( 'Repo: %s.', 'optima-build' ), $src['repo'] ) ) . ' ';
		echo esc_html( $has_key ? __( 'Key saved.', 'optima-build' ) : __( 'No key yet.', 'optima-build' ) ) . ' ';
		if ( '' !== $problem ) {
			echo esc_html( $problem );
		} elseif ( '' !== $newer ) {
			/* translators: %s: version */
			echo esc_html( sprintf( __( '%s is ready.', 'optima-build' ), $newer ) );
		} else {
			esc_html_e( 'Up to date.', 'optima-build' );
		}
		echo '</p>';
		echo '<div class="sh-btns">';
		if ( $has_key && '' !== $ready && current_user_can( 'update_themes' ) ) {
			echo self::action( '', 'theme', __( 'Update theme', 'optima-build' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		echo '</div>';
		if ( current_user_can( 'update_themes' ) && ! ( defined( 'OPTIMA_THEME_TOKEN' ) && '' !== (string) OPTIMA_THEME_TOKEN ) ) {
			echo '<details' . ( $has_key ? '' : ' open' ) . '><summary>' . esc_html( $has_key ? __( 'Change key', 'optima-build' ) : __( 'Connect the theme repo', 'optima-build' ) ) . '</summary>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sh-btns" style="margin-top:8px"><input type="hidden" name="action" value="' . esc_attr( Optima_Hub_Themes::ACTION ) . '">';
			wp_nonce_field( Optima_Hub_Themes::ACTION );
			/* translators: %s: owner/repo. */
			echo '<label for="sh-theme-key" style="flex-basis:100%">' . esc_html( sprintf( __( 'Read-only key for %s', 'optima-build' ), $src['repo'] ) ) . '</label>';
			echo '<input type="password" id="sh-theme-key" name="optima_theme_key" autocomplete="off" placeholder="' . esc_attr( $has_key ? __( 'Saved. Paste a new one to replace it.', 'optima-build' ) : 'github_pat_' ) . '" class="regular-text" style="flex:1 1 16em;min-width:0;max-width:100%">';
			echo '<button type="submit" class="button">' . esc_html__( 'Save key', 'optima-build' ) . '</button>';
			if ( $has_key ) {
				echo '<button type="submit" name="forget" value="1" class="button sh-quiet">' . esc_html__( 'Remove key', 'optima-build' ) . '</button>';
			}
			echo '</form><p class="sh-note">' . esc_html__( 'A GitHub fine-grained token with read-only Contents access to this one repository. It is stored encrypted and only ever sent to GitHub.', 'optima-build' ) . '</p></details>';
		}
		self::theme_dev_note( true );
		self::close_block();
	}

	/** What the theme's files need, for the developer: Update URI and the dashboards list. */
	private static function theme_dev_note( bool $connected ): void {
		Optima_Admin_UI::dev_open();
		if ( ! $connected ) {
			echo '<p class="sh-note">' . esc_html__( 'To let the theme update itself, its style.css names its repo in the Update URI header (https://github.com/optimadigitalmedia/<Client>), and a read-only key is saved here.', 'optima-build' ) . '</p>';
		}
		echo '<p class="sh-note">' . esc_html__( 'Overview\'s dashboards row reads the theme\'s setup file: "dashboards" with recon, analytics, search_console, business_profile and host, each an https address.', 'optima-build' ) . '</p>';
		Optima_Admin_UI::dev_close();
	}

	/* 4. Fill from theme again ------------------------------------------ */

	private static function fill_block(): void {
		$data = Optima_Hub_Setup::data();
		if ( ! $data ) {
			self::open_block( __( 'Fill from theme again', 'optima-build' ), 'done', 'sh-up-fill' );
			echo '<p>' . esc_html__( 'This theme has no setup file yet, so there is nothing to fill.', 'optima-build' ) . '</p>';
			self::close_block();
			return;
		}
		$done     = (array) get_option( Optima_Hub_Setup::DONE, array() );
		// A section counts only when the feature that reads it is on (seo is read by Optima Core).
		$owner    = array( 'team' => 'optima-team', 'reviews' => 'optima-reviews', 'chat' => 'optima-chat', 'hours' => 'optima-hours', 'analytics' => 'optima-analytics', 'cards' => 'optima-cards', 'contact' => 'optima-contact', 'seo' => 'optima-core' );
		$active   = (array) get_option( 'active_plugins', array() );
		$sections = array_keys( array_filter( $data, static fn( $v, $k ) => is_array( $v ) && isset( $owner[ $k ] ) && in_array( $owner[ $k ] . '/' . $owner[ $k ] . '.php', $active, true ), ARRAY_FILTER_USE_BOTH ) );
		$left     = array_values( array_diff( $sections, array_keys( $done ) ) );
		$state    = $left ? 'ready' : 'done';
		self::open_block( __( 'Fill from theme again', 'optima-build' ), $state, 'sh-up-fill' );
		$theme = wp_get_theme();
		if ( $left ) {
			/* translators: 1: theme version, 2: list of sections */
			echo '<p>' . esc_html( sprintf( __( 'Setup file %1$s has sections not filled in yet: %2$s. Only blank or default fields change.', 'optima-build' ), (string) $theme->get( 'Version' ), implode( ', ', $left ) ) ) . '</p>';
		} else {
			/* translators: %s: theme version */
			echo '<p>' . esc_html( sprintf( __( 'Setup file %s matches the site. Fill again after a theme update brings new facts; only blank or default fields change.', 'optima-build' ), (string) $theme->get( 'Version' ) ) ) . '</p>';
		}
		echo '<div class="sh-btns">' . self::action( '', 'refill', __( 'Fill again', 'optima-build' ), (bool) $left ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		self::close_block();
	}

	/* 5. Approved copy -------------------------------------------------- */

	private static function copy_block(): void {
		$screen = Optima_Hub_Menu::content_screen();
		if ( ! $screen ) {
			self::open_block( __( 'Approved copy', 'optima-build' ), 'done', 'sh-up-copy' );
			echo '<p>' . esc_html__( 'Turn on Business info and SEO (Optima Core) to apply approved copy from the theme.', 'optima-build' ) . '</p>';
			self::close_block();
			return;
		}
		if ( ! class_exists( 'Optima_Sync' ) || ! class_exists( 'Optima_Sync_Admin' ) ) {
			// The theme's own Content updates screen.
			self::open_block( __( 'Approved copy', 'optima-build' ), 'ready', 'sh-up-copy' );
			/* translators: %s: who provides the screen */
			echo '<p>' . esc_html( sprintf( __( 'New words from this site\'s repo are applied on the theme\'s own screen (provided by %s).', 'optima-build' ), $screen['by'] ) ) . '</p>';
			echo '<div class="sh-btns"><a class="button" href="' . esc_url( $screen['url'] ) . '">' . esc_html( $screen['label'] ) . '</a></div>';
			self::close_block();
			return;
		}
		$sum = Optima_Sync::summary( true );
		if ( empty( $sum['manifest'] ) ) {
			self::open_block( __( 'Approved copy', 'optima-build' ), 'done', 'sh-up-copy' );
			echo '<p>' . esc_html__( 'This theme carries no approved copy yet. Approved words reach the site with the next theme release.', 'optima-build' ) . '</p>';
			self::close_block();
			return;
		}
		$waiting = (int) ( $sum['counts'][ Optima_Sync::UPDATE ] ?? 0 );
		$review  = (int) ( $sum['review'] ?? 0 );
		self::open_block( __( 'Approved copy', 'optima-build' ), ( $waiting || $review ) ? 'needs' : 'done', 'sh-up-copy' );
		/* translators: 1: items waiting, 2: items edited in WordPress */
		echo '<p>' . esc_html( sprintf( __( '%1$d with new approved copy waiting. %2$d edited in WordPress or need a look.', 'optima-build' ), $waiting, $review ) ) . '</p>';
		echo '<div class="sh-btns">';
		if ( $waiting ) {
			$plan = Optima_Sync::plan();
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sh-inline">';
			echo '<input type="hidden" name="action" value="' . esc_attr( Optima_Sync_Admin::ACTION ) . '"><input type="hidden" name="step" value="safe"><input type="hidden" name="back" value="updates">';
			echo '<input type="hidden" name="review" value="' . esc_attr( (string) $plan['hash'] ) . '">';
			wp_nonce_field( Optima_Sync_Admin::ACTION );
			/* translators: %d: items */
			echo '<button type="submit" class="button button-primary">' . esc_html( sprintf( _n( 'Apply %d now', 'Apply %d now', $waiting, 'optima-build' ), $waiting ) ) . '</button></form>';
		}
		echo '<a class="button" href="' . esc_url( $screen['url'] ) . '">' . esc_html( $review ? __( 'Review', 'optima-build' ) : __( 'Open Approved copy', 'optima-build' ) ) . '</a>';
		echo '</div>';
		self::close_block();
	}

	/* 6. Release channel ------------------------------------------------ */

	private static function channel_block(): void {
		$test   = 'test' === Optima_Hub_Access::channel();
		$builds = Optima_Hub_Health::test_builds();
		$n      = 'good' === $builds['state'] ? 0 : count( (array) ( $builds['list'] ?? array() ) );
		self::open_block( __( 'Release channel', 'optima-build' ), ( ! $test && $n ) ? 'needs' : 'done', 'sh-up-channel' );
		echo '<p><strong>' . esc_html( $test ? __( 'Test', 'optima-build' ) : __( 'Stable', 'optima-build' ) ) . '.</strong> ';
		echo esc_html( $test ? __( 'This site gets each release first, before the client sites.', 'optima-build' ) : __( 'This site gets releases after they are checked on the test site.', 'optima-build' ) ) . ' ';
		/* translators: %d: test builds */
		echo esc_html( sprintf( _n( '%d test build on this site.', '%d test builds on this site.', $n, 'optima-build' ), $n ) ) . '</p>';
		if ( Optima_Hub_Access::channel_fixed() ) {
			echo '<p class="sh-note">' . esc_html__( 'Set in wp-config.php (OPTIMA_HUB_CHANNEL), so it cannot be changed here.', 'optima-build' ) . '</p>';
		} else {
			echo '<div class="sh-btns">' . self::action( '', 'channel', $test ? __( 'Switch to Stable', 'optima-build' ) : __( 'Switch to Test', 'optima-build' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		self::close_block();
	}

	/* 7. Email ---------------------------------------------------------- */

	private static function email_block(): void {
		if ( ! class_exists( 'Optima_Hub_Mail' ) ) {
			return;
		}
		$check = Optima_Hub_Mail::check();
		$ready = Optima_Hub_Mail::ready();
		$state = ! $ready || 'bad' === $check['state'] ? 'needs' : ( 'good' === $check['state'] ? 'done' : 'ready' );
		self::open_block( __( 'Email', 'optima-build' ), $state, 'sh-up-mail' );
		echo '<p>' . esc_html( $ready ? $check['value'] . '. ' . $check['note'] : __( 'Not set up: the host sends form emails, which often land in junk.', 'optima-build' ) ) . '</p>';
		echo '<div class="sh-btns">';
		if ( $ready ) {
			$me = wp_get_current_user();
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sh-inline"><input type="hidden" name="action" value="' . esc_attr( Optima_Hub_Mail::TEST ) . '"><input type="hidden" name="back" value="updates">';
			echo '<input type="hidden" name="to" value="' . esc_attr( (string) $me->user_email ) . '">';
			wp_nonce_field( Optima_Hub_Mail::TEST );
			/* translators: %s: email address */
			echo '<button type="submit" class="button' . ( 'done' === $state ? '' : ' button-primary' ) . '">' . esc_html( sprintf( __( 'Send a test to %s', 'optima-build' ), (string) $me->user_email ) ) . '</button></form>';
		}
		echo '<a class="button' . ( $ready ? '' : ' button-primary' ) . '" href="' . esc_url( Optima_Hub_Mail::url() ) . '">' . esc_html( $ready ? __( 'Email settings', 'optima-build' ) : __( 'Set up Email', 'optima-build' ) ) . '</a>';
		echo '</div>';
		self::close_block();
	}

	/* 8. What Site health flags ----------------------------------------- */

	private static function health_block(): void {
		$flags = array_values( array_filter( array_merge( Optima_Hub_Health::checks(), Optima_Hub_Health::ai_checks() ), static fn( $c ) => 'good' !== $c['state'] ) );
		self::open_block( __( 'Site health says', 'optima-build' ), $flags ? 'needs' : 'done', 'sh-up-health' );
		if ( ! $flags ) {
			echo '<p>' . esc_html__( 'Every check passes.', 'optima-build' ) . '</p>';
			self::close_block();
			return;
		}
		echo '<ul class="sh-waiting">';
		foreach ( $flags as $c ) {
			echo '<li><div><strong>' . esc_html( $c['label'] . ': ' . $c['value'] ) . '</strong><p class="sh-note">' . esc_html( $c['note'] ) . '</p></div>';
			$link = ! empty( $c['link'] ) ? (string) $c['link'] : Optima_Hub_Admin::url( 'health' );
			echo '<a class="button" href="' . esc_url( $link ) . '">' . esc_html( ! empty( $c['link_text'] ) ? (string) $c['link_text'] : __( 'Open', 'optima-build' ) ) . '</a></li>';
		}
		echo '</ul>';
		self::close_block();
	}

	/* 9. Site lock ------------------------------------------------------ */

	private static function lock_block(): void {
		$option = '0' !== (string) get_option( Optima_Hub_Lock::OPTION, '1' );
		$state  = Optima_Hub_Lock::state();
		$map    = array( 'on' => 'done', 'waiting' => 'needs', 'off' => 'ready' );
		$notes  = array(
			'on'      => __( 'On. Only operators edit the site; everyone else sees Overview, Site health and Inbox, and sends requests.', 'optima-build' ),
			'waiting' => __( 'Switched on, but no user on this site passes the operator check yet, so nobody is locked. It starts by itself once an operator account exists.', 'optima-build' ),
			'off'     => __( 'Off. Everyone edits the site as their WordPress role allows.', 'optima-build' ),
		);
		self::open_block( __( 'Site lock', 'optima-build' ), $map[ $state ] ?? 'ready', 'sh-lock' );
		echo '<p>' . esc_html( $notes[ $state ] ?? '' ) . '</p>';
		if ( Optima_Hub_Lock::enabled() !== $option ) {
			echo '<p class="sh-note">' . esc_html__( 'Set in code with the optima_build_site_lock filter, so this switch has no effect until that changes.', 'optima-build' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sh-btns">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Optima_Hub_Lock::ACTION ) . '"><input type="hidden" name="on" value="' . ( $option ? '' : '1' ) . '">';
		wp_nonce_field( Optima_Hub_Lock::ACTION );
		echo '<button type="submit" class="button">' . esc_html( $option ? __( 'Turn off', 'optima-build' ) : __( 'Turn on', 'optima-build' ) ) . '</button></form>';
		self::close_block();
	}

	/* 10. Last update --------------------------------------------------- */

	/** One line for the newest log entry: when, who, what. Also used on Overview. */
	public static function last_line(): string {
		$log = Optima_Hub_Features::recent_log();
		if ( ! $log ) {
			return '';
		}
		$row      = (array) $log[0];
		$features = Optima_Hub_Catalog::features();
		$name     = 'theme' === $row['slug'] ? __( 'the theme', 'optima-build' ) : ( 'optima-build' === $row['slug'] ? __( 'Optima Build', 'optima-build' ) : (string) ( $features[ $row['slug'] ]['name'] ?? $row['slug'] ) );
		$verbs    = array(
			'on'      => __( 'turned on %s', 'optima-build' ),
			'off'     => __( 'turned off %s', 'optima-build' ),
			'back'    => __( 'took %s back a version', 'optima-build' ),
			'update'  => __( 'updated %s', 'optima-build' ),
			'channel' => __( 'switched the release channel', 'optima-build' ),
		);
		$what = sprintf( $verbs[ $row['do'] ] ?? '%s', $name );
		if ( 'update' === $row['do'] && ! empty( $row['ok'] ) && false !== strpos( (string) $row['note'], '>' ) ) {
			/* translators: %s: version */
			$what .= ' ' . sprintf( __( 'to v%s', 'optima-build' ), trim( (string) substr( (string) $row['note'], strrpos( (string) $row['note'], '>' ) + 1 ) ) );
		}
		if ( 'theme' === $row['slug'] && ! empty( $row['ok'] ) && '' !== (string) $row['note'] ) {
			/* translators: %s: version */
			$what .= ' ' . sprintf( __( 'to %s', 'optima-build' ), (string) $row['note'] );
		}
		/* translators: 1: date and time, 2: person, 3: what they did */
		$line = sprintf( __( '%1$s. %2$s %3$s.', 'optima-build' ), wp_date( 'M j, g:i a', (int) $row['time'] ), (string) $row['who'], $what );
		return empty( $row['ok'] ) ? $line . ' ' . __( 'It did not finish.', 'optima-build' ) : $line;
	}

	private static function log_block(): void {
		self::open_block( __( 'Last update', 'optima-build' ), 'log', 'sh-up-log' );
		$line = self::last_line();
		echo '<p>' . esc_html( '' !== $line ? $line : __( 'Nothing updated from Build yet.', 'optima-build' ) ) . '</p>';
		echo '<p class="sh-note"><a href="' . esc_url( Optima_Hub_Admin::url( 'features' ) . '#sh-log' ) . '">' . esc_html__( 'Recent changes on Features', 'optima-build' ) . ' &rsaquo;</a></p>';
		self::close_block();
	}
}
