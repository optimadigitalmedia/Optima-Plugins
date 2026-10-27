<?php
/**
 * A reviews section: rating summary, review cards, optional disclaimer.
 *
 * Copy to {theme}/optima-reviews/section.php to change the markup.
 *
 * @var array  $reviews    Normalized reviews (Optima_Reviews_Query::normalize()).
 * @var array  $totals     Platform totals (Optima_Reviews_Query::totals()).
 * @var string $layout     grid | row | list.
 * @var string $disclaimer Text from Reviews > Settings, may be empty.
 *
 * @package Optima_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="optima-reviews optima-reviews--<?php echo esc_attr( $layout ); ?>" aria-label="<?php esc_attr_e( 'Reviews', 'optima-reviews' ); ?>">
	<?php
	if ( $totals ) {
		echo Optima_Reviews_Render::template( 'summary', array( 'totals' => $totals ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
	}
	?>

	<?php if ( $reviews ) : ?>
		<div class="optima-reviews__list"<?php echo 'row' === $layout ? ' tabindex="0"' : ''; ?>>
			<?php
			foreach ( $reviews as $review ) {
				echo Optima_Reviews_Render::template( 'card', array( 'review' => $review ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
			}
			?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== trim( $disclaimer ) ) : ?>
		<p class="optima-reviews__disclaimer"><?php echo esc_html( $disclaimer ); ?></p>
	<?php endif; ?>
</section>
