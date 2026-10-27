<?php
/**
 * One review card.
 *
 * Copy to {theme}/optima-reviews/card.php to change the markup.
 *
 * @var array $review Normalized review (Optima_Reviews_Query::normalize()).
 *
 * @package Optima_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$optima_source = $review['source'];
$optima_date   = Optima_Reviews_Render::date( $review['date'] );
?>
<article class="optima-review optima-review--<?php echo esc_attr( $optima_source ); ?>">
	<div class="optima-review__top">
		<span class="optima-review__badge"><?php echo esc_html( Optima_Reviews_Sources::badge( $optima_source ) ); ?></span>
		<?php if ( $review['rating'] > 0 ) : ?>
			<?php echo Optima_Reviews_Render::stars( (float) $review['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
		<?php endif; ?>
	</div>

	<blockquote class="optima-review__text"<?php echo $review['url'] ? ' cite="' . esc_url( $review['url'] ) . '"' : ''; ?>>
		<?php echo wpautop( esc_html( $review['text'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- text escaped before wpautop. ?>
	</blockquote>

	<footer class="optima-review__footer">
		<?php if ( $review['photo'] ) : ?>
			<img class="optima-review__avatar" src="<?php echo esc_url( $review['photo'] ); ?>" alt="" width="40" height="40" loading="lazy" decoding="async" referrerpolicy="no-referrer" />
		<?php else : ?>
			<span class="optima-review__avatar optima-review__avatar--initial" aria-hidden="true"><?php echo esc_html( Optima_Reviews_Render::initial( $review['name'] ) ); ?></span>
		<?php endif; ?>

		<div class="optima-review__who">
			<span class="optima-review__name"><?php echo esc_html( $review['name'] ); ?></span>
			<?php if ( $review['detail'] || $optima_date ) : ?>
				<span class="optima-review__meta">
					<?php echo esc_html( implode( ' · ', array_filter( array( $review['detail'], $optima_date ) ) ) ); ?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( $review['url'] ) : ?>
			<a class="optima-review__link" href="<?php echo esc_url( $review['url'] ); ?>" target="_blank" rel="noopener">
				<?php echo esc_html( Optima_Reviews_Sources::view_text( $optima_source ) ); ?>
				<span class="screen-reader-text">
					<?php
					/* translators: %s: reviewer name. */
					echo esc_html( sprintf( __( '(review by %s, opens in a new tab)', 'optima-reviews' ), $review['name'] ) );
					?>
				</span>
				<span aria-hidden="true">&rsaquo;</span>
			</a>
		<?php endif; ?>
	</footer>
</article>
