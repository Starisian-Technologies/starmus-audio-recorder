<?php
namespace Starmus\includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers ACF field groups for Recording Sessions.
 */
class RecordingSessionFields {
    public function __construct() {
        add_action( 'acf/init', [ $this, 'register_field_groups' ] );
    }

    public function register_field_groups(): void {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            return;
        }

        // Agreement to Terms field group
        acf_add_local_field_group( [
            'key'    => 'group_689a647f54a3a',
            'title'  => esc_html__( 'Agreement to Terms', 'starmus-audio-recorder' ),
            'fields' => [
                [
                    'key'   => 'field_689a64f8ab306',
                    'label' => esc_html__( 'Agreed to Contributor Terms', 'starmus-audio-recorder' ),
                    'name'  => 'agreed_to_contributor_terms',
                    'type'  => 'true_false',
                    'ui'    => 0,
                ],
                [
                    'key'   => 'field_689a6646ab30c',
                    'label' => esc_html__( 'Contributor ID', 'starmus-audio-recorder' ),
                    'name'  => 'contributor_id',
                    'type'  => 'text',
                ],
                [
                    'key'   => 'field_689a6480ab303',
                    'label' => esc_html__( 'Contributor Name', 'starmus-audio-recorder' ),
                    'name'  => 'contributor_name',
                    'type'  => 'text',
                ],
                [
                    'key'           => 'field_689a649fab304',
                    'label'         => esc_html__( 'Contributor Signature', 'starmus-audio-recorder' ),
                    'name'          => 'contributor_signature',
                    'type'          => 'file',
                    'return_format' => 'array',
                    'library'       => 'all',
                ],
                [
                    'key'   => 'field_689a64dfab305',
                    'label' => esc_html__( 'Agreement Datetime', 'starmus-audio-recorder' ),
                    'name'  => 'agreement_datetime',
                    'type'  => 'datetime_picker',
                ],
                [
                    'key'   => 'field_689a6588ab30a',
                    'label' => esc_html__( 'URL', 'starmus-audio-recorder' ),
                    'name'  => 'url',
                    'type'  => 'page_link',
                ],
                [
                    'key'   => 'field_689a6524ab307',
                    'label' => esc_html__( 'Contributor IP', 'starmus-audio-recorder' ),
                    'name'  => 'contributor_ip',
                    'type'  => 'text',
                ],
                [
                    'key'   => 'field_689a6563ab308',
                    'label' => esc_html__( 'Submission ID', 'starmus-audio-recorder' ),
                    'name'  => 'submission_id',
                    'type'  => 'text',
                ],
                [
                    'key'   => 'field_689a6572ab309',
                    'label' => esc_html__( 'Contributor User Agent', 'starmus-audio-recorder' ),
                    'name'  => 'contributor_user_agent',
                    'type'  => 'text',
                ],
                [
                    'key'   => 'field_689a65a6ab30b',
                    'label' => esc_html__( 'Contributor Geolocation', 'starmus-audio-recorder' ),
                    'name'  => 'contributor_geolocation',
                    'type'  => 'google_map',
                ],
            ],
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'recording-session',
                    ],
                ],
            ],
            'position'     => 'acf_after_title',
            'style'        => 'default',
            'show_in_rest' => 1,
        ] );

        // Recording Session Metadata field group
        acf_add_local_field_group( [
            'key'    => 'group_682cba4f12a3b',
            'title'  => esc_html__( 'Recording Session Metadata', 'starmus-audio-recorder' ),
            'fields' => [
                [
                    'key'         => 'field_682cba6d12a3c',
                    'label'       => esc_html__( 'Project / Collection ID', 'starmus-audio-recorder' ),
                    'name'        => 'project_collection_id',
                    'type'        => 'text',
                    'instructions'=> esc_html__( 'Identifier for the project or collection this session belongs to. Helps group related sessions.', 'starmus-audio-recorder' ),
                ],
                [
                    'key'         => 'field_682cbad312a3d',
                    'label'       => esc_html__( 'Accession Number', 'starmus-audio-recorder' ),
                    'name'        => 'accession_number',
                    'type'        => 'text',
                    'instructions'=> esc_html__( 'Unique accession or catalog number for this specific recording session.', 'starmus-audio-recorder' ),
                ],
                [
                    'key'          => 'field_682cbb1a12a3e',
                    'label'        => esc_html__( 'Session Date', 'starmus-audio-recorder' ),
                    'name'         => 'session_date',
                    'type'         => 'date_picker',
                    'display_format'=> 'F j, Y',
                    'return_format'=> 'Y-m-d',
                ],
                [
                    'key'          => 'field_682cbb6d12a3f',
                    'label'        => esc_html__( 'Session Start Time', 'starmus-audio-recorder' ),
                    'name'         => 'session_start_time',
                    'type'         => 'time_picker',
                    'display_format'=> 'g:i a',
                    'return_format'=> 'H:i:s',
                ],
                [
                    'key'          => 'field_682cbba712a40',
                    'label'        => esc_html__( 'Session End Time', 'starmus-audio-recorder' ),
                    'name'         => 'session_end_time',
                    'type'         => 'time_picker',
                    'display_format'=> 'g:i a',
                    'return_format'=> 'H:i:s',
                ],
                [
                    'key'         => 'field_682cbc0a12a41',
                    'label'       => esc_html__( 'Location', 'starmus-audio-recorder' ),
                    'name'        => 'location',
                    'type'        => 'text',
                    'instructions'=> esc_html__( 'Physical place where the recording session occurred (e.g., building, room, address).', 'starmus-audio-recorder' ),
                ],
                [
                    'key'   => 'field_682cbc5312a42',
                    'label' => esc_html__( 'GPS Coordinates', 'starmus-audio-recorder' ),
                    'name'  => 'gps_coordinates',
                    'type'  => 'google_map',
                ],
                [
                    'key'         => 'field_682cbd3812a43',
                    'label'       => esc_html__( 'Narrators / Contributors', 'starmus-audio-recorder' ),
                    'name'        => 'narrators_contributors',
                    'type'        => 'relationship',
                    'post_type'   => [ 'person' ],
                    'filters'     => [ 'search' ],
                    'return_format'=> 'object',
                    'multiple'    => 1,
                ],
                [
                    'key'         => 'field_682cbdb412a44',
                    'label'       => esc_html__( 'Interviewers / Recorders', 'starmus-audio-recorder' ),
                    'name'        => 'interviewers_recorders',
                    'type'        => 'relationship',
                    'post_type'   => [ 'person' ],
                    'filters'     => [ 'search' ],
                    'return_format'=> 'object',
                    'multiple'    => 1,
                ],
                [
                    'key'   => 'field_682cbe3e12a45',
                    'label' => esc_html__( 'Recording Equipment', 'starmus-audio-recorder' ),
                    'name'  => 'recording_equipment',
                    'type'  => 'textarea',
                    'rows'  => 3,
                ],
                [
                    'key'           => 'field_682cbf3112a46',
                    'label'         => esc_html__( 'Audio Files (Originals)', 'starmus-audio-recorder' ),
                    'name'          => 'audio_files_originals',
                    'type'          => 'file',
                    'return_format' => 'array',
                    'library'       => 'all',
                    'mime_types'    => 'wav, aiff, flac',
                    'multiple'      => 1,
                ],
                [
                    'key'   => 'field_682cc0b112a48',
                    'label' => esc_html__( 'Media Condition Notes', 'starmus-audio-recorder' ),
                    'name'  => 'media_condition_notes',
                    'type'  => 'textarea',
                    'rows'  => 3,
                ],
                [
                    'key'   => 'field_682cc16312a49',
                    'label' => esc_html__( 'Usage Restrictions / Rights', 'starmus-audio-recorder' ),
                    'name'  => 'usage_restrictions_rights',
                    'type'  => 'textarea',
                    'rows'  => 4,
                ],
                [
                    'key'     => 'field_682cc20a12a4a',
                    'label'   => esc_html__( 'Access Level', 'starmus-audio-recorder' ),
                    'name'    => 'access_level',
                    'type'    => 'select',
                    'choices' => [
                        'public'     => esc_html__( 'Public', 'starmus-audio-recorder' ),
                        'restricted' => esc_html__( 'Restricted', 'starmus-audio-recorder' ),
                        'embargoed'  => esc_html__( 'Embargoed', 'starmus-audio-recorder' ),
                        'private'    => esc_html__( 'Private', 'starmus-audio-recorder' ),
                    ],
                    'default_value' => 'public',
                ],
            ],
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'recording-session',
                    ],
                ],
            ],
            'position'     => 'normal',
            'style'        => 'default',
            'show_in_rest' => 1,
        ] );
    }
}
