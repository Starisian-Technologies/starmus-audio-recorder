/**
 * @file starmus-tus.js
 * @version 6.7.0-HOOK-INTEGRATION
 * @description TUS (resumable upload) client with header security and metadata flattening for PHP Hooks.
 * Provides upload functionality with automatic fallback from TUS to direct upload.
 * Integrates with WordPress REST API and tusd server for optimal file transfer.
 *
 * Features:
 * - TUS resumable uploads with chunk-based transfer
 * - Direct upload fallback for unsupported environments
 * - Metadata sanitization and flattening for PHP compatibility
 * - Upload progress tracking and error handling
 * - Webhook security with secret headers
 * - Automatic upload method selection based on availability
 */

'use strict';

import sparxstarIntegration from './starmus-sparxstar-integration.js';

/**
 * Circuit breaker for upload failures
 */
class UploadCircuitBreaker {
  constructor() {
    this.failures = 0;
    this.threshold = 3; // Open after 3 failures
    this.timeout = 60000; // 1 minute timeout
    this.state = 'closed'; // closed, open, half-open
    this.openedAt = null;
  }

  async execute(operation, context = '') {
    if (this.state === 'open') {
      const timeSinceOpen = Date.now() - this.openedAt;
      if (timeSinceOpen < this.timeout) {
        throw new Error(
          `Upload circuit breaker open - too many failures${context ? ` [${context}]` : ''}`
        );
      } else {
        this.state = 'half-open';
        console.log(
          `[Circuit Breaker] Attempting half-open state${context ? ` [${context}]` : ''}`
        );
      }
    }

    try {
      const result = await operation();

      // Success - reset breaker
      if (this.state === 'half-open') {
        this.state = 'closed';
        this.failures = 0;
        console.log('[Circuit Breaker] Reset to closed state');
      }

      return result;
    } catch (error) {
      this.failures++;

      if (this.failures >= this.threshold) {
        this.state = 'open';
        this.openedAt = Date.now();
        console.error(`[Circuit Breaker] Opened after ${this.failures} failures`);
      }

      throw error;
    }
  }
}

const uploadCircuitBreaker = new UploadCircuitBreaker();

/**
 * Default configuration object for TUS uploads.
 * Contains chunk sizes, retry settings, and endpoint configuration.
 * Enhanced with tier-based optimization from SPARXSTAR.
 *
 * @constant
 * @type {Object}
 * @property {number} chunkSize - Size of each upload chunk in bytes (optimized per tier)
 * @property {Array<number>} retryDelays - Retry delay intervals in milliseconds
 * @property {boolean} removeFingerprintOnSuccess - Whether to remove fingerprint after success
 * @property {number} maxChunkRetries - Maximum retry attempts per chunk
 * @property {string} endpoint - TUS server endpoint URL
 * @property {string} webhookSecret - Secret for webhook authentication
 */
// 1. Config
function getDefaultConfig() {
  // Get optimized settings from SPARXSTAR if available
  const envData = sparxstarIntegration.getEnvironmentData();
  const settings = envData?.recordingSettings || {};

  return {
    chunkSize: settings.uploadChunkSize || 512 * 1024, // Tier-optimized chunk size
    retryDelays: [0, 5000, 10000, 30000, 60000, 120000, 300000],
    removeFingerprintOnSuccess: true,
    maxChunkRetries: 10,
    endpoint: '/files/',
    webhookSecret: '',
  };
}

/**
 * Gets merged configuration from defaults and global settings.
 * Combines DEFAULT_CONFIG with window.starmusTus or window.starmusConfig.
 *
 * @function
 * @returns {Object} Merged configuration object
 * @example
 * const config = getConfig();
 * console.log(config.chunkSize); // 524288 (512KB)
 */
function getConfig() {
  // Get tier-optimized defaults
  const defaultConfig = getDefaultConfig();

  // Merge with global config
  const globalCfg = window.starmusTus || window.starmusConfig || {};
  const config = { ...defaultConfig, ...globalCfg };

  // Log optimization info
  const envData = sparxstarIntegration.getEnvironmentData();
  if (envData?.tier) {
    console.log('[TUS] Using tier-optimized config:', {
      tier: envData.tier,
      chunkSize: config.chunkSize,
      network: envData.network?.type,
    });
  }

  return config;
}

/**
 * Normalizes form fields to ensure object type.
 * Converts non-object values to empty object for safety.
 *
 * @function
 * @param {*} fields - Form fields of any type
 * @returns {Object} Normalized form fields object
 */
function normalizeFormFields(fields) {
  if (fields && typeof fields === 'object') {
    return fields;
  }
  return {};
}

