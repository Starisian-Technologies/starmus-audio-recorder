/**
 * @file starmus-audio-editor.js
 * @version 1.0.0‑full
 * @description Audio waveform + annotation editor using Peaks.js.
 * Fully wired UI: playback, time display, zoom, loop,
 * region add/edit/delete, save to REST, CommandBus dispatch,
 * clean teardown, optional transcript sync.
 */
(function (global) {
  'use strict';

  // REQUIRE: STARMUS_EDITOR_DATA must be defined globally before this script runs.
  if (typeof STARMUS_EDITOR_DATA === 'undefined') {
    console.error('Starmus Error: STARMUS_EDITOR_DATA not found — editor cannot initialize.');
    return;
  }

  const { restUrl, nonce, postId, audioUrl, transcript: initialTranscript = [], annotations: initialAnnotations = [] } = STARMUS_EDITOR_DATA;

  // DOM elements
  const editorRoot = document.querySelector('.starmus-editor');
  if (!editorRoot) {
    console.warn('StarmusAudioEditor: .starmus-editor root not found — aborting.');
    return;
  }

  const overviewEl     = document.getElementById('overview');
  const zoomviewEl     = document.getElementById('zoomview');
  const btnPlay        = document.getElementById('play');
  const btnBack5       = document.getElementById('back5');
  const btnFwd5        = document.getElementById('fwd5');
  const btnZoomIn      = document.getElementById('zoom-in');
  const btnZoomOut     = document.getElementById('zoom-out');
  const btnZoomFit     = document.getElementById('zoom-fit');
  const loopCheckbox   = document.getElementById('loop');
  const btnAdd         = document.getElementById('add-region');
  const btnSave        = document.getElementById('save');
  const regionsTable   = document.getElementById('regions-list');
  const noticeEl       = document.getElementById('starmus-editor-notice');
  const timeCurEl      = document.getElementById('starmus-time-cur');
  const timeDurEl      = document.getElementById('starmus-time-dur');
  const peaksContainer = document.getElementById('peaks-container');

  if (!audioUrl || !overviewEl || !zoomviewEl || !btnPlay || !btnAdd || !btnSave || !regionsTable || !peaksContainer) {
    showNotice('Missing required editor elements — audio editor cannot initialize.', 'error');
    return;
  }

  // Dirty flag and save button enable/disable
  let dirty = false;
  function makeSetDirty(btn) {
    return function(val) {
      dirty = !!val;
      btn.disabled = !dirty;
    };
  }
  const setDirty = makeSetDirty(btnSave);
  setDirty(false);

  // Warn if user tries to unload with unsaved changes
  window.addEventListener('beforeunload', (e) => {
    if (dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  // Create audio element
  const audio = new Audio(audioUrl);
  audio.crossOrigin = 'anonymous';

  audio.addEventListener('error', () => {
    showNotice('Audio failed to load — maybe CORS or missing file.', 'error');
  });

  audio.addEventListener('loadedmetadata', () => {
    const duration = audio.duration;
    if (!isFinite(duration) || duration <= 0) {
      showNotice('Invalid audio duration — cannot initialize waveform.', 'error');
      return;
    }

    // normalized annotation list: filter invalid, sort, remove overlaps
    function normalizeAnnotations(arr) {
      const valid = arr
        .filter(a =>
          isFinite(a.startTime) && isFinite(a.endTime) &&
          a.startTime >= 0 && a.endTime > a.startTime && a.endTime <= duration
        )
        .map(a => ({
          id: a.id,
          startTime: a.startTime,
          endTime: a.endTime,
          label: (a.label || '').trim().slice(0, 200)
        }));
      valid.sort((a, b) => a.startTime - b.startTime);
      const result = [];
      let lastEnd = -1;
      valid.forEach(a => {
        if (a.startTime >= lastEnd) {
          result.push(a);
          lastEnd = a.endTime;
        }
      });
      return result;
    }

    function getUUID() {
      if (window.crypto && crypto.randomUUID) {
        return crypto.randomUUID();
      }
      return 'id-' + Math.random().toString(36).slice(2) + Date.now();
    }

    const initialSegments = normalizeAnnotations(initialAnnotations).map(a => ({
      id: a.id || getUUID(),
      startTime: a.startTime,
      endTime: a.endTime,
      label: a.label
    }));

    if (typeof Peaks === 'undefined') {
      showNotice('Peaks.js not loaded — waveform editor cannot initialize.', 'error');
      return;
    }

    // Initialize Peaks
    Peaks.init({
      containers : { overview: overviewEl, zoomview: zoomviewEl },
      mediaElement : audio,
      webAudio : {
        audioContext: new (window.AudioContext || window.webkitAudioContext)(),
        multiChannel: false
      },
      segments : initialSegments,
      height: 180,
      zoomLevels: [64,128,256,512,1024,2048],
      keyboard: false,
      showPlayheadTime: true
    }, (err, peaks) => {
      if (err) {
        console.error('Peaks init error', err);
        showNotice('Could not load waveform editor (see console).', 'error');
        return;
      }

      // Playback UI wiring
      timeDurEl.textContent = formatTime(duration);

      audio.addEventListener('timeupdate', () => {
        timeCurEl.textContent = formatTime(audio.currentTime);
      });

      btnPlay.addEventListener('click', () => {
        if (audio.paused) audio.play();
        else audio.pause();
      });
      audio.addEventListener('play', () => {
        btnPlay.textContent = 'Pause';
        btnPlay.setAttribute('aria-pressed', 'true');
      });
      audio.addEventListener('pause', () => {
        btnPlay.textContent = 'Play';
        btnPlay.setAttribute('aria-pressed', 'false');
      });

      btnBack5.addEventListener('click', () => {
        audio.currentTime = Math.max(0, audio.currentTime - 5);
      });
      btnFwd5.addEventListener('click', () => {
        audio.currentTime = Math.min(duration, audio.currentTime + 5);
      });

      btnZoomIn.addEventListener('click', () => peaks.zoom.zoomIn());
      btnZoomOut.addEventListener('click', () => peaks.zoom.zoomOut());
      btnZoomFit.addEventListener('click', () => peaks.zoom.zoom(0));

      let loopOn = false;
      loopCheckbox.addEventListener('change', () => {
        loopOn = loopCheckbox.checked;
      });

      audio.addEventListener('timeupdate', () => {
        if (!loopOn) return;
        const t = audio.currentTime;
        const segs = peaks.segments.getSegments();
        if (!segs.length) return;
        // find current region if any
        const region = segs.find(r => t >= r.startTime && t < r.endTime);
        if (region && t >= region.endTime) {
          audio.currentTime = region.startTime;
        }
      });

      // Regions UI
      function renderRegions() {
        regionsTable.innerHTML = '';
        const segs = normalizeAnnotations(
          peaks.segments.getSegments().map(s => ({
            id: s.id,
            startTime: s.startTime,
            endTime: s.endTime,
            label: (s.labelText || s.label || '').trim().slice(0,200)
          }))
        );
        if (!segs.length) {
          const tr = document.createElement('tr');
          tr.innerHTML = '<td colspan="5">No annotations. Click “Add Region” to start.</td>';
          regionsTable.appendChild(tr);
          setDirty(false);
          return;
        }
        segs.forEach((s) => {
          const tr = document.createElement('tr');
          const safeLabel = escapeText(s.label);
          const dur = (s.endTime - s.startTime).toFixed(2);
          tr.innerHTML = ''
            + `<td><input data-id="${s.id}" data-key="label" value="${safeLabel}" maxlength="200" class="widefat"/></td>`
            + `<td><input data-id="${s.id}" data-key="startTime" type="number" step="0.01" min="0" value="${s.startTime.toFixed(2)}" class="small-text"/></td>`
            + `<td><input data-id="${s.id}" data-key="endTime"   type="number" step="0.01" min="0" value="${s.endTime.toFixed(2)}" class="small-text"/></td>`
            + `<td>${dur}s</td>`
            + `<td>`
            +   `<button data-act="jump" data-id="${s.id}">Jump</button> `
            +   `<button data-act="del"  data-id="${s.id}">Delete</button>`
            + `</td>`;
          regionsTable.appendChild(tr);
        });
        setDirty(true);
        dispatchAnnotationsUpdated();
      }

      function escapeText(str) {
        return String(str || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      }

      function dispatchAnnotationsUpdated() {
        const payload = peaks.segments.getSegments().map(s => ({
          id: s.id,
          startTime: s.startTime,
          endTime: s.endTime,
          label: (s.labelText || s.label || '').trim().slice(0,200)
        }));
        if (global.StarmusHooks && typeof global.StarmusHooks.dispatch === 'function') {
          global.StarmusHooks.dispatch('starmus/editor/annotations‑updated', {
            instanceId: STARMUS_EDITOR_DATA.instanceId || null,
            annotations: payload
          });
        }
      }

      // Wire region edits
      regionsTable.addEventListener('input', (e) => {
        const id = e.target.dataset.id;
        const key = e.target.dataset.key;
        if (!id || !key) return;
        const seg = peaks.segments.getSegment(id);
        if (!seg) return;
        let value = e.target.value;
        if (key === 'startTime' || key === 'endTime') {
          value = parseFloat(value);
          if (isNaN(value)) return;
        }
        const upd = {};
        upd[key] = value;
        seg.update(upd);
        setDirty(true);
        renderRegions();
      });

      regionsTable.addEventListener('click', (e) => {
        const act = e.target.dataset.act;
        const id = e.target.dataset.id;
        if (!act || !id) return;
        const seg = peaks.segments.getSegment(id);
        if (!seg) return;

        if (act === 'jump') {
          audio.currentTime = seg.startTime;
          audio.play();
        } else if (act === 'del') {
          peaks.segments.removeById(id);
          setDirty(true);
          renderRegions();
        }
      });

      btnAdd.addEventListener('click', () => {
        const cur = audio.currentTime || 0;
        const start = Math.max(0, cur - 2);
        const end   = Math.min(duration, cur + 2);
        peaks.segments.add({
          id: getUUID(),
          startTime: start,
          endTime: end,
          label: ''
        });
        renderRegions();
      });

      // Validation helper
      function validateSegments(segs) {
        for (let i = 0; i < segs.length; i++) {
          const a = segs[i];
          if (!(a.startTime < a.endTime)) {
            return `Region ${i+1}: start must be < end.`;
          }
          for (let j = i + 1; j < segs.length; j++) {
            const b = segs[j];
            if (a.startTime < b.endTime && b.startTime < a.endTime) {
              return `Regions ${i+1} and ${j+1} overlap.`;
            }
          }
        }
        return null;
      }

      btnSave.addEventListener('click', async () => {
        if (!dirty) return;
        btnSave.disabled = true;
        showNotice(null);

        let segs = peaks.segments.getSegments().map(s => ({
          id: s.id,
          startTime: s.startTime,
          endTime: s.endTime,
          label: (s.labelText || s.label || '').trim().slice(0,200)
        }));
        segs = normalizeAnnotations(segs);
        const err = validateSegments(segs);
        if (err) {
          showNotice('Save failed: ' + err, 'error');
          btnSave.disabled = false;
          return;
        }

        const payload = {
          postId: postId,
          annotations: segs,
          metadata: {
            edit_timestamp: (new Date()).toISOString(),
            annotation_count: segs.length
          }
        };

        try {
          const resp = await fetch(restUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json;charset=utf-8',
              'X-WP-Nonce': nonce
            },
            body: JSON.stringify(payload)
          });
          const data = await resp.json();
          if (!resp.ok || !data.success) {
            throw new Error(data.message || resp.statusText);
          }
          if (Array.isArray(data.annotations)) {
            peaks.segments.removeAll();
            peaks.segments.add(normalizeAnnotations(data.annotations));
            renderRegions();
          }
          setDirty(false);
          showNotice('Annotations saved successfully.', 'success');
          dispatchAnnotationsUpdated();
        } catch (e) {
          console.error('Save error:', e);
          showNotice('Save failed: ' + e.message, 'error');
          btnSave.disabled = false;
        }
      });

      // Initial UI
      renderRegions();
      dispatchAnnotationsUpdated();

      // Optional: integrate transcript panel if available
      if (global.StarmusTranscriptController && Array.isArray(initialTranscript)) {
        try {
          const transcript = new global.StarmusTranscriptController(initPeaks = peaks, 'starmus-transcript-panel', initialTranscript);
          // no further action needed; user can click words to seek
        } catch (_e) {
          // swallow — transcript optional
        }
      }

      // PUBLIC API for cleanup
      global.StarmusAudioEditor = {
        destroy: function() {
          try { peaks.destroy(); } catch (_) {}
          audio.pause();
          audio.src = '';
          regionsTable.innerHTML = '';
          showNotice(null);
          btnPlay.onclick = null;
          btnBack5.onclick = null;
          btnFwd5.onclick = null;
          btnZoomIn.onclick = null;
          btnZoomOut.onclick = null;
          btnZoomFit.onclick = null;
          btnAdd.onclick = null;
          btnSave.onclick = null;
        }
      };

    }); // Peaks.init
  }); // loadedmetadata

  function showNotice(msg, type = 'error') {
    if (!noticeEl) return;
    if (!msg) {
      noticeEl.hidden = true;
    } else {
      noticeEl.textContent = msg;
      noticeEl.className = 'starmus-alert starmus-alert--' + type;
      noticeEl.hidden = false;
    }
  }

  function formatTime(secs) {
    const m = Math.floor(secs / 60);
    const s = Math.floor(secs % 60);
    return m + ':' + String(s).padStart(2, '0');
  }

})(typeof window !== 'undefined' ? window : this);
