<?php
/**
 * The edit screen for one case result: the fields, the live preview (which
 * reads the same headline field the site shows), the firm's approval, and the
 * rule that keeps an unapproved result from going live.
 *
 * @package Optima_Results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Results_Editor {

	const NONCE = 'optima_results_save';

	public static function boot(): void {
		add_action( 'add_meta_boxes_' . Optima_Results::POST_TYPE, array( __CLASS__, 'boxes' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'after_title' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'gate' ), 20, 2 );
		add_action( 'save_post_' . Optima_Results::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'guard_transition' ), 10, 3 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'redirect' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . Optima_Results::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Optima_Results::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_updated_messages', array( __CLASS__, 'messages' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_by_attorney' ) );
	}

	/** ?optima_attorney=<profile id> on the results list: that person's results (linked from People). */
	public static function filter_by_attorney( WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || Optima_Results::POST_TYPE !== $q->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, read only.
		$who = isset( $_GET['optima_attorney'] ) ? absint( wp_unslash( $_GET['optima_attorney'] ) ) : 0;
		if ( $who ) {
			$q->set( 'meta_key', Optima_Results::META['attorney'] );
			$q->set( 'meta_value', (string) $who );
		}
	}

	/** Set when this request held a result back, so the redirect can say why. */
	private static bool $held = false;

	/** Why it was held: 'approval' or 'consent'. */
	private static string $held_for = '';

	public static function title_placeholder( string $text, WP_Post $post ): string {
		return Optima_Results::POST_TYPE === $post->post_type ? __( 'Headline, for example "Settlement for a client hurt in a rear-end crash"', 'optima-results' ) : $text;
	}

	public static function after_title( WP_Post $post ): void {
		if ( Optima_Results::POST_TYPE !== $post->post_type ) {
			return;
		}
		echo '<p class="sr-title-help">' . esc_html__( 'The headline above is what visitors see on the site and in the preview. Keep it plain and factual.', 'optima-results' ) . '</p>';
	}

	public static function boxes(): void {
		add_meta_box( 'optima-result-details', __( 'The result', 'optima-results' ), array( __CLASS__, 'box_details' ), Optima_Results::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'optima-result-approval', __( 'Have the firm approve it', 'optima-results' ), array( __CLASS__, 'box_approval' ), Optima_Results::POST_TYPE, 'side', 'high' );
		add_meta_box( 'optima-result-preview', __( 'Preview on the site', 'optima-results' ), array( __CLASS__, 'box_preview' ), Optima_Results::POST_TYPE, 'normal', 'default' );
	}

	public static function box_details( WP_Post $post ): void {
		$r = Optima_Results::get( $post->ID );
		wp_nonce_field( self::NONCE, '_optima_results_nonce' );
		$areas = get_terms(
			array(
				'taxonomy'   => Optima_Results::TAXONOMY,
				'hide_empty' => false,
			)
		);
		$areas = is_wp_error( $areas ) ? array() : $areas;
		?>
		<div class="sr-fields">
			<div class="sr-row">
				<p class="sr-field">
					<label for="sr-area"><?php esc_html_e( 'Practice area', 'optima-results' ); ?></label>
					<select id="sr-area" name="optima_result[area]">
						<option value="0"><?php esc_html_e( 'Choose one', 'optima-results' ); ?></option>
						<?php foreach ( $areas as $term ) : ?>
							<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $r['area_id'], (int) $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="sr-help">
						<label for="sr-new-area"><?php esc_html_e( 'Or add a new one', 'optima-results' ); ?></label>
						<input id="sr-new-area" type="text" name="optima_result[new_area]" value="" autocomplete="off">
					</span>
				</p>
				<p class="sr-field">
					<label for="sr-type"><?php esc_html_e( 'Result type', 'optima-results' ); ?></label>
					<select id="sr-type" name="optima_result[type]">
						<?php foreach ( Optima_Results::types() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $r['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</div>
			<div class="sr-row">
				<p class="sr-field">
					<label for="sr-amount"><?php esc_html_e( 'Amount (optional)', 'optima-results' ); ?></label>
					<input id="sr-amount" type="text" name="optima_result[amount]" value="<?php echo esc_attr( $r['amount'] ); ?>" autocomplete="off">
					<span class="sr-help"><?php esc_html_e( 'Leave blank to show the result without a figure. Only enter an amount the firm can document.', 'optima-results' ); ?></span>
				</p>
				<p class="sr-field">
					<label for="sr-date"><?php esc_html_e( 'Date of the result', 'optima-results' ); ?></label>
					<input id="sr-date" type="date" name="optima_result[date]" value="<?php echo esc_attr( $r['date'] ); ?>">
					<span class="sr-help"><?php esc_html_e( 'Shown as month and year.', 'optima-results' ); ?></span>
				</p>
			</div>
			<?php if ( class_exists( 'Optima_Team' ) && post_type_exists( Optima_Team::POST_TYPE ) ) : ?>
				<?php
				$people = get_posts(
					array(
						'post_type'      => Optima_Team::POST_TYPE,
						'post_status'    => 'publish',
						'posts_per_page' => 200,
						'orderby'        => 'title',
						'order'          => 'ASC',
						'no_found_rows'  => true,
					)
				);
				?>
				<p class="sr-field">
					<label for="sr-attorney"><?php esc_html_e( 'Attorney', 'optima-results' ); ?></label>
					<select id="sr-attorney" name="optima_result[attorney]">
						<option value="0"><?php esc_html_e( 'Not named', 'optima-results' ); ?></option>
						<?php foreach ( $people as $person ) : ?>
							<option value="<?php echo esc_attr( (string) $person->ID ); ?>" <?php selected( $r['attorney_id'], (int) $person->ID ); ?>><?php echo esc_html( $person->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="sr-help"><?php esc_html_e( 'From People. The name shows on the result and the result counts on their profile. Nobody here? Add the person under Optima, People first.', 'optima-results' ); ?></span>
				</p>
			<?php endif; ?>
			<div class="sr-row">
				<p class="sr-field">
					<label for="sr-role"><?php esc_html_e( 'Attorney\'s role', 'optima-results' ); ?></label>
					<select id="sr-role" name="optima_result[role]">
						<option value=""><?php esc_html_e( 'Not given', 'optima-results' ); ?></option>
						<?php foreach ( Optima_Results::roles() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $r['role'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="sr-help"><?php esc_html_e( 'Printed on the result beside the amount.', 'optima-results' ); ?></span>
				</p>
				<p class="sr-field">
					<label for="sr-gross-net"><?php esc_html_e( 'Gross or net', 'optima-results' ); ?></label>
					<select id="sr-gross-net" name="optima_result[gross_net]">
						<option value=""><?php esc_html_e( 'Not given', 'optima-results' ); ?></option>
						<?php foreach ( Optima_Results::gross_net_options() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $r['gross_net'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="sr-help"><?php esc_html_e( 'Whether the amount is before or after fees and costs.', 'optima-results' ); ?></span>
				</p>
			</div>
			<div class="sr-row">
				<p class="sr-field">
					<label for="sr-venue-county"><?php esc_html_e( 'Venue: county or parish', 'optima-results' ); ?></label>
					<input id="sr-venue-county" type="text" name="optima_result[venue_county]" value="<?php echo esc_attr( $r['venue_county'] ); ?>" autocomplete="off">
				</p>
				<p class="sr-field">
					<label for="sr-venue-state"><?php esc_html_e( 'Venue: state', 'optima-results' ); ?></label>
					<input id="sr-venue-state" type="text" name="optima_result[venue_state]" value="<?php echo esc_attr( $r['venue_state'] ); ?>" autocomplete="off">
					<span class="sr-help"><?php esc_html_e( 'Shown with the year under the result.', 'optima-results' ); ?></span>
				</p>
			</div>
			<fieldset class="sr-field sr-post-verdict">
				<legend><?php esc_html_e( 'After the result', 'optima-results' ); ?></legend>
				<span class="sr-checks">
					<?php foreach ( Optima_Results::post_verdict_options() as $key => $label ) : ?>
						<label><input type="checkbox" name="optima_result[post_verdict][]" value="<?php echo esc_attr( $key ); ?>" data-sr-label="<?php echo esc_attr( $label ); ?>" <?php checked( in_array( $key, $r['post_verdict'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</span>
				<label for="sr-post-verdict-note" class="screen-reader-text"><?php esc_html_e( 'Note on what happened after the result', 'optima-results' ); ?></label>
				<input id="sr-post-verdict-note" type="text" name="optima_result[post_verdict_note]" value="<?php echo esc_attr( $r['post_verdict_note'] ); ?>" placeholder="<?php esc_attr_e( 'Note (optional)', 'optima-results' ); ?>" autocomplete="off">
				<span class="sr-help"><?php esc_html_e( 'Anything that changed the figure after it was reached. Printed on the result beside the amount.', 'optima-results' ); ?></span>
			</fieldset>
			<p class="sr-field">
				<label for="sr-description"><?php esc_html_e( 'Short description', 'optima-results' ); ?></label>
				<textarea id="sr-description" name="optima_result[description]" rows="4" maxlength="600"><?php echo esc_textarea( $r['description'] ); ?></textarea>
				<span class="sr-help"><?php esc_html_e( 'Two or three sentences on what happened. Leave out client names unless the client has agreed in writing.', 'optima-results' ); ?></span>
			</p>
			<div class="sr-flags" id="sr-flags" role="status" aria-live="polite" hidden>
				<p class="sr-flags-title"></p>
				<ul></ul>
			</div>
		</div>
		<?php
	}

	public static function box_approval( WP_Post $post ): void {
		$r = Optima_Results::get( $post->ID );
		?>
		<div class="sr-approval">
			<p class="sr-check">
				<input id="sr-approved" type="checkbox" name="optima_result[approved]" value="1" <?php checked( $r['approved'] ); ?>>
				<label for="sr-approved"><?php esc_html_e( 'Approved by the firm', 'optima-results' ); ?></label>
			</p>
			<p class="sr-field">
				<label for="sr-approver"><?php esc_html_e( 'Approver name', 'optima-results' ); ?></label>
				<input id="sr-approver" type="text" name="optima_result[approver]" value="<?php echo esc_attr( $r['approver'] ); ?>" autocomplete="name">
			</p>
			<p class="sr-field">
				<label for="sr-approved-on"><?php esc_html_e( 'Approval date', 'optima-results' ); ?></label>
				<input id="sr-approved-on" type="date" name="optima_result[approved_on]" value="<?php echo esc_attr( $r['approved_on'] ); ?>">
			</p>
			<p class="sr-field">
				<label for="sr-consent-on"><?php esc_html_e( 'Client consent date', 'optima-results' ); ?></label>
				<input id="sr-consent-on" type="date" name="optima_result[consent_on]" value="<?php echo esc_attr( $r['consent_on'] ); ?>">
				<span class="sr-help">
					<?php
					if ( Optima_Results::consent_required() ) {
						esc_html_e( 'Required on this site (Florida): the result cannot be approved or shown until the client\'s consent date is filled in.', 'optima-results' );
					} else {
						esc_html_e( 'When the client agreed to the result being published. Kept on file, never shown on the site.', 'optima-results' );
					}
					?>
				</span>
			</p>
			<?php if ( Optima_Results::consent_missing( $r ) ) : ?>
				<p class="sr-blocked" role="status"><?php esc_html_e( 'Not approved: Florida needs the client\'s consent date before this result can be approved or shown on the site.', 'optima-results' ); ?></p>
			<?php endif; ?>
			<p class="sr-help"><?php esc_html_e( 'A result goes live once the firm has approved the exact wording. Until then it saves for review.', 'optima-results' ); ?></p>
		</div>
		<?php
	}

	public static function box_preview( WP_Post $post ): void {
		$r = Optima_Results::get( $post->ID );
		?>
		<div class="sr-preview" id="sr-preview">
			<p class="sr-preview-note"><?php esc_html_e( 'This is how the result reads in the grid. The disclaimer is added to every result on its own.', 'optima-results' ); ?></p>
			<?php
			// The same card the site prints, filled from the saved values; the
			// script then keeps it in step with the fields as you type.
			echo '<div class="optima-results">' . Optima_Results_Render::card( $r, true ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside card().
			?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Saving                                                              */
	/* ------------------------------------------------------------------ */

	/** The posted fields, sanitized, or null when this request did not come from our form. */
	private static function posted( int $post_id ): ?array {
		if ( ! isset( $_POST['_optima_results_nonce'], $_POST['optima_result'] ) ) {
			return null;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_optima_results_nonce'] ) ), self::NONCE ) ) {
			return null;
		}
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}
		$in   = (array) wp_unslash( $_POST['optima_result'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field below.
		$type = sanitize_key( (string) ( $in['type'] ?? '' ) );
		$date = sanitize_text_field( (string) ( $in['date'] ?? '' ) );
		$on   = sanitize_text_field( (string) ( $in['approved_on'] ?? '' ) );
		$cons = sanitize_text_field( (string) ( $in['consent_on'] ?? '' ) );
		$role = sanitize_key( (string) ( $in['role'] ?? '' ) );
		$gn   = sanitize_key( (string) ( $in['gross_net'] ?? '' ) );
		$pv   = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['post_verdict'] ?? array() ) ), array_keys( Optima_Results::post_verdict_options() ) ) );
		return array(
			'area'        => absint( $in['area'] ?? 0 ),
			'new_area'    => sanitize_text_field( (string) ( $in['new_area'] ?? '' ) ),
			'type'        => isset( Optima_Results::types()[ $type ] ) ? $type : 'other',
			'amount'      => sanitize_text_field( (string) ( $in['amount'] ?? '' ) ),
			'date'        => Optima_Results::valid_date( $date ) ? $date : '',
			'description' => sanitize_textarea_field( (string) ( $in['description'] ?? '' ) ),
			'approved'    => ! empty( $in['approved'] ),
			'approver'    => sanitize_text_field( (string) ( $in['approver'] ?? '' ) ),
			'approved_on' => Optima_Results::valid_date( $on ) ? $on : '',
			'attorney'    => absint( $in['attorney'] ?? 0 ),
			'role'        => isset( Optima_Results::roles()[ $role ] ) ? $role : '',
			'gross_net'   => isset( Optima_Results::gross_net_options()[ $gn ] ) ? $gn : '',
			'venue_county' => sanitize_text_field( (string) ( $in['venue_county'] ?? '' ) ),
			'venue_state' => sanitize_text_field( (string) ( $in['venue_state'] ?? '' ) ),
			'post_verdict' => $pv,
			'post_verdict_note' => sanitize_text_field( (string) ( $in['post_verdict_note'] ?? '' ) ),
			'consent_on'  => Optima_Results::valid_date( $cons ) ? $cons : '',
		);
	}

	/**
	 * Why a result may not go live, from posted or saved values: 'approval'
	 * (no firm approval with a name), 'consent' (Florida preset, no client
	 * consent date) or '' when it may.
	 */
	private static function hold_reason( bool $approved, string $approver, string $consent_on ): string {
		if ( ! Optima_Results::is_approved( $approved, $approver ) ) {
			return 'approval';
		}
		if ( Optima_Results::consent_missing( array( 'consent_on' => $consent_on ) ) ) {
			return 'consent';
		}
		return '';
	}

	/** The hold reason from what is saved on a result. */
	private static function saved_hold_reason( int $id ): string {
		$m = Optima_Results::META;
		return self::hold_reason( '1' === (string) get_post_meta( $id, $m['approved'], true ), (string) get_post_meta( $id, $m['approver'], true ), (string) get_post_meta( $id, $m['consent_on'], true ) );
	}

	/**
	 * Keep an unapproved result from going live, however it is saved: the
	 * edit screen, quick edit, bulk edit or code. It is saved as Pending review.
	 */
	public static function gate( array $data, array $postarr ): array {
		if ( Optima_Results::POST_TYPE !== ( $data['post_type'] ?? '' ) || ! in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ) {
			return $data;
		}
		$id     = (int) ( $postarr['ID'] ?? 0 );
		$posted = self::posted( $id );
		if ( null !== $posted ) {
			$why = self::hold_reason( $posted['approved'], $posted['approver'], $posted['consent_on'] );
		} else {
			$why = $id ? self::saved_hold_reason( $id ) : 'approval';
		}
		if ( '' !== $why ) {
			$data['post_status'] = 'pending';
			self::$held          = true;
			self::$held_for      = $why;
		}
		return $data;
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$p = self::posted( $post_id );
		if ( null === $p ) {
			return;
		}
		$m = Optima_Results::META;
		update_post_meta( $post_id, $m['type'], $p['type'] );
		update_post_meta( $post_id, $m['amount'], $p['amount'] );
		update_post_meta( $post_id, $m['date'], $p['date'] );
		update_post_meta( $post_id, $m['description'], $p['description'] );
		update_post_meta( $post_id, $m['approver'], $p['approver'] );
		// Only a real People profile may be named.
		$attorney = $p['attorney'] && class_exists( 'Optima_Team' ) && Optima_Team::POST_TYPE === get_post_type( $p['attorney'] ) ? $p['attorney'] : 0;
		update_post_meta( $post_id, $m['attorney'], (string) $attorney );
		update_post_meta( $post_id, $m['role'], $p['role'] );
		update_post_meta( $post_id, $m['gross_net'], $p['gross_net'] );
		update_post_meta( $post_id, $m['venue_county'], $p['venue_county'] );
		update_post_meta( $post_id, $m['venue_state'], $p['venue_state'] );
		update_post_meta( $post_id, $m['post_verdict'], implode( ',', $p['post_verdict'] ) );
		update_post_meta( $post_id, $m['post_verdict_note'], $p['post_verdict_note'] );
		update_post_meta( $post_id, $m['consent_on'], $p['consent_on'] );
		// Florida preset: the approval does not take without the client's consent date.
		if ( $p['approved'] && Optima_Results::consent_missing( $p ) ) {
			$p['approved'] = false;
		}
		if ( $p['approved'] ) {
			update_post_meta( $post_id, $m['approved'], '1' );
			// Fill in today when the approval is ticked without a date.
			update_post_meta( $post_id, $m['approved_on'], '' !== $p['approved_on'] ? $p['approved_on'] : current_time( 'Y-m-d' ) );
		} else {
			update_post_meta( $post_id, $m['approved'], '0' );
			update_post_meta( $post_id, $m['approved_on'], $p['approved_on'] );
		}

		$term_id = $p['area'];
		if ( '' !== $p['new_area'] && current_user_can( 'manage_categories' ) ) {
			$existing = term_exists( $p['new_area'], Optima_Results::TAXONOMY );
			if ( $existing ) {
				$term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
			} else {
				$made    = wp_insert_term( $p['new_area'], Optima_Results::TAXONOMY );
				$term_id = is_wp_error( $made ) ? $term_id : (int) $made['term_id'];
			}
		}
		wp_set_object_terms( $post_id, $term_id ? array( $term_id ) : array(), Optima_Results::TAXONOMY );
	}

	/**
	 * Last line of defence for code paths that skip wp_insert_post_data, such
	 * as a scheduled result whose approval was removed before its date.
	 */
	public static function guard_transition( string $new, string $old, WP_Post $post ): void {
		if ( 'publish' !== $new || Optima_Results::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( null !== self::posted( (int) $post->ID ) ) {
			return; // The edit screen; gate() already decided with the posted values.
		}
		if ( '' !== self::saved_hold_reason( (int) $post->ID ) ) {
			remove_action( 'transition_post_status', array( __CLASS__, 'guard_transition' ), 10 );
			wp_update_post(
				array(
					'ID'          => $post->ID,
					'post_status' => 'pending',
				)
			);
			add_action( 'transition_post_status', array( __CLASS__, 'guard_transition' ), 10, 3 );
		}
	}

	public static function redirect( string $location, int $post_id ): string {
		if ( Optima_Results::POST_TYPE !== get_post_type( $post_id ) ) {
			return $location;
		}
		if ( self::$held ) {
			$location = add_query_arg( 'optima_result_held', 'consent' === self::$held_for ? 'consent' : '1', $location );
		}
		$r     = Optima_Results::get( $post_id );
		$flags = Optima_Results::find_flagged( $r['headline'] . ' ' . $r['description'] );
		if ( $flags ) {
			$location = add_query_arg( 'optima_result_words', count( $flags ), $location );
		}
		return $location;
	}

	public static function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || Optima_Results::POST_TYPE !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['optima_result_held'] ) && 'consent' === $_GET['optima_result_held'] ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Saved for review. This result is not on the site yet.', 'optima-results' ) . '</strong> ' . esc_html__( 'This site uses the Florida rule set: a result cannot be approved or shown until the client\'s consent date is filled in. Add the date, tick "Approved by the firm" again, then publish.', 'optima-results' ) . '</p></div>';
		} elseif ( ! empty( $_GET['optima_result_held'] ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Saved for review. This result is not on the site yet.', 'optima-results' ) . '</strong> ' . esc_html__( 'It can go live once the firm approves it: tick "Approved by the firm", add the approver\'s name, then publish.', 'optima-results' ) . '</p></div>';
		}
		if ( ! empty( $_GET['optima_result_words'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Some wording may read as a promise or a ranking. The suggestions under the description show a plainer way to say it, and the firm makes the final call.', 'optima-results' ) . '</p></div>';
		}
		// phpcs:enable
	}

	public static function messages( array $messages ): array {
		$messages[ Optima_Results::POST_TYPE ] = array(
			1  => __( 'Case result updated.', 'optima-results' ),
			4  => __( 'Case result updated.', 'optima-results' ),
			6  => __( 'Case result published. It shows in the results grid with the disclaimer.', 'optima-results' ),
			7  => __( 'Case result saved.', 'optima-results' ),
			8  => __( 'Case result saved for review.', 'optima-results' ),
			9  => __( 'Case result scheduled.', 'optima-results' ),
			10 => __( 'Draft saved.', 'optima-results' ),
		);
		return $messages;
	}

	/* ------------------------------------------------------------------ */
	/* The list of results                                                 */
	/* ------------------------------------------------------------------ */

	public static function columns( array $cols ): array {
		$out = array();
		foreach ( $cols as $key => $label ) {
			if ( 'title' === $key ) {
				$out[ $key ] = __( 'Headline', 'optima-results' );
				$out['sr_amount'] = __( 'Amount', 'optima-results' );
				$out['sr_type'] = __( 'Type', 'optima-results' );
				$out['sr_year'] = __( 'Year', 'optima-results' );
				if ( class_exists( 'Optima_Team' ) ) {
					$out['sr_attorney'] = __( 'Attorney', 'optima-results' );
				}
				continue;
			}
			if ( 'date' === $key ) {
				$out['sr_threshold'] = __( 'Above threshold', 'optima-results' );
				$out['sr_approval']  = __( 'Firm approval', 'optima-results' );
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	public static function column( string $col, int $post_id ): void {
		$r = Optima_Results::get( $post_id );
		if ( 'sr_amount' === $col ) {
			echo esc_html( '' !== $r['amount'] ? $r['amount'] : __( 'Not given', 'optima-results' ) );
		} elseif ( 'sr_year' === $col ) {
			echo esc_html( '' !== $r['date'] ? substr( $r['date'], 0, 4 ) : '' );
		} elseif ( 'sr_threshold' === $col ) {
			echo esc_html( Optima_Results::threshold_label( $r['amount'] ) );
		} elseif ( 'sr_type' === $col ) {
			echo esc_html( $r['type_label'] );
		} elseif ( 'sr_attorney' === $col ) {
			echo $r['attorney_id'] ? '<a href="' . esc_url( (string) get_edit_post_link( $r['attorney_id'] ) ) . '">' . esc_html( $r['attorney'] ) . '</a>' : '<span class="description">' . esc_html__( 'Not named', 'optima-results' ) . '</span>';
		} elseif ( 'sr_approval' === $col ) {
			if ( Optima_Results::is_approved( $r['approved'], $r['approver'] ) && Optima_Results::consent_missing( $r ) ) {
				echo '<strong>' . esc_html__( 'Needs the client\'s consent date (Florida)', 'optima-results' ) . '</strong>';
			} elseif ( Optima_Results::is_approved( $r['approved'], $r['approver'] ) ) {
				/* translators: 1: approver name, 2: date */
				echo esc_html( sprintf( __( 'Approved by %1$s, %2$s', 'optima-results' ), $r['approver'], $r['approved_on'] ? date_i18n( get_option( 'date_format' ), strtotime( $r['approved_on'] . ' 12:00:00' ) ) : '' ) );
			} else {
				echo '<strong>' . esc_html__( 'Waiting for the firm', 'optima-results' ) . '</strong>';
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                              */
	/* ------------------------------------------------------------------ */

	public static function assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || Optima_Results::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'optima-results', OPTIMA_RESULTS_URL . 'assets/optima-results.css', array(), OPTIMA_RESULTS_VERSION );
		wp_enqueue_style( 'optima-results-editor', OPTIMA_RESULTS_URL . 'assets/results-editor.css', array( 'optima-results' ), OPTIMA_RESULTS_VERSION );
		wp_enqueue_script( 'optima-results-editor', OPTIMA_RESULTS_URL . 'assets/results-editor.js', array(), OPTIMA_RESULTS_VERSION, true );
		wp_localize_script(
			'optima-results-editor',
			'optimaResultsEditor',
			array(
				'words'      => Optima_Results::flagged_words(),
				'types'      => array_combine( array_keys( Optima_Results::types() ), array_map( array( 'Optima_Results', 'type_label' ), array_keys( Optima_Results::types() ) ) ),
				'oneWord'    => __( 'One word may need a change before the firm approves this', 'optima-results' ),
				/* translators: %d: number of words */
				'manyWords'  => __( '%d words may need a change before the firm approves this', 'optima-results' ),
				'noHeadline' => __( 'Your headline shows here', 'optima-results' ),
				'roles'      => Optima_Results::roles(),
				'grossNet'   => Optima_Results::gross_net_options(),
				/* translators: %s: the attorney's role */
				'roleText'   => __( 'Role: %s', 'optima-results' ),
				/* translators: %s: county and state */
				'venueText'  => __( 'Venue: %s', 'optima-results' ),
				/* translators: %s: what happened after the result */
				'afterText'  => __( 'After the result: %s', 'optima-results' ),
			)
		);
	}
}
