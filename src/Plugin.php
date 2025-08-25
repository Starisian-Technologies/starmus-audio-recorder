<?php
/**
 * Main plugin loader.
 *
 * @package Starisian\Starmus
 */

namespace Starisian\Starmus;

use Exception;
use Starisian\src\includes\StarmusPlugin;

final class Plugin {
    public const MINIMUM_PHP_VERSION = '8.2';
    public const MINIMUM_WP_VERSION = '6.4';

    private StarmusPlugin $starmus_plugin;
    private array $compatibility_messages = [];

    public function __construct() {
        if ( ! $this->check_compatibility() ) {
            add_action( 'admin_notices', [ $this, 'display_compatibility_notice' ] );
            return;
        }

        $this->load_starmus_plugin();

        register_activation_hook( STARMUS_MAIN_FILE, [ self::class, 'activate' ] );
        register_deactivation_hook( STARMUS_MAIN_FILE, [ self::class, 'deactivate' ] );
        register_uninstall_hook( STARMUS_MAIN_FILE, [ self::class, 'uninstall' ] );

        add_action( 'init', [ $this, 'init' ] );
    }

    private function load_starmus_plugin(): void {
        try {
            $this->starmus_plugin = StarmusPlugin::get_instance();
        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Failed to load StarmusPlugin: ' . $e->getMessage() );
            }
        }
    }

    public function init(): void {
        $this->starmus_plugin->init();
    }

    public static function activate(): void {
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * WARNING: This is a destructive operation.
     * Deletes all data associated with this plugin.
     */
    public static function uninstall(): void {
        require_once STARMUS_PATH . 'src/admin/StarmusAdmin.php';

        $cpt_slug = 'starmus_submission';
        if ( class_exists( '\\Starisian\\src\\admin\\StarmusAdminSettings' ) ) {
            $cpt_slug = \Starisian\src\admin\StarmusAdminSettings::get_option( 'cpt_slug', $cpt_slug );
        }

        delete_option( 'starmus_settings' );

        $posts = get_posts(
            [
                'post_type'   => $cpt_slug,
                'numberposts' => -1,
                'post_status' => 'any',
            ]
        );

        foreach ( $posts as $post ) {
            wp_delete_post( $post->ID, true );
        }
    }

    private function check_compatibility(): bool {
        if ( version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '<' ) ) {
            $this->compatibility_messages[] = sprintf(
                __( 'Starmus Audio Recorder requires PHP %1$s or higher. You are running %2$s.', 'starmus-audio-recorder' ),
                self::MINIMUM_PHP_VERSION,
                PHP_VERSION
            );
        }

        if ( version_compare( get_bloginfo( 'version' ), self::MINIMUM_WP_VERSION, '<' ) ) {
            $this->compatibility_messages[] = sprintf(
                __( 'Starmus Audio Recorder requires WordPress %1$s or higher. You are running %2$s.', 'starmus-audio-recorder' ),
                self::MINIMUM_WP_VERSION,
                get_bloginfo( 'version' )
            );
        }

        return empty( $this->compatibility_messages );
    }

    public function display_compatibility_notice(): void {
        foreach ( $this->compatibility_messages as $message ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
        }
    }

    private function __clone() {}

    public function __wakeup(): void {
        $this->check_compatibility();
    }
}
