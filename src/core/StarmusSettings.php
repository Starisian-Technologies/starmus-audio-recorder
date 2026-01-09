<?php

declare(strict_types=1);
/**
 * Optimized and secure settings management for the Starmus plugin.
 *
 * @package Starisian\Sparxstar\Starmus\core
 *
 * @file    StarmusSettings.php
 *
 * @author Starisian Technologies (Max Barrett) <support@starisian.com>
 *
 * @version 0.9.2
 *
 * @since 0.3.1
 */
namespace Starisian\Sparxstar\Starmus\core;

use function absint;
use function apply_filters;
use function array_filter;
use function array_map;
use function delete_option;
use function esc_url_raw;
use function explode;
use function get_option;
use function implode;
use function max;
use function min;
use function pathinfo;
use function preg_replace;
use function sanitize_key;
use function sanitize_text_field;

use Starisian\Sparxstar\Starmus\core\interfaces\IStarmusSettings;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;

use function strtolower;

use Throwable;

use function trailingslashit;
use function update_option;

if (! \defined('ABSPATH')) {
    exit;
}

/**
 * Summary of StarmusSettings
 *
 * Handles all plugin settings with caching, validation, and sanitization.
 * Provides methods to get/set individual settings and bulk update.
 * Integrates with WordPress options API for persistent storage.
 *
 * @package Starisian\Sparxstar\Starmus\core
 *
 * @version 0.9.2
 *
 * @since 0.3.1
 *
 * @author Starisian Technologies (Max Barrett) <support@starisian.com>
 */
final class StarmusSettings implements IStarmusSettings
{
    /**
     * Whitelisted MIME types for audio/video file uploads.
     *
     * Centralized in a constant for clarity and reuse across the plugin.
     * Maps file extensions to their corresponding MIME types.
     *
     * @var array<string, string>
     */
    private const ALLOWED_MIMES = [
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'ogg'  => 'audio/ogg',
    'oga'  => 'audio/ogg',
    'opus' => 'audio/ogg; codecs=opus',
    'weba' => 'audio/webm',
    'aac'  => 'audio/aac',
    'm4a'  => 'audio/mp4',
    'flac' => 'audio/flac',
    'mp4'  => 'video/mp4',
    'm4v'  => 'video/x-m4v',
    'mov'  => 'video/quicktime',
    'webm' => 'video/webm',
    'ogv'  => 'video/ogg',
    'avi'  => 'video/x-msvideo',
    'wmv'  => 'video/x-ms-wmv',
    '3gp'  => 'video/3gpp',
    '3g2'  => 'video/3gpp2',
    ];

    /**
     * WordPress option key for storing plugin settings.
     *
     * REVERTED: Back to OPTION_KEY, no transients.
     * All plugin settings are stored under this single option key in wp_options table.
     *
     * @var string
     */
    public const STARMUS_OPTION_KEY = 'starmus_options';

    /**
     * Cached plugin settings for current request.
     *
     * Using nullable type allows simple cache invalidation with `null`.
     * Reduces repeated database queries within a single request.
     *
     * @var array<string, mixed>|null
     */
    private ?array $obj_cache = null;

    /**
     * Cached default settings to avoid recomputation.
     *
     * Stores the default configuration values for all plugin settings.
     * Computed once and reused throughout the request lifecycle.
     *
     * @var array<string, mixed>|null
     */
    private ?array $default_obj_cache = null;

    /**
     * Constructor - Initializes settings and primes caches.
     *
     * Loads default settings, fetches current settings from database,
     * and registers WordPress hooks for MIME type validation.
     *
     * @return void
     */
    public function __construct()
    {
        try {
            // Prime the caches for immediate use.
            $this->default_obj_cache = $this->get_defaults();
            $this->obj_cache         = $this->all(); // This will now fetch from options
            $this->register_hooks();
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            // Initialize with defaults on error
            $this->default_obj_cache = [];
            $this->obj_cache         = [];
        }
    }

