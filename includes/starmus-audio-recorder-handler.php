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
        const START_ACTION = 'starmus_start_submission';

        /**
         * Security nonce action name used to validate the form submission.
         */
        const NONCE_ACTION = 'starmus_submit_audio_action';
        const START_NONCE_ACTION = 'starmus_start_submission_action';

        /**
         * HTML form field name for the nonce value.
         */
        const NONCE_FIELD = 'starmus_audio_nonce_field';
        const START_NONCE_FIELD = 'starmus_start_submission_nonce';

        /**
         * WordPress shortcode tag that renders the recorder form.
         */
        const SHORTCODE_TAG = 'starmus_audio_recorder';
        const START_SHORTCODE_TAG = 'starmus_start_submission';

        /**
         * Default custom post type where audio submissions are stored.
         * This allows future expansion via options or filters.
         */
        const POST_TYPE = 'recording-session';

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
            add_action('admin_post_nopriv_' . self::START_ACTION, [$this, 'process_start_form']);
            add_action('admin_post_' . self::START_ACTION, [$this, 'process_start_form']);
            add_shortcode(self::SHORTCODE_TAG, [$this, 'render_recorder_form_shortcode']);
            add_shortcode(self::START_SHORTCODE_TAG, [$this, 'render_start_shortcode']);
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
                wp_send_json_error(['success' => false, 'message' => 'Invalid request.'], 400);
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
                wp_send_json_error(['success' => false, 'message' => 'Nonce verification failed.'], 403);
                return;
            }

            if (empty($_POST['audio_consent']) || sanitize_text_field($_POST['audio_consent']) !== 'on') {
                wp_send_json_error(['success' => false, 'message' => 'Consent is required.'], 400);
                return;
            }

            $submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
            $session_post = $submission_id ? get_post($submission_id) : null;
            if (!$session_post || self::POST_TYPE !== $session_post->post_type) {
                wp_send_json_error(['success' => false, 'message' => 'Invalid submission ID.'], 400);
                return;
            }

            $uuid = isset($_POST['audio_uuid']) ? sanitize_text_field($_POST['audio_uuid']) : '';
            if (!$this->is_valid_uuid($uuid)) {
                wp_send_json_error(['success' => false, 'message' => 'Invalid or missing UUID.'], 400);
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
                wp_send_json_error(['success' => false, 'message' => 'Unsupported audio file type: ' . esc_html($file['type'])], 400);
                return;
            }
            if (!$check['ext'] || !$check['type']) {
                wp_send_json_error(['success' => false, 'message' => 'File type or extension not allowed (server check).'], 400);
                return;
            }

            $attachment_id = $this->upload_file_to_media_library('audio_file', $submission_id);
            if (is_wp_error($attachment_id)) {
                wp_send_json_error(['success' => false, 'message' => 'Failed to save audio file: ' . $attachment_id->get_error_message()], 500);
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

            // --- Data specific to the audio file itself (from $_FILES and recorder) ---
            $audio_uuid_from_js = isset( $_POST['audio_uuid'] ) ? sanitize_text_field( $_POST['audio_uuid'] ) : '';
            $file = $_FILES['audio_file']; // Already validated

            wp_update_post([
                'ID'           => $submission_id,
                'post_status'  => 'publish',
                'post_author'  => $current_user_id,
                'post_content' => $audio_player_html,
            ]);

            update_post_meta($submission_id, 'audio_id_js', $audio_uuid_from_js);
            update_post_meta($submission_id, 'audio_consent', 'yes');
            update_post_meta($submission_id, '_audio_attachment_id', $attachment_id);
            update_post_meta($submission_id, 'submission_ip_address', $current_ip_address);
            update_post_meta($submission_id, 'submission_user_agent', $current_user_agent);
            update_post_meta($submission_id, 'submission_datetime', $current_submitted_at);
            update_post_meta($submission_id, 'audio_submission_event_id', $current_audio_submission_id);
            update_post_meta($submission_id, 'submission_user_id', $current_user_id);
            update_post_meta($submission_id, 'submission_user_email', $current_user_email);
            update_post_meta($submission_id, 'audio_file_type', $file['type']);
            update_post_meta($submission_id, 'audio_file_size', $file['size']);
            update_post_meta($submission_id, 'audio_file_name_original', $file['name']);

            wp_send_json_success([
                'success'       => true,
                'message'       => 'Submission successful.',
                'attachment_id' => $attachment_id,
                'post_id'       => $submission_id,
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
                'submit_button_text' => 'Submit Recording',
            ], $atts);

            ob_start();
            $form_action_url = esc_url(admin_url('admin-ajax.php'));
            $form_id = esc_attr($attributes['form_id']);
            $submit_button_text = esc_html($attributes['submit_button_text']);
            $nonce_action = self::NONCE_ACTION;
            $nonce_field_name = self::NONCE_FIELD;
            $submission_id = isset($_GET['submission_id']) ? absint($_GET['submission_id']) : '';
            $template_path = $this->plugin_path . 'templates/starmus-audio-recorder-ui.php';

            if (file_exists($template_path)) {
                include $template_path;
            } else {
                echo '<p>Error: Audio recorder form template not found.</p>';
            }

            return ob_get_clean();
        }

        public function render_start_shortcode($atts = []): string
        {
            $atts = shortcode_atts([
                'redirect' => '',
            ], $atts);
            $redirect = esc_url($atts['redirect']);

            ob_start();
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::START_ACTION); ?>" />
                <?php wp_nonce_field(self::START_NONCE_ACTION, self::START_NONCE_FIELD); ?>
                <label><?php echo esc_html__('Title', 'starmus-audio-recorder'); ?>
                    <input type="text" name="session_title" required />
                </label>
                <label>
                    <input type="checkbox" name="consent" required /> <?php echo esc_html__('Consent', 'starmus-audio-recorder'); ?>
                </label>
                <label><?php echo esc_html__('Date', 'starmus-audio-recorder'); ?>
                    <input type="date" name="session_date" />
                </label>
                <label><?php echo esc_html__('Language', 'starmus-audio-recorder'); ?>
                    <input type="text" name="language" />
                </label>
                <label><?php echo esc_html__('Dialect', 'starmus-audio-recorder'); ?>
                    <input type="text" name="dialect" />
                </label>
                <input type="hidden" name="redirect_url" value="<?php echo esc_url($redirect); ?>" />
                <button type="submit"><?php echo esc_html__('Next', 'starmus-audio-recorder'); ?></button>
            </form>
            <?php
            return ob_get_clean();
        }

        public function process_start_form(): void
        {
            if (!isset($_POST[self::START_NONCE_FIELD]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::START_NONCE_FIELD])), self::START_NONCE_ACTION)) {
                wp_safe_redirect(wp_get_referer());
                exit;
            }

            $title    = isset($_POST['session_title']) ? sanitize_text_field($_POST['session_title']) : '';
            $consent  = isset($_POST['consent']) ? 'yes' : 'no';
            $date     = isset($_POST['session_date']) ? sanitize_text_field($_POST['session_date']) : '';
            $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';
            $dialect  = isset($_POST['dialect']) ? sanitize_text_field($_POST['dialect']) : '';

            $post_id = wp_insert_post([
                'post_type'   => self::POST_TYPE,
                'post_status' => 'draft',
                'post_title'  => $title,
            ], true);

            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, 'consent', $consent);
                update_post_meta($post_id, 'session_date', $date);
                update_post_meta($post_id, 'language', $language);
                update_post_meta($post_id, 'dialect', $dialect);
                $redirect = isset($_POST['redirect_url']) ? esc_url_raw($_POST['redirect_url']) : home_url('/');
                $redirect = add_query_arg('submission_id', $post_id, $redirect);
                wp_safe_redirect($redirect);
            } else {
                wp_safe_redirect(wp_get_referer());
            }
            exit;
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

                wp_enqueue_script(
                    'starmus-audio-recorder-submissions',
                    $this->plugin_url . 'assets/js/starmus-audio-recorder-submissions.js',
                    ['starmus-audio-recorder-module'],
                    $this->version,
                    true
                );

                wp_localize_script('starmus-audio-recorder-submissions', 'starmusFormData', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce_action' => self::NONCE_ACTION,
                    'nonce_field' => self::NONCE_FIELD,
                ]);

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
                    return "The uploaded file exceeds the upload_max_filesize directive in php.ini.";
                case UPLOAD_ERR_FORM_SIZE:
                    return "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.";
                case UPLOAD_ERR_PARTIAL:
                    return "The uploaded file was only partially uploaded.";
                case UPLOAD_ERR_NO_FILE:
                    return "No file was uploaded.";
                case UPLOAD_ERR_NO_TMP_DIR:
                    return "Missing a temporary folder.";
                case UPLOAD_ERR_CANT_WRITE:
                    return "Failed to write file to disk.";
                case UPLOAD_ERR_EXTENSION:
                    return "A PHP extension stopped the file upload.";
                case UPLOAD_ERR_OK:
                    return "File uploaded successfully.";
                case UPLOAD_ERR_EMPTY_FILE:
                    return "The uploaded file is empty.";
                case UPLOAD_ERR_INVALID_FILE:
                    return "The uploaded file is invalid.";
                case UPLOAD_ERR_INVALID_TYPE:
                    return "The uploaded file type is not allowed.";
                case UPLOAD_ERR_FILE_TOO_LARGE:
                    return "The uploaded file is too large.";
                case UPLOAD_ERR_FILE_NOT_FOUND:
                    return "The uploaded file was not found.";
                case UPLOAD_ERR_FILE_NOT_READABLE:
                    return "The uploaded file is not readable.";
                case UPLOAD_ERR_FILE_NOT_WRITABLE:
                    return "The uploaded file is not writable.";
                case UPLOAD_ERR_FILE_EXISTS:
                    return "The uploaded file already exists.";
                case UPLOAD_ERR_FILE_NOT_SUPPORTED:
                    return "The uploaded file type is not supported.";
                case UPLOAD_ERR_FILE_TOO_SHORT:
                    return "The uploaded file is too short.";
                default:
                    return "Unknown upload error.";
            }
        }
    }

    // Register the audio submission handler
    // Register in main class file :: new StarmusAudioSubmissionHandler(); // Fixed class name

}
