<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\core\interfaces;

use WP_Error;
use WP_REST_Request;

/**
 * Interface for StarmusSubmissionHandler.
 */
interface IStarmusSubmissionHandler
{
    /**
     * Handle multipart chunk upload via REST.
     *
     *
     */
    public function handle_upload_chunk_rest_multipart(WP_REST_Request $request): array|WP_Error;

    /**
     * Handle base64 chunk upload via REST.
     *
     *
     */
    public function handle_upload_chunk_rest_base64(WP_REST_Request $request): array|WP_Error;
}
