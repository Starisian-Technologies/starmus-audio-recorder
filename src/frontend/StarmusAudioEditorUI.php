<?php
/**
 * Manages the front-end audio editing interface with Peaks.js.
 * This version includes hooks for extensibility and high-impact hardening patches.
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

	public function render_audio_editor_shortcode(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'You must be logged in to edit audio.', 'starmus_audio_recorder' ) . '</p>';
        }

        do_action('starmus_before_editor_render');
		$context = $this->get_editor_context();
		if ( is_wp_error($context) ) {
			return '<div class="notice notice-error"><p>' . esc_html( $context->get_error_message() ) . '</p></div>';
		}
		$template_path = STARMUS_PATH . 'src/templates/starmus-audio-editor-ui.php';
        $template_path = apply_filters('starmus_editor_template', $template_path, $context);
		ob_start();
		include $template_path;
		return ob_get_clean();
	}

    /**
     * PATCH 1: Robust enqueue (works with block themes, no global `$post` dependency).
     */
    public function enqueue_scripts(): void {
        if ( ! is_singular() ) return;
        $post = get_queried_object();
        $content = is_object($post) ? (string) ($post->post_content ?? '') : '';
        $has_shortcode = function_exists('has_shortcode') && has_shortcode($content, 'starmus_audio_editor');
        if ( ! $has_shortcode && ! str_contains($content, '[starmus_audio_editor') ) return;

        $ctx = $this->get_editor_context(); if ( is_wp_error($ctx) ) return;

        wp_enqueue_style('starmus-audio-editor-style', STARMUS_URL.'assets/css/starmus-audio-editor-style.min.css', [], STARMUS_VERSION);
        wp_enqueue_script('peaks-js', STARMUS_URL.'assets/js/vendor/peaks.min.js', [], '2.0.0', true);
        wp_enqueue_script('starmus-audio-editor', STARMUS_URL.'assets/js/starmus-audio-editor.min.js', ['peaks-js','jquery'], STARMUS_VERSION, true);

        wp_localize_script('starmus-audio-editor','STARMUS_EDITOR_DATA',[
            'restUrl'=>esc_url_raw(rest_url('starmus/v1/annotations')),
            'nonce'=>wp_create_nonce('wp_rest'),
            'postId'=>(int)$ctx['post_id'],
            'audioUrl'=>$ctx['audio_url'],
            'waveformDataUrl'=>$ctx['waveform_url'],
            'annotations'=>json_decode($ctx['annotations_json'], true),
        ]);
    }
    
    /**
     * PATCH 2: Context validation (checks attachment relation, waveform existence, safer defaults).
     */
    private function get_editor_context() {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if ( ! $post_id ) return new \WP_Error('invalid_id', __('Invalid submission ID.','starmus_audio_recorder'));
        if ( ! current_user_can('edit_post',$post_id) ) return new \WP_Error('permission_denied', __('You do not have permission to edit this item.','starmus_audio_recorder'));

        $attachment_id = (int) get_post_meta($post_id, '_audio_attachment_id', true);
        if ( ! $attachment_id || 'attachment' !== get_post_type($attachment_id) )
            return new \WP_Error('no_audio', __('No audio file is attached to this submission.','starmus_audio_recorder'));

        $audio_url = wp_get_attachment_url($attachment_id);
        if ( ! $audio_url ) return new \WP_Error('no_audio_url', __('Audio file URL not available.','starmus_audio_recorder'));

        $uploads = wp_get_upload_dir();
        $wave_json_path = (string) get_post_meta($attachment_id, '_waveform_json_path', true);
        $waveform_url = ($wave_json_path && file_exists($wave_json_path))
            ? str_replace($uploads['basedir'], $uploads['baseurl'], $wave_json_path)
            : '';

        return [
            'post_id'=>$post_id,
            'attachment_id'=>$attachment_id,
            'audio_url'=>$audio_url,
            'waveform_url'=>$waveform_url,
            'annotations_json'=> get_post_meta($post_id, 'starmus_annotations_json', true) ?: '[]',
        ];
    }
    
	/**
	 * PATCH 3: REST payload hardening (size limits, schema checks, basic rate-limit).
	 */
	public function register_rest_endpoint(): void {
		register_rest_route('starmus/v1','/annotations',[
			'methods'=>'POST',
			'callback'=>[$this,'handle_save_annotations'],
			'permission_callback'=>fn($req)=> ($pid=(int)$req['postId']) && current_user_can('edit_post',$pid),
			'args'=>[
				'postId'=>['type'=>'integer','required'=>true,'sanitize_callback'=>'absint'],
				'annotations'=>[
					'required'=>true,
					'validate_callback'=>function($val){
						if ( ! is_array($val) ) return false;
						if ( count($val) > 1000 ) return false; // cap segments
						return true;
					}
				],
			],
		]);
	}

    /**
     * PATCH 3 (cont.): REST payload hardening.
     */
    public function handle_save_annotations(\WP_REST_Request $request): \WP_REST_Response {
        $post_id = (int) $request->get_param('postId');
        $annotations = $request->get_param('annotations');

        // Simple per-user rate limit: 1 write / 2s per post
        $key = 'starmus_ann_rl_'.get_current_user_id().'_'.$post_id;
        if ( get_transient($key) ) {
            return new \WP_REST_Response(['success'=>false,'message'=>__('Please wait before saving again.','starmus_audio_recorder')], 429);
        }
        set_transient($key, 1, 2);

        $sanitized = [];
        foreach ( (array)$annotations as $seg ) {
            if ( empty($seg['id']) || !isset($seg['startTime'], $seg['endTime']) ) continue;
            $start = (float) $seg['startTime']; $end = (float) $seg['endTime'];
            if ( $start < 0 || $end <= $start ) continue;
            $label = isset($seg['label']) ? wp_strip_all_tags((string)$seg['label']) : '';
            if ( strlen($label) > 200 ) $label = substr($label,0,200);
            $sanitized[] = ['id'=>sanitize_key($seg['id']),'startTime'=>$start,'endTime'=>$end,'label'=>$label];
        }
        usort($sanitized, fn($a,$b)=> $a['startTime'] <=> $b['startTime']);
        for ($i=1;$i<count($sanitized);$i++){ if ($sanitized[$i]['startTime'] < $sanitized[$i-1]['endTime']) return new \WP_REST_Response(['success'=>false,'message'=>__('Overlapping annotations are not allowed.','starmus_audio_recorder')],400); }

        do_action('starmus_before_annotations_save', $sanitized, $post_id);
        update_post_meta($post_id, 'starmus_annotations_json', wp_json_encode($sanitized, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        do_action('starmus_after_annotations_save', $post_id, $sanitized);

        return new \WP_REST_Response(['success'=>true,'message'=>__('Annotations saved.','starmus_audio_recorder'),'annotations'=>$sanitized],200);
    }
}