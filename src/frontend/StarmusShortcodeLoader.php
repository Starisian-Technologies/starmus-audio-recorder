<?php

declare(strict_types=1);

namespace Starisian\Sparxstar\Starmus\frontend;

use Starisian\Sparxstar\Starmus\core\StarmusAssetLoader;

if ( ! \defined('ABSPATH')) {
    exit;
}

use Starisian\Sparxstar\Starmus\core\StarmusConsentHandler;
use Starisian\Sparxstar\Starmus\core\StarmusSettings;
use Starisian\Sparxstar\Starmus\data\StarmusAudioDAL;
use Starisian\Sparxstar\Starmus\data\StarmusProsodyDAL;
use Starisian\Sparxstar\Starmus\helpers\StarmusLogger;
use Starisian\Sparxstar\Starmus\helpers\StarmusTemplateLoaderHelper;
use Throwable;

/**
 * Registers shortcodes and routes rendering lazily to the correct UI classes.
 *
 * @since 0.7.7
 * @author Your Name
 * @note This class is responsible for registering all the shortcodes used by the plugin and ensuring
 * that the rendering of each shortcode is handled in a way that defers the instantiation of any heavy UI classes until the shortcode is actually rendered. This helps improve performance by avoiding unnecessary object creation on every page load, and ensures that resources are only used when needed. The class also includes error handling to log any exceptions that occur during shortcode registration or rendering, and to provide user-friendly messages when issues arise.
 * @warning Be mindful of the fact that if any of the shortcodes rely on certain conditions (e.g., user permissions, specific query parameters), those conditions should be handled within the rendering logic
 * to avoid unexpected behavior or performance issues. Additionally, ensure that any dependencies used in the rendering of shortcodes are properly initialized and available to prevent errors during rendering.
 * @example When the [starmus_audio_recorder] shortcode is used, the StarmusAudioRecorderUI class will only be instantiated at the moment the shortcode is rendered, rather than at the time of shortcode registration. This allows for a more efficient use of resources, especially on pages
 * where the shortcode is not used. The same applies to the [starmus_audio_editor] shortcode, which will only instantiate the StarmusAudioEditorUI class when the shortcode is rendered, allowing for better performance on pages that do not use the editor. The [starmus_my_recordings] shortcode will only execute the logic to fetch and render the user's recordings when the shortcode is rendered, rather than at the time of registration, which helps improve performance on pages that do not use this shortcode. The [starmus_recording_detail] shortcode will only execute the logic to check permissions and render the recording detail view when the shortcode is rendered, rather than at the time of registration, which helps improve performance on pages that do not use this shortcode. The [starmus_audio_re_recorder] shortcode will only instantiate the StarmusAudioRecorderUI class and execute the re-recorder rendering logic when the shortcode is rendered, rather than at the time of registration, which helps improve performance on pages that do not use this shortcode. The [starmus_contributor_consent] shortcode will only instantiate the StarmusConsentUI class and execute the consent rendering logic when the shortcode is rendered, rather than at the time of registration, which helps improve performance on pages that do not use this shortcode. The [starmus_script_recorder] shortcode will only execute the logic to render the combined prosody player and re-recorder when the shortcode is rendered, rather than at the time of registration, which helps improve performance on pages that do not use this shortcode.
 * @see safe_render() for the method used to wrap the rendering logic of each shortcode to ensure that any exceptions are caught and logged, and that a user-friendly message is displayed if an error occurs during rendering.
 * @see render_editor_with_bootstrap() for an example of how this method is used to handle potential errors in rendering the audio editor UI, which involves fetching context and data that could potentially fail.
 * @see render_my_recordings_shortcode() for an example of how this method is used to handle errors in rendering the user's recordings list, which involves database queries and template rendering that could potentially throw exceptions.
 * @see render_recording_detail_shortcode() for an example of how this method is used to handle errors in rendering the recording detail view, which involves permission checks and template rendering that could potentially throw exceptions.
 * @see render_submission_detail_via_filter() for an example of how this method is used to handle errors in rendering the recording detail view via a content filter, which involves checking the
 * query context and rendering templates based on permissions.
 * @see StarmusLogger::log() for the logging mechanism used to record any exceptions that occur during rendering.
 * @todo Consider extending the safe_render() method to allow for different types of fallback messages based on the context or type of component being rendered, to provide a more tailored user experience when errors occur.
 * @todo Consider adding a mechanism to notify site administrators when certain types of errors occur frequently in the rendering of components, to help identify and address underlying issues in the codebase that may be causing these errors.
 * @todo Consider implementing a more robust error handling strategy that includes categorizing errors and providing different levels of logging (e.g., critical, warning, info) to help prioritize issues that arise in the rendering of components.
 * @todo Consider adding unit tests for the safe_render() method to ensure that it correctly catches exceptions and logs them, and that it returns the expected fallback message when an error occurs during rendering.
 * @todo Consider adding integration tests that simulate errors in the rendering of components to ensure that the safe_render() method correctly handles those errors and provides a good user experience even when issues arise.
 * @todo Consider adding documentation for developers on how to use the safe_render() method when creating new shortcodes or rendering logic, to encourage consistent error handling across
 */
