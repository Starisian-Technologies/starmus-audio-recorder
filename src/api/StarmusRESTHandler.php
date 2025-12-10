<?php

declare(strict_types=1);

/**
 * REST handler bridging HTTP endpoints to submission services.
 *
 * @package Starisian\Sparxstar\Starmus\api
 * @version 6.5.0-PHP7-COMPAT
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
use WP_REST_Server;

/**
 * WordPress REST API handler.
 * NOTE: 'readonly' removed for PHP < 8.2 compatibility.
 */
final class StarmusRESTHandler
{
    /**
     * Submission handler service.
     */
    private StarmusSubmissionHandler $submission_handler;

    /**
     * Data Access Layer.
     */
    private StarmusAudioRecorderDALInterface $dal;

    /**
     * Settings.
     */
    private StarmusSettings $settings;

    /**
     * Initialize REST handler.
     */
    public function __construct(
        StarmusAudioRecorderDALInterface $dal,
        StarmusSettings $settings,
        ?StarmusSubmissionHandler $submission_handler = null
    ) {
        $this->dal = $dal;
        $this->settings = $settings;
        $this->submission_handler = $submission_handler ?? new StarmusSubmissionHandler($this->dal, $this->settings);

        // FIX: Use array callback for PHP 7.4 compatibility instead of (...)
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        // 1. Multipart Chunk Handler
        register_rest_route(
            StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
            '/upload-chunk',
            [
                'methods'             => 'POST', // or WP_REST_Server::CREATABLE
                'callback'            => [$this->submission_handler, 'handle_upload_chunk_rest_multipart'], // FIX: Array syntax
                'permission_callback' => function () { return current_user_can('upload_files'); },
            ]
        );

        // 2. Fallback Handler (Direct POST)
        register_rest_route(
            StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
            '/upload-fallback',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_fallback_upload'], // FIX: Array syntax
                'permission_callback' => function () { return current_user_can('upload_files'); },
            ]
        );

        // 3. Legacy Base64
        register_rest_route(
            StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
            '/upload-chunk-legacy',
            [
                'methods'             => 'POST',
                'callback'            => [$this->submission_handler, 'handle_upload_chunk_rest_base64'], // FIX: Array syntax
                'permission_callback' => function () { return current_user_can('upload_files'); },
            ]
        );

        // 4. Status Check
        register_rest_route(
            StarmusSubmissionHandler::STARMUS_REST_NAMESPACE,
            '/status/(?P<id>\d+)',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'handle_status'], // FIX: Array syntax
                'permission_callback' => function () { return current_user_can('upload_files'); },
                'args'                => [
                    'id' => [
                        'validate_callback' => 'is_numeric',
                    ],
                ],
            ]
        );
    }

    public function handle_fallback_upload(WP_REST_Request $request): ?WP_REST_Response // Return type loosened for error handling
    {
        try {
            // Log incoming payload
            $files = $request->get_file_params();
            StarmusLogger::debug('StarmusRESTHandler', 'Fallback upload request', [
                'files'  => array_keys($files), // Log keys only for security
            ]);

            // Defensive file-key handling
            $file = $files['audio_file'] ?? ($files['file'] ?? null);
            if (! $file || ! \is_array($file) || empty($file['tmp_name'])) {
                return new \WP_REST_Response([ // Explicit Response object for errors
                    'code' => 'server_error',
                    'message' => __('Missing or invalid file upload data.', 'starmus-audio-recorder'),
                    'data' => ['status' => 400]
                ], 400);
            }

            // Pass request downstream
            $result = $this->submission_handler->handle_fallback_upload_rest($request);

            if (is_wp_error($result)) {
                return new \WP_REST_Response([
                    'code' => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                    'data' => ['status' => 500]
                ], 500);
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
        } catch (\Throwable $throwable) {
            error_log($throwable->getMessage());
            return new \WP_REST_Response([
                'code' => 'server_error',
                'message' => __('Upload failed. Please try again later.', 'starmus-audio-recorder'),
                'data' => ['status' => 500]
            ], 500);
        }
    }

    public function handle_status(WP_REST_Request $request): WP_REST_Response
    {
        $post_id = (int) $request['id'];

        try {
            $post_info = $this->dal->get_post_info($post_id);
            if (! $post_info) {
                return new WP_REST_Response(['code' => 'not_found', 'message' => 'Submission not found'], 404);
            }

            if ($post_info['type'] !== $this->submission_handler->get_cpt_slug()) {
                return new WP_REST_Response(['code' => 'invalid_type', 'message' => 'Not an audio recording'], 403);
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
            return new WP_REST_Response(['code' => 'server_error', 'message' => 'Could not fetch status'], 500);
        }
    }
}