    /**
     * Register all WordPress filters related to MIME type validation.
     *
     * Hooks into WordPress upload filters to allow custom audio/video file types
     * that are configured in plugin settings.
     */
    private function register_hooks(): void
    {
        try {
            add_filter('wp_check_filetype_and_ext', $this->filter_filetype_and_ext(...), 10, 5);
            add_filter('upload_mimes', $this->filter_upload_mimes(...));
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    /**
     * Retrieve a single setting by key with default fallback.
     *
     * Fetches a specific setting value from the cached settings array.
     * Returns the provided default value if the key doesn't exist.
     *
     * @param string $key The setting key to retrieve.
     * @param mixed $default Default value to return if setting doesn't exist.
     *
     * @return mixed The setting value or default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $settings = $this->all();
            return $settings[$key] ?? $default;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return $default;
        }
    }

    /**
     * Retrieve all settings from WordPress options with caching.
     *
     * REVERTED: Now pulls from wp_options instead of transients.
     * Merges saved settings with defaults to ensure all keys exist.
     * Results are cached in obj_cache for the duration of the request.
     *
     * @return array<string, mixed> Complete settings array with defaults merged.
     */
    public function all(): array
    {
        try {
            if ($this->obj_cache === null) {
                $saved = get_option(self::STARMUS_OPTION_KEY, []);

                StarmusLogger::info(
                    'get_option result',
                    ['component' => self::class]
                );

                $merged          = wp_parse_args($saved, $this->get_defaults());
                $this->obj_cache = $merged;
            }

            return $this->obj_cache;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return $this->get_defaults();
        }
    }

    /**
     * Set a single setting value with sanitization and cache invalidation.
     *
     * REVERTED: Now updates wp_options instead of transients.
     * Validates the key, sanitizes the value, updates the option,
     * and clears the cache on success.
     *
     * @param string $key The setting key to update.
     * @param mixed $value The new value to set.
     *
     * @return bool True on successful update, false on failure or invalid key.
     */
    public function set(string $key, $value): bool
    {
        try {
            if (! $this->is_valid_key($key)) {
                return false;
            }

            $current_settings       = $this->all(); // Get current (merged) settings
            $current_settings[$key] = $this->sanitize_value($key, $value);

            $updated = update_option(self::STARMUS_OPTION_KEY, $current_settings); // Update the entire option
            if ($updated) {
                $this->clear_cache();
            }

            return $updated;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return false;
        }
    }

    /**
     * Bulk update multiple settings with validation and defaults merge.
     *
     * REVERTED: Now updates wp_options instead of transients.
     * Validates and sanitizes all provided settings, merges with defaults,
     * updates the option, and clears cache on success.
     *
     * @param array<string, mixed> $settings Associative array of setting keys and values.
     *
     * @return bool True on successful update, false on failure.
     */
    public function update_all(array $settings): bool
    {
        try {
            $sanitized = [];
            foreach ($settings as $key => $value) {
                if ($this->is_valid_key($key)) {
                    $sanitized[$key] = $this->sanitize_value($key, $value);
                }
            }

            // Ensure that the update preserves existing settings not explicitly passed
            $current_settings       = $this->all(); // Get current effective settings
            $final_settings_to_save = wp_parse_args($sanitized, $current_settings); // New values take precedence

            $updated = update_option(self::STARMUS_OPTION_KEY, $final_settings_to_save); // Update the entire option
            if ($updated) {
                $this->clear_cache();
            }

            return $updated;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return false;
        }
    }

    /**
     * Retrieve default settings with caching.
     *
     * FIXED: Syntax error in consent_message.
     * Returns the default configuration values for all plugin settings.
     * Applies 'starmus_default_settings' filter to allow customization.
     * Results are cached in default_obj_cache for the request.
     *
     * @return array<string, mixed> Default settings array.
     */
    public function get_defaults(): array
    {
        try {
            if ($this->default_obj_cache === null) {
                $this->default_obj_cache = [
                 'cpt_slug'                => 'audio-recording',
                 'file_size_limit'         => 10,
                 'recording_time_limit'    => 300,
                 'allowed_file_types'      => '',
                 'allowed_languages'       => '',
                 'speech_recognition_lang' => 'en-US',
                 'tus_endpoint'            => 'https://contribute.sparxstar.com/files/',
                 'consent_message'         => 'I consent to having this audio recording stored and used.', // FIXED SYNTAX
                 'collect_ip_ua'           => 0,
                 'delete_on_uninstall'     => 0,
                 'data_policy_url'         => '',
                 'edit_page_id'            => 0,
                 'recorder_page_id'        => 0,
                 'my_recordings_page_id'   => 0,
                ];
                $this->default_obj_cache = apply_filters('starmus_default_settings', $this->default_obj_cache);
            }

            return $this->default_obj_cache;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            // Return hardcoded minimal defaults on error
            return [
            'cpt_slug'                => 'audio-recording',
            'file_size_limit'         => 10,
            'recording_time_limit'    => 300,
            'allowed_file_types'      => '',
            'allowed_languages'       => '',
            'speech_recognition_lang' => 'en-US',
            'tus_endpoint'            => 'https://contribute.sparxstar.com/files/',
            'consent_message'         => 'I consent to having this audio recording stored and used.',
            'collect_ip_ua'           => 0,
            'delete_on_uninstall'     => 0,
            'data_policy_url'         => '',
            'edit_page_id'            => 0,
            'recorder_page_id'        => 0,
            'my_recordings_page_id'   => 0,
            ];
        }
    }

    /**
     * Initialize defaults on plugin activation using options API.
     * REVERTED: Uses add_option/update_option.
     * Adds the settings option with defaults if it doesn't exist.
     * Merges existing settings with defaults for new keys.
     * Clears cache to ensure immediate use of new defaults.
     */
    public function add_defaults_on_activation(): void
    {
        try {
            $existing_options = get_option(self::STARMUS_OPTION_KEY, false);
            if (false === $existing_options) {
                // Option doesn't exist, so add it with defaults.
                add_option(self::STARMUS_OPTION_KEY, $this->get_defaults(), '', 'yes'); // 'yes' for autoload
            } else {
                // Option exists, ensure it's merged with current defaults (for new settings in updates).
                $merged = wp_parse_args($existing_options, $this->get_defaults());
                update_option(self::STARMUS_OPTION_KEY, $merged);
            }

            $this->clear_cache(); // Clear internal cache to ensure new default is used immediately
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    /**
     * Validate that a setting key exists in default settings.
     *
     * Checks if the provided key is recognized as a valid setting
     * by checking against the default settings array.
     *
     * @param string $key The setting key to validate.
     *
     * @return bool True if key is valid, false otherwise.
     */
    private function is_valid_key(string $key): bool
    {
        return \array_key_exists($key, $this->get_defaults());
    }

    /**
     * Sanitize setting values based on their expected type.
     *
     * @param mixed $value
     */
    /**
     * Sanitize setting values based on their key/purpose.
     *
     * Applies appropriate sanitization based on setting type:
     * - Integers for file sizes and limits
     * - Sanitized text/textarea for user-facing content
     * - Boolean conversion for checkboxes
     * - Custom sanitization for file types and slugs
     *
     * @param string $key The setting key being sanitized.
     * @param mixed $value The raw value to sanitize.
     *
     * @return mixed Sanitized value appropriate for the key type.
     */
    private function sanitize_value(string $key, $value)
    {
        switch ($key) {
            case 'cpt_slug':
                return sanitize_key((string) $value);
            case 'file_size_limit':
                $v = (int) $value;
                return max(1, $v);
            case 'recording_time_limit':
                $v = (int) $value;
                return max(1, min($v, 3600));
            case 'collect_ip_ua':
                return (int) ! empty($value);
            case 'edit_page_id':
            case 'recorder_page_id':
            case 'my_recordings_page_id':
                return absint($value);
            case 'allowed_file_types':
                $list = \is_array($value) ? $value : explode(',', (string) $value);
                $list = array_map(static fn ($s) => trim(strtolower((string) $s)), $list);
                $list = array_filter($list, static fn ($s): bool => $s !== '');
                $list = array_map(static fn ($s) => preg_replace('/[^a-z0-9\.\-+\/]/', '', $s), $list);
                $list = array_unique($list);
                return implode(',', $list);
            case 'allowed_languages':
                $list = \is_array($value) ? $value : explode(',', (string) $value);
                $list = array_map(static fn ($s) => trim(strtolower((string) $s)), $list);
                $list = array_filter($list, static fn ($s): bool => $s !== '');
                $list = array_map(static fn ($s) => preg_replace('/[^a-z0-9\-]/', '', $s), $list);
                $list = array_unique($list);
                return implode(',', $list);
            case 'speech_recognition_lang':
                $sanitized = preg_replace('/[^a-zA-Z0-9\-]/', '', (string) $value);
                return empty($sanitized) ? 'en-US' : $sanitized;
            case 'tus_endpoint':
                $url = esc_url_raw((string) $value);
                return empty($url) ? 'https://contribute.sparxstar.com/files/' : trailingslashit($url);
            case 'consent_message':
                return wp_kses_post((string) $value);
            case 'data_policy_url':
                return esc_url_raw((string) $value);
            default:
                return sanitize_text_field(\is_scalar($value) ? (string) $value : '');
        }
    }

    /**
     * Clear in-memory caches.
     *
     * Resets both the settings cache and defaults cache
     * to null, forcing fresh retrieval on next access.
     */
    public function clear_cache(): void
    {
        $this->obj_cache         = null;
        $this->default_obj_cache = null;
    }

    /**
     * Delete all plugin settings (e.g., on uninstall).
     * REVERTED: Now deletes option.
     *
     * @return bool True on successful deletion, false on failure.
     */
    public function delete_all(): bool
    {
        try {
            $this->clear_cache();
            return delete_option(self::STARMUS_OPTION_KEY);
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return false;
        }
    }

    /**
     * Legacy wrapper (deprecated).
     *
     * @param mixed $default
     */
    public function starmus_get_option(string $key, $default = ''): mixed
    {
        return $this->get($key, $default);
    }

    /**
     * Filter MIME type detection to whitelist allowed formats.
     *
     * @param mixed $types
     * @param mixed $file
     * @param mixed $filename
     * @param mixed $mimes_allowed
     * @param mixed $real_mime
     */
    public function filter_filetype_and_ext($types, $file, $filename, $mimes_allowed, $real_mime): array
    {
        try {
            unset($file, $mimes_allowed, $real_mime);

            $ext       = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
            $whitelist = self::get_allowed_mimes();

            if (isset($whitelist[$ext])) {
                return [
                 'ext'             => $ext,
                 'type'            => $whitelist[$ext],
                 'proper_filename' => $filename,
                ];
            }

            return \is_array($types) ? $types : [];
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return \is_array($types) ? $types : [];
        }
    }

    /**
     * Filter upload MIME whitelist to allow audio/video formats.
     *
     * @param array<string, string> $mimes Existing MIME types map.
     *
     * @return array<string, string> Modified MIME types map.
     */
    public function filter_upload_mimes(array $mimes): array
    {
        try {
            foreach (self::get_allowed_mimes() as $ext => $mime) {
                $mimes[$ext] = $mime;
            }

            return $mimes;
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return $mimes;
        }
    }

    /**
     * Public static getter for the allowed MIME map.
     *
     * @returns array<string, string> The allowed MIME types map.
     */
    public static function get_allowed_mimes(): array
    {
        return self::ALLOWED_MIMES;
    }
}
