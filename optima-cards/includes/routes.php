<?php
/**
 * URLs. Nothing here needs a WordPress Page: the plugin owns these addresses.
 *
 *   /card/                      team card            /card/<person>/            a person's card
 *   /card/save/                 team vCard           /card/<person>/save/       their vCard
 *   /card/share/                team share mode      /card/<person>/share/      their share mode
 *   /card/share/manifest/       home-screen manifest /card/<person>/share/manifest/
 *   /links/                     link in bio
 *
 * The "card" and "links" words are settings. Rules flush on activation, when
 * those words change, and once per plugin version.
 *
 * @package Optima_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Cards_Routes {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 1 );
	}

	public static function activate() {
		self::rules();
		flush_rewrite_rules( false );
	}

	public static function rules() {
		$s = Optima_Cards_Settings::get();
		$c = preg_quote( trim( $s['card_base'], '/' ), '#' );
		$l = preg_quote( trim( $s['links_base'], '/' ), '#' );
		if ( $s['enable_cards'] ) {
			add_rewrite_rule( "^{$c}/?$", 'index.php?odc_view=card&odc_who=team', 'top' );
			add_rewrite_rule( "^{$c}/save/?$", 'index.php?odc_view=vcard&odc_who=team', 'top' );
			add_rewrite_rule( "^{$c}/share/?$", 'index.php?odc_view=share&odc_who=team', 'top' );
			add_rewrite_rule( "^{$c}/share/manifest/?$", 'index.php?odc_view=manifest&odc_who=team', 'top' );
			add_rewrite_rule( "^{$c}/([a-z0-9-]+)/save/?$", 'index.php?odc_view=vcard&odc_who=$matches[1]', 'top' );
			add_rewrite_rule( "^{$c}/([a-z0-9-]+)/share/manifest/?$", 'index.php?odc_view=manifest&odc_who=$matches[1]', 'top' );
			add_rewrite_rule( "^{$c}/([a-z0-9-]+)/share/?$", 'index.php?odc_view=share&odc_who=$matches[1]', 'top' );
			add_rewrite_rule( "^{$c}/([a-z0-9-]+)/?$", 'index.php?odc_view=card&odc_who=$matches[1]', 'top' );
		}
		if ( $s['enable_links'] ) {
			add_rewrite_rule( "^{$l}/?$", 'index.php?odc_view=links', 'top' );
		}
		$stamp = OPTIMA_CARDS_VERSION . '|' . $s['card_base'] . '|' . $s['links_base'] . '|' . $s['enable_cards'] . $s['enable_links'];
		if ( get_option( 'optima_cards_routes' ) !== $stamp ) {
			flush_rewrite_rules( false );
			update_option( 'optima_cards_routes', $stamp, false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = 'odc_view';
		$vars[] = 'odc_who';
		return $vars;
	}

	public static function dispatch() {
		$view = get_query_var( 'odc_view' );
		if ( ! $view ) {
			return;
		}
		if ( 'links' === $view ) {
			Optima_Cards_Render::links_page();
			exit;
		}
		$people = Optima_Cards_Settings::people();
		$who    = get_query_var( 'odc_who' ) ? get_query_var( 'odc_who' ) : 'team';
		if ( ! isset( $people[ $who ] ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return; // the theme's 404 page
		}
		$p = $people[ $who ];
		switch ( $view ) {
			case 'vcard':
				Optima_Cards_VCard::send( $p );
				break;
			case 'manifest':
				Optima_Cards_Render::manifest( $p );
				break;
			case 'share':
				Optima_Cards_Render::share_page( $p );
				break;
			default:
				Optima_Cards_Render::card_page( $p, $people );
		}
		exit;
	}
}
