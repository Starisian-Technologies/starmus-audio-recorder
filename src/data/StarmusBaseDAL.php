<?php

/**
 * Abstract Base DAL with Paranoid Error Handling.
 *
 * Implements safeguards against silent data loss.
 * If a DB write fails, the payload is dumped to the StarmusLogger
 * so it can be recovered manually.
 *
 * @package Starisian\Sparxstar\Starmus\data
 * @version 1.1.0
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\data;

use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusBaseDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

if (! defined('ABSPATH')) {
	exit;
}

abstract class StarmusBaseDAL implements IStarmusBaseDAL
{

	// --- META OPERATIONS ---

	/**
	 * {@inheritdoc}
	 */
	public function save_post_meta(int $post_id, string $key, mixed $value): bool
	{
		try {
			$success = false;

			if (function_exists('update_field')) {
				$success = (bool) update_field($key, $value, $post_id);
			} else {
				// update_post_meta returns int|bool. False on failure, true/int on success.
				// Note: It returns false if the value is the same as existing, which is technically a "success",
				// but for data loss prevention, we care about DB errors.
				$result = update_post_meta($post_id, $key, $value);
				$success = (false !== $result);
			}

			if (! $success) {
				// EMERGENCY DATA DUMP
				// If WP/ACF says "False", we log the data so it isn't lost.
				$this->log_write_failure($post_id, $key, $value);
			}

			return $success;
		} catch (Throwable $e) {
			StarmusLogger::log($e);
			$this->log_write_failure($post_id, $key, $value, $e->getMessage());
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_post_meta(int $post_id, string $key, bool $single = true): mixed
	{
		try {
			if (function_exists('get_field')) {
				$val = get_field($key, $post_id);
				if ($val !== null && $val !== false) {
					return $val;
				}
			}
			return get_post_meta($post_id, $key, $single);
		} catch (Throwable $e) {
			StarmusLogger::log($e);
			return null;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_post_info(int $post_id): ?array
	{
		$post = get_post($post_id);
		return $post ? array(
			'id'     => $post->ID,
			'type'   => $post->post_type,
			'status' => $post->post_status,
		) : null;
	}

	// --- PROVENANCE & SECURITY ---

	/**
	 * {@inheritdoc}
	 */
	public function set_integrity_data(int $post_id, string $sha256, string $md5): bool
	{
		$s1 = $this->save_post_meta($post_id, 'file_hash', $sha256);
		$s2 = $this->save_post_meta($post_id, 'file_hash_md5', $md5);
		$s3 = $this->save_post_meta($post_id, 'asset_health', 'healthy');
		$s4 = $this->save_post_meta($post_id, 'last_fixity_check', current_time('Y-m-d H:i:s'));

		// Return true only if ALL critical security fields saved
		return $s1 && $s2 && $s3 && $s4;
	}

	/**
	 * {@inheritdoc}
	 */
	public function log_provenance_event(int $post_id, string $type, string $agent, string $description, string $hash = ''): bool
	{
		try {
			$row = array(
				'revision_date'        => current_time('Y-m-d H:i:s'),
				'revision_type'        => $type,
				'revision_agent'       => $agent,
				'revision_description' => $description,
				'revision_hash'        => $hash,
				'signature'            => '',
			);

			if (function_exists('add_row')) {
				$result = add_row('revision_history', $row, $post_id);
				if (! $result) {
					$this->log_write_failure($post_id, 'revision_history', $row);
					return false;
				}
				return true;
			}

			// Fallback logic
			$history = (array) $this->get_post_meta($post_id, 'revision_history');
			$history[] = $row;
			return $this->save_post_meta($post_id, 'revision_history', $history);
		} catch (Throwable $e) {
			StarmusLogger::log($e);
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function log_asset_audit(int $post_id, string $action): bool
	{
		try {
			$row = array(
				'ts'     => current_time('Y-m-d H:i:s'),
				'action' => $action,
			);

			if (function_exists('add_row')) {
				$result = add_row('asset_audit_log', $row, $post_id);
				if (! $result) {
					$this->log_write_failure($post_id, 'asset_audit_log', $row);
					return false;
				}
				return true;
			}

			// Fallback logic
			$log = (array) $this->get_post_meta($post_id, 'asset_audit_log');
			$log[] = $row;
			return $this->save_post_meta($post_id, 'asset_audit_log', $log);
		} catch (Throwable $e) {
			StarmusLogger::log($e);
			return false;
		}
	}

	/**
	 * Helper: Dumps failed data to logs to prevent total data loss.
	 *
	 * @param int    $post_id
	 * @param string $key
	 * @param mixed  $value
	 * @param string $error_msg
	 */
	protected function log_write_failure(int $post_id, string $key, mixed $value, string $error_msg = 'DB Write Returned False'): void
	{
		// We JSON encode the value to ensure complex arrays/objects are readable in the text log
		$safe_value = json_encode($value);

		StarmusLogger::error(
			"DATA LOSS PREVENTION: Write failed for Post {$post_id}, Key: {$key}. Reason: {$error_msg}",
			array(
				'failed_payload' => $safe_value // The data is now safe in the log
			)
		);
	}
}
