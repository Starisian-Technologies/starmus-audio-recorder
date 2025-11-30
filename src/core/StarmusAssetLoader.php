<?php

/**
 * Unified, build-process-aware, and ES-Module-aware asset loader for the Starmus Audio System.
 *
 * This class is the sole authority for enqueuing all Starmus client-side assets.
 *
 * - In production (WP_DEBUG is false), it loads a single, minified, bundled file for performance.
 * - In development (WP_DEBUG is true), it loads the 'starmus-integrator.js' as a native
 *   ES Module (`type="module"`), allowing the browser to handle the dependency tree for
 *   the best possible debugging experience.
 *
 * @package Starmus
 *
 * @version 3.1.0
 */

namespace Starisian\Sparxstar\Starmus\core;

use function array_filter;
use function array_map;
use function defined;
use function explode;
use function json_encode;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;

use function trim;

if (! \defined('ABSPATH')) {
    exit;
}

final class StarmusAssetLoader
{
    /**
     * Handle for the production bundle script.
     *
     * @var string
     */
    private const HANDLE_PROD_BUNDLE = 'starmus-audio-recorder-script.bundle';

    /**
     * Handle for the main stylesheet.
     *
     * @var string
     */
    private const STYLE_HANDLE = 'starmus-audio-recorder-styles';

    /**
     * Constructor - Registers WordPress hooks for asset enqueueing.
     *
     * @return void
     */
    public function __construct()
    {
        add_action('wp_enqueue_scripts', $this->enqueue_frontend_assets(...));

    }

    /**
     * Enqueues frontend assets for Starmus pages.
     *
     * Only loads assets on frontend pages that contain Starmus shortcodes.
     * Always uses production-optimized bundled assets for performance.
     */
    public function enqueue_frontend_assets(): void
    {
        error_log('[Starmus AssetLoader] enqueue_frontend_assets() called');

        if (is_admin()) {
            error_log('[Starmus AssetLoader] Skipping - is admin');
            return;
        }

        error_log('[Starmus AssetLoader] Loading assets...');

        // Load assets on all frontend pages - shortcode detection happens too late
        $this->enqueue_production_assets();
        $this->enqueue_styles();

        error_log('[Starmus AssetLoader] Assets enqueued successfully');
    }

    public function enqueue_re_recorder_assets(): void
    {
        wp_localize_script(
            'starmus-audio-recorder-script.bundle',
            'STARMUS_RERECORDER_DATA',
            ['mode' => 'rerecorder']
        );
    }


    /**
     * Enqueues the single, bundled, and minified JavaScript file for production.
     *
     * This is a standard script, as the build process resolves all modules.
     * Optionally depends on SPARXSTAR environment checker if available for optimal
     * device detection, but gracefully falls back to internal detection after 2s timeout.
     * Localizes the script with server-side configuration data.
     */
    private function enqueue_production_assets(): void
    {
        try {
            error_log('[Starmus AssetLoader] Enqueueing JS: ' . STARMUS_URL . 'assets/js/starmus-audio-recorder-script.bundle.min.js');

            // Check if SPARXSTAR environment checker is registered (optional dependency)
            $dependencies = [];
            if (wp_script_is('sparxstar-user-environment-check-app', 'registered')) {
                $dependencies[] = 'sparxstar-user-environment-check-app';
                error_log('[Starmus AssetLoader] SPARXSTAR environment checker detected - adding as dependency');
            } else {
                error_log('[Starmus AssetLoader] SPARXSTAR not available - will use fallback environment detection');
            }

            wp_enqueue_script(
                self::HANDLE_PROD_BUNDLE,
                STARMUS_URL . 'assets/js/starmus-audio-recorder-script.bundle.min.js',
                $dependencies,
                $this->resolve_version(),
                true
            );

            $config = $this->get_localization_data();
            error_log('[Starmus AssetLoader] Localizing script with config: ' . json_encode($config));

            // Keep the legacy config for backward compatibility
            wp_localize_script(self::HANDLE_PROD_BUNDLE, 'starmusConfig', $config);
            
            // New unified bootstrap contract required by refactored JS
            wp_localize_script(
                self::HANDLE_PROD_BUNDLE,
                'STARMUS_BOOTSTRAP',
                [
                    'version' => $this->resolve_version(),
                    'config'  => $config,
                    'env'     => defined('WP_ENV') ? WP_ENV : 'production',
                    'postId'  => get_the_ID() ?: 0,
                    'restUrl' => esc_url_raw(rest_url()),
                    'homeUrl' => esc_url_raw(home_url('/')),
                ]
            );

            $this->maybe_localize_editor_data();
          

            error_log('[Starmus AssetLoader] JS enqueued successfully');
        } catch (\Throwable $throwable) {
            error_log('[Starmus AssetLoader] ERROR in enqueue_production_assets: ' . $throwable->getMessage());
            StarmusLogger::log('StarmusAssetLoader::enqueue_production_assets', $throwable);
        }
    }

