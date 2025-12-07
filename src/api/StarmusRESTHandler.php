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
 * - /upload-chunk: Chunked file uploads for large files or poor connections
 * - /status/{id}: Check processing status of submitted recordings
 *
 * All endpoints require the 'upload_files' capability and handle errors gracefully
 * with appropriate HTTP status codes and WordPress-standard error responses.
 *
 * The handler integrates with the plugin's logging system and fires WordPress
 * action hooks for completed submissions to enable third-party integrations.
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
     * Handles the actual business logic for processing uploaded audio files,
     * creating WordPress posts, and managing file operations. Injected via
     * constructor for dependency inversion and testability.
     *
     * @since 0.1.0
     *
     * @var StarmusSubmissionHandler
     */
    private StarmusSubmissionHandler $submission_handler;

    /**
     * Initialize REST handler with required dependencies.
     *
     * Sets up the REST handler with injected dependencies and registers
     * WordPress REST API routes. The submission handler can be optionally
     * injected for testing purposes - if not provided, a default instance
     * will be created.
     *
     * Automatically registers REST routes via the 'rest_api_init' action hook.
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
     * Registers three REST endpoints under the plugin's namespace:
     *
     * 1. POST /star-/v1/upload-fallback
     *    - Traditional form-based file upload
     *    - Requires 'upload_files' capability
     *
     * 2. POST /star-/v1/upload-chunk
     *    - Chunked file upload for large files or poor connections
     *    - Requires 'upload_files' capability
     *
     * 3. GET /star-/v1/status/{id}
     *    - Check processing status of a submitted recording
     *    - Requires 'upload_files' capability
     *    - Validates numeric ID parameter
     *
     * Called automatically via 'rest_api_init' action hook during WordPress initialization.
     *
     * @since 0.1.0
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(
            StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
            '/upload-fallback',
            [
                'methods'             => 'POST',
                'callback'            => $this->handle_fallback_upload(...),
                'permission_callback' => static fn () => current_user_can('upload_files'),
            ]
        );

        register_rest_route(
            StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
            '/upload-chunk',
            [
                'methods'             => 'POST',
                'callback'            => $this->handle_chunk_upload(...),
                'permission_callback' => static fn () => current_user_can('upload_files'),
            ]
        );

        register_rest_route(
            StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
            '/status/(?P<id>\d+)',
            [
                'methods'             => 'GET',
                'callback'            => $this->handle_status(...),
                'permission_callback' => static fn () => current_user_can('upload_files'),
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
     * Processes multipart form uploads containing audio files. This endpoint serves
     * as a fallback for browsers or environments that don't support chunked uploads.
     * The handler extracts file data from the request, validates it, and delegates
     * processing to the submission handler.
     *
     * Expected request format:
     * - Content-Type: multipart/form-data
     * - File field: 'audio_file' or 'file'
     * - Additional form parameters as needed
     *
     * On success, fires the 'starmus_submission_complete' action hook with
     * attachment_id and post_id parameters for third-party integration.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request WordPress REST request object containing file and form data.
     *
     * @phpstan-param WP_REST_Request<array<string,mixed>> $request
     *
     * @return WP_REST_Response|WP_Error Success response with submission data or error object.
     *                                   Success: {success: true, data: {attachment_id, post_id, ...}}
     *                                   Error: WP_Error with appropriate HTTP status code
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

            // CRITICAL FIX START: $result is array|WP_Error from handle_fallback_upload_rest()
            // No need to check instanceof WP_REST_Response since method doesn't return that type
            $response_data = $result;

            // We expect the final structure to contain a 'data' key with submission details.
            $submission_data = $response_data['data'] ?? [];
            // CRITICAL FIX END.

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
            StarmusLogger::log('RestHandler:fallback', $throwable, 'error');
            return new WP_Error(
                'server_error',
                __('Upload failed. Please try again later.', 'starmus-audio-recorder'),
                ['status' => 500]
            );
        }
    }

    /**
     * Handle chunked audio file uploads.
     *
     * Processes chunked file uploads for large audio files or environments with
     * poor network connectivity. This endpoint supports resumable uploads where
     * files are split into smaller chunks and uploaded sequentially.
     *
     * The handler delegates chunk processing to the submission handler, which
     * manages chunk assembly, validation, and final file creation.
     *
     * Expected request format:
     * - Content-Type: application/octet-stream or multipart/form-data
     * - Chunk metadata in headers or form fields
     * - Binary chunk data in request body
     *
     * On successful completion of all chunks, fires the 'starmus_submission_complete'
     * action hook with attachment_id and post_id parameters.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request WordPress REST request object containing chunk data and metadata.
     *
     * @phpstan-param WP_REST_Request<array<string,mixed>> $request
     *
     * @return WP_REST_Response|WP_Error Success response with chunk status or error object.
     *                                   Success: {success: true, data: {chunk_info, progress, ...}}
     *                                   Error: WP_Error with appropriate HTTP status code
     */
    public function handle_chunk_upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $result = $this->submission_handler->handle_upload_chunk_rest($request);

            if (is_wp_error($result)) {
                return $result;
            }

            if (! empty($result['data']['attachment_id']) && ! empty($result['data']['post_id'])) {
                do_action('starmus_submission_complete', $result['data']['attachment_id'], $result['data']['post_id']);
            }

            return new WP_REST_Response(
                [
                    'success' => true,
                    'data'    => $result,
                ],
                200
            );
        } catch (\Throwable $throwable) {
            StarmusLogger::log('RestHandler:chunk', $throwable, 'error');
            return new WP_Error('server_error', 'Chunk upload failed', ['status' => 500]);
        }
    }

    /**
     * Handle status check requests for submitted audio recordings.
     *
     * Retrieves the processing status of a previously submitted audio recording
     * by its post ID. Validates that the requested ID exists, belongs to the
     * correct post type, and is accessible by the current user.
     *
     * Used by frontend interfaces to poll for processing completion or check
     * submission status for user feedback.
     *
     * URL format: GET /star-/v1/status/{id}
     * Where {id} is a numeric post ID of an audio-recording post type.
     *
     * @since 0.1.0
     *
     * @param WP_REST_Request $request WordPress REST request object with 'id' parameter.
     *
     * @phpstan-param WP_REST_Request<array<string,mixed>> $request
     *
     * @return WP_REST_Response|WP_Error Status response or error object.
     *                                   Success: {success: true, data: {id, status, type}}
     *                                   Error: WP_Error with 404 (not found), 403 (wrong type), or 500 (server error)
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
            StarmusLogger::log('RestHandler:status', $throwable, 'error');
            return new WP_Error('server_error', 'Could not fetch status', ['status' => 500]);
        }
    }
}
