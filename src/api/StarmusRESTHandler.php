<?php

declare(strict_types=1);

/**
 * REST handler bridging HTTP endpoints to submission services.
 *
 * @package Starisian\Sparxstar\Starmus\api
 */

namespace Starisian\Sparxstar\Starmus\api;

if (! \defined('ABSPATH')) {
    exit;
}

use function __;
use function current_user_can;
use function is_wp_error;
use function register_rest_route;

use Starisian\Sparxstar\Starmus\core\interfaces\StarmusAudioRecorderDALInterface;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * WordPress REST API handler for audio submission endpoints.
 *
 * This class serves as a bridge between HTTP REST requests and the internal
 * submission handling services. It exposes three main endpoints:
 * - /upload-fallback: Traditional form-based file uploads
 * - /upload-chunk: Chunked file uploads (NEW Multipart)
 * - /upload-chunk-legacy: Chunked file uploads (Old Base64/JSON)
 * - /status/{id}: Check processing status of submitted recordings
 *
 * @package Starisian\Sparxstar\Starmus\api
 *
 * @since   0.1.0
 */
final readonly class StarmusRESTHandler
{
    /**
     * Submission handler service for processing audio uploads.
     *
     * @since 0.1.0
     *
     * @var StarmusSubmissionHandler
     */
    private StarmusSubmissionHandler $submission_handler;

    /**
     * Initialize REST handler with required dependencies.
     *
     * @since 0.1.0
     *
     * @param StarmusAudioRecorderDALInterface $dal Data access layer for database operations.
     * @param StarmusSettings $settings Plugin settings and configuration.
     * @param StarmusSubmissionHandler|null $submission_handler Optional submission handler for dependency injection.
     */
    public function __construct(
        private StarmusAudioRecorderDALInterface $dal,
        private StarmusSettings $settings,
        ?StarmusSubmissionHandler $submission_handler = null
    ) {

        $this->submission_handler = $submission_handler ?? new StarmusSubmissionHandler($this->dal, $this->settings);

        add_action('rest_api_init', $this->register_routes(...));
    }

    /**
     * Register WordPress REST API routes for audio submissions.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public function register_routes(): void
    {
        // 1. PRIORITY 2 TARGET: NEW Multipart Chunk Handler (Official /upload-chunk route)
        register_rest_route(
            StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
            '/upload-chunk',
            [
                'methods'             => 'POST',
                'callback'            => [$this->submission_handler, 'handle_upload_chunk_rest_multipart'], // <-- WIRED CORRECTLY
                'permission_callback' => static fn() => current_user_can('upload_files'),
            ]
        );

        // 2. PRIORITY 3: Fallback Handler (Direct POST)
        register_rest_route(
            StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
            '/upload-fallback',
            [
                'methods'             => 'POST',
                'callback'            => $this->handle_fallback_upload(...),
                'permission_callback' => static fn() => current_user_can('upload_files'),
            ]
        );

        // 3. LEGACY: Old Base64 Chunk Handler (Moved to -legacy route)
        register_rest_route(
            StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
            '/upload-chunk-legacy', // <-- NEW ROUTE NAME
            [
                'methods'             => 'POST',
                'callback'            => [$this->submission_handler, 'handle_upload_chunk_rest_base64'], // <-- WIRED CORRECTLY
                'permission_callback' => static fn() => current_user_can('upload_files'),
            ]
        );

        // 4. Status Check
        register_rest_route(
            StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
            '/status/(?P<id>\d+)',
            [
                'methods'             => 'GET',
                'callback'            => $this->handle_status(...),
                'permission_callback' => static fn() => current_user_can('upload_files'),
                'args'                => [
                    'id' => [
                        'validate_callback' => 'is_numeric',
                    ],
                ],
            ]
        );
    }

    /**
     * Handle traditional form-based audio file uploads.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request WordPress REST request object containing file and form data.
     *
     * @phpstan-param WP_REST_Request<array<string,mixed>> $request
     *
     * @return WP_REST_Response|WP_Error Success response with submission data or error object.
     */
    public function handle_fallback_upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            // Log incoming payload
            $files = $request->get_file_params();
            StarmusLogger::debug('StarmusRESTHandler', 'Fallback upload request', [
                'files'  => $files,
                'params' => $request->get_params(),
            ]);

            // Defensive file-key handling
            $file = $files['audio_file'] ?? ($files['file'] ?? null);
            if (! $file || ! \is_array($file) || empty($file['tmp_name'])) {
                return new WP_Error(
                    'server_error',
                    __('Missing or invalid file upload data.', 'starmus-audio-recorder'),
                    ['status' => 400]
                );
            }

            // Pass request downstream
            $result = $this->submission_handler->handle_fallback_upload_rest($request);

            if (is_wp_error($result)) {
                return $result;
            }

            // Extract submission data
            $response_data = $result;
            $submission_data = $response_data['data'] ?? [];

            do_action(
                'starmus_submission_complete',
                $submission_data['attachment_id'] ?? 0,
                $submission_data['post_id']       ?? 0
            );

            return new WP_REST_Response(
                [
                    'success' => true,
                    'data'    => $submission_data,
                ],
                200
            );
        } catch (\Throwable $throwable) {
            error_log($throwable->getMessage());
            return new WP_Error(
                'server_error',
                __('Upload failed. Please try again later.', 'starmus-audio-recorder'),
                ['status' => 500]
            );
        }
    }
    
    /**
     * Handle status check requests for submitted audio recordings.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request WordPress REST request object with 'id' parameter.
     *
     * @phpstan-param WP_REST_Request<array<string,mixed>> $request
     *
     * @return WP_REST_Response|WP_Error Status response or error object.
     */
    public function handle_status(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post_id = (int) $request['id'];

        try {
            $post_info = $this->dal->get_post_info($post_id);
            if (! $post_info) {
                return new WP_Error('not_found', 'Submission not found', ['status' => 404]);
            }

            if ($post_info['type'] !== $this->submission_handler->get_cpt_slug()) {
                return new WP_Error('invalid_type', 'Not an audio recording', ['status' => 403]);
            }

            return new WP_REST_Response(
                [
                    'success' => true,
                    'data'    => [
                        'id'     => $post_id,
                        'status' => $post_info['status'],
                        'type'   => $post_info['type'],
                    ],
                ],
                200
            );
        } catch (\Throwable $throwable) {
            error_log($throwable->getMessage());
            return new WP_Error('server_error', 'Could not fetch status', ['status' => 500]);
        }
    }
}