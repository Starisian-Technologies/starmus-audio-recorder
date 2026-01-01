<?php

namespace Starisian\Sparxstar\Starmus\data\interfaces;

use Starisian\Sparxstar\Starmus\data\interfaces\StarmusDALInterface;

// Extends the Core Interface
interface StarmusProsodyDALInterface extends StarmusDALInterface
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
