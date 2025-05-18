<?php

if ( ! class_exists( 'Starmus_Audio_Submission_Handler' ) ) {

    class Starmus_Audio_Submission_Handler {

        /**
         * Action hook for logged-in users.
         * @var string
         */
        const ACTION_AUTH = 'starmus_submit_audio';

        /**
         * Action hook for non-logged-in users.
         * @var string
         */
        const ACTION_NO_PRIV = 'nopriv_starmus_submit_audio';

        /**
         * Nonce action name.
         * @var string
         */
        const NONCE_ACTION = 'starmus_submit_audio_action'; // Matches your HTML form's nonce

        /**
         * Nonce field name.
         * @var string
         */
        const NONCE_FIELD = 'starmus_audio_nonce_field'; // Matches your HTML form's nonce
        /**
         * Shortcode tag.
         * @var string
         */
        const SHORTCODE_TAG = 'starmus_audio_recorder'; // Define the shortcode tag

        /**
         * Constructor. Hooks into WordPress AJAX and registers the shortcode.
         */
        public function __construct() {
            // AJAX handlers
            add_action( 'wp_ajax_nopriv_' . self::ACTION_NO_PRIV, [ $this, 'handle_submission' ] );
            add_action( 'wp_ajax_' . self::ACTION_AUTH, [ $this, 'handle_submission' ] );

            // Register shortcode
            add_shortcode( self::SHORTCODE_TAG, [ $this, 'render_recorder_form_shortcode' ] );

            // Action to enqueue scripts and styles if the shortcode is used (optional but good practice)
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts_if_shortcode_is_present' ] );
        }

        /**
         * Handles the audio submission AJAX request.
         */
        public function handle_submission() {
            // Verify nonce for security
            if ( ! isset( $_POST[self::NONCE_FIELD] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[self::NONCE_FIELD] ) ), self::NONCE_ACTION ) ) {
                wp_send_json_error( [ 'success' => false, 'message' => 'Nonce verification failed.' ], 403 );
                return;
            }
            
            // Basic capability check (optional, adjust as needed)
            // if ( ! current_user_can( 'upload_files' ) ) { // Example capability
            //     wp_send_json_error( [ 'success' => false, 'message' => 'You do not have permission to upload files.' ], 403 );
            //     return;
            // }

            $response_data = []; // For accumulating data before sending JSON

            // Validate consent
            if ( empty( $_POST['audio_consent'] ) || sanitize_text_field($_POST['audio_consent']) !== 'on' ) { // Checkboxes often send 'on' or their value if set
                // Your HTML has <input type="checkbox" ... name="audio_consent" required>
                // If the value attribute isn't set for the checkbox, it defaults to 'on' when checked.
                // If you set value="yes", then check for 'yes'.
                wp_send_json_error( [ 'success' => false, 'message' => 'Consent is required.' ], 400 );
                return;
            }

            // Validate UUID
            $uuid = isset( $_POST['audio_uuid'] ) ? sanitize_text_field( $_POST['audio_uuid'] ) : '';
            if ( ! $this->is_valid_uuid( $uuid ) ) {
                wp_send_json_error( [ 'success' => false, 'message' => 'Invalid or missing UUID.' ], 400 );
                return;
            }

            // Validate file
            if ( empty( $_FILES['audio_file'] ) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK ) {
                wp_send_json_error( [ 'success' => false, 'message' => $this->get_upload_error_message($_FILES['audio_file']['error'] ?? UPLOAD_ERR_NO_FILE) ], 400 );
                return;
            }

            $file = $_FILES['audio_file'];
            if ( ! $this->is_allowed_file_type( $file['type'] ) ) {
                wp_send_json_error( [ 'success' => false, 'message' => 'Unsupported audio file type: ' . esc_html($file['type']) ], 400 );
                return;
            }

            // Save file to media library
            $attachment_id = $this->upload_file_to_media_library( 'audio_file' );
            if ( is_wp_error( $attachment_id ) ) {
                wp_send_json_error( [ 'success' => false, 'message' => 'Failed to save audio file: ' . $attachment_id->get_error_message() ], 500 );
                return;
            }
            $response_data['attachment_id'] = $attachment_id;

            // Create post
            $post_data = [
                'post_title'   => 'Audio Recording ' . $uuid,
                'post_type'    => $this->get_target_post_type(),
                'post_status'  => 'publish', // Or 'draft', 'pending'
                'meta_input'   => [
                    'audio_uuid'     => $uuid,
                    'audio_consent'  => 'yes', // Storing 'yes' explicitly
                    '_audio_attachment_id' => $attachment_id, // Storing attachment ID as meta
                ],
            ];
            $post_id = wp_insert_post( $post_data );

            if ( is_wp_error( $post_id ) ) {
                // If post creation fails, consider deleting the orphaned attachment
                wp_delete_attachment( $attachment_id, true );
                wp_send_json_error( [ 'success' => false, 'message' => 'Failed to create post: ' . $post_id->get_error_message() ], 500 );
                return;
            }
            $response_data['post_id'] = $post_id;

            // Optionally, set the audio attachment as a "featured image" or a specific meta field
            // set_post_thumbnail($post_id, $attachment_id); // This is for images usually.
            // For audio, you might just store the attachment_id in post meta (already done above)
            // or the file URL.

            // Set cookie (as done by your JS, server-side is more reliable if this is the primary way)
            // Note: Your JS already sets this. If you want server to be authoritative, ensure JS doesn't set it
            // or that this overrides it with the same parameters.
            // For AJAX, the cookie set here will be available on subsequent *page loads*,
            // not necessarily immediately in JS on the same "page" after the AJAX response.
            // However, since your JS sets it, this server-side setcookie might be redundant unless
            // this AJAX endpoint is also hit by non-JS submissions (unlikely given the setup).
            // If the goal is for the *next* form to read this cookie, JS setting is usually sufficient.
            // $cookie_set = setcookie( 'audio_uuid', $uuid, time() + 86400, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
            // $response_data['cookie_set_by_server'] = $cookie_set;


            $response_data['success'] = true;
            $response_data['message'] = 'Submission successful.';
            wp_send_json_success( $response_data, 200 );
        }


        /**
         * Renders the audio recorder form HTML for the shortcode.
         *
         * @param array $atts Shortcode attributes.
         * @param string|null $content Shortcode content.
         * @return string HTML output for the form.
         */
        public function render_recorder_form_shortcode( $atts = [], $content = null ): string {
            // Normalize shortcode attributes with defaults
            $attributes = shortcode_atts( [
                'form_id'           => 'sparxstarAudioForm', // Default form ID
                'submit_button_text' => 'Submit Recording',
                // Add more attributes if needed (e.g., for customizing labels, etc.)
            ], $atts );

            // It's better to not directly echo within a shortcode handler, but to return the HTML.
            // Output buffering is a clean way to capture HTML from an included file or generated here.
            ob_start();

            // --- Prepare variables for the template ---
            $form_action_url = esc_url( admin_url( 'admin-ajax.php' ) ); // For AJAX submission
            // If you were using admin-post.php, it would be:
            // $form_action_url = esc_url( admin_url( 'admin-post.php' ) );
            // And you'd need a hidden field: <input type="hidden" name="action" value="your_admin_post_action_hook_name">

            $form_id = esc_attr( $attributes['form_id'] );
            $submit_button_text = esc_html( $attributes['submit_button_text'] );

            // Nonce values
            $nonce_action = self::NONCE_ACTION;
            $nonce_field_name = self::NONCE_FIELD;

            // Path to your HTML template file for the form
            // Assumes your class file is in 'includes/' and your template in 'includes/templates/'
            $template_path = plugin_dir_path( __FILE__ ) . 'templates/audio-recorder-form-template.php';

            if ( file_exists( $template_path ) ) {
                include $template_path; // This file will use the variables defined above
            } else {
                // Fallback or error message if template is missing
                echo '<p>Error: Audio recorder form template not found.</p>';
            }

            return ob_get_clean();
        }


        /**
         * Enqueues scripts and styles if the shortcode is detected on the page.
         * This is a more efficient way than always loading them.
         */
        public function enqueue_scripts_if_shortcode_is_present() {
            global $post;
            if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, self::SHORTCODE_TAG ) ) {
                // Enqueue your main recorder JavaScript (the modular one)
                wp_enqueue_script(
                    'starmus-audio-recorder-module',
                    plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/starmus-audio-recorder-module.js', // Adjust path
                    [], // Dependencies
                    'YOUR_VERSION_HERE', // Version
                    true // In footer
                );

                // Enqueue your AJAX form submission JavaScript
                wp_enqueue_script(
                    'starmus-audio-form-submission',
                    plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/starmus-audio-form-submission.js', // Adjust path
                    ['starmus-audio-recorder-module'], // Depends on the recorder module
                    'YOUR_VERSION_HERE',
                    true
                );

                // Localize data for the submission script if needed (e.g., AJAX URL, nonce)
                // Though the nonce is in the form, AJAX URL can be passed this way
                wp_localize_script('starmus-audio-form-submission', 'starmusFormData', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce_action' => self::NONCE_ACTION, // For potential JS-side nonce creation/validation if needed elsewhere
                    'nonce_field' => self::NONCE_FIELD,  // For JS reference
                    // 'form_action_hook' => self::ACTION_AUTH // Your AJAX action name
                ]);


                // Enqueue your CSS
                wp_enqueue_style(
                    'starmus-audio-recorder-style',
                    plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/starmus-audio-recorder.css', // Adjust path
                    [],
                    'YOUR_VERSION_HERE'
                );
            }
        }


        /**
         * Validates a UUID.
         * @param string $uuid
         * @return bool
         */
        protected function is_valid_uuid( string $uuid ): bool {
            return !empty( $uuid ) && preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid );
        }

        /**
         * Checks if the file type is allowed.
         * @param string $file_type
         * @return bool
         */
        protected function is_allowed_file_type( string $file_type ): bool {
            $allowed_types = apply_filters('starmus_allowed_audio_types', [
                'audio/mpeg', // mp3
                'audio/wav',  // wav
                'audio/webm', // webm
                'audio/mp4',  // m4a / mp4 audio
                'audio/ogg',  // ogg (Opus)
                'audio/aac',  // aac
            ]);
            return in_array( strtolower( $file_type ), $allowed_types, true );
        }

        /**
         * Handles file upload to media library.
         * @param string $file_key The key in $_FILES array.
         * @param int $post_id Optional. Post ID to attach to.
         * @return int|WP_Error Attachment ID on success, WP_Error on failure.
         */
        protected function upload_file_to_media_library( string $file_key, int $post_id = 0 ) {
            if ( ! function_exists( 'media_handle_upload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php'; // Though not an image, media_handle_upload often uses it.
            }
            // media_handle_upload expects the file key, and an optional post_id to attach to.
            // If $post_id is 0, the attachment is unattached. We'll attach it later if needed.
            $attachment_id = media_handle_upload( $file_key, $post_id );
            return $attachment_id;
        }

        /**
         * Gets the target post type for the audio submission.
         * Allows filtering.
         * @return string
         */
        protected function get_target_post_type(): string {
            $default_post_type = 'starmus_audio'; // Consider a custom post type
            return apply_filters( 'starmus_audio_submission_post_type', $default_post_type );
        }

        /**
         * Gets a user-friendly message for a file upload error code.
         * @param int $error_code
         * @return string
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
                default:
                    return "Unknown upload error.";
            }
        }
   } // End class

    // Instantiate the class
    new Starmus_Audio_Submission_Handler();

} // End if class_exists
