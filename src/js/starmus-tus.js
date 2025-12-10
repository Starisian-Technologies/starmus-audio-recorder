/**
 * @file starmus-tus.js
 * @version 6.6.0-MULTI-DOMAIN
 * @description Forces relative API paths to support multiple domains/URLs.
 */

'use strict';

// 1. Config
const DEFAULT_CONFIG = {
  chunkSize: 512 * 1024, 
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

// 2. Direct Upload (Updated for Multi-Domain)
export async function uploadDirect(blob, fileName, formFields = {}, metadata = {}, _instanceId = '', onProgress) {
  const cfg = getConfig();
  const nonce = cfg.nonce || (window.starmusConfig && window.starmusConfig.nonce) || '';

  // CRITICAL FIX: Use Relative Path for Endpoint
  // This ensures we upload to the current domain (e.g. penguin.linux.test OR contribute.sparxstar.com)
  // regardless of what might be saved in the DB settings.
  const endpoint = '/wp-json/star-starmus-audio-recorder/v1/upload-fallback';

  const fields = normalizeFormFields(formFields);

  return new Promise((resolve, reject) => {
    const fd = new FormData();
    
    if (!(blob instanceof Blob)) return reject(new Error('INVALID_BLOB_TYPE'));

    // Filter out fields we will add manually
    const SKIP = ['_starmus_env', '_starmus_calibration', 'first_pass_transcription'];
    Object.entries(fields).forEach(([k, v]) => {
        if (!SKIP.includes(k)) fd.append(k, v);
    });
    
    fd.append('audio_file', blob, fileName);

    if (metadata?.transcript) fd.append('first_pass_transcription', metadata.transcript);
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
        // Detailed error for 500/403
        reject(new Error(`Upload failed: ${xhr.status} ${xhr.statusText}`));
      }
    };

    xhr.onerror = () => reject(new Error('Network error on direct upload'));
    xhr.send(fd);
  });
}

// 3. Priority Wrapper
export async function uploadWithPriority(arg1) {
  let blob, fileName, formFields, metadata, instanceId, onProgress;

  if (arg1 && arg1.blob) {
      ({ blob, fileName, formFields, metadata, instanceId, onProgress } = arg1);
  } else {
      [blob, fileName, formFields, metadata, instanceId, onProgress] = arguments;
  }

  if (!blob) throw new Error('No blob provided');

  // Skip TUS if disabled/missing, go straight to robust Direct fallback
  try {
    if (isTusAvailable(blob.size)) {
        return await uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress);
    }
    throw new Error('TUS_UNAVAILABLE');
  } catch (e) {
    console.log('[Uploader] Using Direct Fallback (Relative URL)');
    return await uploadDirect(blob, fileName, formFields, metadata, instanceId, onProgress);
  }
}

// 4. Helpers
export function isTusAvailable(blobSize = 0) {
  const cfg = getConfig();
  // Only use TUS if explicitly configured AND library is loaded
  return !!(window.tus && window.tus.Upload && cfg.endpoint && blobSize > 0);
}

export async function uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress) {
    throw new Error('TUS_DISABLED_IN_THIS_BUILD');
}

export function estimateUploadTime(fileSize, networkInfo) {
  let downlink = networkInfo?.downlink || 0.5; 
  const bytesPerSec = (downlink * 1000000) / 8;
  return Math.ceil((fileSize / bytesPerSec) * 1.5);
}

export function formatUploadEstimate(s) {
  return !isFinite(s) ? '...' : (s < 60 ? `~${s}s` : `~${Math.ceil(s/60)}m`);
}

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