<?php
/**
 * Add this to your StarmusSubmissionHandler::save_all_metadata() method
 * Right after the attachment creation section
 */

// AFRICA BANDWIDTH OPTIMIZATION - Add after line ~400 in save_all_metadata()
if ( $attachment_id !== 0 ) {
	$this->update_acf_field( 'original_source', $attachment_id, $audio_post_id );
	
	// NEW: Africa bandwidth optimization
	$file_path = get_attached_file( $attachment_id );
	if ( $file_path && file_exists( $file_path ) ) {
		
		// Check if optimization needed
		$id3_service = new \Starisian\Sparxstar\Starmus\services\StarmusId3Service();
		if ( $id3_service->needsAfricaOptimization( $file_path ) ) {
			
			// Create bandwidth-optimized versions
			$africa_service = new \Starisian\Sparxstar\Starmus\services\StarmusAfricaBandwidthService();
			$optimized = $africa_service->createAfricaOptimized( $file_path );
			
			// Store optimized file paths
			$this->update_acf_field( 'africa_2g_version', $optimized['africa_2g'] ?? '', $audio_post_id );
			$this->update_acf_field( 'africa_3g_version', $optimized['africa_3g'] ?? '', $audio_post_id );
			$this->update_acf_field( 'africa_wifi_version', $optimized['africa_wifi'] ?? '', $audio_post_id );
			
			// Store data usage estimates
			$usage = $africa_service->estimateDataUsage( $file_path );
			$this->update_acf_field( 'data_usage_estimate', json_encode( $usage ), $audio_post_id );
			
			StarmusLogger::info( 'AfricaBandwidth', 'Optimized for Gambia networks', [
				'post_id' => $audio_post_id,
				'original_size' => $usage['size_mb'] . 'MB',
				'cost_estimate' => '$' . $usage['cost_estimate_usd']
			]);
		}
	}
}