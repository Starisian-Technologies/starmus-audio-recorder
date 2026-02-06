<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\services;

use Aws\Exception\AwsException;
use Aws\SageMakerRuntime\SageMakerRuntimeClient;

use function basename;
use function file_exists;
use function file_get_contents;
use function get_post;
use function get_post_meta;

use InvalidArgumentException;

use function is_wp_error;
use function json_decode;
use function json_encode;
use function json_last_error;
use function md5;

use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Throwable;

use function wp_get_attachment_url;
use function wp_get_post_terms;

use WP_Post;

if ( ! \defined('ABSPATH')) {
    exit;
}

/**
 * Service for interacting with AWS SageMaker Endpoints.
 *
 * Ported and adapted from AiWA Orchestrator for Starmus Audio Recorder.
 * Handles synchronous audio transcription via SageMaker Runtime invocations.
 *
 * @package Starisian\Sparxstar\Starmus\services
 */
final class StarmusSageMakerService
{
    private ?SageMakerRuntimeClient $client = null;

    public function __construct(private ?StarmusSettings $settings = new StarmusSettings())
    {
    }

    /**
     * initialize and return the AWS SageMaker Runtime Client.
     *
     * @throws AwsException
     */
    private function get_client(): SageMakerRuntimeClient
    {
        if ($this->client instanceof SageMakerRuntimeClient) {
            return $this->client;
        }

        // 1. Resolve Configuration (Environment > Constants > Settings)
        $region = $_ENV['STARMUS_AWS_REGION']
        ?? \constant('STARMUS_AWS_REGION')
        ?? $this->settings->get('aws_region')
        ?? 'us-east-1';

        $key = $_ENV['STARMUS_AWS_KEY']
        ?? \constant('STARMUS_AWS_KEY')
        ?? $this->settings->get('aws_key')
        ?? '';

        $secret = $_ENV['STARMUS_AWS_SECRET']
        ?? \constant('STARMUS_AWS_SECRET')
        ?? $this->settings->get('aws_secret')
        ?? '';

        $config = [
        'version' => 'latest',
        'region' => $region,
        ];

        // 2. Attach Credentials if provided
        if ( ! empty($key) && ! empty($secret)) {
            $config['credentials'] = [
            'key' => $key,
            'secret' => $secret,
            ];
        }

        $this->client = new SageMakerRuntimeClient($config);

        return $this->client;
    }

    /**
     * Compile the training/transcription manifesto for a specific recording.
     *
     * @param int $recording_id The post ID of the audio recording.
     *
     * @return array The structured manifest ready for JSON encoding.
     */
    public function bundle_job_data(int $recording_id): array
    {
        $post = get_post($recording_id);
        if ( ! $post instanceof WP_Post || $post->post_type !== 'audio-recording') {
            throw new InvalidArgumentException('Invalid recording ID: ' . $recording_id);
        }

        // 1. Core Archival Identity
        $manifest = [
        'global_uuid' => get_post_meta($recording_id, 'starmus_global_uuid', true) ?: '',
        'created_at' => $post->post_date_gmt,
        ];

        // 2. Audio Source (Prefer Cloud URI or fallback to local Attachment URL)
        // Note: The AI Engine likely needs a presigned URL or direct S3/R2 URI.
        $cloud_uri = get_post_meta($recording_id, 'starmus_cloud_object_uri', true);
        if (empty($cloud_uri)) {
            // Fallback: Check for attachment
            $attachment_id = get_post_meta($recording_id, 'starmus_audio_files_originals', true);
            if ($attachment_id) {
                $cloud_uri = wp_get_attachment_url((int) $attachment_id);
            }
        }

        $manifest['audio_uri'] = $cloud_uri;

        // 3. Language & Dialect
        $languages = wp_get_post_terms($recording_id, 'starmus_tax_language', ['fields' => 'names']);
        $dialects = wp_get_post_terms($recording_id, 'starmus_tax_dialect', ['fields' => 'names']);
        $manifest['language'] = ! is_wp_error($languages) && ! empty($languages) ? $languages[0] : 'en'; // Default
        $manifest['dialect'] = ! is_wp_error($dialects) && ! empty($dialects) ? $dialects[0] : '';

        // 4. Consent & Legal
        $contributor_id = (int) get_post_meta($recording_id, 'starmus_authorized_signatory', true);
        if ($contributor_id) {
            $manifest['consent_scope'] = [
            'terms_type' => get_post_meta($recording_id, 'starmus_terms_type', true),
            'classification' => get_post_meta($recording_id, 'starmus_data_classification', true),
            'signatory_hash' => md5((string) $contributor_id), // Anonymized ref
            ];
        }

        // 5. Script Content (if linked)
        // Assuming relationship logic (meta or tax). Checking meta 'starmus_linked_script' as per standard rel logic.
        // Detailed lookup logic could reside in DAL, keeping it simple here.
        $script_id = get_post_meta($recording_id, 'starmus_linked_script', true);
        if ($script_id) {
            $script_post = get_post($script_id);
            if ($script_post) {
                $manifest['script_text'] = $script_post->post_content;
            }
        }

        // 6. Optional Prior Transcript (e.g. from CPT starmus_transcript)
        // Search for connected transcript
        // This query should ideally be in DAL.
        // For now, we omit complex queries to keep this service focused on packaging known data.

        return $manifest;
    }

