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
use Starmus\frontend\StarmusSubmissionHandler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function register_rest_route;
use function is_wp_error;
use function get_post_status;
use function get_post_type;

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
     * @param StarmusSettings            $settings            Plugin configuration wrapper.
     * @param StarmusSubmissionHandler|null $submission_handler Optional prebuilt submission handler.
     */
    public function __construct(StarmusSettings $settings, ?StarmusSubmissionHandler $submission_handler = null)
    {
        $this->submission_handler = $submission_handler ?? new StarmusSubmissionHandler($settings);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes.
     */
    public function register_routes(): void
    {
        register_rest_route(
            StarmusSubmissionHandler::STAR_REST_NAMESPACE,
            '/upload-fallback',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_fallback_upload'],
                'permission_callback' => static fn() => \current_user_can('upload_files'),
            ]
        );

        register_rest_route(
            StarmusSubmissionHandler::STAR_REST_NAMESPACE,
            '/upload-chunk',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_chunk_upload'],
                'permission_callback' => static fn() => \current_user_can('upload_files'),
            ]
        );

        register_rest_route(
            StarmusSubmissionHandler::STAR_REST_NAMESPACE,
            '/status/(?P<id>\d+)',
            [
                'methods' => 'GET',
                'callback' => [$this, 'handle_status'],
                'permission_callback' => static fn() => \current_user_can('upload_files'),
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
     * @throws \Throwable
     */
    public function handle_fallback_upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $result = $this->submission_handler->handle_fallback_upload_rest($request);

            if (is_wp_error($result)) {
                return $result;
            }

            // Fire a hook for post-upload processing (e.g. schedule cron pipeline).
            do_action('starmus_submission_complete', $result['attachment_id'], $result['post_id']);

            return new WP_REST_Response([
                'success' => true,
                'data' => $result,
            ], 200);
        } catch (\Throwable $e) {
            StarmusLogger::error('RestHandler:fallback', $e);
            return new WP_Error('server_error', 'Upload failed', ['status' => 500]);
        }
    }

    /**
     * Handle chunked uploads.
     *
     * @throws \Throwable
     */
    public function handle_chunk_upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $result = $this->submission_handler->handle_upload_chunk_rest($request);

            if (is_wp_error($result)) {
                return $result;
            }

            // If the result has attachment_id, assume finalization and fire the hook.
            if (!empty($result['attachment_id']) && !empty($result['post_id'])) {
                do_action('starmus_submission_complete', $result['attachment_id'], $result['post_id']);
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => $result,
            ], 200);
        } catch (\Throwable $e) {
            StarmusLogger::error('RestHandler:chunk', $e);
            return new WP_Error('server_error', 'Chunk upload failed', ['status' => 500]);
        }
    }

    /**
     * Handle status check for a submission.
     *
     * @throws \Throwable
     */
    public function handle_status(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post_id = (int) $request['id'];

        try {
            $status = get_post_status($post_id);

            if (!$status) {
                return new WP_Error('not_found', 'Submission not found', ['status' => 404]);
            }

            // Ensure it's the correct CPT
            $post_type = get_post_type($post_id);
            if ($post_type !== $this->submission_handler->settings?->get('cpt_slug', 'audio-recording')) {
                return new WP_Error('invalid_type', 'Not an audio recording', ['status' => 403]);
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'id' => $post_id,
                    'status' => $status,
                ],
            ], 200);
        } catch (\Throwable $e) {
            StarmusLogger::error('RestHandler:status', $e);
            return new WP_Error('server_error', 'Could not fetch status', ['status' => 500]);
        }
    }
}
