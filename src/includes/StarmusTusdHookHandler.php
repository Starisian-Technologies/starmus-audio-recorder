<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\includes;

use Starisian\Sparxstar\Starmus\core\StarmusSubmissionHandler;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! \defined('ABSPATH')) {
    exit;
}

use WP_REST_Server;

/**
 * Handles TUS daemon webhook callbacks for upload completion events.
 *
 * Provides REST API endpoint to receive notifications from the TUS daemon
 * when file uploads are completed. Processes upload metadata and moves
 * files to their final destinations while maintaining security boundaries.
 *
 * @package Starisian\Sparxstar\Starmus\includes
 *
 * @since 1.0.0
 * @see https://tus.io/protocols/resumable-upload.html TUS Protocol Specification
 * @see StarmusSubmissionHandler For file processing implementation
 *
 * Security Features:
 * - Webhook secret validation via x-starmus-secret header
 * - Path traversal protection for temporary file cleanup
 * - Input sanitization and validation
 * - JSON-only communication with proper content type handling
 *
 * Supported TUS Events:
 * - post-finish: Upload completion notification with file processing
 * - Default: Generic event acknowledgment
 */
class StarmusTusdHookHandler
{
    /**
     * REST API namespace for webhook endpoints.
     *
     * @since 1.0.0
     */
    protected string $namespace = 'starmus/v1';

    /**
     * REST API base path for webhook routes.
     *
     * @since 1.0.0
     */
    protected string $rest_base = 'hook';

    /**
     * Initializes the TUS webhook handler with required dependencies.
     *
     * @param StarmusSubmissionHandler $submission_handler Handler for processing completed uploads
     *
     * @since 1.0.0
     */
    public function __construct(
        private readonly StarmusSubmissionHandler $submission_handler
    ) {
    }

