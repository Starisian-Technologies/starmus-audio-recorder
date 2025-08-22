<?php
/**
 * Starmus Shortcode Manager
 *
 * Handles rendering all front-end shortcodes for the plugin.
 *
 * @package Starmus\src\includes
 */

namespace Starisian\src\includes;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StarmusShortcodeManager
 */
class StarmusShortcodeManager {

	public function __construct() {
		$this->register_shortcodes();
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_endpoint' ] );
	}

	/**
	 * Registers all shortcodes for the plugin.
	 */
	public function register_shortcodes(): void {
		// The original recorder form
		add_shortcode( 'starmus_audio_recorder', [ $this, 'render_recorder_shortcode' ] );

		// The new shortcode to list a user's recordings
		add_shortcode( 'starmus_my_recordings', [ $this, 'render_my_recordings_shortcode' ] );

		// The new shortcode for the editor page
		add_shortcode( 'starmus_audio_editor', [ $this, 'render_audio_editor_shortcode' ] );
	}

	/**
	 * Renders the audio recorder form via a template file.
	 * (Moved from StarmusSubmissionManager)
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string The HTML content of the form.
	 */
	public function render_recorder_shortcode( $atts = [] ): string {
		$attributes = shortcode_atts(
			[
				'form_id'            => 'starmusAudioForm',
				'submit_button_text' => 'Submit Recording',
			],
			$atts
		);

		ob_start();

		$consent_message    = StarmusAdminSettings::get_option( 'consent_message' );
		$form_action_url    = esc_url( admin_url( 'admin-ajax.php' ) );
		$form_id            = esc_attr( $attributes['form_id'] );
		$submit_button_text = esc_html( $attributes['submit_button_text'] );
		$template_path      = dirname( __DIR__, 2 ) . '/templates/starmus-audio-recorder-ui.php'; // Adjusted path

		if ( file_exists( $template_path ) ) {
			include $template_path;
		} else {
			return '<p>' . esc_html__( 'Error: Audio recorder form template not found.', 'starmus' ) . '</p>';
		}

		return ob_get_clean();
	}

