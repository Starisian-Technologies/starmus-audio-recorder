<?php

/**
 * Dependency Manager & System Validator.
 *
 * A self-contained static class responsible for the "Environment Negotiation" phase.
 * It enforces version requirements, detects conflicts, and loads bundled dependencies
 * in a deterministic order.
 *
 * @package Starisian\Sparxstar\Starmus\helpers
 * @version 1.5.0
 */

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\helpers;

use function add_action;
use function add_filter;
use function class_exists;
use function define;
use function defined;
use function file_exists;
use function function_exists;
use function implode;
use function is_admin;
use function is_plugin_active;
use function phpversion;
use function version_compare;

if ( ! defined('ABSPATH')) {
    exit;
}

class StarmusDependencies
{
    public const SPARXSTAR_SCF_PRE_INSTALLED = false;
    public const SPARXSTAR_SCF_INSTALLED     = false;
    private const MIN_PHP_VERSION = '8.2';

    private const MIN_WP_VERSION  = '6.8';

    /**
     * Main Entry Point: Validates environment and loads dependencies.
     *
     * Call this from the bootloader. If it returns true, the Core is safe to launch.
     *
     * @return bool True if environment is healthy and dependencies loaded.
     */
    public static function bootstrap_scf(): bool
    {
        try{
            // 1. Check System Requirements (PHP/WP)
            if ( ! self::check_versions()) {
                return false;
            }
            if ( ! self::is_safe_to_load_scf()) {
                return false;
            }
            // 2. Negotiate Secure Custom Fields (Conflict Check + Loading)
            if ( ! self::load_scf()) {
                return false;
            }

            // 3. Register Schema (Only if SCF loaded successfully)
            self::register_acf_schema();
        } catch (\Throwable $throwable) {
            self::render_error(['Exception during dependency bootstrap: ' . $throwable->getMessage()]);
            return false;
        }
        return true;
    }

    /**
     * 0. Check PHP and WordPress Versions.
     */
    private static function check_versions(): bool
    {
        error_log('Starmus Version Check Started.');
        $errors = [];
        try{
            if (version_compare(phpversion(), self::MIN_PHP_VERSION, '<')) {
                  $errors[] = 'PHP version ' . self::MIN_PHP_VERSION . '+ is required. Running: ' . phpversion();
            }

            global $wp_version;
            if (version_compare($wp_version, self::MIN_WP_VERSION, '<')) {
                $errors[] = 'WordPress version ' . self::MIN_WP_VERSION . '+ is required. Running: ' . $wp_version;
            }

            if ($errors !== []) {
                self::render_error($errors);
                return false;
            }
        }catch(\Throwable $throwable){
            self::render_error(['Exception during version check: ' . $throwable->getMessage()]);
            return false;
        }

        return true;
    }

    public static function print_test_message(): void
    {
        error_log('Starmus Dependencies Loaded Successfully.');
    }

    public static function is_scf_installed(): bool
    {
        if (defined('SPARXSTAR_SCF_PRE_INSTALLED') && SPARXSTAR_SCF_PRE_INSTALLED === true) {
            error_log('SCF Pre-installed environment detected.');
            return true;
        }
        if (defined('SPARXSTAR_SCF_INSTALLED') && SPARXSTAR_SCF_INSTALLED === true) {
            error_log('SCF already installed.');
            return true;
        }
        return false;
    }

    public static function is_scf_sparxstar_installed(): bool
    {
        if (defined('SPARXSTAR_SCF_PRE_INSTALLED') && SPARXSTAR_SCF_PRE_INSTALLED === true) {
            error_log('SCF Pre-installed environment detected.');
            return false;
        }
        if (defined('SPARXSTAR_SCF_INSTALLED') && SPARXSTAR_SCF_INSTALLED === true) {
            error_log('SPARXSTAR SCF installed environment.');
            return true;
        }
        return false;
    }

