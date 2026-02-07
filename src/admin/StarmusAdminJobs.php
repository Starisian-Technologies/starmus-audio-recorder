<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\admin;

use Starisian\Sparxstar\Starmus\data\StarmusSageMakerJobRepository;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

if ( ! \defined('ABSPATH')) {
    exit;
}

/**
 * Admin view for managing Starmus SageMaker Jobs.
 * Displays a list of jobs, detailed views, and handles manual retries/deletes.
 */
final class StarmusAdminJobs
{
    private StarmusSageMakerJobRepository $repository;

    public function __construct(
        StarmusSageMakerJobRepository $repository
    ) {
        $this->repository = $repository;
        $this->register_hooks();
    }

    private function register_hooks(): void
    {
        add_action('admin_menu', $this->add_menu_page(...));
        add_action('admin_post_starmus_delete_job', $this->handle_delete_job(...));
        add_action('wp_ajax_starmus_retry_job', $this->ajax_retry_job(...));
    }

    public function add_menu_page(): void
    {
        add_submenu_page(
            'starmus-audio-recorder', // Parent slug (assumed existing)
            __('Transcription Jobs', 'starmus-audio-recorder'),
            __('Jobs', 'starmus-audio-recorder'),
            'manage_options',
            'starmus-sagemaker-jobs',
            $this->render(...)
        );
    }

    public function render(): void
    {
        try {
            if ( ! current_user_can('manage_options')) {
                wp_die(esc_html__('Insufficient permissions', 'starmus-audio-recorder'));
            }

            $job_id = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';

            echo '<div class="wrap"><h1>' . esc_html__('Starmus Transcription Jobs', 'starmus-audio-recorder') . '</h1>';

            if ($job_id) {
                $this->render_detail_view($job_id);
            } else {
                $this->render_list_view();
            }

            echo '</div>';
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            echo '<div class="wrap"><div class="notice notice-error"><p><strong>Error:</strong> Unable to render jobs page.</p></div></div>';
        }
    }

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
        echo '<p><strong>' . esc_html__('Attempts:', 'starmus-audio-recorder') . '</strong> ' . esc_html((string) ($job['attempts'] ?? 0)) . '</p>';

        if (isset($job['created_at'])) {
            echo '<p><strong>' . esc_html__('Created:', 'starmus-audio-recorder') . '</strong> ' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $job['created_at'])) . '</p>';
        }

        if (isset($job['error_message'])) {
            echo '<h3>' . esc_html__('Error', 'starmus-audio-recorder') . '</h3>';
            echo '<pre>' . esc_html($job['error_message']) . '</pre>';
        }

        if (isset($job['result'])) {
            echo '<h3>' . esc_html__('Result', 'starmus-audio-recorder') . '</h3>';
            echo '<pre style="white-space:pre-wrap;">' . esc_html(json_encode($job['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        }

        echo '<p><a href="' . esc_url(menu_page_url('starmus-sagemaker-jobs', false)) . '">' . esc_html__('Back to list', 'starmus-audio-recorder') . '</a></p>';
    }

    private function render_list_view(): void
    {
        $page = isset($_GET['paged']) ? max(1, (int) sanitize_key(wp_unslash($_GET['paged']))) : 1;
        $per_page = 20;

        $jobs = $this->repository->get_paged_jobs($page, $per_page);
        $total_jobs = $this->repository->get_total_count();
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
                $created = isset($job['created_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $job['created_at']) : '';
                echo '<tr>';
                echo '<td><strong>' . esc_html((string) $id) . '</strong></td>';
                echo '<td>' . esc_html($job['status'] ?? 'unknown') . '</td>';
                echo '<td>' . esc_html((string) ($job['attempts'] ?? 0)) . '</td>';
                echo '<td>' . esc_html($created) . '</td>';

                $view_url = add_query_arg(['page' => 'starmus-sagemaker-jobs', 'job_id' => $id], admin_url('admin.php'));
                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=starmus_delete_job&job_id=' . rawurlencode((string) $id)), 'starmus_delete_job_' . $id);
                echo '<td><a href="' . esc_url($view_url) . '">' . esc_html__('View', 'starmus-audio-recorder') . '</a> | <a href="' . esc_url($delete_url) . '" onclick="return confirm(\'Delete this job?\');">' . esc_html__('Delete', 'starmus-audio-recorder') . '</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'total' => (int) $total_pages,
            'current' => $page,
            ]);
            echo '</div></div>';
        }
    }

    public function handle_delete_job(): void
    {
        if ( ! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions', 'starmus-audio-recorder'));
        }

        $job_id = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';

        if ($job_id && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'starmus_delete_job_' . $job_id)) {
            $this->repository->delete($job_id);
        }

        wp_safe_redirect(admin_url('admin.php?page=starmus-sagemaker-jobs'));
        exit;
    }

    public function ajax_retry_job(): void
    {
        check_ajax_referer('starmus_jobs_nonce', 'nonce');
        // Retry logic implementation (placeholder for now)
        wp_send_json_success();
    }
}
