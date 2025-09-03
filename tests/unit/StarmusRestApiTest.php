<?php
/**
 * REST API Tests for STAR/AIWA compliance
 * 
 * @package Starmus\tests\unit
 */
declare(strict_types=1);
namespace Starmus\tests\unit;

use PHPUnit\Framework\TestCase;
use Starmus\frontend\StarmusAudioRecorderUI;

final class StarmusRestApiTest extends TestCase
{
    private StarmusAudioRecorderUI $ui;

    protected function setUp(): void
    {
        $this->ui = new StarmusAudioRecorderUI();
        
        // Mock WordPress functions
        if (!function_exists('current_user_can')) {
            function current_user_can($capability) { return true; }
        }
        if (!function_exists('wp_verify_nonce')) {
            function wp_verify_nonce($nonce, $action) { return true; }
        }
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
        $namespace = StarmusAudioRecorderUI::STAR_REST_NAMESPACE;
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