<?php

/**
 * Starmus Audio Editor UI - Full Logic Restoration & PHP 8.2+ Optimization
 *
 * This version restores all original helper and validation methods while
 * fixing the permission check for Administrators and Editors. It utilizes
 * modern PHP 8.2+ syntax as per project requirements.
 *
 * @package Starisian\Sparxstar\Starmus\frontend
 *
 * @version 2.0.0-ENTERPRISE
 */
namespace Starisian\Sparxstar\Starmus\frontend;

use Starisian\Sparxstar\Starmus\services\StarmusFileService;
use Exception;
use function file_exists;
use function is_numeric;
use function realpath;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;

use function str_replace;

use Throwable;

use function usort;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! \defined('ABSPATH')) {
    exit;
}

// RESTORING PHP 8.2+ SYNTAX
final class StarmusAudioEditorUI
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
     * RESTORING PHP 8.0+ Constructor Property Promotion (if you were using it)
     * For this example, I'll keep the explicit declaration for clarity.
     */
    public function __construct()
    {
        // RESTORING PHP 8.1+ First-class callable syntax
        add_action('init', $this->register_hooks(...));
    }

    /**
     * Register hooks.
     */
    public function register_hooks(): void
    {
        add_action('rest_api_init', $this->register_rest_endpoint(...));

        // Add body class
        add_filter(
            'body_class',
            function ($classes) {
                global $post;
                if ($post && has_shortcode($post->post_content, 'starmus_audio_editor')) {
                    $classes[] = 'starmus-page';
                    $classes[] = 'starmus-editor-page';
                }

                return $classes;
            }
        );
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
            STarmusLogger::log($throwable);
            return '<div class="notice notice-error"><p>' .
                esc_html__('Audio editor unavailable.', 'starmus-audio-recorder') .
                '</p></div>';
        }
    }

    /**
     * Legacy method for enqueuing assets.
     */
    public function enqueue_scripts(): void
    {
        // Logic handled by a dedicated AssetLoader class, method retained for compatibility.
    }

    /**
     * Centralized Permission Check (FIXED).
     */
    private function user_can_access(object $post): bool
    {
        // 1. Admin/Editor Override
        if (current_user_can('edit_others_posts')) {
            return true;
        }

        // 2. Author Check
        if ((int) $post->post_author === get_current_user_id()) {
            return true;
        }

        // 3. Custom Capability Check
        if (current_user_can('starmus_edit_audio')) {
            return true;
        }

        // 4. Fallback CPT Check
        return (bool) current_user_can('edit_post', $post->ID);
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

            $url_id       = absint($_GET['recording_id'] ?? $_GET['post_id'] ?? 0);
            $shortcode_id = isset($atts['post_id']) ? absint($atts['post_id']) : 0;
            $post_id      = $url_id ?: $shortcode_id;

            if (! $post_id) {
                return new WP_Error('no_id', __('No recording ID provided.', 'starmus-audio-recorder'));
            }

            $post = get_post($post_id);
            if (! $post) {
                return new WP_Error('invalid_id', __('Invalid recording ID.', 'starmus-audio-recorder'));
            }

            if (! $this->user_can_access($post)) {
                return new WP_Error('permission_denied', __('You do not have permission to edit this recording.', 'starmus-audio-recorder'));
            }

            // Security Check for URL-based access
            if ($url_id > 0 && ! current_user_can('manage_options')) {
                $nonce = $_GET['nonce'] ?? $_GET['_wpnonce'] ?? '';
                if (! $nonce || ! wp_verify_nonce(sanitize_text_field($nonce), 'starmus_edit_audio_' . $post_id)) {
                    return new WP_Error('invalid_nonce', __('Security check failed.', 'starmus-audio-recorder'));
                }
            }

            $attachment_id = absint(get_field('mastered_mp3', $post_id)) ?: absint(get_field('original_source', $post_id)) ?: absint(get_post_meta($post_id, '_audio_attachment_id', true));
            if (! $attachment_id) {
                return new WP_Error('no_audio', __('No audio file found for this recording.', 'starmus-audio-recorder'));
            }

            $audio_url = '';
            if (class_exists(StarmusFileService::class)) {
                $fs        = new StarmusFileService();
                $audio_url = $fs->star_get_public_url($attachment_id);
            } else {
                $audio_url = wp_get_attachment_url($attachment_id);
            }

            if (! $audio_url) {
                return new WP_Error('no_audio_url', __('Audio file URL is not available.', 'starmus-audio-recorder'));
            }

            $waveform_url     = $this->get_secure_waveform_url($attachment_id);
            $annotations_json = get_field('starmus_waveform_json', $post_id);
            $transcript_json  = get_field('first_pass_transcription', $post_id);

            $this->cached_context = [
                'post_id'               => $post_id,
                'attachment_id'         => $attachment_id,
                'audio_url'             => $audio_url,
                'waveform_url'          => $waveform_url,
                'annotations_json'      => \is_string($annotations_json) ? $annotations_json : '[]',
                'transcript_json'       => \is_string($transcript_json) ? $transcript_json : '',
                'starmus_waveform_json' => \is_string($annotations_json) ? $annotations_json : '[]',
                'transcript_data'       => \is_string($transcript_json) ? $transcript_json : '',
            ];

            return $this->cached_context;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return new WP_Error('context_error', __('Unable to load editor context.', 'starmus-audio-recorder'));
        }
    }

    private function get_secure_waveform_url(int $attachment_id): string
    {
        try {
            $wave_json_path = get_post_meta($attachment_id, '_waveform_json_path', true);
            if (! \is_string($wave_json_path) || ($wave_json_path === '' || $wave_json_path === '0') || ! file_exists($wave_json_path)) {
                return '';
            }

            $uploads = wp_get_upload_dir();
            if (! str_starts_with(realpath($wave_json_path), realpath($uploads['basedir']))) {
                return '';
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }

        return str_replace($uploads['basedir'], $uploads['baseurl'], $wave_json_path);
    }

    public function register_rest_endpoint(): void
    {
        register_rest_route(
            self::STARMUS_REST_NAMESPACE,
            '/annotations',
            [
                'methods'             => 'POST',
                'callback'            => $this->handle_save_annotations(...),
                'permission_callback' => $this->can_save_annotations(...),
                'args'                => [
                    'postId' => [
                        'required'          => true,
                        'validate_callback' => $this->validate_post_id(...),
                    ],
                    'annotations' => [
                        'required'          => true,
                        'validate_callback' => $this->validate_annotations(...),
                    ],
                ],
            ]
        );
    }

    public function validate_post_id(mixed $value): bool
    {
        return is_numeric($value) && $value > 0 && get_post(absint($value)) !== null;
    }

    public function sanitize_annotations(mixed $value): array
    {
        try {
            // Full sanitization logic restored
            if (! \is_array($value)) {
                return [];
            }

            $sanitized = [];
            foreach ($value as $a) {
                if (\is_array($a)) {
                    $sanitized[] = [
                        'id'        => sanitize_key($a['id'] ?? ''),
                        'startTime' => (float) ($a['startTime'] ?? 0),
                        'endTime'   => (float) ($a['endTime'] ?? 0),
                        'labelText' => wp_kses_post($a['labelText'] ?? ''),
                        'color'     => sanitize_hex_color($a['color'] ?? '#000000'),
                    ];
                }
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }

        return $sanitized;
    }

    public function validate_annotations(mixed $value): bool
    {
        try {
            // Full validation logic restored
            if (! \is_array($value) || \count($value) > self::STARMUS_MAX_ANNOTATIONS) {
                return false;
            }

            foreach ($value as $a) {
                if (! \is_array($a) || ! isset($a['startTime'], $a['endTime'])) {
                    return false;
                }

                if ($a['startTime'] < 0 || $a['endTime'] < 0 || $a['startTime'] >= $a['endTime']) {
                    return false;
                }
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }

        return true;
    }

    public function can_save_annotations(WP_REST_Request $request): bool
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (! $nonce || ! wp_verify_nonce($nonce, 'wp_rest')) {
            return false;
        }

        $post = get_post(absint($request->get_param('postId')));
        return $post && $this->user_can_access($post);
    }

    public function handle_save_annotations(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $post_id     = absint($request->get_param('postId'));
            $annotations = $this->sanitize_annotations($request->get_param('annotations'));

            if ($this->is_rate_limited($post_id)) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => 'Too many requests.',
                    ],
                    429
                );
            }

            if (is_wp_error($val = $this->validate_annotation_consistency($annotations))) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => $val->get_error_message(),
                    ],
                    400
                );
            }

            $json_data = wp_json_encode($annotations);
            if ($json_data === false) {
                throw new Exception('Failed to encode annotations.');
            }

            update_field('waveform_json', $json_data, $post_id);
            return new WP_REST_Response(
                [
                    'success' => true,
                    'count'   => \count($annotations),
                ],
                200
            );
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Internal server error.',
                ],
                500
            );
        }
    }

    private function is_rate_limited(int $post_id): bool
    {
        $key = \sprintf('starmus_ann_rl_%d_%d', get_current_user_id(), $post_id);
        if (get_transient($key)) {
            return true;
        }

        set_transient($key, true, self::STARMUS_RATE_LIMIT_SECONDS);
        return false;
    }

    private function validate_annotation_consistency(array $annotations): bool|WP_Error
    {
        if ($annotations === []) {
            return true;
        }

        usort($annotations, fn (array $a, array $b): int => $a['startTime'] <=> $b['startTime']);
        for ($i = 0; $i < \count($annotations) - 1; $i++) {
            if ($annotations[$i]['endTime'] > $annotations[$i + 1]['startTime']) {
                return new WP_Error('overlap_detected', __('Overlapping annotations detected.', 'starmus-audio-recorder'));
            }
        }

        return true;
    }

    public function get_editor_context_public(array $atts = []): array|WP_Error
    {
        return $this->get_editor_context($atts);
    }
}
