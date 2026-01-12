<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\api;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Class StarmusDataRESTHandler
 *
 * Handles REST API retrieval endpoints for Starmus Audio Recorder.
 * Implements Async Data Loading to prevent DOM-based memory exhaustion.
 *
 * @package Starisian\Sparxstar\Starmus\api
 */
class StarmusDataRESTHandler
{
    /**
     * Namespace for the API.
     */
    private const NAMESPACE = 'star-starmus/v1';

    /**
     * Initialize the class and register routes.
     */
    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes.
     */
    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/recording/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_recording_data'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'id' => [
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    },
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Check permissions for reading recording data.
     *
     * @param WP_REST_Request $request The request object.
     * @return bool|WP_Error True if authorized, WP_Error otherwise.
     */
    public function check_permission(WP_REST_Request $request)
    {
        $post_id = $request->get_param('id');

        if ( ! current_user_can('edit_post', $post_id)) {
            return new WP_Error(
                'rest_forbidden',
                __('Sorry, you are not allowed to view this recording.', 'starmus-audio-recorder'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Get recording data (Waveform and Transcription).
     *
     * This endpoint is used to async load heavy JSON blobs that would otherwise
     * crash the DOM if injected via wp_localize_script.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function get_recording_data(WP_REST_Request $request)
    {
        $post_id = $request->get_param('id');
        $post    = get_post($post_id);

        if ( ! $post || $post->post_type !== 'audio-recording') {
            return new WP_Error(
                'rest_post_invalid',
                __('Invalid recording ID.', 'starmus-audio-recorder'),
                ['status' => 404]
            );
        }

        // Fetch using raw meta for performance and bypass ACF overhead
        // which might try to format the value (and crash memory)
        $waveform_json      = get_post_meta($post_id, 'starmus_waveform_json', true);
        $transcription_json = get_post_meta($post_id, 'starmus_transcription_json', true);
        $fingerprint        = get_post_meta($post_id, 'starmus_fingerprint_json', true);

        $data = [
            'id'            => $post_id,
            'waveform'      => [],
            'transcription' => [],
            'fingerprint'   => $fingerprint,
        ];

        // Process Waveform
        if ( ! empty($waveform_json)) {
            if (is_string($waveform_json)) {
                $decoded = json_decode($waveform_json, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data['waveform'] = $decoded;
                }
            } elseif (is_array($waveform_json)) {
                $data['waveform'] = $waveform_json;
            }
        }

        // Process Transcription
        if ( ! empty($transcription_json)) {
            if (is_string($transcription_json)) {
                $decoded = json_decode($transcription_json, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data['transcription'] = $decoded;
                }
            } elseif (is_array($transcription_json)) {
                $data['transcription'] = $transcription_json;
            }
        }

        return new WP_REST_Response($data, 200);
    }
}
