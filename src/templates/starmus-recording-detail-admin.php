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
    $mastered_mp3_field = get_field('mastered_mp3', $post_id);
    $archival_wav_field = get_field('archival_wav', $post_id);
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

    $mp3_url = $get_url($mastered_mp3_id);
    $wav_url = $get_url($archival_wav_id);
    $original_url = $get_url($original_id); // Assuming _audio_attachment_id is covered by audio_files_originals
    $playback_url = $mp3_url ?: $original_url;

    // --- 2. Telemetry & Logs ---
    $processing_log = get_post_meta($post_id, 'starmus_processing_log', true);
    $runtime_raw = get_post_meta($post_id, 'runtime_metadata', true);

    // --- 3. Robust Data Parsing (New Schema) ---
    // OPTIMIZATION: Use get_post_meta() instead of get_field() for potentially massive JSON blobs.
    // get_field() applies formatting which can double/triple memory usage for large strings, causing OOM.
    $env_json_raw = get_post_meta($post_id, 'starmus_environment_data', true);

    // COMPLETE DATA BUNDLE: Load ALL raw JSON fields submitted from recorder
    $transcription_json_raw = get_post_meta($post_id, 'starmus_transcription_json', true);
    $recording_metadata_raw = get_post_meta($post_id, 'starmus_recording_metadata', true);
    $waveform_json_raw = get_post_meta($post_id, 'starmus_waveform_json', true);
    $transcriber_metadata_raw = get_post_meta($post_id, 'starmus_transcriber_metadata', true);

    // RAW SUBMISSION PAYLOAD: The complete form_data array as received from recorder
    $raw_submission_data = get_post_meta($post_id, 'starmus_raw_submission_data', true);
    $mapped_submission_data = get_post_meta($post_id, 'starmus_mapped_submission_data', true);

    // SAFETY: Truncate massively large JSON strings before processing or display to prevent memory exhaustion
    if (is_string($env_json_raw) && strlen($env_json_raw) > 50000) {
        // Just decode needed parts if possible, but for display, we must truncate
        // We keep the full string for json_decode but for template output variables we must be careful.
    }

    $env_data = empty($env_json_raw) ? [] : json_decode((string)$env_json_raw, true);

    // FIX: Parse IDs correctly from the flat structure we built in JS
    $visitor_id = $env_data['identifiers']['visitorId'] ?? 'N/A';
    $session_id = $env_data['identifiers']['sessionId'] ?? 'N/A';
    // FIX: Fingerprint is now at root of env data
    $fingerprint_val = $env_data['fingerprint'] ?? $env_data['identifiers']['visitorId'] ?? 'N/A';

    $fingerprint_display = sprintf('Session: %s | Fingerprint: %s', $session_id, $fingerprint_val);

    // Optimization: starmus_contributor_ip
    $submission_ip_display = get_post_meta($post_id, 'starmus_contributor_ip', true) ?: ($env_data['identifiers']['ipAddress'] ?? 'Unknown');

    // FIX: Mic Profile Location (New Schema)
    // The JSON shows {"gain":1,"speechLevel":100} inside transcriber field
    $mic_data_raw = get_post_meta($post_id, 'starmus_transcriber_metadata', true);
    $mic_data = json_decode((string)$mic_data_raw, true);

    $mic_profile_display = empty($mic_data) ? 'N/A' : (json_encode($mic_data) ?: 'Invalid Data');

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
    // OPTIMIZATION: get_post_meta for large text
    $transcript_raw = get_post_meta($post_id, 'starmus_transcription_text', true);
    $transcript_text = '';
    if (! empty($transcript_raw)) {
        $decoded = is_string($transcript_raw) ? json_decode($transcript_raw, true) : $transcript_raw;
        $transcript_text = is_array($decoded) && isset($decoded['transcript']) ? $decoded['transcript'] : $transcript_raw;
    }

    // Parse Waveform
    // OPTIMIZATION: get_post_meta for massive JSON
    $waveform_json_raw = get_post_meta($post_id, 'starmus_waveform_json', true);
    $waveform_data = ! empty($waveform_json_raw) ? json_decode((string)$waveform_json_raw, true) : [];
    // SAFETY: Free up massive raw strings immediately
    unset($waveform_json_raw);

    // --- 4. Standard Metadata (New Schema) ---
    $accession_number = get_field('starmus_accession_number', $post_id);
    $location_data = get_field('starmus_session_location', $post_id);
    $project_id = get_field('starmus_project_collection_id', $post_id);

    $languages = get_the_terms($post_id, 'starmus_tax_language');
    $rec_types = get_the_terms($post_id, 'recording-type');

    // --- 5. URLs ---
    $edit_page_ids = $settings->get('edit_page_id', []);
    $recorder_page_ids = $settings->get('recorder_page_id', []);

    // Normalize to single ID (first one if array)
    $edit_page_id = is_array($edit_page_ids) ? ($edit_page_ids[0] ?? 0) : (int) $edit_page_ids;
    $recorder_page_id = is_array($recorder_page_ids) ? ($recorder_page_ids[0] ?? 0) : (int) $recorder_page_ids;

    $edit_page_url = $edit_page_id > 0 ? get_permalink($edit_page_id) : '';
    $recorder_page_url = $recorder_page_id > 0 ? get_permalink($recorder_page_id) : '';
} catch (\Throwable $throwable) {
    echo '<div class="starmus-alert starmus-alert--error"><p>Error: ' . esc_html($throwable->getMessage()) . '</p></div>';
    return;
}
?>

