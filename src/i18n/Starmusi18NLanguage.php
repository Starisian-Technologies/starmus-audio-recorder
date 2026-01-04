<?php

/**
 * Starmus Audio Recorder Internationalization (i18n) Language Manager
 *
 * This class handles all internationalization functionality for the Starmus Audio Recorder plugin,
 * including text domain loading, translation management, and JavaScript localization strings.
 *
 * @package   Starisian\Sparxstar\Starmus\i18n
 *
 * @version 0.9.2
 *
 * @author    Starisian Technologies <Max Barrett - support@starisian.com>
 * @copyright 2025 Starisian Technologies
 * @license   proprietary
 */

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\i18n;

use function esc_attr;
use function esc_html;
use function load_plugin_textdomain;
use function plugin_basename;

if ( ! \defined(ABSPATH)) {
    exit;
}

/**
 * Internationalization and localization manager for AiWA Orchestrator
 *
 * Provides centralized translation functionality including:
 * - WordPress text domain loading
 * - Static translation helper methods
 * - JavaScript localization string preparation
 * - Consistent escaping for different output contexts
 *
 * @final This class should not be extended
 *
 * @since 1.0.0
 */
final class Starmusi18NLanguage
{
    /**
     * WordPress text domain for translation strings
     *
     * This constant defines the text domain used for all translations
     * within the AiWA Orchestrator plugin. It must match the domain
     * specified in the plugin header and translation files.
     *
     * @var string
     *
     * @since 1.0.0
     */
    private const DOMAIN = 'starmus-audio-recorder';

    /**
     * Initialize the internationalization system
     *
     * Sets up WordPress hooks to load the plugin text domain when WordPress
     * has finished loading all active plugins. This ensures translations are
     * available throughout the plugin lifecycle.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
		$this->register_hooks();

    }

	public function register_hooks(): void
	{
		// Load text domain on plugins_loaded action
		add_action('plugins_loaded', [$this, 'load_textdomain']);
	}

    /**
     * Load the plugin text domain for translations
     *
     * Loads translation files from the plugin's languages directory.
     * This method is automatically called via the 'plugins_loaded' WordPress hook.
     * Translation files should be named in the format: {domain}-{locale}.mo
     *
     * @since 1.0.0
     *
     * @example Translation file examples:
     * - starmus-audio-recorder-en_US.mo (English - United States)
     * - starmus-audio-recorder-fr_FR.mo (French - France)
     * - starmus-audio-recorder-es_ES.mo (Spanish - Spain)
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            self::DOMAIN,
            false,
            \dirname(plugin_basename(__FILE__), 2) . '/languages'
        );
    }

    /**
     * Translate a text string using the plugin's text domain
     *
     * This is a convenience wrapper around WordPress's __() function that
     * automatically uses the plugin's text domain. Use this method for all
     * translatable strings within the plugin.
     *
     * @since 1.0.0
     *
     * @param string $msg The text string to translate
     *
     * @return string The translated string, or original string if no translation found
     *
     * @example Basic usage:
     * echo AiWAi18NLanguage::t('Save Settings');
     * @example With sprintf formatting:
     * echo sprintf(AiWAi18NLanguage::t('Welcome, %s!'), $username);
     */
    public static function t(string $msg): string
    {
        return __($msg, 'starmus-audio-recorder');
    }

    /**
     * Get translated and escaped strings for JavaScript localization
     *
     * Provides a collection of translated strings specifically prepared for
     * use in JavaScript contexts. All strings are translated first, then
     * properly escaped based on their intended use (HTML content vs attributes).
     *
     * This method should be used with wp_localize_script() to make translated
     * strings available to frontend JavaScript code.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Associative array of translated and escaped strings
     *
     * @example Usage with wp_localize_script():
     * $i18n = new AiWAi18NLanguage();
     * wp_localize_script('aiwa-admin-js', 'aiwaL10n', $i18n->get_js_strings());
     * @example JavaScript usage:
     * console.log(aiwaL10n.welcomeMessage);
     * document.getElementById('save-btn').textContent = aiwaL10n.saveButtonText;
     */
    public function get_js_strings(): array
    {
        return [
        // All strings pass through self::t() for consistency and single translation entry
        'newsLabel'      => esc_html(self::t('News')),
        'showcaseLabel'  => esc_html(self::t('Showcase')),
        'hostingLabel'   => esc_html(self::t('Hosting')),
        'extendLabel'    => esc_html(self::t('Extend')),
        'learnLabel'     => esc_html(self::t('Learn')),
        'communityLabel' => esc_html(self::t('Community')),
        'aboutLabel'     => esc_html(self::t('About')),
        'welcomeMessage' => esc_html(self::t('Welcome, %s!')),

        // CORRECTED: Translate first, then escape for attribute use.
        'saveButtonText' => esc_attr(self::t('Save Settings')),
        ];
    }
}
