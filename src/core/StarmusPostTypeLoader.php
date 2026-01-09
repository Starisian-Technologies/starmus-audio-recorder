<?php

declare(strict_types=1);
namespace Starisian\Sparxstar\Starmus\core;

/**
 * @file StarmusPostTypeLoader.php
 * @author Starisian Technologies (Max Barrett) <support@starisian.com>
 * @license Starisian Technologies Proprietary License (STPL) - see LICENSE file for details
 * @copyright Copyright (c) 2020-2024 Starisian Technologies
 * @link https://starisian.com/
 */

use Exception;
use function add_action;
use function register_post_type;
use function register_taxonomy;
use function defined;

if( ! defined('ABSPATH')) {
    exit;
} // Exit if accessed directly
/**
 * Class StarmusPostTypeLoader
 *
 * Registers custom post types and taxonomies for the Starmus Audio Recorder plugin.
 * @package Starisian\Sparxstar\Starmus\core
 * @version 1.0.0
 * @since 1.0.0
 */
final class StarmusPostTypeLoader
{
    /**
     * Summary of instance
     * @var StarmusPostTypeLoader|null
     */
    private static ?StarmusPostTypeLoader $instance = null;
    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct()
    {
        $this->registerPostTypes();
    }
    /**
     * Gets the singleton instance of StarmusPostTypeLoader.
     *
     * @return StarmusPostTypeLoader|null
     */
    public static function starmus_getInstance(): StarmusPostTypeLoader{
        if (self::$instance === null) {
            self::$instance = new StarmusPostTypeLoader();
        }
        return self::$instance;
    }
    /**
     * Registers Custom Post Types and Taxonomies
     * PHP exported from Secure Custome Fields version 6.8
     * @source Secure Custom Fields Plugin source code for registerPostTypes()
     * @version 6.8
     *
     * @return void
     */
    private function registerPostTypes(): void
    {
        add_action('init', function () {
            register_taxonomy('starmus_tax_audio_quality', [
            0 => 'audio-recording',
            ], [
            'labels' => [
            'name' => 'Audio Quality',
            'singular_name' => 'Audio Quality',
            ],
            'description' => 'How clear and easy to hear the recording is.',
            'public' => false,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);

            register_taxonomy('starmus_tax_audio_status', [
            0 => 'audio-recording',
            ], [
            'labels' => [
            'name' => 'Audio Statuses',
            'singular_name' => 'Audio Status',
            ],
            'description' => 'Shows if the file is new, being worked on, or finished.',
            'public' => false,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);

            register_taxonomy('starmus_tax_dialect', [
            0 => 'audio-script',
            1 => 'audio-recording',
            2 => 'starmus_transcript',
            3 => 'starmus_translate',
            ], [
            'labels' => [
            'name' => 'Dialects',
            'singular_name' => 'Dialect',
            ],
            'description' => 'Different ways of speaking the same language in different places.',
            'public' => false,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);

            register_taxonomy('starmus_genre', [
            0 => 'audio-script',
            1 => 'audio-recording',
            2 => 'starmus_release',
            ], [
            'labels' => [
            'name' => 'Genres',
            'singular_name' => 'Genre',
            ],
            'description' => 'The type of music or story (like Rock, Jazz, or Comedy).',
            'public' => false,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);

            register_taxonomy('starmus_tax_language', [
            0 => 'audio-script',
            1 => 'audio-recording',
            2 => 'starmus_transcript',
            3 => 'starmus_translate',
            ], [
            'labels' => [
            'name' => 'Languages',
            'singular_name' => 'Language',
            ],
            'description' => 'The language spoken or written in the file.',
            'public' => false,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);

            register_taxonomy('starmus_mood', [
            0 => 'audio-script',
            1 => 'audio-recording',
            2 => 'starmus_release',
            ], [
            'labels' => [
            'name' => 'Moods',
            'singular_name' => 'Mood',
            ],
            'description' => 'The feeling the content gives you (like Happy, Sad, or Scary).',
            'public' => false,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);

            register_taxonomy('starmus_part_of_speech', [
            0 => 'starmus_transcript',
            1 => 'starmus_translate',
            ], [
            'labels' => [
            'name' => 'Part of Speech',
            'singular_name' => 'Part of Speech',
            ],
            'description' => 'The type of word (Noun, Verb, Adjective).',
            'public' => false,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);

            register_taxonomy('starmus_tax_project', [
            0 => 'audio-script',
            1 => 'audio-recording',
            ], [
            'labels' => [
            'name' => 'Projects',
            'singular_name' => 'Project',
            ],
            'description' => 'Groups files together into larger collections.',
            'public' => false,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);

            register_taxonomy('starmus_story_type', [
            0 => 'starmus_transcript',
            1 => 'starmus_translate',
            ], [
            'labels' => [
            'name' => 'Story Type',
            'singular_name' => 'Story Type',
            ],
            'description' => 'The kind of story being told (like fairy tale, interview, or speech).',
            'public' => false,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);

            register_taxonomy('starmus_subject', [
            0 => 'starmus_transcript',
            1 => 'starmus_translate',
            ], [
            'labels' => [
            'name' => 'Subject',
            'singular_name' => 'Subject',
            ],
            'description' => 'What the text is about or talks about.',
            'public' => false,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);

            register_taxonomy('starmus_theme', [
            0 => 'audio-script',
            1 => 'audio-recording',
            2 => 'starmus_release',
            ], [
            'labels' => [
            'name' => 'Themes',
            'singular_name' => 'Theme',
            ],
            'description' => 'The main topic or idea (like Friendship or Space Travel).',
            'public' => false,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);

            register_taxonomy('starmus_workflow_status', [
            0 => 'audio-script',
            1 => 'audio-recording',
            2 => 'starmus_transcript',
            3 => 'starmus_translate',
            4 => 'starmus_release',
            ], [
            'labels' => [
            'name' => 'Workflow Status',
            'singular_name' => 'Workflow Status',
            ],
            'description' => 'Shows if the work is just started, in progress, being checked, or done.',
            'public' => false,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_admin_column' => true,
            ]);
        });

        add_action('init', function () {
            register_post_type('audio-recording', [
            'labels' => [
            'name' => 'Audio Recordings',
            'singular_name' => 'Audio Recording',
            ],
            'description' => 'The actual sound files and their technical details.',
            'public' => false,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-microphone',
            'capability_type' => [
            0 => 'audio_recording',
            1 => 'audio_recordings',
            ],
            'map_meta_cap' => true,
            'supports' => [
            0 => 'title',
            1 => 'editor',
            2 => 'author',
            3 => 'thumbnail',
            4 => 'revisions',
            5 => 'comments',
            6 => 'custom-fields',
            ],
            'taxonomies' => [
            0 => 'starmus_tax_language',
            1 => 'starmus_tax_dialect',
            2 => 'starmus_tax_project',
            3 => 'starmus_tax_audio_quality',
            4 => 'starmus_tax_audio_status',
            5 => 'starmus_genre',
            6 => 'starmus_mood',
            7 => 'starmus_theme',
            8 => 'starmus_workflow_status',
            ],
            'delete_with_user' => false,
            ]);

            register_post_type('starmus_transcript', [
            'labels' => [
            'name' => 'Audio Transcriptions',
            'singular_name' => 'Audio Transcription',
            ],
            'description' => 'The written-out text of what was said in a recording.',
            'public' => false,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-editor-textcolor',
            'capability_type' => [
            0 => 'transcript',
            1 => 'transcripts',
            ],
            'map_meta_cap' => true,
            'supports' => [
            0 => 'title',
            1 => 'editor',
            2 => 'author',
            3 => 'thumbnail',
            4 => 'revisions',
            5 => 'comments',
            6 => 'custom-fields',
            ],
            'taxonomies' => [
            0 => 'starmus_tax_language',
            1 => 'starmus_tax_dialect',
            2 => 'starmus_tax_project',
            3 => 'starmus_workflow_status',
            4 => 'starmus_part_of_speech',
            5 => 'starmus_subject',
            6 => 'starmus_story_type',
            ],
            'delete_with_user' => false,
            ]);

            register_post_type('starmus_translate', [
            'labels' => [
            'name' => 'Audio Translations',
            'singular_name' => 'Audio Translation',
            ],
            'description' => 'Text translated from the original language into a new one.',
            'public' => false,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-translation',
            'capability_type' => [
            0 => 'translation',
            1 => 'translations',
            ],
            'map_meta_cap' => true,
            'supports' => [
            0 => 'title',
            1 => 'editor',
            2 => 'author',
            3 => 'thumbnail',
            4 => 'revisions',
            5 => 'comments',
            6 => 'custom-fields',
            ],
            'taxonomies' => [
            0 => 'starmus_tax_language',
            1 => 'starmus_tax_dialect',
            2 => 'starmus_tax_project',
            3 => 'starmus_workflow_status',
            4 => 'starmus_part_of_speech',
            5 => 'starmus_subject',
            6 => 'starmus_story_type',
            ],
            'delete_with_user' => false,
            ]);

            register_post_type('audio-script', [
            'labels' => [
            'name' => 'Scripts',
            'singular_name' => 'Script',
            ],
            'description' => 'Text documents meant to be read out loud.',
            'public' => false,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-format-aside',
            'capability_type' => [
            0 => 'audio_script',
            1 => 'audio_scripts',
            ],
            'map_meta_cap' => true,
            'supports' => [
            0 => 'title',
            1 => 'editor',
            2 => 'author',
            3 => 'revisions',
            4 => 'custom-fields',
            ],
            'taxonomies' => [
            0 => 'starmus_tax_language',
            1 => 'starmus_tax_dialect',
            2 => 'starmus_tax_project',
            3 => 'starmus_genre',
            4 => 'starmus_mood',
            5 => 'starmus_theme',
            6 => 'starmus_workflow_status',
            ],
            'delete_with_user' => false,
            ]);

            register_post_type('starmus_release', [
            'labels' => [
            'name' => 'Releases',
            'singular_name' => 'Release',
            ],
            'description' => 'Albums, EPs, or Singles prepared for public distribution.',
            'public' => false,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-album',
            'capability_type' => [
            0 => 'release',
            1 => 'releases',
            ],
            'map_meta_cap' => true,
            'supports' => [
            0 => 'title',
            1 => 'editor',
            2 => 'author',
            3 => 'thumbnail',
            4 => 'revisions',
            5 => 'custom-fields',
            ],
            'taxonomies' => [
            0 => 'starmus_tax_language',
            1 => 'starmus_tax_dialect',
            2 => 'starmus_tax_project',
            3 => 'starmus_genre',
            4 => 'starmus_mood',
            5 => 'starmus_theme',
            6 => 'starmus_workflow_status',
            ],
            'delete_with_user' => false,
            ]);

            register_post_type('sparx_contributor', [
            'labels' => [
            'name' => 'Contributors',
            'singular_name' => 'Contributor',
            ],
            'description' => 'People who helped create the work, like authors or performers.',
            'public' => false,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-groups',
            'capability_type' => [
            0 => 'contributor',
            1 => 'contributors',
            ],
            'map_meta_cap' => true,
            'supports' => [
            0 => 'title',
            1 => 'editor',
            2 => 'thumbnail',
            3 => 'custom-fields',
            ],
            'delete_with_user' => false,
            ]);
        });
    }

    public function deletePostTypes(): void
    {
        // Unregister post types if needed (WordPress does not support unregistering natively)
        // This is a placeholder for any cleanup logic if required in the future.

    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function __clone()
    {
        throw new \Exception('Cloning of ' . __CLASS__ . ' is not allowed.');
    }
    public function __wakeup()
    {
        throw new \Exception('Unserializing of ' . __CLASS__ . ' is not allowed.');
    }

}
