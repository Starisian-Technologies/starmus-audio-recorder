<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\core\interfaces;

/**
 * @file IStarmusContentBundle.php
 *
 * @package Starisian/Sparxstar/Starmus/core/interfaces
 *
 * @author Starisian Technologies (Max Barrett) <support@starisian.com>
 * @license Starisian Technologies Proprietary License (STPD)
 * @copyright Copyright (c) 2025-2026 Starisian Technologies. All rights reserved.
 *
 * @version
 *
 * @since 1.0.0
 */

if (! \defined('ABSPATH')) {
    exit;
}

/**
 * Interface IContentBundle
 *
 * Contract for loading CPTs, field groups and taxonomies for SCF
 *
 * @package Starisian/Sparxstar/Starmus/core/interfaces
 */
interface IContentBundle
{
    /**
     * Register CPT, fields, taxonomies, and rules.
     * This method is allowed to call SCF functions directly.
     */
    public function sparxStarmusRegister(): void;

    /**
     * Stable identifier for auditing / registry / tooling.
     */
    public function sparxStarmusGetId(): string;
}
