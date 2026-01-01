<?php

namespace Starisian\Sparxstar\Starmus\data\interfaces;

use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusBaseDAL;

// Extends the Core Interface
interface IStarmusProsodyDAL extends IStarmusBaseDAL
{

	/**
	 * Retrieves the full configuration payload.
	 */
	public function get_script_payload(int $post_id): array;

	/**
	 * Saves the user-calibrated reading pace.
	 */
	public function save_calibrated_pace(int $post_id, int $ms_per_word): bool;
}
