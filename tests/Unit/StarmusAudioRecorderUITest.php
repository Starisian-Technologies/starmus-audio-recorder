<?php

/**
 * @package Starmus\Tests\Unit
 * @version 0.9.2
 * @since 0.3.1
 */

declare(strict_types=1);

namespace Starmus\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\Sparxstar\Starmus\frontend\StarmusAudioRecorderUI;

final class StarmusAudioRecorderUITest extends TestCase
{
	public function testRenderRecorderShortcodeMethodExists(): void
	{
		$this->assertTrue(
			method_exists(StarmusAudioRecorderUI::class, 'render_recorder_shortcode'),
			'render_recorder_shortcode() method does not exist'
		);
	}
}
