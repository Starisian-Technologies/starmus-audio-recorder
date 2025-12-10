/**
 * @file starmus-tus.js
 * @version 1.3.0‑full
 * @description Full uploader module for :contentReference[oaicite:0]{index=0} Audio Recorder.
 * Implements three‑tier upload strategy (TUS resumable → chunked‑REST → direct POST),
 * optimized for unstable networks and fallback compatibility.
 */

'use strict';

import { debugLog } from './starmus-hooks.js';

// Default config parameters — You can override via window.starmusTus or window.starmusConfig
const DEFAULT_CONFIG = {
  // 512KB is the sweet spot for unstable 2G/3G networks
  chunkSize: 512 * 1024, 
  // Backoff: Immediate, 5s, 10s, 30s, 60s, 2m, 5m, 10m
  retryDelays: [0, 5000, 10000, 30000, 60000, 120000, 300000, 600000], 
  removeFingerprintOnSuccess: true,
  // Retry a specific chunk 10 times before giving up on the whole file
  maxChunkRetries: 10 
};

/** Retrieve runtime config */
function getConfig() {
  return window.starmusTus || window.starmusConfig || {};
}

/** Sanitize metadata (filename, form‑fields) for safe headers / transmission */
function sanitizeMetadata(value) {
  let v = (typeof value === 'string' ? value : String(value)).replace(/[\r\n\t]/g, ' ');
  return v.length > 500 ? v.slice(0, 497) + '...' : v;
}

function normalizeFormFields(fields) {
  if (fields && typeof fields === 'object') return fields;
  return {};
}

/**
 * TUS upload (resumable, chunked, fault‑tolerant)
 */
export async function uploadWithTus(blob, fileName, formFields = {}, metadata = {}, instanceId = '', onProgress) {
  if (!window.tus || !window.tus.Upload) {
    debugLog('[TUS] No tus library — failing over');
    throw new Error('TUS_NOT_AVAILABLE');
  }

  const cfg = getConfig();
  const endpoint = cfg.endpoint || (window.starmusConfig && window.starmusConfig.endpoints && window.starmusConfig.endpoints.tusUpload);
  if (!endpoint) {
    throw new Error('TUS_ENDPOINT_NOT_CONFIGURED');
  }

  const meta = {
    filename: sanitizeMetadata(fileName),
    filetype: blob.type || 'audio/webm',
    ...Object.entries(normalizeFormFields(formFields)).reduce((acc, [k, v]) => {
      acc[k] = sanitizeMetadata(v);
      return acc;
    }, {})
  };

  if (metadata) {
    try {
      meta.starmus_meta = sanitizeMetadata(JSON.stringify(metadata));
    } catch (e) {
      debugLog('[TUS] Metadata sanitization failed', e);
    }
  }

  return new Promise((resolve, reject) => {
    let uploader;

    const opts = {
      endpoint: endpoint,
      chunkSize: cfg.chunkSize || DEFAULT_CONFIG.chunkSize,
      retryDelays: cfg.retryDelays || DEFAULT_CONFIG.retryDelays,
      metadata: meta,

      fingerprint(file /*, options*/) {
        return ['starmus', instanceId, file.size, file.lastModified || ''].join('-');
      },

      onError(error) {
        debugLog('[TUS] Upload error', error);
        reject(error);
      },

      onProgress(uploaded, total) {
        if (typeof onProgress === 'function') {
          onProgress(uploaded, total);
        }
      },

      async onSuccess() {
        debugLog('[TUS] Upload success', uploader.url);

        if (cfg.removeFingerprintOnSuccess && window.tus?.defaultOptions?.removeFingerprint) {
          try {
            const fp = await window.tus.defaultOptions.fingerprint(blob, opts);
            await window.tus.defaultOptions.removeFingerprint(fp);
          } catch (e) {
            debugLog('[TUS] Failed to remove fingerprint', e);
          }
        }

        resolve(uploader.url);
      }
    };

    uploader = new window.tus.Upload(blob, opts);

    uploader.findPreviousUploads()
      .then((previous) => {
        if (previous && previous.length > 0) {
          debugLog('[TUS] Resuming from previous upload', previous[0].uploadUrl);
          uploader.resumeFromPreviousUpload(previous[0]);
        }
      })
      .finally(() => {
        uploader.start();
      });
  });
}

