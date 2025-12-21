/**
 * tus-js-client Implementation Comparison
 * Official Documentation vs Starmus Audio Recorder
 */

// ===== OFFICIAL TUS-JS-CLIENT EXAMPLE =====
import * as tus from 'tus-js-client';

// Basic official example
const officialUpload = new tus.Upload(file, {
  endpoint: 'http://localhost:1080/files/',
  retryDelays: [0, 3000, 5000, 10000, 20000],
  metadata: {
    filename: file.name,
    filetype: file.type,
  },
  onError: function (error) {
    console.log('Failed because: ' + error);
  },
  onProgress: function (bytesUploaded, bytesTotal) {
    const percentage = ((bytesUploaded / bytesTotal) * 100).toFixed(2);
    console.log(bytesUploaded, bytesTotal, percentage + '%');
  },
  onSuccess: function () {
    console.log('Download %s from %s', upload.file.name, upload.url);
  },
});

// Resume from previous uploads
officialUpload.findPreviousUploads().then(function (previousUploads) {
  if (previousUploads.length) {
    officialUpload.resumeFromPreviousUpload(previousUploads[0]);
  }
  officialUpload.start();
});

// ===== STARMUS IMPLEMENTATION =====
// Enhanced with security, metadata flattening, and WordPress integration

const starmusUpload = new tus.Upload(blob, {
  endpoint: cfg.endpoint,
  retryDelays: cfg.retryDelays, // [0, 5000, 10000, 30000, 60000, 120000, 300000]
  chunkSize: cfg.chunkSize,     // Tier-optimized (512KB default)
  parallelUploads: 1,
  metadata: tusMetadata,        // Flattened for PHP compatibility
  removeFingerprintOnSuccess: cfg.removeFingerprintOnSuccess,
  
  // SECURITY HEADERS (Starmus Enhancement)
  headers: {
    'x-starmus-secret': webhookSecret,
    'x-starmus-timestamp': timestamp,
    'x-starmus-payload-hash': btoa(payload),
    'x-starmus-tier': envData?.tier || 'C',
    'x-starmus-network': envData?.network?.type || 'unknown'
  },

  onError: (error) => {
    // Enhanced error reporting with SPARXSTAR integration
    sparxstarIntegration.reportError('upload_tus_failed', {
      error: error.message,
      fileSize: blob.size,
      tier: envData?.tier
    });
    reject(error);
  },

  onProgress: (bytesUploaded, bytesTotal) => {
    if (typeof onProgress === 'function') {
      onProgress(bytesUploaded, bytesTotal);
    }
  },

  onSuccess: () => {
    // Success reporting and WordPress integration
    sparxstarIntegration.reportError('upload_tus_success', {
      fileSize: blob.size,
      duration: Date.now() - startTime,
      tier: envData?.tier
    });
    
    resolve({
      success: true,
      tus_url: upload.url,
      message: 'Upload transfer complete. Processing in background.'
    });
  }
});

// Same resume pattern as official
starmusUpload.findPreviousUploads().then(function (previousUploads) {
  if (previousUploads.length) {
    starmusUpload.resumeFromPreviousUpload(previousUploads[0]);
  }
  starmusUpload.start();
});

// ===== KEY DIFFERENCES =====

/**
 * 1. SECURITY ENHANCEMENTS
 * - Webhook secret validation
 * - Timestamp-based payload verification
 * - Tier and network information headers
 */

/**
 * 2. METADATA FLATTENING
 * - Complex objects converted to JSON strings
 * - PHP-compatible field mapping
 * - WordPress-specific metadata handling
 */

/**
 * 3. ERROR REPORTING
 * - Integration with SPARXSTAR monitoring
 * - Detailed error context and metrics
 * - Circuit breaker pattern for reliability
 */

/**
 * 4. TIER-BASED OPTIMIZATION
 * - Dynamic chunk size based on device capability
 * - Network-aware retry strategies
 * - Adaptive timeout configurations
 */

/**
 * 5. WORDPRESS INTEGRATION
 * - REST API fallback mechanism
 * - Nonce-based authentication
 * - Custom post type handling
 */

export { officialUpload, starmusUpload };