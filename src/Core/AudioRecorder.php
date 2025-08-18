<?php
namespace Starisian\src\Core;

use Starisian\src\Includes\StarmusAudioSubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AudioRecorder {
    const VERSION = '0.5.0';
    const MINIMUM_PHP_VERSION = '7.2';
    const MINIMUM_WP_VERSION = '5.2';

    private static ?AudioRecorder $instance = null;
    private string $plugin_path;
    private string $plugin_url;
    private ?StarmusAudioSubmissionHandler $StarmusHandler = null;

    private function __construct() {
        $this->plugin_path = STARMUS_PATH;
        $this->plugin_url  = STARMUS_URL;

        if ( ! $this->check_compatibility() ) {
            add_action( 'admin_notices', [ $this, 'admin_notice_compatibility' ] );
            return;
        }

        if ( ! isset( $this->StarmusHandler ) ) {
            $this->StarmusHandler = new StarmusAudioSubmissionHandler();
        }
    }

    public static function get_instance(): AudioRecorder {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function check_compatibility(): bool {
        if ( version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '<' ) ) {
            return false;
        }

        global $wp_version;
        if ( version_compare( $wp_version, self::MINIMUM_WP_VERSION, '<' ) ) {
            return false;
        }

        return true;
    }

    public function admin_notice_compatibility(): void {
        echo '<div class="notice notice-error"><p>';
        if ( version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '<' ) ) {
            echo esc_html__( 'Starmus Audio Recorder requires PHP version ' . self::MINIMUM_PHP_VERSION . ' or higher.', 'starmus-audio-recorder' ) . '<br>';
        }
        if ( version_compare( $GLOBALS['wp_version'], self::MINIMUM_WP_VERSION, '<' ) ) {
            echo esc_html__( 'Starmus Audio Recorder requires WordPress version ' . self::MINIMUM_WP_VERSION . ' or higher.', 'starmus-audio-recorder' );
        }
        echo '</p></div>';
    }

    public static function starmus_run(): void {
        if ( ! isset( $GLOBALS['Starisian\\AudioRecorder'] ) || ! $GLOBALS['Starisian\\AudioRecorder'] instanceof self ) {
            $GLOBALS['Starisian\\AudioRecorder'] = self::get_instance();
        }
    }

    public static function starmus_activate(): void {
        flush_rewrite_rules();
    }

    public static function starmus_deactivate(): void {
        flush_rewrite_rules();
    }

    public static function starmus_uninstall(): void {
        // Optional cleanup logic for uninstall.
        // Example: delete_option('starmus_audio_recorder_settings');
    }
}
