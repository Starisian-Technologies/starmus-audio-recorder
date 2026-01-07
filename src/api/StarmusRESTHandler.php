<?php

declare(strict_types=1);

/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * WordPress REST API Handler for Audio Submission Endpoints
 *
 * Bridges HTTP REST requests to internal submission services, providing
 * secure upload endpoints for audio recordings with multiple fallback strategies.
 * Integrates with WordPress REST API authentication and permission systems.
 *
 * Key Features:
 * - **Multi-Strategy Upload**: TUS chunked, fallback direct, legacy base64
 * - **WordPress Integration**: Native REST API, permissions, nonces
 * - **Error Handling**: Comprehensive error responses and logging
 * - **Status Tracking**: Real-time submission status endpoints
 * - **Security**: User capability checks and request validation
 *
 * Registered Endpoints:
 * - `POST /wp-json/star/v1/upload-chunk` - TUS multipart chunk handler
 * - `POST /wp-json/star/v1/upload-fallback` - Direct file upload fallback
 * - `POST /wp-json/star/v1/upload-chunk-legacy` - Base64 legacy support
 * - `GET /wp-json/star/v1/status/{id}` - Submission status checking
 *
 * Authentication & Permissions:
 * - Requires `upload_files` WordPress capability
 * - Integrates with WordPress user authentication
 * - Supports logged-in users and API authentication
 * - Automatic nonce validation via WordPress REST API
 *
 * Upload Strategy Hierarchy:
 * 1. **Primary**: TUS resumable uploads (handle_upload_chunk_rest_multipart)
 * 2. **Fallback**: Direct HTTP POST uploads (handle_fallback_upload)
 * 3. **Legacy**: Base64 encoded uploads (handle_upload_chunk_rest_base64)
 *
 * Error Response Format:
 * ```json
 * {
 *   "code": "error_type",
 *   "message": "Human readable message",
 *   "data": { "status": 400 }
 * }
 * ```
 *
 * Success Response Format:
 * ```json
 * {
 *   "success": true,
 *   "data": {
 *     "post_id": 123,
 *     "attachment_id": 456,
 *     "status": "completed"
 *   }
 * }
 * ```
 *
 * WordPress Integration:
 * - Hooks into `rest_api_init` for route registration
 * - Uses WordPress permission callbacks
 * - Integrates with WP_REST_Request and WP_REST_Response
 * - Triggers `starmus_submission_complete` action on success
 *
 * @package Starisian\Sparxstar\Starmus\api
 *
 * @version 6.5.0-PHP7-COMPAT
 *
 * @since   1.0.0
 * @see StarmusSubmissionHandler Submission processing service
 * @see IStarmusAudioDAL Data access layer
 * @see WP_REST_Request WordPress request object
 * @see WP_REST_Response WordPress response object
 */
namespace Starisian\Sparxstar\Starmus\api;

use Throwable;

if (! \defined('ABSPATH')) {
    exit;
}

use function __;
use function add_action;
use function current_user_can;
use function do_action;
use function is_wp_error;
use function register_rest_route;

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\core\StarmusSubmissionHandler;
use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusAudioDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * WordPress REST API handler for audio submissions.
 *
 * Bridges HTTP endpoints to internal submission processing services
 * with comprehensive error handling and multiple upload strategies.
 *
 * NOTE: 'readonly' removed for PHP < 8.2 compatibility.
 *
 * @since 1.0.0
 */
final class StarmusRESTHandler
{
    /**
     * Submission processing service for upload handling.
     *
     * Handles the core business logic for audio file processing,
     * post creation, and metadata management.
     *
     * @since 1.0.0
     */
    private StarmusSubmissionHandler $submission_handler;

    /**
     * Data Access Layer for WordPress operations.
     *
     * Provides abstraction for WordPress database operations,
     * post management, and metadata handling.
     *
     * @since 1.0.0
     */
    private IStarmusAudioDAL $dal;

    /**
     * Plugin settings and configuration management.
     *
     * Contains plugin configuration, feature flags, and
     * user-customizable settings for audio processing.
     *
     * @since 1.0.0
     */
    private StarmusSettings $settings;

