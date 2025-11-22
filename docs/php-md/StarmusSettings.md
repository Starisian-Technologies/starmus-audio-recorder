# StarmusSettings

**Namespace:** `Starisian\Sparxstar\Starmus\core`

**File:** `/workspaces/starmus-audio-recorder/src/core/StarmusSettings.php`

## Description

Optimized and secure settings management for the Starmus plugin.
@package Starisian\Sparxstar\Starmus\core
@version 0.8.5
@since 0.3.1

## Methods

### `__construct()`

**Visibility:** `public`

Optimized and secure settings management for the Starmus plugin.
@package Starisian\Sparxstar\Starmus\core
@version 0.8.5
@since 0.3.1
/

namespace Starisian\Sparxstar\Starmus\core;

if (! defined('ABSPATH')) {
	exit;
}

final class StarmusSettings
{

	/**
Whitelisted MIME types for audio/video file uploads.
Centralized in a constant for clarity and reuse across the plugin.
Maps file extensions to their corresponding MIME types.
@var array<string, string>
/
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
WordPress option key for storing plugin settings.
REVERTED: Back to OPTION_KEY, no transients.
All plugin settings are stored under this single option key in wp_options table.
@var string
/
	public const STARMUS_OPTION_KEY = 'starmus_options';

	/**
Cached plugin settings for current request.
Using nullable type allows simple cache invalidation with `null`.
Reduces repeated database queries within a single request.
@var array<string, mixed>|null
/
	private ?array $obj_cache = null;

	/**
Cached default settings to avoid recomputation.
Stores the default configuration values for all plugin settings.
Computed once and reused throughout the request lifecycle.
@var array<string, mixed>|null
/
	private ?array $default_obj_cache = null;

	/**
Constructor - Initializes settings and primes caches.
Loads default settings, fetches current settings from database,
and registers WordPress hooks for MIME type validation.
@return void

### `get()`

**Visibility:** `public`

Retrieve a single setting by key with default fallback.
Fetches a specific setting value from the cached settings array.
Returns the provided default value if the key doesn't exist.
@param string $key     The setting key to retrieve.
@param mixed  $default Default value to return if setting doesn't exist.
@return mixed The setting value or default.

### `all()`

**Visibility:** `public`

Retrieve all settings from WordPress options with caching.
REVERTED: Now pulls from wp_options instead of transients.
Merges saved settings with defaults to ensure all keys exist.
Results are cached in obj_cache for the duration of the request.
@return array<string, mixed> Complete settings array with defaults merged.

### `set()`

**Visibility:** `public`

Set a single setting value with sanitization and cache invalidation.
REVERTED: Now updates wp_options instead of transients.
Validates the key, sanitizes the value, updates the option,
and clears the cache on success.
@param string $key   The setting key to update.
@param mixed  $value The new value to set.
@return bool True on successful update, false on failure or invalid key.

### `update_all()`

**Visibility:** `public`

Bulk update multiple settings with validation and defaults merge.
REVERTED: Now updates wp_options instead of transients.
Validates and sanitizes all provided settings, merges with defaults,
updates the option, and clears cache on success.
@param array<string, mixed> $settings Associative array of setting keys and values.
@return bool True on successful update, false on failure.

### `get_defaults()`

**Visibility:** `public`

Retrieve default settings with caching.
FIXED: Syntax error in consent_message.
Returns the default configuration values for all plugin settings.
Applies 'starmus_default_settings' filter to allow customization.
Results are cached in default_obj_cache for the request.
@return array<string, mixed> Default settings array.

### `add_defaults_on_activation()`

**Visibility:** `public`

Initialize defaults on plugin activation using options API.
REVERTED: Uses add_option/update_option.

### `clear_cache()`

**Visibility:** `public`

Clear in-memory caches.

### `delete_all()`

**Visibility:** `public`

Delete all plugin settings (e.g., on uninstall).
REVERTED: Now deletes option.

### `starmus_get_option()`

**Visibility:** `public`

Legacy wrapper (deprecated).

### `filter_filetype_and_ext()`

**Visibility:** `public`

Filter MIME type detection to whitelist allowed formats.

### `filter_upload_mimes()`

**Visibility:** `public`

Filter upload MIME whitelist to allow audio/video formats.

### `get_allowed_mimes()`

**Visibility:** `public`

Public static getter for the allowed MIME map.

## Properties

---

_Generated by Starisian Documentation Generator_
