<?php

/**
 * Admin Detail Template (starmus-recording-detail-admin.php) - FINAL ROBUST VERSION
 *
 * FIXED: Prioritizes parsing telemetry from the saved JSON blobs
 * (environment_data, runtime_metadata) for reliable display.
 *
 * @package Starisian\Starmus
 */

if (! defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\services\StarmusFileService;

// === 1. INITIALIZATION & DATA RESOLUTION ===

try {
    $post_id = get_the_ID();

    if (! $post_id && isset($args['post_id'])) {
        $post_id = intval($args['post_id']);
    }

    if (! $post_id) {
        throw new \Exception('No post ID found.');
    }

    $settings = new StarmusSettings();

    // Safe Service Instantiation
    $file_service = class_exists(\Starisian\Sparxstar\Starmus\services\StarmusFileService::class)
    ? new StarmusFileService()
    : null;

    // --- 1. Audio Assets (New Schema) ---
    // ACF fields return URLs when return_format is 'url', but we need attachment IDs
    $mastered_mp3_field    = get_field('mastered_mp3', $post_id);
    $archival_wav_field    = get_field('archival_wav', $post_id);
    $original_source_field = get_field('original_source', $post_id);

    // If ACF returns URLs, we need to get attachment IDs from URLs
    if (is_string($mastered_mp3_field) && ($mastered_mp3_field !== '' && $mastered_mp3_field !== '0')) {
        $mastered_mp3_id = attachment_url_to_postid($mastered_mp3_field);
    } else {
        $mastered_mp3_id = (int) $mastered_mp3_field;
    }

    if (is_string($archival_wav_field) && ($archival_wav_field !== '' && $archival_wav_field !== '0')) {
        $archival_wav_id = attachment_url_to_postid($archival_wav_field);
    } else {
        $archival_wav_id = (int) $archival_wav_field;
    }

    if (is_string($original_source_field) && ($original_source_field !== '' && $original_source_field !== '0')) {
        $original_id = attachment_url_to_postid($original_source_field);
    } else {
        $original_id = (int) $original_source_field;
    }

    // Fallback to legacy fields if new schema fields are empty
    if ($original_id === 0) {
        $original_id = (int) get_post_meta($post_id, '_audio_attachment_id', true);
    }
    if ($mastered_mp3_id === 0) {
        $mastered_mp3_id = (int) get_post_meta($post_id, '_audio_mp3_attachment_id', true);
    }
    if ($archival_wav_id === 0) {
        $archival_wav_id = (int) get_post_meta($post_id, '_audio_wav_attachment_id', true);
    }

    $get_url = function (int $att_id) use ($file_service) {
        if ($att_id <= 0) {
            return '';
        }
        try {
            if ($file_service instanceof \Starisian\Sparxstar\Starmus\services\StarmusFileService) {
                return $file_service->star_get_public_url($att_id);
            }
        } catch (\Throwable) {
        }
        return wp_get_attachment_url($att_id) ?: '';
    };

    $mp3_url      = $get_url($mastered_mp3_id);
    $wav_url      = $get_url($archival_wav_id);
    $original_url = $get_url($original_id); // Assuming _audio_attachment_id is covered by audio_files_originals
    $playback_url = $mp3_url ?: $original_url;

    // --- 2. Telemetry & Logs ---
    $processing_log = get_post_meta($post_id, 'starmus_processing_log', true);
    $runtime_raw    = get_post_meta($post_id, 'runtime_metadata', true);

    // --- 3. Robust Data Parsing (New Schema) ---
    $env_json_raw = get_field('environment_data', $post_id);
    $env_data     = empty($env_json_raw) ? [] : json_decode($env_json_raw, true);

    // FIX: Parse IDs correctly from the flat structure we built in JS
    $visitor_id = $env_data['identifiers']['visitorId'] ?? 'N/A';
    $session_id = $env_data['identifiers']['sessionId'] ?? 'N/A';

    $fingerprint_display = sprintf('Session: %s | Visitor: %s', $session_id, $visitor_id);

    $submission_ip_display = get_field('contributor_ip', $post_id) ?: ($env_data['identifiers']['ipAddress'] ?? 'Unknown');

    // FIX: Mic Profile Location (New Schema)
    // The JSON shows {"gain":1,"speechLevel":100} inside transcriber field
    $mic_data_raw        = get_field('transcriber', $post_id);
    $mic_data            = json_decode($mic_data_raw, true);
    $mic_profile_display = isset($mic_data['gain']) ? 'Gain: ' . $mic_data['gain'] : 'N/A';

    // CRITICAL FIX: Parse Browser/OS from User Agent string if structured data missing
    $ua_string = $env_data['device']['userAgent'] ?? '';

    // Simple parser for display
    if ($ua_string) {
        $browser = 'Unknown';
        if (str_contains((string) $ua_string, 'Chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains((string) $ua_string, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains((string) $ua_string, 'Safari')) {
            $browser = 'Safari';
        }

        $os = 'Unknown';
        if (str_contains((string) $ua_string, 'Android')) {
            $os = 'Android';
        } elseif (str_contains((string) $ua_string, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains((string) $ua_string, 'Mac')) {
            $os = 'MacOS';
        } elseif (str_contains((string) $ua_string, 'Linux')) {
            $os = 'Linux';
        } elseif (str_contains((string) $ua_string, 'CrOS')) {
            $os = 'ChromeOS';
        }

        $user_agent_display = sprintf('%s on %s', $browser, $os);
    } else {
        $user_agent_display = 'N/A';
    }

    // Parse Transcript
    $transcript_raw  = get_field('first_pass_transcription', $post_id);
    $transcript_text = '';
    if (! empty($transcript_raw)) {
        $decoded         = is_string($transcript_raw) ? json_decode($transcript_raw, true) : $transcript_raw;
        $transcript_text = is_array($decoded) && isset($decoded['transcript']) ? $decoded['transcript'] : $transcript_raw;
    }

    // --- 4. Standard Metadata (New Schema) ---
    $accession_number = get_field('starmus_accession_number', $post_id);
    $location_data    = get_field('starmus_session_location', $post_id);
    $project_id       = get_field('starmus_project_collection_id', $post_id);

    $languages = get_the_terms($post_id, 'starmus_tax_language');
    $rec_types = get_the_terms($post_id, 'recording_type');

    // --- 5. URLs ---
    $edit_page_slug     = $settings->get('edit_page_id', '');
    $recorder_page_slug = $settings->get('recorder_page_id', '');
    $edit_page_url      = $edit_page_slug ? get_permalink(get_page_by_path($edit_page_slug)) : '';
    $recorder_page_url  = $recorder_page_slug ? get_permalink(get_page_by_path($recorder_page_slug)) : '';
} catch (\Throwable $throwable) {
    echo '<div class="starmus-alert starmus-alert--error"><p>Error: ' . esc_html($throwable->getMessage()) . '</p></div>';
    return;
}
?>

<main class="starmus-admin-detail" id="starmus-detail-<?php echo esc_attr((string) $post_id); ?>">

	<!-- Header -->
	<header class="starmus-detail__header">
		<h1>
			<span class="screen-reader-text"><?php esc_html_e('Recording Title:', 'starmus-audio-recorder'); ?></span>
			<?php echo esc_html(get_the_title($post_id)); ?>
		</h1>

		<div class="starmus-detail__meta-badges">
			<span class="starmus-badge"><?php echo intval($post_id); ?></span>
			<span class="starmus-badge"><?php echo esc_html(get_the_date('F j, Y g:i A', $post_id)); ?></span>
			<?php if (! empty($languages) && ! is_wp_error($languages)) { ?>
				<span class="starmus-badge"><?php echo esc_html($languages[0]->name); ?></span>
			<?php } ?>
			<?php if (! empty($rec_types) && ! is_wp_error($recording_types)) { ?>
				<span class="starmus-badge"><?php echo esc_html($recording_types[0]->name); ?></span>
			<?php } ?>
		</div>
	</header>

	<!-- Audio Player & Assets -->
	<section class="starmus-detail__section sparxstar-glass-card">
		<h2 id="starmus-audio-heading"><?php esc_html_e('Audio Assets', 'starmus-audio-recorder'); ?></h2>

		<?php if ($playback_url) { ?>
			<figure class="starmus-player-wrap" style="margin-bottom: 20px;">
				<audio controls preload="metadata" style="width: 100%;" class="starmus-audio-full">
					<source src="<?php echo esc_url($playback_url); ?>" type="<?php echo str_contains($playback_url, '.mp3') ? 'audio/mpeg' : 'audio/webm'; ?>">
        <?php esc_html_e('Browser does not support audio.', 'starmus-audio-recorder'); ?>
				</audio>
			</figure>
		<?php } else { ?>
			<div class="starmus-alert starmus-alert--warning">
				<p><?php esc_html_e('No audio files attached.', 'starmus-audio-recorder'); ?></p>
			</div>
		<?php } ?>

		<table class="starmus-info-table">
			<thead>
				<tr>
					<th><?php esc_html_e('Asset Type', 'starmus-audio-recorder'); ?></th>
					<th><?php esc_html_e('Status', 'starmus-audio-recorder'); ?></th>
					<th><?php esc_html_e('Action', 'starmus-audio-recorder'); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong>Mastered MP3</strong></td>
					<td><?php echo $mastered_mp3_id !== 0 ? '<span style="color:var(--starmus-success);">✔ Available</span>' : '<span style="color:var(--starmus-warning);">Processing...</span>'; ?></td>
					<td>
						<?php if ($mp3_url) { ?>
							<a href="<?php echo esc_url($mp3_url); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding:4px 8px;font-size:0.8em;">Download</a>
						<?php } ?>
					</td>
				</tr>
				<tr>
					<td><strong>Archival WAV</strong></td>
					<td><?php echo $archival_wav_id !== 0 ? '<span style="color:var(--starmus-success);">✔ Available</span>' : '<span style="color:var(--starmus-text-muted);">Not generated</span>'; ?></td>
					<td>
						<?php if ($wav_url) { ?>
							<a href="<?php echo esc_url($wav_url); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding:4px 8px;font-size:0.8em;">Download</a>
						<?php } ?>
					</td>
				</tr>
				<tr>
					<td><strong>Original Source</strong></td>
					<td><?php echo ($original_id !== 0) ? '<span style="color:var(--starmus-success);">✔ Available</span>' : '<span style="color:var(--starmus-danger);">MISSING</span>'; ?></td>
					<td>
						<?php if ($original_url) { ?>
							<a href="<?php echo esc_url($original_url); ?>" target="_blank" download class="starmus-btn starmus-btn--outline" style="padding:4px 8px;font-size:0.8em;">Download</a>
						<?php } ?>
					</td>
				</tr>
			</tbody>
		</table>
	</section>

	<!-- Waveform -->
	<?php
    if (! empty($waveform_data)) {
        $width   = 800;
        $height  = 100;
        $count   = count($waveform_data);
        $step    = max(1, floor($count / 800));
        $points  = [];
        $max_val = max(array_map(abs(...), $waveform_data)) ?: 1;
        for ($i = 0; $i < $count; $i += $step) {
            $val      = (float) $waveform_data[$i];
            $x        = ($i / $count) * $width;
            $y        = $height - (($val / $max_val) * $height);
            $points[] = sprintf('%s,%s', $x, $y);
        }
        ?>
		<section class="starmus-detail__section sparxstar-glass-card">
			<h2><?php esc_html_e('Waveform Data', 'starmus-audio-recorder'); ?></h2>
			<figure class="starmus-waveform-container" style="background:#f0f0f1; border:1px solid #ddd; padding:10px; border-radius: 8px;">
				<svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" preserveAspectRatio="none" width="100%" height="<?php echo $height; ?>">
					<polyline fill="none" stroke="#2271b1" stroke-width="1" points="<?php echo esc_attr(implode(' ', $points)); ?>" />
				</svg>
			</figure>
		</section>
    <?php } ?>

	<div class="starmus-grid-layout">

		<!-- LEFT: Metadata -->
		<div class="starmus-col-main">

			<!-- Transcription -->
			<section class="starmus-detail__section sparxstar-glass-card">
				<h2><?php esc_html_e('Transcription', 'starmus-audio-recorder'); ?></h2>
				<?php if ($transcript_text) { ?>
					<div class="starmus-transcript-box" style="background:#f9f9f9; padding:15px; border:1px solid #eee; border-radius:4px; max-height:200px; overflow-y:auto;">
        <?php echo wp_kses_post(nl2br($transcript_text)); ?>
					</div>
				<?php } else { ?>
					<p class="description">No transcription data available.</p>
				<?php } ?>
			</section>

			<!-- Environment Data (Parsed) -->
			<section class="starmus-detail__section sparxstar-glass-card">
				<h2><?php esc_html_e('Environment & Device', 'starmus-audio-recorder'); ?></h2>
				<table class="starmus-info-table">
					<tbody>
						<tr>
							<th>IP Address</th>
							<td><code><?php echo esc_html($submission_ip_display); ?></code></td>
						</tr>
						<tr>
							<th>Fingerprint</th>
							<td><code><?php echo esc_html($fingerprint_display); ?></code></td>
						</tr>
						<tr>
							<th>Browser/OS</th>
							<td><?php echo esc_html($user_agent_display); ?></td>
						</tr>
						<tr>
							<th>Mic Profile</th>
							<td><?php echo esc_html($mic_profile_display); ?></td>
						</tr>
						<?php if (! empty($runtime_raw)) { ?>
							<tr>
								<th>Raw Runtime</th>
								<td>
									<details>
										<summary>View JSON</summary>
										<pre style="font-size:0.8em; white-space:pre-wrap;"><?php echo esc_html($runtime_raw); ?></pre>
									</details>
								</td>
							</tr>
						<?php } ?>
						<?php if (! empty($env_json_raw)) { ?>
							<tr>
								<th>Raw Environment</th>
								<td>
									<details>
										<summary>View JSON</summary>
										<pre style="font-size:0.8em; white-space:pre-wrap;"><?php echo esc_html($env_json_raw); ?></pre>
									</details>
								</td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</section>

			<!-- Logs -->
			<?php if ($processing_log) { ?>
				<section class="starmus-detail__section sparxstar-glass-card">
					<details class="starmus-logs">
						<summary style="cursor: pointer; font-weight: 600; color: var(--starmus-primary);">View Technical Processing Log</summary>
						<div style="margin-top: 10px;">
							<pre class="starmus-processing-log" style="max-height: 300px; overflow-y: auto;"><?php echo esc_html($processing_log); ?></pre>
						</div>
					</details>
				</section>
			<?php } ?>

		</div>

		<!-- RIGHT: Admin Actions -->
		<aside class="starmus-col-sidebar">
			<section class="starmus-detail__section sparxstar-glass-card">
				<h2><?php esc_html_e('Admin Actions', 'starmus-audio-recorder'); ?></h2>
				<div class="starmus-btn-stack">
					<?php if (current_user_can('edit_post', $post_id)) { ?>
						<a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" class="starmus-btn starmus-btn--primary" style="justify-content:center;">Edit Metadata</a>
					<?php } ?>
					<?php if ($recorder_page_url) { ?>
						<a href="<?php echo esc_url(add_query_arg('recording_id', $post_id, $recorder_page_url)); ?>" class="starmus-btn starmus-btn--outline" style="justify-content:center;">Re-Record</a>
					<?php } ?>
					<?php if ($edit_page_url) { ?>
						<a href="<?php echo esc_url(add_query_arg('recording_id', $post_id, $edit_page_url)); ?>" class="starmus-btn starmus-btn--outline" style="justify-content:center;">Open Editor</a>
					<?php } ?>
				</div>
			</section>

			<!-- Archive Info -->
			<section class="starmus-detail__section sparxstar-glass-card">
				<h3>Archive Info</h3>
				<ul style="list-style:none; padding:0; margin:0; font-size:0.9em;">
					<li><strong>Collection ID:</strong> <?php echo esc_html($project_id ?: '-'); ?></li>
					<li><strong>Accession #:</strong> <?php echo esc_html($accession_number ?: '-'); ?></li>
					<li><strong>Location:</strong> <?php echo esc_html($location_data ?: '-'); ?></li>
				</ul>
			</section>
		</aside>

	</div>

</main>
