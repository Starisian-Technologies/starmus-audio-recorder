<?php

/**
 * Starmus Audio Editor UI - Complete Restoration
 *
 * @package Starisian\Sparxstar\Starmus\frontend
 * @version 1.3.0-COMPLETE
 */

namespace Starisian\Sparxstar\Starmus\frontend;

use Exception;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function file_exists;
use function is_numeric;
use function json_decode;
use const JSON_ERROR_NONE;
use function json_last_error;
use function json_last_error_msg;
use function realpath;
use function str_replace;
use function usort;

if (! \defined('ABSPATH')) {
    exit;
}

class StarmusAudioEditorUI
{
    /**
     * REST namespace for editor endpoints.
     */
    public const STARMUS_REST_NAMESPACE = 'star_uec/v1';

    /**
     * Upper bound for stored annotations.
     */
    public const STARMUS_MAX_ANNOTATIONS = 1000;

    /**
     * Time-based throttle.
     */
    public const STARMUS_RATE_LIMIT_SECONDS = 2;

    /**
     * Cached rendering context.
     */
    private ?array $cached_context = null;

    /**
     * Bootstrap the editor.
     */
    public function __construct()
    {
        // FIX: PHP 7.4 Array Callback
        add_action('init', [$this, 'register_hooks']);
    }

    /**
     * Register hooks.
     */
    public function register_hooks(): void
    {
        add_action('rest_api_init', [$this, 'register_rest_endpoint']);

        // Add body class
        add_filter('body_class', function ($classes) {
            global $post;
            if ($post && has_shortcode($post->post_content, 'starmus_audio_editor')) {
                $classes[] = 'starmus-page';
                $classes[] = 'starmus-editor-page';
            }
            return $classes;
        });
    }

    /**
     * Render the audio editor shortcode.
     */
    public function render_audio_editor_shortcode(array $atts = []): string
    {
        try {
            if (! is_user_logged_in()) {
                return '<p>' . esc_html__('You must be logged in to edit audio.', 'starmus-audio-recorder') . '</p>';
            }

            do_action('starmus_before_editor_render');

            $context = $this->get_editor_context($atts);

            if (is_wp_error($context)) {
                return '<div class="notice notice-error"><p>' . esc_html($context->get_error_message()) . '</p></div>';
            }

            return StarmusTemplateLoaderHelper::secure_render_template(
                'starmus-audio-editor-ui.php',
                ['context' => $context]
            );
        } catch (Throwable $throwable) {
            $this->log_error($throwable);
            return '<div class="notice notice-error"><p>' .
                esc_html__('Audio editor unavailable.', 'starmus-audio-recorder') .
                '</p></div>';
        }
    }

    /**
     * Legacy method for conditionally enqueueing editor assets.
     * Kept for backward compatibility.
     */
    public function enqueue_scripts(): void
    {
        // Logic handled by global AssetLoader.
        // Method retained to prevent fatal errors if external code calls it.
    }

