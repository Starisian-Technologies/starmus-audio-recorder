<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\integrations;

use Starisian\Sparxstar\Starmus\includes\StarmusSageMakerJobRepository;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for managing SageMaker transcription job business logic.
 *
 * Handles retry, delete operations, and job statistics.
 *
 * @package Starisian\Sparxstar\Starmus
 *
 * @since 1.0.0
 */
final readonly class StarmusSageMakerClient {

	/**
	 * Constructor.
	 *
	 * @param StarmusSageMakerJobRepository $repository Job repository instance.
	 */
	public function __construct(
		/**
		 * Job repository.
		 *
		 * @var StarmusSageMakerJobRepository
		 */
		private StarmusSageMakerJobRepository $repository
	) {
	}

	/**
	 * Get job counts by status.
	 *
	 * @return array Associative array with keys: total, pending, processing, done, failed.
	 */
	public function get_job_counts(): array {
		$jobs = $this->get_all_jobs();

		$counts = [
			'total'      => 0,
			'pending'    => 0,
			'processing' => 0,
			'done'       => 0,
			'failed'     => 0,
		];

		foreach ( $jobs as $job ) {
			++$counts['total'];
			$status = $job['status'] ?? 'pending';
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}

	/**
	 * Retry a job by resetting its status and scheduling it.
	 *
	 * @param string $job_id Job identifier.
	 *
	 * @return bool True on success, false if job not found.
	 */
	public function retry_job( string $job_id ): bool {
		$job = $this->repository->find( $job_id );

		if ( ! $job ) {
			return false;
		}

		// Reset job status and schedule for processing
		$job['status']       = 'pending';
		$job['attempts']     = 0;
		$job['scheduled_at'] = time();

		$this->repository->save( $job_id, $job );

		// Schedule WP Cron event if not already scheduled
		if ( ! wp_next_scheduled( 'aiwa_orch_process_transcription_job', [ $job_id ] ) ) {
			wp_schedule_single_event( time() + 5, 'aiwa_orch_process_transcription_job', [ $job_id ] );
		}

		return true;
	}

	/**
	 * Delete a job and cleanup associated files.
	 *
	 * @param string $job_id Job identifier.
	 *
	 * @return bool True if deleted, false if not found.
	 */
	public function delete_job( string $job_id ): bool {
		$job = $this->repository->find( $job_id );

		if ( ! $job ) {
			return false;
		}

		// Attempt to delete associated file if present
		if ( ! empty( $job['file'] ) && file_exists( $job['file'] ) ) {
			@unlink( $job['file'] );
		}

		return $this->repository->delete( $job_id );
	}

	/**
	 * Get all jobs from repository (helper for internal operations).
	 *
	 * @return array All jobs.
	 */
	private function get_all_jobs(): array {
		$jobs = get_option( 'aiwa_sagemaker_jobs', [] );
		return \is_array( $jobs ) ? $jobs : [];
	}
}
