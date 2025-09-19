<?php
/**
 * Starmus My Recordings List Template - Accessible Version
 *
 * @package Starmus\templates
 * @version 0.6.9
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
					$audio_url       = get_post_meta( $current_post_id, 'audio_file_url', true );
					$recording_type  = get_the_terms( $current_post_id, 'recording_type' );
					$language        = get_the_terms( $current_post_id, 'language' );
					$duration        = get_post_meta( $current_post_id, 'audio_duration', true );
					?>

					<div class="starmus-card">
						<div class="starmus-card__header">
							<h3 class="starmus-card__title">
								<a href="<?php the_permalink(); ?>" class="starmus-card__link">
									<?php echo esc_html( $post_title ); ?>
								</a>
							</h3>
							<div class="starmus-card__meta">
								<span class="starmus-card__date">
									<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
										<?php echo esc_html( get_the_date( 'M j, Y' ) ); ?>
									</time>
								</span>
								<?php if ( $recording_type && ! is_wp_error( $recording_type ) ) : ?>
									<span class="starmus-card__type"><?php echo esc_html( $recording_type[0]->name ); ?></span>
								<?php endif; ?>
								<?php if ( $language && ! is_wp_error( $language ) ) : ?>
									<span class="starmus-card__language"><?php echo esc_html( $language[0]->name ); ?></span>
								<?php endif; ?>
							</div>
						</div>

						<?php if ( $audio_url ) : ?>
							<div class="starmus-card__audio">
								<audio controls preload="metadata" class="starmus-audio-player">
									<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/webm">
									<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/mp4">
									<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/mpeg">
									<?php esc_html_e( 'Your browser does not support the audio element.', STARMUS_TEXT_DOMAIN ); ?>
								</audio>
								<?php if ( $duration ) : ?>
									<span class="starmus-card__duration"><?php echo esc_html( gmdate( 'i:s', $duration ) ); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<div class="starmus-card__actions">
							<a href="<?php the_permalink(); ?>" class="starmus-btn starmus-btn--outline">
								<?php esc_html_e( 'View Details', STARMUS_TEXT_DOMAIN ); ?>
							</a>
							<?php if ( ! empty( $edit_page_url ) && current_user_can( 'edit_post', $current_post_id ) ) : ?>
								<?php
								$edit_link        = add_query_arg( 'post_id', $current_post_id, $edit_page_url );
								$secure_edit_link = wp_nonce_url( $edit_link, 'starmus_edit_audio', 'nonce' );
								?>
								<a href="<?php echo esc_url( $secure_edit_link ); ?>" class="starmus-btn starmus-btn--primary">
									<?php esc_html_e( 'Edit Audio', STARMUS_TEXT_DOMAIN ); ?>
								</a>
							<?php endif; ?>
						</div>
					</div>

				<?php endwhile; ?>
			</div><!-- .starmus-recordings-grid -->
		</section>

		<?php
		// Pagination with accessibility improvements
		the_posts_pagination(
			array(
				'mid_size'           => 2,
				'prev_text'          => esc_html__( 'Previous', STARMUS_TEXT_DOMAIN ),
				'next_text'          => esc_html__( 'Next', STARMUS_TEXT_DOMAIN ),
				'screen_reader_text' => esc_html__( 'Recordings navigation', STARMUS_TEXT_DOMAIN ),
				'aria_label'         => esc_html__( 'Recordings', STARMUS_TEXT_DOMAIN ),
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