    /**
     * Helper: Convert stored annotations JSON to array.
     */
    private function parse_annotations_json(string $json): array
    {
        try {
            if ($json === '' || $json === '0') {
                return [];
            }
            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(json_last_error_msg());
            }
            return \is_array($data) ? $data : [];
        } catch (Throwable $throwable) {
            $this->log_error($throwable);
            return [];
        }
    }

    /**
     * Centralized Permission Check (FIXED).
     */
    private function user_can_access($post): bool
    {
        if (!$post) return false;
        
        $user_id = get_current_user_id();
        
        // 1. Admin/Editor Override
        if (current_user_can('edit_others_posts')) return true;
        
        // 2. Author Check
        if ((int)$post->post_author === $user_id) return true;
        
        // 3. Capability Check
        if (current_user_can('starmus_edit_audio')) return true;
        
        // 4. Fallback CPT Check
        if (current_user_can('edit_post', $post->ID)) return true;

        return false;
    }

    /**
     * Build the editor rendering context.
     */
    private function get_editor_context(array $atts = []): array|WP_Error
    {
        try {
            if ($this->cached_context !== null) {
                return $this->cached_context;
            }

            // 1. Resolve ID
            $url_id       = absint($_GET['post_id'] ?? 0);
            $shortcode_id = isset($atts['post_id']) ? absint($atts['post_id']) : 0;
            $post_id      = $url_id ?: $shortcode_id;

            // 2. Security Check (URL only)
            if ($url_id > 0) {
                // Allow bypassing nonce if Admin (for testing)
                if (!current_user_can('manage_options')) {
                    $nonce = $_GET['nonce'] ?? $_GET['_wpnonce'] ?? '';
                    if (! $nonce || ! wp_verify_nonce(sanitize_text_field($nonce), 'starmus_edit_audio_' . $post_id)) {
                        return new WP_Error('invalid_nonce', __('Security check failed.', 'starmus-audio-recorder'));
                    }
                }
            }

            // 3. Fallback
            if (! $post_id) {
                // Return "Demo Mode" context if needed, or error
                return new WP_Error('no_id', __('No recording ID provided.', 'starmus-audio-recorder'));
            }

            // 4. Validate Post
            $post = get_post($post_id);
            if (! $post) {
                return new WP_Error('invalid_id', __('Invalid recording ID.', 'starmus-audio-recorder'));
            }

            // 5. Permissions (Using new robust check)
            if (! $this->user_can_access($post)) {
                return new WP_Error('permission_denied', __('Permission denied.', 'starmus-audio-recorder'));
            }

            // 6. Retrieve Audio
            $attachment_id = absint(get_post_meta($post_id, 'mastered_mp3', true));
            if (!$attachment_id) $attachment_id = absint(get_post_meta($post_id, 'audio_files_originals', true));
            if (!$attachment_id) $attachment_id = absint(get_post_meta($post_id, '_audio_attachment_id', true));

            if (! $attachment_id) {
                return new WP_Error('no_audio', __('No audio file found.', 'starmus-audio-recorder'));
            }

            // URL resolution (Cloudflare support)
            $audio_url = '';
            if (class_exists('\Starisian\Sparxstar\Starmus\services\StarmusFileService')) {
                $fs = new \Starisian\Sparxstar\Starmus\services\StarmusFileService();
                $audio_url = $fs->star_get_public_url($attachment_id);
            } else {
                $audio_url = wp_get_attachment_url($attachment_id);
            }

            if (! $audio_url) {
                return new WP_Error('no_audio_url', __('Audio URL unavailable.', 'starmus-audio-recorder'));
            }

            // 7. Metadata
            $waveform_url     = $this->get_secure_waveform_url($attachment_id);
            $annotations_json = get_post_meta($post_id, 'starmus_annotations_json', true);
            $transcript_json  = get_post_meta($post_id, 'first_pass_transcription', true);

            // Normalize Transcript
            $transcript_data = [];
            if($transcript_json) {
                $decoded = json_decode($transcript_json, true);
                if(json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $transcript_data = $decoded;
                } else {
                    $transcript_data = [['text' => $transcript_json]];
                }
            }

            $this->cached_context = [
                'post_id'          => $post_id,
                'attachment_id'    => $attachment_id,
                'audio_url'        => $audio_url,
                'waveform_url'     => $waveform_url,
                'annotations_json' => \is_string($annotations_json) ? $annotations_json : '[]',
                'transcript_data'  => $transcript_data
            ];

            return $this->cached_context;

        } catch (Throwable $throwable) {
            $this->log_error($throwable);
            return new WP_Error('context_error', __('Unable to load editor context.', 'starmus-audio-recorder'));
        }
    }

    /**
     * Generate a signed URL for the waveform attachment.
     */
    private function get_secure_waveform_url(int $attachment_id): string
    {
        $wave_json_path = get_post_meta($attachment_id, '_waveform_json_path', true);
        
        if (! \is_string($wave_json_path) || empty($wave_json_path) || ! file_exists($wave_json_path)) {
            return '';
        }

        $uploads = wp_get_upload_dir();
        if (strpos(realpath($wave_json_path), realpath($uploads['basedir'])) !== 0) {
            return '';
        }

        return str_replace($uploads['basedir'], $uploads['baseurl'], $wave_json_path);
    }

    /**
     * Register REST endpoint.
     */
    public function register_rest_endpoint(): void
    {
        register_rest_route(
            self::STARMUS_REST_NAMESPACE,
            '/annotations',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_save_annotations'],
                'permission_callback' => [$this, 'can_save_annotations'],
                'args'                => [
                    'postId' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => [$this, 'validate_post_id'],
                    ],
                    'annotations' => [
                        'required'          => true,
                        'sanitize_callback' => [$this, 'sanitize_annotations'],
                        'validate_callback' => [$this, 'validate_annotations'],
                    ],
                ],
            ]
        );
    }

    /**
     * Validate post ID.
     */
    public function validate_post_id($value): bool
    {
        return is_numeric($value) && $value > 0 && get_post(absint($value)) !== null;
    }

    /**
     * Sanitize annotations payload.
     */
    public function sanitize_annotations($value): array
    {
        try {
            if (! \is_array($value)) {
                return [];
            }

            $sanitized = [];
            foreach ($value as $annotation) {
                if (! \is_array($annotation)) {
                    continue;
                }

                $sanitized[] = [
                    'id'        => sanitize_key($annotation['id'] ?? ''),
                    'startTime' => \floatval($annotation['startTime'] ?? 0),
                    'endTime'   => \floatval($annotation['endTime'] ?? 0),
                    'labelText' => wp_kses_post($annotation['labelText'] ?? ''),
                    'color'     => sanitize_hex_color($annotation['color'] ?? '#000000'),
                ];
            }

            return $sanitized;
        } catch (Throwable $throwable) {
            $this->log_error($throwable);
            return [];
        }
    }

    /**
     * Validate structure of annotations.
     */
    public function validate_annotations($value): bool
    {
        if (! \is_array($value) || \count($value) > self::STARMUS_MAX_ANNOTATIONS) {
            return false;
        }

        foreach ($value as $annotation) {
            if (! \is_array($annotation)) return false;
            if (! isset($annotation['startTime'], $annotation['endTime'])) return false;

            $start = \floatval($annotation['startTime']);
            $end   = \floatval($annotation['endTime']);
            if ($start < 0 || $end < 0 || $start >= $end) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check permissions for REST save.
     */
    public function can_save_annotations(WP_REST_Request $request): bool
    {
        try {
            $nonce = $request->get_header('X-WP-Nonce');
            if (! $nonce || ! wp_verify_nonce($nonce, 'wp_rest')) {
                return false;
            }

            $post_id = absint($request->get_param('postId'));
            $post = get_post($post_id);
            
            // Use robust check
            return $this->user_can_access($post);

        } catch (Throwable $throwable) {
            $this->log_error($throwable);
            return false;
        }
    }

    /**
     * Handle saving annotations.
     */
    public function handle_save_annotations(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $post_id     = absint($request->get_param('postId'));
            $annotations = $request->get_param('annotations');

            if ($this->is_rate_limited($post_id)) {
                return new WP_REST_Response(
                    ['success' => false, 'message' => __('Too many requests.', 'starmus-audio-recorder')],
                    429
                );
            }

            $validation_result = $this->validate_annotation_consistency($annotations);
            if (is_wp_error($validation_result)) {
                return new WP_REST_Response(
                    ['success' => false, 'message' => $validation_result->get_error_message()],
                    400
                );
            }

            $json_data = wp_json_encode($annotations);
            if ($json_data === false) {
                return new WP_REST_Response(
                    ['success' => false, 'message' => 'Encoding failed.'],
                    500
                );
            }

            do_action('starmus_before_annotations_save', $post_id, $annotations);
            update_post_meta($post_id, 'starmus_annotations_json', $json_data);
            do_action('starmus_after_annotations_save', $post_id, $annotations);

            return new WP_REST_Response(
                [
                    'success' => true,
                    'message' => __('Annotations saved successfully.', 'starmus-audio-recorder'),
                    'count'   => \count($annotations),
                ],
                200
            );
        } catch (Throwable $throwable) {
            $this->log_error($throwable);
            return new WP_REST_Response(
                ['success' => false, 'message' => 'Internal server error.'],
                500
            );
        }
    }

    /**
     * Check rate limiting.
     */
    private function is_rate_limited(int $post_id): bool
    {
        $user_id = get_current_user_id();
        $key     = \sprintf('starmus_ann_rl_%s_%d', $user_id, $post_id);
        if (get_transient($key)) {
            return true;
        }

        set_transient($key, true, self::STARMUS_RATE_LIMIT_SECONDS);
        return false;
    }

    /**
     * Ensure annotation timestamps are sorted and non-overlapping.
     */
    private function validate_annotation_consistency(array $annotations): true|\WP_Error
    {
        if ($annotations === []) {
            return true;
        }

        usort(
            $annotations,
            fn(array $a, array $b): int => $a['startTime'] <=> $b['startTime']
        );
        for ($i = 0; $i < \count($annotations) - 1; $i++) {
            $current = $annotations[$i];
            $next    = $annotations[$i + 1];
            if ($current['endTime'] > $next['startTime']) {
                return new WP_Error('overlap_detected', __('Overlapping annotations detected.', 'starmus-audio-recorder'));
            }
        }

        return true;
    }

    /**
     * Public wrapper for context retrieval.
     */
    public function get_editor_context_public(array $atts = []): array|WP_Error
    {
        return $this->get_editor_context($atts);
    }

    /**
     * Log errors.
     */
    private function log_error(Throwable $e): void
    {
        if (\defined('WP_DEBUG') && WP_DEBUG && \defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('StarmusEditor: ' . $e->getMessage());
        }
    }
}