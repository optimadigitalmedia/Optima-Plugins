<?php
/**
 * Release rings and the pause switch (Optima Site Standard rule 19, §10).
 *
 * A Optima plugin release reaches optimadigitalmedia.com (and any staging copy) first,
 * early client sites a day later and the rest a day after that. Until a site's turn the
 * release waits: WordPress still lists it, an operator can still press Update
 * now, but the automatic update holds back. Two more brakes stop it everywhere:
 *
 *   - Pause on one site: Build, Updates, "Pause automatic updates" (7 days).
 *   - Hold a release on every site: a "hold" entry in catalog.json, e.g.
 *     { "slug": "optima-build", "version": "0.10.0", "note": "Fix coming" }
 *     ("version": "*" holds every version of that plugin). Sites read the
 *     catalog within 12 hours, or at once with Check for new versions.
 *
 * The ring comes from OPTIMA_HUB_RING in wp-config.php (1, 2 or 3), else the
 * setting on the Updates screen, else the address: optimadigitalmedia.com and staging
 * are 1, ring 2 is set by hand, everything else is 3. A release whose date the
 * version list does not carry is never held by its ring, so an older feed
 * behaves exactly as before.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Rings {

	const OPTION_RING  = 'optima_hub_ring';
	const OPTION_PAUSE = 'optima_hub_pause_until';
	const ACTION       = 'optima_hub_rings';
	const PAUSE_DAYS   = 7;

	/** Ring number => label, and how many days after a release it reaches that ring. */
	const RINGS = array(
		1 => array( 'Ring 1: optimadigitalmedia.com and staging', 0 ),
		2 => array( 'Ring 2: early client sites (set by hand)', 1 ),
		3 => array( 'Ring 3: client sites', 2 ),
	);

	public static function boot(): void {
		// After Optima Build's own holds (priority 20), so a version sent back stays held either way.
		add_filter( 'auto_update_plugin', array( __CLASS__, 'filter' ), 30, 2 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
	}

	/** This site's ring, 1 to 3. */
	public static function ring(): int {
		if ( defined( 'OPTIMA_HUB_RING' ) && isset( self::RINGS[ (int) OPTIMA_HUB_RING ] ) ) {
			return (int) OPTIMA_HUB_RING;
		}
		$saved = (int) get_option( self::OPTION_RING, 0 );
		if ( isset( self::RINGS[ $saved ] ) ) {
			return $saved;
		}
		return self::guess();
	}

	/** The ring the address suggests. */
	public static function guess(): int {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( preg_match( '/(^|\.)optimadigitalmedia\.com$/', $host ) || Optima_Hub_Health::is_staging() ) {
			return 1;
		}
		return 3;
	}

	/** Days after its release that a version reaches this site. */
	public static function delay_days(): int {
		$ring = self::ring();
		/**
		 * Filters how many days after a release this site's ring installs it.
		 *
		 * @param int $days Days.
		 * @param int $ring The site's ring.
		 */
		return max( 0, (int) apply_filters( 'optima_hub_ring_delay', (int) self::RINGS[ $ring ][1], $ring ) );
	}

	/** Unix time the pause on this site ends, or 0. */
	public static function paused_until(): int {
		$until = (int) get_option( self::OPTION_PAUSE, 0 );
		return $until > time() ? $until : 0;
	}

	/**
	 * Why an automatic update of this version waits, or '' when it may install.
	 * Each answer is one plain sentence for the Updates screen.
	 */
	public static function wait_reason( string $slug, string $version ): string {
		if ( '' === $slug || '' === $version ) {
			return '';
		}
		foreach ( Optima_Hub_Catalog::holds() as $hold ) {
			if ( $hold['slug'] === $slug && ( '*' === $hold['version'] || $hold['version'] === $version ) ) {
				/* translators: %s: the hold note from catalog.json, or nothing */
				return trim( sprintf( __( 'Held on every site by Optima. %s', 'optima-build' ), $hold['note'] ) );
			}
		}
		$until = self::paused_until();
		if ( $until ) {
			/* translators: %s: date */
			return sprintf( __( 'Automatic updates are paused on this site until %s.', 'optima-build' ), wp_date( 'M j', $until ) );
		}
		$lands = self::lands_at( $slug, $version );
		if ( $lands > time() ) {
			/* translators: 1: date, 2: ring label */
			return sprintf( __( 'Installs by itself %1$s (%2$s).', 'optima-build' ), wp_date( 'M j', $lands ), self::RINGS[ self::ring() ][0] );
		}
		return '';
	}

	/** When a version reaches this site's ring; 0 when its release date is unknown. */
	public static function lands_at( string $slug, string $version ): int {
		$published = Optima_Hub_Catalog::published( $slug, $version );
		return $published ? $published + self::delay_days() * DAY_IN_SECONDS : 0;
	}

	/**
	 * Hold an automatic update of a Optima plugin until its ring's turn, while
	 * the site is paused, or while catalog.json holds it. Manual updates are
	 * never stopped, and every other plugin passes through untouched.
	 *
	 * @param bool|null $update Whether to auto-update, as decided so far.
	 * @param object    $item   The update offer.
	 * @return bool|null
	 */
	public static function filter( $update, $item ) {
		$id = isset( $item->id ) ? (string) $item->id : '';
		if ( 0 !== strpos( $id, 'github.com/' . Optima_Hub_Catalog::REPO . '/' ) ) {
			return $update;
		}
		$slug    = (string) ( $item->slug ?? substr( $id, strlen( 'github.com/' . Optima_Hub_Catalog::REPO . '/' ) ) );
		$version = (string) ( $item->new_version ?? '' );
		return '' === self::wait_reason( $slug, $version ) ? $update : false;
	}

	/** Save the ring, or start or end a pause (operators only). */
	public static function save(): void {
		check_admin_referer( self::ACTION );
		if ( ! Optima_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can change how it updates.', 'optima-build' ), 403 );
		}
		$do = sanitize_key( wp_unslash( (string) ( $_POST['do'] ?? '' ) ) );
		if ( 'pause' === $do ) {
			update_option( self::OPTION_PAUSE, time() + self::PAUSE_DAYS * DAY_IN_SECONDS, false );
			$msg = __( 'Automatic updates are paused here for 7 days. Update now still works.', 'optima-build' );
		} elseif ( 'resume' === $do ) {
			delete_option( self::OPTION_PAUSE );
			$msg = __( 'Automatic updates are back on.', 'optima-build' );
		} else {
			$ring = (int) ( $_POST['ring'] ?? 0 );
			if ( isset( self::RINGS[ $ring ] ) && $ring !== self::guess() ) {
				update_option( self::OPTION_RING, $ring, false );
			} else {
				delete_option( self::OPTION_RING ); // Back to what the address says.
			}
			/* translators: %s: ring label */
			$msg = sprintf( __( 'This site is in %s.', 'optima-build' ), self::RINGS[ self::ring() ][0] );
		}
		set_transient( 'optima_hub_notice_' . get_current_user_id(), array( 'message' => $msg, 'type' => 'success' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Optima_Hub_Admin::url( 'updates' ) . '#sh-rings' );
		exit;
	}

	/** The box on Build, Updates. */
	public static function panel( bool $operator ): void {
		$ring   = self::ring();
		$until  = self::paused_until();
		$holds  = Optima_Hub_Catalog::holds();
		$locked = defined( 'OPTIMA_HUB_RING' );
		echo '<section class="sh-box" id="sh-rings"><h2 class="sh-title">' . esc_html__( 'When releases install here', 'optima-build' ) . '</h2>';
		$days = self::delay_days();
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: ring label, 2: sentence about the delay */
				__( '%1$s. %2$s', 'optima-build' ),
				self::RINGS[ $ring ][0],
				0 === $days
					? __( 'Optima releases install here first, as soon as they are out.', 'optima-build' )
					/* translators: %d: days */
					: sprintf( _n( 'Optima releases install here %d day after they are out, once the sites before this one have them.', 'Optima releases install here %d days after they are out, once the sites before this one have them.', $days, 'optima-build' ), $days )
			)
		) . '</p>';
		if ( $until ) {
			/* translators: %s: date */
			echo '<p><span class="sh-tag is-held">' . esc_html( sprintf( __( 'Paused until %s', 'optima-build' ), wp_date( 'M j', $until ) ) ) . '</span></p>';
		}
		foreach ( $holds as $hold ) {
			/* translators: 1: plugin slug, 2: version or "every version" */
			echo '<p><span class="sh-tag is-held">' . esc_html( sprintf( __( 'Held by Optima: %1$s %2$s', 'optima-build' ), $hold['slug'], '*' === $hold['version'] ? __( '(every version)', 'optima-build' ) : $hold['version'] ) ) . '</span> ' . esc_html( $hold['note'] ) . '</p>';
		}
		if ( $operator ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			wp_nonce_field( self::ACTION );
			if ( ! $locked ) {
				echo '<label for="sh-ring" class="screen-reader-text">' . esc_html__( 'Ring', 'optima-build' ) . '</label><select id="sh-ring" name="ring">';
				foreach ( self::RINGS as $n => $meta ) {
					echo '<option value="' . (int) $n . '"' . selected( $ring, $n, false ) . '>' . esc_html( $meta[0] . ( self::guess() === $n ? ' ' . __( '(from the address)', 'optima-build' ) : '' ) ) . '</option>';
				}
				echo '</select> <button type="submit" name="do" value="ring" class="button">' . esc_html__( 'Save ring', 'optima-build' ) . '</button>';
			}
			if ( $until ) {
				echo ' <button type="submit" name="do" value="resume" class="button">' . esc_html__( 'Turn automatic updates back on', 'optima-build' ) . '</button>';
			} else {
				echo ' <button type="submit" name="do" value="pause" class="button sh-quiet">' . esc_html__( 'Pause automatic updates for 7 days', 'optima-build' ) . '</button>';
			}
			echo '</form>';
			echo '<p class="sh-note">' . esc_html__( 'A pause stops automatic updates on this site only. To hold a release on every site, add it to "hold" in catalog.json. Update now always works.', 'optima-build' ) . ( $locked ? ' ' . esc_html__( 'The ring is set in wp-config.php (OPTIMA_HUB_RING).', 'optima-build' ) : '' ) . '</p>';
		}
		echo '</section>';
	}
}
