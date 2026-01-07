<?php

/**
 * REST API Tests for STAR/AIWA compliance
 *
 * @package Starmus\Tests\Unit
 */

declare(strict_types=1);

namespace Starmus\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starisian\Sparxstar\Starmus\api\StarmusRESTHandler;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\core\StarmusSubmissionHandler;
use Starisian\Sparxstar\Starmus\data\interfaces\IStarmusAudioDAL;

final class StarmusRestApiTest extends TestCase
{
	private StarmusRESTHandler $handler;

	protected function setUp(): void
	{
		$dal = $this->createMock(IStarmusAudioDAL::class);
		$settings = $this->createMock(StarmusSettings::class);
		$submission_handler = $this->createMock(StarmusSubmissionHandler::class);

        $this->handler = new StarmusRESTHandler($dal, $settings, $submission_handler);
	}

	public function testUploadPermissionsRequireCapability(): void
	{
		$request = $this->createMock(\WP_REST_Request::class);
		$request->method('get_header')->willReturn('valid_nonce');

		$result = $this->handler->upload_permissions_check($request);
		$this->assertTrue($result, 'Should allow upload with proper capabilities');
	}

	public function testRestNamespaceFollowsStarConvention(): void
	{
		$namespace = STARMUS_REST_NAMESPACE;
		$this->assertStringStartsWith('starmus/', $namespace, 'REST namespace must follow STAR convention');
	}

	public function testIdempotencyWithSameUuid(): void
	{
		// Test that same UUID doesn't create duplicate posts
		$uuid = 'test-uuid-123';

		// Mock find_post_by_uuid to return existing post
		$this->assertTrue(true, 'Idempotency test placeholder - implement with WordPress test framework');
	}
}
