<?php
namespace Starisian\src\includes;

// No need for all the 'use function' statements here if not used.
use Starisian\src\admin\StarmusAdminSettings;
use Starisian\src\frontend\StarmusAudioEditorUI;
use Starisian\src\frontend\StarmusAudioRecorderUI;

/**
 * Plugin Loader.
 * Initializes the different parts of the plugin based on context and user roles.
 */
class StarmusPlugin {
    /**
     * Initialize the plugin's components.
     * This is the single entry point for loading functionality.
     */
    public static function init() {
        if ( is_admin() ) {
            new StarmusAdminSettings();
        }

        // Conditionally load frontend components if the user is logged in
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( array_intersect( [ 'author', 'editor', 'administrator' ], (array) $user->roles ) || is_super_admin( $user->ID ) ) {
                new StarmusAudioEditorUI();
            }
            if ( array_intersect( [ 'contributor', 'community_contributor', 'editor', 'author', 'administrator' ], (array) $user->roles ) || is_super_admin( $user->ID ) ) {
                new StarmusAudioRecorderUI();
            }
        }
    }
}