	/**
	 * Renders a list of the current user's audio submissions.
	 *
	 * @return string HTML list of audio files with edit links.
	 */
	public function render_my_recordings_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to view your recordings.', 'starmus' ) . '</p>';
		}

		$current_user_id = get_current_user_id();
		$cpt_slug        = StarmusAdminSettings::get_option( 'cpt_slug' );

		$args = [
			'post_type'      => $cpt_slug,
			'author'         => $current_user_id,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		];

		$query = new \WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return '<p>' . esc_html__( 'You have not submitted any recordings yet.', 'starmus' ) . '</p>';
		}

		ob_start();

		echo '<div class="starmus-my-recordings-list">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$attachment_id = get_post_meta( get_the_ID(), '_audio_attachment_id', true );
			$audio_url     = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
			
			// IMPORTANT: Assumes you have a page with the slug 'edit-audio' for the editor shortcode.
			$edit_page_url = home_url( '/edit-audio/' ); 
			$edit_link     = add_query_arg( 'post_id', get_the_ID(), $edit_page_url );
			
			if ( $audio_url ) {
				?>
				<div class="starmus-recording-item" style="margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
					<h4><?php the_title(); ?></h4>
					<p><em><?php echo get_the_date(); ?></em></p>
					<audio controls src="<?php echo esc_url( $audio_url ); ?>"></audio>
					<p><a href="<?php echo esc_url( $edit_link ); ?>" class="button">Edit Audio</a></p>
				</div>
				<?php
			}
		}
		echo '</div>';

		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Renders the Peaks.js audio editor interface.
	 *
	 * @return string HTML for the editor.
	 */
	public function render_audio_editor_shortcode(): string {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'Invalid submission ID or you do not have permission to edit this item.', 'starmus' ) . '</p></div>';
		}

		// Return the exact HTML structure needed for the JavaScript to initialize.
		return '<div class="wrap">
			<h1>Audio Editor</h1>
			<div id="peaks-container" style="border:1px solid #ddd;padding:8px">
			  <div id="overview" style="height:80px"></div>
			  <div id="zoomview" style="height:160px;margin-top:8px"></div>
			</div>
			<p><button id="play" class="button button-primary">Play/Pause</button>
			   <button id="add-region" class="button">Add Region</button>
			   <button id="save" class="button button-primary">Save Annotations</button></p>
			<div id="regions-list"></div>
		</div>';
	}

	/**
	 * Conditionally enqueues scripts for the shortcodes.
	 */
	public function enqueue_scripts(): void {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		$base_url  = defined('STARMUS_URL') ? STARMUS_URL : plugin_dir_url(dirname(__DIR__,2).'/plugin.php');
		$base_path = defined('STARMUS_PATH') ? STARMUS_PATH : dirname(__DIR__,2) . '/';

		// Enqueue scripts for the RECORDER form
		if ( has_shortcode( $post->post_content, 'starmus_audio_recorder' ) ) {
			wp_enqueue_script('starmus-audio-recorder-module', $base_url.'assets/js/starmus-audio-recorder-module.js', [], filemtime($base_path.'assets/js/starmus-audio-recorder-module.js'), true);
			wp_enqueue_script('starmus-audio-recorder-submissions', $base_url.'assets/js/starmus-audio-recorder-submissions.js', ['starmus-audio-recorder-module'], filemtime($base_path.'assets/js/starmus-audio-recorder-submissions.js'), true);
			wp_localize_script( 'starmus-audio-recorder-submissions', 'starmusFormData', [
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'action'              => 'starmus_submit_audio',
				'nonce'               => wp_create_nonce( 'starmus_submit_audio_action' ),
				'nonce_field'         => 'starmus_audio_nonce_field',
				'recordingTimeLimit'  => StarmusAdminSettings::get_option( 'recording_time_limit' ),
			]);
			wp_enqueue_style('starmus-audio-recorder-style', $base_url.'assets/css/starmus-audio-recorder-style.css', [], filemtime($base_path.'assets/css/starmus-audio-recorder-style.css'));
		}

		// Enqueue scripts for the EDITOR
		if ( has_shortcode( $post->post_content, 'starmus_audio_editor' ) ) {
			$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) return;

			$attachment_id = get_post_meta( $post_id, '_audio_attachment_id', true );
			$audio_url = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
			$annotations = get_post_meta( $post_id, 'starmus_annotations_json', true ) ?: '[]';

			wp_enqueue_script('peaks', 'https://unpkg.com/peaks.js/dist/peaks.min.js', [], null, true);
			wp_enqueue_script('starmus-audio-editor', $base_url.'assets/js/starmus-audio-editor.js', ['peaks','jquery'], filemtime($base_path.'assets/js/starmus-audio-editor.js'), true);

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
 *
 * This function is now "merge-aware" to support chunked saving from the client.
 * It also returns the final saved annotations to allow the client to sync IDs.
 *
 * @param \WP_REST_Request $request The REST request object.
 * @return \WP_REST_Response
 */
public function handle_save_annotations( \WP_REST_Request $request ): \WP_REST_Response {
    $post_id     = $request->get_param( 'postId' );
    $new_annotations = $request->get_param( 'annotations' );

    // Step 1: Get existing annotations from the database.
    $existing_annotations_json = get_post_meta( $post_id, 'starmus_annotations_json', true );
    $existing_annotations = ! empty( $existing_annotations_json ) ? json_decode( $existing_annotations_json, true ) : [];
    if (!is_array($existing_annotations)) {
        $existing_annotations = [];
    }

    // Step 2: Merge new annotations with existing ones.
    // We'll use the 'id' field as the unique key to prevent duplicates and handle updates.
    $merged_annotations = [];
    $all_annotations = array_merge($existing_annotations, $new_annotations);
    $seen_ids = [];

    // Process in reverse to ensure the newest annotation data for an ID is kept.
    foreach (array_reverse($all_annotations) as $annotation) {
        if (isset($annotation['id']) && !in_array($annotation['id'], $seen_ids, true)) {
            // Add to the front of the array to maintain rough order
            array_unshift($merged_annotations, $annotation);
            $seen_ids[] = $annotation['id'];
        }
    }

    // Step 3: Deeply sanitize the final, merged array.
    $sanitized_annotations = [];
    foreach ( $merged_annotations as $segment ) {
        if (empty($segment['id']) || !isset($segment['startTime']) || !isset($segment['endTime'])) {
            continue; // Skip invalid entries
        }
        $sanitized_annotations[] = [
            'id'        => sanitize_text_field( $segment['id'] ),
            'startTime' => (float) $segment['startTime'],
            'endTime'   => (float) $segment['endTime'],
            'label'     => isset($segment['label']) ? sanitize_text_field( $segment['label'] ) : '',
        ];
    }
    
    // Optional but recommended: Sort the final array by startTime
    usort($sanitized_annotations, function($a, $b) {
        return $a['startTime'] <=> $b['startTime'];
    });

		// Step 4: Reject overlaps (after sorting)
		for ($i=1;$i<count($sanitized_annotations);$i++){
			if ($sanitized_annotations[$i]['startTime'] < $sanitized_annotations[$i-1]['endTime']){
				return new \WP_REST_Response(['success'=>false,'message'=>'Overlapping annotations are not allowed.'],400);
			}
		}

		// Step 5: Save the complete, sanitized data back to the post meta.
		update_post_meta( $post_id, 'starmus_annotations_json', wp_json_encode( $sanitized_annotations ) );

		// Step 6: Return a success response that INCLUDES the final data for client-side syncing.
		return new \WP_REST_Response( [
				'success'     => true,
				'message'     => 'Annotations saved.',
				'annotations' => $sanitized_annotations,
		], 200 );
}

}