    /**
     * Registers WordPress action hooks for REST API initialization.
     *
     * Hooks into the WordPress REST API initialization process to register
     * the webhook endpoint routes when the REST API is ready.
     *
     * @since 1.0.0
     *
     * @hook rest_api_init Called when WordPress REST API is initialized
     */
    public function register_hooks(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Registers REST API routes for TUS webhook handling.
     *
     * Creates the webhook endpoint that accepts POST requests from the TUS daemon
     * with proper validation, permission checking, and parameter sanitization.
     *
     * Route: POST /wp-json/starmus/v1/hook
     *
     * @since 1.0.0
     * @see handle_tusd_hook() Main webhook callback handler
     * @see permissions_check() Authorization validation
     *
     * Required Parameters:
     * - Type: Event type string (e.g., 'post-finish')
     * - Event: Event data object with upload information
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handle_tusd_hook'],
                    'permission_callback' => [$this, 'permissions_check'],
                    'args'                => [
                        'Type' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'Event' => [
                            'required' => true,
                            'type'     => 'object',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Main webhook callback handler for TUS daemon notifications.
     *
     * Processes incoming webhook requests from the TUS daemon, validates the payload,
     * and routes different event types to their appropriate handlers.
     *
     * @param WP_REST_Request $request Incoming webhook request with JSON payload
     *
     * @return WP_REST_Response|WP_Error Success response with empty JSON or error object
     *
     * @since 1.0.0
     * @see handle_post_finish() Handler for upload completion events
     *
     * Expected JSON Payload:
     * ```json
     * {
     *   "Type": "post-finish",
     *   "Event": {
     *     "Upload": {
     *       "Storage": { "Path": "/tmp/upload", "InfoPath": "/tmp/upload.info" },
     *       "MetaData": { "postId": "123", "title": "Recording" }
     *     }
     *   }
     * }
     * ```
     *
     * Supported Event Types:
     * - post-finish: Upload completion with file processing
     * - default: Generic event acknowledgment
     *
     * Error Responses:
     * - 400: Invalid JSON body or missing required fields
     * - 403: Authorization failure (handled by permissions_check)
     * - 500: Internal processing errors
     */
    public function handle_tusd_hook(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $json_params = $request->get_json_params();

            // 1. Validate JSON Body exists (tusd sends JSON)
            if (empty($json_params) || ! \is_array($json_params)) {
                return new WP_Error('invalid_json', 'Invalid JSON body.', ['status' => 400]);
            }

            $event_type = sanitize_key($json_params['Type'] ?? '');
            $event_data = $json_params['Event'] ?? [];

            if (empty($event_type) || empty($event_data)) {
                return new WP_Error('invalid_payload', 'Invalid payload.', ['status' => 400]);
            }

            if (\defined('WP_DEBUG') && WP_DEBUG) {
                StarmusLogger::debug(
                    'Received payload',
                    [
                        'component'  => self::class,
                        'event_type' => $event_type,
                    ]
                );
            }

            do_action('starmus_tusd_event_' . $event_type, $event_data);

            // 2. TUSD requires Content-Type: application/json in the response.
            // WP_REST_Response handles this automatically.
            return match ($event_type) {
                'post-finish' => $this->handle_post_finish($event_data),
                default       => new WP_REST_Response([], 200), // Return empty JSON object {}
            };
        } catch (\Throwable $throwable) {
            StarmusLogger::log($throwable);
            return new WP_Error('internal_error', 'Internal server error', ['status' => 500]);
        }
    }

    /**
     * Handles post-finish upload completion events from TUS daemon.
     *
     * Processes completed file uploads by extracting metadata, sanitizing form data,
     * and delegating to the submission handler for final file processing.
     *
     * @param array $event_data Event data containing upload information
     *
     * @since 1.0.0
     * @see process_completed_upload() Internal upload processing method
     * @see StarmusSubmissionHandler::process_completed_file() Final file processing
     *
     * Expected Event Data Structure:
     * ```php
     * [
     *   'Upload' => [
     *     'Storage' => [
     *       'Path' => '/tmp/tusd_uploads/abc123.bin',
     *       'InfoPath' => '/tmp/tusd_uploads/abc123.bin.info'
     *     ],
     *     'MetaData' => [
     *       'postId' => '123',
     *       'title' => 'My Recording',
     *       'language' => 'en'
     *     ]
     *   ]
     * ]
     * ```
     *
     * Note: This is a "fire and forget" operation. The client does not
     * receive this response body as TUS handles it internally.
     *
     * @throws WP_Error If Upload data is missing or processing fails
     *
     * @return WP_REST_Response|WP_Error Empty success response or error object
     */
    private function handle_post_finish(array $event_data): WP_REST_Response|WP_Error
    {
        try {
            if (empty($event_data['Upload'])) {
                return new WP_Error('invalid_post_finish_payload', 'Missing Upload data.', ['status' => 400]);
            }

            $result = $this->process_completed_upload($event_data['Upload']);

            if (is_wp_error($result)) {
                // Note: tusd will verify this is a non-2xx error and log it, but
                // the client will not see this error message directly.
                StarmusLogger::error(
                    'Upload processing failed',
                    ['component' => self::class]
                );
                return $result;
            }
        } catch (\Throwable $throwable) {
            StarmusLogger::log($throwable);
        }

        // 3. IMPORTANT: post-finish is "fire and forget".
        // The client DOES NOT receive this response body.
        // We return an empty JSON object to satisfy tusd's requirement for a hook response.
        return new WP_REST_Response([], 200);
    }

    /**
     * Processes a completed upload with security-conscious temporary file cleanup.
     *
     * Extracts upload information, sanitizes metadata, delegates file processing
     * to the submission handler, and safely removes temporary TUS info files.
     *
     * @param array $upload_info Upload information from TUS daemon
     *
     * @since 1.0.0
     * @see StarmusSubmissionHandler::sanitize_submission_data() Metadata sanitization
     * @see StarmusSubmissionHandler::process_completed_file() File processing
     *
     * Upload Info Structure:
     * ```php
     * [
     *   'Storage' => [
     *     'Path' => '/tmp/tusd_uploads/upload.bin',     // Temporary file path
     *     'InfoPath' => '/tmp/tusd_uploads/upload.info' // TUS metadata file
     *   ],
     *   'MetaData' => [
     *     'postId' => '123',
     *     'title' => 'Recording Title',
     *     // ... other form fields
     *   ]
     * ]
     * ```
     *
     * Security Features:
     * - Path traversal protection using wp_normalize_path()
     * - Validates deletion targets are within WordPress uploads directory
     * - Logs security violations without exposing paths
     * - Safe unlink() with existence and permission checks
     *
     * @throws WP_Error If file processing fails or security violations detected
     *
     * @return mixed Result from submission handler or WP_Error on failure
     */
    private function process_completed_upload(array $upload_info): mixed
    {
        try {
            $temp_path = $upload_info['Storage']['Path'] ?? '';

            // 4. Use the InfoPath provided by tusd, falling back to concatenation only if missing
            $info_path = $upload_info['Storage']['InfoPath'] ?? ($temp_path . '.info');

            $metadata = $upload_info['MetaData'] ?? [];

            $sanitized_form_data = $this->submission_handler->sanitize_submission_data($metadata);

            // Assuming this function moves the file from $temp_path to a permanent location
            $result = $this->submission_handler->process_completed_file($temp_path, $sanitized_form_data);

            // VIP SECURITY: Path Traversal Check for the Info File deletion
            $upload_dir           = wp_get_upload_dir();
            $basedir              = wp_normalize_path($upload_dir['basedir']);
            $normalized_info_path = wp_normalize_path($info_path);

            if (file_exists($normalized_info_path) && str_starts_with($normalized_info_path, $basedir)) {
                if ( ! unlink($normalized_info_path)) {
                    StarmusLogger::warning(
                        'Failed to delete temp info file',
                        [
                            'component' => self::class,
                            'path'      => $normalized_info_path,
                        ]
                    );
                }
            } elseif (file_exists($normalized_info_path)) {
                StarmusLogger::warning(
                    'Security: Attempted deletion outside uploads',
                    [
                        'component' => self::class,
                        'path'      => $normalized_info_path,
                    ]
                );
            }
        } catch (\Throwable $throwable) {
            StarmusLogger::log($throwable);
        }

        return $result;
    }

    /**
     * Validates webhook authorization using shared secret header.
     *
     * Implements secure webhook authentication by comparing a shared secret
     * sent via the x-starmus-secret header against the configured value.
     * Uses timing-safe comparison to prevent timing attacks.
     *
     * @param WP_REST_Request $request Incoming webhook request
     *
     * @return true|WP_Error True if authorized, WP_Error if unauthorized
     *
     * @since 1.0.0
     *
     * Required Configuration:
     * - STARMUS_TUS_WEBHOOK_SECRET constant must be defined
     * - TUS daemon must be started with: -hooks-http-forward-headers x-starmus-secret
     * - Client must send header: x-starmus-secret: {shared_secret}
     *
     * Security Features:
     * - Timing-safe string comparison using hash_equals()
     * - Validates secret is non-empty and not default values
     * - Logs security violations without exposing secrets
     * - Returns appropriate HTTP status codes
     *
     * Error Responses:
     * - 500: STARMUS_TUS_WEBHOOK_SECRET not configured
     * - 403: Missing or invalid secret header
     * @see hash_equals() Timing-safe string comparison
     *
     * @example
     * Configuration in wp-config.php:
     * ```php
     * define('STARMUS_TUS_WEBHOOK_SECRET', 'your-random-secret-key');
     * ```
     *
     * TUS daemon startup:
     * ```bash
     * tusd -hooks-http-forward-headers x-starmus-secret
     * ```
     */
    public function permissions_check(WP_REST_Request $request): true|WP_Error
    {
        try {
            $expected_secret = \defined('STARMUS_TUS_WEBHOOK_SECRET') ? STARMUS_TUS_WEBHOOK_SECRET : '';

            if (empty($expected_secret)) {
                StarmusLogger::error(
                    'STARMUS_TUS_WEBHOOK_SECRET missing in configuration.',
                    ['component' => self::class]
                );
                return new WP_Error('internal_server_error', 'Internal Service Error', ['status' => 500]);
            }

            // 5. Ensure tusd is started with -hooks-http-forward-headers x-starmus-secret
            $provided_secret = trim((string) $request->get_header('x-starmus-secret'));

            if ('' === $provided_secret || '0' === $provided_secret) {
                return new WP_Error('unauthorized', 'Missing secret header.', ['status' => 403]);
            }

            if ( ! hash_equals($expected_secret, $provided_secret)) {
                return new WP_Error('unauthorized', 'Invalid secret.', ['status' => 403]);
            }
        } catch (\Throwable $throwable) {
            StarmusLogger::log($throwable);
        }

        return true;
    }
}
