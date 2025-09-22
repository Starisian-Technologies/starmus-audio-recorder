<?php
namespace Starmus\core;
/**
 * Handles checking for and providing updates for the Starmus plugin.
 *
 * @file        StarmusPluginUpdater.php
 * @package     Starmus\core
 * @version     0.7.2
 * @since       0.7.2
 *
 */
class StarmusPluginUpdater {

    private $plugin_file;
    private $current_version;
    private $update_api_url = 'https://updates.starisian.com/v1/info'; // Your update server URL

    public function __construct( $plugin_file, $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->current_version = $current_version;

        // The crucial hook that starts the process. is added to main plugin class.
    }

    /**
     * The callback function that intercepts the WordPress update check.
     *
     * @param object $transient The WordPress update transient.
     * @return object The modified transient.
     */
    public function check_for_updates( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        // 1. Make the API call to your server.
        $response = wp_remote_get( $this->update_api_url . '?plugin=starmus-audio-recorder' );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return $transient; // Bail if the API call fails.
        }

        $update_data = json_decode( wp_remote_retrieve_body( $response ) );

        // 2. Compare versions.
        if ( $update_data && version_compare( $this->current_version, $update_data->new_version, '<' ) ) {

            // 3. A new version is available! Inject its data into the transient.
            $plugin_slug = plugin_basename( $this->plugin_file );

            $transient->response[ $plugin_slug ] = (object) array(
                'slug'        => 'starmus-audio-recorder',
                'plugin'      => $plugin_slug,
                'new_version' => $update_data->new_version,
                'url'         => $update_data->url, // Link to your plugin's homepage
                'package'     => $update_data->package, // The secure S3/download link for the ZIP file
                'tested'      => $update_data->tested, // e.g., "6.4.1"
            );
        }

        return $transient;
    }
}
