<?php
/**
 * Content -> Pages and posts: every page and post with its dates, newest
 * change first (plugin-first section 4a: Content holds pages and posts with
 * dates). Read-only: each row opens WordPress's own editor. Words still come
 * from the site repo through Approved copy (rule 31); this is where to see
 * what is on the site and how fresh it is.
 *
 * @package Optima_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Hub_Pages {

	const PAGE = 'optima-pages';
	const SHOW = 60;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 16 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_submenu_page( Optima_Hub_Admin::parent(), __( 'Pages and posts', 'optima-build' ), __( 'Pages and posts', 'optima-build' ), 'edit_pages', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::PAGE ) ) {
			Optima_Admin_UI::enqueue( OPTIMA_HUB_URL . 'assets/optima-admin.css' );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$rows = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => self::SHOW,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$counts = array(
			'page' => (int) ( wp_count_posts( 'page' )->publish ?? 0 ),
			'post' => (int) ( wp_count_posts( 'post' )->publish ?? 0 ),
		);
		Optima_Admin_UI::open( __( 'Pages and posts', 'optima-build' ), __( 'Every page and post on the site with its dates, the newest change first. New words come from the site repo through Approved copy.', 'optima-build' ) );
		echo '<section class="sh-box">';
		/* translators: 1: published pages, 2: published posts */
		echo '<p class="sh-value" style="margin:0 0 12px">' . esc_html( sprintf( __( '%1$d pages and %2$d posts published.', 'optima-build' ), $counts['page'], $counts['post'] ) ) . '</p>';
		echo '<p class="sh-btns" style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px"><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=page' ) ) . '">' . esc_html__( 'All pages', 'optima-build' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'edit.php' ) ) . '">' . esc_html__( 'All posts', 'optima-build' ) . '</a></p>';
		if ( ! $rows ) {
			echo '<p class="sh-note">' . esc_html__( 'No pages or posts yet.', 'optima-build' ) . '</p></section>';
			Optima_Admin_UI::close();
			return;
		}
		$statuses = get_post_statuses();
		echo '<div class="sh-scroll"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Title', 'optima-build' ) . '</th><th scope="col">' . esc_html__( 'Type', 'optima-build' ) . '</th><th scope="col">' . esc_html__( 'Status', 'optima-build' ) . '</th><th scope="col">' . esc_html__( 'Published', 'optima-build' ) . '</th><th scope="col">' . esc_html__( 'Last updated', 'optima-build' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $p ) {
			$title = '' !== get_the_title( $p ) ? get_the_title( $p ) : __( '(no title)', 'optima-build' );
			$edit  = current_user_can( 'edit_post', $p->ID ) ? (string) get_edit_post_link( $p->ID ) : '';
			echo '<tr><td>' . ( '' !== $edit ? '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $title ) . '</strong></a>' : '<strong>' . esc_html( $title ) . '</strong>' ) . '</td>';
			echo '<td>' . esc_html( 'page' === $p->post_type ? __( 'Page', 'optima-build' ) : __( 'Post', 'optima-build' ) ) . '</td>';
			echo '<td>' . esc_html( $statuses[ $p->post_status ] ?? ucfirst( $p->post_status ) ) . '</td>';
			echo '<td>' . esc_html( get_the_date( 'M j, Y', $p ) ) . '</td>';
			echo '<td>' . esc_html( get_the_modified_date( 'M j, Y', $p ) ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
		Optima_Admin_UI::close();
	}
}
