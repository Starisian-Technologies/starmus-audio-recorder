<?php
/**
 * @package Starmus\tests\unit
 * @version 0.4.7
 * @since 0.3.1
 */

declare(strict_types=1);
namespace Starmus\tests\unit;
use PHPUnit\Framework\TestCase;
use Starmus\frontend\StarmusAudioRecorderUI;

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
