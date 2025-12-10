/**
 * @file starmus-tus.js
 * @version 6.2.0-COMPAT-FIX
 * @description Upload strategy with Argument Normalization (Object -> Arguments).
 */

'use strict';

// 1. Config for Unstable Networks
const DEFAULT_CONFIG = {
  chunkSize: 512 * 1024, // 512KB chunks
  retryDelays: [0, 5000, 10000, 30000, 60000, 120000, 300000],
  removeFingerprintOnSuccess: true,
  maxChunkRetries: 10
};

function getConfig() {
  return window.starmusTus || window.starmusConfig || {};
}

function normalizeFormFields(fields) {
  if (fields && typeof fields === 'object') return fields;
  return {};
}

function sanitizeMetadata(value) {
  let v = (typeof value === 'string' ? value : String(value)).replace(/[\r\n\t]/g, ' ');
  return v.length > 500 ? v.slice(0, 497) + '...' : v;
}

// 2. Direct Upload Fallback (Expects Positional Arguments)
export async function uploadDirect(blob, fileName, formFields = {}, metadata = {}, _instanceId = '', onProgress) {
  const cfg = getConfig();
  // Fallback endpoint logic
  const endpoint = cfg.directUpload || 
                   (window.starmusConfig && window.starmusConfig.endpoints && window.starmusConfig.endpoints.directUpload) ||
                   '/wp-json/star-starmus-audio-recorder/v1/upload-fallback';
                   
  const nonce = cfg.nonce || (window.starmusConfig && window.starmusConfig.nonce) || '';

  const fields = normalizeFormFields(formFields);

  return new Promise((resolve, reject) => {
    const fd = new FormData();
    
    // Safety check for Blob
    if (!(blob instanceof Blob)) {
        return reject(new Error('INVALID_BLOB_TYPE: ' + typeof blob));
    }

    // Append Fields
    Object.entries(fields).forEach(([k,v]) => fd.append(k, v));
    
    // Append File
    fd.append('audio_file', blob, fileName);

    // Append Metadata
    if (metadata?.transcript) fd.append('_starmus_transcript', metadata.transcript);
    if (metadata?.calibration) fd.append('_starmus_calibration', JSON.stringify(metadata.calibration));
    if (metadata?.env) fd.append('_starmus_env', JSON.stringify(metadata.env));
    if (metadata?.tier) fd.append('tier', metadata.tier);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', endpoint, true);
    if (nonce) xhr.setRequestHeader('X-WP-Nonce', nonce);

    if (xhr.upload && typeof onProgress === 'function') {
      xhr.upload.onprogress = e => {
        if (e.lengthComputable) onProgress(e.loaded, e.total);
      };
    }

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          resolve(JSON.parse(xhr.responseText));
        } catch (e) {
          reject(new Error('Invalid JSON response'));
        }
      } else {
        reject(new Error(`Upload failed: ${xhr.status} ${xhr.statusText}`));
      }
    };

    xhr.onerror = () => reject(new Error('Network error on direct upload'));
    xhr.send(fd);
  });
}

// 3. Priority Wrapper (Fixes the Mismatch)
export async function uploadWithPriority(arg1) {
  // UNWRAP ARGUMENTS: Support both Object (new) and Positional (legacy) styles
  let blob, fileName, formFields, metadata, instanceId, onProgress;

  if (arg1 && arg1.blob) {
      // Object style (Used by starmus-core.js)
      ({ blob, fileName, formFields, metadata, instanceId, onProgress } = arg1);
  } else {
      // Positional style (Legacy support)
      [blob, fileName, formFields, metadata, instanceId, onProgress] = arguments;
  }

  // Debug check
  if (!blob) throw new Error('No blob provided to uploader');

  // STRATEGY: Try TUS -> Fallback to Direct
  try {
    if (isTusAvailable(blob.size)) {
        return await uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress);
    }
    throw new Error('TUS_NOT_AVAILABLE');
  } catch (eTus) {
    console.warn('[Uploader] TUS skipped/failed, using Direct Fallback.', eTus.message);
    // Call Direct with unwrapped positional arguments
    return await uploadDirect(blob, fileName, formFields, metadata, instanceId, onProgress);
  }
}

// 4. Helpers & TUS Stub
export function isTusAvailable(blobSize = 0) {
  const cfg = getConfig();
  // Check if TUS library exists AND endpoint is configured
  return !!(window.tus && window.tus.Upload && cfg.endpoint && blobSize > 0);
}

export async function uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress) {
    // ... (Full TUS implementation would go here, assuming stub for this fix since TUS is disabled on your server)
    throw new Error('TUS_DISABLED');
}

export function estimateUploadTime(fileSize, networkInfo) {
  let downlink = networkInfo?.downlink || 0.5; // Default slow
  const bytesPerSec = (downlink * 1000000) / 8;
  return Math.ceil((fileSize / bytesPerSec) * 1.5);
}

export function formatUploadEstimate(s) {
  return !isFinite(s) ? '...' : (s < 60 ? `~${s}s` : `~${Math.ceil(s/60)}m`);
}

// Export
const StarmusTus = {
  uploadWithTus,
  uploadDirect,
  uploadWithPriority,
  isTusAvailable,
  estimateUploadTime,
  formatUploadEstimate
};

if (typeof window !== 'undefined') window.StarmusTus = StarmusTus;
export default StarmusTus;