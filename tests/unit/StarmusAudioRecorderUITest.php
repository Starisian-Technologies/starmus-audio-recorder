<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Starisian\src\frontend\StarmusAudioRecorderUI;

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
