<?php
/**
 * Optima -> Your business -> Team -> Add a person: a three-step walkthrough.
 *
 * Adding a person touches several places at once (the team page, their profile
 * page, the author box, the Person schema). Done field by field something is
 * always missed; done as one long form nothing can be skipped. So the steps are
 * short and each can be left for later:
 *
 *   1. Who: a person the theme's setup file (optima-setup.json, exported from
 *      the client's Knowledge Hub) names and the site does not have yet, or
 *      someone not in the file. Facts come from the file; nothing is retyped.
 *   2. Photo, byline and place: the headshot, the WordPress user whose posts
 *      carry this byline (usually nobody), the attorney flag on a law site, and
 *      the spot on the team page.
 *   3. Check and publish: the trust checklist on what was gathered, then one
 *      button that creates the profile as a draft or publishes it.
 *
 * State lives in one transient per operator for a day, so a half-finished
 * walkthrough is still there after lunch. The profile post is created only at
 * the last step; leaving early creates nothing.
 *
 * The screen is a tab of the Team screen (admin.php?page=optima-team&tab=add),
 * so it adds no sidebar address (plugin-first rule 10) and the shared nav
 * lights up Your business as usual.
 *
 * @package Optima_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Team_Add {

	const TAB    = 'add';
	const ACTION = 'optima_team_add';

	/** Steps in order: key => title. */
	const STEPS = array(
		1 => 'Who',
		2 => 'Photo, byline and place',
		3 => 'Check and publish',
	);

	/** How long a half-finished walkthrough waits. */
	const KEEP = DAY_IN_SECONDS;

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** The walkthrough's address. */
	public static function url( int $step = 0 ): string {
		$url = add_query_arg( array( 'page' => Optima_Team_Admin::SLUG, 'tab' => self::TAB ), admin_url( 'admin.php' ) );
		return $step ? add_query_arg( 'step', (string) $step, $url ) : $url;
	}

	/** Is the Team screen showing this tab? */
	public static function is_open(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen address only.
		return isset( $_GET['tab'] ) && self::TAB === sanitize_key( wp_unslash( $_GET['tab'] ) );
	}

	/** The walkthrough's few styles, and the media picker for the photo step. */
	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, Optima_Team_Admin::SLUG ) || ( ! self::is_open() && ! Optima_Team_Offboard::is_open() ) ) {
			return;
		}
		wp_enqueue_style( 'optima-team-add', OPTIMA_TEAM_URL . 'assets/optima-team-add.css', array( 'optima-admin-kit' ), OPTIMA_TEAM_VERSION );
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
	}

	/* ---------- state ---------- */

	private static function key(): string {
		return 'optima_team_add_' . get_current_user_id();
	}

	/** What the walkthrough has so far, always in the full shape. */
	public static function state(): array {
		$saved = get_transient( self::key() );
		return self::clean( is_array( $saved ) ? $saved : array() );
	}

	private static function save( array $state ): void {
		set_transient( self::key(), self::clean( $state ), self::KEEP );
	}

	public static function forget(): void {
		delete_transient( self::key() );
	}

	/**
	 * The one shape of the walkthrough's data. Everything saved or posted goes
	 * through here, so a stale transient from an older version never breaks the
	 * screen.
	 *
	 * @param array $in Raw state.
	 */
	public static function clean( array $in ): array {
		$step   = (int) ( $in['step'] ?? 1 );
		$source = (string) ( $in['source'] ?? '' );
		return array(
			'step'     => isset( self::STEPS[ $step ] ) ? $step : 1,
			'source'   => in_array( $source, array( 'setup', 'new' ), true ) ? $source : '',
			'name'     => sanitize_text_field( (string) ( $in['name'] ?? '' ) ),
			'role'     => sanitize_text_field( (string) ( $in['role'] ?? '' ) ),
			'bio'      => sanitize_textarea_field( (string) ( $in['bio'] ?? '' ) ),
			'email'    => sanitize_email( (string) ( $in['email'] ?? '' ) ),
			'branch'   => sanitize_key( (string) ( $in['branch'] ?? '' ) ),
			'photo'    => sanitize_text_field( (string) ( $in['photo'] ?? '' ) ), // Theme path or https address, from the setup file.
			'photo_id' => absint( $in['photo_id'] ?? 0 ),                         // A media library choice wins over the file.
			'attorney' => ! empty( $in['attorney'] ) ? 1 : 0,
			'user'     => absint( $in['user'] ?? 0 ),
			'order'    => (int) ( $in['order'] ?? 0 ),
		);
	}

	/* ---------- who ---------- */

	/**
	 * People the setup file names that the site does not have yet.
	 *
	 * @param array    $people   The file's "team" section.
	 * @param string[] $existing Names already on the site.
	 * @return array<int, array{name:string, role:string, bio:string, email:string, branch:string, photo:string, order:int, has:string[]}>
	 */
	public static function candidates( array $people, array $existing ): array {
		$have = array_map( 'strtolower', array_map( 'trim', $existing ) );
		$out  = array();
		foreach ( $people as $p ) {
			$p    = (array) $p;
			$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
			if ( '' === $name || in_array( strtolower( $name ), $have, true ) ) {
				continue;
			}
			$row = array(
				'name'   => $name,
				'role'   => sanitize_text_field( (string) ( $p['role'] ?? '' ) ),
				'bio'    => sanitize_textarea_field( (string) ( $p['bio'] ?? '' ) ),
				'email'  => sanitize_email( (string) ( $p['email'] ?? '' ) ),
				'branch' => sanitize_key( (string) ( $p['military_branch'] ?? '' ) ),
				'photo'  => sanitize_text_field( (string) ( $p['photo'] ?? '' ) ),
				'order'  => (int) ( $p['order'] ?? 0 ),
			);
			$has = array();
			foreach ( array( 'role', 'bio', 'email', 'photo', 'branch' ) as $key ) {
				if ( '' !== $row[ $key ] ) {
					$has[] = $key;
				}
			}
			$row['has'] = $has;
			$out[]      = $row;
		}
		return $out;
	}

	/** Names already on the site, any status. */
	private static function existing_names(): array {
		$posts = get_posts(
			array(
				'post_type'      => Optima_Team::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 300,
				'no_found_rows'  => true,
			)
		);
		return array_map( static fn( $p ) => (string) $p->post_title, $posts );
	}

	/** The setup file's team section, when Optima Build is on. */
	private static function setup_people(): array {
		if ( ! class_exists( 'Optima_Hub_Setup' ) ) {
			return array();
		}
		return (array) ( Optima_Hub_Setup::data()['team'] ?? array() );
	}

	/* ---------- the checks before publishing ---------- */

	/**
	 * The trust checklist, on the gathered data rather than a saved post, so the
	 * operator sees it before anything exists.
	 *
	 * @return array<string, array{0:string,1:string,2:bool}>
	 */
	public static function checks( array $state ): array {
		$checks = array(
			'photo'  => array( __( 'Real photo', 'optima-team' ), __( 'A photo of the person, never stock', 'optima-team' ), $state['photo_id'] > 0 || '' !== $state['photo'] ),
			'bio'    => array( __( 'Bio and role', 'optima-team' ), __( 'A role and a short bio in plain language', 'optima-team' ), '' !== $state['role'] && '' !== $state['bio'] ),
			'byline' => array( __( 'Byline on posts', 'optima-team' ), __( 'Only when this person writes posts under their own login', 'optima-team' ), $state['user'] > 0 ),
		);
		if ( Optima_Team::is_law() && $state['attorney'] ) {
			$checks['bar'] = array( __( 'Bar admissions', 'optima-team' ), __( 'Added on the profile after this, from the setup file', 'optima-team' ), false );
		}
		return $checks;
	}

	/* ---------- the form handler ---------- */

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'optima-team' ) );
		}
		check_admin_referer( self::ACTION );
		$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$state = self::state();

		if ( 'cancel' === $do ) {
			self::forget();
			self::notice( 'success', __( 'Walkthrough closed. Nothing was created.', 'optima-team' ) );
			wp_safe_redirect( Optima_Team_Admin::url() );
			exit;
		}

		if ( 'back' === $do ) {
			$state['step'] = max( 1, $state['step'] - 1 );
			self::save( $state );
			wp_safe_redirect( self::url() );
			exit;
		}

		if ( 'who' === $do ) {
			$pick = isset( $_POST['who'] ) ? sanitize_text_field( wp_unslash( $_POST['who'] ) ) : '';
			if ( 'new' === $pick ) {
				$state['source'] = 'new';
				$state['name']   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
				$state['role']   = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';
				$state['bio']    = '';
				$state['email']  = '';
				$state['branch'] = '';
				$state['photo']  = '';
				$state['order']  = 0;
				if ( '' === $state['name'] ) {
					self::notice( 'error', __( 'A name is needed to add someone who is not in the setup file.', 'optima-team' ) );
					wp_safe_redirect( self::url() );
					exit;
				}
			} else {
				$found = null;
				foreach ( self::candidates( self::setup_people(), self::existing_names() ) as $c ) {
					if ( $c['name'] === $pick ) {
						$found = $c;
						break;
					}
				}
				if ( ! $found ) {
					self::notice( 'error', __( 'Pick a person from the list, or choose "Someone not in the setup file".', 'optima-team' ) );
					wp_safe_redirect( self::url() );
					exit;
				}
				$state['source'] = 'setup';
				foreach ( array( 'name', 'role', 'bio', 'email', 'branch', 'photo', 'order' ) as $key ) {
					$state[ $key ] = $found[ $key ];
				}
				// The file's email names a WordPress user: that is the byline, unless step 2 changes it.
				$user          = '' !== $state['email'] ? get_user_by( 'email', $state['email'] ) : false;
				$state['user'] = $user ? (int) $user->ID : 0;
			}
			$state['attorney'] = Optima_Team::is_law() && preg_match( '/attorney|lawyer|counsel|partner|associate/i', $state['role'] ) ? 1 : 0;
			$state['step']     = 2;
			self::save( $state );
			wp_safe_redirect( self::url() );
			exit;
		}

		if ( 'place' === $do ) {
			$state['photo_id'] = isset( $_POST['photo_id'] ) ? absint( wp_unslash( $_POST['photo_id'] ) ) : 0;
			$state['user']     = isset( $_POST['user'] ) ? absint( wp_unslash( $_POST['user'] ) ) : 0;
			$state['attorney'] = ! empty( $_POST['attorney'] ) ? 1 : 0;
			$state['order']    = isset( $_POST['order'] ) ? (int) wp_unslash( $_POST['order'] ) : 0;
			$state['step']     = 3;
			self::save( $state );
			wp_safe_redirect( self::url() );
			exit;
		}

		if ( 'create' === $do ) {
			if ( '' === $state['name'] ) {
				wp_safe_redirect( self::url() );
				exit;
			}
			$publish = ! empty( $_POST['publish'] );
			$id      = self::create( $state, $publish ? 'publish' : 'draft' );
			if ( ! $id ) {
				self::notice( 'error', __( 'The profile could not be created. Nothing changed.', 'optima-team' ) );
				wp_safe_redirect( self::url() );
				exit;
			}
			self::forget();
			self::notice(
				'success',
				$publish
					/* translators: %s: person's name */
					? sprintf( __( '%s is on the team page. Fill in the rest of the profile below.', 'optima-team' ), $state['name'] )
					/* translators: %s: person's name */
					: sprintf( __( '%s is saved as a draft. Publish from this screen when the profile is ready.', 'optima-team' ), $state['name'] )
			);
			wp_safe_redirect( (string) get_edit_post_link( $id, 'raw' ) );
			exit;
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Make the profile post from the gathered data. Returns the post ID, or 0.
	 */
	private static function create( array $state, string $status ): int {
		$id = (int) wp_insert_post(
			array(
				'post_type'    => Optima_Team::POST_TYPE,
				'post_status'  => 'publish' === $status ? 'publish' : 'draft',
				'post_title'   => $state['name'],
				'post_content' => $state['bio'],
				'menu_order'   => $state['order'],
			),
			true
		);
		if ( $id <= 0 || is_wp_error( $id ) ) {
			return 0;
		}
		$profile = Optima_Team::empty_profile();
		$profile['role']            = $state['role'];
		$profile['bio']             = $state['bio'];
		$profile['email']           = $state['email'];
		$profile['user']            = $state['user'];
		$profile['attorney']        = $state['attorney'];
		$profile['military_branch'] = $state['branch'];
		// The sanitizer takes these two lists as one-per-line text.
		$profile['memberships'] = '';
		$profile['same_as']     = '';
		update_post_meta( $id, Optima_Team::META, Optima_Team::sanitize_profile( $profile ) );

		$photo = $state['photo_id'] ? $state['photo_id'] : self::sideload( $state['photo'], $id );
		if ( $photo ) {
			set_post_thumbnail( $id, $photo );
		}
		return $id;
	}

	/**
	 * A photo named in the setup file (a path inside the theme, or an https
	 * address) into the media library, once. Returns the attachment ID or 0.
	 */
	private static function sideload( string $photo, int $post_id ): int {
		if ( '' === $photo ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( preg_match( '#^https?://#i', $photo ) ) {
			$att = media_sideload_image( esc_url_raw( $photo ), $post_id, null, 'id' );
			return is_wp_error( $att ) ? 0 : (int) $att;
		}
		$rel = ltrim( str_replace( '..', '', $photo ), '/' );
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			$path = trailingslashit( $dir ) . $rel;
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$tmp = wp_tempnam( basename( $path ) );
			if ( ! $tmp || ! copy( $path, $tmp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- a theme file into a temp file for sideloading.
				return 0;
			}
			$att = media_handle_sideload(
				array(
					'name'     => basename( $path ),
					'tmp_name' => $tmp,
				),
				$post_id
			);
			return is_wp_error( $att ) ? 0 : (int) $att;
		}
		return 0;
	}

	/** One-time message after a redirect, the same way Overview does it. */
	private static function notice( string $type, string $message ): void {
		set_transient( 'optima_team_add_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
	}

	public static function print_notice(): void {
		$key    = 'optima_team_add_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), esc_html( $notice['message'] ) );
		}
	}

	/* ---------- the screen ---------- */

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = self::state();
		Optima_Admin_UI::open(
			__( 'Add a person', 'optima-team' ),
			__( 'Three short steps. Facts come from the setup file, so you pick and connect rather than type. Leave at any step and it waits here for a day; the profile is created only at the end.', 'optima-team' ),
			'',
			Optima_Team_Admin::SLUG
		);
		self::print_notice();
		self::stepper( $state['step'] );
		switch ( $state['step'] ) {
			case 2:
				self::step_place( $state );
				break;
			case 3:
				self::step_check( $state );
				break;
			default:
				self::step_who();
		}
		Optima_Admin_UI::close();
	}

	private static function stepper( int $current ): void {
		echo '<ol class="st-add-steps">';
		foreach ( self::STEPS as $n => $title ) {
			$class = $n < $current ? 'is-done' : ( $n === $current ? 'is-current' : '' );
			printf(
				'<li class="%1$s"%2$s><span class="st-add-n" aria-hidden="true">%3$d</span><span>%4$s</span></li>',
				esc_attr( $class ),
				$n === $current ? ' aria-current="step"' : '',
				(int) $n,
				esc_html( __( $title, 'optima-team' ) ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the titles are literal strings in STEPS.
			);
		}
		echo '</ol>';
	}

	private static function form_open( string $do ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sh-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( self::ACTION );
	}

	private static function nav_buttons( bool $back, string $next ): void {
		echo '<p class="sh-actions">';
		if ( $back ) {
			echo '<button type="submit" name="do" value="back" class="button sh-quiet" formnovalidate>' . esc_html__( 'Back', 'optima-team' ) . '</button> ';
		}
		echo '<button type="submit" class="button button-primary">' . esc_html( $next ) . '</button> ';
		echo '<button type="submit" name="do" value="cancel" class="button-link sh-quiet" formnovalidate>' . esc_html__( 'Cancel and create nothing', 'optima-team' ) . '</button>';
		echo '</p>';
	}

	private static function step_who(): void {
		$candidates = self::candidates( self::setup_people(), self::existing_names() );
		$labels     = array(
			'role'   => __( 'role', 'optima-team' ),
			'bio'    => __( 'bio', 'optima-team' ),
			'email'  => __( 'email', 'optima-team' ),
			'photo'  => __( 'photo', 'optima-team' ),
			'branch' => __( 'military service', 'optima-team' ),
		);
		self::form_open( 'who' );
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Who is this?', 'optima-team' ); ?></h2>
			<?php if ( $candidates ) : ?>
				<p><?php esc_html_e( 'People the theme\'s setup file names who are not on the site yet. Pick one and their facts come with them.', 'optima-team' ); ?></p>
				<ul class="st-add-choices">
					<?php foreach ( $candidates as $i => $c ) : ?>
						<li>
							<label>
								<input type="radio" name="who" value="<?php echo esc_attr( $c['name'] ); ?>" <?php checked( 0 === $i ); ?> required>
								<strong><?php echo esc_html( $c['name'] ); ?></strong>
								<?php if ( '' !== $c['role'] ) : ?><span class="sh-note"><?php echo esc_html( $c['role'] ); ?></span><?php endif; ?>
								<span class="sh-note">
									<?php
									$has = array_map( static fn( $k ) => $labels[ $k ], $c['has'] );
									/* translators: %s: list of facts the file holds */
									echo esc_html( $has ? sprintf( __( 'Comes with: %s.', 'optima-team' ), implode( ', ', $has ) ) : __( 'The file holds only the name.', 'optima-team' ) );
									?>
								</span>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="sh-note">
					<?php
					echo esc_html(
						class_exists( 'Optima_Hub_Setup' )
							? __( 'Everyone the setup file names is already on the site.', 'optima-team' )
							: __( 'Optima Build is not on, so there is no setup file to pick from.', 'optima-team' )
					);
					?>
				</p>
			<?php endif; ?>
			<fieldset class="st-add-new">
				<label><input type="radio" name="who" value="new" <?php checked( ! $candidates ); ?>> <strong><?php esc_html_e( 'Someone not in the setup file', 'optima-team' ); ?></strong></label>
				<p class="sh-note"><?php esc_html_e( 'Starts the profile from a name and a role. Credentials stay blank until the firm confirms them and the setup file carries them.', 'optima-team' ); ?></p>
				<p>
					<label for="st-add-name"><?php esc_html_e( 'Full name', 'optima-team' ); ?></label><br>
					<input type="text" id="st-add-name" name="name" class="regular-text">
				</p>
				<p>
					<label for="st-add-role"><?php esc_html_e( 'Title', 'optima-team' ); ?></label><br>
					<input type="text" id="st-add-role" name="role" class="regular-text" placeholder="<?php esc_attr_e( 'Associate Attorney', 'optima-team' ); ?>">
				</p>
			</fieldset>
			<?php self::nav_buttons( false, __( 'Next: photo, byline and place', 'optima-team' ) ); ?>
		</section>
		</form>
		<?php
	}

	private static function step_place( array $state ): void {
		$law = Optima_Team::is_law();
		self::form_open( 'place' );
		?>
		<section class="sh-box">
			<h2 class="sh-title"><?php echo esc_html( $state['name'] ); ?></h2>
			<p class="sh-note">
				<?php
				echo esc_html(
					'setup' === $state['source']
						? __( 'From the setup file. Change a fact there, not here.', 'optima-team' )
						: __( 'Not in the setup file yet. Facts stay blank until it carries them.', 'optima-team' )
				);
				?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Photo', 'optima-team' ); ?></th>
					<td>
						<?php if ( $state['photo_id'] ) : ?>
							<?php echo wp_get_attachment_image( $state['photo_id'], array( 96, 96 ), false, array( 'class' => 'st-thumb', 'alt' => '' ) ); ?>
						<?php elseif ( '' !== $state['photo'] ) : ?>
							<p class="sh-note">
								<?php
								/* translators: %s: file name or address */
								echo esc_html( sprintf( __( 'The setup file names %s. It is brought into the media library when the profile is created.', 'optima-team' ), $state['photo'] ) );
								?>
							</p>
						<?php else : ?>
							<p class="sh-note"><?php esc_html_e( 'No photo yet. A real photo of the person; never stock.', 'optima-team' ); ?></p>
						<?php endif; ?>
						<input type="hidden" name="photo_id" id="st-add-photo-id" value="<?php echo esc_attr( (string) $state['photo_id'] ); ?>">
						<?php if ( current_user_can( 'upload_files' ) ) : ?>
							<p>
								<button type="button" class="button" id="st-add-photo-pick"><?php echo esc_html( $state['photo_id'] ? __( 'Choose a different photo', 'optima-team' ) : __( 'Choose from the media library', 'optima-team' ) ); ?></button>
								<?php if ( $state['photo_id'] ) : ?><button type="button" class="button-link sh-quiet" id="st-add-photo-clear"><?php esc_html_e( 'Remove', 'optima-team' ); ?></button><?php endif; ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="st-add-user"><?php esc_html_e( 'Byline', 'optima-team' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_users(
							array(
								'name'              => 'user',
								'id'                => 'st-add-user',
								'selected'          => (int) $state['user'],
								'show_option_none'  => __( 'Nobody (no byline)', 'optima-team' ),
								'option_none_value' => '0',
								'capability'        => array( 'edit_posts' ),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'A profile is a record, not an account. Link a WordPress user only when this person publishes posts under their own login; their posts then carry this profile as the byline.', 'optima-team' ); ?></p>
					</td>
				</tr>
				<?php if ( $law ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Attorney', 'optima-team' ); ?></th>
						<td><label><input type="checkbox" name="attorney" value="1" <?php checked( $state['attorney'] ); ?>> <?php esc_html_e( 'This person is an attorney: bar admissions show on the profile', 'optima-team' ); ?></label></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="st-add-order"><?php esc_html_e( 'Place on the team page', 'optima-team' ); ?></label></th>
					<td>
						<input type="number" id="st-add-order" name="order" value="<?php echo esc_attr( (string) $state['order'] ); ?>" class="small-text" step="1">
						<p class="description"><?php esc_html_e( 'Lowest number first, then by name. 0 puts them with the others in name order.', 'optima-team' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="sh-note"><?php esc_html_e( 'Every published person gets a profile page under /team/ and Person schema. Case results and page approvals attach from their profile once the People screen lands.', 'optima-team' ); ?></p>
			<?php self::nav_buttons( true, __( 'Next: check and publish', 'optima-team' ) ); ?>
		</section>
		</form>
		<?php if ( current_user_can( 'upload_files' ) ) : ?>
			<script>
			( function () {
				var pick = document.getElementById( 'st-add-photo-pick' );
				var clear = document.getElementById( 'st-add-photo-clear' );
				var field = document.getElementById( 'st-add-photo-id' );
				if ( ! pick || ! field || ! window.wp || ! wp.media ) { return; }
				var frame;
				pick.addEventListener( 'click', function () {
					if ( ! frame ) {
						frame = wp.media( { title: pick.textContent, button: { text: pick.textContent }, library: { type: 'image' }, multiple: false } );
						frame.on( 'select', function () {
							var a = frame.state().get( 'selection' ).first();
							if ( a ) { field.value = a.get( 'id' ); pick.form.requestSubmit ? pick.form.requestSubmit() : pick.form.submit(); }
						} );
					}
					frame.open();
				} );
				if ( clear ) {
					clear.addEventListener( 'click', function () { field.value = '0'; clear.form.requestSubmit ? clear.form.requestSubmit() : clear.form.submit(); } );
				}
			} )();
			</script>
		<?php endif; ?>
		<?php
	}

	private static function step_check( array $state ): void {
		$checks = self::checks( $state );
		$done   = count( array_filter( wp_list_pluck( $checks, 2 ) ) );
		$user   = $state['user'] ? get_userdata( $state['user'] ) : false;
		self::form_open( 'create' );
		?>
		<div class="sh-grid sh-grid-2">
			<section class="sh-box">
				<h2 class="sh-title"><?php echo esc_html( $state['name'] ); ?></h2>
				<table class="sh-table">
					<tr><td><?php esc_html_e( 'Title', 'optima-team' ); ?></td><td><?php echo esc_html( '' !== $state['role'] ? $state['role'] : '—' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Short bio', 'optima-team' ); ?></td><td><?php echo esc_html( '' !== $state['bio'] ? wp_trim_words( $state['bio'], 30, '…' ) : __( 'None yet', 'optima-team' ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Photo', 'optima-team' ); ?></td><td><?php echo esc_html( $state['photo_id'] ? __( 'From the media library', 'optima-team' ) : ( '' !== $state['photo'] ? __( 'From the setup file', 'optima-team' ) : __( 'None yet', 'optima-team' ) ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Byline', 'optima-team' ); ?></td><td><?php echo esc_html( $user ? $user->display_name : __( 'Nobody', 'optima-team' ) ); ?></td></tr>
					<?php if ( Optima_Team::is_law() ) : ?>
						<tr><td><?php esc_html_e( 'Attorney', 'optima-team' ); ?></td><td><?php echo esc_html( $state['attorney'] ? __( 'Yes', 'optima-team' ) : __( 'No', 'optima-team' ) ); ?></td></tr>
					<?php endif; ?>
					<tr><td><?php esc_html_e( 'Place', 'optima-team' ); ?></td><td><?php echo esc_html( $state['order'] ? (string) $state['order'] : __( 'With the others, by name', 'optima-team' ) ); ?></td></tr>
				</table>
			</section>
			<section class="sh-box">
				<p class="sh-label">
					<?php
					/* translators: 1: checks done, 2: total */
					echo esc_html( sprintf( __( 'Trust signals · %1$d of %2$d', 'optima-team' ), $done, count( $checks ) ) );
					?>
				</p>
				<ul class="sh-waiting">
					<?php foreach ( $checks as $check ) : ?>
						<li>
							<div><strong><?php echo esc_html( $check[0] ); ?></strong><p class="sh-note"><?php echo esc_html( $check[1] ); ?></p></div>
							<span class="sh-pill <?php echo $check[2] ? 'is-done' : 'is-received'; ?>"><?php echo esc_html( $check[2] ? __( 'Done', 'optima-team' ) : __( 'Later', 'optima-team' ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="sh-note"><?php esc_html_e( 'Anything marked Later is finished on the profile after this step. Nothing here blocks publishing.', 'optima-team' ); ?></p>
			</section>
		</div>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Create the profile', 'optima-team' ); ?></h2>
			<p><?php esc_html_e( 'Published: on the team page and at /team/ now, on this site. Draft: saved, visible only here, until you publish it from the profile.', 'optima-team' ); ?></p>
			<p class="sh-actions">
				<button type="submit" name="do" value="back" class="button sh-quiet" formnovalidate><?php esc_html_e( 'Back', 'optima-team' ); ?></button>
				<button type="submit" name="publish" value="1" class="button button-primary"><?php esc_html_e( 'Publish to this site', 'optima-team' ); ?></button>
				<button type="submit" class="button"><?php esc_html_e( 'Save as a draft', 'optima-team' ); ?></button>
				<button type="submit" name="do" value="cancel" class="button-link sh-quiet" formnovalidate><?php esc_html_e( 'Cancel and create nothing', 'optima-team' ); ?></button>
			</p>
		</section>
		</form>
		<?php
	}
}