/**
 * Sanitizes metadata values for TUS compatibility.
 * TUS metadata must be strings, so objects are JSON stringified.
 * Removes newlines, tabs, and carriage returns from strings.
 *
 * @function
 * @param {*} value - Value to sanitize (any type)
 * @returns {string} Sanitized string value safe for TUS metadata
 *
 * @example
 * sanitizeMetadata({key: 'value'}) // '{"key":"value"}'
 * sanitizeMetadata('text\nwith\ttabs') // 'text with tabs'
 */
function sanitizeMetadata(value) {
  if (typeof value === 'object') {
    return JSON.stringify(value);
  }
  const v = (typeof value === 'string' ? value : String(value)).replace(/[\r\n\t]/g, ' ');
  return v; // TUS handles base64 encoding internally usually, but we keep it raw string here
}

/**
 * Direct upload implementation as fallback for TUS.
 * Uploads file directly to WordPress REST API using FormData and XMLHttpRequest.
 * Handles progress tracking and proper metadata mapping for WordPress controller.
 *
 * @async
 * @function
 * @exports uploadDirect
 * @param {Blob} blob - Audio file blob to upload
 * @param {string} fileName - Name for the uploaded file
 * @param {Object} [formFields={}] - Form data fields (consent, language, etc.)
 * @param {Object} [metadata={}] - Additional metadata object
 * @param {string} [metadata.transcript] - Transcription text
 * @param {Object} [metadata.calibration] - Calibration settings
 * @param {Object} [metadata.env] - Environment data
 * @param {string} [metadata.tier] - Browser capability tier
 * @param {string} [_instanceId=''] - Instance identifier (unused)
 * @param {function} [onProgress] - Progress callback function
 * @param {number} onProgress.loaded - Bytes uploaded
 * @param {number} onProgress.total - Total bytes to upload
 * @returns {Promise<Object>} Upload result from WordPress API
 * @throws {Error} When blob is invalid, network fails, or server responds with error
 *
 * @example
 * const result = await uploadDirect(
 *   audioBlob,
 *   'recording.webm',
 *   { consent: 'yes', language: 'en' },
 *   { transcript: 'Hello world', tier: 'A' },
 *   'rec-123',
 *   (loaded, total) => console.log(`${loaded}/${total}`)
 * );
 */
// 2. Direct Upload (Fallback)
export async function uploadDirect(
  blob,
  fileName,
  formFields = {},
  metadata = {},
  _instanceId = '',
  onProgress
) {
  const cfg = getConfig();
  const nonce = cfg.nonce || window.starmusConfig?.nonce || '';

  // Fallback endpoint (WordPress REST API)
  const endpoint = '/wp-json/star-starmus-audio-recorder/v1/upload-fallback';

  const fields = normalizeFormFields(formFields);

  // Get current environment data for error reporting
  const envData = sparxstarIntegration.getEnvironmentData();

  return new Promise((resolve, reject) => {
    const fd = new FormData();

    if (!(blob instanceof Blob)) {
      const error = new Error('INVALID_BLOB_TYPE');
      // Report error to SPARXSTAR
      if (sparxstarIntegration.isAvailable) {
        sparxstarIntegration.reportError('upload_invalid_blob', {
          error: error.message,
          fileSize: blob?.size || 0,
          tier: envData?.tier,
        });
      }
      return reject(error);
    }

    // Filter out fields we will add manually or via specific keys
    const SKIP = ['_starmus_env', '_starmus_calibration'];
    Object.entries(fields).forEach(([k, v]) => {
      if (!SKIP.includes(k)) {
        fd.append(k, v);
      }
    });

    fd.append('audio_file', blob, fileName);

    // Map metadata to form fields expected by the WP Controller
    if (metadata?.calibration) {
      fd.append('_starmus_calibration', JSON.stringify(metadata.calibration));
    }
    if (metadata?.env) {
      fd.append('_starmus_env', JSON.stringify(metadata.env));
    }
    if (metadata?.tier) {
      fd.append('tier', metadata.tier);
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', endpoint, true);
    if (nonce) {
      xhr.setRequestHeader('X-WP-Nonce', nonce);
    }

    if (xhr.upload && typeof onProgress === 'function') {
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          onProgress(e.loaded, e.total);
        }
      };
    }

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          const result = JSON.parse(xhr.responseText);

          // Report successful upload to SPARXSTAR
          if (sparxstarIntegration.isAvailable) {
            sparxstarIntegration.reportError('upload_direct_success', {
              fileSize: blob.size,
              duration: Date.now() - startTime,
              tier: envData?.tier,
              network: envData?.network?.type,
            });
          }

          resolve(result);
        } catch (e) {
          reject(new Error('Invalid JSON response'));
        }
      } else {
        const error = new Error(`Upload failed: ${xhr.status} ${xhr.statusText}`);

        // Report error to SPARXSTAR
        if (sparxstarIntegration.isAvailable) {
          sparxstarIntegration.reportError('upload_direct_failed', {
            error: error.message,
            status: xhr.status,
            fileSize: blob.size,
            tier: envData?.tier,
          });
        }

        reject(error);
      }
    };

    xhr.onerror = () => {
      const error = new Error('Network error on direct upload');

      // Report network error to SPARXSTAR
      if (sparxstarIntegration.isAvailable) {
        sparxstarIntegration.reportError('upload_network_error', {
          error: error.message,
          fileSize: blob.size,
          tier: envData?.tier,
          network: envData?.network?.type,
        });
      }

      reject(error);
    };

    const startTime = Date.now();
    xhr.send(fd);
  });
}