    /**
     * 1 Check if ACF/SCF is installed.
     */
    public static function is_safe_to_load_scf(): bool
    {
        error_log('Starmus SCF Load Safety Check Started.');
        try{

            // Ensure helper functions are available
            if ( ! function_exists('is_plugin_active')) {
                  include_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            // A. Check for HARD CONFLICTS (Active Plugins)
            // These plugins explicitly conflict with our bundled version logic.
            $conflicting_plugins = [
            'secure-custom-fields/secure-custom-fields.php',
            'advanced-custom-fields-pro/acf.php',
            'advanced-custom-fields/acf.php',
            ];

            foreach ($conflicting_plugins as $plugin) {
                if (is_plugin_active($plugin)) {
                    self::render_error([
                     'Conflict Detected: An external version of ACF or SCF is active (' . $plugin . ').',
                     'Please deactivate it to allow Starmus to load its required bundled version.'
                    ]);
                    return false;
                }
            }

            // B. Check for PRE-LOADED ENVIRONMENT (Composer/Other)
            // If code is present but not an active plugin, it is likely a shared library.
            if (function_exists('acf_get_instance') || class_exists('ACF')) {
                // VALIDATION: Ensure the environment is actually usable.
                if ( ! function_exists('acf_add_local_field_group')) {
                    self::render_error([
                    'Dependency Error: A preloaded ACF/SCF environment was detected, but it appears incomplete or corrupted.',
                    'Missing required function: acf_add_local_field_group'
                    ]);
                    return false;
                }
                // Environment is valid. Install our schema.
                define( 'SPARXSTAR_SCF_PRE_INSTALLED', true);
                // Skip loading our bundled version.
                error_log('Starmus Detected Pre-Installed ACF/SCF Environment. Skipping bundled load.');
                return false;
            }
        } catch (\Throwable $throwable) {
            self::render_error(['Exception during SCF load safety check: ' . $throwable->getMessage()]);
            return false;
        }
        // Install our bundled version.
        return true;
    }

    /**
     * 2. Load Bundled SCF (With Strict Activation Checks).
     */
    private static function load_scf(): bool
    {
        error_log('Starmus SCF Load Started.');
        // A. Preloaded Environment Detected: Skip Loading
        if (defined('SPARXSTAR_SCF_PRE_INSTALLED') && SPARXSTAR_SCF_PRE_INSTALLED === true) {
            error_log('Starmus SCF Load Skipped: Pre-installed environment detected.');
            define('SPARXSTAR_SCF_INSTALLED', true);
            return true;
        }
        // C. Installation: Load Bundled Version
        $scf_path = STARMUS_PATH . 'vendor/secure-custom-fields/secure-custom-fields.php';
        try{
            if ( ! file_exists($scf_path)) {
                self::render_error(['Critical Dependency Missing: bundled Secure Custom Fields not found.']);
                return false;
            }

            // Configure SCF Constants & Filters
            if ( ! defined('SPARXSTAR_ACF_PATH')) {
                 define('SPARXSTAR_ACF_PATH', STARMUS_PATH . 'vendor/secure-custom-fields/');
            }
            if ( ! defined('SPARXSTAR_ACF_URL')) {
                define('SPARXSTAR_ACF_URL', STARMUS_URL . 'vendor/secure-custom-fields/');
            }

            add_filter('acf/settings/path', fn() => SPARXSTAR_ACF_PATH);
            add_filter('acf/settings/url', fn() => SPARXSTAR_ACF_URL);
            add_filter('acf/settings/show_admin', '__return_false');
            add_filter('acf/settings/show_updates', '__return_false', 100);

            require_once $scf_path;

            // Final Verification
            if ( ! function_exists('acf_get_instance')) {
                self::render_error(['Dependency Load Failed: Secure Custom Fields could not initialize.']);
                return false;
            }
        }catch(\Throwable $throwable){
            self::render_error(['Exception during SCF load: ' . $throwable->getMessage()]);
            return false;
        }
        define('SPARXSTAR_SCF_INSTALLED', true);
        return true;
    }

    /**
     * 3. Register JSON Schema Paths.
     */
    private static function register_acf_schema(): void
    {
        error_log('Starmus ACF Schema Registration Started.');
        if( ! defined('SPARXSTAR_SCF_INSTALLED') || SPARXSTAR_SCF_INSTALLED !== true) {
            error_log('Starmus ACF Schema Registration Skipped: SCF not installed.');
            return;
        }
        try{
            if ( ! function_exists('acf_add_local_field_group')) {
                throw new \RuntimeException('ACF function acf_add_local_field_group missing.');
            }

            $acfPath = STARMUS_PATH . 'acf-json';
            // Ensure directory exists
            if( ! file_exists($acfPath)) {
                wp_die('Starmus Error: ACF JSON schema directory missing at ' . esc_html($acfPath));
            }

            add_filter('acf/settings/save_json', fn(): string => $acfPath);
            add_filter('acf/settings/load_json', function ($acfPath): array {
                $paths[] = $acfPath;
                return $paths;
            });
        } catch (\Throwable $throwable) {
            self::render_error(['Exception during ACF schema registration: ' . $throwable->getMessage()]);
        }
    }

    /**
     * Helper to display admin notices.
     */
    private static function render_error(array $messages): void
    {
        error_log('Starmus Dependency Error: ' . implode(' | ', $messages));
        if ( ! is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }

        add_action('admin_notices', function () use ($messages): void {
            ?>
			<div class="notice notice-error">
				<p><strong>Starmus Audio Recorder Error:</strong></p>
				<ul>
            <?php foreach ($messages as $msg) : ?>
						<li><?php echo esc_html($msg); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
            <?php
        });
    }
}
