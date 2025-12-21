<?php
/**
 * CLOUDFLARE R2 COMPATIBLE INTEGRATION
 * 
 * Add this to StarmusSubmissionHandler::save_all_metadata()
 * After the attachment creation section (around line 400)
 */

// CLOUDFLARE R2 + AFRICA BANDWIDTH OPTIMIZATION
if ( $attachment_id !== 0 ) {
	$this->update_acf_field( 'original_source', $attachment_id, $audio_post_id );
	
	// Initialize services
	$file_service = new \Starisian\Sparxstar\Starmus\services\StarmusFileService( $this->dal );
	$id3_service = new \Starisian\Sparxstar\Starmus\services\StarmusId3Service();
	$cloudflare_service = new \Starisian\Sparxstar\Starmus\services\StarmusCloudflareAudioService( 
		$file_service, 
		$id3_service 
	);
	
	// Process for African networks (works with R2 offloading)
	$africa_results = $cloudflare_service->processForAfrica( $attachment_id );
	
	if ( ! empty( $africa_results ) && ! isset( $africa_results['error'] ) ) {
		// Store optimized version attachment IDs
		foreach ( $africa_results as $quality => $data ) {
			$this->update_acf_field( "{$quality}_attachment_id", $data['attachment_id'], $audio_post_id );
			$this->update_acf_field( "{$quality}_url", $data['url'], $audio_post_id );
		}
		
		// Store data usage estimates
		$estimates = $cloudflare_service->getAfricaDataEstimate( $attachment_id );
		$this->update_acf_field( 'africa_data_estimates', json_encode( $estimates ), $audio_post_id );
		
		StarmusLogger::info( 'CloudflareIntegration', 'Africa optimization completed', [
			'post_id' => $audio_post_id,
			'versions' => array_keys( $africa_results ),
			'estimated_savings' => $estimates['estimated_2g_size_mb'] . 'MB'
		]);
	}
}