/**
 * Priority upload wrapper that tries TUS first, then falls back to direct upload.
 * Automatically selects the best upload method based on availability and blob size.
 * Supports both object parameter and individual arguments for backward compatibility.
 *
 * @async
 * @function
 * @exports uploadWithPriority
 * @param {Object|Blob} arg1 - Upload parameters object or blob (legacy)
 * @param {Blob} arg1.blob - Audio file blob to upload
 * @param {string} arg1.fileName - Name for the uploaded file
 * @param {Object} arg1.formFields - Form data fields
 * @param {Object} arg1.metadata - Additional metadata
 * @param {string} arg1.instanceId - Instance identifier
 * @param {function} arg1.onProgress - Progress callback function
 * @param {string} [fileName] - Legacy parameter: file name
 * @param {Object} [formFields] - Legacy parameter: form fields
 * @param {Object} [metadata] - Legacy parameter: metadata
 * @param {string} [instanceId] - Legacy parameter: instance ID
 * @param {function} [onProgress] - Legacy parameter: progress callback
 * @returns {Promise<Object>} Upload result from chosen method
 * @throws {Error} When no blob provided or all upload methods fail
 *
 * @example
 * // Object syntax
 * const result = await uploadWithPriority({
 *   blob: audioBlob,
 *   fileName: 'recording.webm',
 *   formFields: { consent: 'yes' },
 *   metadata: { transcript: 'Hello' },
 *   instanceId: 'rec-123',
 *   onProgress: (loaded, total) => console.log(`${loaded}/${total}`)
 * });
 *
 * // Legacy syntax
 * const result = await uploadWithPriority(
 *   audioBlob, 'recording.webm', {}, {}, 'rec-123', progressFn
 * );
 */
// 3. Priority Wrapper
export async function uploadWithPriority(arg1) {
  let blob, fileName, formFields, metadata, instanceId, onProgress;

  if (arg1 && arg1.blob) {
    ({ blob, fileName, formFields, metadata, instanceId, onProgress } = arg1);
  } else {
    [blob, fileName, formFields, metadata, instanceId, onProgress] = arguments;
  }

  if (!blob) {
    throw new Error('No blob provided');
  }

  // Use circuit breaker for all uploads
  return uploadCircuitBreaker.execute(async () => {
    // Try TUS first
    try {
      if (isTusAvailable(blob.size)) {
        return await uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress);
      }
      throw new Error('TUS_UNAVAILABLE');
    } catch (e) {
      console.warn('[Uploader] TUS failed, using Direct Fallback.', e.message);
      return await uploadDirect(blob, fileName, formFields, metadata, instanceId, onProgress);
    }
  }, 'upload');
}

/**
 * Checks if TUS upload is available and viable.
 * Verifies TUS library presence, endpoint configuration, and minimum file size.
 *
 * @function
 * @exports isTusAvailable
 * @param {number} [blobSize=0] - Size of blob to upload in bytes
 * @returns {boolean} True if TUS upload can be used
 *
 * @example
 * if (isTusAvailable(audioBlob.size)) {
 *   console.log('TUS upload available');
 * }
 */
// 4. Helpers
export function isTusAvailable(blobSize = 0) {
  const cfg = getConfig();
  // Ensure library exists, endpoint is defined, and file has size
  return !!(window.tus && window.tus.Upload && cfg.endpoint && blobSize > 0);
}

