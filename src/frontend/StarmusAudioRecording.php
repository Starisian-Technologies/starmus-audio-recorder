<?php
/**
 * Manages the front-end audio recording submission process.
 *
 * @package Starmus\src\frontend
 */

namespace Starisian\src\frontend;


use Starisian\src\admin\StarmusAdmin;
use Starisian\src\admin\StarmusAdminSettings;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StarmusAudioRecording {

    public function __construct() {
        // Ensure WordPress functions are available
        add_shortcode('starmus_audio_recorder', [ $this, 'render_recorder_shortcode' ]);
        add_action('wp_enqueue_scripts', [ $this, 'enqueue_scripts' ]);

        // Register the AJAX hooks for the two-step submission process
    add_action('wp_ajax_starmus_step1_create_post', [ $this, 'handle_step1_create_post' ]);
    add_action('wp_ajax_starmus_step2_upload_audio', [ $this, 'handle_step2_upload_audio' ]);
    }

    /**
     * Renders the two-step recorder form by including the template.
     */
    public function render_recorder_shortcode($atts = []): string {
    $attributes = shortcode_atts(['form_id' => 'starmusAudioForm'], $atts);
        ob_start();
    $form_id = esc_attr($attributes['form_id']);
        $template_path = dirname(__DIR__, 2) . '/templates/starmus-audio-recorder-ui.php';

        if (file_exists($template_path)) {
            include $template_path;
        } else {
            return '<p>' . esc_html__('Error: Audio recorder form template not found.', 'starmus') . '</p>';
        }
        return ob_get_clean();
    }

    /**
     * Enqueues scripts and styles for the recorder.
     */
    public function enqueue_scripts(): void {
        global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'starmus_audio_recorder')) {
            $plugin_url = plugin_dir_url(dirname(__DIR__));
            $version = defined('STARMUS_VERSION') ? STARMUS_VERSION : '1.0.0';

            wp_enqueue_script('starmus-two-step-form', $plugin_url . 'assets/js/starmus-two-step-form.js', ['jquery'], $version, true);
            wp_localize_script('starmus-two-step-form', 'starmusFormData', [
                'ajax_url'      => admin_url('admin-ajax.php'),
                'step1_action'  => 'starmus_step1_create_post',
                'step1_nonce'   => wp_create_nonce('starmus_step1_nonce'),
                'step2_action'  => 'starmus_step2_upload_audio',
                'step2_nonce'   => wp_create_nonce('starmus_step2_nonce'),
            ]);
            wp_enqueue_script('starmus-audio-recorder-module', $plugin_url . 'assets/js/starmus-audio-recorder-module.js', [], $version, true);
            wp_enqueue_style('starmus-audio-recorder-style', $plugin_url . 'assets/css/starmus-audio-recorder-style.css', [], $version);
        }
    }

    /**
     * AJAX handler for Step 1. Creates a 'draft' post with metadata.
     */
    public function handle_step1_create_post(): void {
    check_ajax_referer('starmus_step1_nonce', 'nonce');

    $post_title = isset($_POST['file_name']) ? sanitize_text_field($_POST['file_name']) : 'New Audio Submission';
        
        $post_data = [
            'post_title'   => $post_title,
            'post_type'    => StarmusAdminSettings::get_option('cpt_slug'),
            'post_status'  => 'draft',
            'post_author'  => get_current_user_id(),
            'meta_input'   => [
                'recording_language'      => sanitize_text_field($_POST['language']),
                'recording_type'          => sanitize_text_field($_POST['recording_type']),
                'submitter_name'          => sanitize_text_field($_POST['user_name']),
                'submission_datetime'     => sanitize_text_field($_POST['datetime']),
                'submission_latitude'     => sanitize_text_field($_POST['latitude']),
                'submission_longitude'    => sanitize_text_field($_POST['longitude']),
                'submission_ip'           => sanitize_text_field($_SERVER['REMOTE_ADDR']),
                'submission_user_agent'   => sanitize_text_field($_SERVER['HTTP_USER_AGENT']),
                'consent_given'           => 'yes',
            ],
        ];

    $post_id = wp_insert_post($post_data, true);

    if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()], 500);
        }

    wp_send_json_success(['post_id' => $post_id]);
    }

    /**
     * AJAX handler for Step 2. Attaches audio and publishes the post.
     */
    public function handle_step2_upload_audio(): void {
    check_ajax_referer('starmus_step2_nonce', 'nonce');

    $post_id = isset($_POST['submission_post_id']) ? absint($_POST['submission_post_id']) : 0;

    if (!$post_id || 'draft' !== get_post_status($post_id)) {
            wp_send_json_error(['message' => 'Invalid or already processed submission ID.'], 400);
        }
        
        if (empty($_FILES['audio_file']) || UPLOAD_ERR_OK !== $_FILES['audio_file']['error']) {
            wp_send_json_error(['message' => 'Audio file is missing or contains an upload error.'], 400);
        }

    $attachment_id = media_handle_upload('audio_file', $post_id);

    if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => 'Failed to save the audio file: ' . $attachment_id->get_error_message()], 500);
        }

    update_post_meta($post_id, '_audio_attachment_id', $attachment_id);
        
        $updated_post = [
            'ID'           => $post_id,
            'post_status'  => 'publish',
            'post_content' => '[audio src="' . esc_url(wp_get_attachment_url($attachment_id)) . '"]',
        ];
    wp_update_post($updated_post);
        
    wp_send_json_success(['message' => 'Submission complete!', 'post_id' => $post_id]);
    }
}