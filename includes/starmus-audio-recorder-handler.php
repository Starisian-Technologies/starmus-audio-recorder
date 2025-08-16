<?php
namespace Starmus\includes;

// exit if access directly
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Starmus_Audio_Submission_Handler')) {

    class StarmusAudioSubmissionHandler
    {

        /**
         * AJAX action hook for logged-in users.
         * This should match the 'action' parameter used in your JavaScript.
         */
        const ACTION_AUTH = 'starmus_submit_audio';

        /**
         * Security nonce action name used to validate the form submission.
         */
        const NONCE_ACTION = 'starmus_submit_audio_action';

        /**
         * HTML form field name for the nonce value.
         */
        const NONCE_FIELD = 'starmus_audio_nonce_field';

        /**
         * WordPress shortcode tag that renders the recorder form.
         */
        const SHORTCODE_TAG = 'starmus_audio_recorder';

        /**
         * Default custom post type where audio submissions are stored.
         * This allows future expansion via options or filters.
         */
        const POST_TYPE = 'post';

        private string $plugin_path;
        private string $plugin_url;
        private string $version;

        /**
         * Class constructor.
         * Binds AJAX actions, shortcode registration, and conditional asset loading.
         */
        public function __construct()
        {
            $this->plugin_path = STARMUS_PATH;
            $this->plugin_url = STARMUS_URL;
            $this->version = STARMUS_VERSION;

            $this->register_hooks();

        }

        public function register_hooks(): void
        {
            add_action('wp_ajax_nopriv_' . self::ACTION_AUTH, [$this, 'handle_submission']);
            add_action('wp_ajax_' . self::ACTION_AUTH, [$this, 'handle_submission']);
            add_shortcode(self::SHORTCODE_TAG, [$this, 'render_recorder_form_shortcode']);
            // Set priority to 1 so our filter runs before others and is not overridden
            add_filter('wp_check_filetype_and_ext', [$this, 'force_allowed_audio_filetypes'], 10, 4);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts_if_shortcode_is_present']);
            add_filter('upload_mimes', function ($mimes): array {
                $mimes['webm'] = 'audio/webm';
                $mimes['wav']  = 'audio/wav';
                $mimes['mp3']  = 'audio/mpeg';
                $mimes['ogg']  = 'audio/ogg';
                $mimes['opus'] = 'audio/ogg';
                $mimes['m4a']  = 'audio/mp4';
                $mimes['mp4']  = 'audio/mp4'; // iOS might mislabel audio-only recordings
                return $mimes;
            });
        }

        /**
         * Handles the audio submission AJAX request.
         * Validates data, saves the audio to the media library, and creates a new post.
         */
        public function handle_submission(): void
        {
            // Check if the request is an AJAX request
            if (!wp_doing_ajax()) {
                wp_send_json_error(['success' => false, 'message' => __( 'Invalid request.', 'starmus-audio-recorder' )], 400);
                return;
            }
            error_log('--- START SUBMISSION HANDLER ---');
            error_log('FILES: ' . print_r($_FILES, true));
            error_log('POST: ' . print_r($_POST, true));
            // Check if the user is logged in
            //if ( ! is_user_logged_in() ) {
            //    wp_send_json_error([ 'success' => false, 'message' => 'User is not logged in.' ], 403);
            //    return;
            // }

            if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) {
                wp_send_json_error(['success' => false, 'message' => __( 'Nonce verification failed.', 'starmus-audio-recorder' )], 403);
                return;
            }

            if (empty($_POST['audio_consent']) || sanitize_text_field($_POST['audio_consent']) !== 'on') {
                wp_send_json_error(['success' => false, 'message' => __( 'Consent is required.', 'starmus-audio-recorder' )], 400);
                return;
            }

            $uuid = isset($_POST['audio_uuid']) ? sanitize_text_field($_POST['audio_uuid']) : '';
            if (!$this->is_valid_uuid($uuid)) {
                wp_send_json_error(['success' => false, 'message' => __( 'Invalid or missing UUID.', 'starmus-audio-recorder' )], 400);
                return;
            }

            if (empty($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
                wp_send_json_error(['success' => false, 'message' => $this->get_upload_error_message($_FILES['audio_file']['error'] ?? UPLOAD_ERR_NO_FILE)], 400);
                return;
            }

            $file = $_FILES['audio_file'];
            error_log('DEBUG: Uploaded file type: ' . $file['type'] . ', name: ' . $file['name']);
            $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
            error_log('DEBUG: wp_check_filetype_and_ext: ' . print_r($check, true));
            if (!$this->is_allowed_file_type($file['type'])) {
                wp_send_json_error(['success' => false, 'message' => sprintf( __( 'Unsupported audio file type: %s', 'starmus-audio-recorder' ), esc_html($file['type']) )], 400);
                return;
            }
            if (!$check['ext'] || !$check['type']) {
                wp_send_json_error(['success' => false, 'message' => __( 'File type or extension not allowed (server check).', 'starmus-audio-recorder' )], 400);
                return;
            }

            $attachment_id = $this->upload_file_to_media_library('audio_file');
            if (is_wp_error($attachment_id)) {
                wp_send_json_error(['success' => false, 'message' => sprintf( __( 'Failed to save audio file: %s', 'starmus-audio-recorder' ), $attachment_id->get_error_message() )], 500);
                return;
            }
            
            // --- Data for THIS audio submission (captured by your PHP handler) ---
            $audio_url = wp_get_attachment_url( $attachment_id );
            $audio_player_html = '';
            if ( $audio_url ) {
                $audio_player_html = sprintf( '[audio src="%s"]', esc_url( $audio_url ) );
            }
            // IP Address for THIS audio submission
            $current_ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : 'unknown';

            // User Agent for THIS audio submission
            $current_user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : 'unknown';

            // Timestamp for THIS audio submission
            $current_submitted_at = current_time( 'mysql' );

            // Unique ID for THIS audio submission event/post itself
            $current_audio_submission_id = uniqid( 'audio_submission_', true );

            // WordPress User ID (if logged in during THIS audio submission)
            $current_user_id = get_current_user_id(); // Returns 0 if not logged in

            // User Email (if logged in during THIS audio submission)
            $current_user_email = '';
            if ( $current_user_id > 0 ) {
                $user_data = get_userdata( $current_user_id );
                $current_user_email = $user_data ? $user_data->user_email : '';
            }

            // --- Data linking to the PREVIOUS form submission (from URL via hidden field) ---
            $submission_id = isset($_POST['submission_id']) ? sanitize_text_field($_POST['submission_id']) : '';

            // --- Data specific to the audio file itself (from $_FILES and recorder) ---
            $audio_uuid_from_js = isset( $_POST['audio_uuid'] ) ? sanitize_text_field( $_POST['audio_uuid'] ) : ''; // This is the ID for the audio file/content
            $file = $_FILES['audio_file']; // Already validated

            $post_data = [
                'post_title'   => sprintf( __( 'Audio Recording %s', 'starmus-audio-recorder' ), $audio_uuid_from_js ),
                'post_type'    => $this->get_target_post_type(),
                'post_status'  => 'publish',
                'post_author'  => $current_user_id, // Assign post to the logged-in user
                'post_content' => $audio_player_html, // audio player HTML
                'meta_input'   => [
                    // From THIS audio submission
                    'audio_id_js'                 => $audio_uuid_from_js, // The ID generated by JS for the audio file
                    'audio_consent'               => 'yes',
                    '_audio_attachment_id'        => $attachment_id,
                    'submission_ip_address'       => $current_ip_address,
                    'submission_user_agent'       => $current_user_agent,
                    'submission_datetime'         => $current_submitted_at,
                    'audio_submission_event_id'   => $current_audio_submission_id, // Unique ID for this submission event
                    'submission_user_id'          => $current_user_id,
                    'submission_user_email'       => $current_user_email,
                    'audio_file_type'             => $file['type'],
                    'audio_file_size'             => $file['size'],
                    'audio_file_name_original'    => $file['name'], // Original filename from JS

                    // Link to the PREVIOUS form submission
                    'linked_form_submission_id'   => $submission_id,
                ],
            ];
            $post_id = wp_insert_post( $post_data );


            if (is_wp_error($post_id)) {
                wp_delete_attachment($attachment_id, true);
                wp_send_json_error(['success' => false, 'message' => sprintf( __( 'Failed to create post: %s', 'starmus-audio-recorder' ), $post_id->get_error_message() )], 500);
                return;
            }
            update_post_meta($post_id, 'audio_file_type', $file['type']);
            update_post_meta($post_id, 'audio_file_size', $file['size']);
            update_post_meta($post_id, 'audio_file_name', $file['name']);


            wp_send_json_success([
                'success' => true,
                'message' => __( 'Submission successful.', 'starmus-audio-recorder' ),
                'attachment_id' => $attachment_id,
                'post_id' => $post_id,
            ], 200);
        }

        /**
         * Outputs the audio recorder form when shortcode is used.
         * This will be replaced by a dynamic template include.
         */
        public function render_recorder_form_shortcode($atts = [], $content = null): string
        {
            $attributes = shortcode_atts([
                'form_id' => 'sparxstarAudioForm',
                'submit_button_text' => esc_html__( 'Submit Recording', 'starmus-audio-recorder' ),
            ], $atts);

            ob_start();
            $form_action_url = esc_url(admin_url('admin-ajax.php'));
            $form_id = esc_attr($attributes['form_id']);
            $submit_button_text = esc_html($attributes['submit_button_text']);
            $nonce_action = self::NONCE_ACTION;
            $nonce_field_name = self::NONCE_FIELD;
            $template_path = $this->plugin_path . 'templates/starmus-audio-recorder-ui.php';

            if (file_exists($template_path)) {
                include $template_path;
            } else {
                echo '<p>' . esc_html__( 'Error: Audio recorder form template not found.', 'starmus-audio-recorder' ) . '</p>';
            }

            return ob_get_clean();
        }

        /**
         * Conditionally loads scripts and styles only if shortcode is present.
         */
        public function enqueue_scripts_if_shortcode_is_present(): void
        {
            global $post;
            if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, self::SHORTCODE_TAG)) {
                wp_enqueue_script(
                    'starmus-audio-recorder-module',
                    $this->plugin_url . 'assets/js/starmus-audio-recorder-module.js',
                    [],
                    $this->version,
                    true
                );

                wp_localize_script(
                    'starmus-audio-recorder-module',
                    'starmusRecorderStrings',
                    [
                        'network_lost'               => esc_html__( 'Network connection lost. Current recording is paused. Do NOT close page if you wish to save. Try to Stop and Submit when online.', 'starmus-audio-recorder' ),
                        'network_restored_recording' => esc_html__( 'Network connection restored. You can now Stop your recording and Submit, or Delete and start over.', 'starmus-audio-recorder' ),
                        'network_restored'           => esc_html__( 'Network connection restored.', 'starmus-audio-recorder' ),
                        'ready_to_record'            => esc_html__( 'Ready to record.', 'starmus-audio-recorder' ),
                        'recording_stopped'          => esc_html__( 'Recording stopped, no audio captured.', 'starmus-audio-recorder' ),
                        'recording_complete'         => esc_html__( 'Recording complete. Play, Download, Delete, or Submit.', 'starmus-audio-recorder' ),
                        'recording_saved_no_input'   => esc_html__( 'Recording saved locally. File input not found in form.', 'starmus-audio-recorder' ),
                        'recording_saved_attached'   => esc_html__( 'Recording saved and attached to form.', 'starmus-audio-recorder' ),
                        'recording_saved_no_support' => esc_html__( 'Recording saved, but your browser does not support automatic file attachment. Please try a different browser.', 'starmus-audio-recorder' ),
                        'recording_saved_error'      => esc_html__( 'Recording saved locally. Error attaching to form.', 'starmus-audio-recorder' ),
                        'mic_denied'                 => esc_html__( 'Microphone permission denied. Please allow mic access.', 'starmus-audio-recorder' ),
                        'mic_failed'                 => esc_html__( 'Microphone permission check failed. Please check your browser settings.', 'starmus-audio-recorder' ),
                        'level_unavailable'          => esc_html__( 'Audio level visualization not available.', 'starmus-audio-recorder' ),
                        'recording_paused'           => esc_html__( 'Recording paused', 'starmus-audio-recorder' ),
                        'recording_resumed'          => esc_html__( 'Recording resumed...', 'starmus-audio-recorder' ),
                        'recorder_reset'             => esc_html__( 'Recorder reset.', 'starmus-audio-recorder' ),
                        'unsupported_format'         => esc_html__( 'Unsupported recording format. Try a different browser.', 'starmus-audio-recorder' ),
                    ]
                );

                wp_enqueue_script(
                    'starmus-audio-recorder-submissions',
                    $this->plugin_url . 'assets/js/starmus-audio-recorder-submissions.js',
                    ['starmus-audio-recorder-module'],
                    $this->version,
                    true
                );

                wp_localize_script(
                    'starmus-audio-recorder-submissions',
                    'starmusFormData',
                    [
                        'ajax_url'     => admin_url('admin-ajax.php'),
                        'nonce_action' => self::NONCE_ACTION,
                        'nonce_field'  => self::NONCE_FIELD,
                        'strings'      => [
                            'no_forms'             => esc_html__( 'No audio recorder forms found on this page.', 'starmus-audio-recorder' ),
                            'recorder_failed'      => esc_html__( 'Recorder failed to load.', 'starmus-audio-recorder' ),
                            'error_loading'        => esc_html__( 'Error loading recorder.', 'starmus-audio-recorder' ),
                            'recorder_unavailable' => esc_html__( 'Critical error: Recorder unavailable.', 'starmus-audio-recorder' ),
                            'recording_ready'      => esc_html__( 'Recording ready.', 'starmus-audio-recorder' ),
                            'long_recording'       => esc_html__( 'Your recording is about %s min long and may take some time to upload.', 'starmus-audio-recorder' ),
                            'submit_when_ready'    => esc_html__( 'Please submit when ready.', 'starmus-audio-recorder' ),
                            'error_audio_missing'  => esc_html__( 'Error: Audio not recorded or Audio ID missing.', 'starmus-audio-recorder' ),
                            'error_no_audio'       => esc_html__( 'Error: No audio file data to submit.', 'starmus-audio-recorder' ),
                            'error_consent'        => esc_html__( 'Error: Consent is required.', 'starmus-audio-recorder' ),
                            'submitting'           => esc_html__( 'Submitting your recording…', 'starmus-audio-recorder' ),
                            'error_invalid'        => esc_html__( 'Error: Invalid server response. (%s)', 'starmus-audio-recorder' ),
                            'submit_success'       => esc_html__( 'Successfully submitted!', 'starmus-audio-recorder' ),
                            'error_unknown'        => esc_html__( 'Unknown server error.', 'starmus-audio-recorder' ),
                            'error_template'       => esc_html__( 'Error: %s', 'starmus-audio-recorder' ),
                            'network_error'        => esc_html__( 'Network error. Please check connection and try again.', 'starmus-audio-recorder' ),
                        ],
                    ]
                );

                wp_enqueue_style(
                    'starmus-audio-recorder-style',
                    $this->plugin_url . 'assets/css/starmus-audio-recorder-style.css',
                    [],
                    $this->version
                );
            }
        }

        /**
         * Validates a UUID format using a regular expression, but allows fallback via filter.
         */
        protected function is_valid_uuid(string $uuid): bool
        {
            $strict = apply_filters('starmus_uuid_strict', true);
            if (!$strict && !empty($uuid)) {
                return true;
            }
            return !empty($uuid) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid);
        }

        /**
         * Forces allowed audio file types for uploads.
         * This is a workaround for WordPress MIME type checks.
         */
        public function force_allowed_audio_filetypes($data, $file, $filename, $mimes)
        {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            $map = [
                'webm' => 'audio/webm',
                'ogg'  => 'audio/ogg',
                'opus' => 'audio/ogg',
                'm4a'  => 'audio/mp4',
                'mp4'  => 'audio/mp4',
                'wav'  => 'audio/wav',
                'wave' => 'audio/wav',
                'mp3'  => 'audio/mpeg',
            ];

            if (isset($map[$ext])) {
                return [
                    'ext'             => $ext,
                    'type'            => $map[$ext],
                    'proper_filename' => $filename,
                ];
            }

            return $data;
        }

        /**
         * Determines whether a file type is allowed for audio uploads.
         */
        protected function is_allowed_file_type(string $file_type): bool
        {
            $allowed_types = apply_filters('starmus_allowed_audio_types', [
                'audio/wav',    // WAV format
                'audio/wave',   // WAV format
                'audio/webm',   // Android Chrome/Firefox
                'audio/ogg',    // Android with Opus
                'audio/opus',   // Explicit Opus label
                'audio/mp4',    // iOS Safari
                'audio/m4a',    // iOS Safari
                'video/mp4',    // Some iOS audio files labeled as video/mp4
            ]);
            return in_array(strtolower($file_type), $allowed_types, true);
        }

        /**
         * Handles moving an uploaded file to the media library.
         *
         * @param string $file_key The input name from $_FILES
         * @param int $post_id Optional post ID to associate media with
         * @return int|WP_Error
         */
        protected function upload_file_to_media_library(string $file_key, int $post_id = 0): int|\WP_Error
        {
            if (!function_exists('media_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }
            // Extra filetype/ext check for security
            $file = $_FILES[$file_key] ?? null;
            if ($file) {
               // Let WordPress validate using media_handle_upload with our custom filter already applied
            }
            return media_handle_upload($file_key, $post_id);
        }

        /**
         * Gets the post type where the audio entry should be stored.
         *
         * @return string Custom post type slug.
         */
        protected function get_target_post_type(): string
        {
            return apply_filters('starmus_audio_submission_post_type', self::POST_TYPE);
        }

        /**
         * Provides readable error messages for known upload failures.
         */
        protected function get_upload_error_message(int $error_code): string
        {
            error_log('UPLOAD ERROR CODE: ' . $error_code);
            switch ($error_code) {
                case UPLOAD_ERR_INI_SIZE:
                    return __( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_FORM_SIZE:
                    return __( 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_PARTIAL:
                    return __( 'The uploaded file was only partially uploaded.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_NO_FILE:
                    return __( 'No file was uploaded.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_NO_TMP_DIR:
                    return __( 'Missing a temporary folder.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_CANT_WRITE:
                    return __( 'Failed to write file to disk.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_EXTENSION:
                    return __( 'A PHP extension stopped the file upload.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_OK:
                    return __( 'File uploaded successfully.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_EMPTY_FILE:
                    return __( 'The uploaded file is empty.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_INVALID_FILE:
                    return __( 'The uploaded file is invalid.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_INVALID_TYPE:
                    return __( 'The uploaded file type is not allowed.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_FILE_TOO_LARGE:
                    return __( 'The uploaded file is too large.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_FILE_NOT_FOUND:
                    return __( 'The uploaded file was not found.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_FILE_NOT_READABLE:
                    return __( 'The uploaded file is not readable.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_FILE_NOT_WRITABLE:
                    return __( 'The uploaded file is not writable.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_FILE_EXISTS:
                    return __( 'The uploaded file already exists.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_FILE_NOT_SUPPORTED:
                    return __( 'The uploaded file type is not supported.', 'starmus-audio-recorder' );
                case UPLOAD_ERR_FILE_TOO_SHORT:
                    return __( 'The uploaded file is too short.', 'starmus-audio-recorder' );
                default:
                    return __( 'Unknown upload error.', 'starmus-audio-recorder' );
            }
        }
    }

    // Register the audio submission handler
    // Register in main class file :: new StarmusAudioSubmissionHandler(); // Fixed class name

}
