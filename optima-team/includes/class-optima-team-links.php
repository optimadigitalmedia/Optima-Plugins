<?php
/**
 * What a People profile feeds (plugin-first rule 47): bylines (posts by the
 * linked user), case results that name the person (Optima Case Results), and
 * pages the person reviewed (the Reviewed by box). One place to count and
 * list them, used by the People screen, the profile editor and offboarding.
 *
 * @package Optima_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Team_Links {

	/** Case Results' meta key for the attorney (Optima_Results::META['attorney']). */
	const RESULT_META = '_optima_result_attorney';

	public static function boot(): void {
		add_action( 'add_meta_boxes_' . Optima_Team::POST_TYPE, array( __CLASS__, 'box' ) );
	}

	/**
	 * Counts for one profile.
	 *
	 * @return array{posts:int, results:int, reviewed:int, card:bool}
	 */
	public static function counts( int $post_id ): array {
		$p     = Optima_Team::profile( $post_id );
		$posts = 0;
		if ( $p['user'] ) {
			$posts = (int) count_user_posts( (int) $p['user'], 'post', true );
		}
		$results = 0;
		if ( post_type_exists( 'optima_result' ) ) {
			$results = count(
				get_posts(
					array(
						'post_type'      => 'optima_result',
						'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
						'posts_per_page' => 500,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_key'       => self::RESULT_META, // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value'     => (string) $post_id, // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				)
			);
		}
		$reviewed = class_exists( 'Optima_Team_Reviewed' ) ? count( Optima_Team_Reviewed::pages_for( $post_id ) ) : 0;
		return array(
			'posts'    => $posts,
			'results'  => $results,
			'reviewed' => $reviewed,
			'card'     => class_exists( 'Optima_Cards' ),
		);
	}

	/** Links to the lists behind each count. */
	public static function urls( int $post_id ): array {
		$p = Optima_Team::profile( $post_id );
		return array(
			'posts'    => $p['user'] ? admin_url( 'edit.php?author=' . (int) $p['user'] ) : '',
			'results'  => post_type_exists( 'optima_result' ) ? admin_url( 'edit.php?post_type=optima_result&optima_attorney=' . $post_id ) : '',
			'reviewed' => admin_url( 'edit.php?post_type=page&optima_reviewed_by=' . $post_id ),
		);
	}

	public static function box(): void {
		add_meta_box( 'optima-team-feeds', __( 'This profile feeds', 'optima-team' ), array( __CLASS__, 'render_box' ), Optima_Team::POST_TYPE, 'side', 'default' );
	}

	public static function render_box( WP_Post $post ): void {
		$c = self::counts( $post->ID );
		$u = self::urls( $post->ID );
		$rows = array(
			array( __( 'Team page', 'optima-team' ), 'publish' === $post->post_status ? __( 'Listed', 'optima-team' ) : __( 'Not until published', 'optima-team' ), (string) get_post_type_archive_link( Optima_Team::POST_TYPE ) ),
			array( __( 'Profile page', 'optima-team' ), 'publish' === $post->post_status ? (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH ) : __( 'Not until published', 'optima-team' ), 'publish' === $post->post_status ? get_permalink( $post ) : '' ),
			/* translators: %d: posts */
			array( __( 'Bylines', 'optima-team' ), sprintf( _n( '%d post', '%d posts', $c['posts'], 'optima-team' ), $c['posts'] ), $u['posts'] ),
		);
		if ( post_type_exists( 'optima_result' ) ) {
			/* translators: %d: case results */
			$rows[] = array( __( 'Case results', 'optima-team' ), sprintf( _n( '%d result', '%d results', $c['results'], 'optima-team' ), $c['results'] ), $u['results'] );
		}
		/* translators: %d: pages */
		$rows[] = array( __( 'Reviewed by', 'optima-team' ), sprintf( _n( '%d page', '%d pages', $c['reviewed'], 'optima-team' ), $c['reviewed'] ), $u['reviewed'] );
		echo '<ul class="st-feeds">';
		foreach ( $rows as $row ) {
			echo '<li><span>' . esc_html( $row[0] ) . '</span>';
			echo '' !== $row[2] ? '<a href="' . esc_url( $row[2] ) . '">' . esc_html( $row[1] ) . '</a>' : '<strong>' . esc_html( $row[1] ) . '</strong>';
			echo '</li>';
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Everything that names this person reads from this profile. Facts come from the setup file; words from the site repo.', 'optima-team' ) . '</p>';
	}
}
