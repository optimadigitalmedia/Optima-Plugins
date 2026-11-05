<?php
/**
 * Optima -> Overview: the screen the Optima menu opens on (plugin-first rule 46).
 *
 * One screen an operator reads in a glance and leaves from. In order: one
 * status line (site health, updates waiting, Inbox count), the dashboards row
 * (Recon, analytics, Search Console, Business Profile, the host, the repo, the
 * site itself: each a door out, read from the theme's setup file), the queue
 * (Updates, Inbox, Requests, each a short list with its one action), and one
 * row of site facts (theme, Build, features on, last change, site lock).
 *
 * Release 2 (2026-11-05): the client answers, the everyday-job links and the
 * request box left this screen, since clients never sign in; the request form
 * stays under Inbox, Request a change. The WordPress Dashboard box still shows
 * the status line and opens here, and an operator lands here after signing in.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Overview {

	const PAGE = 'optima-overview';

	/** Rows shown per queue list. */
	const SHOW = 4;

	public static function boot(): void {
		// After Optima_Hub_Admin::menu (15): on a site without another Optima menu that is
		// where the Optima section itself is created, and a screen added before its parent
		// exists gets the wrong address (the top-level link then opens a 404).
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 16 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function menu(): void {
		add_submenu_page( Optima_Hub_Admin::parent(), __( 'Overview', 'optima-build' ), __( 'Overview', 'optima-build' ), Optima_Hub_Lock::VIEW, self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::PAGE ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_HUB_URL . 'assets/optima-admin.css' );
		}
	}

	/**
	 * An operator who signs in with no particular destination lands on Overview,
	 * not the WordPress Dashboard. A deep link (redirect_to) is left alone.
	 *
	 * @param string           $to        Where WordPress would send them.
	 * @param string           $requested What the login form asked for.
	 * @param WP_User|WP_Error $user      Who signed in.
	 */
	public static function login_redirect( $to, $requested, $user ) {
		if ( ! $user instanceof WP_User || ! class_exists( 'Optima_Hub_Access' ) || ! Optima_Hub_Access::is_operator( $user ) ) {
			return $to;
		}
		$plain = '' === (string) $requested || untrailingslashit( (string) $requested ) === untrailingslashit( admin_url() ) || untrailingslashit( (string) $requested ) === untrailingslashit( admin_url( 'index.php' ) );
		return $plain ? self::url() : $to;
	}

	/**
	 * The status line, from every check on Site health: good, warn or bad, the
	 * sentence, and the report's address. The WordPress Dashboard box shows it too.
	 *
	 * @return array{state:string, text:string, url:string}
	 */
	public static function status(): array {
		$checks = array_merge( Optima_Hub_Health::checks(), Optima_Hub_Health::ai_checks() );
		$total  = count( $checks );
		$good   = count( array_filter( $checks, static fn( $c ) => 'good' === $c['state'] ) );
		$bad    = count( array_filter( $checks, static fn( $c ) => 'bad' === $c['state'] ) );
		if ( $good === $total ) {
			/* translators: %d: number of checks */
			$text  = sprintf( __( 'Site health: all %d checks pass.', 'optima-build' ), $total );
			$state = 'good';
		} elseif ( $bad ) {
			/* translators: 1: checks passed, 2: total, 3: checks to fix */
			$text  = sprintf( __( 'Site health: %1$d of %2$d checks pass, %3$d to fix.', 'optima-build' ), $good, $total, $bad );
			$state = 'bad';
		} else {
			/* translators: 1: checks passed, 2: total, 3: checks to look at */
			$text  = sprintf( __( 'Site health: %1$d of %2$d checks pass, %3$d to look at.', 'optima-build' ), $good, $total, $total - $good );
			$state = 'warn';
		}
		return array(
			'state' => $state,
			'text'  => $text,
			'url'   => Optima_Hub_Admin::url( 'health' ),
		);
	}

	/** The status line as markup: dot, sentence, link to the report. */
	public static function status_line(): void {
		$s = self::status();
		?>
		<div class="sh-status is-<?php echo esc_attr( $s['state'] ); ?>">
			<span class="sh-dot" aria-hidden="true"></span>
			<p class="sh-value"><?php echo esc_html( $s['text'] ); ?></p>
			<p class="sh-note"><a href="<?php echo esc_url( $s['url'] ); ?>"><?php esc_html_e( 'See the report', 'optima-build' ); ?> &rsaquo;</a></p>
		</div>
		<?php
	}

	/**
	 * Installed features with an update waiting, and the last version check.
	 *
	 * @return array{waiting:int, checked:int, rows:array<int, array{slug:string, name:string, from:string, to:string, test:bool}>}
	 */
	private static function versions(): array {
		$rows    = array();
		$checked = 0;
		foreach ( Optima_Hub_Catalog::features() as $slug => $f ) {
			$st = Optima_Hub_Features::status( $slug );
			if ( '' === $st['version'] ) {
				continue;
			}
			$checked = max( $checked, (int) $st['checked'] );
			if ( '' !== $st['update'] ) {
				$rows[] = array(
					'slug' => $slug,
					'name' => (string) ( $f['name'] ?? $slug ),
					'from' => $st['version'],
					'to'   => $st['update'],
					'test' => (bool) $st['test'],
				);
			}
		}
		return array(
			'waiting' => count( $rows ),
			'checked' => $checked,
			'rows'    => $rows,
		);
	}

	/**
	 * The doors out of this site. From the theme's setup file ("dashboards":
	 * { "recon", "analytics", "search_console", "business_profile", "host" },
	 * each an https address), plus the site's repo (the theme's Update URI) and
	 * the site itself. A dashboard the file does not name is not shown; a site
	 * does not get an empty tile for a tool it does not use.
	 *
	 * @return array<int, array{label:string, url:string, note:string}>
	 */
	public static function dashboards(): array {
		$file  = class_exists( 'Optima_Hub_Setup' ) ? (array) ( Optima_Hub_Setup::data()['dashboards'] ?? array() ) : array();
		$known = array(
			'recon'            => array( __( 'Recon', 'optima-build' ), __( 'Attribution and signed cases', 'optima-build' ) ),
			'analytics'        => array( __( 'Analytics', 'optima-build' ), __( 'GA4 property', 'optima-build' ) ),
			'search_console'   => array( __( 'Search Console', 'optima-build' ), __( 'Clicks, coverage, sitemaps', 'optima-build' ) ),
			'business_profile' => array( __( 'Business Profile', 'optima-build' ), __( 'Reviews, hours, posts on Google', 'optima-build' ) ),
			'host'             => array( __( 'Host', 'optima-build' ), __( 'Staging, cache, backups', 'optima-build' ) ),
		);
		$out = array();
		foreach ( $known as $key => $words ) {
			$url = esc_url_raw( (string) ( $file[ $key ] ?? '' ), array( 'https' ) );
			if ( '' !== $url ) {
				$out[] = array( 'label' => $words[0], 'url' => $url, 'note' => $words[1] );
			}
		}
		$src = class_exists( 'Optima_Hub_Themes' ) ? Optima_Hub_Themes::source() : null;
		if ( $src ) {
			$out[] = array(
				'label' => __( 'Repo', 'optima-build' ),
				'url'   => 'https://github.com/' . $src['repo'],
				'note'  => $src['repo'],
			);
		}
		$out[] = array(
			'label' => __( 'The site', 'optima-build' ),
			'url'   => home_url( '/' ),
			'note'  => wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ?: home_url( '/' ),
		);
		return (array) apply_filters( 'optima_hub_dashboards', $out );
	}

	/**
	 * The site facts row.
	 *
	 * @return array<int, array{label:string, value:string, url:string}>
	 */
	private static function facts(): array {
		$theme = wp_get_theme();
		$src   = class_exists( 'Optima_Hub_Themes' ) ? Optima_Hub_Themes::source() : null;
		$on    = 0;
		$all   = 0;
		foreach ( array_keys( Optima_Hub_Catalog::features() ) as $slug ) {
			++$all;
			if ( 'on' === Optima_Hub_Features::status( $slug )['state'] ) {
				++$on;
			}
		}
		$log  = Optima_Hub_Features::recent_log();
		$last = $log ? (array) $log[0] : array();
		$lock = Optima_Hub_Lock::state();
		$locks = array(
			'on'      => __( 'On', 'optima-build' ),
			'waiting' => __( 'Waiting for an operator account', 'optima-build' ),
			'off'     => __( 'Off', 'optima-build' ),
		);
		return array(
			array(
				'label' => __( 'Theme', 'optima-build' ),
				'value' => ( $src ? $src['name'] : (string) $theme->get( 'Name' ) ) . ' ' . ( $src ? $src['version'] : (string) $theme->get( 'Version' ) ),
				'url'   => Optima_Hub_Admin::url( 'updates' ),
			),
			array(
				'label' => __( 'Optima Build', 'optima-build' ),
				'value' => OPTIMA_HUB_VERSION . ( 'test' === Optima_Hub_Access::channel() ? ' · ' . __( 'test channel', 'optima-build' ) : '' ),
				'url'   => Optima_Hub_Admin::url( 'updates' ),
			),
			array(
				'label' => __( 'Features on', 'optima-build' ),
				/* translators: 1: features on, 2: features in the catalog */
				'value' => sprintf( __( '%1$d of %2$d', 'optima-build' ), $on, $all ),
				'url'   => Optima_Hub_Admin::url( 'features' ),
			),
			array(
				'label' => __( 'Last change', 'optima-build' ),
				'value' => $last
					/* translators: 1: time since, 2: what changed */
					? sprintf( __( '%1$s ago · %2$s %3$s', 'optima-build' ), human_time_diff( (int) $last['time'] ), (string) $last['do'], (string) $last['slug'] )
					: __( 'None yet', 'optima-build' ),
				'url'   => Optima_Hub_Admin::url( 'updates' ),
			),
			array(
				'label' => __( 'Site lock', 'optima-build' ),
				'value' => $locks[ $lock ] ?? $lock,
				'url'   => Optima_Hub_Admin::url( 'updates' ),
			),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Optima_Hub_Lock::VIEW ) ) {
			return;
		}
		$kinds    = Optima_Hub_Inbox::kinds();
		$inbox    = Optima_Hub_Inbox::count();
		$requests = $kinds['requests'];
		$v        = self::versions();
		$s        = self::status();

		// One sentence: health, then what is waiting.
		$waiting = array();
		if ( $v['waiting'] ) {
			/* translators: %d: updates waiting */
			$waiting[] = sprintf( _n( '%d update', '%d updates', $v['waiting'], 'optima-build' ), $v['waiting'] );
		}
		if ( $inbox ) {
			/* translators: %d: items in the Inbox */
			$waiting[] = sprintf( _n( '%d Inbox item', '%d Inbox items', $inbox, 'optima-build' ), $inbox );
		}
		$lead = 'good' === $s['state'] ? __( 'Site is all right.', 'optima-build' ) : $s['text'];
		$line = $waiting
			/* translators: 1: health sentence, 2: list of what is waiting */
			? sprintf( __( '%1$s %2$s waiting on you.', 'optima-build' ), $lead, implode( __( ' and ', 'optima-build' ), $waiting ) )
			/* translators: %s: health sentence */
			: sprintf( __( '%s Nothing is waiting.', 'optima-build' ), $lead );

		Optima_Admin_UI::open(
			__( 'Overview', 'optima-build' ),
			__( 'The site in a glance: is it all right, what is waiting, and the doors to everything else.', 'optima-build' )
		);
		self::notice();
		$note = Optima_Hub_Lock::waiting_note();
		if ( '' !== $note ) {
			echo '<p class="sh-note">' . esc_html( $note ) . '</p>';
		}
		?>
		<section class="sh-box sh-glance-line">
			<div class="sh-status is-<?php echo esc_attr( $s['state'] ); ?>">
				<span class="sh-dot" aria-hidden="true"></span>
				<p class="sh-value"><?php echo esc_html( $line ); ?></p>
				<p class="sh-note">
					<?php
					echo esc_html(
						$v['checked']
							/* translators: %s: time since the last version check */
							? sprintf( __( 'Checked %s ago', 'optima-build' ), human_time_diff( $v['checked'] ) )
							: __( 'Versions not checked yet', 'optima-build' )
					);
					?>
					· <a href="<?php echo esc_url( $s['url'] ); ?>"><?php esc_html_e( 'Site health', 'optima-build' ); ?> &rsaquo;</a>
				</p>
			</div>
		</section>

		<?php $doors = self::dashboards(); ?>
		<div class="sh-section-head"><p class="sh-label"><?php esc_html_e( 'Dashboards for this site', 'optima-build' ); ?></p></div>
		<div class="sh-grid sh-glance-doors">
			<?php foreach ( $doors as $door ) : ?>
				<a class="sh-box sh-link-card" href="<?php echo esc_url( $door['url'] ); ?>" target="_blank" rel="noopener">
					<h2><?php echo esc_html( $door['label'] ); ?></h2>
					<p><?php echo esc_html( $door['note'] ); ?></p>
				</a>
			<?php endforeach; ?>
		</div>
		<?php if ( count( $doors ) < 3 ) : ?>
			<p class="sh-note"><?php esc_html_e( 'Name this site\'s dashboards in the theme\'s setup file ("dashboards": recon, analytics, search_console, business_profile, host) and they show here.', 'optima-build' ); ?></p>
		<?php endif; ?>

		<div class="sh-grid sh-grid-3 sh-glance-queue">
			<section class="sh-box">
				<div class="sh-section-row">
					<h2><?php esc_html_e( 'Updates', 'optima-build' ); ?> <span class="sh-count"><?php echo esc_html( (string) $v['waiting'] ); ?></span></h2>
					<a href="<?php echo esc_url( Optima_Hub_Admin::url( 'updates' ) ); ?>"><?php esc_html_e( 'Build', 'optima-build' ); ?> &rsaquo;</a>
				</div>
				<?php if ( $v['rows'] ) : ?>
					<ul class="sh-waiting">
						<?php foreach ( array_slice( $v['rows'], 0, self::SHOW ) as $row ) : ?>
							<li>
								<span><?php echo esc_html( $row['name'] ); ?></span>
								<span class="sh-ver"><?php echo esc_html( $row['from'] . ' → ' . $row['to'] ); ?><?php echo $row['test'] ? ' <span class="sh-tag is-held">' . esc_html__( 'Test', 'optima-build' ) . '</span>' : ''; ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="sh-actions"><a class="button button-primary" href="<?php echo esc_url( Optima_Hub_Admin::url( 'updates' ) ); ?>"><?php esc_html_e( 'Apply updates', 'optima-build' ); ?></a></p>
				<?php else : ?>
					<p class="sh-note"><?php esc_html_e( 'Every feature is on its newest version.', 'optima-build' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="sh-box">
				<div class="sh-section-row">
					<h2><?php esc_html_e( 'Inbox', 'optima-build' ); ?> <span class="sh-count"><?php echo esc_html( (string) $inbox ); ?></span></h2>
					<a href="<?php echo esc_url( Optima_Hub_Inbox::url() ); ?>"><?php esc_html_e( 'Open', 'optima-build' ); ?> &rsaquo;</a>
				</div>
				<?php
				$rows = array();
				foreach ( $kinds as $key => $kind ) {
					if ( 'requests' === $key ) {
						continue;
					}
					foreach ( $kind['rows'] as $row ) {
						$rows[] = array( 'kind' => $kind['label'], 'title' => $row['title'], 'note' => $row['note'], 'url' => $row['url'] );
					}
				}
				?>
				<?php if ( $rows ) : ?>
					<ul class="sh-waiting">
						<?php foreach ( array_slice( $rows, 0, self::SHOW ) as $row ) : ?>
							<li>
								<div>
									<a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( '' !== $row['title'] ? $row['title'] : __( '(no title)', 'optima-build' ) ); ?></a>
									<p class="sh-note"><?php echo esc_html( $row['kind'] . ' · ' . $row['note'] ); ?></p>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="sh-note"><?php esc_html_e( 'Nothing waiting.', 'optima-build' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="sh-box">
				<div class="sh-section-row">
					<h2><?php esc_html_e( 'Requests', 'optima-build' ); ?> <span class="sh-count"><?php echo esc_html( (string) $requests['count'] ); ?></span></h2>
					<a href="<?php echo esc_url( $requests['url'] ); ?>"><?php esc_html_e( 'All', 'optima-build' ); ?> &rsaquo;</a>
				</div>
				<?php if ( $requests['rows'] ) : ?>
					<ul class="sh-waiting">
						<?php foreach ( array_slice( $requests['rows'], 0, self::SHOW ) as $row ) : ?>
							<li>
								<div>
									<a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
									<p class="sh-note"><?php echo esc_html( $row['note'] ); ?></p>
								</div>
								<span class="sh-pill is-<?php echo esc_attr( $row['state'] ); ?>"><?php echo esc_html( $row['pill'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="sh-note"><?php esc_html_e( 'No open requests.', 'optima-build' ); ?></p>
				<?php endif; ?>
			</section>
		</div>

		<section class="sh-box sh-glance-facts">
			<?php foreach ( self::facts() as $fact ) : ?>
				<a href="<?php echo esc_url( $fact['url'] ); ?>">
					<span class="sh-label"><?php echo esc_html( $fact['label'] ); ?></span>
					<strong><?php echo esc_html( $fact['value'] ); ?></strong>
				</a>
			<?php endforeach; ?>
		</section>
		<?php
		Optima_Admin_UI::close();
	}

	/** The one-time message after an action that returns here. */
	private static function notice(): void {
		$key    = 'optima_hub_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), esc_html( $notice['message'] ) );
		}
	}
}
