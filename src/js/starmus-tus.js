/**
 * @file starmus-tus.js
 * @version 1.1.0
 * @description TUS resumable upload integration for Starmus Audio Recorder.
 * Optimized for unstable networks (West Africa) with robust fallback.
 */

'use strict';

import { debugLog } from './starmus-hooks.js';

/**
 * Default TUS configuration.
 * Optimized for high latency/packet loss.
 */
const DEFAULT_CONFIG = {
  // CRITICAL CHANGE: 5MB is too big for unstable 2G.
  // 1MB is the sweet spot between overhead and stability.
  chunkSize: 1 * 1024 * 1024,
  retryDelays: [0, 3000, 5000, 10000, 20000, 60000],
  removeFingerprintOnSuccess: true,
  storeFingerprintForResuming: true,
};

/**
 * Upload a file using TUS protocol with resume capability.
 */
export async function uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress) {
  if (!window.tus || !window.tus.Upload) {
    debugLog('[TUS] Library missing, using fallback');
    throw new Error('TUS_NOT_AVAILABLE');
  }

  const config = window.starmusTus || {};
  const endpoint = config.endpoint || window.starmusConfig?.endpoints?.tusUpload;

  if (!endpoint) {
    throw new Error('TUS_ENDPOINT_NOT_CONFIGURED');
  }

  // Build metadata
  const tusMetadata = {
    filename: sanitizeMetadata(fileName),
    filetype: blob.type || 'audio/webm',
    ...Object.keys(formFields).reduce((acc, key) => {
      acc[key] = sanitizeMetadata(String(formFields[key]));
      return acc;
    }, {}),
  };

  if (metadata) {
    tusMetadata.starmus_meta = sanitizeMetadata(JSON.stringify(metadata));
  }

  return new Promise((resolve, reject) => {
    const tusOptions = {
      endpoint: endpoint,
      chunkSize: config.chunkSize || DEFAULT_CONFIG.chunkSize,
      retryDelays: config.retryDelays || DEFAULT_CONFIG.retryDelays,
      metadata: tusMetadata,
      headers: config.headers || {},

      // CRITICAL FIX: Custom fingerprinting.
      // Default tus fingerprint uses blob properties that change on reload.
      // We bind the fingerprint to the 'instanceId' + 'size' which is stable across reloads.
      fingerprint: function (file, _options) {
        return ['starmus', instanceId, file.size].join('-');
      },

      onError: (error) => {
        debugLog('[TUS] Error:', error);
        reject(error);
      },

      onProgress: (bytesUploaded, bytesTotal) => {
        if (onProgress) {
          onProgress(bytesUploaded, bytesTotal);
        }
      },

      onSuccess: () => {
        debugLog('[TUS] Success:', uploader.url);
        resolve(uploader.url);
      },
    };

    const uploader = new window.tus.Upload(blob, tusOptions);

    // Attempt to find previous uploads to resume
    uploader
      .findPreviousUploads()
      .then((previousUploads) => {
        if (previousUploads && previousUploads.length > 0) {
          debugLog('[TUS] Resuming from:', previousUploads[0].uploadUrl);
          uploader.resumeFromPreviousUpload(previousUploads[0]);
        }
        uploader.start();
      })
      .catch(() => {
        // If storage fails, just start fresh
        uploader.start();
      });
  });
}

/**
 * Fallback to direct POST upload using XMLHttpRequest (XHR).
 * Switched from fetch() to support upload progress on slow networks.
 */
export async function uploadDirect(blob, fileName, formFields, metadata, _instanceId, onProgress) {
  const config = window.starmusConfig || {};
  const endpoint = config.endpoints?.directUpload;
  const nonce = config.nonce || '';

  if (!endpoint) {
    throw new Error('Direct upload endpoint not configured');
  }

  return new Promise((resolve, reject) => {
    const formData = new FormData();

    Object.keys(formFields).forEach((key) => formData.append(key, formFields[key]));
    formData.append('audio_file', blob, fileName);

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

    const xhr = new XMLHttpRequest();
    xhr.open('POST', endpoint, true);

    // Add WordPress Nonce
    xhr.setRequestHeader('X-WP-Nonce', nonce);

    // Upload Progress Listener
    if (xhr.upload && onProgress) {
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          onProgress(e.loaded, e.total);
        }
      };
    }

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          const response = JSON.parse(xhr.responseText);
          resolve(response);
        } catch {
          reject(new Error('Invalid JSON response from server'));
        }
      } else {
        reject(new Error(`Upload failed: ${xhr.status} ${xhr.statusText}`));
      }
    };

    xhr.onerror = () => reject(new Error('Network error during direct upload'));
    xhr.onabort = () => reject(new Error('Upload aborted'));

    xhr.send(formData);
  });
}

/**
 * Sanitize metadata values for TUS headers.
 * Note: tus-js-client handles Base64 encoding internally,
 * but we truncate to avoid header overflow.
 */
function sanitizeMetadata(value) {
  if (typeof value !== 'string') {
    value = String(value);
  }
  // Remove newlines which break headers
  value = value.replace(/[\r\n\t]/g, ' ');
  // Truncate to 500 chars to prevent "Request Header Or Cookie Too Large" (431)
  return value.length > 500 ? value.substring(0, 497) + '...' : value;
}

export function isTusAvailable() {
  return !!(
    window.tus &&
    window.tus.Upload &&
    (window.starmusTus?.endpoint || window.starmusConfig?.endpoints?.tusUpload)
  );
}

export function estimateUploadTime(fileSize, networkInfo) {
  let downlink = networkInfo?.downlink;

  if (!downlink || downlink === 0) {
    const effectiveType = networkInfo?.effectiveType;
    switch (effectiveType) {
      case 'slow-2g':
        downlink = 0.05;
        break;
      case '2g':
        downlink = 0.15;
        break; // More conservative for rural
      case '3g':
        downlink = 0.75;
        break;
      case '4g':
        downlink = 8.0;
        break;
      default:
        downlink = 0.5;
    }
  }

  // Cap downlink to realistic rural maximum (10 Mbps)
  // Prevents unrealistic estimates from Chrome bug (reports 10 on Edge 2G)
  downlink = Math.min(downlink, 10);

  const bytesPerSecond = (downlink * 1000000) / 8;
  // 50% overhead for latency/handshakes in rural areas
  return Math.ceil((fileSize / bytesPerSecond) * 1.5);
}

export function formatUploadEstimate(seconds) {
  if (!Number.isFinite(seconds)) {
    return 'Calculating...';
  }
  if (seconds < 60) {
    return `~${seconds}s`;
  }
  const minutes = Math.ceil(seconds / 60);
  return `~${minutes} min`;
}
