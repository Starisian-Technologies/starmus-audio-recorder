<?php

/**
 * Abstract Base DAL with Paranoid Error Handling.
 *
 * Implements safeguards against silent data loss.
 * If a DB write fails, the payload is dumped to the StarmusLogger
 * so it can be recovered manually.
 *
 * @package Starisian\Sparxstar\Starmus\data
 *
 * @version 1.1.0
 */

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\data;

use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusBaseDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

if ( ! \defined('ABSPATH')) {
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

            if (\function_exists('update_field')) {
                $success = (bool) update_field($key, $value, $post_id);
            } else {
                // update_post_meta returns int|bool. False on failure, true/int on success.
                // Note: It returns false if the value is the same as existing, which is technically a "success",
                // but for data loss prevention, we care about DB errors.
                $result  = update_post_meta($post_id, $key, $value);
                $success = (false !== $result);
            }

            if ( ! $success) {
                // EMERGENCY DATA DUMP
                // If WP/ACF says "False", we log the data so it isn't lost.
                $this->log_write_failure($post_id, $key, $value);
            }

            return $success;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            $this->log_write_failure($post_id, $key, $value, $throwable->getMessage());
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get_post_meta(int $post_id, string $key, bool $single = true): mixed
    {
        try {
            // 1. Prefer ACF for structured data retrieval
            if (\function_exists('get_field')) {
                $value = get_field($key, $post_id);

                // Logic: ACF returns 'false' on failure OR if the boolean value is false.
                // We accept the value if it is not null and not false.
                // If it IS false, we fall back to native meta to distinguish between
                // "actually false" and "field doesn't exist".
                if ($value !== null && $value !== false) {
                    return $value;
                }
            }

            // 2. Native Fallback (Source of Truth for raw DB values)
            return get_post_meta($post_id, $key, $single);

        } catch (Throwable $throwable) {
            // 3. Fail Safe: Log the error and return null to prevent WSOD
            StarmusLogger::log($throwable);
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get_post_info(int $post_id): ?array
    {
        $post = get_post($post_id);
        return $post ? [
        'id'     => $post->ID,
        'type'   => $post->post_type,
        'status' => $post->post_status,
        ] : null;
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
            $row = [
            'revision_date'        => current_time('Y-m-d H:i:s'),
            'revision_type'        => $type,
            'revision_agent'       => $agent,
            'revision_description' => $description,
            'revision_hash'        => $hash,
            'signature'            => '',
            ];

            if (\function_exists('add_row')) {
                $result = add_row('revision_history', $row, $post_id);
                if ( ! $result) {
                    $this->log_write_failure($post_id, 'revision_history', $row);
                    return false;
                }

                return true;
            }

            // Fallback logic
            $history   = (array) $this->get_post_meta($post_id, 'revision_history');
            $history[] = $row;
            return $this->save_post_meta($post_id, 'revision_history', $history);
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function log_asset_audit(int $post_id, string $action): bool
    {
        try {
            $row = [
            'ts'     => current_time('Y-m-d H:i:s'),
            'action' => $action,
            ];

            // 1. Try ACF
            if (\function_exists('add_row')) {
                $result = add_row('asset_audit_log', $row, $post_id);

                if ($result) {
                    return true; // <--- CRITICAL FIX: Stop here on success
                }

                // Only log failure if we aren't going to try the fallback?
                // Or log it and try fallback? Let's try fallback.
                $this->log_write_failure($post_id, 'asset_audit_log (ACF)', $row);
            }

            // 2. Fallback Logic (Native)
            // Only runs if ACF is missing or add_row failed
            $log   = (array) $this->get_post_meta($post_id, 'asset_audit_log');
            $log[] = $row;

            return $this->save_post_meta($post_id, 'asset_audit_log', $log);

        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return false;
        }
    }

    /**
     * Helper: Dumps failed data to logs to prevent total data loss.
     */
    protected function log_write_failure(int $post_id, string $key, mixed $value, string $error_msg = 'DB Write Returned False'): void
    {
        // We JSON encode the value to ensure complex arrays/objects are readable in the text log
        $safe_value = json_encode($value);

        StarmusLogger::error(
            \sprintf('DATA LOSS PREVENTION: Write failed for Post %d, Key: %s. Reason: %s', $post_id, $key, $error_msg),
            [
        'failed_payload' => $safe_value, // The data is now safe in the log
            ]
        );
    }
}
