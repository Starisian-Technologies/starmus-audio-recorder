<?php
/**
 * DIRECT CLOUDFLARE R2 SDK INTEGRATION
 * 
 * 1. Add to wp-config.php:
 */

// Cloudflare R2 Configuration
define( 'STARMUS_R2_ACCOUNT_ID', 'your-account-id' );
define( 'STARMUS_R2_ACCESS_KEY', 'your-access-key' );
define( 'STARMUS_R2_SECRET_KEY', 'your-secret-key' );
define( 'STARMUS_R2_BUCKET', 'starmus-audio' );

/**
 * 2. Add to composer.json:
 */
/*
{
  "require": {
    "aws/aws-sdk-php": "^3.0"
  }
}
*/

/**
 * 3. Integration in StarmusSubmissionHandler::save_all_metadata()
 */

// DIRECT R2 AFRICA OPTIMIZATION - Replace existing file handling
if ( $attachment_id !== 0 ) {
	$file_path = get_attached_file( $attachment_id );
	
	if ( $file_path && file_exists( $file_path ) ) {
		// Initialize direct R2 service
		$id3_service = new \Starisian\Sparxstar\Starmus\services\StarmusId3Service();
		$r2_service = new \Starisian\Sparxstar\Starmus\services\StarmusR2DirectService( $id3_service );
		
		// Process for Africa with direct R2 control
		$africa_versions = $r2_service->processAfricaAudio( $file_path, $audio_post_id );
		
		if ( ! empty( $africa_versions ) ) {
			// Store R2 URLs directly (no WordPress attachments needed)
			foreach ( $africa_versions as $quality => $data ) {
				$this->update_acf_field( "africa_{$quality}_url", $data['url'], $audio_post_id );
				$this->update_acf_field( "africa_{$quality}_size", $data['size_mb'], $audio_post_id );
			}
			
			// Store bandwidth estimates
			$estimates = $r2_service->getAfricaEstimates( $file_path );
			$this->update_acf_field( 'africa_bandwidth_savings', json_encode( $estimates ), $audio_post_id );
			
			StarmusLogger::info( 'R2Direct', 'Africa optimization completed', [
				'post_id' => $audio_post_id,
				'versions' => array_keys( $africa_versions ),
				'savings' => $estimates['bandwidth_savings']
			]);
		}
	}
}