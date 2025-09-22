<?php
/**
 * Service class for generating waveform data for audio files.
 *
 * @package Starmus\services
 * @version 1.2.0
 */

namespace Starmus\services;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Handles waveform data generation and storage using the 'audiowaveform' tool.
 */
class WaveformService {

    /**
     * Checks if the required command-line tool is installed and available.
     *
     * @return bool
     */
    public function is_tool_available(): bool {
        // Use 'command -v' which is a more reliable equivalent of 'which' across shells.
        // It returns the path to the command if found, or an empty string if not.
        $path = shell_exec('command -v audiowaveform');
        return !empty(trim($path));
    }

    /**
     * Generates waveform data for an audio attachment and stores it in post meta.
     *
     * @param int $attachment_id The WordPress attachment ID of the audio file.
     * @param bool $force Whether to force regeneration even if data exists.
     * @return bool True on success, false on failure.
     */
    public function generate_waveform_data( int $attachment_id, bool $force = false ): bool {
        if ( ! $force && $this->has_waveform_data( $attachment_id ) ) {
            return true;
        }

        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            error_log("Starmus Waveform Service: Source file not found for attachment ID {$attachment_id}.");
            return false;
        }

        $waveform_peaks = $this->extract_waveform_from_file( $file_path );

        if ( is_null($waveform_peaks) ) { // Check for null, as an empty array could be a valid (silent) waveform.
            error_log("Starmus Waveform Service: Failed to extract waveform data for file: {$file_path}.");
            return false;
        }

        update_post_meta( $attachment_id, '_waveform_data', $waveform_peaks );
        return true;
    }

    /**
     * Deletes waveform data for an audio attachment.
     *
     * @param int $attachment_id The WordPress attachment ID.
     * @return bool
     */
    public function delete_waveform_data( int $attachment_id ): bool {
        return delete_post_meta( $attachment_id, '_waveform_data' );
    }

    /**
     * Checks if waveform data exists for an audio attachment.
     *
     * @param int $attachment_id The WordPress attachment ID.
     * @return bool
     */
    public function has_waveform_data( int $attachment_id ): bool {
        return ! empty( get_post_meta( $attachment_id, '_waveform_data', true ) );
    }

    /**
     * Extracts waveform data from an audio file using 'audiowaveform'.
     *
     * @param string $file_path Absolute path to the audio file.
     * @return array|null An array of float values, or null on failure.
     */
    private function extract_waveform_from_file( string $file_path ): ?array {
        // Create a temporary file path for the JSON output.
        $temp_base = tempnam( sys_get_temp_dir(), 'waveform-' );
        if (!$temp_base) {
             error_log("Starmus Waveform Service: Could not create temporary file.");
             return null;
        }
        $temp_json_path = $temp_base . '.json';
        unlink($temp_base); // Immediately remove the file created by tempnam. We only want the name.

        $input_path  = escapeshellarg($file_path);
        $output_path = escapeshellarg($temp_json_path);

        $command = "audiowaveform -i {$input_path} -o {$output_path} --pixels-per-second 100 --bits 8";
        exec($command . ' 2>&1', $output_lines, $return_code);

        // Check if the command failed or if the output file wasn't created.
        if ($return_code !== 0 || !file_exists($temp_json_path)) {
            error_log("Starmus Waveform Service: audiowaveform failed. Return code: {$return_code}. Command: {$command}. Output: " . implode("\n", $output_lines));
            if (file_exists($temp_json_path)) unlink($temp_json_path); // Cleanup on failure.
            return null;
        }

        $json_content = file_get_contents($temp_json_path);
        unlink($temp_json_path); // Cleanup on success.

        $decoded = json_decode($json_content, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['data'])) {
            return $decoded['data'];
        }

        error_log("Starmus Waveform Service: Failed to decode JSON from audiowaveform output.");
        return null;
    }
}
       