   /**
     * Localize data specifically for the audio editor.
     * Updated to include Audio URL, Waveform, and handle GET parameters.
     */
    private function maybe_localize_editor_data(): void
    {
        global $post;

        // 1. Try to find ID from URL parameter (High Priority for direct editor access)
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        // 2. If not in URL, try to extract from shortcode in current post
        if ($post_id === 0 && $post instanceof \WP_Post) {
            $shortcode = get_post_field('post_content', $post->ID);
            if (has_shortcode((string) $shortcode, 'starmus_audio_editor')) {
                preg_match('/post_id="(\d+)"/', (string) $shortcode, $matches);
                $post_id = isset($matches[1]) ? absint($matches[1]) : 0;
            }
        }

        // If we still don't have a valid ID, stop here.
        if ($post_id === 0) {
            return;
        }

        // 3. Fetch the required Audio and Waveform Data
        $attachment_id = absint(get_post_meta($post_id, '_audio_attachment_id', true));
        $audio_url     = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
        
        // Secure waveform URL logic
        $waveform_url   = '';
        $wave_json_path = get_post_meta($attachment_id, '_waveform_json_path', true);
        if (!empty($wave_json_path) && file_exists($wave_json_path)) {
            $uploads      = wp_get_upload_dir();
            $waveform_url = str_replace($uploads['basedir'], $uploads['baseurl'], $wave_json_path);
        }

        // 4. Get Annotations
        $annotations_json = get_post_meta($post_id, 'starmus_annotations_json', true);
        $annotations      = (!empty($annotations_json) && is_string($annotations_json)) 
                            ? json_decode($annotations_json, true) 
                            : [];

        // 5. Send the COMPLETE data object to JS
        wp_localize_script(
            self::HANDLE_PROD_BUNDLE,
            'STARMUS_EDITOR_DATA',
            [
                'postId'          => $post_id,
                // Ensure this matches the namespace in your StarmusAudioEditorUI class
                'restUrl'         => esc_url_raw(rest_url('star_uec/v1/annotations')), 
                'audioUrl'        => esc_url($audio_url),
                'waveformDataUrl' => esc_url($waveform_url),
                'annotations'     => $annotations,
                'nonce'           => wp_create_nonce('wp_rest'),
                'mode'            => 'editor'
            ]
        );
    }

    private function maybe_localize_re_recorder_data(): void
    {
        if (!isset($_GET['post_id'])) {
            return;
        }

        $post_id = absint($_GET['post_id']);
        if ($post_id <= 0) {
            return;
        }

       wp_localize_script(
            self::HANDLE_PROD_BUNDLE,
            'STARMUS_EDITOR_DATA',
            [
                'postId'          => $post_id,
                'restUrl'         => esc_url_raw(rest_url('star_uec/v1/annotations')),
                'audioUrl'        => esc_url($audio_url),
                'waveformDataUrl' => esc_url($waveform_url),
                'annotations'     => $annotations,
                'nonce'           => wp_create_nonce('wp_rest'),
                'mode'            => 'editor',
                'rerecord'        => isset($_REQUEST['starmus_existing_recording_id']) ? absint($_REQUEST['starmus_existing_recording_id']) : 0,
            ]
        );
    }