/**
 * TUS (resumable) upload implementation with metadata flattening.
 * Handles chunk-based upload with resume capability and webhook security.
 * Maps complex JavaScript objects to flat metadata strings for PHP Hook compatibility.
 *
 * @async
 * @function
 * @exports uploadWithTus
 * @param {Blob} blob - Audio file blob to upload
 * @param {string} fileName - Name for the uploaded file
 * @param {Object} formFields - Form data fields (consent, language, etc.)
 * @param {Object} metadata - Additional metadata object
 * @param {string} metadata.transcript - Transcription text
 * @param {Object} metadata.calibration - Calibration settings
 * @param {Object} metadata.env - Environment data
 * @param {string} metadata.tier - Browser capability tier
 * @param {string} instanceId - Instance identifier
 * @param {function} onProgress - Progress callback function
 * @param {number} onProgress.bytesUploaded - Bytes uploaded so far
 * @param {number} onProgress.bytesTotal - Total bytes to upload
 * @returns {Promise<Object>} Upload result with TUS URL and status
 * @returns {boolean} returns.success - Whether upload completed successfully
 * @returns {string} returns.tus_url - TUS upload URL for tracking
 * @returns {string} returns.message - Status message
 *
 * @description Process:
 * 1. Prepares metadata by flattening objects to strings
 * 2. Configures TUS upload with security headers
 * 3. Attempts to resume previous uploads if found
 * 4. Starts chunked upload with progress tracking
 * 5. Resolves when transfer completes (PHP hook processes asynchronously)
 *
 * @example
 * const result = await uploadWithTus(
 *   audioBlob,
 *   'recording.webm',
 *   { consent: 'yes', post_id: '123' },
 *   { transcript: 'Hello', tier: 'A' },
 *   'rec-123',
 *   (uploaded, total) => console.log(`${uploaded}/${total}`)
 * );
 */
export async function uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress) {
  const cfg = getConfig();
  const envData = sparxstarIntegration.getEnvironmentData();
  const startTime = Date.now();

  return new Promise((resolve, reject) => {
    /**
     * TUS metadata object with flattened values.
     * All values must be strings for TUS protocol compatibility.
     */
    // 1. Prepare Metadata for PHP Hook
    // The PHP hook looks at $event_data['Upload']['MetaData']
    const tusMetadata = {
      filename: fileName,
      filetype: blob.type || 'application/octet-stream',
      name: fileName,
    };

    // Merge standard form fields (post_id, nonce, etc)
    const fields = normalizeFormFields(formFields);
    Object.entries(fields).forEach(([key, val]) => {
      tusMetadata[key] = String(val);
    });

    // Merge complex metadata objects (mapped to specific keys for PHP)
    if (metadata?.calibration) {
      tusMetadata['_starmus_calibration'] = sanitizeMetadata(metadata.calibration);
    }
    if (metadata?.env) {
      tusMetadata['_starmus_env'] = sanitizeMetadata(metadata.env);
    }
    if (metadata?.tier) {
      tusMetadata['tier'] = sanitizeMetadata(metadata.tier);
    }

    /**
     * TUS Upload instance with complete configuration.
     */
    // 2. Configure TUS Upload

    // CRITICAL: Secure webhook headers - never allow empty secrets
    const webhookSecret = cfg.webhookSecret;
    if (!webhookSecret || webhookSecret.trim() === '') {
      const error = new Error('TUS webhook secret not configured - upload blocked for security');
      console.error('[TUS Security] CRITICAL:', error.message);

      // Report security issue to SPARXSTAR
      if (sparxstarIntegration.isAvailable) {
        sparxstarIntegration.reportError('tus_security_no_secret', {
          error: error.message,
          endpoint: cfg.endpoint,
          timestamp: Date.now(),
        });
      }

      throw error;
    }

    // Generate secure headers with timestamp and signature
    const timestamp = Date.now().toString();
    const payload = `${fileName}-${timestamp}-${blob.size}`;

    const upload = new tus.Upload(blob, {
      endpoint: cfg.endpoint,
      retryDelays: cfg.retryDelays,
      chunkSize: cfg.chunkSize,
      parallelUploads: 1,
      metadata: tusMetadata,
      removeFingerprintOnSuccess: cfg.removeFingerprintOnSuccess,

      headers: {
        'x-starmus-secret': webhookSecret,
        'x-starmus-timestamp': timestamp,
        'x-starmus-payload-hash': btoa(payload), // Simple payload verification
        'x-starmus-tier': envData?.tier || 'C',
        'x-starmus-network': envData?.network?.type || 'unknown',
      },

      /**
       * Error handler for upload failures.
       * @param {Error} error - TUS upload error
       */
      onError: (error) => {
        console.error('[StarmusTus] Upload Error:', error);

        // Report TUS error to SPARXSTAR
        if (sparxstarIntegration.isAvailable) {
          sparxstarIntegration.reportError('upload_tus_failed', {
            error: error.message,
            fileSize: blob.size,
            tier: envData?.tier,
            network: envData?.network?.type,
            chunkSize: cfg.chunkSize,
          });
        }

        reject(error);
      },

      /**
       * Progress handler for upload tracking.
       * @param {number} bytesUploaded - Bytes uploaded so far
       * @param {number} bytesTotal - Total bytes to upload
       */
      onProgress: (bytesUploaded, bytesTotal) => {
        if (typeof onProgress === 'function') {
          onProgress(bytesUploaded, bytesTotal);
        }
      },

      /**
       * Success handler when upload transfer completes.
       * Note: PHP post-finish hook runs asynchronously.
       */
      onSuccess: () => {
        // Report successful TUS upload to SPARXSTAR
        if (sparxstarIntegration.isAvailable) {
          sparxstarIntegration.reportError('upload_tus_success', {
            fileSize: blob.size,
            duration: Date.now() - startTime,
            tier: envData?.tier,
            network: envData?.network?.type,
            chunkSize: cfg.chunkSize,
          });
        }

        // Note: The `post-finish` hook in PHP is async.
        // We won't get the Attachment ID back here immediately from tusd.
        // We resolve indicating the transfer is complete.
        resolve({
          success: true,
          tus_url: upload.url,
          message: 'Upload transfer complete. Processing in background.',
        });
      },
    });

    /**
     * Start upload with resume capability.
     * Checks for previous incomplete uploads and resumes if found.
     */
    // 3. Start Upload
    // Check for previous uploads to resume
    upload.findPreviousUploads().then(function (previousUploads) {
      // If previous uploads found, pick the first one to resume
      if (previousUploads.length) {
        upload.resumeFromPreviousUpload(previousUploads[0]);
      }
      upload.start();
    });
  });
}

