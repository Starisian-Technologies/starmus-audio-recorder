<?php

/**
 * @package Starmus\Tests\Unit
 * @version 0.8.5
 * @since 0.3.1
 */

declare(strict_types=1);

namespace Starmus\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\Sparxstar\Starmus\frontend\StarmusAudioRecorderUI;

final class StarmusAudioRecorderUITest extends TestCase
{
    public function testEnqueueMethodExists(): void
    {
        $this->assertTrue(
            method_exists(StarmusAudioRecorderUI::class, 'enqueue_scripts'),
            'enqueue_scripts() method does not exist'
        );
    }
}