/**
 * Chunked‑REST fallback upload (slices + POST per chunk + finalization)
 */
export async function uploadWithChunkedRest(blob, fileName, formFields = {}, metadata = {}, instanceId = '', onProgress) {
  const cfg = getConfig();
  const endpoint = cfg.chunkedUpload || (window.starmusConfig && window.starmusConfig.endpoints && window.starmusConfig.endpoints.chunkedUpload);
  const nonce = cfg.nonce || (window.starmusConfig && window.starmusConfig.nonce) || '';
  if (!endpoint) {
    throw new Error('CHUNKED_UPLOAD_ENDPOINT_NOT_CONFIGURED');
  }
  if (!nonce) {
    throw new Error('CHUNKED_UPLOAD_NONCE_MISSING');
  }

  const fields = normalizeFormFields(formFields);
  const chunkSize = cfg.chunkSize || DEFAULT_CONFIG.chunkSize;
  const totalChunks = Math.ceil(blob.size / chunkSize);
  const storageKey = `starmus_chunked_${instanceId}_${fileName}_${blob.size}`;
  let uploadId = localStorage.getItem(storageKey) || null;

  for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
    let attempt = 0;
    let success = false;

    const start = chunkIndex * chunkSize;
    const end = Math.min(start + chunkSize, blob.size);
    const chunk = blob.slice(start, end);

    while (!success && attempt < (cfg.maxChunkRetries || DEFAULT_CONFIG.maxChunkRetries)) {
      try {
        await sendChunk(chunkIndex, chunk, fileName, metadata, instanceId, uploadId, fields, endpoint, nonce, onProgress);
        success = true;
      } catch (err) {
        attempt++;
        if (attempt >= (cfg.maxChunkRetries || DEFAULT_CONFIG.maxChunkRetries)) {
          throw err;
        }
        await new Promise(r => setTimeout(r, 300 * attempt * attempt));
      }
    }

    if (!uploadId && chunkIndex === 0 && window.lastUploadId) {
      uploadId = window.lastUploadId;
      localStorage.setItem(storageKey, uploadId);
    }
  }

  // Finalize
  const finalize = new FormData();
  finalize.append('upload_id', uploadId);
  finalize.append('finalize', '1');
  const xhr = new XMLHttpRequest();
  xhr.open('POST', endpoint, true);
  xhr.setRequestHeader('X-WP-Nonce', nonce);
  return new Promise((resolve, reject) => {
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          const json = JSON.parse(xhr.responseText);
          localStorage.removeItem(storageKey);
          resolve(json);
        } catch (e) {
          reject(new Error('Invalid JSON on finalize'));
        }
      } else {
        reject(new Error(`Finalize failed: ${xhr.status}`));
      }
    };
    xhr.onerror = () => reject(new Error('Network error on finalize'));
    xhr.send(finalize);
  });

  async function sendChunk(index, chunkData, fileName, metadata, instanceId, uploadId, fields, endpoint, nonce, onProgress) {
    return new Promise((resolve, reject) => {
      const fd = new FormData();
      fd.append('audio_chunk', chunkData, `${fileName}.part${index}`);
      fd.append('chunk_index', index);
      fd.append('total_chunks', totalChunks);
      if (uploadId) fd.append('upload_id', uploadId);
      else fd.append('create_upload_id', '1');
      Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
      if (metadata) {
        fd.append('_starmus_meta', JSON.stringify(metadata));
      }

      const xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint, true);
      xhr.setRequestHeader('X-WP-Nonce', nonce);

      if (xhr.upload && typeof onProgress === 'function') {
        xhr.upload.onprogress = e => {
          if (e.lengthComputable) {
            onProgress(start + e.loaded, blob.size);
          }
        };
      }

      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const r = JSON.parse(xhr.responseText);
            if (!uploadId && r.upload_id) {
              window.lastUploadId = r.upload_id;
            }
            resolve(r);
          } catch (e) {
            reject(new Error('Invalid JSON chunk response'));
          }
        } else {
          reject(new Error(`Chunk ${index} failed: ${xhr.status}`));
        }
      };

      xhr.onerror = () => reject(new Error('Network error during chunk upload'));
      xhr.send(fd);
    });
  }
}

