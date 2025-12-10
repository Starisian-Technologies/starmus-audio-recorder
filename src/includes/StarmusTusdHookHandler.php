<?php

/**
 * PSR-4 compliant class to handle webhooks from a tusd server.
 *
 * @package   Starisian\Sparxstar\Starmus\includes
 * @version   0.9.3-PHP7-COMPAT
 */
namespace Starisian\Sparxstar\Starmus\includes;

if (! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * StarmusTusdHookHandler Class
 */
class StarmusTusdHookHandler
{
    protected string $namespace = 'starmus/v1';
    protected string $rest_base = 'hook';

    /**
     * @var StarmusSubmissionHandler
     */
    private $submission_handler;

    /**
     * Constructor.
     * FIX: Removed 'readonly' for PHP < 8.2 compatibility.
     * 
     * @param StarmusSubmissionHandler $submission_handler
     */
    public function __construct(StarmusSubmissionHandler $submission_handler) {
        $this->submission_handler = $submission_handler;
    }

    /**
     * Registers the WordPress hooks.
     */
    public function register_hooks(): void
    {
        // FIX: Use array syntax for PHP < 8.1 compatibility
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Registers the custom REST API route.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handle_tusd_hook'],      // FIX: Array syntax
                    'permission_callback' => [$this, 'permissions_check'],     // FIX: Array syntax
                ],
            ]
        );
    }

    /**
     * Primary callback for the REST route.
     */
    public function handle_tusd_hook(WP_REST_Request $request) // Removed union return type hint for PHP 7.4
    {
        $data = $request->get_json_params();

        if (empty($data['Type']) || empty($data['Event'])) {
            return new WP_Error('invalid_payload', 'Invalid payload.', ['status' => 400]);
        }

        if (\defined('WP_DEBUG') && WP_DEBUG) {
            StarmusLogger::debug('StarmusTusdHookHandler', 'Received payload', ['type' => $data['Type']]);
        }

        $event_type = $data['Type'];
        do_action('starmus_tusd_event_' . $event_type, $data['Event']);

        if ($event_type === 'post-finish') {
            if (empty($data['Event']['Upload'])) {
                return new WP_Error('invalid_post_finish_payload', 'Missing Upload data.', ['status' => 400]);
            }

            $result = $this->process_completed_upload($data['Event']['Upload']);

            if (is_wp_error($result)) {
                error_log($result->get_error_message());
                return $result;
            }

            return new WP_REST_Response(
                [
                    'status'        => 'success',
                    'message'       => 'Upload processed successfully.',
                    'attachment_id' => $result['attachment_id'] ?? null,
                ],
                200
            );
        }

        return new WP_REST_Response(['status' => 'success', 'message' => 'Hook acknowledged.'], 200);
    }

    /**
     * Delegates processing to submission handler.
     */
    private function process_completed_upload(array $upload_info)
    {
        $temp_path = $upload_info['Storage']['Path'] ?? '';
        $metadata  = $upload_info['MetaData']        ?? [];

        $sanitized_form_data = $this->submission_handler->sanitize_submission_data($metadata);
        $result              = $this->submission_handler->process_completed_file($temp_path, $sanitized_form_data);

        // Cleanup TUS info file
        if (file_exists($temp_path . '.info')) {
            @wp_delete_file($temp_path . '.info');
        }

        return $result;
    }

    /**
     * Permission check.
     */
    public function permissions_check(WP_REST_Request $request)
    {
        $expected_secret = \defined('TUSD_WEBHOOK_SECRET') ? TUSD_WEBHOOK_SECRET : null;

        if (empty($expected_secret)) {
            error_log('TUSD_WEBHOOK_SECRET missing in wp-config.php');
            return new WP_Error('unauthorized', 'Configuration error.', ['status' => 500]);
        }

        $provided_secret = trim((string) $request->get_header('x-starmus-secret'));

        if ($provided_secret === '' || $provided_secret === '0') {
            return new WP_Error('unauthorized', 'Missing secret header.', ['status' => 403]);
        }

        if (! hash_equals($expected_secret, $provided_secret)) {
            return new WP_Error('unauthorized', 'Invalid secret.', ['status' => 403]);
        }

        return true;
    }
}