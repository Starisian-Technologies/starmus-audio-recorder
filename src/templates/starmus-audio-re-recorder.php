<?php
/**
 * AIWA Starmus Trigger – Re-record Template (via Shortcode Attributes)
 *
 * This version uses shortcode attributes to prefill title, language, and recording type.
 * All other hidden fields are handled by Starmus’ native JavaScript workflow.
 *
 * @var string $container_id
 * @var string $title
 * @var string $language
 * @var string $recording_type
 * @var string $consent_message
 * @var string $data_policy_url
 *
 * @version 1.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div id="<?php echo esc_attr( $container_id ); ?>" class="aiwa-starmus-trigger-container aiwa-starmus-rerecord">
	<!-- Step 1: Re-record button -->
	<button type="button" class="aiwa-starmus-start-button button button-primary button-large">
		🎙️ Re-record Audio
	</button>

	<!-- Step 2: Consent area (visible after clicking button) -->
	<div class="aiwa-starmus-consent-area" style="margin-top:1rem; display:none;">
		<label style="display:flex; align-items:center; gap:0.5rem;">
			<input type="checkbox" class="aiwa-starmus-consent-checkbox" />
			<span>
				<?php echo esc_html( $consent_message ); ?>
				<?php if ( ! empty( $data_policy_url ) ) { ?>
					<a href="<?php echo esc_url( $data_policy_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Read Policy', 'starmus-audio-recorder' ); ?>
					</a>
				<?php } ?>
			</span>
		</label>
	</div>

	<!-- Step 3: Recorder appears here -->
	<div class="aiwa-starmus-recorder-target"></div>

	<!-- Hidden original Starmus recorder form -->
	<div class="aiwa-starmus-hidden-form" style="display:none !important;">
		<?php echo do_shortcode( '[starmus_recorder]' ); ?>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
	const containerId = <?php echo wp_json_encode( $container_id ); ?>;
	const triggerContainer = document.getElementById(containerId);
	if (!triggerContainer) return;

	const startButton = triggerContainer.querySelector('.aiwa-starmus-start-button');
	const consentArea = triggerContainer.querySelector('.aiwa-starmus-consent-area');
	const consentCheckbox = triggerContainer.querySelector('.aiwa-starmus-consent-checkbox');
	const hiddenFormContainer = triggerContainer.querySelector('.aiwa-starmus-hidden-form');
	const recorderTarget = triggerContainer.querySelector('.aiwa-starmus-recorder-target');

	const prefillData = {
		title: <?php echo wp_json_encode( $title ); ?>,
		language: <?php echo wp_json_encode( $language ); ?>,
		recordingType: <?php echo wp_json_encode( $recording_type ); ?>,
	};

	startButton.addEventListener('click', () => {
		startButton.style.display = 'none';
		consentArea.style.display = 'block';
	});

	consentCheckbox.addEventListener('change', () => {
		if (!consentCheckbox.checked) return;

		const starmusForm = hiddenFormContainer.querySelector('.starmus-audio-form');
		if (!starmusForm) {
			alert('Recorder unavailable. Please reload.');
			return;
		}

		// --- Pre-fill user-facing fields only ---
		const titleInput = starmusForm.querySelector('[name="starmus_title"]');
		if (titleInput && prefillData.title) titleInput.value = prefillData.title;

		const selectOptionByText = (select, text) => {
			if (!select || !text) return;
			const opt = Array.from(select.options).find(o => o.text.trim() === text.trim());
			if (opt) opt.selected = true;
		};
		selectOptionByText(starmusForm.querySelector('[name="language"]'), prefillData.language);
		selectOptionByText(starmusForm.querySelector('[name="recording_type"]'), prefillData.recordingType);

		// --- Auto-agree to terms ---
		const consent = starmusForm.querySelector('[name="agreement_to_terms"]');
		if (consent) consent.checked = true;

		// --- Trigger Step 2 ---
		const continueBtn = starmusForm.querySelector('[id^="starmus_continue_btn_"]');
		if (continueBtn) continueBtn.click();
		else {
			console.error('Continue button missing.');
			return;
		}

		// --- Move visible recorder into container ---
		const step2 = starmusForm.querySelector('[id^="starmus_step2_"]');
		if (step2) recorderTarget.appendChild(step2);

		consentArea.style.display = 'none';
	});
});
</script>
