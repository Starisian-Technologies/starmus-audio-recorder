<?php

/**
 * @package Starmus\Tests\Unit
 * @version 0.9.2
 * @since 0.3.1
 */

declare(strict_types=1);

namespace Starmus\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\Sparxstar\Starmus\StarmusAudioRecorder;

final class StarmusPluginTest extends TestCase
{
    public function testSingletonInstanceReturnsSameObject(): void
    {
        $instance1 = StarmusAudioRecorder::starmus_get_instance();
        $instance2 = StarmusAudioRecorder::starmus_get_instance();

        $this->assertInstanceOf(StarmusAudioRecorder::class, $instance1);
        $this->assertSame($instance1, $instance2, 'Expected the same instance (singleton)');
    }

    public function testInitMethodRunsWithoutError(): void
    {
        // Initialization happens in constructor, triggered by get_instance
        $plugin = StarmusAudioRecorder::starmus_get_instance();

        // You might want to mock dependencies later
        $this->expectNotToPerformAssertions();
    }
}
