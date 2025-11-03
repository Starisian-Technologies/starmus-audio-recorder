<?php
/**
 * AIWA Starmus Trigger - Template File
 *
 * This template renders the frontend for the [aiwa_starmus_trigger] shortcode.
 * It provides a clean "Record" button that, when clicked, headlessly controls
 * the standard Starmus recorder form.
 *
 * It expects the following variables to be passed from the shortcode class:
 * @var int    $post_id            The ID of the target aiwa_artifact.
 * @var string $container_id       A unique ID for the main container div.
 * @var string $prefill_data_json  A JSON-encoded string of data to pre-fill the form.
 *
 * @version 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>

<div id="<?php echo esc_attr($container_id); ?>" class="aiwa-starmus-trigger-container">
    
    <!-- This is the only button the user sees initially. -->
    <button type="button" class="aiwa-starmus-start-button button button-primary button-large">🎙️ Record this Sentence</button>
    
    <!-- The visible Starmus recorder UI (Step 2) will be moved here by our JS. -->
    <div class="aiwa-starmus-recorder-target"></div>

    <!-- The original Starmus form is rendered here, but hidden from view. -->
    <div class="aiwa-starmus-hidden-form" style="display: none !important; visibility: hidden; height: 0; overflow: hidden;">
        <?php echo do_shortcode('[starmus_recorder]'); ?>
    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const triggerContainer = document.getElementById(<?php echo wp_json_encode($container_id); ?>);
        if (!triggerContainer) return;

        const startButton = triggerContainer.querySelector('.aiwa-starmus-start-button');
        const hiddenFormContainer = triggerContainer.querySelector('.aiwa-starmus-hidden-form');
        const recorderTarget = triggerContainer.querySelector('.aiwa-starmus-recorder-target');
        const prefillData = JSON.parse(<?php echo $prefill_data_json; ?>);

        startButton.addEventListener('click', () => {
            const starmusForm = hiddenFormContainer.querySelector('.starmus-audio-form');
            if (!starmusForm) {
                console.error('AIWA Trigger: Starmus recorder form could not be found.');
                alert('The recorder is currently unavailable. Please try again later.');
                return;
            }

            // --- Step 1: Pre-fill the hidden Starmus form fields ---
            const titleInput = starmusForm.querySelector('[name="starmus_title"]');
            if (titleInput) titleInput.value = prefillData.title;

            // Helper function to find and select a dropdown option by its visible text
            const findAndSelectOption = (selectElement, text) => {
                if (!selectElement) return;
                const option = Array.from(selectElement.options).find(opt => opt.text.trim() === text);
                if (option) {
                    option.selected = true;
                } else {
                    console.warn(`AIWA Trigger: Could not find option "${text}" in dropdown.`);
                }
            };

            findAndSelectOption(starmusForm.querySelector('[name="language"]'), prefillData.languageName);
            findAndSelectOption(starmusForm.querySelector('[name="recording_type"]'), prefillData.recordingTypeName);
            
            const consentCheckbox = starmusForm.querySelector('[name="agreement_to_terms"]');
            if(consentCheckbox) consentCheckbox.checked = true;

            // --- Step 2: Programmatically "click" the continue button to trigger the Starmus JS ---
            const continueButton = starmusForm.querySelector('[id^="starmus_continue_btn_"]');
            if (continueButton) {
                continueButton.click(); // This makes the Starmus JS show Step 2
            } else {
                console.error('AIWA Trigger: Could not find the Starmus "Continue" button.');
                return;
            }

            // --- Step 3: Move the visible recorder UI into our target container ---
            // The Starmus JS has now made the Step 2 div visible. We move it to our container.
            const recorderStep2 = starmusForm.querySelector('[id^="starmus_step2_"]');
            if (recorderStep2) {
                recorderTarget.appendChild(recorderStep2);
            }

            // Finally, hide the initial start button as its job is done.
            startButton.style.display = 'none';
        });
    });
</script>