    /**
     * Send recording manifest to SageMaker for processing.
     *
     * @param int $recording_id The recording to process.
     * @param array $options Overlay options.
     *
     * @return array Job result.
     */
    public function transcribe_recording(int $recording_id, array $options = []): array
    {
        try {
            $manifest = $this->bundle_job_data($recording_id);

            if (empty($manifest['audio_uri'])) {
                return ['error' => 'No audio URI found for recording'];
            }

            // 1. Resolve Endpoint
            $endpoint_name = $_ENV['STARMUS_SAGEMAKER_ENDPOINT']
            ?? \constant('STARMUS_SAGEMAKER_ENDPOINT')
            ?? $this->settings->get('sagemaker_endpoint')
            ?? '';

            if (empty($endpoint_name)) {
                return ['error' => 'Configuration missing'];
            }

            // 2. Prepare Payload (JSON Manifest)
            $payload = json_encode($manifest);

            $args = [
            'EndpointName' => $endpoint_name,
            'ContentType' => 'application/json',
            'Body' => $payload,
            'CustomAttributes' => 'StarmusJobId=' . $recording_id,
            ];
            if (isset($options['custom_attributes'])) {
                $args['CustomAttributes'] .= ',' . $options['custom_attributes'];
            }

            StarmusLogger::info('Invoking SageMaker with Manifest', ['id' => $recording_id, 'endpoint' => $endpoint_name]);

            // 3. Invoke
            $client = $this->get_client();
            $result = $client->invokeEndpoint($args);
            $body = $result['Body'];

            // 4. Return decoded result
            return json_decode((string) $body, true) ?: [];
        } catch (Throwable $throwable) {
            StarmusLogger::error('SageMaker Transcribe Job Failed: ' . $throwable->getMessage());
            return ['error' => $throwable->getMessage()];
        }
    }

    /**
     * Send audio to SageMaker endpoint for transcription (Legacy/Raw implementation).
     *
     * @param string $file_path Absolute path to the audio file.
     * @param array $options Optional overrides (endpoint implementation specific).
     *
     * @return array Response data (JSON decoded).
     */
    public function transcribe_audio(string $file_path, array $options = []): array
    {
        if ( ! file_exists($file_path)) {
            StarmusLogger::error('SageMaker: Audio file not found', ['path' => $file_path]);

            return ['error' => 'File not found'];
        }

        try {
            // 1. Resolve Endpoint Name
            $endpoint_name = $_ENV['STARMUS_SAGEMAKER_ENDPOINT']
            ?? \constant('STARMUS_SAGEMAKER_ENDPOINT')
            ?? $this->settings->get('sagemaker_endpoint')
            ?? '';

            if (empty($endpoint_name)) {
                StarmusLogger::error('SageMaker: No endpoint configured.');

                return ['error' => 'Configuration missing'];
            }

            // 2. Prepare Payload
            // WARN: file_get_contents loads entire file into memory. Ensure PHP memory_limit is sufficient for max audio size.
            $audio_data = file_get_contents($file_path);

            if ($audio_data === false) {
                return ['error' => 'Failed to read audio file'];
            }

            $args = [
            'EndpointName' => $endpoint_name,
            'ContentType' => $options['content_type'] ?? 'audio/x-audio', // Default content type for raw audio
            'Body' => $audio_data,
            ];

            if (isset($options['custom_attributes'])) {
                $args['CustomAttributes'] = $options['custom_attributes'];
            }

            StarmusLogger::info('Invoking SageMaker Endpoint: ' . $endpoint_name, ['file' => basename($file_path)]);

            // 3. Invoke Endpoint
            $client = $this->get_client();
            $result = $client->invokeEndpoint($args);
            $body = $result['Body'];

            // 4. Parse Response
            // Expecting JSON response from the inference container
            $decoded = json_decode((string) $body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // If not JSON, return raw body wrapped
                return [
                'raw_response' => (string) $body,
                'is_json' => false,
                ];
            }

            StarmusLogger::info('SageMaker Invocation Successful', ['endpoint' => $endpoint_name]);

            return $decoded;
        } catch (AwsException $e) {
            StarmusLogger::error('SageMaker AWS Error: ' . $e->getMessage(), [
            'code' => $e->getAwsErrorCode(),
            'type' => $e->getAwsErrorType(),
            ]);

            return ['error' => $e->getMessage(), 'aws_code' => $e->getAwsErrorCode()];
        } catch (Throwable $t) {
            StarmusLogger::log($t);

            return ['error' => $t->getMessage()];
        }
    }
}
