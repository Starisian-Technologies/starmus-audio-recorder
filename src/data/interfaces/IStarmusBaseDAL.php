<?php

/**
 * Base Contract for ALL Starmus Data Access Layers.
 *
 * Enforces standard methods for meta retrieval and error handling
 * across both the Recorder and the Prosody engine.
 *
 * @package Starisian\Sparxstar\Starmus\data\interfaces
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\data\interfaces;

use function defined;

if (! defined('ABSPATH')) {
	exit;
}

interface IStarmusBaseDAL
{

	// --- 1. META OPERATIONS (UNIVERSAL) ---

	/**
	 * Strictly saves metadata to a post.
	 * Must support ACF 'update_field' with fallback to 'update_post_meta'.
	 *
	 * @param int    $post_id Target Post ID.
	 * @param string $key     Meta Key.
	 * @param mixed  $value   Value to save.
	 * @return bool           True on success, False on failure.
	 */
	public function save_post_meta(int $post_id, string $key, mixed $value): bool;

	/**
	 * Strictly retrieves metadata.
	 *
	 * @param int    $post_id Target Post ID.
	 * @param string $key     Meta Key.
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed
	 */
	public function get_post_meta(int $post_id, string $key, bool $single = true): mixed;

	/**
	 * Sets the cryptographic hashes and integrity status.
	 * For Files: Hash of the binary.
	 * For Scripts/Text: Hash of the content string.
	 *
	 * @param int    $post_id Target Post ID.
	 * @param string $sha256  SHA-256 Hash.
	 * @param string $md5     MD5 Hash.
	 * @return bool           True if all integrity fields were saved successfully.
	 */
	public function set_integrity_data(int $post_id, string $sha256, string $md5): bool;

	/**
	 * Appends an entry to the 'revision_history' ACF Repeater.
	 *
	 * @param int    $post_id     Target Post ID.
	 * @param string $type        Revision Type (e.g. 'creation', 'correction').
	 * @param string $agent       Who made the change.
	 * @param string $description What changed.
	 * @param string $hash        State hash (optional).
	 * @return bool               True if the row was added successfully.
	 */
	public function log_provenance_event(int $post_id, string $type, string $agent, string $description, string $hash = ''): bool;

	/**
	 * Appends an entry to the 'asset_audit_log' ACF Repeater.
	 *
	 * @param int    $post_id Target Post ID.
	 * @param string $action  The action taken (e.g. 'Ingested file', 'Pace Updated').
	 * @return bool           True if the log entry was saved.
	 */
	public function log_asset_audit(int $post_id, string $action): bool;
}
