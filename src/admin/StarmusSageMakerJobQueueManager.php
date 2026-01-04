<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\admin;

if ( ! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\admin\interfaces\IStarmusAdminInterface;
use Starisian\Sparxstar\Starmus\includes\StarmusSageMakerJobRepository;
use Starisian\Sparxstar\Starmus\integrations\StarmusSageMakerClient;

final readonly class StarmusSageMakerJobQueueManager implements IStarmusAdminInterface
{
    private StarmusSageMakerJobRepository $repository;

    private StarmusSageMakerClient $manager;

    public function __construct()
    {
        // In a real DI container, these would be injected.
        $this->repository = new StarmusSageMakerJobRepository();
        $this->manager    = new StarmusSageMakerClient($this->repository);

        $this->register_hooks();
    }

    /**
     * Registers WordPress hooks for admin actions and AJAX handlers.
     */
    private function register_hooks(): void
    {
        add_action('admin_post_starmus_delete_job', $this->handle_delete_job(...));
        // AJAX handlers for dashboard widget quick actions
        add_action('wp_ajax_starmus_get_jobs', $this->ajax_get_jobs(...));
        add_action('wp_ajax_starmus_retry_job', $this->ajax_retry_job(...));
        add_action('wp_ajax_starmus_delete_job', $this->ajax_delete_job(...));
    }

    public function render(): void
    {
        if ( ! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'starmus-audio-recorder'));
        }

        $job_id = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';

        echo '<div class="wrap"><h1>' . esc_html__('starmus SageMaker Jobs', 'starmus-audio-recorder') . '</h1>';

        if ($job_id) {
            $this->render_detail_view($job_id);
        } else {
            $this->render_list_view();
        }

        echo '</div>';
    }

    /**
     * Renders the detailed view for a specific SageMaker job.
     *
     * @param string $job_id The ID of the job to display details for.
     */
    private function render_detail_view(string $job_id): void
    {
        $job = $this->repository->find($job_id);
        if ( ! $job) {
            echo '<p>' . esc_html__('Job not found.', 'starmus-audio-recorder') . '</p>';
            echo '<p><a href="' . esc_url(menu_page_url('starmus-sagemaker-jobs', false)) . '">' . esc_html__('Back to list', 'starmus-audio-recorder') . '</a></p>';
            return;
        }

        echo '<h2>' . esc_html($job_id) . '</h2>';
        echo '<p><strong>' . esc_html__('Status:', 'starmus-audio-recorder') . '</strong> ' . esc_html($job['status'] ?? 'unknown') . '</p>';
        echo '<p><strong>' . esc_html__('Attempts:', 'starmus-audio-recorder') . '</strong> ' . esc_html($job['attempts'] ?? 0) . '</p>';

        if (isset($job['created_at'])) {
            echo '<p><strong>' . esc_html__('Created:', 'starmus-audio-recorder') . '</strong> ' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $job['created_at'])) . '</p>';
        }

        if (isset($job['finished_at'])) {
            echo '<p><strong>' . esc_html__('Finished:', 'starmus-audio-recorder') . '</strong> ' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $job['finished_at'])) . '</p>';
        }

        if (isset($job['error_message'])) {
            echo '<h3>' . esc_html__('Error', 'starmus-audio-recorder') . '</h3>';
            echo '<pre>' . esc_html($job['error_message']) . '</pre>';
        }

        if (isset($job['result'])) {
            echo '<h3>' . esc_html__('Result', 'starmus-audio-recorder') . '</h3>';
            echo '<pre style="white-space:pre-wrap;">' . esc_html($job['result']) . '</pre>';
        }

        echo '<p><a href="' . esc_url(menu_page_url('starmus-sagemaker-jobs', false)) . '">' . esc_html__('Back to list', 'starmus-audio-recorder') . '</a></p>';
    }

    /**
     * Renders the list view showing all SageMaker jobs with pagination.
     */
    private function render_list_view(): void
    {
        $page     = isset($_GET['paged']) ? max(1, \intval($_GET['paged'])) : 1;
        $per_page = 20;

        $jobs        = $this->repository->get_paged_jobs($page, $per_page);
        $total_jobs  = $this->repository->get_total_count();
        $total_pages = ceil($total_jobs / $per_page);

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Job ID', 'starmus-audio-recorder') . '</th>';
        echo '<th>' . esc_html__('Status', 'starmus-audio-recorder') . '</th>';
        echo '<th>' . esc_html__('Attempts', 'starmus-audio-recorder') . '</th>';
        echo '<th>' . esc_html__('Created', 'starmus-audio-recorder') . '</th>';
        echo '<th>' . esc_html__('Actions', 'starmus-audio-recorder') . '</th>';
        echo '</tr></thead><tbody>';

        if ($jobs === []) {
            echo '<tr><td colspan="5">' . esc_html__('No jobs found.', 'starmus-audio-recorder') . '</td></tr>';
        } else {
            foreach ($jobs as $id => $job) {
                $created = isset($job['created_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $job['created_at']) : '';
                echo '<tr>';
                echo '<td><strong>' . esc_html($id) . '</strong></td>';
                echo '<td>' . esc_html($job['status'] ?? 'unknown') . '</td>';
                echo '<td>' . esc_html($job['attempts'] ?? 0) . '</td>';
                echo '<td>' . esc_html($created) . '</td>';

                $view_url = add_query_arg(
                    [
                'page'   => 'starmus-sagemaker-jobs',
                'job_id' => $id,
                    ],
                    admin_url('admin.php')
                );
                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=starmus_delete_job&job_id=' . rawurlencode((string) $id)), 'starmus_delete_job_' . $id);
                echo '<td><a href="' . esc_url($view_url) . '">' . esc_html__('View', 'starmus-audio-recorder') . '</a> | <a href="' . esc_url($delete_url) . '">' . esc_html__('Delete', 'starmus-audio-recorder') . '</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        // Render pagination
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links(
                [
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'prev_text' => __('&laquo;', 'starmus-audio-recorder'),
            'next_text' => __('&raquo;', 'starmus-audio-recorder'),
            'total'     => (int) $total_pages,
            'current'   => max(1, (int) $page),
                ]
            );
            echo '</div></div>';
        }
    }

    /**
     * Handles the deletion of a SageMaker job via admin POST request.
     *
     * Verifies user permissions and nonce before deleting the specified job.
     * Redirects back to the jobs list page after completion.
     */
    public function handle_delete_job(): void
    {
        if ( ! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'starmus-audio-recorder'));
        }

        $job_id = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';

        if ($job_id && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'starmus_delete_job_' . $job_id)) {
            $this->manager->delete_job($job_id);
        }

        wp_safe_redirect(admin_url('admin.php?page=starmus-sagemaker-jobs'));
        exit;
    }

    /**
     * AJAX: return jobs summary and recent jobs.
     */
    public function ajax_get_jobs(): void
    {
        if ( ! current_user_can('manage_options')) {
            wp_send_json_error('insufficient_permissions', 403);
        }

        check_ajax_referer('starmus_jobs_nonce', 'nonce');

        $counts = $this->manager->get_job_counts();
        $recent = $this->repository->get_recent_jobs(10);

        wp_send_json_success(
            [
        'counts' => $counts,
        'recent' => $recent,
            ]
        );
    }

    /**
     * AJAX: retry a job (reschedule it).
     */
    public function ajax_retry_job(): void
    {
        if ( ! current_user_can('manage_options')) {
            wp_send_json_error('insufficient_permissions', 403);
        }

        check_ajax_referer('starmus_jobs_nonce', 'nonce');

        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        if (empty($job_id)) {
            wp_send_json_error('missing_job_id', 400);
        }

        $result = $this->manager->retry_job($job_id);

        if ($result) {
            wp_send_json_success(['message' => 'scheduled']);
        } else {
            wp_send_json_error('job_not_found', 404);
        }
    }

    /**
     * AJAX: delete a job.
     */
    public function ajax_delete_job(): void
    {
        if ( ! current_user_can('manage_options')) {
            wp_send_json_error('insufficient_permissions', 403);
        }

        check_ajax_referer('starmus_jobs_nonce', 'nonce');

        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        if (empty($job_id)) {
            wp_send_json_error('missing_job_id', 400);
        }

        $this->manager->delete_job($job_id);
        wp_send_json_success(['message' => 'deleted']);
    }

    /**
     * Returns the WordPress capability required to access the Settings page.
     *
     * This method fulfills the get_capability() contract from the interface.
     * Only users with the 'manage_options' capability (typically Administrators)
     * will be able to see and access this menu item.
     *
     * @return string The capability string.
     */
    public function get_capability(): string
    {
        return 'manage_options';
    }

    /**
     * Determines if this admin page should be registered and displayed.
     *
     * This static method fulfills the is_active() contract from the interface.
     * Returning `true` ensures the orchestrator will add the "Settings" menu item.
     * If you were developing a new feature, you could set this to `false` to hide it
     * from the admin menu without having to remove the class or its wiring.
     *
     * @return bool True if the page should be registered, false otherwise.
     */
    public static function is_active(): bool
    {
        return true;
    }
}
