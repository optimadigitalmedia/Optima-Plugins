<?php
/**
 * The Partners hub: its own sidebar item with the Optima arrow. Overview (who is
 * running, by level, what ends soon, clicks this month and last), the partner
 * list, and settings for levels, placements and event pages. Each partner's
 * details sit in one box on its edit screen.
 *
 * @package Optima_Partners
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Partners_Admin {

	const SLUG = 'optima-partners';
	const CAP  = 'edit_pages';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_optima_partners_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'add_meta_boxes_' . Optima_Partners::TYPE, array( __CLASS__, 'box' ) );
		add_action( 'save_post_' . Optima_Partners::TYPE, array( __CLASS__, 'save_box' ) );
		add_filter( 'manage_' . Optima_Partners::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Optima_Partners::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_PARTNERS_FILE ), array( __CLASS__, 'action_link' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_hint' ), 10, 2 );
	}

	public static function url( string $view = '' ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . ( 'settings' === $view ? '-settings' : '' ) );
	}

	private static function icon(): string {
		if ( ! Optima_Admin_UI::branded() ) {
			return 'dashicons-awards';
		}
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#1272C4" d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon data URI.
	}

	public static function menu(): void {
		$list = 'edit.php?post_type=' . Optima_Partners::TYPE;
		add_menu_page( __( 'Optima Partners', 'optima-partners' ), __( 'Partners', 'optima-partners' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ), self::icon(), '3.3' );
		add_submenu_page( self::SLUG, __( 'Optima Partners', 'optima-partners' ), __( 'Overview', 'optima-partners' ), self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
		add_submenu_page( self::SLUG, __( 'All partners', 'optima-partners' ), __( 'All partners', 'optima-partners' ), self::CAP, $list );
		add_submenu_page( self::SLUG, __( 'Add partner', 'optima-partners' ), __( 'Add partner', 'optima-partners' ), self::CAP, 'post-new.php?post_type=' . Optima_Partners::TYPE );
		add_submenu_page( self::SLUG, __( 'Partner settings', 'optima-partners' ), __( 'Settings', 'optima-partners' ), 'manage_options', self::SLUG . '-settings', array( __CLASS__, 'render_settings' ) );
	}

	/** Partner list and edit screens light up the Partners menu. */
	public static function highlight( $file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && Optima_Partners::TYPE === $screen->post_type ) {
			global $submenu_file;
			$submenu_file = 'post' === $screen->base && 'add' === $screen->action ? 'post-new.php?post_type=' . Optima_Partners::TYPE : 'edit.php?post_type=' . Optima_Partners::TYPE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return self::SLUG;
		}
		return $file;
	}

	public static function assets( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( false === strpos( $hook, self::SLUG ) && ! ( $screen && Optima_Partners::TYPE === $screen->post_type ) ) {
			return;
		}
		Optima_Admin_UI::enqueue( OPTIMA_PARTNERS_URL . 'assets/optima-admin.css' );
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Partners', 'optima-partners' ) . '</a>' );
		return $links;
	}

	public static function title_hint( $text, $post ) {
		return $post instanceof WP_Post && Optima_Partners::TYPE === $post->post_type ? __( 'Partner name', 'optima-partners' ) : $text;
	}

	/* ---------- the details box ---------- */

	public static function box(): void {
		add_meta_box( 'optima-partner', __( 'Partner details', 'optima-partners' ), array( __CLASS__, 'render_box' ), Optima_Partners::TYPE, 'normal', 'high' );
	}

	public static function render_box( $post ): void {
		$p = Optima_Partners::partner( (int) $post->ID );
		wp_nonce_field( 'optima_partner_' . $post->ID, '_sp_nonce' );
		echo '<p><label for="sp-level"><strong>' . esc_html__( 'Level', 'optima-partners' ) . '</strong></label><br><select id="sp-level" name="sp[level]"><option value="">' . esc_html__( 'No level', 'optima-partners' ) . '</option>';
		foreach ( Optima_Partners::levels() as $slug => $name ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $p['level'], $slug, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="sp-url"><strong>' . esc_html__( 'Website', 'optima-partners' ) . '</strong></label><br><input type="url" id="sp-url" name="sp[url]" class="widefat" value="' . esc_attr( $p['url'] ) . '" placeholder="https://"></p>';
		echo '<p><label for="sp-line"><strong>' . esc_html__( 'One line about them (optional)', 'optima-partners' ) . '</strong></label><br><input type="text" id="sp-line" name="sp[line]" class="widefat" maxlength="140" value="' . esc_attr( $p['line'] ) . '"></p>';
		$places = Optima_Partners::placements();
		if ( $places ) {
			echo '<fieldset><legend><strong>' . esc_html__( 'Where they appear', 'optima-partners' ) . '</strong></legend>';
			foreach ( $places as $slug => $name ) {
				echo '<label style="display:inline-block;margin:4px 16px 4px 0"><input type="checkbox" name="sp[placements][]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $p['placements'], true ), true, false ) . '> ' . esc_html( $name ) . '</label>';
			}
			echo '<p class="description">' . esc_html__( 'Each place shows its partners wherever [optima_partners placement="…"] sits on the site. Every partner shows on the full partners list.', 'optima-partners' ) . '</p></fieldset>';
		}
		echo '<p><strong>' . esc_html__( 'Runs (optional)', 'optima-partners' ) . '</strong><br>'
			. '<label>' . esc_html__( 'From', 'optima-partners' ) . ' <input type="date" name="sp[starts]" value="' . esc_attr( $p['starts'] ) . '"></label> '
			. '<label>' . esc_html__( 'until', 'optima-partners' ) . ' <input type="date" name="sp[ends]" value="' . esc_attr( $p['ends'] ) . '"></label></p>'
			. '<p class="description">' . esc_html__( 'Outside these dates the partner comes off the site by itself. Leave both empty to run until you change it.', 'optima-partners' ) . '</p>';
		if ( taxonomy_exists( 'optima_region' ) ) {
			echo '<p class="description">' . esc_html__( 'Regions: tick them in the Regions box. A partner with no region shows everywhere; one with regions shows beside those regions\' events.', 'optima-partners' ) . '</p>';
		}
		$clicks = Optima_Partners::clicks( (int) $post->ID );
		if ( $clicks ) {
			echo '<p><strong>' . esc_html__( 'Clicks by month', 'optima-partners' ) . '</strong><br>';
			foreach ( array_slice( $clicks, 0, 6, true ) as $month => $n ) {
				echo esc_html( wp_date( 'F Y', (int) strtotime( $month . '-15' ) ) . ': ' . number_format_i18n( $n ) ) . '<br>';
			}
			echo '</p>';
		}
	}

	public static function save_box( $post_id ): void {
		if ( ! isset( $_POST['_sp_nonce'] ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sp_nonce'] ) ), 'optima_partner_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['sp'] ) ? (array) wp_unslash( $_POST['sp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in Optima_Partners::save().
		Optima_Partners::save( (int) $post_id, $in );
	}

	public static function columns( $cols ): array {
		$cols = (array) $cols;
		$date = $cols['date'] ?? '';
		unset( $cols['date'] );
		$cols['sp_level']  = __( 'Level', 'optima-partners' );
		$cols['sp_place']  = __( 'Appears', 'optima-partners' );
		$cols['sp_runs']   = __( 'Runs', 'optima-partners' );
		$cols['sp_clicks'] = __( 'Clicks this month', 'optima-partners' );
		if ( '' !== $date ) {
			$cols['date'] = $date;
		}
		return $cols;
	}

	public static function column( $col, $id ): void {
		$id = (int) $id;
		$p  = Optima_Partners::partner( $id );
		if ( 'sp_level' === $col ) {
			echo esc_html( Optima_Partners::levels()[ $p['level'] ] ?? '' );
		} elseif ( 'sp_place' === $col ) {
			$all = Optima_Partners::placements();
			echo esc_html( implode( ', ', array_filter( array_map( static fn( $s ) => $all[ $s ] ?? '', $p['placements'] ) ) ) );
		} elseif ( 'sp_runs' === $col ) {
			echo esc_html( self::runs( $p ) );
		} elseif ( 'sp_clicks' === $col ) {
			echo esc_html( number_format_i18n( Optima_Partners::clicks( $id )[ wp_date( 'Y-m' ) ] ?? 0 ) );
		}
	}

	private static function runs( array $p ): string {
		$f = static fn( $d ) => '' !== $d ? wp_date( (string) get_option( 'date_format' ), (int) strtotime( $d . ' 12:00' ) ) : '';
		if ( '' === $p['starts'] && '' === $p['ends'] ) {
			return __( 'Ongoing', 'optima-partners' );
		}
		if ( '' === $p['starts'] ) {
			/* translators: %s: end date. */
			return sprintf( __( 'Until %s', 'optima-partners' ), $f( $p['ends'] ) );
		}
		if ( '' === $p['ends'] ) {
			/* translators: %s: start date. */
			return sprintf( __( 'From %s', 'optima-partners' ), $f( $p['starts'] ) );
		}
		/* translators: 1: start date, 2: end date. */
		return sprintf( __( '%1$s to %2$s', 'optima-partners' ), $f( $p['starts'] ), $f( $p['ends'] ) );
	}

	/* ---------- overview ---------- */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		Optima_Admin_UI::open(
			__( 'Your partners', 'optima-partners' ),
			__( 'Who is running, at what level and where, what ends soon, and how many people clicked through to each one.', 'optima-partners' ),
			__( 'Partners', 'optima-partners' )
		);
		$all     = array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Optima_Partners::TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'fields'         => 'ids',
				)
			)
		);
		$live    = Optima_Partners::ids();
		$levels  = Optima_Partners::levels();
		$month   = wp_date( 'Y-m' );
		$last    = ( new DateTimeImmutable( 'first day of this month', wp_timezone() ) )->modify( '-1 month' )->format( 'Y-m' );
		$soon    = array_filter(
			$live,
			static function ( $id ) {
				$e = Optima_Partners::partner( $id )['ends'];
				return '' !== $e && $e <= wp_date( 'Y-m-d', (int) strtotime( '+30 days' ) );
			}
		);
		$clicked = 0;
		foreach ( $all as $id ) {
			$clicked += Optima_Partners::clicks( $id )[ $month ] ?? 0;
		}
		?>
		<section class="sh-box">
			<div class="sh-stats">
				<div class="sh-stat"><p class="sh-num"><?php echo (int) count( $live ); ?></p><p class="sh-note"><?php esc_html_e( 'partners on the site', 'optima-partners' ); ?></p></div>
				<?php foreach ( $levels as $slug => $name ) : ?>
					<div class="sh-stat"><p class="sh-num"><?php echo (int) count( array_filter( $live, static fn( $id ) => Optima_Partners::partner( $id )['level'] === $slug ) ); ?></p><p class="sh-note"><?php echo esc_html( $name ); ?></p></div>
				<?php endforeach; ?>
				<div class="sh-stat"><p class="sh-num"><?php echo esc_html( number_format_i18n( $clicked ) ); ?></p><p class="sh-note"><?php esc_html_e( 'clicks this month', 'optima-partners' ); ?></p></div>
			</div>
		</section>

		<?php if ( ! $all ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Getting started', 'optima-partners' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Check the levels and places under Settings. They are yours to name and order.', 'optima-partners' ); ?></li>
					<li><?php esc_html_e( 'Add each partner with their logo, website, level and where they appear.', 'optima-partners' ); ?></li>
					<li><?php esc_html_e( 'Put [optima_partners] on a Partners page, and [optima_partners placement="home-page" layout="rail"] wherever a strip of logos belongs.', 'optima-partners' ); ?></li>
				</ol>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Optima_Partners::TYPE ) ); ?>"><?php esc_html_e( 'Add a partner', 'optima-partners' ); ?></a></p>
			</section>
		<?php endif; ?>

		<?php if ( $soon ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Ending within 30 days', 'optima-partners' ); ?></h2>
				<ul>
					<?php foreach ( $soon as $id ) : ?>
						<li><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a> · <?php echo esc_html( self::runs( Optima_Partners::partner( $id ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="sh-note"><?php esc_html_e( 'A good moment to ask about renewing.', 'optima-partners' ); ?></p>
			</section>
		<?php endif; ?>

		<?php if ( $all ) : ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Clicks to each partner', 'optima-partners' ); ?></h2>
				<table class="sh-table">
					<thead><tr><th><?php esc_html_e( 'Partner', 'optima-partners' ); ?></th><th><?php esc_html_e( 'Level', 'optima-partners' ); ?></th><th><?php echo esc_html( wp_date( 'F', (int) strtotime( $month . '-15' ) ) ); ?></th><th><?php echo esc_html( wp_date( 'F', (int) strtotime( $last . '-15' ) ) ); ?></th><th><?php esc_html_e( 'On the site', 'optima-partners' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $all as $id ) : ?>
							<?php $c = Optima_Partners::clicks( $id ); ?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a></td>
								<td><?php echo esc_html( $levels[ Optima_Partners::partner( $id )['level'] ] ?? '' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $c[ $month ] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $c[ $last ] ?? 0 ) ); ?></td>
								<td><?php echo in_array( $id, $live, true ) ? esc_html__( 'Yes', 'optima-partners' ) : esc_html__( 'Not now (outside its dates)', 'optima-partners' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="sh-note"><?php esc_html_e( 'A click is counted when someone follows a partner\'s logo or name to their website. Search engines and link checkers are left out. No visitor details are kept.', 'optima-partners' ); ?></p>
			</section>
		<?php endif; ?>
		<?php
		Optima_Admin_UI::close();
	}

	/* ---------- settings ---------- */

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Optima_Partners::settings();
		Optima_Admin_UI::open( __( 'Partner settings', 'optima-partners' ), __( 'Your levels and the places partners can appear.', 'optima-partners' ), __( 'Partners', 'optima-partners' ) );
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'optima-partners' ) . '</p></div>';
		}
		?>
		<section class="sh-box">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="optima_partners_settings">
				<?php wp_nonce_field( 'optima_partners_settings' ); ?>
				<p><label for="sp-levels"><strong><?php esc_html_e( 'Levels, highest first, one per line', 'optima-partners' ); ?></strong></label><br>
				<textarea id="sp-levels" name="sp[levels]" rows="4" class="large-text code"><?php echo esc_textarea( $s['levels'] ); ?></textarea></p>
				<p><label for="sp-places"><strong><?php esc_html_e( 'Places partners can appear, one per line', 'optima-partners' ); ?></strong></label><br>
				<textarea id="sp-places" name="sp[placements]" rows="5" class="large-text code"><?php echo esc_textarea( $s['placements'] ); ?></textarea>
				<span class="sh-note"><?php esc_html_e( 'Each one becomes a tick box on every partner. Show a place with [optima_partners placement="home-page"], using the name in lower case with dashes.', 'optima-partners' ); ?></span></p>
				<?php if ( class_exists( 'Optima_Events' ) ) : ?>
					<p><label><input type="checkbox" name="sp[on_events]" value="1" <?php checked( $s['on_events'] ); ?>> <?php esc_html_e( 'Show partners on each event page (those placed on "Event pages", from the event\'s region and the national ones)', 'optima-partners' ); ?></label></p>
				<?php endif; ?>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'optima-partners' ); ?></button></p>
			</form>
		</section>
		<section class="sh-box">
			<h2 class="sh-title"><?php esc_html_e( 'Using it on the site', 'optima-partners' ); ?></h2>
			<table class="sh-table">
				<tr><td>[optima_partners]</td><td><?php esc_html_e( 'Every partner on the site, grouped by level.', 'optima-partners' ); ?></td></tr>
				<tr><td>[optima_partners level="founding"]</td><td><?php esc_html_e( 'One level.', 'optima-partners' ); ?></td></tr>
				<tr><td>[optima_partners placement="home-page" layout="rail"]</td><td><?php esc_html_e( 'One place, as a single row of logos.', 'optima-partners' ); ?></td></tr>
				<tr><td>[optima_partners region="midwest"]</td><td><?php esc_html_e( 'One region\'s partners (with Optima Events regions).', 'optima-partners' ); ?></td></tr>
				<tr><td>layout="logos"</td><td><?php esc_html_e( 'Logos only, no names.', 'optima-partners' ); ?></td></tr>
			</table>
		</section>
		<?php
		Optima_Admin_UI::close();
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change partner settings.', 'optima-partners' ), 403 );
		}
		check_admin_referer( 'optima_partners_settings' );
		$in = isset( $_POST['sp'] ) ? (array) wp_unslash( $_POST['sp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in save_settings().
		Optima_Partners::save_settings( $in );
		wp_safe_redirect( add_query_arg( 'saved', 1, self::url( 'settings' ) ) );
		exit;
	}
}
