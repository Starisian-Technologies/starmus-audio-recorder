<?php

/**
 * Contract for the Script Prosody Engine Data Layer.
 *
 * @package Starisian\Sparxstar\Starmus\data\interfaces
 * @version 1.1.0
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\data\interfaces;

if (! defined('ABSPATH')) {
	exit;
}

// Extends the Base Interface
interface IStarmusProsodyDAL extends IStarmusBaseDAL
{

	/**
	 * Retrieves the full configuration payload.
	 *
	 * @param int $post_id
	 * @return array
	 */
	public function get_script_payload(int $post_id): array;

	/**
	 * Saves the user-calibrated reading pace.
	 *
	 * @param int $post_id
	 * @param int $ms_per_word
	 * @return bool
	 */
	public function save_calibrated_pace(int $post_id, int $ms_per_word): bool;
}
