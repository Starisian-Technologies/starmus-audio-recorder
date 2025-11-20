/**
 * @file starmus-tus.js
 * @version 1.0.0
 * @description TUS resumable upload integration for Starmus Audio Recorder.
 * Provides chunked uploads with automatic resume capability for West Africa networks.
 * 
 * @see https://tus.io/protocols/resumable-upload.html
 */

'use strict';

import { debugLog } from './starmus-hooks.js';

/**
 * Default TUS configuration.
 * Can be overridden by window.starmusTus from PHP.
 */
const DEFAULT_CONFIG = {
    chunkSize: 5 * 1024 * 1024, // 5MB chunks for 2G/3G networks
    retryDelays: [0, 3000, 5000, 10000, 20000], // Progressive retry delays
    removeFingerprintOnSuccess: true,
    storeFingerprintForResuming: true,
};

/**
 * Upload a file using TUS protocol with resume capability.
 * 
 * @param {Blob} blob - Audio blob to upload
 * @param {string} fileName - Original filename
 * @param {object} formFields - Form metadata (title, language, etc.)
 * @param {object} metadata - Additional metadata (calibration, transcript, env)
 * @param {string} instanceId - Starmus instance ID for UI updates
 * @param {function} onProgress - Progress callback (bytesUploaded, bytesTotal) => void
 * @returns {Promise<string>} Upload URL on success
 */
export async function uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress) {
    // Check if TUS is available
    if (!window.tus || !window.tus.Upload) {
        debugLog('[TUS] tus-js-client not loaded, falling back to direct upload');
        throw new Error('TUS_NOT_AVAILABLE');
    }

    const config = window.starmusTus || {};
    const endpoint = config.endpoint || window.starmusConfig?.endpoints?.tusUpload;

    if (!endpoint) {
        debugLog('[TUS] No TUS endpoint configured');
        throw new Error('TUS_ENDPOINT_NOT_CONFIGURED');
    }

    // Build metadata for TUS headers
    const tusMetadata = {
        filename: sanitizeMetadata(fileName),
        filetype: blob.type || 'audio/webm',
        ...Object.keys(formFields).reduce((acc, key) => {
            acc[key] = sanitizeMetadata(String(formFields[key]));
            return acc;
        }, {}),
    };

    // Attach extended metadata as JSON
    if (metadata) {
        tusMetadata.starmus_meta = sanitizeMetadata(JSON.stringify(metadata));
    }

    // TUS configuration
    const tusOptions = {
        endpoint: endpoint,
        chunkSize: config.chunkSize || DEFAULT_CONFIG.chunkSize,
        retryDelays: config.retryDelays || DEFAULT_CONFIG.retryDelays,
        removeFingerprintOnSuccess: config.removeFingerprintOnSuccess ?? DEFAULT_CONFIG.removeFingerprintOnSuccess,
        storeFingerprintForResuming: config.storeFingerprintForResuming ?? DEFAULT_CONFIG.storeFingerprintForResuming,
        metadata: tusMetadata,
        headers: config.headers || {},
        
        onError: function(error) {
            debugLog('[TUS] Upload error:', error);
        },
        
        onProgress: function(bytesUploaded, bytesTotal) {
            const percent = Math.round((bytesUploaded / bytesTotal) * 100);
            debugLog(`[TUS] Progress: ${percent}% (${bytesUploaded}/${bytesTotal} bytes)`);
            
            if (onProgress) {
                onProgress(bytesUploaded, bytesTotal);
            }
        },
        
        onSuccess: function() {
            debugLog('[TUS] Upload complete:', uploader.url);
        },
    };

    // Create TUS uploader instance
    const uploader = new window.tus.Upload(blob, tusOptions);

    return new Promise((resolve, reject) => {
        // Try to resume previous upload first
        uploader.findPreviousUploads()
            .then((previousUploads) => {
                if (previousUploads && previousUploads.length > 0) {
                    debugLog('[TUS] Found previous upload, resuming from:', previousUploads[0].uploadUrl);
                    uploader.resumeFromPreviousUpload(previousUploads[0]);
                }
                
                // Start upload
                uploader.start();
            })
            .catch((error) => {
                debugLog('[TUS] Could not check for previous uploads, starting fresh:', error);
                uploader.start();
            });

        // Override callbacks to use promise
        uploader.options.onSuccess = function() {
            debugLog('[TUS] Upload successful:', uploader.url);
            resolve(uploader.url);
        };

        uploader.options.onError = function(error) {
            debugLog('[TUS] Upload failed:', error);
            reject(error);
        };
    });
}

