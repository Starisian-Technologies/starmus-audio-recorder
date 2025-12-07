<?php

/**
 * @package Starmus\Tests\Unit
 * @version 0.9.2
 * @since 0.3.1
 */

declare(strict_types=1);

namespace Starmus\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StarmusCustomPostTypeTest extends TestCase
{
    public function testCustomPostTypeFileExists(): void
    {
        $file = dirname(dirname(__DIR__)) . '/src/includes/StarmusCustomPostType.php';
        $this->assertTrue(
            file_exists($file),
            'StarmusCustomPostType.php file does not exist'
        );
    }
}