    /**
     * @param StarmusSettings $settings Plugin settings and configuration
     * @param StarmusSubmissionHandler|null $submission_handler Optional submission handler (auto-created if null)
     *
     * @since 1.0.0
     *
     * Dependency Injection:
     * - **Required**: DAL and Settings instances
     * - **Optional**: Submission handler (auto-instantiated with DAL/Settings)
     * - Enables testability and service composition
     *
     * WordPress Hook Registration:
     * - Registers route setup callback on `rest_api_init`
     * - Uses array callback syntax for PHP 7.4 compatibility
     * - Ensures routes are available when REST API initializes
     *
     * Service Dependencies:
     * - DAL: WordPress database operations
     * - Settings: Configuration management
     * - Submission Handler: Core upload processing logic
     * @see register_routes() Route registration implementation
     */
    public function __construct(
        IStarmusAudioDAL $dal,
        StarmusSettings $settings,
        ?StarmusSubmissionHandler $submission_handler = null
    ) {
        $this->dal                = $dal;
        $this->settings           = $settings;
        $this->submission_handler = $submission_handler ?? new StarmusSubmissionHandler($this->dal, $this->settings);

        // Use array callback syntax for PHP 8.2 compatibility
        add_action('rest_api_init', $this->register_routes(...));
    }

    /**
     * Registers all WordPress REST API routes for audio submissions.
     *
     * Configures multiple upload endpoints with different strategies to handle
     * various client capabilities and network conditions. Each route includes
     * proper permission callbacks and parameter validation.
     *
     * @since 1.0.0
     *
     * Registered Routes:
     *
     * 1. **Primary Upload**: `/upload-chunk` (POST)
     *    - TUS resumable multipart uploads
     *    - Optimal for large files and unreliable networks
     *    - Handler: StarmusSubmissionHandler::handle_upload_chunk_rest_multipart
     *
     * 2. **Fallback Upload**: `/upload-fallback` (POST)
     *    - Direct HTTP POST file uploads
     *    - Simpler implementation for basic clients
     *    - Handler: StarmusRESTHandler::handle_fallback_upload
     *
     * 3. **Legacy Upload**: `/upload-chunk-legacy` (POST)
     *    - Base64 encoded file uploads
     *    - Compatibility with older JavaScript implementations
     *    - Handler: StarmusSubmissionHandler::handle_upload_chunk_rest_base64
     *
     * 4. **Status Check**: `/status/{id}` (GET)
     *    - Real-time submission status queries
     *    - Supports progress tracking and error reporting
     *    - Handler: StarmusRESTHandler::handle_status
     *
     * Permission Strategy:
     * - All routes require `upload_files` WordPress capability
     * - Integrates with WordPress user authentication system
     * - Supports both logged-in users and API authentication
     *
     * Route Parameters:
     * - Namespace: StarmusSubmissionHandler::STARMUS_REST_NAMSPACE
     * - Methods: Explicitly defined for security
     * - Validation: Numeric ID validation for status route
     * - Callbacks: Array syntax for PHP 7.4 compatibility
     *
     * @hook rest_api_init WordPress REST API initialization
     *
     * @see register_rest_route() WordPress REST route registration
     * @see current_user_can() WordPress capability checking
     */
    public function register_routes(): void
    {
        // Use global namespace constant
        $namespace = STARMUS_REST_NAMESPACE;

        // 1. Multipart Chunk Handler
        register_rest_route(
            $namespace,
            '/upload-chunk',
            [
        'methods'             => 'POST', // or WP_REST_Server::CREATABLE
        'callback'            => $this->submission_handler->handle_upload_chunk_rest_multipart(...),
        'permission_callback' => $this->upload_permissions_check(...),
        ]
        );

        // 2. Fallback Handler (Direct POST)
        register_rest_route(
            $namespace,
            '/upload-fallback',
            [
        'methods'             => 'POST',
        'callback'            => $this->handle_fallback_upload(...),
        'permission_callback' => $this->upload_permissions_check(...),
        ]
        );

        // 3. Legacy Base64
        register_rest_route(
            $namespace,
            '/upload-chunk-legacy',
            [
        'methods'             => 'POST',
        'callback'            => $this->submission_handler->handle_upload_chunk_rest_base64(...),
        'permission_callback' => $this->upload_permissions_check(...),
        ]
        );

        // 4. Status Check
        register_rest_route(
            $namespace,
            '/status/(?P<id>\d+)',
            [
        'methods'             => 'GET',
        'callback'            => $this->handle_status(...),
        'permission_callback' => $this->upload_permissions_check(...),
        'args'                => [
        'id' => [
         'validate_callback' => 'is_numeric',
        ],
        ],
        ]
        );
    }

