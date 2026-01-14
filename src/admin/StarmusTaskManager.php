<?php

/**
 * Starmus Task Manager
 *
 * Manual task assignment workflow for Starmus assets.
 * Defines admin interfaces and frontend dashboards for task management.
 *
 * @package Starisian\Sparxstar\Starmus\admin
 */

namespace Starisian\Sparxstar\Starmus\admin;

use WP_Query;

if ( ! defined('ABSPATH')) {
    exit;
}

class StarmusTaskManager
{

    // Define allowed CPTs here
    // Updated to match actual CPT names from StarmusPostTypeLoader
    private array $post_types = ['starmus-script', 'audio-recording', 'starmus_transcript'];

    public function __construct()
    {
        // Admin Menu
        add_action('admin_menu', $this->register_admin_menu(...));

        // Assets
        add_action('admin_enqueue_scripts', $this->admin_scripts(...));
        add_action('wp_enqueue_scripts', $this->frontend_scripts(...));

        // AJAX Handlers
        add_action('wp_ajax_starmus_save_task_admin', $this->handle_admin_save(...));
        add_action('wp_ajax_starmus_update_status_user', $this->handle_user_save(...));

        // Shortcode
        add_shortcode('starmus_tasks', $this->render_user_dashboard(...));
    }

    /* ==========================================================================
       PART 1: ADMIN TASK BOARD
       ========================================================================== */

    public function register_admin_menu(): void
    {
        // Changed capability to 'edit_others_posts' to allow Editors/Managers
        add_menu_page(
            'Starmus Tasks',
            'Starmus Tasks',
            'edit_others_posts',
            'starmus-tasks',
            $this->render_admin_page(...),
            'dashicons-clipboard',
            25
        );
    }

