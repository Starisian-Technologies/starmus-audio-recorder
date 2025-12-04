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
 * Exposes WordPress REST API routes for audio submissions.
 */
final readonly class StarmusRESTHandler
{
    private StarmusSubmissionHandler $submission_handler;

    /**
     * Constructor.
     *
     * @param StarmusAudioRecorderDALInterface $dal The data access layer.
     * @param StarmusSettings $settings The settings instance.
     * @param StarmusSubmissionHandler|null $submission_handler Optional submission handler.
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
     * Register REST API routes.
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
     * Handle fallback form-based upload.
     *
     * @phpstan-param WP_REST_Request<array<string,mixed>> $request
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

            // CRITICAL FIX START: Safely handle if $result is an array (expected) or a WP_REST_Response object (current error cause).
            $response_data = $result;
            if ($result instanceof WP_REST_Response) {
                // If the submission handler returned an object, get its data array.
                $response_data = $result->get_data();
            }

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
     * Handle chunked uploads.
     *
     * @phpstan-param WP_REST_Request<array<string,mixed>> $request
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
     * Handle status check for a submission.
     *
     * @phpstan-param WP_REST_Request<array<string,mixed>> $request
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
