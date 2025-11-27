<?php

/**
 * Admin detail template showing all associated audio files, waveform, and metadata.
 *
 * STARMUS CORE STANDARD: Runtime detail views MUST use get_post_meta() exclusively.
 * - get_field() is FORBIDDEN in telemetry/read-only contexts (ACF overhead)
 * - get_post_meta() is core-cached, zero-overhead, fastest in production
 * - Detail views are telemetry surfaces, not edit UIs — performance matters
 *
 * WCAG 2.2 AA COMPLIANCE REQUIRED:
 * - Semantic HTML only (<main>, <section>, <figure>, <table> etc.)
 * - All inputs have <label for="id"> and matching id=""
 * - Buttons use <button>, links use <a href>
 * - Keyboard navigable (TAB reaches all controls, SPACE/ENTER triggers)
 * - ARIA live regions for dynamic status changes
 * - 4.5:1 minimum color contrast
 * - Metadata displayed in accessible <table> with proper headers
 *
 * @package Starisian\Starmus
 * @phpstan-ignore-next-line
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

// === All Submission Metadata (post_meta only - zero ACF overhead) ===
$submission_ip = get_post_meta($post_id, 'submission_ip', true);
$device_fingerprint = get_post_meta($post_id, 'device_fingerprint', true);
$environment_data = get_post_meta($post_id, 'environment_data', true);
$waveform_json = get_post_meta($post_id, 'waveform_json', true);
$processing_log = get_post_meta($post_id, 'processing_log', true);
$user_agent = get_post_meta($post_id, 'user_agent', true);
$mic_profile = get_post_meta($post_id, 'mic_profile', true);
$runtime_metadata = get_post_meta($post_id, 'runtime_metadata', true);
$weba_file = get_post_meta($post_id, 'weba_file', true);
$mastered_mp3 = get_post_meta($post_id, 'mastered_mp3', true);
$archival_wav = get_post_meta($post_id, 'archival_wav', true);

// === Attachment-level metadata (backward compatibility) ===
$archival_mp3_meta = get_post_meta($audio_attachment_id, '_starmus_mp3_path', true);
$archival_wav_meta = get_post_meta($audio_attachment_id, '_starmus_archival_path', true);
$waveform_meta     = get_post_meta($audio_attachment_id, '_waveform_data', true);

// === Waveform data resolution (post_meta priority chain) ===
$waveform_data = [];
if (! empty($waveform_json)) {
	$decoded = is_string($waveform_json) ? json_decode($waveform_json, true) : $waveform_json;
	$waveform_data = is_array($decoded) ? $decoded : [];
} elseif (! empty($waveform_meta)) {
	$waveform_data = is_array($waveform_meta) ? $waveform_meta : [];
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

// Resolve URLs (post_meta → attachment meta fallback)
$wav_url = starmus_fs_to_url($archival_wav ?: $archival_wav_meta, $uploads);
$mp3_url = starmus_fs_to_url($archival_mp3_meta, $uploads);

// Calculate waveform stats for accessible description
$waveform_duration = '';
$waveform_sample_count = 0;
if (! empty($waveform_data) && is_array($waveform_data)) {
	$waveform_sample_count = count($waveform_data);
	// Estimate duration assuming 44.1kHz sample rate
	$duration_seconds = $waveform_sample_count / 44100;
	$waveform_duration = sprintf('%d:%02d', floor($duration_seconds / 60), $duration_seconds % 60);
}
?>

<main class="starmus-admin-detail" id="starmus-recording-detail">
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
			<h3><?php esc_html_e('Original Recording', 'starmus-audio-recorder'); ?></h3>
			<audio controls preload="metadata" style="width:100%;" aria-label="<?php esc_attr_e('Original audio recording player', 'starmus-audio-recorder'); ?>">
				<source src="<?php echo esc_url($weba_file ?: $audio_url); ?>">
				<p><?php esc_html_e('Your browser does not support the audio element. Download the file using the links below.', 'starmus-audio-recorder'); ?></p>
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
				<?php if ($archival_wav || $wav_url) : ?>
					<li><strong>Archival WAV:</strong>
						<a href="<?php echo esc_url($archival_wav ?: $wav_url); ?>" download><?php echo esc_html(basename($archival_wav ?: $wav_url)); ?></a>
						<audio controls preload="metadata" style="width:100%;margin-top:0.3rem;">
							<source src="<?php echo esc_url($archival_wav ?: $wav_url); ?>" type="audio/wav">
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
			<h2><?php esc_html_e('Waveform Visualization', 'starmus-audio-recorder'); ?></h2>
			<figure class="starmus-waveform">
				<svg viewBox="0 0 <?php echo esc_attr((string) $width); ?> <?php echo esc_attr((string) $height); ?>" width="100%" height="<?php echo esc_attr((string) $height); ?>" role="img" aria-labelledby="waveform-title waveform-desc">
					<title id="waveform-title"><?php esc_html_e('Audio waveform visualization', 'starmus-audio-recorder'); ?></title>
					<desc id="waveform-desc"><?php echo esc_html(sprintf(__('Waveform visualization showing %s audio samples. Estimated duration: %s', 'starmus-audio-recorder'), number_format($waveform_sample_count), $waveform_duration)); ?></desc>
					<polyline fill="none" stroke="#0073aa" stroke-width="1" points="<?php echo esc_attr(implode(' ', $points)); ?>" />
				</svg>
				<figcaption class="screen-reader-text">
					<?php echo esc_html(sprintf(__('Waveform graph displaying %s audio samples with estimated duration of %s. Visual representation of audio amplitude over time.', 'starmus-audio-recorder'), number_format($waveform_sample_count), $waveform_duration)); ?>
				</figcaption>
			</figure>
		</section>
	<?php endif; ?>

	<div class="starmus-admin-detail__grid">
		<!-- Left Column: Metadata -->
		<div class="starmus-detail__left">
			<section class="starmus-detail__section starmus-glass">
				<h2><?php esc_html_e('Recording Metadata', 'starmus-audio-recorder'); ?></h2>
				<table class="starmus-info-table">
					<caption class="screen-reader-text"><?php esc_html_e('Recording session metadata including date, time, location, and equipment details', 'starmus-audio-recorder'); ?></caption>
					<tbody>
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
								echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . wp_kses_post(nl2br($val)) . '</td></tr>';
							}
						}
						?>
					</tbody>
				</table>
			</section>
			<section class="starmus-detail__section starmus-glass">
				<h2><?php esc_html_e('Submission Data', 'starmus-audio-recorder'); ?></h2>
				<table class="starmus-info-table">
					<caption class="screen-reader-text"><?php esc_html_e('Submission metadata including IP address, user agent, device information, and environment data', 'starmus-audio-recorder'); ?></caption>
					<tbody>
						<?php
						if ($submission_ip) {
							echo '<tr><th scope="row">' . esc_html__('IP Address', 'starmus-audio-recorder') . '</th><td>' . esc_html($submission_ip) . '</td></tr>';
						}

						if ($user_agent) {
							echo '<tr><th scope="row">' . esc_html__('User Agent', 'starmus-audio-recorder') . '</th><td><code>' . esc_html($user_agent) . '</code></td></tr>';
						}

						if ($mic_profile) {
							echo '<tr><th scope="row">' . esc_html__('Microphone Profile', 'starmus-audio-recorder') . '</th><td>' . esc_html($mic_profile) . '</td></tr>';
						}					// Device fingerprint
						if ($device_fingerprint) {
							$device_data = is_string($device_fingerprint) ? json_decode($device_fingerprint, true) : $device_fingerprint;
							if (is_array($device_data)) {
								echo '<tr><th scope="row">' . esc_html__('Device Information', 'starmus-audio-recorder') . '</th><td>';
								echo '<table class="starmus-nested-table"><caption class="screen-reader-text">' . esc_html__('Device fingerprint details', 'starmus-audio-recorder') . '</caption><tbody>';
								foreach ($device_data as $key => $value) {
									echo '<tr><th scope="row">' . esc_html(ucwords(str_replace('_', ' ', $key))) . '</th><td>' . esc_html($value) . '</td></tr>';
								}
								echo '</tbody></table></td></tr>';
							} else {
								echo '<tr><th scope="row">' . esc_html__('Device Fingerprint', 'starmus-audio-recorder') . '</th><td>' . esc_html($device_fingerprint) . '</td></tr>';
							}
						}					// Environment data
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
			<?php endif; ?>

			<?php if ($transcript_json) : ?>
				<section class="starmus-detail__section starmus-glass">
					<h2><?php esc_html_e('Transcription', 'starmus-audio-recorder'); ?></h2>
					<?php
					$transcript_data = is_string($transcript_json) ? json_decode($transcript_json, true) : $transcript_json;
					if (is_array($transcript_data)) :
						if (isset($transcript_data['text'])) :
					?>
							<div class="starmus-transcription-text">
								<p><?php echo esc_html($transcript_data['text']); ?></p>
							</div>
						<?php
						endif;
						if (isset($transcript_data['confidence'])) :
						?>
							<p class="starmus-transcription-meta">
								<small><?php echo esc_html(sprintf(__('Confidence: %s%%', 'starmus-audio-recorder'), round($transcript_data['confidence'] * 100, 2))); ?></small>
							</p>
						<?php
						endif;
					else :
						?>
						<details class="starmus-accordion">
							<summary class="starmus-accordion__summary"><?php esc_html_e('Show Transcription JSON', 'starmus-audio-recorder'); ?></summary>
							<div class="starmus-accordion__content">
								<pre class="starmus-json-data"><code><?php echo esc_html($transcript_json); ?></code></pre>
							</div>
						</details>
					<?php
					endif;
					?>
				</section>
			<?php endif; ?>

			<?php if ($metadata_json) : ?>
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

			<?php if ($runtime_metadata) : ?>
				<section class="starmus-detail__section starmus-glass">
					<h2><?php esc_html_e('Runtime Metadata', 'starmus-audio-recorder'); ?></h2>
					<details class="starmus-accordion">
						<summary class="starmus-accordion__summary"><?php esc_html_e('Show Runtime Data', 'starmus-audio-recorder'); ?></summary>
						<div class="starmus-accordion__content">
							<pre class="starmus-json-data"><code><?php echo esc_html(is_string($runtime_metadata) ? $runtime_metadata : wp_json_encode($runtime_metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></code></pre>
						</div>
					</details>
				</section>
			<?php endif; ?>
		</div>

		<!-- Right Column: Actions -->
		<div class="starmus-detail__right">
			<section class="starmus-detail__section starmus-glass starmus-detail__actions-card">
				<h2><?php esc_html_e('Actions', 'starmus-audio-recorder'); ?></h2>
				<nav class="starmus-action-buttons" aria-label="<?php esc_attr_e('Recording actions', 'starmus-audio-recorder'); ?>">
					<?php if (current_user_can('edit_post', $post_id)) : ?>
						<a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" class="starmus-btn starmus-btn--primary" aria-label="<?php echo esc_attr(sprintf(__('Edit recording: %s', 'starmus-audio-recorder'), get_the_title($post_id))); ?>">
							<?php esc_html_e('Edit Post', 'starmus-audio-recorder'); ?>
						</a>
					<?php endif; ?>
				</nav>
			</section>

			<?php if ($recorder_page_url) : ?>
				<section class="starmus-detail__section starmus-glass">
					<h2><?php esc_html_e('Re-Record Audio', 'starmus-audio-recorder'); ?></h2>
					<p class="starmus-section-description"><?php esc_html_e('Replace this recording with a new one', 'starmus-audio-recorder'); ?></p>
					<a href="<?php echo esc_url(add_query_arg('recording_id', $post_id, $recorder_page_url)); ?>" class="starmus-btn starmus-btn--secondary" aria-label="<?php echo esc_attr(sprintf(__('Re-record audio for: %s', 'starmus-audio-recorder'), get_the_title($post_id))); ?>">
						<?php esc_html_e('Go to Re-Recorder', 'starmus-audio-recorder'); ?>
					</a>
				</section>
			<?php endif; ?>

			<?php if ($edit_page_url) : ?>
				<section class="starmus-detail__section starmus-glass">
					<h2><?php esc_html_e('Audio Editor', 'starmus-audio-recorder'); ?></h2>
					<p class="starmus-section-description"><?php esc_html_e('Edit annotations and waveform segments', 'starmus-audio-recorder'); ?></p>
					<a href="<?php echo esc_url(add_query_arg('recording_id', $post_id, $edit_page_url)); ?>" class="starmus-btn starmus-btn--secondary" aria-label="<?php echo esc_attr(sprintf(__('Open audio editor for: %s', 'starmus-audio-recorder'), get_the_title($post_id))); ?>">
						<?php esc_html_e('Open in Editor', 'starmus-audio-recorder'); ?>
					</a>
				</section>
			<?php endif; ?>
		</div>
	</div>
</main>