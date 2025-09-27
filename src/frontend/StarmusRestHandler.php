<?php

/**
 * REST handler bridging HTTP endpoints to submission services.
 *
 * @package   Starmus
 */

namespace Starmus\frontend;

if (!defined('ABSPATH')) {
    exit;
}

use Starmus\includes\StarmusSettings;
use Starmus\helpers\StarmusLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes WordPress REST API routes for audio submissions.
 *
 * Routes map directly to {@see StarmusSubmissionHandler} to keep logic in one place.
 */
class StarmusRestHandler
{
    /**
     * Submission service that performs all validation and storage work.
     */
    private StarmusSubmissionHandler $submission_handler;

    /**
     * Build the handler and register the REST routes during boot.
     *
     * @param StarmusSettings $settings Plugin configuration wrapper.
     */
    public function __construct(StarmusSettings $settings)
    {
        $this->submission_handler = new StarmusSubmissionHandler($settings);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(
            StarmusSubmissionHandler::STAR_REST_NAMESPACE,
            '/upload-fallback',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_fallback_upload'],
                'permission_callback' => static fn() => current_user_can('upload_files'),
            ]
        );

        register_rest_route(
            StarmusSubmissionHandler::STAR_REST_NAMESPACE,
            '/upload-chunk',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_chunk_upload'],
                'permission_callback' => static fn() => current_user_can('upload_files'),
            ]
        );

        register_rest_route(
            StarmusSubmissionHandler::STAR_REST_NAMESPACE,
            '/status/(?P<id>\d+)',
            [
                'methods' => 'GET',
                'callback' => [$this, 'handle_status'],
                'permission_callback' => static fn() => current_user_can('upload_files'),
                'args' => [
                    'id' => [
                        'validate_callback' => 'is_numeric',
                    ],
                ],
            ]
        );
    }

    /**
     * Handle fallback form-based upload.
     *
     * @param WP_REST_Request $request REST request containing the form payload.
     *
     * @return WP_REST_Response|WP_Error REST response or error wrapper.
     */
    public function handle_fallback_upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $result = $this->submission_handler->handle_fallback_upload_rest($request);

            if (is_wp_error($result)) {
                return $result;
            }

            return new WP_REST_Response(['success' => true, 'data' => $result], 200);
        } catch (\Throwable $e) {
            StarmusLogger::log('RestHandler:fallback', $e);
            return new WP_Error('server_error', 'Upload failed', ['status' => 500]);
        }
    }

    /**
     * Handle chunked uploads.
     *
     * @param WP_REST_Request $request REST request containing chunk data.
     *
     * @return WP_REST_Response|WP_Error REST response or error wrapper.
     */
    public function handle_chunk_upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $result = $this->submission_handler->handle_upload_chunk_rest($request);

            if (is_wp_error($result)) {
                return $result;
            }

            return new WP_REST_Response(['success' => true, 'data' => $result], 200);
        } catch (\Throwable $e) {
            StarmusLogger::log('RestHandler:chunk', $e);
            return new WP_Error('server_error', 'Chunk upload failed', ['status' => 500]);
        }
    }

    /**
     * Handle status check for a submission.
     *
     * @param WP_REST_Request $request REST request containing the submission ID.
     *
     * @return WP_REST_Response|WP_Error REST response or error wrapper.
     */
    public function handle_status(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post_id = (int) $request['id'];

        try {
            $status = get_post_status($post_id);

            if (!$status) {
                return new WP_Error('not_found', 'Submission not found', ['status' => 404]);
            }

            return new WP_REST_Response(
                [
                    'success' => true,
                    'data' => [
                        'id' => $post_id,
                        'status' => $status,
                    ],
                ],
                200
            );
        } catch (\Throwable $e) {
            StarmusLogger::log('RestHandler:status', $e);
            return new WP_Error('server_error', 'Could not fetch status', ['status' => 500]);
        }
    }
}
