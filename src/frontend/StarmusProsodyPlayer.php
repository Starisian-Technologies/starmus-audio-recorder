<?php

namespace Starisian\Sparxstar\Starmus\frontend;

use function class_exists;
use function ob_get_clean;
use function ob_start;

use Starisian\Sparxstar\Starmus\data\StarmusProsodyDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

if (! \defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class StarmusProsodyPlayer
 *
 * Handles the Shortcode [prosody_reader], Assets, and AJAX Listener.
 *
 * @package Starisian\Sparxstar\Starmus\frontend
 */
class StarmusProsodyPlayer
{
    private ?StarmusProsodyDAL $dal = null;

    public function __construct(?StarmusProsodyDAL $prosody_dal = null)
    {
        $this->dal = $prosody_dal ?: new StarmusProsodyDAL();
        $this->register_hooks();
    }

    /**
     * Summary of register_hooks
     */
    private function register_hooks(): void
    {

        // Hooks
        add_action('init', $this->register_shortcodes(...));
        add_action('init', $this->init_dal(...));
        add_action('wp_enqueue_scripts', $this->register_assets(...));

        // AJAX Endpoints (Authenticated & Public if needed, usually Auth only for this)
        add_action('wp_ajax_starmus_save_pace', $this->handle_ajax_save(...));
    }

    public function register_shortcodes(): void
    {
        // Register the shortcode
        add_shortcode('prosody_reader', $this->render_shortcode(...));
        add_shortcode('starmus_script_card', $this->render_script_card(...));
    }

    public function init_dal(): void
    {
        if ($this->dal instanceof StarmusProsodyDAL) {
            StarmusLogger::info('StarmusProsodyDAL loaded');
            return;
        }

        if (! class_exists(StarmusProsodyDAL::class)) {
            StarmusLogger::error('StarmusProsodyDAL class not found');
        }

        try {
            $this->dal = new StarmusProsodyDAL();
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    /**
     * 1. Register JS/CSS
     */
    public function register_assets(): void
    {
        // Enqueue the CSS (assuming you saved the CSS from previous chat to a file)
        wp_register_style(
            'starmus-prosody-css',
            STARMUS_URL . 'src/css/starmus-prosody-engine.css',
            [],
            STARMUS_VERSION
        );

        // Enqueue the JS (assuming you saved the JS Class to a file)
        wp_register_script(
            'starmus-prosody-js',
            STARMUS_URL . 'src/js/prosody/starmus-prosody-engine.js',
            [],
            STARMUS_VERSION,
            true // Load in footer
        );
    }

    /**
     * 2. The Shortcode Output
     * Usage: [prosody_reader] (uses current post) OR [prosody_reader id="123"]
     *
     * @param array $atts Shortcode attributes
     *
     * @return string HTML Output
     */
    public function render_shortcode(array $atts = []): string
    {
        try {
            $args = shortcode_atts(
                [
                    'id' => get_the_ID(),
                ],
                $atts
            );

            // Logic: 1. ID from Attr, 2. Script ID from GET, 3. Current Post
            $post_id = (int) $args['id'];
            if (isset($_GET['script_id']) && absint($_GET['script_id']) > 0) {
                // If the shortcode was called with default ID (card usage or page context), override it.
                // But if user explicitly passed id="123" in shortcode, that should win?
                // Actually, if we are on a generic "Recorder" page, the shortcode might be plain [prosody_reader].
                // So checking if 'id' matches get_the_ID() is a good heuristic that it's "default".
                if ($post_id === get_the_ID()) {
                    $post_id = absint($_GET['script_id']);
                }
            }

            $data = $this->dal->get_script_payload($post_id);

            if ($data === []) {
                return '<div class="prosody-error">' . esc_html__('Error: Script data not found.', 'starmus-audio-recorder') . '</div>';
            }

            // Load Assets
            wp_enqueue_style('starmus-prosody-css');
            wp_enqueue_script('starmus-prosody-js');

            // Pass Data to JS via Inline Script
            // We use wp_add_inline_script for type safety (integers remain integers)
            // and explicit global assignment.
            $json_payload = wp_json_encode($data);

            if (false === $json_payload) {
                // Fallback or log error
                $json_payload = '{}';
            }

            // Removed wp_add_inline_script in favor of direct injection below to guarantee order

            // Render The HTML Shell
            ob_start();

            // Direct Injection: Ensures availability before footer scripts run
            if ($json_payload) {
                echo '<script id="starmus-prosody-data">';
                echo 'window.StarmusProsodyData = ' . $json_payload . ';';
                echo 'console.log("Starmus Prosody: Data Injected Directly");';
                echo '</script>';
            }
?>
            <div id="cognitive-regulator">
                <!-- CALIBRATION LAYER -->
                <div id="calibration-layer">
                    <button type="button" class="tap-zone" id="btn-tap" aria-label="<?php echo esc_attr__('Tap to set rhythm', 'starmus-audio-recorder'); ?>">
                        <div class="tap-icon" aria-hidden="true">👆</div>
                        <div class="tap-label"><?php esc_html_e('TAP RHYTHM', 'starmus-audio-recorder'); ?></div>
                        <div class="tap-sub"><?php esc_html_e('Spacebar or Click to set pace', 'starmus-audio-recorder'); ?></div>
                    </button>
                    <div class="tap-feedback" id="tap-feedback" role="status" aria-live="polite">...</div>
                </div>

                <!-- THE STAGE -->
                <div id="scaffold-stage" class="hidden">
                    <div id="text-flow"></div>
                    <div class="spacer"></div>
                </div>

                <!-- CONTROLS -->
                <div class="control-deck hidden" id="main-controls">
                    <div class="btn-group">
                        <button id="btn-engage" class="neutral-btn">
                            <span class="icon" aria-hidden="true">▶</span> <span class="label"><?php esc_html_e('TEST FLOW', 'starmus-audio-recorder'); ?></span>
                        </button>
                        <button id="btn-top" class="neutral-btn" title="<?php echo esc_attr__('Return to Top', 'starmus-audio-recorder'); ?>" style="margin-left: 8px;" aria-label="<?php echo esc_attr__('Return to Top', 'starmus-audio-recorder'); ?>">
                            <span class="icon" aria-hidden="true">⬆</span>
                        </button>
                    </div>

                    <div class="fader-group">
                        <span class="fader-label" id="lbl-anxiety"><?php esc_html_e('Anxiety', 'starmus-audio-recorder'); ?></span>
                        <input type="range" id="pace-regulator" min="1000" max="6000" step="50" aria-labelledby="lbl-anxiety lbl-fatigue">
                        <span class="fader-label" id="lbl-fatigue"><?php esc_html_e('Fatigue', 'starmus-audio-recorder'); ?></span>
                    </div>

                    <button id="btn-recal" class="secondary-text-btn" title="<?php echo esc_attr__('Reset Rhythm', 'starmus-audio-recorder'); ?>" aria-label="<?php echo esc_attr__('Reset Rhythm', 'starmus-audio-recorder'); ?>"><?php esc_html_e('[ Re-Tap ]', 'starmus-audio-recorder'); ?></button>
                </div>
            </div>
        <?php
            return ob_get_clean();
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return ''; // Always return a string even on error
        }
    }

    /**
     * Renders a preview card for a Script.
     * Shows excerpt, audio player (if valid recording exists), and proper CTA.
     *
     * @param array $atts
     *
     * @return string
     */
    public function render_script_card(array $atts = []): string
    {
        try {
            $args      = shortcode_atts(['id' => 0], $atts, 'starmus_script_card');
            $script_id = (int) $args['id'];

            if ($script_id <= 0) {
                return '';
            }

            $post = get_post($script_id);
            if (! $post || $post->post_type !== 'starmus-script') {
                return '';
            }

            // 1. Get Excerpt
            $excerpt = has_excerpt($post) ? $post->post_excerpt : wp_trim_words($post->post_content, 20);

            // 2. Check for Related Audio (Current User)
            $audio_url = '';
            $rec_id    = 0;

            if (is_user_logged_in()) {
                $q = new \WP_Query([
                    'post_type'      => 'audio-recording',
                    'author'         => get_current_user_id(),
                    'title'          => $post->post_title, // Matching by title as established
                    'posts_per_page' => 1,
                    'post_status'    => 'publish',
                ]);

                if ($q->have_posts()) {
                    $rec_id = $q->posts[0];
                    // Get audio file URL. Assuming secure field or attachment.
                    // For Starmus, audio is usually an attachment or a specific field.
                    // Let's assume standard attachment for now or custom field.
                    // Checking existing code patterns... usually it's an attachment.
                    $audio_id = get_post_meta($rec_id, 'starmus_audio_file_id', true);
                    // Or native attachment if post_mime_type is audio.
                    // Let's check get_attached_media.
                    $media = get_attached_media('audio', $rec_id);
                    if (! empty($media)) {
                        $audio_url = wp_get_attachment_url(reset($media)->ID);
                    }
                }
            }

            // 3. Build Card URL
            // Assumes page with slug 'star-prosody-recorder' exists
            $recorder_url = site_url('/star-prosody-recorder');
            $action_url   = add_query_arg('script_id', $script_id, $recorder_url);

            // 4. Determine State
            $has_audio    = ! empty($audio_url);
            $action_label = $has_audio ? __('Re-Record Script', 'starmus-audio-recorder') : __('Record Script', 'starmus-audio-recorder');
            $status_class = $has_audio ? 'starmus-status-complete' : 'starmus-status-pending';

            ob_start();
        ?>
            <div class="starmus-script-card <?php echo esc_attr($status_class); ?>">
                <div class="starmus-card-header">
                    <h3 class="starmus-card-title"><?php echo esc_html($post->post_title); ?></h3>
                    <?php if ($has_audio) { ?>
                        <span class="starmus-badge success"><?php esc_html_e('Recorded', 'starmus-audio-recorder'); ?></span>
                    <?php } ?>
                </div>

                <div class="starmus-card-body">
                    <div class="starmus-script-excerpt">
                        <?php echo wp_kses_post($excerpt); ?>
                    </div>

                    <?php if ($has_audio) { ?>
                        <div class="starmus-audio-preview">
                            <audio controls src="<?php echo esc_url($audio_url); ?>" class="starmus-simple-player"></audio>
                        </div>
                    <?php } ?>
                </div>

                <div class="starmus-card-footer">
                    <a href="<?php echo esc_url($action_url); ?>" class="starmus-btn starmus-btn--primary">
                        <?php echo esc_html($action_label); ?>
                    </a>
                </div>
            </div>
            <style>
                /* Minimal Card Styles */
                .starmus-script-card {
                    background: #fff;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    padding: 1.5rem;
                    margin-bottom: 1.5rem;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                }

                .starmus-card-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 1rem;
                }

                .starmus-card-title {
                    margin: 0;
                    font-size: 1.25rem;
                }

                .starmus-script-excerpt {
                    color: #4a5568;
                    margin-bottom: 1.5rem;
                    font-style: italic;
                    border-left: 3px solid #cbd5e0;
                    padding-left: 1rem;
                }

                .starmus-audio-preview {
                    margin-bottom: 1rem;
                }

                .starmus-simple-player {
                    width: 100%;
                }

                .starmus-btn {
                    display: inline-block;
                    padding: 0.5rem 1rem;
                    background: #3182ce;
                    color: white;
                    text-decoration: none;
                    border-radius: 4px;
                    font-weight: 500;
                }

                .starmus-btn:hover {
                    background: #2b6cb0;
                }

                .starmus-badge {
                    background: #c6f6d5;
                    color: #22543d;
                    padding: 0.25rem 0.5rem;
                    border-radius: 99px;
                    font-size: 0.75rem;
                    font-weight: 600;
                    text-transform: uppercase;
                }
            </style>
<?php
            return ob_get_clean();
        } catch (Throwable $t) {
            StarmusLogger::log($t);
            return '<div class="starmus-error">' . esc_html__('Card Error', 'starmus-audio-recorder') . '</div>';
        }
    }

    /**
     * 3. AJAX Handler
     * Only updates the specific 'calibrated_pace_ms' field.
     */
    public function handle_ajax_save(): void
    {
        try {
            // Ensure it's a POST request
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                wp_send_json_error(__('Invalid request method', 'starmus-audio-recorder'));
            }
            // 1. Verify Request
            $post_id = (int) $_POST['post_id'];
            $pace    = (int) $_POST['pace_ms'];
            $nonce   = $_POST['nonce'];

            if (! wp_verify_nonce($nonce, 'starmus_prosody_save_' . $post_id)) {
                wp_send_json_error(__('Security check failed', 'starmus-audio-recorder'));
            }

            if (! current_user_can('edit_post', $post_id)) {
                wp_send_json_error(__('Permission denied', 'starmus-audio-recorder'));
            }

            // 2. Perform Save via DAL
            $success = $this->dal->save_calibrated_pace($post_id, $pace);
        } catch (\Throwable $throwable) {
            wp_send_json_error(__('An error occurred: ', 'starmus-audio-recorder') . $throwable->getMessage());
            StarmusLogger::log($throwable);
        }

        if ($success) {
            wp_send_json_success(['new_pace' => $pace]);
        } else {
            StarmusLogger::log('Starmus update failed for $post_id=' . $post_id);
            wp_send_json_error(__('Update failed', 'starmus-audio-recorder'));
        }
    }
}
