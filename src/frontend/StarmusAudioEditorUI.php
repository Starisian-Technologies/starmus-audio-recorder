<?php
/**
 * Manages the front-end audio editing interface.
 *
 * @package Starmus\frontend
 */

namespace Starmus\frontend;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StarmusAudioEditorUI {
    
    public function __construct() {
        add_shortcode('starmus_audio_editor', [ $this, 'render_audio_editor_shortcode' ]);
        add_action('wp_enqueue_scripts', [ $this, 'enqueue_scripts' ]);
        add_action('rest_api_init', [ $this, 'register_rest_endpoint' ]);
    }

	/**
	 * Renders the Peaks.js audio editor interface by including a template file.
	 *
	 * @return string HTML for the editor.
	 */
	public function render_audio_editor_shortcode(): string {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'Invalid submission ID or you do not have permission to edit this item.', 'starmus_audio_recorder' ) . '</p></div>';
		}

		// --- UPDATED: Load the UI from a dedicated template file ---
	$tpl = locate_template('starmus/audio-editor.php') ?: STARMUS_PATH.'templates/starmus-audio-editor-ui.php';
	ob_start();
	include $tpl;
	return ob_get_clean();
	}

	/**
	 * Conditionally enqueues scripts and styles for the editor.
	 */
	public function enqueue_scripts(): void {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		if ( has_shortcode( $post->post_content, 'starmus_audio_editor' ) ) {
			$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) return;

			$attachment_id = get_post_meta( $post_id, '_audio_attachment_id', true );
			$audio_url = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
			$annotations = get_post_meta( $post_id, 'starmus_annotations_json', true ) ?: '[]';

                        // --- NEW: Enqueue the editor-specific stylesheet ---
                        $css_path = STARMUS_PATH . 'assets/css/starmus-audio-editor.css';
                        $css_version = file_exists( $css_path ) ? filemtime( $css_path ) : STARMUS_VERSION;
                        wp_enqueue_style( 'starmus-audio-editor', STARMUS_URL . 'assets/css/starmus-audio-editor-style.min.css', [], $css_version );


                        $peaks_path = STARMUS_PATH . 'assets/js/peaks.min.js';
                        $peaks_ver  = file_exists( $peaks_path ) ? md5_file( $peaks_path ) : STARMUS_VERSION;
                        wp_enqueue_script('peaks', STARMUS_URL . 'assets/js/peaks.min.js', [], $peaks_ver, true);
                        wp_enqueue_script('starmus-audio-editor', STARMUS_URL . 'assets/js/starmus-audio-editor.min.js', ['peaks','jquery'], @filemtime(STARMUS_PATH.'assets/js/starmus-audio-editor.js') ?: STARMUS_VERSION, true);


			wp_localize_script( 'starmus-audio-editor', 'STARMUS_EDITOR_DATA', [
				'restUrl'     => esc_url_raw( rest_url( 'starmus/v1/annotations' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'postId'      => $post_id,
				'audioUrl'    => $audio_url,
				'annotations' => json_decode( $annotations, true ),
			]);
		}
	}

	/**
	 * Registers the REST API endpoint to save annotations.
	 */
	public function register_rest_endpoint(): void {
		register_rest_route('starmus/v1','/annotations',[
			'methods'=>'POST',
			'callback'=>[$this,'handle_save_annotations'],
			'permission_callback'=>fn($r)=> ($pid=(int)$r['postId']) && current_user_can('edit_post',$pid),
			'args'=>[
				'postId'=>['type'=>'integer','required'=>true],
				'annotations'=>['type'=>'array','required'=>true],
			],
		]);
	}

    /**
     * Callback function to handle saving the annotation data.
     */
    public function handle_save_annotations( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id     = $request->get_param( 'postId' );
        $new_annotations = $request->get_param( 'annotations' );
        $existing_annotations_json = get_post_meta( $post_id, 'starmus_annotations_json', true );
        $existing_annotations = ! empty( $existing_annotations_json ) ? json_decode( $existing_annotations_json, true ) : [];
        if (!is_array($existing_annotations)) $existing_annotations = [];
        $merged_annotations = [];
        $all_annotations = array_merge($existing_annotations, $new_annotations);
        $seen_ids = [];
        foreach (array_reverse($all_annotations) as $annotation) {
            if (isset($annotation['id']) && !in_array($annotation['id'], $seen_ids, true)) {
                array_unshift($merged_annotations, $annotation);
                $seen_ids[] = $annotation['id'];
            }
        }
        $sanitized_annotations = [];
        foreach ( $merged_annotations as $segment ) {
            if (empty($segment['id']) || !isset($segment['startTime']) || !isset($segment['endTime'])) continue;
            $sanitized_annotations[] = [
                'id'        => sanitize_text_field( $segment['id'] ),
                'startTime' => (float) $segment['startTime'],
                'endTime'   => (float) $segment['endTime'],
                'label'     => isset($segment['label']) ? sanitize_text_field( $segment['label'] ) : '',
            ];
        }
        usort($sanitized_annotations, fn($a, $b) => $a['startTime'] <=> $b['startTime']);
		for ($i=1;$i<count($sanitized_annotations);$i++){
			if ($sanitized_annotations[$i]['startTime'] < $sanitized_annotations[$i-1]['endTime']){
				return new \WP_REST_Response(['success'=>false,'message'=>'Overlapping annotations are not allowed.'],400);
			}
		}
		update_post_meta( $post_id, 'starmus_annotations_json', wp_json_encode( $sanitized_annotations ) );
		return new \WP_REST_Response( [ 'success' => true, 'message' => 'Annotations saved.', 'annotations' => $sanitized_annotations, ], 200 );
    }
}
