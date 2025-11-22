# Integrating AIWA with the Starmus Plugin

========================================

This document outlines how to integrate an external service like AIWA with the Starmus Audio Recorder plugin. It covers two key integration points:

1. Providing AI-powered transcriptions and translations.
2. Enabling Starmus's internal language validation check during submission.

## 1\. Transcription and Translation

---

This code demonstrates how to hook into the Starmus plugin to provide AI-powered transcriptions and translations. The data you provide will be embedded directly into the final audio file's ID3 metadata.

### Hook into the Starmus Transcription Filter

The starmus_audio_transcribe filter is called by the Starmus StarmusAudioProcessingService after an audio file has been mastered and is ready for metadata to be written.

codePHP

php``/**   * Hook into the Starmus transcription filter.   *    * This function will be called by the Starmus `StarmusAudioProcessingService`   * after an audio file has been mastered and is ready for metadata.   */  add_filter( 'starmus_audio_transcribe', 'aiwa_provide_transcriptions_and_translations', 10, 3 );  /**   * Gathers transcription and translation data from the AiWA service   * and returns it in the schema expected by Starmus.   *   * @param array  $transcriptions  The default empty array from Starmus.   * @param int    $attachment_id   The ID of the audio attachment being processed.   * @param string $file_path       The path to the mastered MP3 file.   *   * @return array An array of transcription/translation objects.   */  function aiwa_provide_transcriptions_and_translations( $transcriptions, $attachment_id, $file_path ) {      // --- In a real-world scenario, you would call the AiWA API here ---      // $aiwa_api = new AiwaTranscriptionAPI();      // $original_transcription = $aiwa_api->transcribe($file_path, 'mnk');      // $english_translation    = $aiwa_api->translate($original_transcription['text'], 'eng');      // --- For this example, we use placeholder data ---      $original_transcription = [          'success' => true,          'text'    => "Nte Mandinka la. Wolu an kele la. (Placeholder Mandinka transcription)",      ];      $english_translation = [          'success' => true,          'text'    => "I speak Mandinka. We are in a fight. (Placeholder English translation)",      ];      // --- Build the data structure that Starmus expects ---      // 1. Add the original Mandinka transcription      if ( ! empty( $original_transcription['success'] ) && ! empty( $original_transcription['text'] ) ) {          $transcriptions[] = [              'lang' => 'mnk', // ISO 639-2 code for Mandinka              'desc' => 'Original Transcription (Mandinka)',              'text' => $original_transcription['text'],          ];      }      // 2. Add the English translation      if ( ! empty( $english_translation['success'] ) && ! empty( $english_translation['text'] ) ) {          $transcriptions[] = [              'lang' => 'eng', // ISO 639-2 code for English              'desc' => 'English Translation',              'text' => $english_translation['text'],          ];      }      return $transcriptions;  }``

## 2\. Enabling Language Validation

---

The Starmus plugin includes a client-side (JavaScript) check to validate that a recording is likely a West African language. **This check is turned OFF by default.**

To protect data integrity for a specific use case, you can programmatically enable this validation check using the starmus_bypass_language_validation filter.

### Usage

To enable the language validation, you must add a filter that returns false. This tells the Starmus front-end script that it should **not** bypass the validation.

PHP`/**   * Enable the Starmus plugin's internal language check.   * This forces the front-end to validate the recording is a West African language   * before allowing the submission to proceed.   */  add_filter( 'starmus_bypass_language_validation', '__return_false' );`

### How It Works

- **Default Behavior:** By default, the Starmus front-end is configured to **bypass** the language validation script. The setting starmusSettings.bypassLanguageValidation is true, and the JavaScript code does not run the check.
- **Integration:** When your plugin adds the filter above, it changes the setting to false.
- **Result:** The Starmus JavaScript on the front-end detects that bypassLanguageValidation is now false and will execute the validateWestAfricanLanguage function, pausing the submission if the validation fails.
- **Decoupling:** This method ensures that the plugins are cleanly decoupled. There are no direct dependencies, only this clean "hook" that allows for interoperability.