/**
 * Direct (single‑POST) upload fallback
 */
export async function uploadDirect(blob, fileName, formFields = {}, metadata = {}, _instanceId = '', onProgress) {
  const cfg = getConfig();
  const endpoint = cfg.directUpload || (window.starmusConfig && window.starmusConfig.endpoints && window.starmusConfig.endpoints.directUpload);
  const nonce = cfg.nonce || (window.starmusConfig && window.starmusConfig.nonce) || '';
  if (!endpoint) {
    throw new Error('DIRECT_UPLOAD_ENDPOINT_NOT_CONFIGURED');
  }

  const fields = normalizeFormFields(formFields);

  return new Promise((resolve, reject) => {
    const fd = new FormData();
    Object.entries(fields).forEach(([k,v]) => fd.append(k, v));
    fd.append('audio_file', blob, fileName);

    if (metadata?.transcript) fd.append('_starmus_transcript', metadata.transcript);
    if (metadata?.calibration) fd.append('_starmus_calibration', JSON.stringify(metadata.calibration));
    if (metadata?.env) fd.append('_starmus_env', JSON.stringify(metadata.env));

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
        reject(new Error(`Upload failed: ${xhr.status}`));
      }
    };

    xhr.onerror = () => reject(new Error('Network error on direct upload'));
    xhr.send(fd);
  });
}

/**
 * Tertiary upload wrapper (prioritized)
 *  — TUS
 *  — chunked REST
 *  — direct POST
 */
export async function uploadWithPriority(blob, fileName, formFields, metadata, instanceId, onProgress) {
  try {
    return await uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress);
  } catch (eTus) {
    debugLog('[Uploader] TUS failed, trying fallback', eTus);
    // try chunked REST if configured
    try {
      return await uploadWithChunkedRest(blob, fileName, formFields, metadata, instanceId, onProgress);
    } catch (eChunked) {
      debugLog('[Uploader] Chunked REST failed, trying direct', eChunked);
      return await uploadDirect(blob, fileName, formFields, metadata, instanceId, onProgress);
    }
  }
}

/**
 * Utility: is TUS upload available (library + endpoint + minimum size)
 */
export function isTusAvailable(blobSize = 0) {
  try {
    const cfg = getConfig();
    return !!(window.tus && window.tus.Upload && cfg.endpoint && blobSize > 1024 * 1024);
  } catch {
    return false;
  }
}

export function estimateUploadTime(fileSize, networkInfo) {
  let downlink = networkInfo?.downlink;
  if (!downlink || downlink === 0) {
    const effectiveType = networkInfo?.effectiveType;
    downlink = {
      'slow-2g': 0.05,
      '2g': 0.15,
      '3g': 0.75,
      '4g': 8.0
    }[effectiveType] || 0.5;
  }
  downlink = Math.min(downlink, 10);
  const bytesPerSec = (downlink * 1000000) / 8;
  return Math.ceil((fileSize / bytesPerSec) * 1.5);
}

export function formatUploadEstimate(s) {
  return !isFinite(s) ? 'Calculating...' : (s < 60 ? `~${s}s` : `~${Math.ceil(s/60)} min`);
}

// Global export for WordPress compatibility / legacy code
const StarmusTus = {
  uploadWithTus,
  uploadWithChunkedRest,
  uploadDirect,
  uploadWithPriority,
  isTusAvailable,
  estimateUploadTime,
  formatUploadEstimate
};

if (typeof window !== 'undefined') {
  window.StarmusTus = StarmusTus;
}

export default StarmusTus;
