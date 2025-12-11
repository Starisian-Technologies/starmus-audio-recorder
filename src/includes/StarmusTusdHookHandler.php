<?php
declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\includes;

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\includes\StarmusSubmissionHandler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StarmusTusdHookHandler {

    protected string $namespace = 'starmus/v1';
    protected string $rest_base = 'hook';

    public function __construct(
        private readonly StarmusSubmissionHandler $submission_handler
    ) {}

    public function register_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'handle_tusd_hook' ],
                    'permission_callback' => [ $this, 'permissions_check' ],
                    'args'                => [
                        'Type'  => [
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

    public function handle_tusd_hook( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $json_params = $request->get_json_params();

        // 1. Validate JSON Body exists (tusd sends JSON)
        if ( empty( $json_params ) || ! is_array( $json_params ) ) {
            return new WP_Error( 'invalid_json', 'Invalid JSON body.', [ 'status' => 400 ] );
        }

        $event_type = sanitize_key( $json_params['Type'] ?? '' );
        $event_data = $json_params['Event'] ?? [];

        if ( empty( $event_type ) || empty( $event_data ) ) {
            return new WP_Error( 'invalid_payload', 'Invalid payload.', [ 'status' => 400 ] );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            StarmusLogger::debug( 'StarmusTusdHookHandler', 'Received payload', [ 'type' => $event_type ] );
        }

        do_action( "starmus_tusd_event_{$event_type}", $event_data );

        // 2. TUSD requires Content-Type: application/json in the response. 
        // WP_REST_Response handles this automatically.
        return match ( $event_type ) {
            'post-finish' => $this->handle_post_finish( $event_data ),
            default       => new WP_REST_Response( [], 200 ), // Return empty JSON object {}
        };
    }

    private function handle_post_finish( array $event_data ): WP_REST_Response|WP_Error {
        if ( empty( $event_data['Upload'] ) ) {
            return new WP_Error( 'invalid_post_finish_payload', 'Missing Upload data.', [ 'status' => 400 ] );
        }

        $result = $this->process_completed_upload( $event_data['Upload'] );

        if ( is_wp_error( $result ) ) {
            // Note: tusd will verify this is a non-2xx error and log it, but 
            // the client will not see this error message directly.
            StarmusLogger::error( 'StarmusTusdHookHandler', 'Upload processing failed', [ 'error' => $result->get_error_message() ] );
            return $result;
        }

        // 3. IMPORTANT: post-finish is "fire and forget". 
        // The client DOES NOT receive this response body. 
        // We return an empty JSON object to satisfy tusd's requirement for a hook response.
        return new WP_REST_Response( [], 200 );
    }

    private function process_completed_upload( array $upload_info ): mixed {
        $temp_path = $upload_info['Storage']['Path'] ?? '';
        
        // 4. Use the InfoPath provided by tusd, falling back to concatenation only if missing
        $info_path = $upload_info['Storage']['InfoPath'] ?? ( $temp_path . '.info' );
        
        $metadata  = $upload_info['MetaData'] ?? [];

        $sanitized_form_data = $this->submission_handler->sanitize_submission_data( $metadata );
        
        // Assuming this function moves the file from $temp_path to a permanent location
        $result = $this->submission_handler->process_completed_file( $temp_path, $sanitized_form_data );

        // VIP SECURITY: Path Traversal Check for the Info File deletion
        $upload_dir = wp_get_upload_dir();
        $basedir    = wp_normalize_path( $upload_dir['basedir'] );
        $normalized_info_path = wp_normalize_path( $info_path );

        if ( file_exists( $normalized_info_path ) && str_starts_with( $normalized_info_path, $basedir ) ) {
            if ( ! unlink( $normalized_info_path ) ) {
                 StarmusLogger::warning( 'StarmusTusdHookHandler', 'Failed to delete temp info file', [ 'path' => $normalized_info_path ] );
            }
        } elseif ( file_exists( $normalized_info_path ) ) {
            StarmusLogger::warning( 'StarmusTusdHookHandler', 'Security: Attempted deletion outside uploads', [ 'path' => $normalized_info_path ] );
        }

        return $result;
    }

    public function permissions_check( WP_REST_Request $request ): true|WP_Error {
        $expected_secret = defined( 'TUSD_WEBHOOK_SECRET' ) ? TUSD_WEBHOOK_SECRET : '';

        if ( empty( $expected_secret ) ) {
            StarmusLogger::error( 'StarmusTusdHookHandler', 'TUSD_WEBHOOK_SECRET missing in configuration.' );
            return new WP_Error( 'internal_server_error', 'Internal Service Error', [ 'status' => 500 ] );
        }

        // 5. Ensure tusd is started with -hooks-http-forward-headers x-starmus-secret
        $provided_secret = trim( (string) $request->get_header( 'x-starmus-secret' ) );

        if ( '' === $provided_secret || '0' === $provided_secret ) {
            return new WP_Error( 'unauthorized', 'Missing secret header.', [ 'status' => 403 ] );
        }

        if ( ! hash_equals( $expected_secret, $provided_secret ) ) {
            return new WP_Error( 'unauthorized', 'Invalid secret.', [ 'status' => 403 ] );
        }

        return true;
    }
}