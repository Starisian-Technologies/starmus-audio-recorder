<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\core\interfaces;

/**
 * Interface for StarmusSettings.
 */
interface IStarmusSettings
{
    /**
     * Retrieve a single setting by key with default fallback.
     *
     * @param string $key The setting key to retrieve.
     * @param mixed $default Default value to return if setting doesn't exist.
     * @return mixed The setting value or default.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Retrieve all settings.
     *
     * @return array<string, mixed>
     */
    public function all(): array;
}
