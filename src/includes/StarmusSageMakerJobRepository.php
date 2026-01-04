<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\includes;

if ( ! \defined('ABSPATH')) {
    exit;
}

/**
 * Repository for SageMaker transcription jobs stored in wp_options.
 *
 * Encapsulates storage access to the aiwa_sagemaker_jobs option.
 *
 * @package Starisian\Sparxstar\Starmus
 *
 * @since 1.0.0
 */
final class StarmusSageMakerJobRepository
{
    /**
     * Option key in wp_options where jobs are stored.
     *
     * @var string
     */
    private const OPTION_KEY = 'aiwa_sagemaker_jobs';

    /**
     * Find a job by ID.
     *
     * @param string $job_id The job identifier.
     *
     * @return array|null Job data or null if not found.
     */
    public function find(string $job_id): ?array
    {
        $jobs = $this->get_all();
        return $jobs[ $job_id ] ?? null;
    }

    /**
     * Get paginated jobs.
     *
     * @param int $page Page number (1-indexed).
     * @param int $per_page Items per page.
     *
     * @return array Associative array of jobs for the page.
     */
    public function get_paged_jobs(int $page, int $per_page): array
    {
        $jobs = $this->get_all();

        // Sort by created_at descending (newest first)
        uasort(
            $jobs,
            function (array $a, array $b): int {
                $ta = isset($a['created_at']) ? (int) $a['created_at'] : 0;
                $tb = isset($b['created_at']) ? (int) $b['created_at'] : 0;
                return $tb <=> $ta;
            }
        );

        $offset = ($page - 1) * $per_page;
        return \array_slice($jobs, $offset, $per_page, true);
    }

    /**
     * Get total count of jobs.
     *
     * @return int Total number of jobs.
     */
    public function get_total_count(): int
    {
        $jobs = $this->get_all();
        return \count($jobs);
    }

    /**
     * Get recent jobs (sorted by created_at, newest first).
     *
     * @param int $limit Maximum number of jobs to return.
     *
     * @return array Associative array of recent jobs.
     */
    public function get_recent_jobs(int $limit): array
    {
        $jobs = $this->get_all();

        // Sort by created_at descending
        uasort(
            $jobs,
            function (array $a, array $b): int {
                $ta = isset($a['created_at']) ? (int) $a['created_at'] : 0;
                $tb = isset($b['created_at']) ? (int) $b['created_at'] : 0;
                return $tb <=> $ta;
            }
        );

        return \array_slice($jobs, 0, $limit, true);
    }

    /**
     * Save a job (create or update).
     *
     * @param string $job_id Job identifier.
     * @param array $job_data Job data to save.
     *
     * @return bool True on success, false on failure.
     */
    public function save(string $job_id, array $job_data): bool
    {
        $jobs            = $this->get_all();
        $jobs[ $job_id ] = $job_data;
        return update_option(self::OPTION_KEY, $jobs);
    }

    /**
     * Delete a job by ID.
     *
     * @param string $job_id Job identifier.
     *
     * @return bool True if deleted, false if not found.
     */
    public function delete(string $job_id): bool
    {
        $jobs = $this->get_all();

        if ( ! isset($jobs[ $job_id ])) {
            return false;
        }

        unset($jobs[ $job_id ]);
        update_option(self::OPTION_KEY, $jobs);
        return true;
    }

    /**
     * Get all jobs from storage.
     *
     * @return array Associative array of all jobs.
     */
    private function get_all(): array
    {
        $jobs = get_option(self::OPTION_KEY, []);
        return \is_array($jobs) ? $jobs : [];
    }
}
