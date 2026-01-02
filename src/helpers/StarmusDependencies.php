<?php

/**
 * Dependency Detection Guard.
 *
 * Implements the "Detection Only" commercial standard.
 *
 * Handles the "ACF vs SCF" conflict resolution:
 * - If Standard ACF is active, we report it as a conflict (because it blocks SCF).
 * - If SCF or ACF Pro is active, we proceed.
 *
 * @package Starisian\Sparxstar\Starmus\helpers
 * @version 1.1.0
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\helpers;

use function add_action;
use function class_exists;
use function defined;
use function esc_html;
use function function_exists;
use function implode;
use function is_admin;

if (! defined('ABSPATH')) {
	exit;
}

class StarmusDependencies
{

	/**
	 * Detect if a SUFFICIENT version of ACF/SCF is active.
	 *
	 * Returns true ONLY if:
	 * 1. Secure Custom Fields is active (Preferred)
	 * 2. OR ACF Pro is active (Acceptable)
	 *
	 * Returns false if:
	 * 1. ACF is missing completely.
	 * 2. Standard ACF (Free) is active (Insufficient).
	 *
	 * @return bool
	 */
	public static function has_valid_acf(): bool
	{
		error_log('CHECKING FOR VALID ACF/SCF');
		// 1. Check if ANY ACF is running
		if (! function_exists('acf_get_instance')) {
			return false;
		}

		// 2. Check for SCF specifically (Preferred)
		// SCF usually defines SCF_VERSION or maps to acf_pro class depending on version
		if (defined('SCF_VERSION') || class_exists('SCF')) {
			return true;
		}

		// 3. Check for ACF Pro (Acceptable fallback)
		if (class_exists('acf_pro')) {
			return true;
		}

		// 4. If we are here, it's likely Standard ACF Free, which lacks required features.
		return false;
	}

	/**
	 * Detect if Action Scheduler is available.
	 *
	 * @return bool
	 */
	public static function has_action_scheduler(): bool
	{
		return class_exists('ActionScheduler') || function_exists('as_enqueue_async_action');
	}

	/**
	 * Check requirements and register admin notices if missing or conflicting.
	 *
	 * @return bool True if safe to boot, False if dependencies failed.
	 */
	public static function check_critical_dependencies(): bool
	{
		error_log('CHECKING CRITICAL DEPENDENCIES');
		$errors = array();

		// --- ACF / SCF LOGIC ---
		if (! self::has_valid_acf()) {
			if (function_exists('acf_get_instance')) {
				// INTERFERENCE DETECTED: ACF Free is running, blocking bundled SCF.
				$errors[] = 'Standard ACF is active but insufficient. Please deactivate it to allow Secure Custom Fields (SCF) to load.';
			} else {
				// NOTHING LOADED: This shouldn't happen if bundled SCF works,
				// but catches cases where bundle failed.
				$errors[] = 'Secure Custom Fields (SCF) failed to load.';
			}
		}

		// --- ACTION SCHEDULER LOGIC ---
		// (Optional: You can decide if AS is hard-critical or soft-critical)
		// if ( ! self::has_action_scheduler() ) {
		//    $errors[] = 'Action Scheduler';
		// }

		if (empty($errors)) {
			return true;
		}

		// Register notice only if we are in admin
		if (is_admin() && (! defined('DOING_AJAX') || ! DOING_AJAX)) {
			add_action('admin_notices', function () use ($errors) {
?>
				<div class="notice notice-error">
					<p><strong>Starmus Audio Recorder Error:</strong></p>
					<ul>
						<?php foreach ($errors as $err) : ?>
							<li><?php echo esc_html($err); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
<?php
			});
		}

		return false;
	}
}
