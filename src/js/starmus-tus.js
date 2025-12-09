/**
 * @file starmus-transcript-controller.js
 * @version 1.2.0
 * @description Handles the "karaoke-style" transcript panel that syncs with audio playback.
 * Provides click-to-seek, auto-scroll with user-scroll detection, and confidence indicators.
 */

'use strict';

// NEW (Corrected)
import './starmus-hooks.js'; 
const debugLog = window.StarmusHooks.debugLog; 
// ------------------------------------------------------------
// CONFIG
// ------------------------------------------------------------

const DEFAULT_CONFIG = {
  chunkSize: 1 * 1024 * 1024,
  retryDelays: [0, 3000, 5000, 10000, 20000, 60000],
  removeFingerprintOnSuccess: true,
  maxChunkRetries: 3
};

const getConfig = () => window.starmusConfig || {};

function sanitizeMetadata(v) {
  v = typeof v === 'string' ? v : String(v);
  v = v.replace(/[\r\n\t]/g, ' ');
  return v.length > 500 ? v.slice(0, 497) + '...' : v;
}

function normalizeFormFields(f) {
  return f && typeof f === 'object' ? f : {};
}

function getChunkedUploadStorageKey(id, name, size) {
  return `starmus_chunked_${id}_${name}_${size}`;
}

// ------------------------------------------------------------
// MASTER PRIORITY WRAPPER
// ------------------------------------------------------------

export async function uploadWithPriority(blob, fileName, formFields, metadata, instanceId, onProgress) {
  const cfg = getConfig();
  const tusAvailable = window.tus?.Upload && cfg.endpoints?.tusUpload;
  const chunked = cfg.endpoints?.chunkedUpload;
  const direct = cfg.endpoints?.directUpload;

  if (tusAvailable) {
    try {
      const url = await uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress);
      return { method: 'tus', url };
    } catch (e) {
      debugLog('[Priority] TUS failed:', e.message);
    }
  }

  if (chunked) {
    try {
      const res = await uploadWithChunkedRest(blob, fileName, formFields, metadata, instanceId, onProgress);
      return { method: 'chunked_rest', result: res };
    } catch (e) {
      debugLog('[Priority] Chunked REST failed:', e.message);
    }
  }

  if (direct) {
    const res = await uploadDirect(blob, fileName, formFields, metadata, instanceId, onProgress);
    return { method: 'fallback_post', result: res };
  }

  throw new Error('All upload methods failed or missing endpoints');
}

// ------------------------------------------------------------
// TUS UPLOAD (P1)
// ------------------------------------------------------------

export function uploadWithTus(blob, fileName, formFields, metadata, instanceId, onProgress) {
  if (!window.tus?.Upload) throw new Error('TUS library not present');

  const cfg = window.starmusTus || {};
  const endpoint = cfg.endpoint || getConfig().endpoints?.tusUpload;
  if (!endpoint) throw new Error('TUS endpoint missing');

  const metadataObj = {
    filename: sanitizeMetadata(fileName),
    filetype: blob.type || 'audio/webm',
    ...Object.entries(normalizeFormFields(formFields)).reduce((a,[k,v])=> (a[k]=sanitizeMetadata(v),a),{})
  };

  if (metadata) metadataObj.starmus_meta = sanitizeMetadata(JSON.stringify(metadata));

  return new Promise((resolve, reject) => {
    let uploader;

    const opts = {
      endpoint,
      chunkSize: cfg.chunkSize || DEFAULT_CONFIG.chunkSize,
      retryDelays: cfg.retryDelays || DEFAULT_CONFIG.retryDelays,
      metadata: metadataObj,

      fingerprint(file) {
        return ['starmus', instanceId, file.size, file.lastModified || ''].join('-');
      },

      // 🔥 FIX #1 — safe onProgress wrapper
      onProgress(bytesUploaded, bytesTotal) {
        if (typeof onProgress === 'function') {
          onProgress(bytesUploaded, bytesTotal);
        }
      },

      onError: reject,

      async onSuccess() {
        debugLog('[TUS] Success:', uploader.url);

        // 🔥 FIX #2 — version-tolerant fingerprint removal
        const tusRemove =
          window.tus?.defaultOptions?.removeFingerprint ||
          window.tus?.Upload?.prototype?.options?.removeFingerprint;

        if (cfg.removeFingerprintOnSuccess && tusRemove) {
          try {
            const fp = await window.tus.defaultOptions.fingerprint(blob, opts);
            await tusRemove(fp);
          } catch {}
        }

        resolve(uploader.url);
      }
    };

    uploader = new window.tus.Upload(blob, opts);

    uploader.findPreviousUploads()
      .then(prev => prev.length && uploader.resumeFromPreviousUpload(prev[0]))
      .finally(() => uploader.start());
  });
}

// ------------------------------------------------------------
// CHUNKED REST UPLOAD (P2) — runtime retry config honored
// ------------------------------------------------------------