/**
 * Estimates upload time based on file size and network information.
 * Uses connection downlink speed to calculate approximate transfer duration.
 * Includes 50% buffer for realistic estimation with network variations.
 *
 * @function
 * @exports estimateUploadTime
 * @param {number} fileSize - File size in bytes
 * @param {Object} [networkInfo] - Network connection information
 * @param {number} [networkInfo.downlink=0.5] - Downlink speed in Mbps
 * @returns {number} Estimated upload time in seconds
 *
 * @example
 * const estimate = estimateUploadTime(1024000, { downlink: 2.5 });
 * console.log(`Estimated: ${estimate} seconds`);
 */
export function estimateUploadTime(fileSize, networkInfo) {
  const downlink = networkInfo?.downlink || 0.5;
  const bytesPerSec = (downlink * 1000000) / 8;
  return Math.ceil((fileSize / bytesPerSec) * 1.5);
}

/**
 * Formats upload time estimate into human-readable string.
 * Converts seconds to either "~Xs" or "~Xm" format for display.
 *
 * @function
 * @exports formatUploadEstimate
 * @param {number} s - Time in seconds
 * @returns {string} Formatted time string or '...' for invalid input
 *
 * @example
 * formatUploadEstimate(45)  // '~45s'
 * formatUploadEstimate(120) // '~2m'
 * formatUploadEstimate(NaN) // '...'
 */
export function formatUploadEstimate(s) {
  return !isFinite(s) ? '...' : s < 60 ? `~${s}s` : `~${Math.ceil(s / 60)}m`;
}

/**
 * StarmusTus module object with all upload functions.
 * Provides unified interface for TUS and direct upload functionality.
 *
 * @constant
 * @type {Object}
 * @property {function} uploadWithTus - TUS resumable upload
 * @property {function} uploadDirect - Direct upload fallback
 * @property {function} uploadWithPriority - Priority upload wrapper
 * @property {function} isTusAvailable - TUS availability check
 * @property {function} estimateUploadTime - Upload time estimation
 * @property {function} formatUploadEstimate - Time format utility
 */
const StarmusTus = {
  uploadWithTus,
  uploadDirect,
  uploadWithPriority,
  isTusAvailable,
  estimateUploadTime,
  formatUploadEstimate,
};

/**
 * Global export for browser environments.
 * Makes StarmusTus available on window object.
 * @global
 */
if (typeof window !== 'undefined') {
  window.StarmusTus = StarmusTus;
}

/**
 * Default export for ES6 modules.
 * @default StarmusTus
 */
export default StarmusTus;
