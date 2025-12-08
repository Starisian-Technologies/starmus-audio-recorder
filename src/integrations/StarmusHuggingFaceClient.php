<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\integrations;

// Prevent direct access
if (! \defined('ABSPATH')) {
    exit;
}

/**
 * Minimal client to send audio files + first-pass transcription to a HuggingFace-compatible endpoint.
 *
 * Implementation notes:
 * - Reads the local file using `get_attached_file()` when possible and base64-encodes it.
 * - Sends JSON payload: { filename, mime_type, audio_base64, first_pass_transcription }
 * - Uses the option `aiwa_ai_endpoint` and `aiwa_ai_api_key` for configuration.
 *
 * The remote model should accept this shape; adjust as needed.
 */
class StarmusHuggingFaceClient
{
    /**
     * The API endpoint URL.
     *
     * @var string
     */
    private readonly string $endpoint;

    /**
     * The API key for authentication.
     *
     * @var string
     */
    private readonly string $api_key;

    /**
     * Constructor.
     *
     * @param string|null $endpoint The API endpoint URL.
     * @param string|null $api_key The API key.
     */
    public function __construct(?string $endpoint = null, ?string $api_key = null)
    {
        $this->endpoint = $endpoint ?: (string) get_option('aiwa_ai_endpoint', '');
        $this->api_key  = $api_key ?: (string) get_option('aiwa_ai_api_key', '');
    }

    /**
     * Send the attachment to the configured endpoint along with the first-pass transcription.
     * Returns decoded response array on success or null on failure.
     */
    public function sendFileWithFirstPass(int $attachment_id, string $first_pass_transcription): ?array
    {
        $attachment_id = absint($attachment_id);
        if (! $attachment_id || ($this->endpoint === '' || $this->endpoint === '0')) {
            return null;
        }

        // Prefer the local file path for reliable reads
        $file_path    = get_attached_file($attachment_id);
        $filename     = '';
        $mime_type    = '';
        $audio_base64 = '';

        if ($file_path && file_exists($file_path)) {
            $filename  = wp_basename($file_path);
            $mime_type = wp_check_filetype($file_path)['type'] ?? mime_content_type($file_path);
            $contents  = file_get_contents($file_path);
            if ($contents !== false) {
                $audio_base64 = base64_encode($contents);
            }
        }

        // Fallback: try to fetch via attachment URL
        if ($audio_base64 === '' || $audio_base64 === '0') {
            $url = wp_get_attachment_url($attachment_id);
            if ($url) {
                $resp = wp_remote_get($url, ['timeout' => 30]);
                if (! is_wp_error($resp)) {
                    $body = wp_remote_retrieve_body($resp);
                    if (! empty($body)) {
                        $audio_base64 = base64_encode($body);
                        $filename     = wp_basename($url);
                        $mime_type    = wp_remote_retrieve_header($resp, 'content-type') ?: '';
                    }
                }
            }
        }

        if ($audio_base64 === '' || $audio_base64 === '0') {
            error_log('Failed to read audio file for attachment ID: ' . $attachment_id);
            return null;
        }

        $payload = [
            'filename'                 => $filename,
            'mime_type'                => $mime_type,
            'audio_base64'             => $audio_base64,
            'first_pass_transcription' => $first_pass_transcription,
        ];

        $args = [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
            'timeout' => 60,
        ];

        if ($this->api_key !== '' && $this->api_key !== '0') {
            $args['headers']['Authorization'] = 'Bearer ' . $this->api_key;
        }

        $response = wp_remote_post(esc_url_raw($this->endpoint), $args);
        if (is_wp_error($response)) {
            error_log($response);
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || empty($body)) {
            error_log('HuggingFace client received non-2xx or empty body');
            return null;
        }

        $decoded = json_decode($body, true);
        if (! \is_array($decoded)) {
            error_log('HuggingFace client response could not be decoded as JSON');
            return null;
        }

        // Removed success debug log
        return $decoded;
    }
}
