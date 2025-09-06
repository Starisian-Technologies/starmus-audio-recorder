<?php
/**
 * Starmus My Recordings List Template
 *
 * This template is responsible for displaying the list of a user's audio recordings.
 *
 * @package Starmus\templates
 * @version 0.4.0
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

	<?php if ( $query->have_posts() ) : ?>

		<div class="starmus-recordings-grid">
			<?php
			// The WordPress Loop
			while ( $query->have_posts() ) :
				$query->the_post();

				// Get the post ID for use in links and checks.
				$current_post_id = get_the_ID();
				?>

				<?php if ( 0 === $query->current_post ) : // This is the first (most recent) post. ?>

					<!-- Featured Recording (Full Width) -->
					<article id="post-<?php echo esc_attr( strval( $current_post_id ) ); ?>" class="starmus-recording starmus-recording--featured">
						<header class="starmus-recording__header">
							<h2 class="starmus-recording__title">
								<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							</h2>
							<span class="starmus-recording__meta"><?php echo esc_html( get_the_date() ); ?></span>
						</header>
						<div class="starmus-recording__content">
							<?php
								// WordPress will automatically find the [audio] shortcode in the content
								// and render the default audio player.
								the_content();
							?>
						</div>
						<footer class="starmus-recording__footer">
							<?php
							// Only show the "Edit Audio" button if the user has permission to edit this specific post.
							if ( ! empty( $edit_page_url ) && current_user_can( 'edit_post', $current_post_id ) ) :
								// Create the base link to the editor page.
								$edit_link = add_query_arg( 'post_id', $current_post_id, $edit_page_url );

								// *** THIS IS THE FIX ***
								// Add the security nonce to the URL. This prevents CSRF attacks.
								// The action 'starmus_edit_audio' MUST match what your Editor class is checking for.
								$secure_edit_link = wp_nonce_url( $edit_link, 'starmus_edit_audio', 'nonce' );
								?>
								<a href="<?php echo esc_url( $secure_edit_link ); ?>" class="starmus-recording__edit-button">
									<?php esc_html_e( 'Edit Audio', STARMUS_TEXT_DOMAIN ); ?>
								</a>
							<?php endif; ?>
						</footer>
					</article>

				<?php else : // This is for all other recordings. ?>

					<!-- Standard Grid Item -->
					<article id="post-<?php echo esc_attr( strval( $current_post_id ) ); ?>" class="starmus-recording">
						<header class="starmus-recording__header">
							<h3 class="starmus-recording__title">
								<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							</h3>
							<span class="starmus-recording__meta"><?php echo esc_html( get_the_date() ); ?></span>
						</header>
						<div class="starmus-recording__content">
							<?php the_content(); ?>
						</div>
						<footer class="starmus-recording__footer">
							<?php
							if ( ! empty( $edit_page_url ) && current_user_can( 'edit_post', $current_post_id ) ) :
								// Create the base link to the editor page.
								$edit_link = add_query_arg( 'post_id', $current_post_id, $edit_page_url );

								// *** THIS IS THE FIX ***
								// Add the security nonce to the URL. This prevents CSRF attacks.
								// The action 'starmus_edit_audio' MUST match what your Editor class is checking for.
								$secure_edit_link = wp_nonce_url( $edit_link, 'starmus_edit_audio', 'nonce' );
								?>
								<a href="<?php echo esc_url( $secure_edit_link ); ?>" class="starmus-recording__edit-button">
									<?php esc_html_e( 'Edit Audio', STARMUS_TEXT_DOMAIN ); ?>
								</a>
							<?php endif; ?>
						</footer>
					</article>

				<?php endif; ?>

			<?php endwhile; ?>
		</div><!-- .starmus-recordings-grid -->

		<?php
		// Display pagination links if there are multiple pages of recordings.
		the_posts_pagination(
			array(
				'mid_size'  => 2,
				'prev_text' => esc_html__( 'Previous', STARMUS_TEXT_DOMAIN ),
				'next_text' => esc_html__( 'Next', STARMUS_TEXT_DOMAIN ),
			)
		);
		?>

	<?php else : ?>

		<!-- Message to display if the user has no recordings. -->
		<div class="starmus-no-recordings">
			<p><?php esc_html_e( 'You have not submitted any recordings yet.', STARMUS_TEXT_DOMAIN ); ?></p>
		</div>

	<?php endif; ?>

	<?php
	// Restore original Post Data.
	wp_reset_postdata();
	?>
</div><!-- .starmus-my-recordings-container -->
