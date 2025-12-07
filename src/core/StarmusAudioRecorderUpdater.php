<?php

namespace Starisian\Sparxstar\Starmus\core;

/**
 * Handles checking for and providing updates for the Starmus plugin.
 *
 * @file        StarmusAudioRecorderUpdater.php
 *
 * @package     Starmus\core
 *
 * @version 0.9.1
 *
 * @since       0.7.2
 */
class StarmusAudioRecorderUpdater
{
    /**
     * The URL of the update server.
     *
     * @var string
     */
    private string $update_api_url = 'https://updates.starisian.com/v1/info'; // Your update server URL

    /**
     * Constructor.
     *
     * @param string $plugin_file The main plugin file path.
     * @param string $current_version The current version of the plugin.
     */
    public function __construct(private $plugin_file, private $current_version)
    {
        $this->register_hooks();
        // The crucial hook that starts the process.
    }

    private function register_hooks(): void
    {
        add_filter('pre_set_site_transient_update_plugins', $this->check_for_updates(...));
    }

    /**
     * The callback function that intercepts the WordPress update check.
     *
     * @param object $transient The WordPress update transient.
     *
     * @return object The modified transient.
     */
    public function check_for_updates($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        // 1. Make the API call to your server.
        $response = wp_remote_get($this->update_api_url . '?plugin=starmus-audio-recorder');

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return $transient; // Bail if the API call fails.
        }

        $update_data = json_decode(wp_remote_retrieve_body($response));

        // 2. Compare versions.
        if ($update_data && version_compare($this->current_version, $update_data->new_version, '<')) {

            // 3. A new version is available! Inject its data into the transient.
            $plugin_slug = plugin_basename($this->plugin_file);

            $transient->response[$plugin_slug] = (object) [
                'slug'        => 'starmus-audio-recorder',
                'plugin'      => $plugin_slug,
                'new_version' => $update_data->new_version,
                'url'         => $update_data->url, // Link to your plugin's homepage
                'package'     => $update_data->package, // The secure S3/download link for the ZIP file
                'tested'      => $update_data->tested, // e.g., "6.4.1"
            ];
        }

        return $transient;
    }
}
