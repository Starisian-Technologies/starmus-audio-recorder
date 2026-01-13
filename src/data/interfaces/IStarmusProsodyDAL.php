<?php

/**
 * Contract for the Script Prosody Engine Data Layer.
 *
 * @package Starisian\Sparxstar\Starmus\data\interfaces
 *
 * @version 1.1.0
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\data\interfaces;

if ( ! \defined('ABSPATH')) {
    exit;
}

// Extends the Base Interface
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

    /**
     * Retrieves scripts that have not been recorded by the user.
     *
     * @param int $user_id User ID.
     * @param int $posts_per_page Posts per page.
     * @param int $paged Page number.
     *
     * @return \WP_Query
     */
    public function get_unrecorded_scripts(int $user_id, int $posts_per_page = 10, int $paged = 1): \WP_Query;
}
