<?php if ($query->have_posts()) : ?>

	<section aria-labelledby="starmus-recordings-heading">
		<h2 id="starmus-recordings-heading" class="starmus-visually-hidden">
			<?php esc_html_e('Your audio recordings', 'starmus-audio-recorder'); ?></h2>

		<div class="starmus-recordings-grid">
			<?php
			while ($query->have_posts()) :
				$query->the_post();
				$current_post_id = get_the_ID();
				$post_title      = get_the_title();

				// --- CORRECTED LOGIC START ---
				// 1. Get the Attachment ID (this is what you save in the handler).
				$audio_attachment_id = get_post_meta($current_post_id, '_audio_attachment_id', true);
				$audio_url           = ''; // Default to empty

				// 2. If an ID exists, get the URL from it.
				if (! empty($audio_attachment_id)) {
					$audio_url = wp_get_attachment_url((int) $audio_attachment_id);
				}
				// --- CORRECTED LOGIC END ---

				$recording_type = get_the_terms($current_post_id, 'recording_type');
				$language       = get_the_terms($current_post_id, 'language');
				$duration       = get_post_meta($current_post_id, 'audio_duration', true);
			?>

				<div class="starmus-card sparxstar-glass-card">
					<div class="starmus-card__header">
						<h3 class="starmus-card__title">
							<a href="<?php the_permalink(); ?>" class="starmus-card__link">
								<?php echo esc_html($post_title); ?>
							</a>
						</h3>
						<div class="starmus-card__meta">
							<span class="starmus-card__date">
								<time datetime="<?php echo esc_attr(get_the_date('c')); ?>">
									<?php echo esc_html(get_the_date('M j, Y')); ?>
								</time>
							</span>
							<?php if ($recording_type && ! is_wp_error($recording_type)) : ?>
								<span class="starmus-card__type"><?php echo esc_html($recording_type[0]->name); ?></span>
							<?php endif; ?>
							<?php if ($language && ! is_wp_error($language)) : ?>
								<span class="starmus-card__language"><?php echo esc_html($language[0]->name); ?></span>
							<?php endif; ?>
						</div>
					</div>

					<?php if ($audio_url) : ?>
						<div class="starmus-card__audio">
							<audio controls preload="metadata" class="starmus-audio-player">
								<source src="<?php echo esc_url($audio_url); ?>" type="audio/webm">
								<source src="<?php echo esc_url($audio_url); ?>" type="audio/mp4">
								<source src="<?php echo esc_url($audio_url); ?>" type="audio/mpeg">
								<?php esc_html_e('Your browser does not support the audio element.', 'starmus-audio-recorder'); ?>
							</audio>
							<?php if ($duration) : ?>
								<span class="starmus-card__duration"><?php echo esc_html(gmdate('i:s', $duration)); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<div class="starmus-card__actions">
						<a href="<?php echo esc_url(get_permalink($current_post_id)); ?>" class="starmus-btn starmus-btn--outline">
							<?php esc_html_e('View Details', 'starmus-audio-recorder'); ?>
						</a>
						<?php if (! empty($edit_page_url) && current_user_can('edit_post', $current_post_id)) : ?>
							<?php
							$edit_link        = add_query_arg('post_id', $current_post_id, $edit_page_url);
							$secure_edit_link = wp_nonce_url($edit_link, 'starmus_edit_audio_' . $current_post_id, 'nonce');
							?>
							<a href="<?php echo esc_url($secure_edit_link); ?>" class="starmus-btn starmus-btn--primary">
								<?php esc_html_e('Edit Audio', 'starmus-audio-recorder'); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>

			<?php endwhile; ?>
		</div><!-- .starmus-recordings-grid -->
	</section>

	<?php
	the_posts_pagination( /* ... */);
	?>

<?php else : ?>

	<div class="starmus-no-recordings">
		<p><?php esc_html_e('You have not submitted any recordings yet.', 'starmus-audio-recorder'); ?></p>
	</div>

<?php endif; ?>

<?php
wp_reset_postdata();
?>