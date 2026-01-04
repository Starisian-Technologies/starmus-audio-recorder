<?php

/**
 * The template for displaying the "My Submissions" list.
 */
get_header(); ?>

<div id="primary" class="content-area">
	<main id="main" class="site-main">
		<header class="page-header">
			<h1 class="page-title"><?php post_type_archive_title(); ?></h1>
		</header>

		<?php if (have_posts()) { ?>
			<div class="starmus-submissions-list">
        <?php
        while (have_posts()) {
            the_post();
            ?>
					<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
						<h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
						<div class="entry-meta">
							<span>Recorded on: <?php echo esc_html(get_the_date()); ?></span>
						</div>
					</article>
        <?php } ?>
			</div>
        <?php the_posts_pagination(); ?>
		<?php } else { ?>
			<p><?php esc_html_e('You have not submitted any recordings yet.', 'starmus-audio-recorder'); ?></p>
		<?php } ?>
	</main>
</div>

<?php get_sidebar(); ?>
<?php get_footer(); ?>