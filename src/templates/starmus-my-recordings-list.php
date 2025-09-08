<?php
/**
 * Starmus My Recordings List Template - Accessible Version
 *
 * @package Starmus\templates
 * @version 0.4.6
 * @since   0.4.5
 *
 * @var WP_Query $query         The WordPress query object for the recordings.
 * @var string   $edit_page_url The base URL for the audio editor page.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="starmus-my-recordings-container">

	<!-- ARIA live region to announce content updates for screen readers -->
	<div id="starmus-recordings-announcer" class="starmus-visually-hidden" aria-live="polite" aria-atomic="true"></div>

	<?php if ( $query->have_posts() ) : ?>

		<section aria-labelledby="starmus-recordings-heading">
			<h2 id="starmus-recordings-heading" class="starmus-visually-hidden"><?php esc_html_e( 'A list of your audio recordings', STARMUS_TEXT_DOMAIN ); ?></h2>

			<div class="starmus-recordings-grid">
				<?php
				while ( $query->have_posts() ) :
					$query->the_post();
					$current_post_id = get_the_ID();
					$post_title      = get_the_title();
					?>

					<article id="post-<?php echo esc_attr( strval( $current_post_id ) ); ?>" class="starmus-recording <?php echo ( 0 === $query->current_post ) ? 'starmus-recording--featured' : ''; ?>">
						<header class="starmus-recording__header">
							<<?php echo ( 0 === $query->current_post ) ? 'h2' : 'h3'; ?> class="starmus-recording__title">
								<a href="<?php the_permalink(); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'View details for %s', STARMUS_TEXT_DOMAIN ), $post_title ) ); ?>"><?php echo esc_html( $post_title ); ?></a>
							</<?php echo ( 0 === $query->current_post ) ? 'h2' : 'h3'; ?>>
							<p class="starmus-recording__meta">
								<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
							</p>
						</header>

						<div class="starmus-recording__content">
							<?php the_content(); ?>
						</div>

						<footer class="starmus-recording__footer">
							<?php
							if ( ! empty( $edit_page_url ) && current_user_can( 'edit_post', $current_post_id ) ) :
								$edit_link        = add_query_arg( 'post_id', $current_post_id, $edit_page_url );
								$secure_edit_link = wp_nonce_url( $edit_link, 'starmus_edit_audio', 'nonce' );
								?>
								<a href="<?php echo esc_url( $secure_edit_link ); ?>" 
								   class="starmus-recording__edit-button" 
								   aria-label="<?php echo esc_attr( sprintf( __( 'Edit audio for %s', STARMUS_TEXT_DOMAIN ), $post_title ) ); ?>">
									<?php esc_html_e( 'Edit Audio', STARMUS_TEXT_DOMAIN ); ?>
								</a>
							<?php endif; ?>
						</footer>
					</article>

				<?php endwhile; ?>
			</div><!-- .starmus-recordings-grid -->
		</section>

		<?php
		// Pagination with accessibility improvements
		the_posts_pagination(
			array(
				'mid_size'     => 2,
				'prev_text'    => esc_html__( 'Previous', STARMUS_TEXT_DOMAIN ),
				'next_text'    => esc_html__( 'Next', STARMUS_TEXT_DOMAIN ),
				'screen_reader_text' => esc_html__( 'Recordings navigation', STARMUS_TEXT_DOMAIN ),
				'aria_label'   => esc_html__( 'Recordings', STARMUS_TEXT_DOMAIN ),
			)
		);
		?>

	<?php else : ?>

		<div class="starmus-no-recordings">
			<p><?php esc_html_e( 'You have not submitted any recordings yet.', STARMUS_TEXT_DOMAIN ); ?></p>
		</div>

	<?php endif; ?>

	<?php
	wp_reset_postdata();
	?>
</div><!-- .starmus-my-recordings-container -->
