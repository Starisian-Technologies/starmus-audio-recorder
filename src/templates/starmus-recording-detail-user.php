<?php

/**
 * Starmus Single Recording Detail Template Part (User View)
 *
 * @package Starisian\Sparxstar\Starmus\templates\parts
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

// Assume the global $post is set by the shortcode's context.
$post_id = get_the_ID();

// Get the audio URL correctly
$audio_attachment_id = get_post_meta($post_id, '_audio_attachment_id', true);
$audio_url           = empty($audio_attachment_id) ? '' : wp_get_attachment_url((int) $audio_attachment_id);

// Get other necessary data
$recording_type  = get_the_terms($post_id, 'recording_type');
$language        = get_the_terms($post_id, 'language');
$transcript      = get_post_meta($post_id, 'first_pass_transcription', true);
$metadata        = get_post_meta($post_id, 'recording_metadata', true);
$transcript_data = $transcript ? json_decode($transcript, true) : null;
$meta_data       = $metadata ? json_decode($metadata, true) : null;
?>

<div class="starmus-recording-detail">
	<header class="starmus-detail__header">
		<div class="starmus-detail__meta">
			<span class="starmus-meta__date">
				<?php esc_html_e('Recorded on', 'starmus-audio-recorder'); ?>
				<time datetime="<?php echo esc_attr(get_the_date('c', $post_id)); ?>">
					<?php echo esc_html(get_the_date('F j, Y \a\t g:i A', $post_id)); ?>
				</time>
			</span>
			<?php if ($recording_type && ! is_wp_error($recording_type)) { ?>
				<span class="starmus-meta__type">
					<?php esc_html_e('Type:', 'starmus-audio-recorder'); ?>
					<strong><?php echo esc_html($recording_type[0]->name); ?></strong>
				</span>
			<?php } ?>
			<?php if ($language && ! is_wp_error($language)) { ?>
				<span class="starmus-meta__language">
					<?php esc_html_e('Language:', 'starmus-audio-recorder'); ?>
					<strong><?php echo esc_html($language[0]->name); ?></strong>
				</span>
			<?php } ?>
		</div>
	</header>

	<?php if ($audio_url) { ?>
		<div class="starmus-detail__audio">
			<h2><?php esc_html_e('Your Recording', 'starmus-audio-recorder'); ?></h2>
			<audio controls controlsList="nodownload" preload="metadata" class="starmus-audio-player--large"
				style="width: 100%;">
				<source src="<?php echo esc_url($audio_url); ?>" type="audio/webm">
				<?php esc_html_e('Your browser does not support the audio element.', 'starmus-audio-recorder'); ?>
			</audio>
			<?php if ($meta_data && isset($meta_data['technical']['duration'])) { ?>
				<p class="starmus-audio__duration">
					<?php esc_html_e('Duration:', 'starmus-audio-recorder'); ?>
					<?php echo esc_html(gmdate('i:s', $meta_data['technical']['duration'] / 1000)); ?>
				</p>
			<?php } ?>

			<?php
            // Waveform preview (if generated)
            if ($audio_attachment_id) {
                $waveform_peaks = get_post_meta((int) $audio_attachment_id, '_waveform_data', true);
                if (! empty($waveform_peaks) && is_array($waveform_peaks)) {
                    $width      = 900; // viewbox width (CSS will scale)
                    $height     = 96;
                    $count      = count($waveform_peaks);
                    $max_points = 600; // limit for performance
                    $step       = max(1, (int) floor($count / $max_points));
                    $abs_vals   = array_map(abs(...), $waveform_peaks);
                    $max_val    = $abs_vals === [] ? 1 : max($abs_vals);
                    if ($max_val <= 0) {
                        $max_val = 1;
                    }
                    $points = [];
                    for ($i = 0; $i < $count; $i += $step) {
                        $v    = (float) $waveform_peaks[$i];
                        $norm = $v / $max_val; // roughly -1..1
                        $x    = ($i / max(1, $count - 1)) * $width;
                        // center the waveform vertically
                        $y        = ($height / 2) - ($norm * ($height / 2));
                        $points[] = $x . ',' . $y;
                    }
                    $points_str = implode(' ', $points);
                    ?>
					<div class="starmus-detail__waveform" style="margin-top:1rem;">
						<h3><?php esc_html_e('Waveform', 'starmus-audio-recorder'); ?></h3>
						<svg viewBox="0 0 <?php echo esc_attr((string) $width); ?> <?php echo esc_attr((string) $height); ?>" preserveAspectRatio="none" width="100%" height="<?php echo esc_attr((string) $height); ?>" aria-hidden="false" role="img" aria-label="<?php esc_attr_e('Waveform preview of the recording', 'starmus-audio-recorder'); ?>">
							<rect width="100%" height="100%" fill="transparent" />
							<polyline points="<?php echo esc_attr($points_str); ?>" fill="none" stroke="#2b8cff" stroke-width="1.25" stroke-linejoin="round" stroke-linecap="round" />
							<line x1="0" y1="<?php echo esc_attr((string) ($height / 2)); ?>" x2="<?php echo esc_attr((string) $width); ?>" y2="<?php echo esc_attr((string) ($height / 2)); ?>" stroke="#e6eef9" stroke-width="0.5" />
						</svg>
						<details style="margin-top:.5rem;">
							<summary><?php esc_html_e('Show raw waveform data', 'starmus-audio-recorder'); ?></summary>
							<pre class="starmus-raw-json"><code><?php echo esc_html(json_encode($waveform_peaks, JSON_PRETTY_PRINT)); ?></code></pre>
						</details>
					</div>
			<?php
                }
            }
	    ?>
		</div>
	<?php } else { ?>
		<div class="starmus-submission-error">
			<p><strong>Audio file could not be found for this submission.</strong></p>
		</div>
	<?php } ?>

	<?php if ($transcript_data && ! empty($transcript_data['transcript'])) { ?>
		<div class="starmus-detail__transcript">
			<h2><?php esc_html_e('Automatic Transcription', 'starmus-audio-recorder'); ?></h2>
			<p class="starmus-transcript__note">
				<?php esc_html_e('This is an automatic transcription and may contain errors.', 'starmus-audio-recorder'); ?>
			</p>
			<div class="starmus-transcript__content">
				<?php foreach ($transcript_data['transcript'] as $segment) { ?>
					<span class="starmus-transcript__segment"
						data-timestamp="<?php echo esc_attr($segment['timestamp'] ?? 0); ?>">
						<?php echo esc_html($segment['text'] ?? ''); ?>
					</span>
				<?php } ?>
			</div>
		</div>
	<?php } ?>

	<div class="starmus-detail__status">
		<h2><?php esc_html_e('Submission Status', 'starmus-audio-recorder'); ?></h2>
		<div class="starmus-status-card">
			<div class="starmus-status__icon">✓</div>
			<div class="starmus-status__content">
				<h3><?php esc_html_e('Successfully Submitted', 'starmus-audio-recorder'); ?></h3>
				<p><?php esc_html_e('Your recording has been received and is being processed for the linguistic corpus.', 'starmus-audio-recorder'); ?>
				</p>
			</div>
		</div>
	</div>

	<div class="starmus-detail__actions">
		<?php
        $archive_url = get_post_type_archive_link('audio-recording');
if ($archive_url) {
    ?>
			<a href="<?php echo esc_url($archive_url); ?>" class="starmus-btn starmus-btn--outline">
				<?php esc_html_e('← Back to My Recordings', 'starmus-audio-recorder'); ?>
			</a>
		<?php } ?>
	</div>
</div>