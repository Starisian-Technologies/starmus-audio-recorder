/**
 * @file starmus-audio-editor.js
 * @version 1.0.4-GLOBALTHIS-FIX
 * @description Peaks.js waveform editor with modern global scope handling.
 */

(function (global, factory) {
  // Standard UMD wrapper for compatibility
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = factory();
  } else {
    global.StarmusAudioEditor = factory();
  }
// CRITICAL FIX: Use 'globalThis' instead of 'this' as the global fallback
})(typeof window !== 'undefined' ? window : globalThis, function () {
  'use strict';

  function init() {
    const EDITOR_DATA = window.STARMUS_EDITOR_DATA;
    if (!EDITOR_DATA) {
      console.error('STARMUS_EDITOR_DATA not defined — cannot initialize.');
      return;
    }

    const {
      restUrl, nonce, postId,
      audioUrl,
      annotations: initialAnnotations = []
    } = EDITOR_DATA;

    // DOM references
    const overviewEl = document.getElementById('overview');
    const zoomviewEl = document.getElementById('zoomview');
    const btnPlay    = document.getElementById('play');
    const btnBack5   = document.getElementById('back5');
    const btnFwd5    = document.getElementById('fwd5');
    const btnZoomIn  = document.getElementById('zoom-in');
    const btnZoomOut = document.getElementById('zoom-out');
    const btnZoomFit = document.getElementById('zoom-fit');
    const loopCheck  = document.getElementById('loop');
    const btnAdd     = document.getElementById('add-region');
    const btnSave    = document.getElementById('save');
    const regionTable= document.getElementById('regions-list');
    const notice     = document.getElementById('starmus-editor-notice');
    const timeCur    = document.getElementById('starmus-time-cur');
    const timeDur    = document.getElementById('starmus-time-dur');

    if (!audioUrl || !overviewEl || !zoomviewEl || !btnPlay || !btnAdd || !btnSave || !regionTable) {
      showNotice('Missing required editor elements.', 'error');
      return;
    }

    let dirty = false;
    const setDirty = (v) => {
      dirty = !!v;
      btnSave.disabled = !dirty;
    };
    setDirty(false);

    window.addEventListener('beforeunload', (e) => {
      if (dirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    });

    const audio = new Audio(audioUrl);
    audio.crossOrigin = 'anonymous';

    audio.addEventListener('error', () => showNotice('Audio load error — check CORS or URL.', 'error'));

    audio.addEventListener('loadedmetadata', () => {
      const duration = audio.duration;

      if (!isFinite(duration) || duration <= 0) {
        showNotice('Invalid audio file — cannot initialize.', 'error');
        return;
      }

      const normalize = (arr) => {
        const valid = (arr || []).filter(a =>
          isFinite(a.startTime) && isFinite(a.endTime) &&
          a.startTime >= 0 && a.endTime > a.startTime && a.endTime <= duration
        );
        valid.sort((a, b) => a.startTime - b.startTime);
        const res = [];
        let lastEnd = -1;
        for (const seg of valid) {
          if (seg.startTime >= lastEnd) {
            res.push({
              id: seg.id || getUUID(),
              startTime: seg.startTime,
              endTime: seg.endTime,
              label: (seg.label || '').trim().slice(0, 200)
            });
            lastEnd = seg.endTime;
          }
        }
        return res;
      };

      const getUUID = () =>
        window.crypto?.randomUUID?.() || 'id-' + Math.random().toString(36).slice(2) + Date.now();

      const segments = normalize(initialAnnotations);

      if (typeof Peaks === 'undefined') {
        showNotice('Peaks.js not loaded — cannot initialize editor.', 'error');
        return;
      }

      Peaks.init({
        containers: { overview: overviewEl, zoomview: zoomviewEl },
        mediaElement: audio,
        webAudio: {
          audioContext: new (window.AudioContext || window.webkitAudioContext)(),
          multiChannel: false
        },
        height: 180,
        zoomLevels: [64, 128, 256, 512, 1024, 2048],
        segments,
        keyboard: false,
        showPlayheadTime: true
      }, (err, peaks) => {
        if (err) {
          console.error('Peaks init error:', err);
          showNotice('Could not load editor (see console).', 'error');
          return;
        }

        timeDur.textContent = formatTime(duration);
        audio.addEventListener('timeupdate', () => {
          timeCur.textContent = formatTime(audio.currentTime);
        });
        btnPlay.addEventListener('click', () => audio.paused ? audio.play() : audio.pause());
        audio.addEventListener('play', () => {
          btnPlay.textContent = 'Pause';
          btnPlay.setAttribute('aria-pressed', 'true');
        });
        audio.addEventListener('pause', () => {
          btnPlay.textContent = 'Play';
          btnPlay.setAttribute('aria-pressed', 'false');
        });

        btnBack5.onclick = () => audio.currentTime = Math.max(0, audio.currentTime - 5);
        btnFwd5.onclick = () => audio.currentTime = Math.min(duration, audio.currentTime + 5);
        btnZoomIn.onclick = () => peaks.zoom.zoomIn();
        btnZoomOut.onclick = () => peaks.zoom.zoomOut();
        btnZoomFit.onclick = () => peaks.zoom.zoom(0);

        let looping = false;
        loopCheck.onchange = () => looping = loopCheck.checked;
        audio.addEventListener('timeupdate', () => {
          if (!looping) return;
          const t = audio.currentTime;
          const segs = peaks.segments.getSegments();
          if (!segs.length) return;
          const region = segs.find(r => t >= r.startTime && t < r.endTime);
          if (region && t >= region.endTime) {
            audio.currentTime = region.startTime;
          }
        });

        const render = () => {
          const segs = normalize(peaks.segments.getSegments());
          regionTable.innerHTML = '';
          if (!segs.length) {
            regionTable.innerHTML = '<tr><td colspan="5">No annotations. Click “Add Region”.</td></tr>';
            setDirty(false);
            return;
          }
          segs.forEach(s => {
            const tr = document.createElement('tr');
            tr.innerHTML =
              `<td><input data-id="${s.id}" data-key="label" value="${escapeHTML(s.label)}"/></td>` +
              `<td><input data-id="${s.id}" data-key="startTime" value="${s.startTime.toFixed(2)}" step="0.01"/></td>` +
              `<td><input data-id="${s.id}" data-key="endTime" value="${s.endTime.toFixed(2)}" step="0.01"/></td>` +
              `<td>${(s.endTime - s.startTime).toFixed(2)}s</td>` +
              `<td><button data-act="jump" data-id="${s.id}">Jump</button> <button data-act="del" data-id="${s.id}">Delete</button></td>`;
            regionTable.appendChild(tr);
          });
          setDirty(true);
        };

        regionTable.addEventListener('input', (e) => {
          const id = e.target.dataset.id, key = e.target.dataset.key;
          if (!id || !key) return;
          const seg = peaks.segments.getSegment(id);
          if (!seg) return;
          let val = e.target.value;
          if (key === 'startTime' || key === 'endTime') {
            val = parseFloat(val);
            if (isNaN(val)) return;
          }
          seg.update({ [key]: val });
          setDirty(true);
          render();
        });

        regionTable.addEventListener('click', (e) => {
          const act = e.target.dataset.act;
          const id  = e.target.dataset.id;
          if (!act || !id) return;
          const seg = peaks.segments.getSegment(id);
          if (!seg) return;
          if (act === 'jump') {
            audio.currentTime = seg.startTime;
            audio.play();
          } else if (act === 'del') {
            peaks.segments.removeById(id);
            setDirty(true);
            render();
          }
        });

        btnAdd.onclick = () => {
          const cur = audio.currentTime || 0;
          const start = Math.max(0, cur - 2);
          const end = Math.min(duration, cur + 2);
          peaks.segments.add({ id: getUUID(), startTime: start, endTime: end, label: '' });
          render();
        };

        btnSave.onclick = async () => {
          if (!dirty) return;
          setDirty(false);
          const segs = normalize(peaks.segments.getSegments());
          try {
            const resp = await fetch(restUrl, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
              body: JSON.stringify({ postId, annotations: segs })
            });
            const data = await resp.json();
            if (!resp.ok || !data.success) throw new Error(data.message || 'Server error');
            peaks.segments.removeAll();
            peaks.segments.add(normalize(data.annotations || []));
            render();
            showNotice('Annotations saved.', 'success');
          } catch (err) {
            showNotice('Save failed: ' + err.message, 'error');
            setDirty(true);
          }
        };

        render();
      });
    });
  }

  function showNotice(msg, type = 'error') {
    const el = document.getElementById('starmus-editor-notice');
    if (!el) return;
    if (!msg) {
      el.hidden = true;
    } else {
      el.textContent = msg;
      el.className = `starmus-alert starmus-alert--${type}`;
      el.hidden = false;
      setTimeout(() => el.hidden = true, 5000);
    }
  }

  function escapeHTML(str) {
    return ('' + str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function formatTime(s) {
    if (isNaN(s)) return '0:00';
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60);
    return m + ':' + String(sec).padStart(2, '0');
  }

  return { init };
});

// Export for Rollup
export default { init: window.StarmusAudioEditor.init };