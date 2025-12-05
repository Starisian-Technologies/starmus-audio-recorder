<?php

/**
 * Interface for admin page modules.
 *
 * @package Starisian\Sparxstar\Starmus\admin\interfaces
 */

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\admin\interfaces;

if (! \defined('ABSPATH')) {
    exit;
}

/**
 * Interface StarmusAdminInterface
 *
 * Defines the contract for any admin page loaded via StarmusAdminOrchestrator.
 *
 * Implementing classes must:
 *  - Define capability via get_capability() for role-based access.
 *  - Output page UI via render().
 *  - Be instantiated and injected into the orchestrator's constructor.
 *  - Optionally, use is_active() to disable the module without removing it from DI.
 */
interface IStarmusAdminInterface
{
    /**
     * Renders the admin page content. This is hooked into `load-{$page_hook}`.
     */
    public function render(): void;

    /**
     * Returns the WordPress capability required to access this page.
     *
     * @return string The capability string (e.g., 'manage_options', 'edit_posts').
     */
    public function get_capability(): string;

    /**
     * Whether this handler should be auto-registered by the orchestrator.
     * Allows for disabling unfinished modules without removing them from dependency injection.
     *
     * @return bool True if the page should be registered, false otherwise.
     */
    public static function is_active(): bool;
}
