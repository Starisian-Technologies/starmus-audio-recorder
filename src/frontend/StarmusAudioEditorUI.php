<?php
/**
 * Manages the front-end audio editing interface with Peaks.js.
 *
 * @package Starmus\frontend
 */

namespace Starmus\frontend;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StarmusAudioEditorUI {
    
    /**
     * Constructor. Registers hooks for the shortcode, scripts, and REST API.
     */
    public function __construct() {
        add_shortcode('starmus_audio_editor', [ $this, 'render_audio_editor_shortcode' ]);
        add_action('wp_enqueue_scripts', [ $this, 'enqueue_scripts' ]);
        add_action('rest_api_init', [ $this, 'register_rest_endpoint' ]);
    }

	/**
	 * Renders the Peaks.js audio editor interface.
	 *
	 * @return string HTML for the editor.
	 */
	public function render_audio_editor_shortcode(): string {
		$context = $this->get_editor_context();

		if ( is_wp_error($context) ) {
			return '<div class="notice notice-error"><p>' . esc_html( $context->get_error_message() ) . '</p></div>';
		}

		// This allows themes to override the template file.
		$tpl = locate_template('starmus/audio-editor.php') ?: STARMUS_PATH.'templates/starmus-audio-editor-ui.php';
		
		ob_start();
		// The $context array is now available inside the included template file.
		include $tpl;
		return ob_get_clean();
	}

	/**
	 * Conditionally enqueues scripts and styles for the editor page.
	 */
	public function enqueue_scripts(): void {
		global $post;
		// Only proceed if the current page contains the shortcode.
		if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'starmus_audio_editor' ) ) {
			return;
		}

		$context = $this->get_editor_context();
		if ( is_wp_error($context) ) {
			// Do not enqueue scripts if there's an error (e.g., no permission, invalid ID).
			return;
		}

		// Enqueue the editor-specific stylesheet with file modification time for cache-busting.
		wp_enqueue_style(
			'starmus-audio-editor-style',
			STARMUS_URL . 'assets/css/starmus-audio-editor-style.min.css',
			[],
			filemtime( STARMUS_PATH . 'assets/css/starmus-audio-editor-style.min.css' )
		);

		// Enqueue Peaks.js from the vendor directory.
		wp_enqueue_script(
			'peaks-js',
			STARMUS_URL . 'assets/js/vendor/peaks.min.js',
			[],
			'2.0.0', // It's good practice to use the library's actual version number.
			true
		);

		// Enqueue your main editor script, making it dependent on Peaks.js.
		wp_enqueue_script(
			'starmus-audio-editor',
			STARMUS_URL . 'assets/js/starmus-audio-editor.min.js',
			[ 'peaks-js', 'jquery', 'wp-element' ], // wp-element is useful for React-like components.
			filemtime( STARMUS_PATH . 'assets/js/starmus-audio-editor.min.js' ),
			true
		);

		// Pass all necessary data from PHP to our JavaScript file.
		wp_localize_script( 'starmus-audio-editor', 'STARMUS_EDITOR_DATA', [
			'restUrl'         => esc_url_raw( rest_url( 'starmus/v1/annotations' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'postId'          => $context['post_id'],
			'audioUrl'        => $context['audio_url'],
			'waveformDataUrl' => $context['waveform_url'], // Critical data for Peaks.js
			'annotations'     => json_decode( $context['annotations_json'], true ),
		]);
	}

    /**
     * Gathers post context and checks permissions.
	 * This avoids code duplication between the shortcode and enqueue methods.
     *
     * @return array|\WP_Error Array of context data on success, WP_Error on failure.
     */
    private function get_editor_context() {
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

        if ( ! $post_id ) {
            return new \WP_Error('invalid_id', __('Invalid submission ID.', 'starmus_audio_recorder'));
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new \WP_Error('permission_denied', __('You do not have permission to edit this item.', 'starmus_audio_recorder'));
        }

        $attachment_id = get_post_meta( $post_id, '_audio_attachment_id', true );
		if (!$attachment_id) {
			return new \WP_Error('no_audio', __('No audio file is attached to this submission.', 'starmus_audio_recorder'));
		}
		
		// Get waveform data path from post meta (set during upload).
		$waveform_path = get_post_meta($attachment_id, '_waveform_json_path', true);
		
		// Convert the server path of the waveform file to a public URL.
		$uploads_dir = wp_get_upload_dir();
		$waveform_url = $waveform_path ? str_replace($uploads_dir['basedir'], $uploads_dir['baseurl'], $waveform_path) : '';

        return [
            'post_id'        	 => $post_id,
            'attachment_id'  	 => $attachment_id,
            'audio_url'      	 => wp_get_attachment_url( $attachment_id ),
			'waveform_url'   	 => $waveform_url,
            'annotations_json'   => get_post_meta( $post_id, 'starmus_annotations_json', true ) ?: '[]',
        ];
    }
    
	/**
	 * Registers the REST API endpoint to save annotations.
	 */
	public function register_rest_endpoint(): void {
		register_rest_route('starmus/v1','/annotations',[
			'methods'=>'POST',
			'callback'=>[$this,'handle_save_annotations'],
			'permission_callback'=>fn($request)=> ($post_id=(int)$request['postId']) && current_user_can('edit_post',$post_id),
			'args'=>[
				'postId'=>['type'=>'integer','required'=>true, 'sanitize_callback' => 'absint'],
				'annotations'=>['type'=>'array','required'=>true],
			],
		]);
	}

    /**
     * Callback function to handle saving the annotation data via REST API.
     */
    public function handle_save_annotations( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id     = $request->get_param( 'postId' );
        $annotations = $request->get_param( 'annotations' );

        // Sanitize the incoming annotation data.
        $sanitized_annotations = [];
        if ( is_array($annotations) ) {
            foreach ( $annotations as $segment ) {
                if (empty($segment['id']) || !isset($segment['startTime']) || !isset($segment['endTime'])) continue;
                $sanitized_annotations[] = [
                    'id'        => sanitize_text_field( $segment['id'] ),
                    'startTime' => (float) $segment['startTime'],
                    'endTime'   => (float) $segment['endTime'],
                    'label'     => isset($segment['label']) ? sanitize_text_field( $segment['label'] ) : '',
                ];
            }
        }
        
        // Sort by start time to prepare for validation.
        usort( $sanitized_annotations, fn( $a, $b ) => $a['startTime'] <=> $b['startTime'] );

        // Validate for overlapping annotations.
        for ( $i = 1; $i < count( $sanitized_annotations ); $i++ ) {
            if ( $sanitized_annotations[ $i ]['startTime'] < $sanitized_annotations[ $i - 1 ]['endTime'] ) {
                return new \WP_REST_Response(
                    [
                        'success' => false,
                        'message' => __( 'Overlapping annotations are not allowed.', 'starmus_audio_recorder' ),
                    ],
                    400
                );
            }
        }

        update_post_meta( $post_id, 'starmus_annotations_json', wp_json_encode( $sanitized_annotations ) );

        return new \WP_REST_Response(
            [
                'success'      => true,
                'message'      => __( 'Annotations saved.', 'starmus_audio_recorder' ),
                'annotations'  => $sanitized_annotations,
            ],
            200
        );
    }
}