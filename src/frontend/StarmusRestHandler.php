<?php
namespace Starmus\frontend;

if (!defined('ABSPATH')) {
    exit;
}

use Starmus\includes\StarmusSettings;
use Starmus\helpers\StarmusLogger;
use Starmus\helpers\StarmusSanitizer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API handler for Starmus audio submissions.
 *
 * Handles fallback uploads, chunked uploads, and submission status checks.
 */
class StarmusRestHandler
{

    private StarmusSubmissionHandler $submission_handler;

    public function __construct(StarmusSettings $settings)
    {
        $this->submission_handler = new StarmusSubmissionHandler($settings);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes.
     */
    public function register_routes(): void
    {
        register_rest_route(
            'starmus/v1',
            '/upload-fallback',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_fallback_upload'],
                'permission_callback' => fn() => is_user_logged_in(),
            ]
        );

        register_rest_route(
            'starmus/v1',
            '/upload-chunk',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_chunk_upload'],
                'permission_callback' => fn() => is_user_logged_in(),
            ]
        );

        register_rest_route(
            'starmus/v1',
            '/status/(?P<id>\d+)',
            [
                'methods' => 'GET',
                'callback' => [$this, 'handle_status'],
                'permission_callback' => fn() => is_user_logged_in(),
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
     */
    public function handle_fallback_upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $files_data = $request->get_file_params();
            $form_data = StarmusSanitizer::sanitize_submission_data($request->get_params());

            $result = $this->submission_handler->process_fallback_upload($files_data, $form_data);

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
     */
    public function handle_chunk_upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $files_data = $request->get_file_params();
            $form_data = StarmusSanitizer::sanitize_submission_data($request->get_params());

            $result = $this->submission_handler->process_chunk_upload($files_data, $form_data);

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
