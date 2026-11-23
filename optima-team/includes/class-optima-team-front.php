<?php
/**
 * The front end: the profile on each /team/<name>/ page, the [optima_team]
 * grid, excerpts from the short bio, author links that point at the person's
 * profile, and what the REST API shows of people to visitors.
 *
 * Everything a visitor sees goes through Optima_Team::public_profile(), so
 * card only contact and credential lines without a source never print here.
 * A person whose profile page is switched off answers "not found" to
 * visitors; people who can edit them still see it, marked noindex.
 *
 * Everything prints inside the theme's own template (through the_content),
 * so it works on classic and block themes and inherits the site's fonts and
 * colors. CSS custom properties let a theme restyle it.
 *
 * @package Optima_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Team_Front {

	/** True while this plugin prints a photo, so the theme's copy can be dropped. */
	private static $own_photo = false;

	/** Guards against the_content running inside itself. */
	private static $rendering = false;

	/** Set once the stylesheet printed beside a block that rendered after the head. */
	private static $printed = false;

	/** The person whose photo a theme is printing, for the alt text default. */
	private static $alt_name = '';

	/**
	 * The at-a-glance card already printed on this page (release 2): the
	 * profile id and whether it showed the photo, so the profile below it
	 * leaves out what the card already says.
	 *
	 * @var array{id:int, photo:bool}
	 */
	public static $glance = array( 'id' => 0, 'photo' => false );

	/**
	 * The stylesheet for blocks printed through a slot: enqueued while the
	 * head is still to come, else printed once beside the first block.
	 */
	public static function style_tag(): string {
		if ( ! wp_style_is( 'optima-team', 'registered' ) ) {
			wp_register_style( 'optima-team', OPTIMA_TEAM_URL . 'assets/optima-team.css', array(), OPTIMA_TEAM_VERSION );
		}
		if ( self::$printed || wp_style_is( 'optima-team', 'done' ) ) {
			return '';
		}
		if ( ! did_action( 'wp_head' ) || is_admin() ) {
			wp_enqueue_style( 'optima-team' );
			return '';
		}
		$styles = wp_styles();
		wp_dequeue_style( 'optima-team' );
		ob_start();
		$styles->do_item( 'optima-team' );
		$link           = (string) ob_get_clean();
		$styles->done[] = 'optima-team';
		self::$printed  = true;
		return $link;
	}

	public static function boot(): void {
		add_action( 'template_redirect', array( __CLASS__, 'guard_profile' ), 0 );
		add_action( 'pre_get_posts', array( __CLASS__, 'archive_query' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'search_query' ) );
		add_action( 'begin_fetch_post_thumbnail_html', array( __CLASS__, 'photo_begin' ), 10, 2 );
		add_action( 'end_fetch_post_thumbnail_html', array( __CLASS__, 'photo_end' ) );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'photo_alt' ), 10, 2 );
		add_filter( 'rest_' . Optima_Team::POST_TYPE . '_query', array( __CLASS__, 'rest_query' ), 10, 2 );
		add_filter( 'rest_prepare_' . Optima_Team::POST_TYPE, array( __CLASS__, 'rest_prepare' ), 10, 2 );
		add_filter( 'rest_post_search_query', array( __CLASS__, 'rest_search' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'profile' ), 20 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'drop_theme_photo' ), 10, 2 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt' ), 10, 2 );
		add_filter( 'author_link', array( __CLASS__, 'author_link' ), 10, 2 );
		add_shortcode( 'optima_team', array( __CLASS__, 'grid' ) );
	}

	public static function assets(): void {
		wp_register_style( 'optima-team', OPTIMA_TEAM_URL . 'assets/optima-team.css', array(), OPTIMA_TEAM_VERSION );
		if ( is_singular( Optima_Team::POST_TYPE ) || is_post_type_archive( Optima_Team::POST_TYPE ) ) {
			wp_enqueue_style( 'optima-team' );
			return;
		}
		$post = get_post();
		if ( is_singular() && $post && has_shortcode( (string) $post->post_content, 'optima_team' ) ) {
			wp_enqueue_style( 'optima-team' );
		}
	}

	/* ---------- who can see what ---------- */

	/**
	 * Profile page switched off: visitors get the theme's "not found" page.
	 * Someone who can edit the person still sees it, so they can check it, and
	 * search engines are told not to list it. Runs before the bio forward, so
	 * a hidden profile is never forwarded either.
	 */
	public static function guard_profile(): void {
		if ( ! is_singular( Optima_Team::POST_TYPE ) ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( ! $id || Optima_Team::shows( Optima_Team::profile( $id ), 'bio' ) ) {
			return;
		}
		if ( current_user_can( 'edit_post', $id ) ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/** The /team/ list shows the team page: no one whose page is off or who is not on the team page. */
	public static function archive_query( $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( Optima_Team::POST_TYPE ) ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => Optima_Team::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! Optima_Team::on_team_page( $id ) || ! Optima_Team::shows( Optima_Team::profile( $id ), 'bio' ) ) {
				$out[] = $id;
			}
		}
		if ( $out ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $out ) );
		}
	}

	/** Profile pages that are switched off stay out of the site's own search results too. */
	public static function search_query( $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}
		$hidden = Optima_Team::hidden_profiles();
		if ( $hidden ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $hidden ) );
		}
	}

	/* ---------- photo alt text ---------- */

	/** A theme is about to print a person's photo: remember the name. */
	public static function photo_begin( $post_id, $thumb_id ): void {
		unset( $thumb_id );
		self::$alt_name = ( ! is_admin() && ! self::$rendering && Optima_Team::POST_TYPE === get_post_type( (int) $post_id ) ) ? wp_strip_all_tags( get_the_title( (int) $post_id ) ) : '';
	}

	public static function photo_end(): void {
		self::$alt_name = '';
	}

	/**
	 * A person's photo with no alt text set reads as their name. Only fills an
	 * empty alt that came from the media library; this plugin's own markup
	 * already chooses its alt text.
	 */
	public static function photo_alt( $attr, $attachment ) {
		if ( '' === self::$alt_name || ! is_array( $attr ) || '' !== (string) ( $attr['alt'] ?? '' ) ) {
			return $attr;
		}
		$id = is_object( $attachment ) ? (int) $attachment->ID : 0;
		if ( $id && '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
			$attr['alt'] = self::$alt_name;
		}
		return $attr;
	}

	/* ---------- the profile page ---------- */

	public static function profile( $content ) {
		if ( self::$rendering || ! is_singular( Optima_Team::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id = (int) get_the_ID();
		if ( $id !== (int) get_queried_object_id() ) {
			return $content;
		}
		self::$rendering = true;
		$html            = self::render_profile( $id, (string) $content );
		self::$rendering = false;
		return $html;
	}

	/** The theme's own featured image on a profile page: the profile prints it instead. */
	public static function drop_theme_photo( $html, $post_id ) {
		if ( self::$own_photo || is_admin() || ! is_singular( Optima_Team::POST_TYPE ) ) {
			return $html;
		}
		return (int) $post_id === (int) get_queried_object_id() ? '' : $html;
	}

	private static function render_profile( int $id, string $content ): string {
		$p        = Optima_Team::public_profile( Optima_Team::profile( $id ) );
		$s        = Optima_Team::settings();
		$name     = get_the_title( $id );
		$military = Optima_Team::military_line( $p );
		$langs    = Optima_Team::languages( $p );
		// The at-a-glance card above already shows the role, languages, contact and maybe the photo.
		$glanced  = self::$glance['id'] === $id;
		if ( $glanced ) {
			$p['role'] = '';
			$langs     = array();
			$s['contact'] = 0;
		}

		ob_start();
		?>
		<div class="optima-team-profile" data-optima-view="team_profile_view" data-optima-label="<?php echo esc_attr( wp_strip_all_tags( $name ) ); ?>">
			<div class="optima-team-profile__head">
				<?php
				if ( has_post_thumbnail( $id ) && ! ( $glanced && self::$glance['photo'] ) ) {
					self::$own_photo = true;
					echo '<div class="optima-team-profile__photo">' . get_the_post_thumbnail( $id, 'medium_large', array( 'alt' => esc_attr( wp_strip_all_tags( $name ) ), 'fetchpriority' => 'high', 'loading' => 'eager' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
					self::$own_photo = false;
				}
				?>
				<div class="optima-team-profile__intro">
					<?php if ( '' !== $p['role'] ) : ?>
						<p class="optima-team-profile__role"><?php echo esc_html( $p['role'] ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $p['bio'] ) : ?>
						<p class="optima-team-profile__bio"><?php echo esc_html( $p['bio'] ); ?></p>
					<?php endif; ?>
					<?php if ( $s['contact'] && ( '' !== $p['email'] || '' !== $p['phone'] ) ) : ?>
						<p class="optima-team-profile__contact">
							<?php if ( '' !== $p['phone'] ) : ?>
								<a class="optima-team-button" href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $p['phone'] ) ); ?>" data-optima-track="team_call" data-optima-label="<?php echo esc_attr( wp_strip_all_tags( $name ) ); ?>"><?php echo esc_html( $p['phone'] ); ?></a>
							<?php endif; ?>
							<?php if ( '' !== $p['email'] ) : ?>
								<a class="optima-team-button" href="<?php echo esc_url( 'mailto:' . $p['email'] ); ?>" data-optima-track="team_email" data-optima-label="<?php echo esc_attr( wp_strip_all_tags( $name ) ); ?>"><?php echo esc_html( $p['email'] ); ?></a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( '' !== trim( wp_strip_all_tags( $content ) ) ) : ?>
				<div class="optima-team-profile__body"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already filtered post content. ?></div>
			<?php endif; ?>

			<div class="optima-team-profile__facts">
				<?php
				$items = array();
				foreach ( Optima_Team::practice_links( $p ) as $area ) {
					$items[] = '' !== $area['url'] ? '<a href="' . esc_url( $area['url'] ) . '">' . esc_html( $area['name'] ) . '</a>' : esc_html( $area['name'] );
				}
				self::section( __( 'Practice areas', 'optima-team' ), $items );

				if ( Optima_Team::is_law() && $p['attorney'] && $p['bar'] ) {
					$items = array();
					foreach ( $p['bar'] as $row ) {
						$line = $row['state'];
						if ( '' !== $row['year'] ) {
							/* translators: %s: year. */
							$line .= ', ' . sprintf( __( 'admitted %s', 'optima-team' ), $row['year'] );
						}
						$sub = array();
						if ( '' !== $row['number'] ) {
							/* translators: %s: bar number. */
							$sub[] = esc_html( sprintf( __( 'Bar no. %s', 'optima-team' ), $row['number'] ) );
						}
						if ( '' !== $row['url'] ) {
							$sub[] = '<a href="' . esc_url( $row['url'] ) . '" rel="noopener">' . esc_html__( 'Check the license', 'optima-team' ) . '<span class="screen-reader-text"> ' . esc_html( $row['state'] ) . '</span></a>';
						}
						$item = esc_html( $line );
						if ( $sub ) {
							$item .= '<span class="optima-team-sub">' . implode( ' <span aria-hidden="true">&middot;</span> ', $sub ) . '</span>';
						}
						$items[] = $item;
					}
					self::section( __( 'Bar admissions', 'optima-team' ), $items );
				}

				$items = array();
				foreach ( (array) $p['certifications'] as $row ) {
					$item = esc_html( Optima_Team::certification_line( $row ) );
					if ( '' !== (string) ( $row['url'] ?? '' ) ) {
						$item .= '<span class="optima-team-sub"><a href="' . esc_url( $row['url'] ) . '" rel="noopener">' . esc_html__( 'Check the certification', 'optima-team' ) . '</a></span>';
					}
					$items[] = $item;
				}
				self::section( __( 'Board certification', 'optima-team' ), $items );

				$items = array();
				foreach ( $p['education'] as $row ) {
					$items[] = esc_html( implode( ', ', array_filter( array( $row['degree'], $row['school'], $row['year'] ) ) ) );
				}
				self::section( __( 'Education', 'optima-team' ), $items );

				if ( '' !== $military ) {
					self::section( __( 'Military service', 'optima-team' ), array( esc_html( $military ) ) );
				}

				self::section( __( 'Professional memberships', 'optima-team' ), array_map( 'esc_html', $p['memberships'] ) );

				$items = array();
				foreach ( Optima_Team::printable_awards( $p ) as $row ) {
					$items[] = self::award_html( $row );
				}
				self::section( __( 'Awards and honors', 'optima-team' ), $items );

				if ( $langs ) {
					self::section( __( 'Languages', 'optima-team' ), array( esc_html( implode( ', ', $langs ) ) ) );
				}

				$items = array();
				foreach ( $p['same_as'] as $url ) {
					$items[] = '<a href="' . esc_url( $url ) . '" rel="me noopener">' . esc_html( self::site_label( $url ) ) . '</a>';
				}
				self::section( __( 'Other profiles', 'optima-team' ), $items, 'optima-team-links' );
				?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One facts section: a heading and a list. Items arrive escaped.
	 *
	 * @param string   $heading Section heading.
	 * @param string[] $items   Escaped HTML items.
	 * @param string   $class   Extra list class.
	 */
	private static function section( string $heading, array $items, string $class = '' ): void {
		$items = array_filter( $items );
		if ( ! $items ) {
			return;
		}
		echo '<section class="optima-team-facts">';
		echo '<h2 class="optima-team-facts__title">' . esc_html( $heading ) . '</h2>';
		echo '<ul class="optima-team-facts__list' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '">';
		foreach ( $items as $item ) {
			echo '<li>' . $item . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		}
		echo '</ul></section>';
	}

	/**
	 * One award: its line, and the issuer's "How this is awarded" page beside
	 * it when the setup file names one.
	 *
	 * @param array $row A printable award row.
	 */
	public static function award_html( array $row ): string {
		$html = esc_html( Optima_Team::award_line( $row ) );
		if ( '' !== (string) ( $row['standards_url'] ?? '' ) ) {
			$html .= ' <a class="optima-team-standards" href="' . esc_url( $row['standards_url'] ) . '" rel="noopener">' . esc_html__( 'How this is awarded', 'optima-team' ) . '<span class="screen-reader-text"> ' . esc_html( (string) $row['name'] ) . '</span></a>';
		}
		return $html;
	}

	/** A readable name for a profile link. */
	public static function site_label( string $url ): string {
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host  = preg_replace( '/^www\./', '', $host );
		$known = array(
			'linkedin.com'       => 'LinkedIn',
			'avvo.com'           => 'Avvo',
			'justia.com'         => 'Justia',
			'lawyers.justia.com' => 'Justia',
			'martindale.com'     => 'Martindale-Hubbell',
			'superlawyers.com'   => 'Super Lawyers',
			'facebook.com'       => 'Facebook',
			'instagram.com'      => 'Instagram',
			'x.com'              => 'X',
			'twitter.com'        => 'X',
			'youtube.com'        => 'YouTube',
			'github.com'         => 'GitHub',
		);
		if ( isset( $known[ $host ] ) ) {
			return $known[ $host ];
		}
		if ( preg_match( '/(^|\.)([a-z]{2})bar\.(org|gov)$/', $host ) || preg_match( '/bar\.(org|gov)$/', $host ) ) {
			return __( 'State bar profile', 'optima-team' );
		}
		return $host;
	}

	/* ---------- the grid ---------- */

	/**
	 * [optima_team columns="3" ids="12,15" limit="-1" bio="yes" heading="h3" group="auto"]
	 *
	 * group="auto" (the default) lists attorneys first and everyone else under
	 * their group once any profile has a group saved; "yes" always groups;
	 * "no" keeps one list in profile order, as before release 2.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function grid( $atts ): string {
		$s    = Optima_Team::settings();
		$atts = shortcode_atts(
			array(
				'columns' => (string) $s['columns'],
				'ids'     => '',
				'limit'   => '-1',
				'bio'     => 'yes',
				'heading' => 'h3',
				'group'   => 'auto',
			),
			$atts,
			'optima_team'
		);

		$cols    = min( 4, max( 1, absint( $atts['columns'] ) ) );
		$heading = in_array( $atts['heading'], array( 'h2', 'h3', 'h4' ), true ) ? $atts['heading'] : 'h3';
		$limit   = (int) $atts['limit'];
		$args    = array(
			'post_type'      => Optima_Team::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'no_found_rows'  => true,
		);
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $atts['ids'] ) ) );
		if ( $ids ) {
			$args['post__in'] = $ids;
			$args['orderby']  = 'post__in';
		}
		$people = get_posts( $args );
		// Without ids: the team page, everyone live with Team page switched on.
		// With ids: those people, in that order.
		if ( ! $ids ) {
			$people = array_values(
				array_filter(
					$people,
					static function ( $person ) {
						return Optima_Team::on_team_page( (int) $person->ID );
					}
				)
			);
		}
		if ( $limit > 0 ) {
			$people = array_slice( $people, 0, $limit );
		}
		if ( ! $people ) {
			return '';
		}

		wp_enqueue_style( 'optima-team' );

		// Groups: attorneys first, then the rest by group, then anyone without one.
		$sets  = array( '' => $people );
		$named = false;
		foreach ( $people as $person ) {
			if ( '' !== (string) Optima_Team::profile( (int) $person->ID )['group'] ) {
				$named = true;
				break;
			}
		}
		$grouped = 'yes' === $atts['group'] || ( 'auto' === $atts['group'] && $named );
		if ( $grouped ) {
			$sets = array_fill_keys( array_keys( Optima_Team::team_groups() ), array() );
			$rest = array();
			foreach ( $people as $person ) {
				$g = Optima_Team::group_of( Optima_Team::profile( (int) $person->ID ) );
				if ( '' !== $g ) {
					$sets[ $g ][] = $person;
				} else {
					$rest[] = $person;
				}
			}
			$sets['_other'] = $rest;
			$sets           = array_filter( $sets );
		}
		$group_heading = array( 'h3' => 'h2', 'h4' => 'h3' )[ $heading ] ?? '';

		ob_start();
		foreach ( $sets as $key => $list ) {
			if ( $grouped && count( $sets ) > 1 && '' !== $group_heading ) {
				$label = Optima_Team::team_groups()[ $key ] ?? __( 'Our team', 'optima-team' );
				echo '<' . esc_html( $group_heading ) . ' class="optima-team-group">' . esc_html( $label ) . '</' . esc_html( $group_heading ) . '>';
			}
			echo '<ul class="optima-team-grid" style="--optima-team-cols:' . (int) $cols . '">';
			foreach ( $list as $person ) {
				echo self::card( $person, $heading, 'no' !== $atts['bio'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in card().
			}
			echo '</ul>';
		}
		return (string) ob_get_clean();
	}

	/**
	 * One person on the team grid.
	 *
	 * @param WP_Post $person  Profile post.
	 * @param string  $heading h2, h3 or h4.
	 * @param bool    $bio     Show the short bio.
	 */
	public static function card( WP_Post $person, string $heading = 'h3', bool $bio = true ): string {
		$p    = Optima_Team::public_profile( Optima_Team::profile( (int) $person->ID ) );
		$name = get_the_title( $person );
		// A person whose profile page is off shows without a link.
		$url  = Optima_Team::shows( $p, 'bio' ) ? get_permalink( $person ) : '';
		$h    = '<li class="optima-team-card">';
		if ( has_post_thumbnail( $person ) ) {
			self::$rendering = true; // This markup sets its own alt text.
			$img             = get_the_post_thumbnail( $person, 'medium_large', array( 'alt' => '', 'loading' => 'lazy' ) );
			self::$rendering = false;
			if ( '' !== $url ) {
				$h .= '<a class="optima-team-card__photo" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true" data-optima-track="team_profile_click" data-optima-label="' . esc_attr( wp_strip_all_tags( $name ) ) . '">' . $img . '</a>';
			} else {
				$h .= '<span class="optima-team-card__photo" aria-hidden="true">' . $img . '</span>';
			}
		}
		$h .= '<div class="optima-team-card__text">';
		$h .= '' !== $url
			? '<' . esc_html( $heading ) . ' class="optima-team-card__name"><a href="' . esc_url( $url ) . '" data-optima-track="team_profile_click">' . esc_html( wp_strip_all_tags( $name ) ) . '</a></' . esc_html( $heading ) . '>'
			: '<' . esc_html( $heading ) . ' class="optima-team-card__name">' . esc_html( wp_strip_all_tags( $name ) ) . '</' . esc_html( $heading ) . '>';
		if ( '' !== $p['role'] ) {
			$h .= '<p class="optima-team-card__role">' . esc_html( $p['role'] ) . '</p>';
		}
		$military = Optima_Team::military_line( $p );
		if ( '' !== $military ) {
			$h .= '<p class="optima-team-card__meta">' . esc_html( $military ) . '</p>';
		}
		if ( $bio && '' !== $p['bio'] ) {
			$h .= '<p class="optima-team-card__bio">' . esc_html( $p['bio'] ) . '</p>';
		}
		return $h . '</div></li>';
	}

	/* ---------- excerpts and bylines ---------- */

	public static function excerpt( $excerpt, $post = null ) {
		$post = get_post( $post );
		if ( '' !== trim( (string) $excerpt ) || ! $post || Optima_Team::POST_TYPE !== $post->post_type ) {
			return $excerpt;
		}
		return Optima_Team::profile( (int) $post->ID )['bio'];
	}

	public static function author_link( $link, $author_id ) {
		if ( ! Optima_Team::settings()['bylines'] ) {
			return $link;
		}
		$member = Optima_Team::member_for_user( (int) $author_id );
		// No profile page to send readers to: keep WordPress's own author link.
		$url = $member ? Optima_Team::profile_url( $member ) : '';
		return '' !== $url ? $url : $link;
	}

	/* ---------- the REST API ---------- */

	/** Someone who may see every profile through the REST API: editors and the block editor. */
	private static function rest_trusted(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * The people list in the REST API (/wp-json/wp/v2/optima_team): for anyone
	 * who cannot edit posts, only live people whose own page is on.
	 *
	 * @param array $args WP_Query arguments.
	 */
	public static function rest_query( $args, $request = null ) {
		unset( $request );
		if ( ! is_array( $args ) || self::rest_trusted() ) {
			return $args;
		}
		$args['post_status'] = 'publish';
		return self::without_hidden( $args, Optima_Team::hidden_profiles() );
	}

	/**
	 * Leave hidden profiles out of a query. A list of IDs (post__in) wins over
	 * post__not_in in WP_Query, so hidden IDs are taken out of it directly.
	 *
	 * @param array $args   WP_Query arguments.
	 * @param int[] $hidden Hidden profile IDs.
	 */
	public static function without_hidden( array $args, array $hidden ): array {
		if ( ! $hidden ) {
			return $args;
		}
		if ( ! empty( $args['post__in'] ) ) {
			$keep             = array_values( array_diff( array_map( 'intval', (array) $args['post__in'] ), $hidden ) );
			$args['post__in'] = $keep ? $keep : array( 0 );
			return $args;
		}
		$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $hidden );
		return $args;
	}

	/**
	 * One person in the REST API. For anyone who cannot edit posts, a person
	 * whose own page is off, or who is not live, is "not found", the same as
	 * their page. Card only data, details waiting for an OK and link data are
	 * never sent to them, whatever registered it.
	 *
	 * @param WP_REST_Response $response The response.
	 * @param WP_Post          $post     The person.
	 */
	public static function rest_prepare( $response, $post ) {
		if ( self::rest_trusted() || ! $post instanceof WP_Post ) {
			return $response;
		}
		if ( 'publish' !== $post->post_status || ! Optima_Team::shows( Optima_Team::profile( (int) $post->ID ), 'bio' ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'optima-team' ), array( 'status' => 404 ) );
		}
		if ( is_object( $response ) && method_exists( $response, 'get_data' ) && method_exists( $response, 'set_data' ) ) {
			$response->set_data( self::rest_public_data( (array) $response->get_data() ) );
		}
		return $response;
	}

	/** Keys that never go to the public REST API, at the top level or under meta. */
	public static function rest_private_keys(): array {
		return array_merge(
			array( Optima_Team::META, Optima_Team::PENDING, '_optima_team_link', '_optima_team_link_hash', 'optima_team', 'pending', 'link' ),
			Optima_Team::card_keys()
		);
	}

	/**
	 * A person's REST data with the private keys taken out.
	 *
	 * @param array $data Response data.
	 */
	public static function rest_public_data( array $data ): array {
		foreach ( self::rest_private_keys() as $key ) {
			unset( $data[ $key ] );
			if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
				unset( $data['meta'][ $key ] );
			}
		}
		return $data;
	}

	/**
	 * The REST search (/wp-json/wp/v2/search): hidden profiles stay out for
	 * anyone who cannot edit posts, the same as the site's own search.
	 *
	 * @param array $args WP_Query arguments.
	 */
	public static function rest_search( $args, $request = null ) {
		unset( $request );
		if ( ! is_array( $args ) || self::rest_trusted() ) {
			return $args;
		}
		return self::without_hidden( $args, Optima_Team::hidden_profiles() );
	}
}
