<?php
/**
 * Dashboard screen: Optima -> Cards (inside Optima Core's menu), or a
 * top-level "Site Build" section on a site without Optima Core. Everything on the
 * card and links pages is edited here and saved to the `optima_cards_settings` option.
 *
 * @package Optima_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Cards_Admin {

	const SLUG = 'optima-cards';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 ); // after Optima Core's menu (10)
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_optima_cards_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_optima_cards_reset', array( __CLASS__, 'reset' ) );
		add_action( 'admin_init', array( 'Optima_Cards_Settings', 'migrate' ), 2 ); // Release 3: facts to Details, slots to People.
		add_filter( 'plugin_action_links_' . plugin_basename( OPTIMA_CARDS_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/**
	 * Parent menu: the site's existing Build section when there is one (the Optima
	 * Media theme's Optima Build, Recon's Recon Build, or Optima Core's Optima menu),
	 * else Site Build (created by the Optima Build hub, or by Cards itself).
	 */
	public static function parent() {
		global $admin_page_hooks;
		foreach ( array( 'optima-build', 'recon-build', 'optima' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'site-build';
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		global $admin_page_hooks;
		$parent = self::parent();
		// Create the Site Build section only when nothing else has: the Optima Build
		// hub plugin (optima-build) registers it first and Cards nests inside it.
		$own = 'site-build' === $parent && ! isset( $admin_page_hooks['site-build'] );
		if ( $own ) {
			add_menu_page( 'Site Build', 'Site Build', 'edit_pages', 'site-build', array( __CLASS__, 'render' ), 'dashicons-id-alt', 3 );
			add_submenu_page( 'site-build', 'Cards', 'Cards', 'edit_pages', 'site-build', array( __CLASS__, 'render' ) );
		}
		add_submenu_page( $parent, 'Cards', 'Cards', 'edit_pages', self::SLUG, array( __CLASS__, 'render' ) );
		if ( $own ) {
			remove_submenu_page( 'site-build', self::SLUG ); // the section's first item already opens this screen
		}
	}

	public static function action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Settings</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::SLUG ) || false !== strpos( (string) $hook, 'site-build' ) ) {
			wp_enqueue_media();
			Optima_Admin_UI::enqueue( OPTIMA_CARDS_URL . 'assets/optima-admin.css' );
		}
	}

	private static function field( $name, $value, $label, $type = 'text', $help = '' ) {
		$id = 'odc_' . md5( $name );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function photo( $name, $photo_id, $label, $help ) {
		$src = $photo_id ? wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '';
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td class="odc-photo-field">'
			. '<img src="' . esc_url( (string) $src ) . '" style="width:64px;height:64px;border-radius:50%;object-fit:cover;vertical-align:middle;' . ( $src ? '' : 'display:none;' ) . '" alt=""> '
			. '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $photo_id ? $photo_id : '' ) . '">'
			. '<button type="button" class="button odc-pick">Choose photo</button> <button type="button" class="button-link odc-clear">Remove</button>'
			. '<p class="description">' . esc_html( $help ) . '</p></td></tr>';
	}

	/** Spotlight fields: an optional block under the card. Leave all blank to hide it. */
	private static function spot_fields( $base, $v ) {
		echo '<tr><th scope="row" colspan="2" style="padding-bottom:0">Spotlight <span class="description" style="font-weight:400">(optional block under the card, such as a product or booking page)</span></th></tr>';
		self::field( "{$base}[spot_heading]", $v['spot_heading'] ?? '', 'Spotlight heading' );
		self::field( "{$base}[spot_text]", $v['spot_text'] ?? '', 'Spotlight note', 'textarea' );
		self::field( "{$base}[spot_label]", $v['spot_label'] ?? '', 'Button text' );
		self::field( "{$base}[spot_sub]", $v['spot_sub'] ?? '', 'Small line under it' );
		self::field( "{$base}[spot_url]", $v['spot_url'] ?? '', 'Button link', 'text', 'Start with / for a page on this site.' );
	}

	/** Sanitized spotlight values from a submitted card. */
	private static function clean_spot( $v ) {
		return array(
			'spot_heading' => sanitize_text_field( (string) ( $v['spot_heading'] ?? '' ) ),
			'spot_text'    => sanitize_textarea_field( (string) ( $v['spot_text'] ?? '' ) ),
			'spot_label'   => sanitize_text_field( (string) ( $v['spot_label'] ?? '' ) ),
			'spot_sub'     => sanitize_text_field( (string) ( $v['spot_sub'] ?? '' ) ),
			'spot_url'     => esc_url_raw( trim( (string) ( $v['spot_url'] ?? '' ) ) ),
		);
	}

	public static function render() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$s      = Optima_Cards_Settings::get();
		$n      = Optima_Cards_Settings::OPTION;
		$people = Optima_Cards_Settings::people();
		Optima_Admin_UI::open( 'Cards', 'A digital business card for each person, a QR code to share it, and a link page for your social bios.' );
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Saved. The card and links pages are updated. Clear the site cache if you do not see the change.</p></div>';
		}
		if ( isset( $_GET['reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info is-dismissible"><p>Reset to the defaults.</p></div>';
		}
		foreach ( array( $s['card_base'], $s['links_base'] ) as $slug ) {
			if ( get_page_by_path( $slug ) ) {
				echo '<div class="notice notice-warning"><p>A WordPress Page already uses <code>/' . esc_html( $slug ) . '/</code>. Optima Cards will show at that address instead of the Page. Change the address below, or rename that Page.</p></div>';
			}
		}

		echo '<p>Your digital business cards and your social "link in bio" page. ';
		echo '<a class="button" href="' . esc_url( Optima_Cards_Settings::card_url() ) . '" target="_blank">View card</a> ';
		echo '<a class="button" href="' . esc_url( home_url( '/' . $s['links_base'] . '/' ) ) . '" target="_blank">View links</a></p>';

		echo '<h2>Share mode links</h2><p>Open your link on your phone, then add it to your home screen (iPhone: Share, then Add to Home Screen). It opens like an app with a big QR code and share buttons.</p><ul>';
		foreach ( $people as $p ) {
			echo '<li><strong>' . esc_html( $p['name'] ) . ':</strong> <a href="' . esc_url( $p['share'] ) . '" target="_blank"><code>' . esc_html( $p['share'] ) . '</code></a></li>';
		}
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'optima_cards_save' );
		echo '<input type="hidden" name="action" value="optima_cards_save">';

		echo '<h2>Look</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Mode</th><td><label><input type="radio" name="' . esc_attr( $n ) . '[mode]" value="dark"' . checked( $s['mode'], 'dark', false ) . '> Dark</label> &nbsp; <label><input type="radio" name="' . esc_attr( $n ) . '[mode]" value="light"' . checked( $s['mode'], 'light', false ) . '> Light</label></td></tr>';
		echo '<tr><th scope="row">Color and font</th><td><p class="description" style="margin:0">From the theme\'s design, the same as every feature on the site' . ( class_exists( 'Optima_Hub_Design' ) ? ': <a href="' . esc_url( admin_url( 'admin.php?page=optima-design' ) ) . '">Build, Design</a>' : '' ) . '.</p></td></tr>';
		self::photo( "{$n}[share_card_id]", (int) $s['share_card_id'], 'Share picture: card', 'Shows when someone texts or posts the card link. 1200 by 630 pixels.' );
		self::photo( "{$n}[share_links_id]", (int) $s['share_links_id'], 'Share picture: links page', 'Shows when the links page is shared. Blank uses the card\'s.' );
		echo '</table>';

		// The business facts are typed once, on Your business, Details (rule 45).
		$id = Optima_Cards_Settings::identity();
		echo '<h2>Business details</h2><div class="sh-box">';
		Optima_Admin_UI::fact( 'Business name', (string) $id['name'] );
		Optima_Admin_UI::fact( 'Phone', (string) $id['phone'] );
		Optima_Admin_UI::fact( 'Main email', (string) $id['email'] );
		Optima_Admin_UI::fact( 'City, state, ZIP', trim( $id['city'] . ( $id['region'] ? ', ' . $id['region'] : '' ) . ( $id['postal'] ? ' ' . $id['postal'] : '' ) ) );
		Optima_Admin_UI::fact( 'Social profiles', str_replace( array( "\r\n", "\n" ), ', ', (string) $id['same_as'] ) );
		echo '</div><table class="form-table" role="presentation">';
		self::field( "{$n}[footer]", $s['footer'], 'Footer line', 'text', 'Shown at the bottom of every card and the links page.' );
		self::field( "{$n}[note]", $s['note'], 'Contact note', 'textarea', 'Saved in the Notes field of the phone contact.' );
		echo '</table>';

		echo '<h2>Main card</h2><table class="form-table" role="presentation">';
		self::field( "{$n}[team_intro]", $s['team_intro'], 'Intro', 'textarea' );
		self::field( "{$n}[pick_heading]", $s['pick_heading'], 'Heading above the people' );
		self::photo( "{$n}[photo_id]", (int) $s['photo_id'], 'Logo or photo', 'Blank uses the Site Icon, or the first letter of the name.' );
		self::photo( "{$n}[logo_id]", (int) $s['logo_id'], 'Logo for the links and share pages', 'Optional. When the card shows a person, the links page and share page can show the logo instead.' );
		self::spot_fields( $n, $s );
		echo '</table>';

		echo '<h2>People</h2>';
		if ( Optima_Cards_Settings::people_on() ) {
			echo '<p>Each person\'s card reads their name, title, email and photo from their profile in People. Tick a profile to give them a card; the words below are the card\'s own.</p>';
			$linked = array();
			$legacy = array();
			foreach ( (array) $s['people'] as $p ) {
				if ( ! empty( $p['profile'] ) ) {
					$linked[ (int) $p['profile'] ] = $p;
				} elseif ( ! empty( $p['name'] ) ) {
					$legacy[] = $p;
				}
			}
			$profiles = get_posts( array( 'post_type' => 'optima_team', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'menu_order title', 'order' => 'ASC' ) );
			if ( ! $profiles ) {
				echo '<p class="description">No profiles yet. <a href="' . esc_url( admin_url( 'admin.php?page=optima-team&tab=add' ) ) . '">Add a person</a> in People first.</p>';
			}
			foreach ( $profiles as $i => $post ) {
				$p    = $linked[ $post->ID ] ?? array();
				$on   = isset( $linked[ $post->ID ] );
				$prof = (array) get_post_meta( $post->ID, '_optima_team', true );
				$base = "{$n}[profiles][{$post->ID}]";
				echo '<details class="sh-box"' . ( $on ? ' open' : '' ) . '><summary style="cursor:pointer;font-weight:600">' . esc_html( $post->post_title ) . ( $on ? ' · card on' : '' ) . '</summary>';
				echo '<p><label class="sh-target"><input type="checkbox" name="' . esc_attr( $base . '[on]' ) . '" value="1"' . checked( $on, true, false ) . '> Give ' . esc_html( $post->post_title ) . ' a card</label></p>';
				Optima_Admin_UI::fact( 'Title', (string) ( $prof['role'] ?? '' ), (string) get_edit_post_link( $post->ID ), 'the profile' );
				Optima_Admin_UI::fact( 'Email', (string) ( $prof['email'] ?? '' ), (string) get_edit_post_link( $post->ID ), 'the profile' );
				Optima_Admin_UI::fact( 'Photo', has_post_thumbnail( $post->ID ) ? 'Set' : '', (string) get_edit_post_link( $post->ID ), 'the profile' );
				echo '<table class="form-table" role="presentation">';
				self::field( "{$base}[slug]", (string) ( $p['slug'] ?? $post->post_name ), 'Link name', 'text', 'Lowercase, no spaces. "alex" makes /' . $s['card_base'] . '/alex/.' );
				self::field( "{$base}[intro]", (string) ( $p['intro'] ?? '' ), 'Intro (on their card, in their voice)', 'textarea' );
				self::field( "{$base}[pick]", (string) ( $p['pick'] ?? '' ), 'Why talk to them (on the main card)', 'textarea' );
				self::field( "{$base}[tags]", (string) ( $p['tags'] ?? '' ), 'Tags', 'text', 'Comma separated, two to four short topics.' );
				self::photo( "{$base}[share_id]", (int) ( $p['share_id'] ?? 0 ), 'Share picture', 'Optional. Shows when this person\'s card link is texted or posted. 1200 by 630 pixels.' );
				self::spot_fields( $base, $p );
				echo '</table></details>';
			}
			if ( $legacy ) {
				echo '<div class="sh-box"><p class="sh-label">Cards not linked to a profile yet</p><p class="sh-note">These cards were set up before People. They keep working; add a profile with the same name and the card links to it by itself.</p><ul class="sh-list">';
				foreach ( $legacy as $p ) {
					echo '<li>' . esc_html( (string) $p['name'] ) . ' · /' . esc_html( $s['card_base'] . '/' . ( $p['slug'] ?? '' ) ) . '/ · <a href="' . esc_url( admin_url( 'admin.php?page=optima-team&tab=add' ) ) . '">Add a person</a></li>';
				}
				echo '</ul></div>';
			}
		} else {
			echo '<p class="description">Turn on People (Team credentials) on Build, Features to give each person a card from their profile. Cards set up before stay as they are.</p>';
		}

		echo '<h2>Links page</h2><table class="form-table" role="presentation">';
		self::field( "{$n}[links_intro]", $s['links_intro'], 'Intro', 'textarea' );
		echo '<tr><th scope="row">Newest blog post</th><td><label><input type="hidden" name="' . esc_attr( $n ) . '[links_latest]" value="0"><input type="checkbox" name="' . esc_attr( $n ) . '[links_latest]" value="1"' . checked( $s['links_latest'], 1, false ) . '> Show the newest post first (updates by itself)</label></td></tr>';
		echo '</table><div class="sh-scroll"><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Button text</th><th>Small line under it</th><th>Link (start with / for a page on this site)</th><th>New tab</th></tr></thead><tbody>';
		for ( $i = 0; $i < Optima_Cards_Settings::MAX_LINKS; $i++ ) {
			$l = $s['links'][ $i ] ?? array( 'label' => '', 'sub' => '', 'url' => '', 'external' => 0 );
			$row = $i + 1;
			echo '<tr><td><input class="widefat" aria-label="' . esc_attr( "Button text, row $row" ) . '" name="' . esc_attr( "{$n}[links][$i][label]" ) . '" value="' . esc_attr( $l['label'] ) . '"></td>'
				. '<td><input class="widefat" aria-label="' . esc_attr( "Small line, row $row" ) . '" name="' . esc_attr( "{$n}[links][$i][sub]" ) . '" value="' . esc_attr( $l['sub'] ) . '"></td>'
				. '<td><input class="widefat" aria-label="' . esc_attr( "Link, row $row" ) . '" name="' . esc_attr( "{$n}[links][$i][url]" ) . '" value="' . esc_attr( $l['url'] ) . '" placeholder="/contact/ or https://..."></td>'
				. '<td><input type="checkbox" aria-label="' . esc_attr( "Open in a new tab, row $row" ) . '" name="' . esc_attr( "{$n}[links][$i][external]" ) . '" value="1"' . checked( ! empty( $l['external'] ), true, false ) . '></td></tr>';
		}
		echo '</tbody></table></div><p class="description">Blank rows are skipped. Rows show in this order.</p>';

		echo '<h2>Addresses</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Turn on</th><td><label><input type="hidden" name="' . esc_attr( $n ) . '[enable_cards]" value="0"><input type="checkbox" name="' . esc_attr( $n ) . '[enable_cards]" value="1"' . checked( $s['enable_cards'], 1, false ) . '> Business cards</label> &nbsp; <label><input type="hidden" name="' . esc_attr( $n ) . '[enable_links]" value="0"><input type="checkbox" name="' . esc_attr( $n ) . '[enable_links]" value="1"' . checked( $s['enable_links'], 1, false ) . '> Links page</label></td></tr>';
		self::field( "{$n}[card_base]", $s['card_base'], 'Card address', 'text', 'The word after your domain. Default: card.' );
		self::field( "{$n}[links_base]", $s['links_base'], 'Links address', 'text', 'Default: links.' );
		echo '</table>';

		submit_button( 'Save' );
		echo '</form>';
		// Rare and destructive: last on the screen, closed, behind a confirm step.
		echo '<details class="sh-dev"><summary>Start over</summary><div class="sh-dev-body"><p class="sh-note">Puts every Cards setting back to the defaults. Business facts on Details and People profiles are not touched.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Reset every Cards setting to the defaults?\');">';
		wp_nonce_field( 'optima_cards_reset' );
		echo '<input type="hidden" name="action" value="optima_cards_reset"><button class="button-link-delete">Reset Cards to defaults</button></form></div></details>';
		?>
		<script>
		jQuery(function($){
		  $('.odc-pick').on('click',function(e){e.preventDefault();var td=$(this).closest('td');
		    var f=wp.media({title:'Choose a photo',library:{type:'image'},multiple:false});
		    f.on('select',function(){var a=f.state().get('selection').first().toJSON();
		      td.find('input[type=hidden]').val(a.id);td.find('img').attr('src',(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url)).show();});
		    f.open();});
		  $('.odc-clear').on('click',function(e){e.preventDefault();var td=$(this).closest('td');td.find('input[type=hidden]').val('');td.find('img').hide();});
		});
		</script>
		<?php
		Optima_Admin_UI::close();
	}

	public static function save() {
		if ( ! current_user_can( 'edit_pages' ) || ! check_admin_referer( 'optima_cards_save' ) ) {
			wp_die( 'Not allowed.' );
		}
		$in   = (array) wp_unslash( $_POST[ Optima_Cards_Settings::OPTION ] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field below.
		$txt  = function ( $v ) {
			return sanitize_text_field( (string) $v );
		};
		$area = function ( $v ) {
			return sanitize_textarea_field( (string) $v );
		};
		$old  = get_option( Optima_Cards_Settings::OPTION );
		$old  = is_array( $old ) ? $old : array();
		$out  = array();
		// Facts (rule 45) and the old look settings (rule 7) are no longer on this form:
		// what was saved before stays saved, untouched.
		foreach ( array( 'name', 'phone', 'email', 'city', 'region', 'postal', 'socials', 'accent', 'font' ) as $k ) {
			if ( isset( $old[ $k ] ) ) {
				$out[ $k ] = $old[ $k ];
			}
		}
		foreach ( array( 'footer', 'pick_heading' ) as $k ) {
			$out[ $k ] = $txt( $in[ $k ] ?? '' );
		}
		foreach ( array( 'note', 'team_intro', 'links_intro' ) as $k ) {
			$out[ $k ] = $area( $in[ $k ] ?? '' );
		}
		$out['mode']         = 'light' === ( $in['mode'] ?? '' ) ? 'light' : 'dark';
		$out['photo_id']     = absint( $in['photo_id'] ?? 0 );
		$out['logo_id']        = absint( $in['logo_id'] ?? 0 );
		$out['share_card_id']  = absint( $in['share_card_id'] ?? 0 );
		$out['share_links_id'] = absint( $in['share_links_id'] ?? 0 );
		$out                += self::clean_spot( $in );
		$out['links_latest'] = empty( $in['links_latest'] ) ? 0 : 1;
		$out['enable_cards'] = empty( $in['enable_cards'] ) ? 0 : 1;
		$out['enable_links'] = empty( $in['enable_links'] ) ? 0 : 1;
		$out['card_base']    = sanitize_title( $in['card_base'] ?? '' ) ? sanitize_title( $in['card_base'] ) : 'card';
		$out['links_base']   = sanitize_title( $in['links_base'] ?? '' ) ? sanitize_title( $in['links_base'] ) : 'links';
		$out['people']       = array();
		if ( Optima_Cards_Settings::people_on() ) {
			$prev = array();
			foreach ( (array) ( $old['people'] ?? array() ) as $p ) {
				if ( ! empty( $p['profile'] ) ) {
					$prev[ (int) $p['profile'] ] = $p;
				} elseif ( ! empty( $p['name'] ) ) {
					$out['people'][] = $p; // Not linked to a profile yet: kept exactly as it was.
				}
			}
			foreach ( (array) ( $in['profiles'] ?? array() ) as $pid => $p ) {
				$pid = absint( $pid );
				if ( ! $pid || empty( $p['on'] ) || 'optima_team' !== get_post_type( $pid ) ) {
					continue;
				}
				$out['people'][] = array_merge(
					$prev[ $pid ] ?? array(),
					array(
						'profile'  => $pid,
						'slug'     => sanitize_title( ! empty( $p['slug'] ) ? $p['slug'] : get_post_field( 'post_name', $pid ) ),
						'intro'    => $area( $p['intro'] ?? '' ),
						'pick'     => $area( $p['pick'] ?? '' ),
						'tags'     => $txt( $p['tags'] ?? '' ),
						'share_id' => absint( $p['share_id'] ?? 0 ),
					),
					self::clean_spot( $p )
				);
			}
		}
		foreach ( Optima_Cards_Settings::people_on() ? array() : (array) ( $old['people'] ?? array() ) as $p ) {
			$out['people'][] = $p; // Without People the slots stay as they were.
		}
		$out['links'] = array();
		foreach ( (array) ( $in['links'] ?? array() ) as $l ) {
			if ( '' === trim( (string) ( $l['label'] ?? '' ) ) || '' === trim( (string) ( $l['url'] ?? '' ) ) ) {
				continue;
			}
			$out['links'][] = array(
				'label'    => $txt( $l['label'] ),
				'sub'      => $txt( $l['sub'] ?? '' ),
				'url'      => esc_url_raw( trim( $l['url'] ) ),
				'external' => empty( $l['external'] ) ? 0 : 1,
			);
		}
		update_option( Optima_Cards_Settings::OPTION, $out, false );
		wp_safe_redirect( self::url( array( 'saved' => 1 ) ) );
		exit;
	}

	public static function reset() {
		if ( ! current_user_can( 'edit_pages' ) || ! check_admin_referer( 'optima_cards_reset' ) ) {
			wp_die( 'Not allowed.' );
		}
		delete_option( Optima_Cards_Settings::OPTION );
		wp_safe_redirect( self::url( array( 'reset' => 1 ) ) );
		exit;
	}
}
