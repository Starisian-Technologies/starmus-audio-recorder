<?php

/**
 * The template for displaying a single Audio Recording.
 */
get_header(); ?>

<div id="primary" class="content-area">
	<main id="main" class="site-main">
		<?php
		while ( have_posts() ) {
			the_post();
			?>
			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
				<header class="entry-header">
					<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
				</header>

				<div class="entry-content">
					<?php
					$attachment_id = get_post_meta( get_the_ID(), '_audio_attachment_id', true );
					if ( $attachment_id ) {
						// wp_audio_shortcode is safe - it's a core WordPress function that outputs HTML
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo wp_audio_shortcode( array( 'src' => wp_get_attachment_url( $attachment_id ) ) );
					}
					the_content();
					?>
				</div>
			</article>
		<?php } ?>
	</main>
</div>

<?php get_sidebar(); ?>
<?php get_footer(); ?>