<?php

namespace Starisian\Sparxstar\Starmus\frontend;

use function class_exists;
use function ob_get_clean;
use function ob_start;

use Starisian\Sparxstar\Starmus\data\StarmusProsodyDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

if ( ! \defined('ABSPATH')) {
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
        add_action('admin_post_starmus_save_script', $this->handle_post_script(...));

        // AJAX Endpoints (Authenticated & Public if needed, usually Auth only for this)
        add_action('wp_ajax_starmus_save_pace', $this->handle_ajax_save(...));
    }

    public function register_shortcodes(): void
    {
        // Register the shortcodes
        add_shortcode('prosody_player', $this->render_shortcode(...));
        add_shortcode('starmus_script_card', $this->render_script_card(...));
        add_shortcode('starmus_script_library', $this->render_script_library_shortcode(...));
        add_shortcode('starmus_add_script_form', $this->render_add_script_form_shortcode(...));
    }

    public function init_dal(): void
    {
        if ($this->dal instanceof StarmusProsodyDAL) {
            StarmusLogger::info('StarmusProsodyDAL loaded');
            return;
        }

        if ( ! class_exists(StarmusProsodyDAL::class)) {
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
            STARMUS_URL . 'assets/css/starmus-prosody-engine.min.css',
            [],
            STARMUS_VERSION
        );

        // Enqueue the JS (assuming you saved the JS Class to a file)
        wp_register_script(
            'starmus-prosody-js',
            STARMUS_URL . 'assets/js/starmus-prosody-engine.min.js',
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
            // If the shortcode was called with default ID (card usage or page context), override it.
            // But if user explicitly passed id="123" in shortcode, that should win?
            // Actually, if we are on a generic "Recorder" page, the shortcode might be plain [prosody_reader].
            // So checking if 'id' matches get_the_ID() is a good heuristic that it's "default".
            if (isset($_GET['script_id']) && absint($_GET['script_id']) > 0 && $post_id === get_the_ID()) {
                $post_id = absint($_GET['script_id']);
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
                    <button type="button" class="tap-zone" id="btn-tap" aria-label="<?php echo esc_attr__('Tap to set pace of the script.', 'starmus-audio-recorder'); ?>">
                        <div class="tap-icon" aria-hidden="true">👆</div>
                        <div class="tap-label"><?php esc_html_e('TAP or SPACEBAR', 'starmus-audio-recorder'); ?></div>
                        <div class="tap-sub"><?php esc_html_e('To Set Script Pace', 'starmus-audio-recorder'); ?></div>
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
                    <!-- Play Button (Left) -->
                    <button id="btn-engage" class="neutral-btn icon-only" title="<?php esc_attr_e('Test Pace', 'starmus-audio-recorder'); ?>">
                        <span class="icon" aria-hidden="true">►</span>
                    </button>

                    <!-- Slider (Middle) -->
                    <div class="fader-group">
                        <span class="fader-label" id="lbl-anxiety"><?php esc_html_e('Fast/Anxiety', 'starmus-audio-recorder'); ?></span>
                        <input type="range" id="pace-regulator" min="1000" max="6000" step="50" aria-labelledby="lbl-anxiety lbl-fatigue">
                        <span class="fader-label" id="lbl-fatigue"><?php esc_html_e('Slow/Fatigue', 'starmus-audio-recorder'); ?></span>
                    </div>

                    <!-- Top Button (Right) -->
                    <button id="btn-top" class="neutral-btn icon-only" title="<?php echo esc_attr__('Top', 'starmus-audio-recorder'); ?>" aria-label="<?php echo esc_attr__('Return to Top', 'starmus-audio-recorder'); ?>">
                        <span class="icon" aria-hidden="true">↺</span>
                    </button>
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
     */
    public function render_script_card(array $atts = []): string
    {
        try {
            $args = shortcode_atts(['id' => 0], $atts, 'starmus_script_card');
            $script_id = (int) $args['id'];

            if ($script_id <= 0) {
                return '';
            }

            $post = get_post($script_id);
            if ( ! $post || $post->post_type !== 'starmus-script') {
                return '';
            }

            // 1. Get Excerpt
            $excerpt = has_excerpt($post) ? $post->post_excerpt : wp_trim_words($post->post_content, 20);

            // 2. Check for Related Audio (Current User)
            $audio_url = '';
            $rec_id = 0;

            if (is_user_logged_in()) {
                $q = new \WP_Query([
                    'post_type' => 'audio-recording',
                    'author' => get_current_user_id(),
                    'title' => $post->post_title, // Matching by title as established
                    'posts_per_page' => 1,
                    'post_status' => 'publish',
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
                    if ( ! empty($media)) {
                        $audio_url = wp_get_attachment_url(reset($media)->ID);
                    }
                }
            }

            // 3. Build Card URL
            // Assumes page with slug 'star-prosody-recorder' exists
            $recorder_url = site_url('/star-prosody-recorder');
            $action_url = add_query_arg('script_id', $script_id, $recorder_url);

            // 4. Determine State
            $has_audio = ! empty($audio_url);
            $action_label = $has_audio ? __('Re-Record Script', 'starmus-audio-recorder') : __('Record Script', 'starmus-audio-recorder');
            $status_class = $has_audio ? 'starmus-status-complete' : 'starmus-status-pending';

            // 5. Check Edit Permissions (Author or greater)
            $can_edit = false;
            $edit_url = '';
            if (current_user_can('publish_posts') || current_user_can('edit_post', $post->ID)) {
                $can_edit = true;
                // Assuming the edit form is accessible via a query arg or a specific page.
                // We use a filter to allow site admins to define the edit page location.

                // IMPROVEMENT: Auto-detect "script-editor" page if it exists
                $base_url = get_permalink();

                // Helper to find the page with the shortcode if manual page lookup fails
                $edit_page_id = 0;
                $edit_page = get_page_by_path('script-editor');

                if ($edit_page) {
                    $edit_page_id = $edit_page->ID;
                } else {
                    // Try to find ANY page with the [starmus_add_script_form] shortcode
                    // Cached in transient for performance
                    $cached_id = get_transient('starmus_editor_page_id');
                    if ($cached_id) {
                        $edit_page_id = (int) $cached_id;
                    } else {
                        global $wpdb;
                        // Search published pages
                        $sql = "SELECT ID FROM {$wpdb->posts}
                                WHERE post_type='page'
                                AND post_status='publish'
                                AND post_content LIKE '%[starmus_add_script_form]%'
                                LIMIT 1";
                        $found_id = $wpdb->get_var($sql);
                        if ($found_id) {
                            $edit_page_id = (int) $found_id;
                            set_transient('starmus_editor_page_id', $found_id, HOUR_IN_SECONDS);
                        }
                    }
                }

                if ($edit_page_id > 0) {
                    $base_url = get_permalink($edit_page_id);
                }

                $base_edit_url = apply_filters('starmus_script_edit_base_url', $base_url);
                $edit_url = add_query_arg(['starmus_action' => 'edit_script', 'script_id' => $script_id], $base_edit_url);
            }

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
                    <?php if ($can_edit) { ?>
                        <a href="<?php echo esc_url($edit_url); ?>" class="starmus-btn starmus-btn--secondary" style="margin-left: 10px;">
                            <?php esc_html_e('Edit Script', 'starmus-audio-recorder'); ?>
                        </a>
                    <?php } ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return '<div class="starmus-error">' . esc_html__('Card Error', 'starmus-audio-recorder') . '</div>';
        }
    }

    public function render_script_library_shortcode(array $atts = []): string
    {
        try {
            wp_enqueue_style('starmus-prosody-css');
            $args = shortcode_atts(
                [
                    'posts_per_page' => 5,
                    'paged' => 1,
                ],
                $atts,
                'starmus_script_library'
            );

            if ( ! is_user_logged_in()) {
                return '<div class="starmus-error">' . esc_html__('You must be logged in to view your script library.', 'starmus-audio-recorder') . '</div>';
            }

            $user_id = get_current_user_id();
            $posts_per_page = (int) $args['posts_per_page'];
            $paged = (int) $args['paged'];

            $query = $this->dal->get_unrecorded_scripts($user_id, $posts_per_page, $paged);

            ob_start();

            if ($query->have_posts()) {
                echo '<div class="starmus-script-library">';
                while ($query->have_posts()) {
                    $query->the_post();
                    echo do_shortcode('[starmus_script_card id="' . get_the_ID() . '"]');
                }
                echo '</div>';

                // Pagination
                $big = 999999999; // need an unlikely integer
                $pagination_links = paginate_links([
                    'base' => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
                    'format' => '?paged=%#%',
                    'current' => max(1, $paged),
                    'total' => $query->max_num_pages,
                    'prev_text' => __('« Previous', 'starmus-audio-recorder'),
                    'next_text' => __('Next »', 'starmus-audio-recorder'),
                ]);

                if ($pagination_links) {
                    echo '<div class="starmus-pagination">' . $pagination_links . '</div>';
                }
            } else {
                echo '<div class="starmus-info">' . esc_html__('No unrecorded scripts found.', 'starmus-audio-recorder') . '</div>';
            }

            wp_reset_postdata();

            return ob_get_clean();
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return '<div class="starmus-error">' . esc_html__('Library Error', 'starmus-audio-recorder') . '</div>';
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
            $pace = (int) $_POST['pace_ms'];
            $nonce = $_POST['nonce'];

            if ( ! wp_verify_nonce($nonce, 'starmus_prosody_save_' . $post_id)) {
                wp_send_json_error(__('Security check failed', 'starmus-audio-recorder'));
            }

            if ( ! current_user_can('edit_post', $post_id)) {
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

    /**
     * Renders the Add/Edit Script Form.
     */
    public function render_add_script_form_shortcode(array $atts = []): string
    {
        if ( ! is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to add or edit scripts.', 'starmus-audio-recorder') . '</p>';
        }

        // Logic to handle "Edit Mode"
        $script_id = 0;
        $title = '';
        $content = '';
        $sel_lang = '';
        $sel_dial = '';
        $heading = __('Add New Script', 'starmus-audio-recorder');
        $btn_label = __('Save Script', 'starmus-audio-recorder');

        if (isset($_GET['script_id'])) {
            $script_id = absint($_GET['script_id']);
        }

        // If explicitly in edit mode via query arg
        if (isset($_GET['starmus_action']) && $_GET['starmus_action'] === 'edit_script' && $script_id > 0) {
            $post = get_post($script_id);
            if ($post && $post->post_type === 'starmus-script') {
                // Check permissions
                if ( ! current_user_can('edit_post', $script_id)) {
                    return '<div class="starmus-error">' . esc_html__('You do not have permission to edit this script.', 'starmus-audio-recorder') . '</div>';
                }

                $title = $post->post_title;
                $content = $post->post_content;
                $heading = __('Edit Script', 'starmus-audio-recorder');
                $btn_label = __('Update Script', 'starmus-audio-recorder');

                // Get Terms
                $langs = get_the_terms($script_id, 'starmus_tax_language');
                if ($langs && ! is_wp_error($langs)) {
                    $sel_lang = $langs[0]->slug ?? '';
                }
                $dials = get_the_terms($script_id, 'starmus_tax_dialect');
                if ($dials && ! is_wp_error($dials)) {
                    $sel_dial = $dials[0]->slug ?? '';
                }
            }
        }

        // Get Taxonomies for Dropdowns
        $languages = get_terms(['taxonomy' => 'starmus_tax_language', 'hide_empty' => false]);
        $dialects = get_terms(['taxonomy' => 'starmus_tax_dialect', 'hide_empty' => false]);

        // Ensure Styles are loaded
        wp_enqueue_style('starmus-audio-recorder-styles');

        ob_start();
        ?>
        <div class="starmus-script-form-container sparxstar-glass-card starmus-recorder-form">
            <h2><?php echo esc_html($heading); ?></h2>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="starmus-script-form">
                <input type="hidden" name="action" value="starmus_save_script">
                <?php wp_nonce_field('starmus_save_script_nonce', 'starmus_nonce'); ?>
                <?php if ($script_id > 0) { ?>
                    <input type="hidden" name="script_id" value="<?php echo esc_attr((string) $script_id); ?>">
                <?php } ?>

                <div class="starmus-form-group">
                    <label for="starmus_script_title"><?php esc_html_e('Title', 'starmus-audio-recorder'); ?></label>
                    <input type="text" id="starmus_script_title" name="starmus_script_title" value="<?php echo esc_attr($title); ?>" required class="widefat">
                </div>

                <div class="starmus-form-group">
                    <label for="starmus_script_content"><?php esc_html_e('Content (The Script)', 'starmus-audio-recorder'); ?></label>
                    <?php
                    $editor_settings = [
                        'media_buttons' => false,
                        'textarea_name' => 'starmus_script_content',
                        'textarea_rows' => 10,
                        'teeny' => true,
                        'quicktags' => false,
                        'editor_class' => 'widefat',
                    ];
                    // Clean up block comments for display if they exist
                    if (str_contains((string) $content, '<!-- wp:')) {
                        $content = preg_replace('/<!-- \/?wp:.*? -->/', '', (string) $content);
                    }
                    wp_editor($content, 'starmus_script_content', $editor_settings);
                    ?>
                </div>

                <div class="starmus-form-row">
                    <div class="starmus-form-group half">
                        <label for="starmus_script_language"><?php esc_html_e('Language', 'starmus-audio-recorder'); ?></label>
                        <select id="starmus_script_language" name="starmus_script_language" required>
                            <option value=""><?php esc_html_e('Select Language', 'starmus-audio-recorder'); ?></option>
                            <?php if ( ! is_wp_error($languages)) {
                                foreach ($languages as $term) {
                                    echo '<option value="' . esc_attr($term->slug) . '" ' . selected($sel_lang, $term->slug, false) . '>' . esc_html($term->name) . '</option>';
                                }
                            } ?>
                        </select>
                    </div>

                    <div class="starmus-form-group half">
                        <label for="starmus_script_dialect"><?php esc_html_e('Dialect', 'starmus-audio-recorder'); ?></label>
                        <select id="starmus_script_dialect" name="starmus_script_dialect">
                            <option value=""><?php esc_html_e('Select Dialect (Optional)', 'starmus-audio-recorder'); ?></option>
                            <?php if ( ! is_wp_error($dialects)) {
                                foreach ($dialects as $term) {
                                    echo '<option value="' . esc_attr($term->slug) . '" ' . selected($sel_dial, $term->slug, false) . '>' . esc_html($term->name) . '</option>';
                                }
                            } ?>
                        </select>
                    </div>
                </div>

                <div class="starmus-form-actions">
                    <button type="submit" class="starmus-btn starmus-btn--primary"><?php echo esc_html($btn_label); ?></button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle the POST submission of the script form.
     */
    public function handle_post_script(): void
    {
        // 1. Check Nonce
        if ( ! isset($_POST['starmus_nonce']) || ! wp_verify_nonce($_POST['starmus_nonce'], 'starmus_save_script_nonce')) {
            wp_die(__('Security check failed', 'starmus-audio-recorder'));
        }

        // 2. Check Auth
        if ( ! is_user_logged_in()) {
            wp_die(__('You must be logged in.', 'starmus-audio-recorder'));
        }

        // 3. Sanitize
        $title = sanitize_text_field($_POST['starmus_script_title']);
        $content = wp_kses_post($_POST['starmus_script_content']); // Allow HTML since we used wp_editor
        $lang = sanitize_text_field($_POST['starmus_script_language']);
        $dial = sanitize_text_field($_POST['starmus_script_dialect']);
        $script_id = isset($_POST['script_id']) ? (int) $_POST['script_id'] : 0;

        // 4. Insert/Update Post
        $post_data = [
            'post_title' => $title,
            'post_content' => $content, // Storing raw text context for scripts
            'post_type' => 'starmus-script',
            'post_status' => 'publish',
        ];

        if ($script_id > 0) {
            // Edit Mode
            if ( ! current_user_can('edit_post', $script_id)) {
                wp_die(__('Permission denied.', 'starmus-audio-recorder'));
            }
            $post_data['ID'] = $script_id;
            $pid = wp_update_post($post_data);
        } else {
            // New Mode
            $post_data['post_author'] = get_current_user_id();
            $pid = wp_insert_post($post_data);
        }

        if (is_wp_error($pid) || $pid === 0) {
            wp_die(__('Error saving script.', 'starmus-audio-recorder'));
        }

        // 5. Set Terms
        if ($lang) {
            wp_set_object_terms($pid, $lang, 'starmus_tax_language', false); // false = replace
        }
        if ($dial) {
            wp_set_object_terms($pid, $dial, 'starmus_tax_dialect', false);
        }

        // 6. Redirect back
        // Referer check
        $redirect_url = wp_get_referer();
        if ( ! $redirect_url) {
            $redirect_url = home_url();
        }

        // Strip previous status args to avoid buildup
        $redirect_url = remove_query_arg(['starmus_status', 'script_id', 'starmus_action'], $redirect_url);

        $redirect_url = add_query_arg([
            'starmus_status' => 'saved',
            'script_id' => $pid,
        ], $redirect_url);

        wp_safe_redirect($redirect_url);
        exit;
    }
}
