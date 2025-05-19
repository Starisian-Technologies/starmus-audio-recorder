<?php
namespace Starmus\includes;

// exit if access directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Starmus_Audio_Submission_Handler' ) ) {

    class Starmus_Audio_Submission_Handler {

        /**
         * AJAX action hook for logged-in users.
         * This should match the 'action' parameter used in your JavaScript.
         */
        const ACTION_AUTH = 'starmus_submit_audio';

        /**
         * AJAX action hook for non-logged-in users.
         */
        const ACTION_NO_PRIV = 'nopriv_starmus_submit_audio';

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
        public function __construct() {
		$this->plugin_path = STARMUS_PATH;
		$this->plugin_url  = STARMUS_URL;
		$this->version = STARMUS_VERSION;

		$this->register_hooks();
            
        }

	public function register_hooks(): void{
	    add_action( 'wp_ajax_nopriv_' . self::ACTION_NO_PRIV, [ $this, 'handle_submission' ] );
            add_action( 'wp_ajax_' . self::ACTION_AUTH, [ $this, 'handle_submission' ] );
            add_shortcode( self::SHORTCODE_TAG, [ $this, 'render_recorder_form_shortcode' ] );
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts_if_shortcode_is_present' ] );
	}

        /**
         * Handles the audio submission AJAX request.
         * Validates data, saves the audio to the media library, and creates a new post.
         */
        public function handle_submission(): void {
            if ( ! isset( $_POST[self::NONCE_FIELD] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[self::NONCE_FIELD] ) ), self::NONCE_ACTION ) ) {
                wp_send_json_error( [ 'success' => false, 'message' => 'Nonce verification failed.' ], 403 );
                return;
            }

            if ( empty( $_POST['audio_consent'] ) || sanitize_text_field($_POST['audio_consent']) !== 'on' ) {
                wp_send_json_error( [ 'success' => false, 'message' => 'Consent is required.' ], 400 );
                return;
            }

            $uuid = isset( $_POST['audio_uuid'] ) ? sanitize_text_field( $_POST['audio_uuid'] ) : '';
            if ( ! $this->is_valid_uuid( $uuid ) ) {
                wp_send_json_error( [ 'success' => false, 'message' => 'Invalid or missing UUID.' ], 400 );
                return;
            }

            if ( empty( $_FILES['audio_file'] ) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK ) {
                wp_send_json_error( [ 'success' => false, 'message' => $this->get_upload_error_message($_FILES['audio_file']['error'] ?? UPLOAD_ERR_NO_FILE) ], 400 );
                return;
            }

            $file = $_FILES['audio_file'];
            if ( ! $this->is_allowed_file_type( $file['type'] ) ) {
                wp_send_json_error( [ 'success' => false, 'message' => 'Unsupported audio file type: ' . esc_html($file['type']) ], 400 );
                return;
            }

            $attachment_id = $this->upload_file_to_media_library( 'audio_file' );
            if ( is_wp_error( $attachment_id ) ) {
                wp_send_json_error( [ 'success' => false, 'message' => 'Failed to save audio file: ' . $attachment_id->get_error_message() ], 500 );
                return;
            }
            
            $ip_address    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
            $user_agent    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : 'unknown';
            $submitted_at  = current_time( 'mysql' );
            $submission_id = uniqid( 'submission_', true );

            $post_data = [
                'post_title'   => 'Audio Recording ' . $uuid,
                'post_type'    => $this->get_target_post_type(),
                'post_status'  => 'publish',
                'post_content' => '',
                'meta_input'   => [
                    'audio_uuid'           => $uuid,
                    'audio_consent'        => 'yes',
                    '_audio_attachment_id' => $attachment_id,
                    'ip_address'           => $ip_address,
                    'user_agent'           => $user_agent,
                    'submitted_at'         => $submitted_at,
                    'submission_id'        => $submission_id,
                    'audio_file_type'      => $file['type'],
                    'audio_file_size'      => $file['size'],
                    'audio_file_name'      => $file['name'],
                ],
            ];
            $post_id = wp_insert_post( $post_data );

            if ( is_wp_error( $post_id ) ) {
                wp_delete_attachment( $attachment_id, true );
                wp_send_json_error( [ 'success' => false, 'message' => 'Failed to create post: ' . $post_id->get_error_message() ], 500 );
                return;
            }
            update_post_meta($post_id, 'audio_file_type', $file['type']);
            update_post_meta($post_id, 'audio_file_size', $file['size']);
            update_post_meta($post_id, 'audio_file_name', $file['name']);


            wp_send_json_success( [
                'success' => true,
                'message' => 'Submission successful.',
                'attachment_id' => $attachment_id,
                'post_id' => $post_id,
            ], 200 );
        }

        /**
         * Outputs the audio recorder form when shortcode is used.
         * This will be replaced by a dynamic template include.
         */
        public function render_recorder_form_shortcode( $atts = [], $content = null ): string {
            $attributes = shortcode_atts( [
                'form_id'           => 'sparxstarAudioForm',
                'submit_button_text' => 'Submit Recording',
            ], $atts );

            ob_start();
            $form_action_url = esc_url( admin_url( 'admin-ajax.php' ) );
            $form_id = esc_attr( $attributes['form_id'] );
            $submit_button_text = esc_html( $attributes['submit_button_text'] );
            $nonce_action = self::NONCE_ACTION;
            $nonce_field_name = self::NONCE_FIELD;
            $template_path = $this->plugin_path . 'templates/starmus-audio-recorder-ui.php';

            if ( file_exists( $template_path ) ) {
                include $template_path;
            } else {
                echo '<p>Error: Audio recorder form template not found.</p>';
            }

            return ob_get_clean();
        }

        /**
         * Conditionally loads scripts and styles only if shortcode is present.
         */
        public function enqueue_scripts_if_shortcode_is_present(): void {
            global $post;
            if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, self::SHORTCODE_TAG ) ) {
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
         * Validates a UUID format using a regular expression.
         */
        protected function is_valid_uuid( string $uuid ): bool {
            return !empty( $uuid ) && preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid );
        }

        /**
         * Determines whether a file type is allowed for audio uploads.
         */
        protected function is_allowed_file_type( string $file_type ): bool {
            $allowed_types = apply_filters('starmus_allowed_audio_types', [
                'audio/mpeg', 'audio/wav', 'audio/webm', 'audio/mp4', 'audio/ogg', 'audio/aac',
            ]);
            return in_array( strtolower( $file_type ), $allowed_types, true );
        }

        /**
         * Handles moving an uploaded file to the media library.
         *
         * @param string $file_key The input name from $_FILES
         * @param int $post_id Optional post ID to associate media with
         * @return int|WP_Error
         */
        protected function upload_file_to_media_library( string $file_key, int $post_id = 0 ): mixed {
            if ( ! function_exists( 'media_handle_upload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }
            return media_handle_upload( $file_key, $post_id );
        }

        /**
         * Gets the post type where the audio entry should be stored.
         *
         * @return string Custom post type slug.
         */
        protected function get_target_post_type(): string {
            return apply_filters( 'starmus_audio_submission_post_type', self::POST_TYPE );
        }

        /**
         * Provides readable error messages for known upload failures.
         */
        protected function get_upload_error_message(int $error_code) : string {
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
    // Register in main class file :: new Starmus_Audio_Submission_Handler();

}