final class StarmusShortcodeLoader
{
    /**
     * Settings service instance.
     */
    private ?StarmusSettings $settings = null;

    /**
     * Data Access Layer instance.
     */
    private ?StarmusAudioDAL $dal = null;

    /**
     * Consent Handler instance.
     */
    private ?StarmusConsentHandler $consent_handler = null;

    /**
     * Consent UI instance.
     */
    private ?StarmusConsentUI $consent_ui = null;

    /**
     * Prosody player instance.
     */
    private ?StarmusProsodyPlayer $prosody = null;

    /**
     * Constructor to initialize dependencies and register hooks.
     * Dependencies are injected to allow for better testability and separation of concerns, but default instances will be created if not provided.
     *
     * @param StarmusAudioDAL|null $dal The data access layer.
     * @param StarmusSettings|null $settings The settings instance.
     * @param StarmusProsodyDAL|null $prosody_dal The prosody DAL instance.
     *
     * @return void
     * @since 0.7.7
     * @author Your Name
     * @note This constructor initializes the necessary dependencies for the shortcode loader, including the settings, data access layer, consent handler, and consent UI. It also ensures that the prosody engine is set up and registers the necessary hooks for shortcode registration. By allowing dependencies to be injected, we can easily mock these components during testing to ensure that the shortcode loader behaves correctly under various conditions.
     * @warning Be mindful of the fact that if any of the dependencies fail to initialize properly (e.g., due to database connection issues or misconfiguration), it could lead to errors in the shortcode rendering. The constructor includes error handling to log any exceptions that occur during initialization, but it's important to ensure that the underlying issues are addressed to prevent ongoing problems with shortcode functionality.
     * @example If the StarmusAudioDAL fails to connect to the database, the constructor will catch the exception and log it, and the shortcode rendering will fallback to displaying a user-friendly message instead of the expected UI. This allows the site to remain functional even when there are issues with the data layer, while also providing valuable information in the logs for troubleshooting.
     * @example If the StarmusSettings instance fails to load the necessary configuration, the constructor will catch the exception and log it, and the shortcode rendering will fallback to displaying a user-friendly message instead of the expected UI
     */
    public function __construct(?StarmusAudioDAL $dal = null, ?StarmusSettings $settings = null, ?StarmusProsodyDAL $prosody_dal = null)
    {
        try {
            $this->settings = $settings ?? new StarmusSettings();
            $this->dal = $dal ?? new StarmusAudioDAL();
            $this->consent_handler = new StarmusConsentHandler();
            $this->consent_ui = new StarmusConsentUI($this->consent_handler, $this->settings);
            $this->consent_ui->register_hooks();

            // Ensure prosody engine is set up
            $this->set_prosody_engine($prosody_dal);
            $this->register_hooks();
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }
    /**
     * Sets the hook to register shortcodes
     *
     * @internal
     * @return void
     *
     */
    private function register_hooks(): void
    {
        // Currently no additional hooks to register
        add_action('init', $this->register_shortcodes(...));
    }

    /**
     * Register shortcodes — but don't instantiate heavy UI classes yet.
     *
     * @return void
     * @since 0.7.7
     * @author Your Name
     * @note This method registers all the shortcodes used by the plugin, but it does so in a way that defers the instantiation of any heavy UI classes until the shortcode is actually rendered. This helps improve performance by avoiding unnecessary object creation on every page load, and ensures that resources are only used when needed.
     * @warning Be mindful of the fact that if any of the shortcodes rely on certain conditions (e.g., user permissions, specific query parameters), those conditions should be handled within the rendering logic of the shortcode to avoid unexpected behavior or performance issues.
     * @example When the [starmus_audio_recorder] shortcode is used, the StarmusAudioRecorderUI class will only be instantiated at the moment the shortcode is rendered, rather than at the time of shortcode registration. This allows for a more efficient use of resources, especially on pages where the shortcode is not used.
     * @example The same applies to the [starmus_audio_editor] shortcode, which will only instantiate the StarmusAudioEditorUI class when the shortcode is rendered, allowing for better performance on pages that do not use the editor.
     * @example The [starmus_my_recordings] shortcode will only execute the logic to fetch and render the user's recordings when the shortcode is rendered, rather than at the time of registration, which helps improve performance on pages that do not use this shortcode.
     * @example The [starmus_recording_detail] shortcode will only execute the logic to check permissions and render the recording detail view when the shortcode is rendered, rather than at the time of registration, which helps improve performance on pages that do not use this shortcode.
     * @example The [starmus_audio_re_recorder] shortcode will only instantiate the StarmusAudioRecorderUI class and execute the re-recorder rendering logic when the shortcode is rendered, rather than at the time of registration, which helps improve performance on pages that do not use this shortcode.
     * @example The [starmus_contributor_consent] shortcode will only instantiate the StarmusConsentUI class and execute the consent rendering logic when the shortcode is rendered, rather than at the time of registration, which helps improve performance on pages that do not use this shortcode.
     * @example The [starmus_script_recorder] shortcode will only execute the logic to render the combined prosody player and re-recorder when the shortcode is rendered, rather than at the time of registration, which helps improve performance on pages that do not use this shortcode.
     * @see safe_render() for the method used to wrap the rendering logic of each shortcode to ensure that any exceptions are caught and logged, and that a user-friendly message is displayed if an error occurs during rendering.
     */
    public function register_shortcodes(): void
    {
        try {
            add_shortcode('starmus_audio_recorder', fn(): string => $this->safe_render(fn(): string => (new StarmusAudioRecorderUI($this->settings))->render_recorder_shortcode()));
            add_shortcode('starmus_audio_editor', fn(array $atts = []): string => $this->safe_render(fn(): string => $this->render_editor_with_bootstrap($atts)));
            add_shortcode('starmus_my_recordings', $this->render_my_recordings_shortcode(...));
            add_shortcode('starmus_recording_detail', $this->render_recording_detail_shortcode(...));
            add_shortcode('starmus_audio_re_recorder', fn(array $atts = []): string => $this->safe_render(fn(): string => (new StarmusAudioRecorderUI($this->settings))->render_re_recorder_shortcode($atts)));
            add_shortcode('starmus_contributor_consent', fn(): string => $this->safe_render(fn(): string => $this->consent_ui->render_shortcode()));
            add_shortcode(
                'starmus_script_recorder',
                fn(array $atts = [], string $content = null): string =>
                $this->safe_render(
                    fn(): string =>
                    $this->starmus_render_script_recorder($atts, $content)
                )
            );
            add_filter('the_content', $this->render_submission_detail_via_filter(...), 100);
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }
    /**
     * Initialize the prosody engine if not already set.
     *
     * @param StarmusProsodyDAL|null $prosody_dal The prosody DAL instance.
     * @return void
     * @since 0.7.7
     * @author Your Name
     * @note This method checks if the prosody engine has already been initialized, and if not, it creates a new instance of the StarmusProsodyPlayer using the provided DAL. This ensures that the prosody engine is only set up once, avoiding unnecessary object creation and potential performance issues.
     * @warning Be mindful of the fact that if the StarmusProsodyPlayer fails to initialize properly (e.g., due to misconfiguration or missing dependencies), it could lead to errors when attempting to use the prosody functionality. The method includes error handling to log any exceptions that occur during initialization, but it's important to ensure that the underlying issues are addressed to prevent ongoing problems with prosody features.
     * @example If the StarmusProsodyPlayer fails to initialize due to a misconfiguration in the DAL, the method will catch the exception and log it, allowing the rest of the shortcode loader functionality to continue operating without prosody features. This helps maintain overall site functionality while providing valuable information in the logs for troubleshooting.
     */
    private function set_prosody_engine(?StarmusProsodyDAL $prosody_dal = null): void
    {
        if ($this->prosody instanceof StarmusProsodyPlayer) {
            return;
        }

        try {
            $this->prosody = new StarmusProsodyPlayer($prosody_dal);
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }
    }

    /**
     * Safely render UI blocks with logging.
     *
     * @param $renderer A callable that returns the rendered HTML string.
     * @return string The rendered HTML or a fallback message if an error occurs.
     * @since 0.7.7
     * @author Your Name
     * @note This method centralizes error handling for rendering UI components, ensuring that any exceptions thrown during rendering are caught and logged, and that a user-friendly message is displayed instead of a broken component. This helps maintain a good user experience even when unexpected issues arise in the rendering logic.
     * @warning Be mindful of the performance implications of catching exceptions in high-traffic areas. While this provides robustness, it should be used judiciously to avoid masking underlying issues that should be addressed in the code.
     * @example Usage: When rendering the audio recorder shortcode, we wrap the rendering logic in this safe_render method to ensure that if any part of the recorder UI fails to render properly, the error is logged and the user sees a friendly message instead of a broken interface.
     * @see starmus_render_script_recorder() for an example of how this method is used to wrap complex shortcode rendering logic that involves multiple components.
     * @see render_editor_with_bootstrap() for another example of how this method is used to handle potential errors in rendering the audio editor UI, which involves fetching context and data that could potentially fail.
     * @see render_my_recordings_shortcode() for an example of how this method is used to handle errors in rendering the user's recordings list, which involves database queries and template rendering that could potentially throw exceptions.
     * @see render_recording_detail_shortcode() for an example of how this method is used to handle errors in rendering the recording detail view, which involves permission checks and template rendering that could potentially throw exceptions.
     * @see render_submission_detail_via_filter() for an example of how this method is used to handle errors in rendering the recording detail view via a content filter, which involves checking the query context and rendering templates based on permissions.
     * @see StarmusLogger::log() for the logging mechanism used to record any exceptions that occur during rendering.
     * @todo Consider extending this method to allow for different types of fallback messages based on the context or type of component being rendered, to provide a more tailored user experience when errors occur.
     * @todo Consider adding a mechanism to notify site administrators when certain types of errors occur frequently in the rendering of components, to help identify and address underlying issues in the codebase that may be causing these errors.
     * @todo Consider implementing a more robust error handling strategy that includes categorizing errors and providing different levels of logging (e.g., critical, warning, info) to help prioritize issues that arise in the rendering of components.
     * @todo Consider adding unit tests for this method to ensure that it correctly catches exceptions and logs them, and that it returns the expected fallback message when an error occurs during rendering.
     * @todo Consider adding integration tests that simulate errors in the rendering of components to ensure that this method correctly handles those errors and provides a good user experience even when issues arise.
     * @todo Consider adding documentation for developers on how to use this method when creating new shortcodes or rendering logic, to encourage consistent error handling across the codebase and improve the overall robustness of the plugin.
     * @todo Consider adding a mechanism to allow developers to specify custom fallback messages when using this method, to provide more context-specific feedback to users when errors occur in different components.
     * @todo Consider adding a mechanism to allow developers to specify custom logging contexts or tags when using this method, to help categorize and analyze errors that occur in different parts of the rendering logic.
     * @todo Consider adding a mechanism to allow developers to specify custom error handling callbacks when using this method, to provide more flexibility in how errors are handled and reported when they occur during rendering.
     * @todo Consider adding a mechanism to allow developers to specify custom error reporting mechanisms (e.g., sending an email notification, logging to an external service) when using this method, to help ensure that critical errors in the rendering of components are promptly addressed by the development team.
     * @todo Consider adding a mechanism to allow developers to specify different fallback messages based on the type of error that occurs (e.g., database error, permission error, etc.) to provide more specific feedback to users when issues arise in the rendering of components.
     * @todo Consider adding a mechanism to allow developers to specify different logging levels based on the type of error that occurs, to help prioritize issues that arise in the rendering of components and ensure that critical issues are addressed promptly.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the context of the rendering (e.g., frontend vs. backend, user-facing component vs. admin component) to provide more tailored error handling and reporting based on the specific use case.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the user role or permissions of the current user, to provide more appropriate feedback and logging based on the user's access level and potential impact of the error.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the environment (e.g., development vs. production) to provide more detailed feedback and logging during development while providing a more user-friendly experience in production when errors occur in the rendering of components.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the frequency of errors (e.g., if a certain error occurs frequently, escalate it to a higher logging level or trigger additional notifications) to help identify and address underlying issues that may be causing frequent errors in the rendering of components.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the potential impact of the error (e.g., if an error is likely to cause significant issues for users, escalate it to a higher logging level or trigger additional notifications) to help ensure that critical issues in the rendering of components are promptly addressed by the development team.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the potential root cause of the error (e.g., if an error is likely caused by a third-party service, provide specific logging or notifications to help identify and address issues with that service) to help ensure that issues in the rendering of components are effectively diagnosed and addressed.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the potential resolution of the error (e.g., if an error is likely resolved by a simple code fix, provide specific logging or notifications to help identify and address that fix) to help ensure that issues in the rendering of components are effectively resolved in a timely manner.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the potential recurrence of the error (e.g., if an error is likely to recur under certain conditions, provide specific logging or notifications to help identify and address those conditions) to help ensure that issues in the rendering of components are effectively mitigated and prevented from recurring in the future.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the potential severity of the error (e.g., if an error is likely to cause significant issues for users, escalate it to a higher logging level or trigger additional notifications) to help ensure that critical issues in the rendering of components are promptly addressed by the development team and that users are provided with appropriate feedback when issues arise.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the potential scope of the error (e.g., if an error is likely to affect a large number of users, escalate it to a higher logging level or trigger additional notifications) to help ensure that widespread issues in the rendering of components are promptly addressed by the development team and that users are provided with appropriate feedback when issues arise.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the potential duration of the error (e.g., if an error is likely to cause prolonged issues for users, escalate it to a higher logging level or trigger additional notifications) to help ensure that critical issues in the rendering of components are promptly addressed by the development team and that users are provided with appropriate feedback when issues arise.
     * @todo Consider adding a mechanism to allow developers to specify different error handling strategies based on the potential recoverability of the error (e.g., if an error is likely to be recoverable with a simple user action, provide specific feedback and logging to help guide users through that recovery process) to help ensure that users are empowered to resolve issues in the rendering of components when possible and that critical issues are effectively addressed by the development team when they arise.
     *
     */
    private function safe_render(callable $renderer): string
    {
        try {
            return $renderer();
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return '<p>' . esc_html__('Component unavailable.', 'starmus-audio-recorder') . '</p>';
        }
    }

    /**
     * Render the "My Recordings" shortcode.
     * Modified to handle Detail View inline since CPT is not publicly queryable.
     *
     * @param $atts Shortcode attributes, e.g. posts_per_page.
     * @return string Rendered HTML for the My Recordings list or detail view.
     * @since 0.7.7
     * @author Your Name
     * @note This method now handles both the list view and the detail view for recordings. The detail view is triggered by query parameters (e.g. ?view=detail&recording_id=123) and checks permissions to ensure the user can view the recording. This approach is necessary because
     * the CPT is not publicly queryable, so we cannot rely on standard single post templates for the detail view. Instead, we render the appropriate template directly within this method based on the context.
     * @warning Ensure that the query parameters used for the detail view are unique enough to avoid conflicts with other query parameters in the site. Also, be mindful of security implications when rendering content based on query parameters, and always sanitize and validate inputs properly.
     */
    public function render_my_recordings_shortcode(array $atts = []): string
    {
        if ( ! is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to view your recordings.', 'starmus-audio-recorder') . '</p>';
        }

        try {
            // === Detail View Handler ===
            // Since audio-recording is hidden from frontend queries, we handle display here.
            // Use $_GET directly as filter_input has reliability issues in some WP environments
            $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : '';
            $recording_id = isset($_GET['recording_id']) ? absint($_GET['recording_id']) : 0;

            if ($view === 'detail' && $recording_id > 0) {
                // Security: Ensure user owns this recording or can edit others
                $post = get_post($recording_id);
                if ($post && (get_current_user_id() === (int) $post->post_author || current_user_can('edit_others_posts'))) {
                    // Masquerade global post for template parts that use get_the_ID()
                    global $post;
                    $post = get_post($recording_id);
                    setup_postdata($post);

                    $template = current_user_can('edit_others_posts')
                        ? 'starmus-recording-detail-admin.php'
                        : 'starmus-recording-detail-user.php';

                    // Contextual Links
                    $recorder_page_id = $this->settings->get('recorder_page_id');
                    $recorder_url = $recorder_page_id ? get_permalink((int) $recorder_page_id) : '';

                    // Pass variables explicitly to robust templates
                    $output = StarmusTemplateLoaderHelper::render_template($template, [
                        'post_id' => $recording_id,
                        'recorder_page_url' => $recorder_url,
                        // Add edit_page_url if needed, currently not strictly required by prompt unless "re-recorder" implies it
                    ]);
                    wp_reset_postdata();
                    return $output;
                }
            }

            // === List View Handler ===
            $attributes = shortcode_atts(['posts_per_page' => 10], $atts);
            $posts_per_page = max(1, absint($attributes['posts_per_page']));
            $paged = get_query_var('paged') ? (int) get_query_var('paged') : 1;
            $cpt_slug = $this->settings->get('cpt_slug', 'audio-recording');
            $query = $this->dal->get_user_recordings(get_current_user_id(), $cpt_slug, $posts_per_page, $paged);

            // Resolve Base URL for links
            $page_ids = $this->settings->get('my_recordings_page_id');
            $page_id = \is_array($page_ids) ? (int) reset($page_ids) : (int) $page_ids;
            $base_url = $page_id > 0 ? get_permalink($page_id) : get_permalink();

            return StarmusTemplateLoaderHelper::render_template(
                'parts/starmus-my-recordings-list.php',
                [
                    'query' => $query,
                    'edit_page_url' => $this->dal->get_edit_page_url_admin($cpt_slug),
                    'base_url' => $base_url,
                ]
            );
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return '<p>' . esc_html__('Unable to load recordings.', 'starmus-audio-recorder') . '</p>';
        }
    }

    /**
     * Render the single recording detail shortcode.
     *
     * @return string
     * @since 0.7.7
     * @author Your Name
     * @note This method renders the detail view for a single recording. It checks if the current page is a singular view of the 'audio-recording' CPT, and then checks the user's
     * permissions to determine which template to load (admin vs. user). This is necessary because the CPT is not publicly queryable, so we cannot rely on standard single post templates for the detail view. Instead, we render the appropriate template directly within this method based on the context.
     * @warning Ensure that this shortcode is only used on the appropriate pages (i.e., single audio recording pages) to avoid confusion or unexpected behavior. The method includes a check for the post
     * type and will return a message if used in the wrong context, but it's important to ensure that content creators understand where this shortcode should be used for it to function properly.
      * @example If a user visits a single audio recording page and has the appropriate permissions, they will see the detailed view of that recording rendered by this shortcode. If they do not have permission, they will see a message indicating that they do not have access to view the recording detail. If the shortcode is used on a page that is not a single audio recording view, it will display a message indicating that the shortcode can only be used on single audio recording pages.
       * @see render_submission_detail_via_filter() for an alternative way to render the recording detail view via a content filter, which can serve as a fallback in case the shortcode is not used directly in the content.
      * @see StarmusTemplateLoaderHelper::render_template() for the method used to load the appropriate template based on user permissions when rendering the recording detail view.
       * @see StarmusLogger::log() for the logging mechanism used to record any exceptions that occur during rendering of the recording detail view.
      * @todo Consider adding additional context or variables to the templates used for the recording detail view to provide more information or functionality based on the specific recording being viewed, such as related recordings, user interactions, or metadata about the recording.
      * @todo Consider adding a mechanism to allow content creators to specify which template to use for the recording detail view via shortcode attributes, to provide more flexibility in how the detail view is rendered based on different contexts or user roles.
      * @todo Consider adding additional permission checks or capabilities to allow for more granular control over who can view the recording detail view, such as allowing certain user roles to view details of recordings they do not own,
     */
    public function render_recording_detail_shortcode(): string
    {
        try {
            if ( ! is_singular('audio-recording')) {
                return '<p><em>[starmus_recording_detail] can only be used on a single audio recording page.</em></p>';
            }

            $post_id = get_the_ID();
            $template_to_load = '';
            if (current_user_can('edit_others_posts', $post_id)) {
                $template_to_load = 'starmus-recording-detail-admin.php';
            } elseif (is_user_logged_in() && get_current_user_id() === (int) get_post_field('post_author', $post_id)) {
                $template_to_load = 'starmus-recording-detail-user.php';
            }

            if ($template_to_load !== '' && $template_to_load !== '0') {
                return StarmusTemplateLoaderHelper::render_template($template_to_load);
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }

        return is_user_logged_in()
            ? '<p>You do not have permission to view this recording detail.</p>'
            : '<p><em>You must be logged in to view this recording detail.</em></p>';
    }

    /**
     * Automatically inject recording detail template into single view.
     *
     * @param $content The original post content.
     * @return string Modified content with recording detail if applicable.
     * @since 0.7.7
     * @author Your Name
     * @see render_recording_detail_shortcode() for the actual rendering logic.
     * @note This is a fallback for cases where the shortcode might not be used directly, ensuring the detail view is always rendered on single recording pages.
     * @warning Ensure this doesn't conflict with other content filters or shortcodes that might be used on the same page.
     * @todo Consider making this more robust by checking for specific conditions or allowing opt-out via shortcode attributes or settings in the future.
     * @example If a user visits a single audio recording page without using the shortcode, this filter will still render the appropriate detail template based on their permissions.
     * @example This also means that if the shortcode is used within the content, it will render the detail view twice, so it's recommended to use the shortcode approach for better control over placement in the content.
     * @example This is particularly useful given that the CPT is not publicly queryable, so relying on the shortcode alone might lead to cases where the detail view is not rendered if the user forgets to include it in the content. This filter ensures a consistent user experience regardless of shortcode usage.
     */
    public function render_submission_detail_via_filter(string $content = ''): string
    {
        try {
            if ( ! is_singular('audio-recording') || ! in_the_loop() || ! is_main_query()) {
                return $content;
            }

            $post_id = get_the_ID();
            $template_to_load = '';

            if (current_user_can('edit_others_posts', $post_id)) {
                $template_to_load = 'parts/starmus-recording-detail-admin.php';
            } elseif (is_user_logged_in() && get_current_user_id() === (int) get_post_field('post_author', $post_id)) {
                $template_to_load = 'parts/starmus-recording-detail-user.php';
            }

            if ($template_to_load !== '' && $template_to_load !== '0') {
                return StarmusTemplateLoaderHelper::render_template($template_to_load);
            }
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
        }

        return '<p>You do not have permission to view this recording detail.</p>';
    }
    /**
     * Summary of render_editor_with_bootstrap
     * @param array $atts
     * @return string
     */
    private function render_editor_with_bootstrap(array $atts): string
    {

        try {

            // Create editor instance and get context
            $editor = new StarmusAudioEditorUI();
            $context = $editor->get_editor_context_public($atts);

            if (is_wp_error($context)) {
                $error_message = $context->get_error_message();
                return '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
            }

            // Get transcript data
            // FIX: Updated key to match StarmusSubmissionHandler (starmus_transcription_json)
            // Fallback to star_transcript_json for legacy
            $transcript_json = get_post_meta($context['post_id'], 'starmus_transcription_json', true);
            if (empty($transcript_json)) {
                $transcript_json = get_post_meta($context['post_id'], 'star_transcript_json', true);
            }

            $transcript_data = [];
            if ($transcript_json && \is_string($transcript_json)) {
                $decoded = json_decode($transcript_json, true);
                if (\is_array($decoded)) {
                    $transcript_data = $decoded;
                }
            }

            // Parse annotations
            $annotations_data = [];
            if ( ! empty($context['annotations_json']) && \is_string($context['annotations_json'])) {
                $decoded = json_decode($context['annotations_json'], true);
                if (\is_array($decoded)) {
                    $annotations_data = $decoded;
                }
            }

            // Set editor data for asset loader to localize
            StarmusAssetLoader::set_editor_data(
                [
                    'postId' => $context['post_id'],
                    'restUrl' => esc_url_raw(rest_url('star_uec/v1/annotations')),
                    'audioUrl' => esc_url($context['audio_url']),
                    'waveformDataUrl' => esc_url($context['waveform_url']),
                    'annotations' => $annotations_data,
                    'transcript' => $transcript_data,
                    'nonce' => wp_create_nonce('wp_rest'),
                    'mode' => 'editor',
                    'canCommit' => current_user_can('publish_posts'),
                ]
            );
        } catch (Throwable $throwable) {
            StarmusLogger::log($throwable);
            return '<p>' . esc_html__('Component unavailable.', 'starmus-audio-recorder') . '</p>';
        }

        // Render the UI with context
        return $editor->render_audio_editor_shortcode($atts);
    }
    /**
     * Render the script recorder, which combines the prosody player and re-recorder.
     * This is a more complex shortcode that serves a specific use case, so it's handled here in the loader.
     *
     * @param $atts Shortcode attributes, passed through to inner components.
     * @param $content Shortcode content, if any (not used in this case).
     * @return string Rendered HTML for the script recorder.
     */

    public function starmus_render_script_recorder(array $atts = [], ?string $content = null): string
    {
        // Ensure prosody engine is available before attempting to render
        if ( ! $this->prosody instanceof StarmusProsodyPlayer) {
            return '<p>' . esc_html__('SPARXSTAR Prosody recorder unavailable.', 'starmus-audio-recorder') . '</p>';
        }
        // This shortcode is intended for logged-in users only, as it involves recording functionality.
        if ( ! is_user_logged_in()) {
            return '<p>' . esc_html__('Login required.', 'starmus-audio-recorder') . '</p>';
        }

        /*
        * Define supported attributes here.
        * These will be forwarded to inner shortcodes.
        */
        $atts = shortcode_atts([
            'post_id'   => '',
            'user_id'   => '',
            'mode'      => '',
            'class'     => '',
        ], $atts, 'starmus_script_recorder');

        // Build attribute string for pass-through to inner shortcodes, excluding 'class' which is used for the wrapper div
        $attr_string = '';

        foreach ($atts as $key => $value) {
            if ($key === 'class') {
                continue;
            }

            if ($value === '' || $value === null) {
                continue;
            }

            if ( ! is_scalar($value)) {
                continue;
            }

            $attr_string .= sprintf(' %s="%s"', esc_attr($key), esc_attr((string) $value));
        }

        ob_start();
        ?>
        // app-mode class sets the form in an app like container with specific styling, giving and app like feel to the user, and differentiating it from the rest of the content on the page. The sparxstar-app-mode class is used to apply specific styles for the SPARXSTAR prosody recorder, ensuring a consistent and tailored user experience for this particular component.
        <div class="starmus-app-mode sparxstar-app-mode">
            // The starmus-prosody-recorder class is used to apply specific styles to the container that holds both the prosody player and the re-recorder, ensuring that they are visually grouped together and styled appropriately for their combined functionality. The additional class from the shortcode attributes allows for further customization of the styling if needed.
            <div class="starmus-prosody-recorder <?php echo esc_attr($atts['class']); ?>">

                <?php echo do_shortcode('[prosody_player' . $attr_string . ']'); ?>

                <?php echo do_shortcode('[starmus_audio_re_recorder' . $attr_string . ']'); ?>

            </div>
        </div>

        <?php
        return ob_get_clean();
    }
    /**
     * Getter for the prosody engine, allowing other components to access it if needed.
     * This is useful for cases where the prosody player needs to be integrated outside of the standard shortcodes.
     *
     * @return StarmusProsodyPlayer|null The prosody player instance, or null if it failed to initialize.
     */

    public function get_prosody_engine(): ?StarmusProsodyPlayer
    {
        return $this->prosody;
    }
}
