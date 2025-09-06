/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * @package Starmus\submissions
 * @since 0.1.0
 * @version 0.7.0
 * @file Submission Controller — Offline-First queue, Tier C fallback, tus.io resumable uploads.
 */
(function(window, document){
  'use strict';

  // --- Config / Utils ---
  var CONFIG={ LOG_PREFIX:'[Starmus Controller]' };

  // Expected global (configure in theme or page):
  // window.starmusTus = {
  //   endpoint: 'https://your-tus-endpoint/files/', // REQUIRED (tus-php or other server)
  //   headers: { },                                 // optional auth headers
  //   chunkSize: 5*1024*1024,                       // 5MB default
  //   retryDelays: [0, 3000, 5000, 10000, 20000]    // exponential backoff-ish
  // };

  function s(v){ try{return String(v).replace(/[\r\n\t<>]/g,' ').slice(0,500);}catch(_){return'';} }
  function log(level,msg,data){ if(!console||!console[level]) return; console[level](CONFIG.LOG_PREFIX, s(msg), data? s(data):''); }
  function el(id){ return document.getElementById(id); }
  function safeId(id){ return typeof id==='string' && /^[A-Za-z0-9_-]{1,100}$/.test(id); }
  function showUserMessage(instanceId, text, type){
    var area = document.getElementById('starmus_recorder_status_'+instanceId) || document.getElementById('starmus_calibration_status_'+instanceId);
    if (area) { area.textContent = text; area.setAttribute('data-status', type||'info'); }
  }

  // Encodes metadata to tus-friendly header format (base64 values)
  function tusMeta(obj){
    var out = {};
    Object.keys(obj||{}).forEach(function(k){
      var v = obj[k];
      if (v === undefined || v === null) return;
      out[k] = btoa(unescape(encodeURIComponent(String(v))));
    });
    return out;
  }

  // --- Offline Queue (IndexedDB) ---
  var Offline = {
    db:null, name:'StarmusSubmissions', store:'pendingSubmissions',
    init:function(){
      if(!('indexedDB' in window)) { log('warn','IndexedDB missing'); return; }
      var req=indexedDB.open(this.name,1); var self=this;
      req.onupgradeneeded=function(e){
        var db=e.target.result;
        if(!db.objectStoreNames.contains(self.store)){
          db.createObjectStore(self.store,{ keyPath:'id', autoIncrement:true });
        }
      };
      req.onsuccess=function(e){ self.db=e.target.result; log('log','Offline DB ready'); self.processQueue(); };
      req.onerror=function(e){ log('error','IndexedDB error', e && e.target && e.target.errorCode); };
    },
    add:function(formInstanceId, audioBlob, fileName, formFields, metadata){
      if(!this.db){ showUserMessage(formInstanceId,'Cannot save offline here.','error'); return; }
      var tx=this.db.transaction([this.store],'readwrite'); var store=tx.objectStore(this.store);
      var req=store.add({
        formInstanceId:formInstanceId,
        fileName:fileName,
        when:Date.now(),
        audioBlob:audioBlob,
        formFields: formFields || {},
        meta: metadata || {}
      });
      req.onsuccess=function(){ showUserMessage(formInstanceId,'You are offline. Saved and will auto-send later.','success'); };
      req.onerror=function(){ showUserMessage(formInstanceId,'Failed to save offline.','error'); };
    },
    processQueue:function(){
      if(!this.db || !navigator.onLine) return;
      var tx=this.db.transaction([this.store],'readwrite'); var store=tx.objectStore(this.store); var self=this;
      store.openCursor().onsuccess=function(e){
        var cur=e.target.result;
        if(cur){
          var item=cur.value;
          resumableTusUpload(item.audioBlob, item.fileName, item.formFields, item.meta, item.formInstanceId)
            .then(function(){
              showUserMessage(item.formInstanceId,'Queued submission sent.','success');
              store.delete(cur.key);
            })
            .catch(function(err){
              log('error','Queued upload failed (will retry later)', err && (err.message || err));
              // leave it in the queue
            });
          cur.continue();
        }
      };
    }
  };

  // --- tus uploader (resumable). Falls back to direct form POST if tus is absent. ---
  function resumableTusUpload(blob, fileName, formFields, metadata, instanceId){
    var tusCfg = window.starmusTus || {};
    if (!window.tus || !tusCfg.endpoint) {
      // Fallback single-shot POST to WP endpoint if configured
      if (window.starmusFormData && starmusFormData.rest_url && starmusFormData.rest_nonce){
        var fd=new FormData();
        Object.keys(formFields||{}).forEach(function(k){ fd.append(k, formFields[k]); });
        fd.append('audio_file', blob, fileName || 'recording');
        if (metadata) fd.append('metadata', JSON.stringify(metadata));
        return fetch(starmusFormData.rest_url,{ method:'POST', headers:{'X-WP-Nonce': starmusFormData.rest_nonce}, body: fd })
          .then(function(res){ if(!res.ok) throw new Error('Direct upload failed: '+res.status); return res; });
      }
      return Promise.reject(new Error('tus missing and no fallback endpoint configured'));
    }

    return new Promise(function(resolve, reject){
      // Build tus metadata: filename + any custom fields + JSON metadata
      var meta = Object.assign({}, formFields||{});
      meta.filename = fileName || 'recording';
      if (metadata) meta.starmus_meta = JSON.stringify(metadata);

      var uploader = new tus.Upload(blob, {
        endpoint: tusCfg.endpoint,
        chunkSize: tusCfg.chunkSize || 5*1024*1024,
        retryDelays: tusCfg.retryDelays || [0, 3000, 5000, 10000, 20000],
        headers: tusCfg.headers || {},
        metadata: meta,
        onError: function(error){ reject(error); },
        onProgress: function(bytesUploaded, bytesTotal){
          var pct = Math.floor((bytesUploaded/bytesTotal)*100);
          showUserMessage(instanceId, 'Uploading… '+pct+'%', 'info');
        },
        onSuccess: function(){
          showUserMessage(instanceId, 'Upload complete.', 'success');
          resolve(uploader.url);
        }
      });

      // Resume if possible (fingerprint by default uses blob + endpoint)
      uploader.findPreviousUploads().then(function (previousUploads) {
        if (previousUploads.length) {
          uploader.resumeFromPreviousUpload(previousUploads[0]);
        }
        uploader.start();
      });
    });
  }

  // --- Recorder bootstrap / Tier C reveal ---
  function initRecorder(instanceId){
    if(!safeId(instanceId)) return;
    if(!window.StarmusAudioRecorder || typeof window.StarmusAudioRecorder.init!=='function'){ revealTierC(instanceId); return; }
    window.StarmusAudioRecorder.init({ formInstanceId: instanceId, srContinuous:true, language:'en-US' })
      .then(function(r){
        if(r && r.tier==='A'){ showUserMessage(instanceId,'Recorder ready. Use “Setup Mic” for best results.','info'); }
        else { showUserMessage(instanceId,'Recorder ready (compat mode).','info'); }
      })
      .catch(function(err){ log('error','Engine init failed', err && err.message); revealTierC(instanceId); });
  }

  function revealTierC(instanceId){
    var recWrap=el('starmus_recorder_container_'+instanceId);
    var fb=el('starmus_fallback_container_'+instanceId);
    if(recWrap) recWrap.style.display='none';
    if(fb) fb.style.display='block';
    showUserMessage(instanceId,'Live recording not supported. Use file upload below.','warn');
  }

  // --- Form submit glue ---
  function bindForm(formId){
    var form=el(formId); if(!form) return; var instanceId=formId;
    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      handleSubmit(instanceId, form);
    });
  }

  function collectFormFields(form){
    var fd=new FormData(form); var obj={};
    fd.forEach(function(v,k){ if (k!=='audio_file') obj[k]=v; });
    return obj;
  }

  function buildMetadata(instanceId){
    var engine=window.StarmusAudioRecorder && window.StarmusAudioRecorder.instances && window.StarmusAudioRecorder.instances[instanceId];
    var meta = {
      instanceId: instanceId,
      recordedAt: new Date().toISOString()
    };
    if (engine){
      meta.mime = engine.audioBlob && engine.audioBlob.type;
      meta.durationMs = Math.max(0, Date.now() - (engine.startMs || Date.now()));
      if (engine.tier==='A' && Array.isArray(engine.segments)) meta.languageSegments = engine.segments;
      meta.tier = engine.tier;
    }
    return meta;
    }

  function handleSubmit(instanceId, form){
    var engine=window.StarmusAudioRecorder && window.StarmusAudioRecorder.instances && window.StarmusAudioRecorder.instances[instanceId];
    var fb=el('starmus_fallback_input_'+instanceId);
    var blob=null; var fileName='recording.webm';

    if(engine && engine.audioBlob){
      blob=engine.audioBlob;
      fileName = (engine.tier==='B') ? 'recording.wav' : 'recording.webm';
    } else if (fb && fb.files && fb.files.length){
      blob=fb.files[0]; fileName=blob.name;
    }

    if(!blob){ showUserMessage(instanceId,'No audio recorded or selected.','error'); return; }

    var formFields = collectFormFields(form);
    var metadata = buildMetadata(instanceId);

    // Offline-first
    if(!navigator.onLine){
      log('log','Offline; queueing');
      Offline.add(instanceId, blob, fileName, formFields, metadata);
      return;
    }

    // Online — resumable tus upload
    resumableTusUpload(blob, fileName, formFields, metadata, instanceId)
      .then(function(url){
        // Optional post-finalize notify to WP endpoint (attach tus URL)
        if (window.starmusFormData && starmusFormData.rest_url && starmusFormData.rest_nonce){
          var fd=new FormData();
          Object.keys(formFields||{}).forEach(function(k){ fd.append(k, formFields[k]); });
          fd.append('tus_url', url || '');
          fd.append('metadata', JSON.stringify(metadata));
          return fetch(starmusFormData.rest_url, { method:'POST', headers:{'X-WP-Nonce': starmusFormData.rest_nonce}, body: fd });
        }
      })
      .then(function(){ showUserMessage(instanceId,'Submission saved.','success'); })
      .catch(function(err){
        log('error','Upload failed; saving locally', err && (err.message||err));
        showUserMessage(instanceId,'Upload failed; saved locally for later.','warn');
        Offline.add(instanceId, blob, fileName, formFields, metadata);
      });
  }

  // --- Init ---
  function start(){
    Offline.init();
    window.addEventListener('online', function(){ log('log','Back online; processing queue'); Offline.processQueue(); });
    // Auto-discover all containers by convention and bind forms
    var nodes = document.querySelectorAll('[id^="starmus_recorder_container_"]');
    nodes.forEach(function(n){
      var id=n.id.replace('starmus_recorder_container_','');
      initRecorder(id);
      bindForm(id);
    });
  }
  if (document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', start); } else { start(); }

})(window, document);
