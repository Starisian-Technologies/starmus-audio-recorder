<?php

/**
 * Registers and renders WordPress Dashboard widgets for Starmus transcription Workflow.
 *
 * @package Starisian\Sparxstar\Starmus\admin\widgets
 */

namespace Starisiam\Sparxstar\Starmus\admin\widgets;

if (! defined('ABSPATH')) {
    exit;
}

use Throwable;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\services\StarmusStatsDataService;

/**
 * Dashboard Widgets for AIWA Workflow
 */
class StarmusAdminWidgets
{

    /**
     * @var StarmusStatsDataService
     */
    private StarmusStatsDataService $data_service;

    /**
     * Constructor.
     *
     * @param StarmusStatsDataService $data_service
     */
    public function __construct(StarmusStatsDataService $data_service)
    {
        try {
            $this->data_service = $data_service;
            $this->register_hooks();
        } catch (Throwable $e) {
            StarmusLogger::log('StarmusAdminWidgets::__construct() failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Hook widget registration into WordPress.
     */
    public function register_hooks(): void
    {
        try {
            add_action('wp_dashboard_setup', [$this, 'register_widgets']);
        } catch (Throwable $e) {
            StarmusLogger::log('StarmusAdminWidgets::register_hooks() failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Register the dashboard widgets.
     */
    public function register_widgets(): void
    {
        try {

            wp_add_dashboard_widget(
                'aiwa_jobs_widget',
                __('AIWA: Transcription Jobs', 'aiwa'),
                [$this, 'render_jobs_widget']
            );
        } catch (Throwable $e) {
            StarmusLogger::log('StarmusAdminWidgets::register_widgets() failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }



    /**
     * Render recent transcription jobs widget.
     */
    public function render_jobs_widget(): void
    {
        try {
            $jobs = get_option('aiwa_sagemaker_jobs', []);

            $counts = [
                'total' => 0,
                'pending' => 0,
                'processing' => 0,
                'done' => 0,
                'failed' => 0,
            ];

            foreach ($jobs as $job) {
                $counts['total']++;
                $status = isset($job['status']) ? $job['status'] : 'pending';
                if (isset($counts[$status])) {
                    $counts[$status]++;
                }
            }

            echo '<div class="aiwa-jobs-widget">';
            echo '<p><strong>' . esc_html__('Total jobs:', 'aiwa') . '</strong> ' . intval($counts['total']) . '</p>';
            echo '<p><strong>' . esc_html__('Pending:', 'aiwa') . '</strong> ' . intval($counts['pending']) . ' — ' . esc_html__('Processing:', 'aiwa') . ' ' . intval($counts['processing']) . ' — ' . esc_html__('Done:', 'aiwa') . ' ' . intval($counts['done']) . ' — ' . esc_html__('Failed:', 'aiwa') . ' ' . intval($counts['failed']) . '</p>';

            if (empty($jobs)) {
                echo '<p>' . esc_html__('No jobs queued.', 'aiwa') . '</p>';
                echo '</div>';
                return;
            }

            // Sort jobs by created_at desc and show 5 most recent
            usort($jobs, function ($a, $b) {
                $ta = isset($a['created_at']) ? (int) $a['created_at'] : 0;
                $tb = isset($b['created_at']) ? (int) $b['created_at'] : 0;
                return $tb <=> $ta;
            });

            $recent = array_slice($jobs, 0, 5, true);

            echo '<table class="widefat"><thead><tr><th>' . esc_html__('Job ID', 'aiwa') . '</th><th>' . esc_html__('Status', 'aiwa') . '</th><th>' . esc_html__('Attempts', 'aiwa') . '</th><th>' . esc_html__('Created', 'aiwa') . '</th></tr></thead><tbody>';
            foreach ($recent as $id => $job) {
                $created = isset($job['created_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $job['created_at']) : '';
                echo '<tr>';
                echo '<td>' . esc_html($id) . '</td>';
                echo '<td>' . esc_html($job['status'] ?? '') . '</td>';
                echo '<td>' . esc_html((string) intval($job['attempts'] ?? 0)) . '</td>';
                echo '<td>' . esc_html($created) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            echo '<p><a href="' . esc_url(admin_url('admin.php?page=aiwa-sagemaker-jobs')) . '">' . esc_html__('View all jobs', 'aiwa') . '</a></p>';
            echo '</div>';

            $this->enqueue_jobs_widget_script();
        } catch (Throwable $e) {
            StarmusLogger::log('StarmusAdminWidgets::render_jobs_widget() failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            echo '<p>' . esc_html__('Unable to load job data.', 'aiwa') . '</p>';
        }
    }

    /**
     * Enqueue small inline JS for polling and quick actions.
     */
    private function enqueue_jobs_widget_script(): void
    {
        try {
            $nonce = wp_create_nonce('aiwa_jobs_nonce');
            $ajax_url = admin_url('admin-ajax.php');

            $script = <<<JS
            <script>
            (function(){
                const ajaxUrl = '{$ajax_url}';
                const nonce = '{$nonce}';
                function refreshJobs(){
                    fetch(ajaxUrl + '?action=aiwa_get_jobs&nonce=' + nonce, { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(data => {
                            if (!data.success) return;
                            const counts = data.data.counts;
                            const widget = document.querySelector('.aiwa-jobs-widget');
                            if (widget) {
                                const html = [];
                                html.push('<p><strong>Total jobs:</strong> ' + counts.total + '</p>');
                                html.push('<p><strong>Pending:</strong> ' + counts.pending + ' — Processing: ' + counts.processing + ' — Done: ' + counts.done + ' — Failed: ' + counts.failed + '</p>');
                                widget.innerHTML = html.join('') + widget.innerHTML.substring(widget.innerHTML.indexOf('<table'));
                            }
                        }).catch(()=>{});
                }
                document.addEventListener('click', function(e){
                    const el = e.target;
                    if (el.matches('.aiwa-retry-job') || el.closest('.aiwa-retry-job')){
                        e.preventDefault();
                        const id = el.dataset.jobId || el.closest('.aiwa-retry-job').dataset.jobId;
                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=aiwa_retry_job&job_id=' + encodeURIComponent(id) + '&nonce=' + nonce })
                            .then(r => r.json()).then(()=> refreshJobs());
                    }
                    if (el.matches('.aiwa-delete-job') || el.closest('.aiwa-delete-job')){
                        e.preventDefault();
                        if (!confirm('Delete job?')) return;
                        const id = el.dataset.jobId || el.closest('.aiwa-delete-job').dataset.jobId;
                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=aiwa_delete_job&job_id=' + encodeURIComponent(id) + '&nonce=' + nonce })
                            .then(r => r.json()).then(()=> refreshJobs());
                    }
                }, true);
                setInterval(refreshJobs, 15000);
            })();
            </script>
            JS;

            echo $script;
        } catch (Throwable $e) {
            StarmusLogger::log('StarmusAdminWidgets::enqueue_jobs_widget_script() failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
