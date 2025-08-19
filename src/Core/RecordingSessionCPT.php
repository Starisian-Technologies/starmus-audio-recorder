<?php
namespace Starisian\src\Core;

/**
 * Registers the `recording-session` custom post type and its meta fields.
 */
class RecordingSessionCPT {
    public const POST_TYPE = 'recording-session';

    /**
     * Meta fields stored with each recording session.
     */
    private array $meta_fields = [
        'consent'      => ['type' => 'string'],
        'session_date' => ['type' => 'string'],
        'language'     => ['type' => 'string'],
        'dialect'      => ['type' => 'string'],
    ];

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('init', [$this, 'register_meta']);
    }

    /**
     * Registers the custom post type.
     */
    public function register_cpt(): void {
        register_post_type(self::POST_TYPE, [
            'label'       => __('Recording Sessions', 'starmus-audio-recorder'),
            'public'      => false,
            'show_ui'     => true,
            'supports'    => ['title'],
            'has_archive' => false,
            'rewrite'     => false,
        ]);
    }

    /**
     * Registers meta fields for the recording session.
     */
    public function register_meta(): void {
        foreach ($this->meta_fields as $key => $args) {
            register_post_meta(self::POST_TYPE, $key, array_merge([
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback'     => function () {
                    return current_user_can('edit_posts');
                },
            ], $args));
        }
    }
}
