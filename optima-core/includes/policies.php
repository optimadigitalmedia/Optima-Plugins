<?php
/**
 * Privacy and terms (upstream 1.8.0): a privacy policy and terms of service
 * drafted from what this site actually runs, for the firm (or its lawyer) to
 * review and approve before anything is published.
 *
 * The web team drafts; the firm's named approver reviews and approves; a person
 * publishes. Optima, Content, Privacy and terms (tab "policies"):
 *   1. builds each draft from Your business, Details and the visitor data list
 *      (visitor-data.json) of every Optima plugin that is on, plus Gravity Forms.
 *      An Optima plugin that is on but has no list gets a [Confirm] line, so
 *      the draft never claims less than the site collects;
 *   2. copies a ready-to-send email for the firm's reviewer;
 *   3. records the approval (who and when);
 *   4. makes a DRAFT page from the approved text, marked for the firm's
 *      approval, where the reviewer's changes go in and a person publishes it.
 *      Nothing here publishes on its own, and an existing page is never
 *      overwritten;
 *   5. says "changed since it was approved" when a feature is added or removed,
 *      with what changed, so the page never drifts from the site.
 *
 * Lines in [square brackets] are for the firm or its lawyer to confirm. These
 * are templates, never legal advice: the firm owns its legal pages.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Policies {

	const TAB    = 'policies';
	const OPTION = 'optima_core_policies';
	const ACTION = 'optima_core_policies';
	const DOCS   = array( 'privacy', 'terms' );

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
	}

	/** The screen is a tab of Optima Core's screen (rule 10), filed under Content. */
	public static function url(): string {
		return admin_url( 'admin.php?page=optima&tab=' . self::TAB );
	}

	/** The words for each document. */
	public static function title( string $doc ): string {
		return 'terms' === $doc ? 'Terms of service' : 'Privacy policy';
	}

	/* ------------------------------------------------------------------ */
	/* What the site collects                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Every active Optima plugin's visitor data list, plus Gravity Forms.
	 *
	 * @return array<string, array{name:string, items:array, cookies:array}> keyed by plugin folder.
	 */
	public static function sources(): array {
		$out    = array();
		$active = (array) get_option( 'active_plugins', array() );
		sort( $active );
		foreach ( $active as $file ) {
			$slug = dirname( (string) $file );
			if ( 0 !== strpos( $slug, 'optima-' ) ) {
				continue;
			}
			$path = WP_PLUGIN_DIR . '/' . $slug . '/visitor-data.json';
			$data = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
			if ( ! is_array( $data ) ) {
				// On, but it has not said what it keeps: the draft asks instead of guessing.
				$out[ $slug ] = array(
					'name'     => self::plugin_name( (string) $file, $slug ),
					'items'    => array(),
					'cookies'  => array(),
					'unlisted' => true,
				);
				continue;
			}
			if ( empty( $data['items'] ) && empty( $data['cookies'] ) ) {
				continue;
			}
			$out[ $slug ] = array(
				'name'    => self::plugin_name( (string) $file, $slug ),
				'items'   => array_values( (array) ( $data['items'] ?? array() ) ),
				'cookies' => array_values( (array) ( $data['cookies'] ?? array() ) ),
			);
		}
		if ( class_exists( 'GFAPI' ) || in_array( 'gravityforms/gravityforms.php', $active, true ) ) {
			$days = function_exists( 'optima_law_entry_days' ) ? (int) optima_law_entry_days() : 0;
			$out['gravityforms'] = array(
				'name'    => 'Website forms',
				'items'   => array(
					array(
						'what'   => 'What people type into the website\'s forms, such as name, email, phone and message, plus any files they upload',
						'where'  => 'This website\'s database, as form entries',
						'kept'   => $days ? sprintf( '%d days, then deleted', $days ) : 'Until someone deletes it',
						'shared' => 'Emailed to the business',
					),
				),
				'cookies' => array(),
			);
		}
		return $out;
	}

	private static function plugin_name( string $file, string $slug ): string {
		if ( function_exists( 'get_plugin_data' ) && is_readable( WP_PLUGIN_DIR . '/' . $file ) ) {
			$name = (string) ( get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false )['Name'] ?? '' );
			if ( '' !== $name ) {
				return $name;
			}
		}
		return ucwords( str_replace( '-', ' ', $slug ) );
	}

	/** One line per thing collected, for spotting what changed since an approval. */
	public static function fingerprint( array $sources ): array {
		$lines = array();
		foreach ( $sources as $src ) {
			if ( ! empty( $src['unlisted'] ) ) {
				$lines[] = $src['name'] . ': on, with no visitor data list';
			}
			foreach ( $src['items'] as $i ) {
				$lines[] = $src['name'] . ': ' . (string) ( $i['what'] ?? '' );
			}
			foreach ( $src['cookies'] as $c ) {
				$lines[] = $src['name'] . ': cookie ' . (string) ( $c['name'] ?? '' );
			}
		}
		sort( $lines );
		return array_values( array_unique( $lines ) );
	}

	/* ------------------------------------------------------------------ */
	/* The drafts                                                          */
	/* ------------------------------------------------------------------ */

	/** Business info, with the blanks a draft needs. */
	private static function business(): array {
		$b = function_exists( 'optima_core_business' ) ? (array) optima_core_business() : array();
		$b = array_merge( array( 'name' => '', 'legal_name' => '', 'email' => '', 'phone' => '', 'city' => '', 'region' => '' ), $b );
		$b['name'] = '' !== trim( (string) $b['name'] ) ? (string) $b['name'] : (string) get_bloginfo( 'name' );
		$b['who']  = '' !== trim( (string) $b['legal_name'] ) ? (string) $b['legal_name'] : $b['name'];
		$b['site'] = (string) home_url( '/' );
		return $b;
	}

	private static function e( $s ): string {
		return esc_html( (string) $s );
	}

	/** The privacy policy draft, as HTML. */
	public static function privacy( array $sources ): string {
		$b       = self::business();
		$contact = trim( $b['email'] . ( '' !== $b['phone'] ? ' or ' . $b['phone'] : '' ) );
		$contact = '' !== $contact ? $contact : '[email or phone]';
		$h       = '<p><strong>Last updated: [date]</strong></p>';
		$h      .= '<h2>Who we are</h2><p>' . self::e( $b['who'] ) . ' runs this website, ' . self::e( $b['site'] ) . '. This policy explains what the website collects about the people who visit it, what happens to that information, and the choices you have. Questions about it can go to ' . self::e( $contact ) . '.</p>';

		$items    = array();
		$cookies  = array();
		$shared   = array();
		$unlisted = array();
		foreach ( $sources as $src ) {
			if ( ! empty( $src['unlisted'] ) ) {
				$unlisted[] = (string) $src['name'];
			}
			foreach ( $src['items'] as $i ) {
				$items[] = $i;
				$with    = trim( (string) ( $i['shared'] ?? '' ) );
				if ( '' !== $with ) {
					$shared[ $with ] = true;
				}
			}
			foreach ( $src['cookies'] as $c ) {
				$cookies[] = $c;
			}
		}

		$h .= '<h2>What the website collects</h2>';
		if ( ! $items ) {
			$h .= '<p>The website does not keep information about the people who visit it.</p>';
		} else {
			$h .= '<ul>';
			foreach ( $items as $i ) {
				$line = self::e( $i['what'] ) . ( ! empty( $i['when'] ) ? ' (' . self::e( $i['when'] ) . ')' : '' ) . '. ';
				$line .= 'Where it is kept: ' . self::e( rtrim( (string) $i['where'], '.' ) ) . '. ';
				$line .= 'How long: ' . self::e( rtrim( (string) $i['kept'], '.' ) ) . '.';
				$h    .= '<li>' . $line . '</li>';
			}
			$h .= '</ul>';
		}
		foreach ( $unlisted as $name ) {
			$h .= '<p>[Confirm: ' . self::e( $name ) . ' is on this website. Add what it collects about visitors, where it is kept and for how long, and any cookies it sets.]</p>';
		}
		$h .= '<p>We use this information to answer you, to provide what you asked for, and to keep the website working and safe. [Confirm: any other use, such as marketing emails only to people who asked for them.]</p>';
		$h .= '<p>[Confirm: anything the firm collects outside these features, such as an outside intake, chat or booking tool, or call tracking.]</p>';

		$h .= '<h2>Who else receives it</h2>';
		if ( $shared ) {
			$h .= '<ul>';
			foreach ( array_keys( $shared ) as $with ) {
				$h .= '<li>' . self::e( rtrim( $with, '.' ) ) . '</li>';
			}
			$h .= '</ul><p>Each of these services handles the information under its own privacy policy.</p>';
		} else {
			$h .= '<p>The website does not send visitor information to outside services.</p>';
		}
		$h .= '<p>[Confirm: we do not sell personal information.]</p>';

		$h .= '<h2>Cookies and browser storage</h2>';
		if ( $cookies ) {
			$h .= '<ul>';
			foreach ( $cookies as $c ) {
				$h .= '<li><strong>' . self::e( $c['name'] ) . '</strong>: ' . self::e( rtrim( (string) $c['why'], '.' ) ) . '. Lasts: ' . self::e( rtrim( (string) $c['lasts'], '.' ) ) . '.</li>';
			}
			$h .= '</ul>';
		} else {
			$h .= '<p>The website does not set cookies of its own.</p>';
		}
		if ( isset( $sources['optima-analytics'] ) ) {
			$h .= '<p>You can change your choice about analytics cookies at any time with the privacy choices link at the bottom of every page.</p>';
		}

		$h .= '<h2>Your choices</h2><p>You can ask us for a copy of the information we hold about you, or ask us to correct or delete it, by contacting ' . self::e( $contact ) . '. [Confirm: any rights that apply in the states where you do business.]</p>';
		$h .= '<h2>Children</h2><p>[Confirm: this website is not meant for children under 13, and we do not knowingly collect information from them.]</p>';
		$h .= '<h2>Changes to this policy</h2><p>When the website changes what it collects, we update this policy and the date at the top.</p>';
		$h .= '<h2>Contact</h2><p>' . self::e( $b['who'] ) . ( '' !== $b['city'] ? ', ' . self::e( $b['city'] ) . ( '' !== $b['region'] ? ', ' . self::e( $b['region'] ) : '' ) : '' ) . '. ' . self::e( $contact ) . '.</p>';
		return $h;
	}

	/** The terms of service draft, as HTML. */
	public static function terms(): string {
		$b       = self::business();
		$contact = trim( $b['email'] . ( '' !== $b['phone'] ? ' or ' . $b['phone'] : '' ) );
		$contact = '' !== $contact ? $contact : '[email or phone]';
		$state   = '' !== trim( (string) $b['region'] ) ? (string) $b['region'] : '[state]';
		$h       = '<p><strong>Last updated: [date]</strong></p>';
		$h      .= '<h2>Using this website</h2><p>These terms cover your use of ' . self::e( $b['site'] ) . ', run by ' . self::e( $b['who'] ) . '. By using the website you agree to them.</p>';
		$h      .= '<h2>Information on this website</h2><p>The website gives general information about ' . self::e( $b['name'] ) . ' and its services. It is not professional advice for your situation. [Confirm: wording your field requires, such as a medical, legal or financial disclaimer.] Prices, hours and services can change, so please check with us before you rely on them.</p>';
		$h      .= '<h2>Content</h2><p>The words, photos, logos and design on this website belong to ' . self::e( $b['who'] ) . ' or are used with permission. Please do not copy them for your own use without asking.</p>';
		$h      .= '<h2>Links to other websites</h2><p>The website links to other websites, such as maps, booking tools and social media. We do not control them, and their own terms and privacy policies apply.</p>';
		$h      .= '<h2>Using the website fairly</h2><p>Please do not try to break into the website, overload it, or use its forms to send spam.</p>';
		$h      .= '<h2>No guarantees</h2><p>We work to keep the website accurate and available, but we cannot promise it will always be error-free or online. [Confirm: limitation of liability wording.]</p>';
		$h      .= '<h2>Which laws apply</h2><p>These terms are governed by the laws of ' . self::e( $state ) . '. [Confirm.]</p>';
		$h      .= '<h2>Changes to these terms</h2><p>We may update these terms. The date at the top shows the latest version.</p>';
		$h      .= '<h2>Contact</h2><p>' . self::e( $b['who'] ) . '. ' . self::e( $contact ) . '.</p>';
		return $h;
	}

	public static function draft( string $doc, ?array $sources = null ): string {
		return 'terms' === $doc ? self::terms() : self::privacy( null === $sources ? self::sources() : $sources );
	}

	/** HTML to plain text for the email, keeping headings and bullets readable. */
	public static function plain( string $html ): string {
		$t = preg_replace( '#<h2>(.*?)</h2>#s', "\n$1\n", $html );
		$t = preg_replace( '#<li>(.*?)</li>#s', "- $1\n", (string) $t );
		$t = preg_replace( '#</p>#', "\n\n", (string) $t );
		$t = html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $t ) );
	}

	/** The email for the firm's reviewer. */
	public static function email(): string {
		$b    = self::business();
		$text = 'Subject: Please review our website privacy policy and terms of service' . "\n\n";
		$text .= "Hi [reviewer's name],\n\n";
		$text .= 'Our web team drafted the privacy policy and terms of service below for ' . $b['site'] . ". The privacy policy lists what the website actually collects, where it is kept, for how long, and which outside services receive it. Lines in [square brackets] need your input.\n\n";
		$text .= "Could you review both and send back any changes? Nothing goes on the website until you approve it.\n\n";
		$text .= "Thank you,\n[your name]\n\n";
		foreach ( self::DOCS as $doc ) {
			$text .= str_repeat( '=', 40 ) . "\n" . strtoupper( self::title( $doc ) ) . " (DRAFT)\n" . str_repeat( '=', 40 ) . "\n\n" . self::plain( self::draft( $doc ) ) . "\n\n";
		}
		return rtrim( $text ) . "\n";
	}

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */

	public static function state(): array {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		foreach ( self::DOCS as $doc ) {
			$all[ $doc ] = array_merge(
				array( 'status' => 'draft', 'sent' => 0, 'approved' => 0, 'note' => '', 'by' => '', 'html' => '', 'lines' => array(), 'page' => 0 ),
				is_array( $all[ $doc ] ?? null ) ? $all[ $doc ] : array()
			);
		}
		return $all;
	}

	/**
	 * What changed since a document was approved: lines added and removed.
	 *
	 * @return array{added:array, removed:array}
	 */
	public static function changes( array $doc, ?array $sources = null ): array {
		if ( 'approved' !== $doc['status'] ) {
			return array( 'added' => array(), 'removed' => array() );
		}
		$now = self::fingerprint( null === $sources ? self::sources() : $sources );
		$was = (array) $doc['lines'];
		return array( 'added' => array_values( array_diff( $now, $was ) ), 'removed' => array_values( array_diff( $was, $now ) ) );
	}

	public static function out_of_date( string $key, array $doc, ?array $sources = null ): bool {
		if ( 'approved' !== $doc['status'] ) {
			return false;
		}
		if ( 'privacy' === $key ) {
			$c = self::changes( $doc, $sources );
			return (bool) ( $c['added'] || $c['removed'] );
		}
		return self::terms() !== $doc['html'];
	}

	/** Apply one action to the stored state. Pure enough to test without a screen. */
	public static function apply( array $state, string $doc, string $do, string $note, string $by, int $now, ?array $sources = null ): array {
		if ( ! in_array( $doc, self::DOCS, true ) ) {
			return $state;
		}
		$d = $state[ $doc ];
		if ( 'sent' === $do ) {
			$d['status'] = 'approved' === $d['status'] ? $d['status'] : 'sent';
			$d['sent']   = $now;
		} elseif ( 'approve' === $do && '' !== trim( $note ) ) {
			$sources       = null === $sources ? self::sources() : $sources;
			$d['status']   = 'approved';
			$d['approved'] = $now;
			$d['note']     = $note;
			$d['by']       = $by;
			$d['html']     = self::draft( $doc, $sources );
			$d['lines']    = 'privacy' === $doc ? self::fingerprint( $sources ) : array();
			$d['page']     = 0; // A new approval gets its own draft page; an older page stays as it is.
		} elseif ( 'reopen' === $do ) {
			$d['status'] = 'draft';
			$d['page']   = 0; // The next approval makes a new draft page; the old page stays as it is.
		}
		$state[ $doc ] = $d;
		return $state;
	}

	/**
	 * Make a draft page from the approved text, with the approval date filled in.
	 * The firm's changes go in there, in the WordPress editor, and a person
	 * publishes it. An existing page is never overwritten: a new draft is made.
	 */
	public static function make_page( string $doc, array $d ): int {
		$date = wp_date( (string) get_option( 'date_format' ), (int) $d['approved'] );
		$id   = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft', // Never published from here: a person publishes it after the firm approves.
				'post_title'   => self::title( $doc ),
				'post_name'    => 'terms' === $doc ? 'terms-of-service' : 'privacy-policy',
				'post_content' => str_replace( '[date]', $date, (string) $d['html'] ),
				'meta_input'   => array( '_optima_needs_firm_approval' => '1' ),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		// WordPress's own privacy page setting follows, unless a published page already holds it.
		$current = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		if ( 'privacy' === $doc && ( ! $current || 'publish' !== get_post_status( $current ) ) ) {
			update_option( 'wp_page_for_privacy_policy', (int) $id );
		}
		return (int) $id;
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the privacy policy or terms.', 'optima-core' ), 403 );
		}
		check_admin_referer( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$doc  = sanitize_key( wp_unslash( (string) ( $_POST['doc'] ?? '' ) ) );
		$do   = sanitize_key( wp_unslash( (string) ( $_POST['do'] ?? '' ) ) );
		$note = sanitize_text_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) );
		// phpcs:enable
		$state = self::state();
		$msg   = '';
		if ( 'page' === $do && in_array( $doc, self::DOCS, true ) ) {
			$d = $state[ $doc ];
			if ( 'approved' === $d['status'] && ! self::out_of_date( $doc, $d ) && ! $d['page'] ) {
				$id = self::make_page( $doc, $d );
				if ( $id ) {
					$state[ $doc ]['page'] = $id;
					$msg                   = self::title( $doc ) . ': a draft page is ready. Add the firm\'s changes; a person publishes it once the firm approves.';
				}
			}
			$msg = '' !== $msg ? $msg : 'Only an approved, up-to-date draft can become a page.';
		} else {
			$user  = wp_get_current_user();
			$state = self::apply( $state, $doc, $do, $note, $user && $user->exists() ? (string) $user->display_name : '', time() );
			$msg   = 'approve' === $do && '' === trim( $note ) ? 'Write who approved it and when, then press Mark approved.' : 'Saved.';
		}
		update_option( self::OPTION, $state, false );
		set_transient( 'optima_core_policies_msg_' . get_current_user_id(), $msg, MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* The screen                                                          */
	/* ------------------------------------------------------------------ */

	private static function button( string $doc, string $do, string $label, string $class = 'button', string $extra = '' ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:8px 0">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="doc" value="' . esc_attr( $doc ) . '">'
			. wp_nonce_field( self::ACTION, '_wpnonce', true, false ) . $extra
			. '<button type="submit" name="do" value="' . esc_attr( $do ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** How many documents need a step: not approved yet, or changed since approval. */
	public static function waiting( ?array $state = null, ?array $sources = null ): int {
		$state   = null === $state ? self::state() : $state;
		$sources = null === $sources ? self::sources() : $sources;
		$n       = 0;
		foreach ( self::DOCS as $doc ) {
			if ( 'approved' !== $state[ $doc ]['status'] || self::out_of_date( $doc, $state[ $doc ], $sources ) ) {
				++$n;
			}
		}
		return $n;
	}

	/** The tab body, inside Optima Core's screen (Optima_Core_Admin opens and closes it). */
	public static function render_tab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sources = self::sources();
		$state   = self::state();
		$date    = (string) get_option( 'date_format' );
		$msg     = get_transient( 'optima_core_policies_msg_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'optima_core_policies_msg_' . get_current_user_id() );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( (string) $msg ) . '</p></div>';
		}

		// What needs doing first (rule 52): documents not approved, or changed since.
		$waiting  = self::waiting( $state, $sources );
		$unlisted = array_values( wp_list_pluck( array_filter( $sources, static function ( $src ) { return ! empty( $src['unlisted'] ); } ), 'name' ) );
		echo '<section class="sh-box"><div class="sh-status' . ( $waiting ? ' is-warn' : '' ) . '"><span class="sh-dot" aria-hidden="true"></span><p class="sh-value">'
			. esc_html( $waiting ? sprintf( _n( '%d document waits for the firm\'s approval.', '%d documents wait for the firm\'s approval.', $waiting, 'optima-core' ), $waiting ) : __( 'Both documents are approved and up to date.', 'optima-core' ) )
			. '</p></div>';
		if ( $unlisted ) {
			echo '<p class="sh-note">' . esc_html( sprintf( 'On, with no visitor data list (the draft asks the firm to fill these in): %s.', implode( ', ', $unlisted ) ) ) . '</p>';
		}
		echo '</section>';

		$order = self::DOCS;
		usort(
			$order,
			static function ( $a, $b ) use ( $state, $sources ) {
				$wa = 'approved' !== $state[ $a ]['status'] || self::out_of_date( $a, $state[ $a ], $sources );
				$wb = 'approved' !== $state[ $b ]['status'] || self::out_of_date( $b, $state[ $b ], $sources );
				return (int) $wb - (int) $wa;
			}
		);

		$primary = false; // One primary button per screen: only the first Make the draft page is filled.
		foreach ( $order as $doc ) {
			$d     = $state[ $doc ];
			$stale = self::out_of_date( $doc, $d, $sources );
			$look  = $stale ? 'is-warn' : ( 'approved' === $d['status'] ? '' : 'is-off' );
			echo '<section class="sh-box"><h2 class="sh-title">' . esc_html( self::title( $doc ) ) . '</h2><p class="sh-status ' . esc_attr( $look ) . '"><span class="sh-dot" aria-hidden="true"></span>';
			if ( $stale ) {
				echo '<strong>Changed since approved.</strong> The website changed after the firm approved this, so send the new draft and approve it again.';
			} elseif ( 'approved' === $d['status'] ) {
				echo '<strong>Approved</strong> ' . esc_html( wp_date( $date, (int) $d['approved'] ) ) . '. ' . esc_html( (string) $d['note'] );
				if ( $d['page'] && get_post( (int) $d['page'] ) ) {
					$live = 'publish' === get_post_status( (int) $d['page'] );
					echo ' ' . ( $live ? 'The page is live.' : 'The page is a draft: add the firm\'s changes, then a person publishes it.' ) . ' <a href="' . esc_url( (string) get_edit_post_link( (int) $d['page'], 'url' ) ) . '">Open the page</a>';
				}
			} elseif ( 'sent' === $d['status'] ) {
				echo '<strong>With the firm for review</strong> since ' . esc_html( wp_date( $date, (int) $d['sent'] ) ) . '.';
			} else {
				echo '<strong>Draft.</strong> Not sent to the firm yet, and no page is made until the firm approves it.';
			}
			echo '</p>';
			if ( $stale && 'privacy' === $doc ) {
				$c = self::changes( $d, $sources );
				echo '<ul class="sh-list">';
				foreach ( $c['added'] as $line ) {
					echo '<li>New: ' . esc_html( $line ) . '</li>';
				}
				foreach ( $c['removed'] as $line ) {
					echo '<li>No longer collected: ' . esc_html( $line ) . '</li>';
				}
				echo '</ul>';
			}
			if ( 'approved' !== $d['status'] || $stale ) {
				echo self::button( $doc, 'sent', 'Mark as sent to the firm', 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
				echo self::button( $doc, 'approve', 'Mark approved', 'button', '<label for="sp-note-' . esc_attr( $doc ) . '" class="screen-reader-text">Who approved it</label><input type="text" id="sp-note-' . esc_attr( $doc ) . '" name="note" class="regular-text" placeholder="Who approved it, how and when">' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			} else {
				if ( ! $d['page'] || ! get_post( (int) $d['page'] ) ) {
					echo self::button( $doc, 'page', 'Make the draft page', $primary ? 'button' : 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
					$primary = true;
				}
				echo self::button( $doc, 'reopen', 'Start a new draft', 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			}
			echo '<details><summary>Read the draft</summary><div style="max-width:72ch">' . wp_kses_post( self::draft( $doc, $sources ) ) . '</div></details>';
			echo '</section>';
		}

		$email = self::email();
		echo '<section class="sh-box"><h2 class="sh-title">The email for the firm</h2>';
		echo '<p>For the firm\'s named approver, or the lawyer they choose. The firm decides the final wording.</p>';
		echo '<textarea id="sp-email" class="large-text" readonly rows="12">' . esc_textarea( $email ) . '</textarea>';
		echo '<p><button type="button" class="button" id="sp-copy">Copy the email</button> <span id="sp-copied" class="sh-note" role="status"></span></p>';
		echo '<script>document.getElementById("sp-copy").addEventListener("click",function(){var t=document.getElementById("sp-email"),s=document.getElementById("sp-copied");function done(){s.textContent="Copied.";}function pick(){t.select();try{document.execCommand("copy");done();}catch(e){s.textContent="Select the text and copy it.";}}if(navigator.clipboard){navigator.clipboard.writeText(t.value).then(done,pick);}else{pick();}});</script>';
		echo '</section>';

		echo '<section class="sh-box"><h2 class="sh-title">Where the list comes from</h2><p>Each feature that is on lists what it keeps about visitors. The firm adds anything else, like an outside intake or booking tool.</p>';
		if ( $sources ) {
			echo '<ul class="sh-list" aria-label="Features that keep something about visitors">';
			foreach ( $sources as $src ) {
				echo '<li>' . esc_html( (string) $src['name'] . ( ! empty( $src['unlisted'] ) ? ' (no list yet)' : '' ) ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="sh-note">No feature on this site keeps anything about visitors.</p>';
		}
		echo '</section>';
	}
}
