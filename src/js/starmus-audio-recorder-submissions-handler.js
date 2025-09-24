// FILE: starmus-audio-recorder-submissions-handler.js (HOOKS-INTEGRATED)
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @module  StarmusSubmissionsHandler 
 * @version 1.2.1
 * @file    The Submission Engine - Pure data handling with hooks integration
 */
(function(window, document) {
    'use strict';

    const CONFIG = { LOG_PREFIX: '[Starmus Submissions]' };
    function log(level, msg, data) { if (console && console[level]) { console[level](CONFIG.LOG_PREFIX, msg, data || ''); } }

    function debugInitBanner() {
        if (!window.isStarmusAdmin) {return;}
        const banner = document.createElement('div');
        banner.textContent = '[Starmus Submissions Handler] JS Initialized';
        banner.style.cssText = 'position:fixed;top:24px;left:0;z-index:99999;background:#2a2;color:#fff;padding:4px 12px;font:14px monospace;opacity:0.95';
        document.body.appendChild(banner);
        setTimeout(() => banner.remove(), 4000);
        log('info', 'DEBUG: Submissions Handler banner shown'); 
    }
    function el(id) { return document.getElementById(id); }
    function safeId(id) { return typeof id === 'string' && /^[A-Za-z0-9_-]{1,100}$/.test(id); }
    function s(str) { return typeof str === 'string' ? str.replace(/[<>"'&]/g, '') : ''; }
    function collectFormFields(form) { const fields = {}; new FormData(form).forEach((value, key) => fields[key] = value); return fields; }
    function doAction(hook, ...args) { if (window.StarmusHooks?.doAction) { window.StarmusHooks.doAction(hook, ...args); } }
    function applyFilters(hook, value, ...args) { return window.StarmusHooks?.applyFilters ? window.StarmusHooks.applyFilters(hook, value, ...args) : value; }

  function showUserMessage(instanceId, text, type){
    log('debug', 'showUserMessage called', { instanceId, text, type });
    if (!safeId(instanceId)) {
      log('warn', 'showUserMessage: unsafe instanceId', instanceId);
      return;
    }
    const area = el('starmus_recorder_status_'+instanceId) || el('starmus_calibration_status_'+instanceId) || el('starmus_step1_usermsg_'+instanceId);
    if (area) {
      area.textContent = String(text || '');
      area.setAttribute('data-status', type||'info');
      log('debug', 'showUserMessage: updated area', area.id);
    } else {
      log('warn', 'showUserMessage: area not found for', instanceId);
    }
  }

  // --- Offline Queue (IndexedDB) ---
  const Offline = {
    db: null, name:'StarmusSubmissions', store:'pendingSubmissions',
    init: function() {
        if (!('indexedDB' in window)) { log('warn','IndexedDB missing'); return; }
        try {
            const req = indexedDB.open(this.name, 1);
            const self = this;
            req.onupgradeneeded = function(e){ const db=e.target.result; if(!db.objectStoreNames.contains(self.store)){ db.createObjectStore(self.store,{ keyPath:'id', autoIncrement:true }); } };
            req.onsuccess = function(e){
                self.db = e.target.result;
                log('log','Offline DB ready');
                // FIX: Do not process the queue immediately. Wait 60 seconds to avoid the rate-limit loop.
                log('info', 'Queue processing for any old submissions will begin in 60 seconds.');
                setTimeout(() => self.processQueue(), 60000);
            };
            req.onerror = function(e){ log('error','IndexedDB error', e?.target?.errorCode); };
        } catch (err) {
            log('error', 'Could not initialize offline database', err.message);
        }
    },
    add: function(formInstanceId, audioBlob, fileName, formFields, metadata){
        if(!this.db || !safeId(formInstanceId)){ showUserMessage(formInstanceId,'Cannot save offline here.','error'); return; }
        try {
            const tx = this.db.transaction([this.store],'readwrite'); const store=tx.objectStore(this.store);
            const req = store.add({ formInstanceId, fileName, when:Date.now(), audioBlob, formFields: formFields || {}, meta: metadata || {} });
            req.onsuccess = function(){ showUserMessage(formInstanceId,'You are offline. Saved and will auto-send later.','success'); };
            req.onerror = function(){ showUserMessage(formInstanceId,'Failed to save offline.','error'); };
        } catch (err) {
            log('error', 'Failed to add item to offline queue', err.message);
        }
    },
    processQueue: function(){
        if(!this.db || !navigator.onLine) {return;}
        log('info', 'Processing offline queue...');
        try {
            const tx = this.db.transaction([this.store],'readwrite'); const store=tx.objectStore(this.store);
            let processedCount = 0;
            store.openCursor().onsuccess = function(e){
                const cur = e.target.result;
                if(cur){
                    const item = cur.value;
                    // FIX: Increase the delay between each retry to be kinder to the server.
                    setTimeout(function() {
                        resumableTusUpload(item.audioBlob, item.fileName, item.formFields, item.meta, item.formInstanceId)
                            .then(function(){ showUserMessage(item.formInstanceId,'Queued submission sent.','success'); store.delete(cur.key); })
                            .catch(function(err){ log('error','Queued upload failed (will retry later)', err?.message || err); });
                    }, processedCount * 5000); // Stagger retries by 5 seconds
                    processedCount++;
                    cur.continue();
                }
            };
        } catch (err) {
            log('error', 'Failed to process offline queue', err.message);
        }
    }
};



  // --- tus uploader ---
  function resumableTusUpload(blob, fileName, formFields, metadata, instanceId){
        log('info', 'resumableTusUpload called', { instanceId, fileName });
        if (!safeId(instanceId)) {
          return Promise.reject(new Error('Invalid instanceId for upload'));
        }
        const tusCfg = window.starmusTus || {};
        if (!window.tus || !tusCfg.endpoint) {
          log('warn', 'TUS not configured, falling back to standard REST upload.');
          const wpData = window.starmusFormData || {};
          if (wpData.rest_url && wpData.rest_nonce){
            const fd = new FormData();

            // Add the nonce to the form data body, as expected by the PHP.
            fd.append('_wpnonce', wpData.rest_nonce);

            if (wpData.user_id) { fd.append('user_id', wpData.user_id); }
            Object.keys(formFields||{}).forEach(function(k){
              const value = formFields[k];
              if (value !== null && value !== undefined && value !== '') {
                fd.append(s(k), value);
              }
            });
            fd.append('audio_file', blob, s(fileName) || 'recording.webm');
            if (metadata) { fd.append('metadata', JSON.stringify(metadata)); }

            // *** THE FIX: Point to the new, simpler fallback URL ***
            const fallbackUrl = wpData.rest_url.replace('/upload-chunk', '/upload-fallback');
            log('debug', 'Using fallback URL:', fallbackUrl);

            // Use the new URL and remove the unnecessary X-WP-Nonce header
            return fetch(fallbackUrl, { method:'POST', body: fd })
              .then(function(res){
                if(!res.ok) {
                  return res.text().then(errorText => {
                    log('error', 'Server response:', { status: res.status, statusText: res.statusText, body: errorText });
                    throw new Error(`Upload failed: ${res.status} - ${errorText}`);
                  });
                }
                log('info', 'Direct upload success');
                return res.json(); // Assume server sends back JSON on success
              });
          }
          return Promise.reject(new Error('Fallback REST endpoint not configured.'));
        }

        // The TUS upload logic (the second half of this function) remains completely unchanged.
        return new Promise(function(resolve, reject){
          const meta = Object.assign({}, formFields||{});
          meta.filename = s(fileName) || 'recording';
          if (metadata) {meta.starmus_meta = JSON.stringify(metadata);}
          log('info', 'Starting tus upload', meta);
          const uploader = new tus.Upload(blob, {
            endpoint: tusCfg.endpoint,
            chunkSize: tusCfg.chunkSize || 5*1024*1024,
            retryDelays: tusCfg.retryDelays || [0, 3000, 5000, 10000, 20000],
            headers: tusCfg.headers || {},
            metadata: meta,
            onError: function(error){ log('error', 'tus upload error', error); reject(error); },
            onProgress: function(bytesUploaded, bytesTotal){ const pct = Math.round((bytesUploaded/bytesTotal)*100); log('debug', 'tus upload progress', { pct, bytesUploaded, bytesTotal }); showUserMessage(instanceId, 'Uploading… '+pct+'%', 'info'); },
            onSuccess: function(){ log('info', 'tus upload complete'); showUserMessage(instanceId, 'Upload complete.', 'success'); resolve(uploader.url); }
          });
          uploader.findPreviousUploads().then(function (previousUploads) {
            if (previousUploads.length) { log('info', 'Resuming previous tus upload'); uploader.resumeFromPreviousUpload(previousUploads[0]); }
            uploader.start();
          }).catch(function(err) {
            log('warn', 'Could not resume previous upload, starting new', err.message);
            uploader.start();
          });
        });
    }

  // --- Recorder bootstrap / Tier C reveal ---
  function initRecorder(instanceId){
    log('info', 'initRecorder called', instanceId);
    return new Promise((resolve, reject) => {
      if(!safeId(instanceId)) {
        log('warn', 'initRecorder: unsafe instanceId', instanceId);
        reject(new Error('Unsafe instanceId'));
        return;
      }
      const recorderModule = window.StarmusAudioRecorder;
      if(!recorderModule || typeof recorderModule.init!=='function'){
        log('warn', 'initRecorder: recorder module missing or invalid');
        revealTierC(instanceId);
        reject(new Error('Recorder module missing or invalid'));
        return;
      }
      // FIX: Correctly check the debug _instances object for an existing instance.
      if (recorderModule._instances && recorderModule._instances[instanceId]) {
        log('info', 'initRecorder: recorder already initialized for', instanceId);
        return resolve(true);
      }
      showUserMessage(instanceId, 'Initializing microphone...', 'info');
      recorderModule.init({ formInstanceId: instanceId })
        .then(function(r){
          log('info', 'Recorder module initialized', r);
          if(r && r.tier==='A'){ showUserMessage(instanceId,'Recorder ready. Use “Setup Mic” for best results.','info'); }
          else { showUserMessage(instanceId,'Recorder ready.','info'); }
          resolve(r);
        })
        .catch(function(err){
          log('error','Engine init failed', err && err.message);
          revealTierC(instanceId);
          reject(err);
        });
    });
  }

    function revealTierC(instanceId) {
        if (!safeId(instanceId)) {return;}
        const recWrap = el('starmus_recorder_container_' + instanceId);
        const fb = el('starmus_fallback_container_' + instanceId);
        if (recWrap) {recWrap.style.display = 'none';}
        if (fb) {fb.style.display = 'block';}
        doAction('starmus_tier_c_revealed', instanceId);
    }

    // REMOVED: Redundant _bindForm and _bindContinueButton functions.
    // This responsibility lies solely with the UI Controller.



  function _buildMetadata(_instanceId){
    if(!safeId(_instanceId)) {return {};}
    const engine = window.StarmusAudioRecorder && window.StarmusAudioRecorder.getSubmissionData;
    const data = engine ? engine(_instanceId) : null;
    const meta = { instanceId: _instanceId, recordedAt: new Date().toISOString() };
    if (data && data.metadata) { Object.assign(meta, data.metadata); }
    return meta;
  }

    function handleSubmit(instanceId, form) {
        log('info', 'handleSubmit called', { instanceId, form });
        if (!safeId(instanceId)) {
            log('warn', 'handleSubmit: unsafe instanceId', instanceId);
            return Promise.reject(new Error('Unsafe instanceId'));
        }

        // Disable submit button to prevent multiple clicks
        const submitBtn = el(`starmus_submit_btn_${instanceId}`);
        const originalText = submitBtn?.textContent;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
        }
    const recordingData = window.StarmusAudioRecorder?.getSubmissionData?.(instanceId);
    const fb = el('starmus_fallback_input_' + instanceId);
    let blob = null, fileName = 'recording.webm';
    if (recordingData?.blob) {
      log('info', 'handleSubmit: got blob from recorder', recordingData);
      blob = recordingData.blob;
      fileName = recordingData.fileName;
    } else if (fb?.files?.length) {
      log('info', 'handleSubmit: got blob from fallback input', fb.files[0]);
      blob = fb.files[0];
      fileName = fb.files[0].name;
    }
    if (!blob) {
      log('error', 'handleSubmit: no audio blob found');
      doAction('starmus_submission_failed', instanceId, 'No audio');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText || 'Submit';
      }
      return Promise.reject(new Error('No audio blob found'));
    }
    // Validate minimum recording length - allow single words (1 second minimum)
    const metadata = recordingData?.metadata || {};
    const duration = metadata.duration || 0;
    if (duration < 1000) {
      log('warn', 'handleSubmit: recording too short', duration);
      showUserMessage(instanceId, 'Recording too short. Please record at least 1 second.', 'error');
      doAction('starmus_submission_failed', instanceId, 'Recording too short');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText || 'Submit';
      }
      return Promise.reject(new Error('Recording too short'));
    }
    // Check recording quality if available
    const quality = window.StarmusAudioRecorder?.getRecordingQuality?.(instanceId);
    if (quality?.quality === 'poor') {
      const warnings = quality.warnings.join(', ');
      log('warn', 'handleSubmit: poor recording quality', warnings);
      showUserMessage(instanceId, `Recording quality may be poor: ${warnings}. Continue anyway?`, 'warning');
      doAction('starmus_quality_warning', instanceId, quality);
    }
    // Get recording type from form for context-aware validation
    const recordingTypeSelect = form.querySelector(`select[name="recording_type"]`);
    const recordingTypeText = recordingTypeSelect?.selectedOptions[0]?.text || 'unknown';
    // Validate this is a West African language (not recognized by Speech API)
    const languageValidation = window.StarmusAudioRecorder?.validateWestAfricanLanguage?.(instanceId, recordingTypeText);
    if (languageValidation && !languageValidation.isValid) {
      log('warn', 'handleSubmit: language validation failed', languageValidation);
      showUserMessage(instanceId, `Warning: ${languageValidation.reason}. This corpus is for West African languages only.`, 'error');
      doAction('starmus_language_validation_failed', instanceId, languageValidation);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText || 'Submit';
      }
      return Promise.reject(new Error('Language validation failed'));
    }
    if (languageValidation?.isValid) {
      log('info', 'handleSubmit: language validation passed', languageValidation);
      doAction('starmus_west_african_language_confirmed', instanceId, languageValidation);
    }
    const formFields = collectFormFields(form);
    // Add transcript data to form fields (UTC normalized)
    if (metadata.transcript && metadata.transcript.length > 0) {
      log('info', 'handleSubmit: adding transcript to formFields');
  formFields['first_pass_transcription'] = JSON.stringify({
        transcript: metadata.transcript, // Already has UTC timestamps
        detectedLanguage: metadata.detectedLanguage,
        hasTranscription: metadata.hasTranscription,
        recordedAt: metadata.recordedAt, // UTC ISO string
        timezone: metadata.timezone // For local display conversion
      });
    }
    // Add comprehensive recording metadata for linguistic corpus
    formFields['recording_metadata'] = JSON.stringify({
      identifiers: {
        sessionUUID: metadata.sessionUUID,
        submissionUUID: metadata.submissionUUID,
        formInstanceId: instanceId
      },
      technical: {
        duration: metadata.duration,
        sampleRate: metadata.sampleRate,
        codec: metadata.codec,
        fileSize: metadata.fileSize,
        audioProcessing: metadata.audioProcessing
      },
      device: {
        userAgent: metadata.userAgent,
        platform: metadata.platform,
        screenResolution: metadata.screenResolution,
        deviceMemory: metadata.deviceMemory,
        hardwareConcurrency: metadata.hardwareConcurrency,
        connection: metadata.connection
      },
      linguistic: {
        browserLanguage: metadata.language,
        browserLanguages: metadata.languages,
        detectedLanguage: metadata.detectedLanguage,
        speechRecognitionAvailable: metadata.speechRecognitionAvailable,
        westAfricanValidation: languageValidation
      },
      temporal: {
        recordedAt: metadata.recordedAt, // UTC ISO string
        recordedAtLocal: metadata.recordedAtLocal, // Local reference
        timezone: metadata.timezone, // For display conversion
        submittedAt: new Date().toISOString() // UTC submission time
      }
    });
    const submissionPackage = { instanceId, blob, fileName, formFields, metadata };
    // Hook: Allow filters to handle submission (e.g., offline mode)
    const shouldProceed = applyFilters('starmus_before_submit', true, submissionPackage);
    if (!shouldProceed) {
      log('info', 'handleSubmit: submission handled by filter hook');
      doAction('starmus_submission_queued', instanceId, submissionPackage);
      return Promise.resolve('Handled by filter');
    }
    log('info', 'handleSubmit: starting upload', submissionPackage);
    doAction('starmus_submission_started', instanceId, submissionPackage);
    return resumableTusUpload(blob, fileName, formFields, metadata, instanceId)
      .then(url => {
        log('info', 'handleSubmit: upload success', url);
        doAction('starmus_upload_success', instanceId, url);
        return notifyServer(url, formFields, metadata);
      })
      .then(() => {
        log('info', 'handleSubmit: submission complete');
        doAction('starmus_submission_complete', instanceId, submissionPackage);
        if (submitBtn) {
          submitBtn.textContent = 'Submitted!';
          setTimeout(() => {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText || 'Submit';
          }, 2000);
        }
      })
     .catch(err => {
    log('error', 'handleSubmit: an error occurred during submission', { message: err?.message, instanceId });

    // Check if the user is ACTUALLY offline.
    if (!navigator.onLine) {
        // The browser says we are offline, so save the submission.
        showUserMessage(instanceId, 'You seem to be offline. Your submission has been saved and will be sent automatically when you reconnect.', 'info');
        Offline.add(instanceId, blob, fileName, formFields, metadata);
    } else {
        // The user is online, so the problem must be the server.
        // Give a more accurate error message.
        showUserMessage(instanceId, 'Submission failed. The server responded with an error. Please try again later.', 'error');
    }

    // Always re-enable the button on any failure.
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText || 'Submit Recording';
    }

    doAction('starmus_submission_failed', instanceId, err);
    // We still throw the error for other parts of the system that might need to know about it.
    throw err;
});
    }

    function notifyServer(tusUrl, formFields, metadata) {
  const wpData = window.starmusFormData || {};
  if (!wpData.rest_url || !wpData.rest_nonce) {
    return Promise.resolve();
  }

  const fd = new FormData();
  Object.keys(formFields || {}).forEach(k => fd.append(k, formFields[k]));
  fd.append('tus_url', tusUrl || '');
  fd.append('metadata', JSON.stringify(metadata));

  if (formFields['first_pass_transcription']) {
    fd.append('first_pass_transcription', formFields['first_pass_transcription']);
  }

  // force notify to fallback route
  const notifyUrl = wpData.rest_url.replace('/upload-chunk', '/upload-fallback');

  return fetch(notifyUrl, {
    method: 'POST',
    headers: { 'X-WP-Nonce': wpData.rest_nonce },
    body: fd
  });
}

    // --- Init ---
    function init() {
    log('info', 'SubmissionsHandler init called');
    debugInitBanner();
    Offline.init();
    window.addEventListener('online', () => {
      log('info', 'Network online event');
      doAction('starmus_network_online');
      Offline.processQueue();
    });
    window.addEventListener('offline', () => {
      log('info', 'Network offline event');
      doAction('starmus_network_offline');
    });
    // Hook into recording events
    log('info', 'Firing starmus_submissions_handler_ready');
    doAction('starmus_submissions_handler_ready');
    }

    // Initialize directly when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // --- Global Interface ---
    window.StarmusSubmissionsHandler = {
        init,
        handleSubmit,
        initRecorder
    };

})(window, document);