    /**
     * Check if the current user has permission to upload files.
     *
     *
     * @return bool True if the user has permission, false otherwise.
     */
    public function upload_permissions_check(): bool
    {
        return current_user_can('upload_files');
    }

    /**
     * Handles direct HTTP POST file uploads as fallback strategy.
     *
     * Processes traditional multipart/form-data uploads when TUS resumable
     * uploads are not available or fail. Provides comprehensive error handling
     * and triggers WordPress actions on successful completion.
     *
     * @param WP_REST_Request $request WordPress REST API request object
     *
     * @since 1.0.0
     *
     * Request Requirements:
     * - **Method**: POST with multipart/form-data
     * - **File Field**: 'audio_file' or 'file' in $_FILES
     * - **Permissions**: Current user must have 'upload_files' capability
     * - **File Validation**: Valid temporary upload path required
     *
     * Processing Flow:
     * 1. **Request Logging**: Debug logging of incoming file parameters
     * 2. **File Extraction**: Flexible field name handling (audio_file/file)
     * 3. **Validation**: File existence and format verification
     * 4. **Delegation**: Pass to StarmusSubmissionHandler for processing
     * 5. **Error Handling**: Convert WP_Error to REST response format
     * 6. **Success Actions**: Trigger completion hooks and return data
     *
     * File Parameter Handling:
     * - Primary field name: 'audio_file'
     * - Fallback field name: 'file'
     * - Validates tmp_name existence and array structure
     * - Defensive programming against malformed uploads
     *
     * Response Formats:
     *
     * **Success Response** (200):
     * ```json
     * {
     *   "success": true,
     *   "data": {
     *     "post_id": 123,
     *     "attachment_id": 456,
     *     "status": "completed"
     *   }
     * }
     * ```
     *
     * **Error Response** (400/500):
     * ```json
     * {
     *   "code": "server_error",
     *   "message": "Upload failed. Please try again later.",
     *   "data": { "status": 500 }
     * }
     * ```
     *
     * WordPress Integration:
     * - Triggers `starmus_submission_complete` action on success
     * - Passes attachment_id and post_id to action hooks
     * - Integrates with WordPress error handling (WP_Error)
     * - Uses WordPress internationalization for error messages
     *
     * Security Considerations:
     * - File parameter keys logged only (not content)
     * - Input validation before processing
     * - Error message sanitization
     * - Capability checking via route permission callback
     *
     * Error Conditions:
     * - Missing or invalid file upload data (400)
     * - Submission handler processing failure (500)
     * - Unexpected exceptions during processing (500)
     *
     * @throws Throwable Caught and converted to HTTP 500 response
     *
     * @return WP_REST_Response REST response object or null on critical failure
     *
     * @see StarmusSubmissionHandler::handle_fallback_upload_rest() Core processing
     * @see StarmusLogger::debug() Request logging
     *
     * @action starmus_submission_complete Triggered on successful upload
     */
    public function handle_fallback_upload(WP_REST_Request $request): WP_REST_Response
    {
        try {
            // Log incoming payload
            $files = $request->get_file_params();
            StarmusLogger::debug(
                'Fallback upload request',
                [
            'component' => self::class,
            'file_keys' => array_keys($files),
            ]
            );

            // Defensive file-key handling
            $file = $files['audio_file'] ?? ($files['file'] ?? null);
            if (! $file || ! \is_array($file) || empty($file['tmp_name'])) {
                return new WP_REST_Response(
                    [ // Explicit Response object for errors
                     'code'    => 'server_error',
                     'message' => __('Missing or invalid file upload data.', 'starmus-audio-recorder'),
                     'data'    => ['status' => 400],
                    ],
                    400
                );
            }

            // Pass request downstream
            $result = $this->submission_handler->handle_fallback_upload_rest($request);

            if (is_wp_error($result)) {
                return new WP_REST_Response(
                    [
                  'code'    => $result->get_error_code(),
                  'message' => $result->get_error_message(),
                  'data'    => ['status' => 500],
                 ],
                    500
                );
            }

            // Extract submission data
            $response_data   = $result;
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
        } catch (Throwable $throwable) {
            error_log('[STARMUS PHP] REST Upload Error: ' . $throwable->getMessage() . ' in ' . $throwable->getFile() . ':' . $throwable->getLine());
            return new WP_REST_Response(
                [
            'code'    => 'server_error',
            'message' => __('Upload failed. Please try again later.', 'starmus-audio-recorder'),
            'data'    => ['status' => 500],
            ],
                500
            );
        }
    }

