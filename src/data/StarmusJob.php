<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\data;

if ( ! \defined('ABSPATH')) {
    exit;
}

/**
 * Data Object (DTO) for a Starmus Transcription Job.
 *
 * @package Starisian\Sparxstar\Starmus\data
 */
final class StarmusJob
{
    public function __construct(
        public string $job_id,
        public int $post_id,
        public string $status = 'pending',
        public int $attempts = 0,
        public ?string $file_path = null,
        public ?string $error_message = null,
        public ?string $result = null,
        public int $created_at = 0,
        public ?int $finished_at = null,
        public ?int $id = null
    ) {
        if ($this->created_at === 0) {
            $this->created_at = time();
        }
    }
}
