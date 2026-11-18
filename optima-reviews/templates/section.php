<?php
/**
 * A reviews section: rating summary, review cards, optional disclaimer.
 *
 * Copy to {theme}/optima-reviews/section.php to change the markup.
 *
 * @var array  $reviews    Normalized reviews (Optima_Reviews_Query::normalize()).
 * @var array  $totals     Platform totals (Optima_Reviews_Query::totals()).
 * @var string $layout     grid | row | list.
 * @var string $disclaimer Text from Reviews > Settings, may be empty (on a law site,
 *                         never empty: counsel's wording or the marked placeholder).
 * @var bool   $disclaimer_first Law sites: print the disclaimer before the list.
 * @var bool   $curated    A hand-picked or capped list (featured, topic, count).
 * @var string $curated_label "Selected client reviews".
 * @var string $all_url    The Google profile, for "Read all reviews on Google".
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

	<?php
	$optima_first = ! empty( $disclaimer_first );
	$optima_text  = trim( (string) ( $disclaimer ?? '' ) );
	?>

	<?php if ( $reviews && ! empty( $curated ) ) : ?>
		<p class="optima-reviews__curated">
			<span class="optima-reviews__curated-label"><?php echo esc_html( (string) ( $curated_label ?? __( 'Selected client reviews', 'optima-reviews' ) ) ); ?></span>
			<?php if ( ! empty( $all_url ) ) : ?>
				<a class="optima-reviews__all" href="<?php echo esc_url( $all_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Read all reviews on Google', 'optima-reviews' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'optima-reviews' ); ?></span> <span aria-hidden="true">&rsaquo;</span></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( $optima_first && '' !== $optima_text && $reviews ) : ?>
		<p class="optima-reviews__disclaimer optima-reviews__disclaimer--first"><?php echo esc_html( $optima_text ); ?></p>
	<?php endif; ?>

	<?php if ( $reviews ) : ?>
		<div class="optima-reviews__list"<?php echo 'row' === $layout ? ' tabindex="0"' : ''; ?>>
			<?php
			foreach ( $reviews as $review ) {
				echo Optima_Reviews_Render::template( 'card', array( 'review' => $review ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
			}
			?>
		</div>
	<?php endif; ?>

	<?php if ( ! $optima_first && '' !== $optima_text ) : ?>
		<p class="optima-reviews__disclaimer"><?php echo esc_html( $optima_text ); ?></p>
	<?php endif; ?>
</section>
