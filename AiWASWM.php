<?php
final class AiWASWM {

    private static $instance = null;

    public $post_types;
    public $workflow;
    public $frontend_ui;
    public $cron;
    public $settings;
    public $starmus;
    public ?AiWASWMForminatorIntegration $forminator = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_classes();
        $this->register_hooks();
    }

    private function register_hooks(): void {
        add_action( 'forminator_form_after_save_entry', [ $this->get_Forminator(), 'handle_forminator_consent_submission' ], 10, 2 );
    }

    private function load_dependencies(): void {
        // Core Classes
        require_once AIWA_SWM_PATH . 'includes/class-post-types.php';
        require_once AIWA_SWM_PATH . 'includes/class-workflow-manager.php';
        require_once AIWA_SWM_PATH . 'includes/class-frontend-ui.php';
        require_once AIWA_SWM_PATH . 'includes/class-cron-manager.php';

        // Admin Classes
        require_once AIWA_SWM_PATH . 'includes/admin/class-admin-settings.php';
        
        // Integration Classes
        require_once AIWA_SWM_PATH . 'includes/integrations/class-integration-starmus.php';
        require_once AIWA_SWM_PATH . 'includes/integrations/class-integration-forminator.php';
    }

    private function init_classes(): void {
        // Note: The order of instantiation can be important.
        $this->post_types = new AiWASWMPostTypes();
        $this->workflow   = new AiWASWMWorkflowManager();
        $this->frontend_ui= new AiWASWMFrontendUI();
        $this->cron       = new AiWASWMCronManager();
        
        if ( is_admin() ) {
            $this->settings = new AiWASWMAdminSettings();
        }
        
        // Only load integrations if their respective plugins are active
        if ( class_exists( 'Starmus_Audio_Recorder' ) ) { // Check for a main Starmus class
            $this->starmus = new AiWASWMStarmusIntegration();
        }
        if ( class_exists( 'Forminator' ) ) { // Check for a main Forminator class
            $this->forminator = new AiWASWMForminatorIntegration();
        }
    }

    public function get_Forminator(): ?AiWASWMForminatorIntegration {
        return $this->forminator;
    }
}