export function uploadWithChunkedRest(blob, fileName, formFields, metadata, instanceId, onProgress) {
  const cfg = getConfig();
  const endpoint = cfg.endpoints?.chunkedUpload;
  const nonce = cfg.nonce || '';
  if (!endpoint) throw new Error('Chunked REST endpoint missing');
  if (!nonce) throw new Error('Nonce missing');

  const fields = normalizeFormFields(formFields);
  const chunkSize = cfg.chunkSize || DEFAULT_CONFIG.chunkSize;
  const totalChunks = Math.ceil(blob.size / chunkSize);

  const storage = window.localStorage || window.sessionStorage;
  const storageKey = getChunkedUploadStorageKey(instanceId, fileName, blob.size);
  let uploadId = storage.getItem(storageKey) || null;

  const maxRetries = cfg.maxChunkRetries ?? DEFAULT_CONFIG.maxChunkRetries; // 🔥 FIX #3

  return new Promise(async (resolve, reject) => {
    try {
      for (let i = 0; i < totalChunks; i++) {
        let attempt = 0;
        let success = false;
        const start = i * chunkSize;
        const end = Math.min(start + chunkSize, blob.size);
        const chunk = blob.slice(start, end);

        while (!success && attempt < maxRetries) {
          attempt++;
          try {
            await sendChunk(i, chunk, start);
            success = true;
          } catch (err) {
            if (attempt === maxRetries) throw err;

            // exponential backoff retained
            await new Promise(r => setTimeout(r, 300 * attempt * attempt));
          }
        }
      }

      const finalize = new FormData();
      finalize.append('upload_id', uploadId);
      finalize.append('finalize', '1');

      const r = await fetch(endpoint, { method:'POST', headers:{'X-WP-Nonce':nonce}, body:finalize });
      if (!r.ok) throw new Error(`Finalization failed: ${r.status}`);
      const json = await r.json();

      storage.removeItem(storageKey);
      resolve(json);

    } catch (e) { reject(e); }

    function sendChunk(index, chunk, start) {
      return new Promise((res, rej) => {
        const fd = new FormData();
        fd.append('audio_chunk', chunk, `${fileName}.part${index}`);
        fd.append('chunk_index', index);
        fd.append('total_chunks', totalChunks);
        uploadId ? fd.append('upload_id', uploadId) : fd.append('create_upload_id', '1');

        Object.entries(fields).forEach(([k,v]) => fd.append(k,v));
        if (metadata) fd.append('_starmus_meta', JSON.stringify(metadata));

        const xhr = new XMLHttpRequest();
        xhr.open('POST', endpoint, true);
        xhr.setRequestHeader('X-WP-Nonce', nonce);

        xhr.upload.onprogress = e =>
          typeof onProgress === 'function' && e.lengthComputable &&
          onProgress(start + e.loaded, blob.size);

        xhr.onload = () => {
          if (xhr.status < 200 || xhr.status >= 300)
            return rej(new Error(`Chunk ${index} failed: ${xhr.status}`));
          try {
            const json = JSON.parse(xhr.responseText);
            if (!uploadId && json.upload_id) {
              uploadId = json.upload_id;
              storage.setItem(storageKey, uploadId);
            }
            res(json);
          } catch {
            rej(new Error('Invalid JSON response'));
          }
        };

        xhr.onerror = () => rej(new Error('Network error'));
        xhr.send(fd);
      });
    }
  });
}

// ------------------------------------------------------------
// DIRECT UPLOAD (P3)
// ------------------------------------------------------------

export function uploadDirect(blob, fileName, formFields, metadata, _id, onProgress) {
  const cfg = getConfig();
  const endpoint = cfg.endpoints?.directUpload;
  const nonce = cfg.nonce || '';
  if (!endpoint) throw new Error('Direct upload endpoint missing');

  const fields = normalizeFormFields(formFields);

  return new Promise((res, rej) => {
    const fd = new FormData();
    Object.entries(fields).forEach(([k,v]) => fd.append(k,v));
    fd.append('audio_file', blob, fileName);

    if (metadata?.transcript) fd.append('_starmus_transcript', metadata.transcript);
    if (metadata?.calibration) fd.append('_starmus_calibration', JSON.stringify(metadata.calibration));
    if (metadata?.env) fd.append('_starmus_env', JSON.stringify(metadata.env));

    const xhr = new XMLHttpRequest();
    xhr.open('POST', endpoint, true);
    xhr.setRequestHeader('X-WP-Nonce', nonce);

    xhr.upload.onprogress = e =>
      typeof onProgress === 'function' && e.lengthComputable &&
      onProgress(e.loaded, e.total);

    xhr.onload = () => {
      if (xhr.status < 200 || xhr.status >= 300)
        return rej(new Error(`Upload failed: ${xhr.status}`));
      try { res(JSON.parse(xhr.responseText)); }
      catch { rej(new Error('Invalid JSON response')); }
    };

    xhr.onerror = () => rej(new Error('Network error'));
    xhr.send(fd);
  });
}

// ------------------------------------------------------------
// HELPERS
// ------------------------------------------------------------

export function estimateUploadTime(bytes, net) {
  let d = net?.downlink || ({
    'slow-2g':0.05, '2g':0.15, '3g':0.75, '4g':8.0
  }[net?.effectiveType] ?? 0.5);
  d = Math.min(d,10);
  return Math.ceil((bytes/((d*1e6)/8))*1.5);
}

export const formatUploadEstimate = s =>
  !isFinite(s) ? 'Calculating...' :
  s<60 ? `~${s}s` : `~${Math.ceil(s/60)} min`;

// FINAL EXPORT TARGET (BROWSER + WORDPRESS SAFE)
const StarmusTus = {
  uploadWithTus,
  uploadWithChunkedRest,
  uploadDirect,
  uploadWithPriority,
  estimateUploadTime,
  formatUploadEstimate
};

// Browser global for WordPress
if (typeof window !== 'undefined') {
  window.StarmusTus = StarmusTus;
}

// ES module + CommonJS export
export default StarmusTus;


