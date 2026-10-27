<?php
/**
 * The rating summary: each platform's real overall rating and review count,
 * with links to read every review and to leave one.
 *
 * Copy to {theme}/optima-reviews/summary.php to change the markup.
 *
 * @var array $totals Platform totals (Optima_Reviews_Query::totals()).
 *
 * @package Optima_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $totals ) {
	return;
}
?>
<div class="optima-reviews-summary">
	<?php foreach ( $totals as $total ) : ?>
		<?php
		$source   = Optima_Reviews_Sources::get( $total['source'] );
		$label    = $source ? $source['label'] : '';
		$has_star = $source && $source['stars'] && $total['rating'] > 0;
		$count    = sprintf(
			/* translators: 1: number of reviews, 2: platform name. */
			_n( '%1$s review on %2$s', '%1$s reviews on %2$s', $total['count'], 'optima-reviews' ),
			number_format_i18n( $total['count'] ),
			$label
		);
		?>
		<div class="optima-reviews-summary__item">
			<?php if ( $has_star ) : ?>
				<span class="optima-reviews-summary__score"><?php echo esc_html( number_format_i18n( $total['rating'], 1 ) ); ?></span>
				<?php echo Optima_Reviews_Render::stars( $total['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
			<?php endif; ?>
			<span class="optima-reviews-summary__count">
				<?php if ( $total['url'] ) : ?>
					<a href="<?php echo esc_url( $total['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $count ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $count ); ?>
				<?php endif; ?>
			</span>
			<?php if ( $total['write_url'] ) : ?>
				<a class="optima-reviews-summary__write" href="<?php echo esc_url( $total['write_url'] ); ?>" target="_blank" rel="noopener">
					<?php
					/* translators: %s: platform name. */
					echo esc_html( sprintf( __( 'Leave a review on %s', 'optima-reviews' ), $label ) );
					?>
					<span aria-hidden="true">&rsaquo;</span>
				</a>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
