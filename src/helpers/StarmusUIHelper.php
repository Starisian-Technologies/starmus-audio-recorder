<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\helpers;

use function esc_html;
use function is_admin;

/**
 * Starmus UI Helper Functions
 *
 * @file src/helpers/StarmusUIHelper.php
 *
 * @package Starisian\Sparxstar\Starmus\helpers
 *
 * @author Starisian Technologies
 * @license Starisian Technologies Proprietary License
 *
 * @version 1.0.0
 *
 * @since 1.0.0
 */

if ( ! \defined('ABSPATH')) {
    exit();
}

/**
 * Static helper class for Starmus UI related functions.
 *
 * @package Starisian\Sparxstar\Starmus\helpers
 */

final class StarmusUIHelper
{
    /**
     * Render an error message in the WordPress admin area.
     *
     * @param string $message The error message to display.
     */
    public static function renderError(string $message): void
    {
        if (is_admin()) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }
}
