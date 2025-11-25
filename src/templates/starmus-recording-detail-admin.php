<?php

/**
 * Admin detail template showing all associated audio files, waveform, and metadata.
 *
 * @package Starisian\Starmus
 */

use Starisian\Sparxstar\Starmus\core\StarmusSettings;

$post_id = get_the_ID();
$uploads = wp_get_upload_dir();
$settings = new StarmusSettings();

// Get page URLs for actions
$edit_page_slug = $settings->get('edit_page_id', '');
$recorder_page_slug = $settings->get('recorder_page_id', '');
$edit_page_url = $edit_page_slug ? get_permalink(get_page_by_path($edit_page_slug)) : '';
$recorder_page_url = $recorder_page_slug ? get_permalink(get_page_by_path($recorder_page_slug)) : '';

$audio_attachment_id = (int) get_post_meta($post_id, '_audio_attachment_id', true);
$audio_url           = $audio_attachment_id !== 0 ? wp_get_attachment_url($audio_attachment_id) : '';

$language        = get_the_terms($post_id, 'language');
$type            = get_the_terms($post_id, 'recording-type');
$transcript_json = get_post_meta($post_id, 'first_pass_transcription', true);
$metadata_json   = get_post_meta($post_id, 'recording_metadata', true);

// === New ACF Fields ===
$waveform_json      = get_field('waveform_json', $post_id);
$weba_file          = get_field('weba_file', $post_id);
$mastered_mp3       = get_field('mastered_mp3', $post_id);
$archival_wav_acf   = get_field('archival_wav', $post_id);
$processing_log     = get_field('processing_log', $post_id);
$device_fingerprint = get_field('device_fingerprint', $post_id);
$environment_data   = get_field('environment_data', $post_id);

// === Backward compatibility ===
$archival_mp3_meta = get_post_meta($audio_attachment_id, '_starmus_mp3_path', true);
$archival_wav_meta = get_post_meta($audio_attachment_id, '_starmus_archival_path', true);
$waveform_meta     = get_post_meta($audio_attachment_id, '_waveform_data', true);

$waveform_data = [];
if (! empty($waveform_json)) {
	$waveform_data = json_decode($waveform_json, true);
} elseif (! empty($waveform_meta)) {
	$waveform_data = $waveform_meta;
}

// === Utility functions ===
function starmus_local_time(int $post_id): string
{
	return get_date_from_gmt(get_post_time('Y-m-d H:i:s', true, $post_id), 'F j, Y \a\t g:i A');
}

function starmus_language_label(string $lang_code): string
{
	$map = [
		'en'    => 'English',
		'en-US' => 'English (US)',
		'mnk'   => 'Mandinka',
		'wo'    => 'Wolof',
		'fr'    => 'French',
	];
	return $map[$lang_code] ?? strtoupper($lang_code);
}

function starmus_fs_to_url(string $path, array $uploads): string
{
	if ($path === '' || $path === '0') {
		return '';
	}
	$real = realpath($path);
	$base = realpath($uploads['basedir']);
	return ($real && $base && str_starts_with($real, $base))
		? str_replace($uploads['basedir'], $uploads['baseurl'], $path)
		: '';
}

// Resolve URLs
$wav_url = starmus_fs_to_url($archival_wav_acf ?: $archival_wav_meta, $uploads);
$mp3_url = starmus_fs_to_url($archival_mp3_meta, $uploads);
?>

