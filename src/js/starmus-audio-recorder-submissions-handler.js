// FILE: starmus-audio-recorder-submissions-handler.js (HOOKS-INTEGRATED)
/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 * 
 * @module  StarmusSubmissionsHandler
 * @version 1.2.0
 * @file    The Submission Engine - Pure data handling with hooks integration
 */
(function(window, document) {
    'use strict'; 

    const CONFIG = { LOG_PREFIX: '[Starmus Submissions]' };
    function log(level, msg, data) {  
        if (console && console[level]) { 
            console[level](CONFIG.LOG_PREFIX, msg, data || ''); 
        } 
    }
    function el(id) { return document.getElementById(id); }
    function safeId(id) { 
        return typeof id === 'string' && /^[A-Za-z0-9_-]{1,100}$/.test(id); 
    }
    
    function doAction(hook, ...args) {
        if (window.StarmusHooks) {
            window.StarmusHooks.doAction(hook, ...args);
        }
    }

    function applyFilters(hook, value, ...args) {
        return window.StarmusHooks ? 
            window.StarmusHooks.applyFilters(hook, value, ...args) : value;
    }

  function showUserMessage(instanceId, text, type){
    if (!safeId(instanceId)) return;
    const area = el('starmus_recorder_status_'+instanceId) || el('starmus_calibration_status_'+instanceId) || el('starmus_step1_usermsg_'+instanceId);
    if (area) {
      // SECURITY FIX: Always sanitize user messages before displaying
      area.textContent = s(text);
      area.setAttribute('data-status', type||'info');
    }
  }

  // --- Offline Queue (IndexedDB) ---
  const Offline = {
    db:null, name:'StarmusSubmissions', store:'pendingSubmissions',
    init:function(){
      if(!('indexedDB' in window)) { log('warn','IndexedDB missing'); return; }
      try {
        const req=indexedDB.open(this.name,1); const self=this;
        req.onupgradeneeded=function(e){ const db=e.target.result; if(!db.objectStoreNames.contains(self.store)){ db.createObjectStore(self.store,{ keyPath:'id', autoIncrement:true }); } };
        req.onsuccess=function(e){ self.db=e.target.result; log('log','Offline DB ready'); self.processQueue(); };
        req.onerror=function(e){ log('error','IndexedDB error', e && e.target && e.target.errorCode); };
      } catch (err) {
        log('error', 'Could not initialize offline database', err.message);
      }
    },
    add:function(formInstanceId, audioBlob, fileName, formFields, metadata){
      if(!this.db || !safeId(formInstanceId)){ showUserMessage(formInstanceId,'Cannot save offline here.','error'); return; }
      try {
        const tx=this.db.transaction([this.store],'readwrite'); const store=tx.objectStore(this.store);
        const req=store.add({ formInstanceId, fileName, when:Date.now(), audioBlob, formFields: formFields || {}, meta: metadata || {} });
        req.onsuccess=function(){ showUserMessage(formInstanceId,'You are offline. Saved and will auto-send later.','success'); };
        req.onerror=function(){ showUserMessage(formInstanceId,'Failed to save offline.','error'); };
      } catch (err) {
        log('error', 'Failed to add item to offline queue', err.message);
      }
    },
    processQueue:function(){
      if(!this.db || !navigator.onLine) return;
      try {
        const tx=this.db.transaction([this.store],'readwrite'); const store=tx.objectStore(this.store);
        let processedCount = 0;
        store.openCursor().onsuccess=function(e){
          const cur=e.target.result;
          if(cur){
            const item=cur.value;
            setTimeout(function() {
              resumableTusUpload(item.audioBlob, item.fileName, item.formFields, item.meta, item.formInstanceId)
                .then(function(){ showUserMessage(item.formInstanceId,'Queued submission sent.','success'); store.delete(cur.key); })
                .catch(function(err){ log('error','Queued upload failed (will retry later)', err && (err.message || err)); });
            }, processedCount * 1000);
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
    if (!safeId(instanceId)) return Promise.reject(new Error('Invalid instanceId for upload'));
    const tusCfg = window.starmusTus || {};
    if (!window.tus || !tusCfg.endpoint) {
      const wpData = window.starmusFormData || {};
      if (wpData.rest_url && wpData.rest_nonce){
        const fd=new FormData();
        Object.keys(formFields||{}).forEach(function(k){ fd.append(s(k), formFields[k]); });
        fd.append('audio_file', blob, s(fileName) || 'recording');
        if (metadata) fd.append('metadata', JSON.stringify(metadata));
        return fetch(wpData.rest_url,{ method:'POST', headers:{'X-WP-Nonce': wpData.rest_nonce}, body: fd })
          .then(function(res){ if(!res.ok) throw new Error('Direct upload failed: '+res.status); return res; });
      }
      return Promise.reject(new Error('tus missing and no fallback endpoint configured'));
    }
    return new Promise(function(resolve, reject){
      const meta = Object.assign({}, formFields||{});
      meta.filename = s(fileName) || 'recording';
      if (metadata) meta.starmus_meta = JSON.stringify(metadata);
      const uploader = new tus.Upload(blob, {
        endpoint: tusCfg.endpoint,
        chunkSize: tusCfg.chunkSize || 5*1024*1024,
        retryDelays: tusCfg.retryDelays || [0, 3000, 5000, 10000, 20000],
        headers: tusCfg.headers || {},
        metadata: meta,
        onError: function(error){ reject(error); },
        onProgress: function(bytesUploaded, bytesTotal){ const pct = Math.round((bytesUploaded/bytesTotal)*100); showUserMessage(instanceId, 'Uploading… '+pct+'%', 'info'); },
        onSuccess: function(){ showUserMessage(instanceId, 'Upload complete.', 'success'); resolve(uploader.url); }
      });
      uploader.findPreviousUploads().then(function (previousUploads) {
        if (previousUploads.length) { uploader.resumeFromPreviousUpload(previousUploads[0]); }
        uploader.start();
      }).catch(function(err) {
        log('warn', 'Could not resume previous upload, starting new', err.message);
        uploader.start();
      });
    });
  }

  // --- Recorder bootstrap / Tier C reveal ---
  function initRecorder(instanceId){
    if(!safeId(instanceId)) return;
    const recorderModule = window.StarmusAudioRecorder;
    if(!recorderModule || typeof recorderModule.init!=='function'){ revealTierC(instanceId); return; }
    showUserMessage(instanceId, 'Initializing microphone...', 'info');
    recorderModule.init({ formInstanceId: instanceId, srContinuous: CONFIG.SPEECH_CONFIG.continuous, language: CONFIG.SPEECH_CONFIG.language })
      .then(function(r){
        if(r && r.tier==='A'){ showUserMessage(instanceId,'Recorder ready. Use “Setup Mic” for best results.','info'); }
        else { showUserMessage(instanceId,'Recorder ready.','info'); }
      })
      .catch(function(err){ log('error','Engine init failed', err && err.message); revealTierC(instanceId); });
  }

    function revealTierC(instanceId) {
        if (!safeId(instanceId)) return;
        const recWrap = el('starmus_recorder_container_' + instanceId);
        const fb = el('starmus_fallback_container_' + instanceId);
        if (recWrap) recWrap.style.display = 'none';
        if (fb) fb.style.display = 'block';
        doAction('starmus_tier_c_revealed', instanceId);
    }

  // --- Form submit glue ---
  function bindForm(formId){
    if(!safeId(formId)) return;
    const form=el(formId); if(!form) return;
    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      handleSubmit(formId, form);
    });
  }

  function bindContinueButton(formId) {
    if(!safeId(formId)) return;
    const continueBtn = el('starmus_continue_btn_' + formId);
    const step1 = el('starmus_step1_' + formId);
    const step2 = el('starmus_step2_' + formId);
    if (!continueBtn || !step1 || !step2) return;
    continueBtn.addEventListener('click', function() {
        let allValid = true;
        const inputs = step1.querySelectorAll('[required]');
        for (const input of inputs) {
            if (!input.checkValidity()) {
                if (typeof input.reportValidity === 'function') { input.reportValidity(); }
                allValid = false;
                break;
            }
        }
        if (allValid) {
            step1.style.display = 'none';
            step2.style.display = 'block';
            initRecorder(formId);
        }
    });
  }

  function collectFormFields(form){
    const fd=new FormData(form); const obj={};
    fd.forEach(function(v,k){ if (k!=='audio_file') obj[s(k)]=v; });
    return obj;
  }

  function buildMetadata(instanceId){
    if(!safeId(instanceId)) return {};
    const engine = window.StarmusAudioRecorder && window.StarmusAudioRecorder.getSubmissionData;
    const data = engine ? engine(instanceId) : null;
    const meta = { instanceId: instanceId, recordedAt: new Date().toISOString() };
    if (data && data.metadata) { Object.assign(meta, data.metadata); }
    return meta;
  }

    function handleSubmit(instanceId, form) {
        if (!safeId(instanceId)) return;

        const recordingData = window.StarmusAudioRecorder?.getSubmissionData?.(instanceId);
        const fb = el('starmus_fallback_input_' + instanceId);
        let blob = null, fileName = 'recording.webm';

        if (recordingData?.blob) {
            blob = recordingData.blob;
            fileName = recordingData.fileName;
        } else if (fb?.files?.length) {
            blob = fb.files[0];
            fileName = fb.files[0].name;
        }

        if (!blob) {
            doAction('starmus_submission_failed', instanceId, 'No audio');
            return;
        }

        const formFields = collectFormFields(form);
        const metadata = recordingData?.metadata || {};
        
        const submissionPackage = { instanceId, blob, fileName, formFields, metadata };

        // Hook: Allow filters to handle submission (e.g., offline mode)
        const shouldProceed = applyFilters('starmus_before_submit', true, submissionPackage);
        if (!shouldProceed) {
            log('log', 'Submission handled by filter hook');
            return;
        }
        
        doAction('starmus_submission_started', instanceId, submissionPackage);

        resumableTusUpload(blob, fileName, formFields, metadata, instanceId)
            .then(url => {
                doAction('starmus_upload_success', instanceId, url);
                return notifyServer(url, formFields, metadata);
            })
            .then(() => {
                doAction('starmus_submission_complete', instanceId);
            })
            .catch(err => {
                log('error', 'Upload failed', err?.message);
                doAction('starmus_submission_failed', instanceId, err);
                Offline.add(instanceId, blob, fileName, formFields, metadata);
            });
    }

    function notifyServer(tusUrl, formFields, metadata) {
        const wpData = window.starmusFormData || {};
        if (!wpData.rest_url || !wpData.rest_nonce) return Promise.resolve();
        
        const fd = new FormData();
        Object.keys(formFields || {}).forEach(k => fd.append(k, formFields[k]));
        fd.append('tus_url', tusUrl || '');
        fd.append('metadata', JSON.stringify(metadata));
        
        return fetch(wpData.rest_url, {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpData.rest_nonce },
            body: fd
        });
    }

    function collectFormFields(form) {
        const fd = new FormData(form);
        const obj = {};
        fd.forEach((v, k) => { if (k !== 'audio_file') obj[k] = v; });
        return applyFilters('starmus_form_fields', obj, form);
    }

    function initRecorder(instanceId) {
        return new Promise((resolve, reject) => {
            if (!window.StarmusAudioRecorder?.init) {
                revealTierC(instanceId);
                return reject(new Error('Recorder module missing.'));
            }

            window.StarmusAudioRecorder.init({ formInstanceId: instanceId })
                .then(resolve)
                .catch(err => {
                    log('error', 'Engine init failed', err?.message);
                    revealTierC(instanceId);
                    reject(err);
                });
        });
    }

    // --- Init ---
    function init() {
        Offline.init();
        window.addEventListener('online', () => Offline.processQueue());
        
        // Hook into recording events
        doAction('starmus_submissions_handler_ready');
    }
    
    // Initialize when hooks are ready
    if (window.StarmusHooks) {
        window.StarmusHooks.addAction('starmus_hooks_ready', init, 5);
    } else {
        // Fallback if hooks not available
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    }

    // --- Global Interface ---
    window.StarmusSubmissionsHandler = {
        handleSubmit,
        initRecorder,
        Offline
    };

})(window, document);
