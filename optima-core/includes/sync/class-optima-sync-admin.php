<?php
/**
 * Site Sync screen, notices and health check.
 *
 * The screen is Optima, Content, Approved copy (address tab=sync): counts at the
 * top, then every row that is not already current, with what WordPress holds
 * next to what the theme ships, then the pages and forms the theme can add.
 * Looking never changes anything; every change is a button, and nothing new
 * is added to the site until a person ticks it.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Sync_Admin {

	const ACTION = 'optima_sync';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'optima_core_dashboard_checks', array( __CLASS__, 'check' ), 20 );
	}

	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=optima&tab=sync' ) );
	}

	/* ---------- Notice and health check ---------- */

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) || ( isset( $_GET['tab'] ) && 'sync' === $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$summary = Optima_Sync::summary();
		$auto    = get_transient( 'optima_sync_last_auto' );
		if ( is_array( $auto ) && $auto['written'] > 0 ) {
			delete_transient( 'optima_sync_last_auto' );
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>Site Sync:</strong> %s <a href="%s">See what changed</a>.</p></div>',
				esc_html( sprintf( _n( 'the theme update brought %d change to the site.', 'the theme update brought %d changes to the site.', $auto['written'], 'optima-core' ), $auto['written'] ) ),
				esc_url( self::url() )
			);
		}
		if ( $summary['review'] > 0 ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Site Sync:</strong> %s <a href="%s">Review</a>.</p></div>',
				esc_html( sprintf( _n( '%d item needs a look before it can sync.', '%d items need a look before they can sync.', $summary['review'], 'optima-core' ), $summary['review'] ) ),
				esc_url( self::url() )
			);
		}
	}

	public static function check( array $checks ) {
		$summary = Optima_Sync::summary();
		if ( ! $summary['manifest'] ) {
			return $checks;
		}
		$checks[] = array(
			'label'        => 'Site Sync',
			'status'       => $summary['review'] ? 'warn' : 'good',
			'message'      => $summary['review']
				? sprintf( _n( '%d item needs a look before it can sync.', '%d items need a look before they can sync.', $summary['review'], 'optima-core' ), $summary['review'] )
				: 'The site matches the copy in the theme, apart from edits you chose to keep.',
			'action'       => self::url(),
			'action_label' => $summary['review'] ? 'Review Site Sync' : 'Open Site Sync',
		);
		return $checks;
	}

	/* ---------- Screen ---------- */

	public static function render() {
		$plan  = Optima_Sync::plan();
		$state = array();
		foreach ( $plan['rows'] as $row ) {
			$state[ $row['state'] ][] = $row;
		}
		Optima_Sync::summary( true );

		self::flash();

		if ( ! $plan['manifest'] ) {
			echo '<section class="sh-box"><h2>No approved copy in this theme yet</h2>';
			echo '<p>This theme carries no approved copy yet. Approved words reach the site with the next theme release: approve the page in the site repo\'s content folder, release the theme, then apply it here.</p>';
			if ( class_exists( 'Optima_Hub_Admin' ) ) {
				echo '<p><a class="button" href="' . esc_url( Optima_Hub_Admin::url( 'updates' ) . '#sh-theme' ) . '">The theme and its repo on Build, Updates</a></p>';
			}
			echo '</section>';
			return;
		}

		$counts = array(
			Optima_Sync::CURRENT  => array( 'Up to date', 'good' ),
			Optima_Sync::UPDATE   => array( 'Ready to update', 'good' ),
			Optima_Sync::EDITED   => array( 'Edited in WordPress', 'warn' ),
			Optima_Sync::CONFLICT => array( 'Needs a decision', 'warn' ),
			Optima_Sync::MISSING  => array( 'Not on the site yet', 'warn' ),
			Optima_Sync::OFF      => array( 'Waiting on Gravity Forms', 'warn' ),
		);
		foreach ( $state as $key => $rows ) {
			$state[ $key ] = array_values(
				array_filter(
					$rows,
					static function ( $r ) {
						return empty( $r['covered'] );
					}
				)
			);
		}
		echo '<div class="sh-grid sh-grid-3">';
		foreach ( $counts as $key => $meta ) {
			$n = count( $state[ $key ] ?? array() );
			if ( ! $n && in_array( $key, array( Optima_Sync::MISSING, Optima_Sync::CONFLICT, Optima_Sync::OFF ), true ) ) {
				continue;
			}
			printf(
				'<section class="sh-box sh-check is-%s"><p class="sh-label">%s</p><p class="sh-value">%d</p></section>',
				esc_attr( $n && 'good' !== $meta[1] ? $meta[1] : 'good' ),
				esc_html( $meta[0] ),
				(int) $n
			);
		}
		echo '</div>';

		foreach ( $plan['errors'] as $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}

		$updates = $state[ Optima_Sync::UPDATE ] ?? array();
		if ( $updates ) {
			echo '<section class="sh-box"><h2>' . esc_html( sprintf( _n( '%d update from the theme', '%d updates from the theme', count( $updates ), 'optima-core' ), count( $updates ) ) ) . '</h2>';
			echo '<p>Nobody has edited these in WordPress since the last sync, so the theme copy can go straight in. Each page keeps a revision, and you can undo the whole sync below.</p>';
			self::rows( $updates, false );
			self::form( 'safe', $plan['hash'], 'Sync these now' );
			echo '</section>';
		}

		$review  = array();
		$blocked = array();
		foreach ( array_merge( $state[ Optima_Sync::EDITED ] ?? array(), $state[ Optima_Sync::CONFLICT ] ?? array() ) as $row ) {
			if ( empty( $row['blocked'] ) ) {
				$review[] = $row;
			} else {
				$blocked[] = $row;
			}
		}
		foreach ( $blocked as $row ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( Optima_Sync::label( $row ) ) . ':</strong> ' . esc_html( $row['blocked'] ) . '</p></div>';
		}
		if ( $review ) {
			echo '<section class="sh-box"><h2>Changes made in WordPress</h2>';
			echo '<p>These differ from the theme copy. Site Sync never overwrites them on its own. Tick the ones to decide on, then choose. "Keep the WordPress copy" also means the edit should go back into the client repo so the next theme carries it.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			self::rows( $review, true );
			self::hidden( 'pick', $plan['hash'] );
			echo '<p class="sh-actions"><button class="button button-primary" name="choice" value="keep">Keep the WordPress copy</button> <button class="button" name="choice" value="take">Use the theme copy</button></p>';
			echo '</form></section>';
		}

		$addable = array();
		$missing = array();
		foreach ( $state[ Optima_Sync::MISSING ] ?? array() as $row ) {
			if ( in_array( $row['kind'], array( 'create', 'form' ), true ) ) {
				$addable[] = $row;
			} else {
				$missing[] = $row;
			}
		}
		if ( $addable ) {
			self::addable( $addable, $plan['hash'] );
		}
		if ( $missing ) {
			echo '<section class="sh-box"><h2>Pages the theme names but cannot create</h2><p>The theme has copy for these but no "create" block saying how to make the page. Add one in the site repo\'s optima-sync.json, or create the page by hand, then come back.</p><ul class="sh-list">';
			foreach ( $missing as $row ) {
				echo '<li>' . esc_html( Optima_Sync::label( $row ) ) . '</li>';
			}
			echo '</ul></section>';
		}

		$off = $state[ Optima_Sync::OFF ] ?? array();
		if ( $off ) {
			echo '<section class="sh-box"><h2>Gravity Forms is not active</h2><p>The theme ships these forms. Nothing happens to them until Gravity Forms is on; then come back here to add or update them.</p><ul class="sh-list">';
			foreach ( $off as $row ) {
				echo '<li>' . esc_html( Optima_Sync::label( $row ) ) . '</li>';
			}
			echo '</ul></section>';
		}

		if ( ! $updates && ! $review && ! $blocked && ! $addable && ! $missing && ! $off && ! $plan['errors'] ) {
			echo '<section class="sh-box"><h2>Everything is in sync</h2><p>Every page matches the copy in this theme. When a theme update changes the copy, the untouched pages update on their own.</p></section>';
		}

		$journal = get_option( Optima_Sync::JOURNAL, array() );
		if ( in_array( $journal['state'] ?? '', array( 'applied', 'failed', 'applying' ), true ) ) {
			echo '<section class="sh-box"><h2>Last sync</h2>';
			$who = array(
				'auto' => 'Automatic, after a theme update',
				'cli'  => 'From the command line',
			);
			printf(
				'<p>%s, %s. %s</p>',
				esc_html( $who[ $journal['who'] ?? '' ] ?? 'Run by hand' ),
				esc_html( wp_date( 'M j, g:i a', strtotime( $journal['time'] ) ) ),
				esc_html( sprintf( _n( '%d item changed.', '%d items changed.', count( $journal['rows'] ), 'optima-core' ), count( $journal['rows'] ) ) )
			);
			self::form( 'undo', '', 'Undo the last sync', 'button' );
			echo '</section>';
		}
	}

	/** Pages and forms the theme can add. Nothing is ticked to start with. */
	private static function addable( array $rows, $hash ) {
		$trashed = false;
		echo '<section class="sh-box"><h2>' . esc_html( sprintf( _n( '%d page or form the theme can add', '%d pages and forms the theme can add', count( $rows ), 'optima-core' ), count( $rows ) ) ) . '</h2>';
		echo '<p>The theme ships these, but the site does not have them yet. Nothing is added until you tick it. A new page gets the status the theme gives it (a draft stays a draft), its copy from the theme, and its SEO and page fields in the same step. Undo the last sync moves a new page to the trash and switches a new form off.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<table class="widefat striped"><thead><tr><td class="check-column"></td><th>Item</th><th>What it adds</th><th>Note</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$note = '';
			if ( ! empty( $row['blocked'] ) ) {
				$note = $row['blocked'];
			} elseif ( ! empty( $row['trashed'] ) ) {
				$note    = 'A page with this address is in the trash. Restore it instead of creating a second one, or empty it from the trash first.';
				$trashed = true;
			} elseif ( ! empty( $row['author_note'] ) ) {
				$note = $row['author_note'];
			}
			printf(
				'<tr><th class="check-column"><input type="checkbox" name="ids[]" value="%s"%s aria-label="%s"></th><td><strong>%s</strong>%s</td><td>%s</td><td>%s</td></tr>',
				esc_attr( $row['id'] ),
				empty( $row['blocked'] ) ? '' : ' disabled',
				esc_attr( Optima_Sync::label( $row ) ),
				esc_html( 'form' === $row['kind'] ? $row['title'] : '/' . trim( ( '' !== $row['parent'] && false === strpos( $row['slug'], '/' ) ? $row['parent'] . '/' : '' ) . $row['slug'], '/' ) . '/' ),
				! empty( $row['trashed'] ) ? '<br><span class="sh-tag">In the trash</span>' : '',
				esc_html( self::adds( $row ) ),
				esc_html( $note )
			);
		}
		echo '</tbody></table>';
		self::hidden( 'add', $hash );
		echo '<p class="sh-actions"><button class="button button-primary" name="choice" value="create">Create the ticked items</button>';
		if ( $trashed ) {
			echo ' <button class="button" name="choice" value="restore">Restore the ticked pages from the trash</button>';
		}
		echo '</p></form></section>';
	}

	/** One plain line on what adding a row does. */
	private static function adds( array $row ) {
		if ( 'form' === $row['kind'] ) {
			$n = count( (array) ( $row['form']['fields'] ?? array() ) );
			return sprintf( _n( 'A Gravity Forms form with %d field, titled exactly as the theme names it.', 'A Gravity Forms form with %d fields, titled exactly as the theme names it.', $n, 'optima-core' ), $n );
		}
		$bits   = array( ( 'publish' === $row['status'] ? 'A published ' : 'A draft ' ) . ( 'page' === $row['post_type'] ? 'page' : $row['post_type'] ) . ' titled "' . $row['title'] . '"' );
		$bits[] = '' !== $row['parent'] ? 'under /' . $row['parent'] . '/' : '';
		$bits[] = '' !== $row['template'] && 'default' !== $row['template'] ? 'on the template ' . $row['template'] : '';
		$bits[] = '' !== trim( (string) $row['theme'] ) ? 'with its copy from the theme' : 'with no copy yet';
		return implode( ', ', array_filter( $bits ) ) . '.';
	}

	private static function rows( array $rows, $pick ) {
		echo '<table class="widefat striped"><thead><tr>';
		if ( $pick ) {
			echo '<td class="check-column"></td>';
		}
		echo '<th>Item</th><th>Now in WordPress</th><th>In the theme</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			if ( $pick ) {
				printf( '<th class="check-column"><input type="checkbox" name="ids[]" value="%s"></th>', esc_attr( $row['id'] ) );
			}
			printf(
				'<td><strong>%s</strong>%s</td><td>%s</td><td>%s</td>',
				esc_html( Optima_Sync::label( $row ) ),
				Optima_Sync::CONFLICT === $row['state'] ? '<br><span class="sh-tag">' . esc_html( empty( $row['synced'] ) ? 'Never synced, and different' : 'Changed in both' ) . '</span>' : '',
				esc_html( self::preview( $row, $row['raw'] ?? '' ) ),
				esc_html( self::preview( $row, $row['theme'] ) )
			);
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function preview( array $row, $text ) {
		if ( 'form' === $row['kind'] ) {
			$form = is_array( $text ) ? $text : json_decode( (string) $text, true );
			if ( ! is_array( $form ) ) {
				return '(none)';
			}
			$labels = array();
			foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
				$labels[] = (string) ( $field['label'] ?? '' );
			}
			$n     = count( $labels );
			$plain = sprintf( _n( 'Form "%1$s", %2$d field: %3$s', 'Form "%1$s", %2$d fields: %3$s', $n, 'optima-core' ), (string) ( $form['title'] ?? '' ), $n, implode( ', ', array_filter( $labels ) ) );
			return mb_strlen( $plain ) > 220 ? mb_substr( $plain, 0, 220 ) . '...' : $plain;
		}
		if ( ! empty( $row['image'] ) ) {
			return $text === $row['theme'] ? 'Image: ' . wp_basename( (string) $row['image'] ) : ( $text ? 'Image #' . (int) $text : 'No image' );
		}
		$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
		if ( '' === $plain ) {
			return '(empty)';
		}
		return mb_strlen( $plain ) > 220 ? mb_substr( $plain, 0, 220 ) . '...' : $plain;
	}

	private static function hidden( $step, $hash ) {
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="step" value="' . esc_attr( $step ) . '">';
		echo '<input type="hidden" name="review" value="' . esc_attr( $hash ) . '">';
		wp_nonce_field( self::ACTION );
	}

	private static function form( $step, $hash, $label, $class = 'button button-primary' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( $step, $hash );
		echo '<p class="sh-actions"><button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></p></form>';
	}

	private static function flash() {
		$msg = get_transient( 'optima_sync_flash_' . get_current_user_id() );
		if ( ! $msg ) {
			return;
		}
		delete_transient( 'optima_sync_flash_' . get_current_user_id() );
		printf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( $msg['type'] ), esc_html( $msg['text'] ) );
	}

	/* ---------- Actions ---------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$step   = sanitize_key( wp_unslash( $_POST['step'] ?? '' ) );
		$review = sanitize_text_field( wp_unslash( $_POST['review'] ?? '' ) );
		$ids    = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['ids'] ?? array() ) );
		$choice = sanitize_key( wp_unslash( $_POST['choice'] ?? '' ) );

		if ( 'undo' === $step ) {
			$result = Optima_Sync::undo();
			$text   = is_wp_error( $result ) ? $result->get_error_message() : sprintf( 'Undone: %d put back.', $result['restored'] ) . ( $result['skipped'] ? ' Left alone because they changed since: ' . implode( ', ', $result['skipped'] ) . '.' : '' );
		} elseif ( 'add' === $step ) {
			if ( ! $ids ) {
				$result = new WP_Error( 'none', 'Tick at least one item first.' );
			} else {
				$result = Optima_Sync::apply( $review, 'restore' === $choice ? 'restore' : 'create', $ids );
			}
			if ( is_wp_error( $result ) ) {
				$text = $result->get_error_message();
			} elseif ( 'restore' === $choice ) {
				$text = sprintf( 'Restored %d page(s) from the trash, each with the status it had.', $result['written'] );
			} else {
				$text = sprintf( 'Added %d item(s). New drafts stay drafts until you publish them. Purge the hosting cache to see published pages live.', $result['written'] );
			}
			if ( ! is_wp_error( $result ) && ! empty( $result['notes'] ) ) {
				$text .= ' ' . implode( ' ', $result['notes'] );
			}
		} elseif ( 'pick' === $step ) {
			if ( ! $ids ) {
				$result = new WP_Error( 'none', 'Tick at least one item first.' );
			} else {
				$result = Optima_Sync::apply( $review, 'take' === $choice ? 'take' : 'keep', $ids );
			}
			$text = is_wp_error( $result ) ? $result->get_error_message() : ( 'take' === $choice ? sprintf( 'Theme copy used for %d item(s).', $result['written'] ) : 'Kept the WordPress copy. Bring the edit back into the client repo so the next theme carries it.' );
		} else {
			$result = Optima_Sync::apply( $review, 'safe' );
			$text   = is_wp_error( $result ) ? $result->get_error_message() : sprintf( 'Synced %d item(s). Purge the hosting cache to see them live.', $result['written'] );
		}
		// Applied from Build, Updates: back there, with the result as its notice.
		if ( 'updates' === sanitize_key( wp_unslash( $_POST['back'] ?? '' ) ) && class_exists( 'Optima_Hub_Admin' ) ) {
			set_transient( 'optima_hub_notice_' . get_current_user_id(), array( 'type' => is_wp_error( $result ) ? 'error' : 'success', 'message' => $text ), MINUTE_IN_SECONDS );
			wp_safe_redirect( Optima_Hub_Admin::url( 'updates' ) . '#sh-up-copy' );
			exit;
		}
		set_transient( 'optima_sync_flash_' . get_current_user_id(), array( 'type' => is_wp_error( $result ) ? 'error' : 'success', 'text' => $text ), 300 );
		wp_safe_redirect( self::url() );
		exit;
	}
}