/**
 * Fallback to direct POST upload when TUS is not available.
 * 
 * @param {Blob} blob - Audio blob to upload
 * @param {string} fileName - Original filename
 * @param {object} formFields - Form metadata
 * @param {object} metadata - Additional metadata
 * @param {string} instanceId - Starmus instance ID
 * @param {function} onProgress - Progress callback (optional, not supported in fetch)
 * @returns {Promise<object>} Upload response JSON
 */
export async function uploadDirect(blob, fileName, formFields, metadata, instanceId, onProgress) {
    const config = window.starmusConfig || {};
    const endpoint = config.endpoints?.directUpload;
    const nonce = config.nonce || '';

    if (!endpoint) {
        throw new Error('Direct upload endpoint not configured');
    }

    const formData = new FormData();
    
    // Add form fields
    Object.keys(formFields).forEach((key) => {
        formData.append(key, formFields[key]);
    });
    
    // Add audio file
    formData.append('audio_file', blob, fileName);
    
    // Add metadata
    if (metadata) {
        if (metadata.transcript) {
            formData.append('_starmus_transcript', metadata.transcript);
        }
        if (metadata.calibration) {
            formData.append('_starmus_calibration', JSON.stringify(metadata.calibration));
        }
        if (metadata.env) {
            formData.append('_starmus_env', JSON.stringify(metadata.env));
        }
    }

    debugLog('[Direct Upload] Uploading to:', endpoint);

    const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': nonce,
        },
        body: formData,
    });

    if (!response.ok) {
        const errorText = await response.text();
        throw new Error(`Upload failed: ${response.status} - ${errorText}`);
    }

    return response.json();
}

/**
 * Sanitize metadata values for TUS headers (no newlines, limited length).
 * 
 * @param {string} value - Metadata value
 * @returns {string} Sanitized value
 */
function sanitizeMetadata(value) {
    if (typeof value !== 'string') {
        value = String(value);
    }
    
    // Remove newlines and control characters
    value = value.replace(/[\r\n\t]/g, ' ');
    
    // Trim to reasonable length (TUS metadata has limits)
    if (value.length > 500) {
        value = value.substring(0, 497) + '...';
    }
    
    return value;
}

/**
 * Check if TUS is available and configured.
 * 
 * @returns {boolean} True if TUS can be used
 */
export function isTusAvailable() {
    const hasTusLib = !!(window.tus && window.tus.Upload);
    const hasEndpoint = !!(window.starmusTus?.endpoint || window.starmusConfig?.endpoints?.tusUpload);
    
    return hasTusLib && hasEndpoint;
}

/**
 * Get estimated upload time based on file size and network speed.
 * Useful for showing user estimates.
 * 
 * @param {number} fileSize - File size in bytes
 * @param {object} networkInfo - Network info from env.network
 * @returns {number} Estimated seconds
 */
export function estimateUploadTime(fileSize, networkInfo) {
    let downlink = networkInfo?.downlink; // Mbps
    
    // Fallback estimates based on effective type
    if (!downlink || downlink === 0) {
        const effectiveType = networkInfo?.effectiveType;
        switch (effectiveType) {
            case 'slow-2g':
                downlink = 0.05; // 50 Kbps
                break;
            case '2g':
                downlink = 0.25; // 250 Kbps
                break;
            case '3g':
                downlink = 1.0; // 1 Mbps
                break;
            case '4g':
                downlink = 10.0; // 10 Mbps
                break;
            default:
                downlink = 1.0; // Conservative default
        }
    }
    
    // Convert to bytes per second
    const bytesPerSecond = (downlink * 1000000) / 8;
    
    // Add 30% overhead for protocol, retries, etc.
    const estimatedSeconds = (fileSize / bytesPerSecond) * 1.3;
    
    return Math.ceil(estimatedSeconds);
}

/**
 * Format upload time estimate for display.
 * 
 * @param {number} seconds - Estimated seconds
 * @returns {string} Human-readable time
 */
export function formatUploadEstimate(seconds) {
    if (seconds < 60) {
        return `~${seconds}s`;
    } else if (seconds < 3600) {
        const minutes = Math.ceil(seconds / 60);
        return `~${minutes}m`;
    } else {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.ceil((seconds % 3600) / 60);
        return `~${hours}h ${minutes}m`;
    }
}