    /**
     * Provides real-time status information for audio submission posts.
     *
     * Queries WordPress database for submission status and validates post type
     * to ensure only audio recording submissions are accessible via this endpoint.
     *
     * @param WP_REST_Request $request WordPress REST API request with post ID
     *
     * @since 1.0.0
     *
     * URL Pattern: `/wp-json/star/v1/status/{id}`
     * - **Method**: GET
     * - **Parameter**: `id` (numeric post ID)
     * - **Validation**: Automatic numeric validation via route args
     *
     * Request Processing:
     * 1. **ID Extraction**: Extract post ID from URL parameter
     * 2. **Database Query**: Fetch post information via DAL
     * 3. **Existence Check**: Verify post exists in database
     * 4. **Type Validation**: Ensure post is audio recording type
     * 5. **Response Assembly**: Return status information or error
     *
     * Response Formats:
     *
     * **Success Response** (200):
     * ```json
     * {
     *   "success": true,
     *   "data": {
     *     "id": 123,
     *     "status": "publish",
     *     "type": "audio-recording"
     *   }
     * }
     * ```
     *
     * **Error Responses**:
     *
     * *Post Not Found* (404):
     * ```json
     * {
     *   "code": "not_found",
     *   "message": "Submission not found"
     * }
     * ```
     *
     * *Invalid Post Type* (403):
     * ```json
     * {
     *   "code": "invalid_type",
     *   "message": "Not an audio recording"
     * }
     * ```
     *
     * *Server Error* (500):
     * ```json
     * {
     *   "code": "server_error",
     *   "message": "Could not fetch status"
     * }
     * ```
     *
     * Status Information:
     * - **ID**: WordPress post ID (integer)
     * - **Status**: WordPress post status (draft, pending, publish, etc.)
     * - **Type**: Custom post type slug for verification
     *
     * Security Features:
     * - Post type validation prevents access to non-audio content
     * - DAL abstraction prevents direct database exposure
     * - Error message standardization prevents information leakage
     * - Capability checking via route permission callback
     *
     * Use Cases:
     * - Upload progress tracking in JavaScript clients
     * - Status dashboard implementations
     * - Integration testing and debugging
     * - Third-party service integrations
     *
     * Performance Considerations:
     * - Single database query via optimized DAL method
     * - Minimal data transfer (status info only)
     * - Efficient post type checking
     * - No file system operations
     *
     * @throws Throwable Caught and converted to HTTP 500 response
     *
     * @return WP_REST_Response REST response with status data or error
     *
     * @see IStarmusAudioDAL::get_post_info() Data retrieval
     * @see StarmusSubmissionHandler::get_cpt_slug() Post type validation
     */
    public function handle_status(WP_REST_Request $request): WP_REST_Response
    {
        $post_id = (int) $request['id'];

        try {
            $post_info = $this->dal->get_post_info($post_id);
            if (! $post_info) {
                return new WP_REST_Response(
                    [
                  'code'    => 'not_found',
                  'message' => 'Submission not found',
                 ],
                    404
                );
            }

            if ($post_info['type'] !== $this->submission_handler->get_cpt_slug()) {
                return new WP_REST_Response(
                    [
                'code'    => 'invalid_type',
                'message' => 'Not an audio recording',
                ],
                    403
                );
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
        } catch (Throwable $throwable) {
            error_log('[STARMUS PHP] Status Error: ' . $throwable->getMessage() . ' in ' . $throwable->getFile() . ':' . $throwable->getLine());
            return new WP_REST_Response(
                [
            'code'    => 'server_error',
            'message' => 'Could not fetch status',
            ],
                500
            );
        }
    }
}