<article class="starmus-admin-detail">
	<header class="starmus-detail__header">
		<h1><?php echo esc_html(get_the_title($post_id)); ?></h1>
		<div class="starmus-detail__meta">
			<span><strong>Recorded:</strong> <?php echo esc_html(starmus_local_time($post_id)); ?></span>
			<?php if ($language && ! is_wp_error($language)) : ?>
				<span><strong>Language:</strong> <?php echo esc_html(starmus_language_label($language[0]->name)); ?></span>
			<?php endif; ?>
			<?php if ($type && ! is_wp_error($type)) : ?>
				<span><strong>Type:</strong> <?php echo esc_html($type[0]->name); ?></span>
			<?php endif; ?>
		</div>
	</header>

	<section class="starmus-detail__section">
		<h2><?php esc_html_e('Audio Files', 'starmus-audio-recorder'); ?></h2>
		<?php if ($weba_file || $audio_url) : ?>
			<p><strong>Original Recording:</strong></p>
			<audio controls preload="metadata" style="width:100%;">
				<source src="<?php echo esc_url($weba_file ?: $audio_url); ?>">
			</audio>

			<ul class="starmus-file-list">
				<?php if ($weba_file) : ?>
					<li><strong>Original Source (WebA):</strong>
						<a href="<?php echo esc_url($weba_file); ?>" target="_blank"><?php echo esc_html(basename($weba_file)); ?></a>
					</li>
				<?php endif; ?>
				<?php if ($mastered_mp3 || $mp3_url) : ?>
					<li><strong>Mastered MP3:</strong>
						<a href="<?php echo esc_url($mastered_mp3 ?: $mp3_url); ?>" download><?php echo esc_html(basename($mastered_mp3 ?: $mp3_url)); ?></a>
						<audio controls preload="metadata" style="width:100%;margin-top:0.3rem;">
							<source src="<?php echo esc_url($mastered_mp3 ?: $mp3_url); ?>" type="audio/mpeg">
						</audio>
					</li>
				<?php endif; ?>
				<?php if ($archival_wav_acf || $wav_url) : ?>
					<li><strong>Archival WAV:</strong>
						<a href="<?php echo esc_url($archival_wav_acf ?: $wav_url); ?>" download><?php echo esc_html(basename($archival_wav_acf ?: $wav_url)); ?></a>
						<audio controls preload="metadata" style="width:100%;margin-top:0.3rem;">
							<source src="<?php echo esc_url($archival_wav_acf ?: $wav_url); ?>" type="audio/wav">
						</audio>
					</li>
				<?php endif; ?>
			</ul>
		<?php else : ?>
			<p><em>No audio files available.</em></p>
		<?php endif; ?>
	</section>

	<?php
	if (! empty($waveform_data) && is_array($waveform_data)) :
		$width      = 800;
		$height     = 120;
		$count      = count($waveform_data);
		$max_points = 600;
		$step       = max(1, floor($count / $max_points));
		$max_val    = max(array_map(abs(...), $waveform_data));
		if ($max_val <= 0) {
			$max_val = 1;
		}
		$points = [];
		for ($i = 0; $i < $count; $i += $step) {
			$v        = (float) $waveform_data[$i];
			$x        = ($i / max(1, $count - 1)) * $width;
			$y        = $height - (($v / $max_val) * $height);
			$points[] = sprintf('%s,%s', $x, $y);
		}
	?>
		<section class="starmus-detail__section">
			<h2>Waveform Visualization</h2>
			<div class="starmus-waveform">
				<svg viewBox="0 0 <?php echo esc_attr((string) $width); ?> <?php echo esc_attr((string) $height); ?>" width="100%" height="<?php echo esc_attr((string) $height); ?>" role="img" aria-label="Waveform preview">
					<polyline fill="none" stroke="#0073aa" stroke-width="1" points="<?php echo esc_attr(implode(' ', $points)); ?>" />
				</svg>
			</div>
		</section>
	<?php endif; ?>

	<div class="starmus-admin-detail__grid">
		<!-- Left Column: Metadata -->
		<div class="starmus-detail__left">
			<section class="starmus-detail__section starmus-glass">
				<h2><?php esc_html_e('Recording Metadata', 'starmus-audio-recorder'); ?></h2>
				<dl class="starmus-info-list">
					<?php
					$session_fields = [
						'session_date'          => 'Session Date',
						'session_start_time'    => 'Start Time',
						'session_end_time'      => 'End Time',
						'duration'              => 'Duration',
						'location'              => 'Location',
						'recording_equipment'   => 'Recording Equipment',
						'media_condition_notes' => 'Media Condition Notes',
					];
					foreach ($session_fields as $k => $label) {
						$val = get_post_meta($post_id, $k, true);
						if (! empty($val)) {
							echo '<dt>' . esc_html($label) . '</dt><dd>' . wp_kses_post(nl2br($val)) . '</dd>';
						}
					}
					?>
				</dl>
			</section>

			<section class="starmus-detail__section starmus-glass">
				<h2><?php esc_html_e('Submission Data', 'starmus-audio-recorder'); ?></h2>
				<dl class="starmus-info-list">
					<?php
					$submission_ip = get_post_meta($post_id, 'submission_ip', true);
					if ($submission_ip) {
						echo '<dt>' . esc_html__('IP Address', 'starmus-audio-recorder') . '</dt><dd>' . esc_html($submission_ip) . '</dd>';
					}

					// Device fingerprint
					if ($device_fingerprint) {
						$device_data = is_string($device_fingerprint) ? json_decode($device_fingerprint, true) : $device_fingerprint;
						if (is_array($device_data)) {
							echo '<dt>' . esc_html__('Device Information', 'starmus-audio-recorder') . '</dt><dd>';
							echo '<ul class="starmus-nested-list">';
							foreach ($device_data as $key => $value) {
								echo '<li><strong>' . esc_html(ucwords(str_replace('_', ' ', $key))) . ':</strong> ' . esc_html($value) . '</li>';
							}
							echo '</ul></dd>';
						} else {
							echo '<dt>' . esc_html__('Device Fingerprint', 'starmus-audio-recorder') . '</dt><dd>' . esc_html($device_fingerprint) . '</dd>';
						}
					}

					// Environment data
					if ($environment_data) {
						$env_data = is_string($environment_data) ? json_decode($environment_data, true) : $environment_data;
						if (is_array($env_data)) {
							echo '<dt>' . esc_html__('Environment Data', 'starmus-audio-recorder') . '</dt><dd>';
							echo '<ul class="starmus-nested-list">';
							foreach ($env_data as $key => $value) {
								if (is_array($value)) {
									echo '<li><strong>' . esc_html(ucwords(str_replace('_', ' ', $key))) . ':</strong> ' . esc_html(wp_json_encode($value)) . '</li>';
								} else {
									echo '<li><strong>' . esc_html(ucwords(str_replace('_', ' ', $key))) . ':</strong> ' . esc_html($value) . '</li>';
								}
							}
							echo '</ul></dd>';
						} else {
							echo '<dt>' . esc_html__('Environment Data', 'starmus-audio-recorder') . '</dt><dd>' . esc_html($environment_data) . '</dd>';
						}
					}
					?>
				</dl>
			</section>

			<?php if ($processing_log) : ?>
				<section class="starmus-detail__section starmus-glass">
					<h2><?php esc_html_e('Processing Log', 'starmus-audio-recorder'); ?></h2>
					<details class="starmus-accordion">
						<summary class="starmus-accordion__summary"><?php esc_html_e('View Processing Log', 'starmus-audio-recorder'); ?></summary>
						<div class="starmus-accordion__content">
							<pre class="starmus-processing-log"><code><?php echo esc_html($processing_log); ?></code></pre>
						</div>
					</details>
				</section>
			<?php endif; ?> <?php if ($metadata_json) : ?>
				<section class="starmus-detail__section starmus-glass">
					<h2><?php esc_html_e('Raw Recording Metadata', 'starmus-audio-recorder'); ?></h2>
					<details class="starmus-accordion">
						<summary class="starmus-accordion__summary"><?php esc_html_e('Show JSON', 'starmus-audio-recorder'); ?></summary>
						<div class="starmus-accordion__content">
							<pre class="starmus-json-data"><code><?php echo esc_html(json_encode(json_decode($metadata_json), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></code></pre>
						</div>
					</details>
				</section>
			<?php endif; ?>

			<?php if ($transcript_json) : ?>
				<section class="starmus-detail__section starmus-glass">
					<h2><?php esc_html_e('Transcription Data', 'starmus-audio-recorder'); ?></h2>
					<details class="starmus-accordion">
						<summary class="starmus-accordion__summary"><?php esc_html_e('Show JSON', 'starmus-audio-recorder'); ?></summary>
						<div class="starmus-accordion__content">
							<pre class="starmus-json-data"><code><?php echo esc_html(json_encode(json_decode($transcript_json), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></code></pre>
						</div>
					</details>
				</section>
			<?php endif; ?>
		</div>

		<!-- Right Column: Actions -->
		<div class="starmus-detail__right">
			<section class="starmus-detail__section starmus-glass starmus-detail__actions-card">
				<h2><?php esc_html_e('Actions', 'starmus-audio-recorder'); ?></h2>
				<div class="starmus-action-buttons">
					<?php if (current_user_can('edit_post', $post_id)) : ?>
						<a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" class="starmus-btn starmus-btn--primary">
							<?php esc_html_e('Edit Post', 'starmus-audio-recorder'); ?>
						</a>
					<?php endif; ?>
				</div>
			</section>

			<?php if ($recorder_page_url) : ?>
				<section class="starmus-detail__section starmus-glass">
					<h2><?php esc_html_e('Re-Record Audio', 'starmus-audio-recorder'); ?></h2>
					<p class="starmus-section-description"><?php esc_html_e('Replace this recording with a new one', 'starmus-audio-recorder'); ?></p>
					<a href="<?php echo esc_url(add_query_arg('post_id', $post_id, $recorder_page_url)); ?>" class="starmus-btn starmus-btn--secondary">
						<?php esc_html_e('Go to Re-Recorder', 'starmus-audio-recorder'); ?>
					</a>
				</section>
			<?php endif; ?>

			<?php if ($edit_page_url) : ?>
				<section class="starmus-detail__section starmus-glass">
					<h2><?php esc_html_e('Audio Editor', 'starmus-audio-recorder'); ?></h2>
					<p class="starmus-section-description"><?php esc_html_e('Edit annotations and waveform segments', 'starmus-audio-recorder'); ?></p>
					<a href="<?php echo esc_url(add_query_arg('post_id', $post_id, $edit_page_url)); ?>" class="starmus-btn starmus-btn--secondary">
						<?php esc_html_e('Open in Editor', 'starmus-audio-recorder'); ?>
					</a>
				</section>
			<?php endif; ?>
		</div>
	</div>
</article>