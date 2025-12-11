/**
 * @file starmus-tus.js
 * @version 6.7.0-HOOK-INTEGRATION
 * @description TUS client with header security and metadata flattening for PHP Hooks.
 */

'use strict';

// 1. Config
const DEFAULT_CONFIG = {
  chunkSize: 512 * 1024, 
  retryDelays: [0, 5000, 10000, 30000, 60000, 120000, 300000],
  removeFingerprintOnSuccess: true,
  maxChunkRetries: 10,
  // The endpoint for the tusd server (can be relative if proxied, e.g., '/files/')
  endpoint: '/files/', 
  // Defined in localized script (wp_localize_script)
  webhookSecret: '' 
};

function getConfig() {
  // Merge defaults with global config
  const globalCfg = window.starmusTus || window.starmusConfig || {};
  return { ...DEFAULT_CONFIG, ...globalCfg };
}

function normalizeFormFields(fields) {
  if (fields && typeof fields === 'object') return fields;
  return {};
}

/**
 * Sanitizes metadata values for TUS. 
 * TUS metadata must be strings.
 */
function sanitizeMetadata(value) {
  if (typeof value === 'object') {
    return JSON.stringify(value);
  }
  let v = (typeof value === 'string' ? value : String(value)).replace(/[\r\n\t]/g, ' ');
  return v; // TUS handles base64 encoding internally usually, but we keep it raw string here
}

// 2. Direct Upload (Fallback)
export async function uploadDirect(blob, fileName, formFields = {}, metadata = {}, _instanceId = '', onProgress) {
  const cfg = getConfig();
  const nonce = cfg.nonce || (window.starmusConfig && window.starmusConfig.nonce) || '';

  // Fallback endpoint (WordPress REST API)
  const endpoint = '/wp-json/star-starmus-audio-recorder/v1/upload-fallback';

  const fields = normalizeFormFields(formFields);

  return new Promise((resolve, reject) => {
    const fd = new FormData();
    
    if (!(blob instanceof Blob)) return reject(new Error('INVALID_BLOB_TYPE'));

    // Filter out fields we will add manually or via specific keys
    const SKIP = ['_starmus_env', '_starmus_calibration', 'first_pass_transcription'];
    Object.entries(fields).forEach(([k, v]) => {
        if (!SKIP.includes(k)) fd.append(k, v);
    });
    
    fd.append('audio_file', blob, fileName);

    // Map metadata to form fields expected by the WP Controller
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

  // Try TUS first
  try {
    if (isTusAvailable(blob.size)) {
        return await uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress);
    }
    // If availability check fails, throw to trigger catch block
    throw new Error('TUS_UNAVAILABLE');
  } catch (e) {
    console.warn('[Uploader] TUS failed or unavailable, using Direct Fallback.', e.message);
    return await uploadDirect(blob, fileName, formFields, metadata, instanceId, onProgress);
  }
}

// 4. Helpers
export function isTusAvailable(blobSize = 0) {
  const cfg = getConfig();
  // Ensure library exists, endpoint is defined, and file has size
  return !!(window.tus && window.tus.Upload && cfg.endpoint && blobSize > 0);
}

/**
 * TUS Upload Implementation
 * Maps JS objects to flat metadata strings for the PHP Hook.
 */
export async function uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress) {
    const cfg = getConfig();

    return new Promise((resolve, reject) => {
        // 1. Prepare Metadata for PHP Hook
        // The PHP hook looks at $event_data['Upload']['MetaData']
        const tusMetadata = {
            filename: fileName,
            filetype: blob.type || 'application/octet-stream',
            name: fileName
        };

        // Merge standard form fields (post_id, nonce, etc)
        const fields = normalizeFormFields(formFields);
        Object.entries(fields).forEach(([key, val]) => {
            tusMetadata[key] = String(val);
        });

        // Merge complex metadata objects (mapped to specific keys for PHP)
        if (metadata?.transcript) tusMetadata['first_pass_transcription'] = sanitizeMetadata(metadata.transcript);
        if (metadata?.calibration) tusMetadata['_starmus_calibration'] = sanitizeMetadata(metadata.calibration);
        if (metadata?.env) tusMetadata['_starmus_env'] = sanitizeMetadata(metadata.env);
        if (metadata?.tier) tusMetadata['tier'] = sanitizeMetadata(metadata.tier);

        // 2. Configure TUS Upload
        const upload = new tus.Upload(blob, {
            endpoint: cfg.endpoint,
            retryDelays: cfg.retryDelays,
            chunkSize: cfg.chunkSize,
            parallelUploads: 1,
            metadata: tusMetadata,
            removeFingerprintOnSuccess: cfg.removeFingerprintOnSuccess,
            
            // CRITICAL: Send the secret.
            // tusd must be running with: -hooks-http-forward-headers x-starmus-secret
            headers: {
                'x-starmus-secret': cfg.webhookSecret || '' 
            },

            onError: (error) => {
                console.error('[StarmusTus] Upload Error:', error);
                reject(error);
            },

            onProgress: (bytesUploaded, bytesTotal) => {
                if (typeof onProgress === 'function') {
                    onProgress(bytesUploaded, bytesTotal);
                }
            },

            onSuccess: () => {
                // Note: The `post-finish` hook in PHP is async. 
                // We won't get the Attachment ID back here immediately from tusd.
                // We resolve indicating the transfer is complete.
                resolve({
                    success: true,
                    tus_url: upload.url,
                    message: 'Upload transfer complete. Processing in background.'
                });
            }
        });

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