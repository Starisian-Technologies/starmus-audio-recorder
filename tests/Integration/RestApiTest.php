<?php

/**
 * WordPress REST API Integration Tests
 * 
 * @package Starmus\Tests\Integration
 */

declare(strict_types=1);

namespace Starmus\Tests\Integration;

class RestApiTest extends \WP_UnitTestCase
{

    private $user_id;
    private $ui;

    public function setUp(): void
    {
        parent::setUp();

        // Create test user with upload capability
        $this->user_id = $this->factory->user->create([
            'role' => 'editor'
        ]);
        wp_set_current_user($this->user_id);

        $this->ui = new \Starmus\frontend\StarmusAudioRecorderUI();
    }

    public function test_rest_namespace_follows_star_convention()
    {
        $namespace = \Starmus\includes\StarmusSubmissionHandler::STARMUS_REST_NAMESPACE;
        $this->assertStringStartsWith('star-', $namespace);
    }

    public function test_upload_requires_authentication()
    {
        wp_set_current_user(0); // Logout

        $request = new WP_REST_Request('POST', '/star-starmus-audio-recorder/v1/upload-chunk');
        $response = $this->ui->upload_permissions_check($request);

        $this->assertFalse($response);
    }

    public function test_upload_requires_capability()
    {
        // User without upload_files capability
        $user_id = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $request = new WP_REST_Request('POST', '/star-starmus-audio-recorder/v1/upload-chunk');
        $response = $this->ui->upload_permissions_check($request);

        $this->assertFalse($response);
    }

    public function test_upload_requires_valid_nonce()
    {
        $request = new WP_REST_Request('POST', '/star-starmus-audio-recorder/v1/upload-chunk');
        $request->set_header('X-WP-Nonce', 'invalid_nonce');

        $response = $this->ui->upload_permissions_check($request);

        $this->assertFalse($response);
    }

    public function test_idempotent_post_creation()
    {
        $uuid = 'test-uuid-' . wp_generate_uuid4();

        // Create first post
        $post_id_1 = wp_insert_post([
            'post_type' => 'audio-recording',
            'post_status' => 'draft',
            'post_author' => $this->user_id,
            'meta_input' => ['audio_uuid' => $uuid]
        ]);

        // Try to create duplicate - should find existing
        $existing_post = $this->ui->find_post_by_uuid($uuid);

        $this->assertEquals($post_id_1, $existing_post->ID);
    }
}