    public function admin_scripts($hook): void
    {
        if ($hook !== 'toplevel_page_starmus-tasks') {
            return;
        }

        wp_enqueue_style('starmus-admin-css', false);
        wp_add_inline_style('starmus-admin-css', "
            .starmus-modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; }
            .starmus-modal-content { background:#fff; width:500px; margin:50px auto; padding:20px; box-shadow:0 4px 10px rgba(0,0,0,0.2); border-radius:4px; }
            .starmus-modal h2 { margin-top:0; }
            .starmus-row { margin-bottom:15px; }
            .starmus-row label { display:block; font-weight:bold; margin-bottom:5px; }
            .starmus-row input, .starmus-row select, .starmus-row textarea { width:100%; box-sizing: border-box; }
            .status-badge { padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 11px; text-transform: uppercase; }
            .status-unassigned { background: #e5e5e5; color: #555; }
            .status-assigned { background: #b3e5fc; color: #0277bd; }
            .status-in_progress { background: #fff9c4; color: #fbc02d; }
            .status-submitted { background: #e1bee7; color: #7b1fa2; }
            .status-closed { background: #c8e6c9; color: #2e7d32; }
            .status-rejected { background: #ffcdd2; color: #c62828; }
            .post-type-label { font-size: 10px; background: #eee; padding: 2px 5px; border-radius: 3px; margin-left: 5px; }
        ");
    }

    public function render_admin_page(): void
    {
        // Handle Filters
        $filter_status = isset($_GET['f_status']) ? sanitize_text_field($_GET['f_status']) : '';
        $filter_user   = isset($_GET['f_user']) ? intval($_GET['f_user']) : '';
        $filter_cat    = isset($_GET['f_cat']) ? sanitize_text_field($_GET['f_cat']) : '';

        // Query Args
        $args = [
            'post_type'      => $this->post_types,
            'posts_per_page' => 50, // Limit to 50 for performance, add pagination if needed
            'meta_query'     => ['relation' => 'AND']
        ];

        // REMOVED 'EXISTS' check. We now handle empty meta in the loop.

        if ($filter_status) {
            // If filtering for 'unassigned', we must also include posts where the key does NOT exist
            if ($filter_status === 'unassigned') {
                $args['meta_query'][] = [
                    'relation' => 'OR',
                    ['key' => 'starmus_status', 'value' => 'unassigned'],
                    ['key' => 'starmus_status', 'compare' => 'NOT EXISTS']
                ];
            } else {
                $args['meta_query'][] = ['key' => 'starmus_status', 'value' => $filter_status];
            }
        } else {
            // "When they are closed they should drop away"
            // Default View: Hide closed/rejected unless specifically asked for
            $args['meta_query'][] = [
                'relation' => 'OR',
                [
                    'key'     => 'starmus_status',
                    'value'   => 'closed',
                    'compare' => '!='
                ],
                [
                    'key'     => 'starmus_status',
                    'compare' => 'NOT EXISTS'
                ]
            ];
        }

        if ($filter_user) {
            $args['meta_query'][] = ['key' => 'starmus_assign_to', 'value' => $filter_user];
        }

        if ($filter_cat) {
            $args['meta_query'][] = ['key' => 'starmus_task_cat', 'value' => $filter_cat];
        }

        $query = new WP_Query($args);

        // Get users with edit capabilities
        $users = get_users(['capability' => 'edit_posts']);

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Starmus Task Assignment Board</h1>

            <!-- Filters -->
            <div class="tablenav top">
                <form method="get">
                    <input type="hidden" name="page" value="starmus-tasks" />
                    <div class="alignleft actions">
                        <select name="f_status">
                            <option value="">All Statuses</option>
                            <?php foreach ($this->get_statuses() as $k => $v): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($filter_status, $k); ?>><?php echo esc_html($v); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="f_user">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user->ID; ?>" <?php selected($filter_user, $user->ID); ?>><?php echo esc_html($user->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="f_cat">
                            <option value="">All Categories</option>
                            <?php foreach ($this->get_categories() as $k => $v): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($filter_cat, $k); ?>><?php echo esc_html($v); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="submit" class="button" value="Filter">
                    </div>
                </form>
            </div>

            <!-- Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Asset Title</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>Priority</th>
                        <th>Due Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($query->have_posts()): while ($query->have_posts()): $query->the_post();
                            $pid = get_the_ID();
                            // Use get_post_meta single=true to avoid array issues, default empty strings
                            $cat = get_post_meta($pid, 'starmus_task_cat', true);
                            $status = get_post_meta($pid, 'starmus_status', true);
                            if (empty($status)) {
                                $status = 'unassigned';
                            }

                            $assign_to = get_post_meta($pid, 'starmus_assign_to', true);
                            $priority = get_post_meta($pid, 'starmus_priority', true) ?: 'normal';
                            $due = get_post_meta($pid, 'starmus_due_date', true);
                            $instruct = get_post_meta($pid, 'starmus_instruct', true);

                            $user_info = $assign_to ? get_userdata($assign_to) : false;
                            $user_name = $user_info ? $user_info->display_name : '—';

                            // Convert MySQL Date to HTML5 Datetime-local format for the input
                            $due_local = $due ? date('Y-m-d\TH:i', strtotime($due)) : '';

                            // Safe Data Packet
                            $task_data = [
                                'id' => $pid,
                                'cat' => $cat,
                                'status' => $status,
                                'assign' => $assign_to,
                                'priority' => $priority,
                                'due' => $due_local,
                                'instruct' => $instruct
                            ];
                            ?>
                            <tr>
                                <td>
                                    <strong><a href="<?php echo get_edit_post_link($pid); ?>"><?php the_title(); ?></a></strong>
                                    <span class="post-type-label"><?php echo get_post_type(); ?></span>
                                </td>
                                <td><?php echo $cat ? ucfirst($cat) : '—'; ?></td>
                                <td><span class="status-badge status-<?php echo esc_attr($status); ?>"><?php echo ucfirst(str_replace('_', ' ', $status)); ?></span></td>
                                <td><?php echo esc_html($user_name); ?></td>
                                <td><?php echo ucfirst($priority); ?></td>
                                <td><?php echo $due ? date('M d, g:i a', strtotime($due)) : '—'; ?></td>
                                <td>
                                    <button class="button small starmus-edit-btn"
                                        data-task="<?php echo esc_attr(json_encode($task_data)); ?>">Quick Edit</button>
                                </td>
                            </tr>
                        <?php endwhile;
                    else: ?>
                        <tr>
                            <td colspan="7">No assets found.</td>
                        </tr>
                    <?php endif;
                    wp_reset_postdata(); ?>
                </tbody>
            </table>
        </div>

        <!-- Edit Modal -->
        <div id="starmus-modal" class="starmus-modal">
            <div class="starmus-modal-content">
                <h2>Manage Task</h2>
                <form id="starmus-admin-form">
                    <input type="hidden" id="edit_post_id" name="post_id">
                    <?php wp_nonce_field('starmus_admin_action', 'starmus_nonce'); ?>

                    <div class="starmus-row">
                        <label>Assigned To</label>
                        <select name="starmus_assign_to" id="edit_assign_to">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user->ID; ?>"><?php echo esc_html($user->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="starmus-row">
                        <label>Status</label>
                        <select name="starmus_status" id="edit_status">
                            <?php foreach ($this->get_statuses() as $k => $v): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="starmus-row">
                        <label>Priority</label>
                        <select name="starmus_priority" id="edit_priority">
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>

                    <div class="starmus-row">
                        <label>Due Date</label>
                        <!-- HTML5 Date Picker for safety -->
                        <input type="datetime-local" name="starmus_due_date" id="edit_due_date">
                    </div>

                    <div class="starmus-row">
                        <label>Instructions</label>
                        <textarea name="starmus_instruct" id="edit_instruct" rows="6"></textarea>
                    </div>

                    <div style="text-align:right;">
                        <button type="button" class="button" onclick="document.getElementById('starmus-modal').style.display='none'">Cancel</button>
                        <button type="submit" class="button button-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Open Modal
                $('.starmus-edit-btn').on('click', function() {
                    var btn = $(this);
                    // Safe parsing of JSON data
                    var data = btn.data('task');

                    $('#edit_post_id').val(data.id);
                    $('#edit_assign_to').val(data.assign);
                    $('#edit_status').val(data.status);
                    $('#edit_priority').val(data.priority);
                    $('#edit_due_date').val(data.due);
                    $('#edit_instruct').val(data.instruct);
                    $('#starmus-modal').show();
                });

                // Save via AJAX
                $('#starmus-admin-form').on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var btn = form.find('button[type="submit"]');
                    btn.prop('disabled', true).text('Saving...');

                    var data = form.serialize();
                    data += '&action=starmus_save_task_admin';

                    $.post(ajaxurl, data, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error saving: ' + (response.data || 'Unknown error'));
                            btn.prop('disabled', false).text('Save Changes');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function handle_admin_save(): void
    {
        check_ajax_referer('starmus_admin_action', 'starmus_nonce');

        if ( ! current_user_can('edit_others_posts')) {
            wp_send_json_error('Permission denied');
        }

        $post_id = intval($_POST['post_id']);
        if ( ! $post_id) {
            wp_send_json_error('No ID');
        }

        $old_status = get_post_meta($post_id, 'starmus_status', true);
        if (empty($old_status)) {
            $old_status = 'unassigned';
        }

        $new_status = sanitize_text_field($_POST['starmus_status']);

        // Date Conversion: HTML5 datetime-local (Y-m-d\TH:i) -> MySQL (Y-m-d H:i:s)
        $raw_date = sanitize_text_field($_POST['starmus_due_date']);
        $mysql_date = $raw_date ? date('Y-m-d H:i:s', strtotime($raw_date)) : '';

        // Update Fields
        update_post_meta($post_id, 'starmus_assign_to', intval($_POST['starmus_assign_to']));
        update_post_meta($post_id, 'starmus_status', $new_status);
        update_post_meta($post_id, 'starmus_priority', sanitize_text_field($_POST['starmus_priority']));
        update_post_meta($post_id, 'starmus_due_date', $mysql_date);
        update_post_meta($post_id, 'starmus_instruct', sanitize_textarea_field($_POST['starmus_instruct']));

        // Track Assigned By
        update_post_meta($post_id, 'starmus_assign_by', get_current_user_id());

        // Timestamp Logic
        $now = current_time('mysql');

        // If becoming assigned for first time or status changed to assigned
        if ($new_status === 'assigned' && $old_status === 'unassigned') {
            update_post_meta($post_id, 'starmus_assign_time', $now);

            // Generate Strong UUID
            if ( ! get_post_meta($post_id, 'starmus_assign_id', true)) {
                $uuid = wp_generate_uuid4(); // Native WP UUID
                update_post_meta($post_id, 'starmus_assign_id', $uuid);
            }
        }

        // If closing
        if ($new_status === 'closed' && $old_status !== 'closed') {
            update_post_meta($post_id, 'starmus_done_time', $now);
        }

        wp_send_json_success();
    }


    /* ==========================================================================
       PART 2: USER DASHBOARD (Frontend)
       ========================================================================== */

    public function frontend_scripts(): void
    {
        wp_enqueue_style('starmus-front-css', false);
        wp_add_inline_style('starmus-front-css', "
            .starmus-dashboard table { width:100%; border-collapse: collapse; margin: 20px 0; }
            .starmus-dashboard th, .starmus-dashboard td { border: 1px solid #ddd; padding: 12px; text-align: left; vertical-align:top; }
            .starmus-dashboard th { background-color: #f2f2f2; }
            .starmus-instruct { font-size: 0.9em; color: #555; background: #fafafa; padding: 10px; border-radius: 4px; margin-top:5px; white-space: pre-wrap; }
            .starmus-actions { display: flex; gap: 10px; align-items: center; }
            .starmus-meta { font-size: 0.85em; color: #888; margin-bottom: 5px; }
        ");

        wp_enqueue_script('jquery');
    }

    public function render_user_dashboard($atts): string|false
    {
        if ( ! is_user_logged_in()) {
            return '<p>Please log in to view your tasks.</p>';
        }

        $current_user_id = get_current_user_id();

        $args = [
            'post_type'      => $this->post_types,
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => 'starmus_assign_to',
                    'value' => $current_user_id
                ],
                // Exclude closed/rejected from main view if desired, but request implies list all
            ]
        ];

        $query = new WP_Query($args);

        ob_start();
        ?>
        <div class="starmus-dashboard">
            <h3>My Tasks</h3>
            <table>
                <thead>
                    <tr>
                        <th width="30%">Asset / Task</th>
                        <th width="15%">Due Date</th>
                        <th width="35%">Instructions</th>
                        <th width="20%">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($query->have_posts()): while ($query->have_posts()): $query->the_post();
                            $pid = get_the_ID();
                            $status = get_post_meta($pid, 'starmus_status', true) ?: 'unassigned';
                            $cat = get_post_meta($pid, 'starmus_task_cat', true);
                            $due = get_post_meta($pid, 'starmus_due_date', true);
                            $instruct = get_post_meta($pid, 'starmus_instruct', true);

                            // Status Logic for User
                            $can_edit = ! in_array($status, ['closed', 'rejected']);
                            ?>
                            <tr>
                                <td>
                                    <strong><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></strong>
                                    <div class="starmus-meta">
                                        <?php echo ucfirst(str_replace('_', ' ', get_post_type())); ?>
                                        | Cat: <?php echo ucfirst($cat); ?>
                                    </div>
                                </td>
                                <td><?php echo $due ? date('M d, Y', strtotime($due)) . '<br><small>' . date('g:i a', strtotime($due)) . '</small>' : '—'; ?></td>
                                <td>
                                    <?php if ($instruct): ?>
                                        <div class="starmus-instruct"><?php echo esc_html($instruct); ?></div>
                                    <?php else: ?>
                                        <span style="color:#999;">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($can_edit): ?>
                                        <form class="starmus-user-status-form" data-id="<?php echo $pid; ?>">
                                            <div class="starmus-actions">
                                                <select name="new_status">
                                                    <option value="assigned" <?php selected($status, 'assigned'); ?>>Assigned</option>
                                                    <option value="in_progress" <?php selected($status, 'in_progress'); ?>>In Progress</option>
                                                    <option value="submitted" <?php selected($status, 'submitted'); ?>>Submitted</option>
                                                </select>
                                                <button type="submit">Update</button>
                                            </div>
                                            <span class="msg"></span>
                                        </form>
                                    <?php else: ?>
                                        <strong><?php echo ucfirst($status); ?></strong>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile;
                    else: ?>
                        <tr>
                            <td colspan="4">No tasks assigned to you.</td>
                        </tr>
                    <?php endif;
                    wp_reset_postdata(); ?>
                </tbody>
            </table>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('.starmus-user-status-form').on('submit', function(e) {
                    e.preventDefault();
                    var form = $(this);
                    var pid = form.data('id');
                    var status = form.find('select').val();
                    var btn = form.find('button');
                    var msg = form.find('.msg');

                    btn.prop('disabled', true).text('...');

                    $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                        action: 'starmus_update_status_user',
                        post_id: pid,
                        status: status,
                        nonce: '<?php echo wp_create_nonce("starmus_user_action"); ?>'
                    }, function(res) {
                        btn.prop('disabled', false).text('Update');
                        if (res.success) {
                            msg.css('color', 'green').text('Saved');
                            setTimeout(function() {
                                msg.text('');
                            }, 2000);
                        } else {
                            msg.css('color', 'red').text(res.data);
                        }
                    });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    public function handle_user_save(): void
    {
        check_ajax_referer('starmus_user_action', 'nonce');

        $post_id = intval($_POST['post_id']);
        $new_status = sanitize_text_field($_POST['status']);
        $user_id = get_current_user_id();

        // Security: Ensure User is assigned to this task
        $assigned_to = get_post_meta($post_id, 'starmus_assign_to', true);
        if ($assigned_to != $user_id) {
            wp_send_json_error('Not authorized for this task');
        }

        // Logic: Allowed statuses for users
        $allowed = ['assigned', 'in_progress', 'submitted'];
        if ( ! in_array($new_status, $allowed)) {
            wp_send_json_error('Invalid status');
        }

        // Check if currently closed/rejected (User cannot reopen)
        $current_status = get_post_meta($post_id, 'starmus_status', true);
        if (in_array($current_status, ['closed', 'rejected'])) {
            wp_send_json_error('Task is closed');
        }

        update_post_meta($post_id, 'starmus_status', $new_status);

        // Optional: If you ever want to track submission time, uncomment below:
        // if ($new_status === 'submitted' && $current_status !== 'submitted') {
        //     update_post_meta($post_id, 'starmus_submitted_time', current_time('mysql'));
        // }

        wp_send_json_success();
    }

    /* ==========================================================================
       HELPERS
       ========================================================================== */

    private function get_statuses(): array
    {
        return [
            'unassigned' => 'Unassigned',
            'assigned' => 'Assigned',
            'in_progress' => 'In Progress',
            'submitted' => 'Submitted',
            'closed' => 'Closed',
            'rejected' => 'Rejected'
        ];
    }

    private function get_categories(): array
    {
        return [
            'performance' => 'Performance',
            'annotation' => 'Annotation',
            'translation' => 'Translation',
            'review' => 'Review',
            'delivery' => 'Delivery'
        ];
    }
}
