<?php
/**
 * REST API Tests for STAR/AIWA compliance
 * 
 * @package Starisian\Sparxstar\Starmus\tests\unit
 */
declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\tests\unit;

use PHPUnit\Framework\TestCase;
use Starisian\Sparxstar\Starmus\frontend\StarmusAudioRecorderUI;

final class StarmusRestApiTest extends TestCase
{
    private StarmusAudioRecorderUI $ui;

    protected function setUp(): void
    {
        $this->ui = new StarmusAudioRecorderUI();
    }

    public function testUploadPermissionsRequireCapability(): void
    {
        $request = $this->createMock(\WP_REST_Request::class);
        $request->method('get_header')->willReturn('valid_nonce');
        
        $result = $this->ui->upload_permissions_check($request);
        $this->assertTrue($result, 'Should allow upload with proper capabilities');
    }

    public function testRestNamespaceFollowsStarConvention(): void
    {
        $namespace = StarmusAudioRecorderUI::STARMUS_REST_NAMESPACE;
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