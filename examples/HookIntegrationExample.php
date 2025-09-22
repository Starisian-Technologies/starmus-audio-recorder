/**
 * =========================================================================
 * AiWA Integration Example for the Starmus Audio Processing Pipeline
 * =========================================================================
 *
 * This code demonstrates how to hook into the Starmus plugin to provide
 * AI-powered transcriptions and translations, which will be embedded
 * directly into the audio file's ID3 metadata.
 */

/**
 * Hook into the Starmus transcription filter.
 *
 * This function will be called by the Starmus `AudioProcessingService`
 * after an audio file has been mastered and is ready for metadata.
 */
add_filter( 'starmus_audio_transcribe', 'aiwa_provide_transcriptions_and_translations', 10, 3 );

/**
 * Gathers transcription and translation data from the AiWA service
 * and returns it in the schema expected by Starmus.
 *
 * @param array  $transcriptions The default empty array from Starmus.
 * @param int    $attachment_id  The ID of the audio attachment being processed.
 * @param string $file_path      The path to the mastered MP3 file.
 *
 * @return array An array of transcription/translation objects.
 */
function aiwa_provide_transcriptions_and_translations( $transcriptions, $attachment_id, $file_path ) {

    // --- In a real-world scenario, call the AiWA API here ---
    // $aiwa_api = new AiwaTranscriptionAPI();
    // $original_transcription = $aiwa_api->transcribe($file_path, 'mnk');
    // $english_translation    = $aiwa_api->translate($original_transcription['text'], 'eng');

    // --- For this example, we use placeholder data ---
    $original_transcription = [
        'success' => true,
        'text'    => "Nte Mandinka la. Wolu an kele la. (Placeholder Mandinka transcription)",
    ];
    $english_translation = [
        'success' => true,
        'text'    => "I speak Mandinka. We are in a fight. (Placeholder English translation)",
    ];

    // --- Build the data structure that Starmus expects ---

    // 1. Add the original Mandinka transcription
    if ( ! empty( $original_transcription['success'] ) && ! empty( $original_transcription['text'] ) ) {
        $transcriptions[] = [
            'lang' => 'mnk', // ISO 639-2 code for Mandinka
            'desc' => 'Original Transcription (Mandinka)',
            'text' => $original_transcription['text'],
        ];
    }

    // 2. Add the English translation
    if ( ! empty( $english_translation['success'] ) && ! empty( $english_translation['text'] ) ) {
        $transcriptions[] = [
            'lang' => 'eng', // ISO 639-2 code for English
            'desc' => 'English Translation',
            'text' => $english_translation['text'],
        ];
    }

    return $transcriptions;
}
