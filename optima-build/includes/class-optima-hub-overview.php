<?php
/**
 * Optima -> Overview: the screen the Optima menu opens on (plugin-first rule 46).
 *
 * It answers three things first: is the site all right (one status line from
 * Site health), what needs the client (Needs you), and what Optima is doing
 * (With Optima: open change requests and where each stands). Then the four
 * everyday jobs as links, each shown only when its feature is on, and one
 * request box that posts to the existing Request a change handler. Operators
 * also see a small strip: updates waiting and the last version check.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Overview {

	const PAGE = 'optima-overview';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 14 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
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
	 * The four everyday jobs, each only when its feature is on and the person
	 * can do it.
	 *
	 * @return array<int, array{title:string, note:string, url:string}>
	 */
	public static function jobs(): array {
		$jobs = array();
		if ( class_exists( 'Optima_Team' ) && post_type_exists( Optima_Team::POST_TYPE ) && current_user_can( 'edit_posts' ) ) {
			$jobs[] = array(
				'title' => __( 'Edit a team bio', 'optima-build' ),
				'note'  => __( 'Change a name, title, photo or bio on the team pages.', 'optima-build' ),
				'url'   => admin_url( 'edit.php?post_type=' . Optima_Team::POST_TYPE ),
			);
		}
		if ( current_user_can( 'edit_posts' ) ) {
			$jobs[] = array(
				'title' => __( 'Write a post', 'optima-build' ),
				'note'  => __( 'Start a new blog post. It stays a draft until you publish it.', 'optima-build' ),
				'url'   => admin_url( 'post-new.php' ),
			);
		}
		if ( class_exists( 'Optima_Hours' ) && current_user_can( 'manage_options' ) ) {
			$jobs[] = array(
				'title' => __( 'Change hours', 'optima-build' ),
				'note'  => __( 'Weekly hours, holidays and closures, in the footer and on Google.', 'optima-build' ),
				'url'   => admin_url( 'admin.php?page=optima-hours' ),
			);
		}
		if ( class_exists( 'Optima_Reviews_Requests' ) && current_user_can( 'manage_options' ) ) {
			$jobs[] = array(
				'title' => __( 'Ask for a review', 'optima-build' ),
				'note'  => __( 'Your review link, QR code and ready-to-send messages.', 'optima-build' ),
				'url'   => admin_url( 'admin.php?page=' . Optima_Reviews_Requests::PAGE ),
			);
		}
		return $jobs;
	}

	/** Updates waiting and the last version check, for the operator strip. */
	private static function versions(): array {
		$installed = array_filter(
			array_map( array( 'Optima_Hub_Features', 'status' ), array_keys( Optima_Hub_Catalog::features() ) ),
			static fn( $st ) => '' !== $st['version']
		);
		return array(
			'waiting' => count( array_filter( $installed, static fn( $st ) => '' !== $st['update'] ) ),
			'checked' => $installed ? (int) max( array_column( $installed, 'checked' ) ) : 0,
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Optima_Hub_Lock::VIEW ) ) {
			return;
		}
		$operator = Optima_Hub_Access::is_operator();
		$brand    = Optima_Hub_Admin::branded() ? __( 'Optima', 'optima-build' ) : __( 'us', 'optima-build' );
		$kinds    = Optima_Hub_Inbox::kinds();
		$needs    = array_filter( $kinds, static fn( $k ) => 'you' === $k['who'] && $k['count'] > 0 );
		$hours    = class_exists( 'Optima_Hours' ) && method_exists( 'Optima_Hours', 'settings' ) && empty( Optima_Hours::settings()['confirmed'] );
		$requests = $kinds['requests'];
		Optima_Admin_UI::open(
			__( 'Overview', 'optima-build' ),
			__( 'How the site is doing, what needs you, and what we are working on.', 'optima-build' )
		);
		self::notice();
		?>
		<?php $waiting = Optima_Hub_Lock::waiting_note(); ?>
		<?php if ( '' !== $waiting ) : ?>
			<p class="sh-note"><?php echo esc_html( $waiting ); ?></p>
		<?php endif; ?>
		<?php if ( Optima_Hub_Lock::applies() ) : ?>
			<p class="sh-note">
				<?php
				/* translators: %s: Optima, or "us" on an unbranded site */
				echo esc_html( sprintf( __( 'Changes to this site go through %s. Send a request below.', 'optima-build' ), $brand ) );
				?>
			</p>
		<?php endif; ?>
		<section class="sh-box">
			<?php self::status_line(); ?>
		</section>

		<?php if ( $operator ) : ?>
			<?php $v = self::versions(); ?>
			<section class="sh-box sh-ops">
				<span class="sh-label"><?php esc_html_e( 'Build', 'optima-build' ); ?></span>
				<span class="sh-tag <?php echo $v['waiting'] ? 'is-update' : 'is-current'; ?>">
					<?php
					echo esc_html(
						$v['waiting']
							/* translators: %d: updates waiting */
							? sprintf( _n( '%d update waiting', '%d updates waiting', $v['waiting'], 'optima-build' ), $v['waiting'] )
							: __( 'No updates waiting', 'optima-build' )
					);
					?>
				</span>
				<span class="sh-note">
					<?php
					echo esc_html(
						$v['checked']
							/* translators: %s: time since, e.g. "5 minutes" */
							? sprintf( __( 'Last check %s ago', 'optima-build' ), human_time_diff( $v['checked'] ) )
							: __( 'Not checked yet', 'optima-build' )
					);
					?>
				</span>
				<a class="button sh-quiet" href="<?php echo esc_url( Optima_Hub_Admin::url( 'updates' ) ); ?>"><?php esc_html_e( 'Open Build', 'optima-build' ); ?></a>
			</section>
		<?php endif; ?>

		<div class="sh-grid sh-grid-2">
			<section class="sh-box">
				<p class="sh-label"><?php esc_html_e( 'Needs you', 'optima-build' ); ?></p>
				<?php if ( $needs || $hours ) : ?>
					<ul class="sh-waiting">
						<?php foreach ( $needs as $kind ) : ?>
							<li>
								<?php if ( current_user_can( $kind['cap'] ) ) : ?>
									<a href="<?php echo esc_url( $kind['url'] ); ?>"><?php echo esc_html( $kind['label'] ); ?></a>
								<?php else : ?>
									<span><?php echo esc_html( $kind['label'] ); ?></span>
								<?php endif; ?>
								<span class="sh-pill is-received"><?php echo esc_html( (string) $kind['count'] ); ?></span>
							</li>
						<?php endforeach; ?>
						<?php if ( $hours ) : ?>
							<li>
								<?php if ( current_user_can( 'manage_options' ) ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=optima-hours' ) ); ?>"><?php esc_html_e( 'Hours not confirmed yet', 'optima-build' ); ?></a>
								<?php else : ?>
									<span><?php esc_html_e( 'Hours not confirmed yet', 'optima-build' ); ?></span>
								<?php endif; ?>
								<span class="sh-pill is-received"><?php esc_html_e( 'Check', 'optima-build' ); ?></span>
							</li>
						<?php endif; ?>
					</ul>
					<p class="sh-note"><a href="<?php echo esc_url( Optima_Hub_Inbox::url() ); ?>"><?php esc_html_e( 'Open Inbox', 'optima-build' ); ?> &rsaquo;</a></p>
				<?php else : ?>
					<p class="sh-note"><?php esc_html_e( 'Nothing needs you right now.', 'optima-build' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="sh-box">
				<p class="sh-label">
					<?php
					echo esc_html( Optima_Hub_Admin::branded() ? __( 'With Optima', 'optima-build' ) : __( 'With us', 'optima-build' ) );
					?>
				</p>
				<?php if ( $requests['rows'] ) : ?>
					<ul class="sh-waiting">
						<?php foreach ( $requests['rows'] as $row ) : ?>
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
					<p class="sh-note"><?php esc_html_e( 'No open requests. Anything you send shows here with where it stands.', 'optima-build' ); ?></p>
				<?php endif; ?>
				<p class="sh-note"><a href="<?php echo esc_url( Optima_Hub_Admin::url( 'requests' ) ); ?>"><?php esc_html_e( 'Every request', 'optima-build' ); ?> &rsaquo;</a></p>
			</section>
		</div>

		<?php $jobs = self::jobs(); ?>
		<?php if ( $jobs ) : ?>
			<div class="sh-section-head">
				<p class="sh-eyebrow"><?php esc_html_e( 'Everyday jobs', 'optima-build' ); ?></p>
			</div>
			<div class="sh-grid sh-grid-3">
				<?php foreach ( $jobs as $job ) : ?>
					<a class="sh-box sh-link-card" href="<?php echo esc_url( $job['url'] ); ?>">
						<h2><?php echo esc_html( $job['title'] ); ?></h2>
						<p><?php echo esc_html( $job['note'] ); ?></p>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<section class="sh-box" id="ask">
			<h2><?php esc_html_e( 'Request a change', 'optima-build' ); ?></h2>
			<p>
				<?php
				/* translators: %s: Optima, or "us" on an unbranded site */
				echo esc_html( sprintf( __( 'Want something added? Ask %s', 'optima-build' ), $brand ) );
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sh-form sh-ask">
				<input type="hidden" name="action" value="<?php echo esc_attr( Optima_Hub_Requests::ACTION ); ?>">
				<input type="hidden" name="do" value="new">
				<input type="hidden" name="back" value="overview">
				<?php wp_nonce_field( Optima_Hub_Requests::ACTION ); ?>
				<label for="sh-ask-what" class="screen-reader-text"><?php esc_html_e( 'What would you like changed or added?', 'optima-build' ); ?></label>
				<textarea id="sh-ask-what" name="what" rows="3" required placeholder="<?php esc_attr_e( 'Tell us what and where. We will take it from here.', 'optima-build' ); ?>"></textarea>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Send request', 'optima-build' ); ?></button></p>
			</form>
		</section>
		<?php
		Optima_Admin_UI::close();
	}

	/** The one-time message after a request is sent from this screen. */
	private static function notice(): void {
		$key    = 'optima_hub_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), esc_html( $notice['message'] ) );
		}
	}
}