    /**
     * Enqueues the minified stylesheet for the plugin.
     *
     * Loads the production-optimized CSS bundle with cache-busting version.
     */
    private function enqueue_styles(): void
    {
        try {
            error_log('[Starmus AssetLoader] Enqueueing CSS: ' . STARMUS_URL . 'assets/css/starmus-audio-recorder-styles.min.css');

            wp_enqueue_style(
                self::STYLE_HANDLE,
                STARMUS_URL . 'assets/css/starmus-audio-recorder-styles.min.css',
                [],
                $this->resolve_version()
            );

            error_log('[Starmus AssetLoader] CSS enqueued successfully');
        } catch (\Throwable $throwable) {
            error_log('[Starmus AssetLoader] ERROR in enqueue_styles: ' . $throwable->getMessage());
            StarmusLogger::log('StarmusAssetLoader::enqueue_styles', $throwable);
        }
    }

    /**
     * Gathers and prepares all necessary server-side data for the client-side app.
     *
     * Builds the configuration object that's localized to JavaScript, including:
     * - REST API endpoints for uploads
     * - Authentication nonce
     * - Current user ID
     * - Allowed file types and MIME types from settings
     *
     * @return array<string, mixed> Configuration array with endpoints, nonce, user_id, and file type settings.
     *                              Returns safe defaults on error.
     */
    private function get_localization_data(): array
    {
        try {
            // Get settings instance
            $settings = new StarmusSettings();

            // Get allowed file types from settings (comma-separated string like 'mp3,wav,webm')
            $allowed_file_types = $settings->get('allowed_file_types', 'mp3,wav,webm');
            $allowed_types_arr  = array_values(array_filter(array_map('trim', explode(',', (string) $allowed_file_types)), fn($v) => $v !== ''));

            // Map extensions to MIME types
            $allowed_mimes = [];
            foreach ($allowed_types_arr as $ext) {
                $mime = StarmusSettings::get_allowed_mimes()[$ext] ?? null;
                if ($mime) {
                    $allowed_mimes[$ext] = $mime;
                }
            }

            // TUS endpoint from settings
            $tus_endpoint = get_option('starmus_tus_endpoint', '');

            // Speech recognition language from settings
            $speech_lang = $settings->get('speech_recognition_lang', 'en-US');

            // Get my-recordings page URL from settings
            $my_recordings_slug = $settings->get('my_recordings_page_slug', 'my-submissions');
            $my_recordings_url  = home_url('/' . $my_recordings_slug . '/');

            return [
                'endpoints' => [
                    'directUpload' => esc_url_raw(rest_url(StarmusSubmissionHandler::STARMUS_REST_NAMESPACE . '/upload-fallback')),
                    'tusUpload'    => esc_url_raw($tus_endpoint),
                ],
                'nonce'                 => wp_create_nonce('wp_rest'),
                'user_id'               => get_current_user_id(),
                'allowedFileTypes'      => $allowed_types_arr, // ['mp3', 'wav', 'webm']
                'allowedMimeTypes'      => $allowed_mimes,     // ['mp3' => 'audio/mpeg', ...]
                'speechRecognitionLang' => sanitize_text_field($speech_lang), // BCP 47 language code
                'myRecordingsUrl'       => esc_url_raw($my_recordings_url), // Redirect URL after successful submission
            ];
        } catch (\Throwable $throwable) {
            StarmusLogger::log('StarmusAssetLoader::get_localization_data', $throwable);
            return [
                'endpoints' => [
                    'directUpload' => '',
                    'tusUpload'    => '',
                ],
                'nonce'                 => '',
                'user_id'               => 0,
                'allowedFileTypes'      => [],
                'allowedMimeTypes'      => [],
                'speechRecognitionLang' => 'en-US',
                'myRecordingsUrl'       => home_url('/my-submissions/'),
            ];
        }
    }

    /**
     * Resolves the asset version number for cache-busting.
     *
     * Uses the STARMUS_VERSION constant if defined, otherwise falls back to 1.0.0.
     * This version is appended to asset URLs to invalidate browser caches on updates.
     *
     * @return string Version string for cache-busting.
     */
    private function resolve_version(): string
    {
        try {
            return (\defined('STARMUS_VERSION') && STARMUS_VERSION) ? STARMUS_VERSION : '1.0.0';
        } catch (\Throwable $throwable) {
            StarmusLogger::log('StarmusAssetLoader::resolve_version', $throwable);
            return '1.0.0';
        }
    }
}