<main class="starmus-admin-detail" id="starmus-detail-<?php echo esc_attr((string) $post_id); ?>">

    <!-- Header -->
    <header class="starmus-detail__header">
        <h2>
            <span class="screen-reader-text"><?php esc_html_e('Recording Title:', 'starmus-audio-recorder'); ?></span>
            <?php echo esc_html(get_the_title($post_id)); ?>
        </h2>

        <div class="starmus-detail__meta-badges">
            <span class="starmus-badge"><?php echo intval($post_id); ?></span>
            <span class="starmus-badge"><?php echo esc_html(get_the_date('F j, Y g:i A', $post_id)); ?></span>
            <?php if (! empty($languages) && ! is_wp_error($languages)) { ?>
                <span class="starmus-badge"><?php echo esc_html($languages[0]->name); ?></span>
            <?php } ?>
            <?php if (! empty($rec_types) && ! is_wp_error($rec_types)) { ?>
                <span class="starmus-badge"><?php echo esc_html($rec_types[0]->name); ?></span>
            <?php } ?>
        </div>
    </header>

    <!-- Audio Player & Assets -->
    <section class="starmus-detail__section sparxstar-glass-card">
        <h3 id="starmus-audio-heading"><?php esc_html_e('Audio Assets', 'starmus-audio-recorder'); ?></h3>

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
                    <th scope="col"><?php esc_html_e('Asset Type', 'starmus-audio-recorder'); ?></th>
                    <th scope="col"><?php esc_html_e('Status', 'starmus-audio-recorder'); ?></th>
                    <th scope="col"><?php esc_html_e('Action', 'starmus-audio-recorder'); ?></th>
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
        $width = 800;
        $height = 100;
        $count = count($waveform_data);

        // OPTIMIZATION: Downsample strictly to screen width
        $target_points = 800;
        $step = max(1, floor($count / $target_points));

        $points = [];
        // OPTIMIZATION: Find max value efficiently without creating new array
        $max_val = 1.0;
        foreach ($waveform_data as $v) {
            $abs = abs((float)$v);
            if ($abs > $max_val) {
                $max_val = $abs;
            }
        }

        for ($i = 0; $i < $count; $i += $step) {
            $val = (float) $waveform_data[$i];
            // Format numbers to reduce string size
            $x = number_format(($i / $count) * $width, 2, '.', '');
            $y = number_format($height - (($val / $max_val) * $height), 2, '.', '');
            $points[] = $x . ',' . $y;
        }

        // Free memory immediately
        unset($waveform_data);

        // Force GC to reclaim memory before continuing to next tasks
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    ?>
        <section class="starmus-detail__section sparxstar-glass-card">
            <h3><?php esc_html_e('Waveform Data', 'starmus-audio-recorder'); ?></h3>
            <figure class="starmus-waveform-container" style="background:#f0f0f1; border:1px solid #ddd; padding:10px; border-radius: 8px;">
                <svg role="img" aria-label="<?php esc_attr_e('Audio waveform visualization', 'starmus-audio-recorder'); ?>" viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" preserveAspectRatio="none" width="100%" height="<?php echo $height; ?>">
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
                <h3><?php esc_html_e('Transcription', 'starmus-audio-recorder'); ?></h3>
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
                <h3><?php esc_html_e('Environment & Device', 'starmus-audio-recorder'); ?></h3>
                <table class="starmus-info-table">
                    <tbody>
                        <tr>
                            <th scope="row">IP Address</th>
                            <td><code><?php echo esc_html($submission_ip_display); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row">Fingerprint</th>
                            <td><code><?php echo esc_html($fingerprint_display); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row">Browser/OS</th>
                            <td><?php echo esc_html($user_agent_display); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Mic Profile</th>
                            <td><?php echo esc_html($mic_profile_display); ?></td>
                        </tr>
                        <?php if (! empty($runtime_raw)) { ?>
                            <tr>
                                <th scope="row">Raw Runtime</th>
                                <td>
                                    <details>
                                        <summary>View Complete JSON (<?php echo number_format(strlen((string)$runtime_raw)); ?> bytes)</summary>
                                        <div style="max-height: 500px; overflow: auto; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; margin-top: 10px;">
                                            <pre style="font-size:0.75em; white-space:pre-wrap; word-wrap: break-word; margin: 0;"><?php
                                                                                                                                    // Pretty-print the full JSON for readability
                                                                                                                                    $decoded_runtime = json_decode((string)$runtime_raw);
                                                                                                                                    if ($decoded_runtime !== null) {
                                                                                                                                        echo esc_html(json_encode($decoded_runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                                                                                                                                    } else {
                                                                                                                                        echo esc_html($runtime_raw);
                                                                                                                                    }
                                                                                                                                    ?></pre>
                                        </div>
                                        <div style="margin-top: 10px; display: flex; gap: 8px;">
                                            <button type="button" class="button button-secondary" onclick="var d=<?php echo $runtime_raw; ?>;navigator.clipboard.writeText(JSON.stringify(d,null,2)).then(()=>alert('Copied!')).catch(()=>alert('Failed'))">📋 Copy JSON</button>
                                            <a href="data:application/json;charset=utf-8,<?php echo rawurlencode((string)$runtime_raw); ?>" download="runtime-metadata-<?php echo $post_id; ?>.json" class="button button-secondary" style="text-decoration:none;">💾 Download JSON</a>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php } ?>
                        <?php if (! empty($raw_submission_data)) { ?>
                            <tr>
                                <th scope="row">Complete Submission Payload</th>
                                <td>
                                    <details open>
                                        <summary><strong>📦 RAW FORM DATA (After POST, Before Mapper)</strong> (<?php echo number_format(strlen((string)$raw_submission_data)); ?> bytes)</summary>
                                        <div style="max-height: 600px; overflow: auto; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; margin-top: 10px; border: 2px solid #ffa500;">
                                            <pre style="font-size:0.75em; white-space:pre-wrap; word-wrap: break-word; margin: 0;"><?php
                                                                                                                                    // Display raw JSON with proper formatting
                                                                                                                                    $decoded_submission = json_decode((string)$raw_submission_data, true);
                                                                                                                                    if ($decoded_submission !== null && json_last_error() === JSON_ERROR_NONE) {
                                                                                                                                        echo esc_html(json_encode($decoded_submission, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                                                                                                                                    } else {
                                                                                                                                        echo esc_html($raw_submission_data);
                                                                                                                                    }
                                                                                                                                    ?></pre>
                                        </div>
                                        <div style="margin-top: 10px; display: flex; gap: 8px;">
                                            <button type="button" class="button button-primary" onclick="var d=<?php echo $raw_submission_data; ?>;navigator.clipboard.writeText(JSON.stringify(d,null,2)).then(()=>alert('Raw data copied!')).catch(()=>alert('Failed'))">📋 Copy Raw JSON</button>
                                            <a href="data:application/json;charset=utf-8,<?php echo rawurlencode((string)$raw_submission_data); ?>" download="raw-submission-<?php echo $post_id; ?>.json" class="button button-primary" style="text-decoration:none;">💾 Download Raw</a>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php } ?>
                        <?php if (! empty($mapped_submission_data)) { ?>
                            <tr>
                                <th scope="row">Mapped Submission Data</th>
                                <td>
                                    <details>
                                        <summary><strong>🗺️ AFTER SCHEMA MAPPER</strong> (<?php echo number_format(strlen((string)$mapped_submission_data)); ?> bytes)</summary>
                                        <div style="max-height: 600px; overflow: auto; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; margin-top: 10px; border: 2px solid #4CAF50;">
                                            <pre style="font-size:0.75em; white-space:pre-wrap; word-wrap: break-word; margin: 0;"><?php
                                                                                                                                    $decoded_mapped = json_decode((string)$mapped_submission_data, true);
                                                                                                                                    if ($decoded_mapped !== null && json_last_error() === JSON_ERROR_NONE) {
                                                                                                                                        echo esc_html(json_encode($decoded_mapped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                                                                                                                                    } else {
                                                                                                                                        echo esc_html($mapped_submission_data);
                                                                                                                                    }
                                                                                                                                    ?></pre>
                                        </div>
                                        <div style="margin-top: 10px; display: flex; gap: 8px;">
                                            <button type="button" class="button button-secondary" onclick="var d=<?php echo $mapped_submission_data; ?>;navigator.clipboard.writeText(JSON.stringify(d,null,2)).then(()=>alert('Mapped data copied!')).catch(()=>alert('Failed'))">📋 Copy Mapped</button>
                                            <a href="data:application/json;charset=utf-8,<?php echo rawurlencode((string)$mapped_submission_data); ?>" download="mapped-submission-<?php echo $post_id; ?>.json" class="button button-secondary" style="text-decoration:none;">💾 Download Mapped</a>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php } ?>
                        <?php if (! empty($env_json_raw)) { ?>
                            <tr>
                                <th scope="row">Raw Environment</th>
                                <td>
                                    <details>
                                        <summary>View Complete JSON (<?php echo number_format(strlen((string)$env_json_raw)); ?> bytes)</summary>
                                        <div style="max-height: 500px; overflow: auto; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; margin-top: 10px;">
                                            <pre style="font-size:0.75em; white-space:pre-wrap; word-wrap: break-word; margin: 0;"><?php
                                                                                                                                    // Pretty-print the full JSON for readability
                                                                                                                                    $decoded_env = json_decode((string)$env_json_raw);
                                                                                                                                    if ($decoded_env !== null) {
                                                                                                                                        echo esc_html(json_encode($decoded_env, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                                                                                                                                    } else {
                                                                                                                                        echo esc_html($env_json_raw);
                                                                                                                                    }
                                                                                                                                    ?></pre>
                                        </div>
                                        <div style="margin-top: 10px; display: flex; gap: 8px;">
                                            <button type="button" class="button button-secondary" onclick="var d=<?php echo $env_json_raw; ?>;navigator.clipboard.writeText(JSON.stringify(d,null,2)).then(()=>alert('Copied!')).catch(()=>alert('Failed'))">📋 Copy JSON</button>
                                            <a href="data:application/json;charset=utf-8,<?php echo rawurlencode((string)$env_json_raw); ?>" download="environment-data-<?php echo $post_id; ?>.json" class="button button-secondary" style="text-decoration:none;">💾 Download JSON</a>
                                        </div>
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
                        <summary style="cursor: pointer; font-weight: 600; color: var(--starmus-primary);">View Complete Technical Processing Log (<?php echo number_format(strlen((string)$processing_log)); ?> bytes)</summary>
                        <div style="margin-top: 10px;">
                            <div style="max-height: 500px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px;">
                                <pre class="starmus-processing-log" style="margin: 0; font-size:0.75em; white-space:pre-wrap; word-wrap: break-word;"><?php echo esc_html($processing_log); ?></pre>
                            </div>
                            <div style="margin-top: 10px; display: flex; gap: 8px;">
                                <button type="button" class="button button-secondary" onclick="var txt=<?php echo json_encode($processing_log); ?>;navigator.clipboard.writeText(txt).then(()=>alert('Log copied!')).catch(()=>alert('Failed'))">📋 Copy Log</button>
                                <a href="data:text/plain;charset=utf-8,<?php echo rawurlencode((string)$processing_log); ?>" download="processing-log-<?php echo $post_id; ?>.txt" class="button button-secondary" style="text-decoration:none;">💾 Download Log</a>
                            </div>
                        </div>
                    </details>
                </section>
            <?php } ?>

        </div>

        <!-- RIGHT: Admin Actions -->
        <aside class="starmus-col-sidebar">
            <!-- Archive Info -->
            <section class="starmus-detail__section sparxstar-glass-card">
                <h4>Archive Info</h4>
                <ul style="list-style:none; padding:0; margin:0; font-size:0.9em;">
                    <li><strong>Collection ID:</strong> <?php echo esc_html($project_id ?: '-'); ?></li>
                    <li><strong>Accession #:</strong> <?php echo esc_html($accession_number ?: '-'); ?></li>
                    <li><strong>Location:</strong> <?php echo esc_html($location_data ?: '-'); ?></li>
                </ul>
            </section>
            <section class="starmus-detail__section sparxstar-glass-card">
                <h4><?php esc_html_e('Admin Actions', 'starmus-audio-recorder'); ?></h4>
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


        </aside>

    </div>

</main>
