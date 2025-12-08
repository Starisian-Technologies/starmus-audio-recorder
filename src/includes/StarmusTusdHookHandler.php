<?php

/**
 * PSR-4 compliant class to handle webhooks from a tusd server.
 *
 * This class is responsible for receiving and validating tusd webhook calls
 * and then passing the data to the appropriate handler for processing.
 *
 * @package   Starisian\Sparxstar\Starmus\includes
 *
 * @version 0.9.2
 */
namespace Starisian\Sparxstar\Starmus\includes;

// Exit if accessed directly.
if (! \defined('ABSPATH')) {
    exit;
}

// Import dependencies from the same namespace and WordPress core.
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * StarmusTusdHookHandler Class
 *
 * This class is solely responsible for the REST API endpoint that listens for tusd webhooks.
 * It validates the request and delegates the payload to a dedicated submission handler.
 */
class StarmusTusdHookHandler
{
    /**
     * REST API namespace.
     *
     * @var string
     */
    protected string $namespace = 'starmus/v1';

    /**
     * REST API base route.
     *
     * @var string
     */
    protected string $rest_base = 'hook';

    /**
     * Constructor. Injects the submission handler dependency.
     *
     * @param StarmusSubmissionHandler $submission_handler An instance of the class that processes uploads.
     */
    public function __construct(
        /**
         * A dedicated handler for processing the submission data.
         */
        private readonly StarmusSubmissionHandler $submission_handler
    ) {
    }

    /**
     * Registers the WordPress hooks. This is the entry point for the class.
     */
    public function register_hooks(): void
    {
        // The REST API routes MUST be registered on the 'rest_api_init' hook.
        add_action('rest_api_init', $this->register_routes(...));
    }

    /**
     * Registers the custom REST API route for the tusd webhook.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE, // Corresponds to POST
                    'callback'            => $this->handle_tusd_hook(...),
                    'permission_callback' => $this->permissions_check(...),
                ],
            ]
        );
    }

    /**
     * Primary callback for the REST route. It receives the webhook request.
     *
     * @param WP_REST_Request $request The incoming REST request object.
     *
     * @return WP_REST_Response|WP_Error A success response or an error object.
     */
    public function handle_tusd_hook(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $data = $request->get_json_params();

        if (empty($data['Type']) || empty($data['Event'])) {
            return new WP_Error('invalid_payload', 'Invalid or empty payload from tusd.', ['status' => 400]);
        }

        if (\defined('WP_DEBUG') && WP_DEBUG) {
            StarmusLogger::debug('StarmusTusdHookHandler', 'Received payload', ['type' => $data['Type'], 'payload' => wp_json_encode($data['Type'])]);
        }

        // Optional Improvement: Allow other classes to hook into any tusd event.
        $event_type = $data['Type'];
        do_action('starmus_tusd_event_' . $event_type, $data['Event']);

        // We only perform file processing on the 'post-finish' event.
        if ($event_type === 'post-finish') {
            if (empty($data['Event']['Upload'])) {
                return new WP_Error('invalid_post_finish_payload', 'post-finish event is missing Upload data.', ['status' => 400]);
            }

            $result = $this->process_completed_upload($data['Event']['Upload']);

            if (is_wp_error($result)) {
                error_log($result->get_error_message());
                return $result;
            }

            // Refinement: Return a more explicit success response with the attachment ID.
            return new WP_REST_Response(
                [
                    'status'        => 'success',
                    'message'       => 'Upload processed successfully.',
                    'attachment_id' => $result['attachment_id'] ?? null,
                ],
                200
            );
        }

        // For all other hook types, acknowledge receipt without processing.
        return new WP_REST_Response(
            [
                'status'  => 'success',
                'message' => 'Hook received and acknowledged.',
            ],
            200
        );
    }

    /**
     * Delegates the processing of the completed upload to the submission handler.
     *
     * @param array $upload_info The 'Upload' object from the tusd hook payload.
     *
     * @return array|WP_Error The result from the submission handler, or a WP_Error.
     */
    private function process_completed_upload(array $upload_info): array|WP_Error
    {
        $temp_path = $upload_info['Storage']['Path'] ?? '';
        $metadata  = $upload_info['MetaData']        ?? [];

        $sanitized_form_data = $this->submission_handler->sanitize_submission_data($metadata);
        $result              = $this->submission_handler->process_completed_file($temp_path, $sanitized_form_data);

        if (file_exists($temp_path . '.info')) {
            wp_delete_file($temp_path . '.info');
        }

        return $result;
    }

    /**
     * Permission check for the REST API endpoint. Hardened for production.
     *
     * @param WP_REST_Request $request The incoming REST request object.
     *
     * @return bool|WP_Error True if the request is permitted, otherwise a WP_Error.
     */
    public function permissions_check(WP_REST_Request $request): bool|WP_Error
    {
        $expected_secret = \defined('TUSD_WEBHOOK_SECRET') ? TUSD_WEBHOOK_SECRET : null;

        if (empty($expected_secret)) {
            error_log('TUSD_WEBHOOK_SECRET is not defined in wp-config.php');
            return new WP_Error('unauthorized', 'Endpoint not configured.', ['status' => 500]);
        }

        // Refinement: Harden security logic.
        $provided_secret = trim((string) $request->get_header('x-starmus-secret'));

        if ($provided_secret === '' || $provided_secret === '0') {
            StarmusLogger::warning('StarmusTusdHookHandler', 'Missing secret header in TUSD webhook request');
            return new WP_Error('unauthorized', 'Missing secret header.', ['status' => 403]);
        }

        if (! hash_equals($expected_secret, $provided_secret)) {
            StarmusLogger::warning('StarmusTusdHookHandler', 'Invalid secret provided in TUSD webhook request');
            return new WP_Error('unauthorized', 'Invalid secret.', ['status' => 403]);
        }

        return true;
    }
}
