/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains, the property of Starisian Technologies and its suppliers, if any.
 * The intellectual and technical concepts contained herein are proprietary to Starisian Technologies and its suppliers and may
 * be covered by U.S. and foreign patents, patents in process, and are protected by trade secret or copyright law.
 *
 * Dissemination of this information or reproduction of this material is strictly forbidden unless
 * prior written permission is obtained from Starisian Technologies.
 *
 * SPDX-License-Identifier:  LicenseRef-Starisian-Technologies-Proprietary
 * License URI:              https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 */

/**
 * @file Manages the Starmus Audio Editor interface using Peaks.js.
 * @description This script initializes the audio waveform editor, handles user interactions
 * (playback, zoom, annotations), manages unsaved changes, and communicates with the
 * WordPress REST API to save annotation data.
 */

(function () {
  'use strict';

  if (typeof STARMUS_EDITOR_DATA === 'undefined') {
    console.error('Starmus Error: Editor data (STARMUS_EDITOR_DATA) not found. Cannot initialize.');
    return;
  }

  const { restUrl, nonce, postId, audioUrl, annotations = [] } = STARMUS_EDITOR_DATA;

  const editorRoot = document.querySelector('.starmus-editor');
  if (!editorRoot) {
    return;
  }

  const overviewEl = document.getElementById('overview');
  const zoomviewEl = document.getElementById('zoomview');
  const btnPlay = document.getElementById('play');
  const btnAdd = document.getElementById('add-region');
  const btnSave = document.getElementById('save');
  const list = document.getElementById('regions-list');
  const peaksContainer = document.getElementById('peaks-container');

  if (
    !audioUrl ||
    !overviewEl ||
    !zoomviewEl ||
    !btnPlay ||
    !btnAdd ||
    !btnSave ||
    !list ||
    !peaksContainer
  ) {
    showInlineNotice('Missing required elements for the audio editor.');
    return;
  }

  let dirty = false;

  function setDirty(val) {
    dirty = val;
    btnSave.disabled = !dirty;
  }

  // Disable save initially — ensures clean start
  setDirty(false);

  window.addEventListener('beforeunload', function (e) {
    if (dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  function showInlineNotice(msg, type = 'error') {
    const notice = document.getElementById('starmus-editor-notice');
    if (!notice) return;
    if (!msg) {
      notice.hidden = true;
      return;
    }
    notice.textContent = msg;
    notice.className = `starmus-editor__notice starmus-editor__notice--${type}`;
    notice.hidden = false;
  }

  const audio = new Audio(audioUrl);
  audio.crossOrigin = 'anonymous';

  audio.addEventListener('error', function () {
    showInlineNotice(
      'Audio failed to load. This may be a CORS issue. Ensure the server sends correct Cross-Origin-Resource-Policy headers.'
    );
  });

  audio.addEventListener('loadedmetadata', function () {
    if (!Number.isFinite(audio.duration) || audio.duration === 0) {
      showInlineNotice('Audio failed to load or has an invalid duration.');
      return;
    }

    function normalizeAnnotations(arr) {
      const sorted = arr
        .slice()
        .filter(
          (a) =>
            Number.isFinite(a.startTime) &&
            Number.isFinite(a.endTime) &&
            a.endTime > a.startTime &&
            a.startTime >= 0 &&
            a.endTime <= audio.duration
        );
      sorted.sort((a, b) => a.startTime - b.startTime);
      const result = [];
      let lastEnd = -1;
      for (const ann of sorted) {
        if (ann.startTime >= lastEnd) {
          result.push(ann);
          lastEnd = ann.endTime;
        }
      }
      return result;
    }

    function getUUID() {
      return window.crypto?.randomUUID
        ? window.crypto.randomUUID()
        : 'id-' + Math.random().toString(36).slice(2) + Date.now();
    }

    const initialSegments = normalizeAnnotations(annotations).map((a) => ({
      ...a,
      id: a.id || getUUID(),
      label: (a.label || '').trim().slice(0, 200),
    }));

    if (typeof Peaks === 'undefined') {
      showInlineNotice('Peaks.js library not loaded. Please ensure it is included.');
      return;
    }

    const peaksOptions = {
      containers: { overview: overviewEl, zoomview: zoomviewEl },
      mediaElement: audio,
      webAudio: {
        audioContext: new (window.AudioContext || window.webkitAudioContext)(),
        multiChannel: false,
      },
      height: 180,
      zoomLevels: [64, 128, 256, 512, 1024, 2048],
      keyboard: false,
      segments: initialSegments,
      pointMarkerColor: '#ff0000',
      showPlayheadTime: true,
    };

    Peaks.init(peaksOptions, function (err, peaks) {
      if (err) {
        console.error('Peaks.js initialization error:', err);
        showInlineNotice(
          'Could not load audio waveform. Please check the browser console for details.'
        );
        return;
      }

      let transcriptController = null;

      const mockTranscriptData = [
        { start: 0.5, end: 1.2, text: 'Welcome', confidence: 0.95 },
        { start: 1.2, end: 1.8, text: 'to', confidence: 0.99 },
        { start: 1.8, end: 2.5, text: 'the', confidence: 0.98 },
        { start: 2.5, end: 3.1, text: 'Starmus', confidence: 0.92 },
        { start: 3.1, end: 3.8, text: 'editor.', confidence: 0.94 },
        { start: 4.0, end: 5.2, text: 'This', confidence: 0.96 },
        { start: 5.2, end: 6.5, text: 'is', confidence: 0.97 },
        { start: 6.5, end: 7.0, text: 'a', confidence: 0.99 },
        { start: 7.0, end: 8.2, text: 'synchronized', confidence: 0.75 },
        { start: 8.2, end: 8.8, text: 'test.', confidence: 0.93 },
      ];

      if (
        typeof StarmusTranscript !== 'undefined' &&
        document.getElementById('starmus-transcript-panel')
      ) {
        const transcriptData =
          (typeof STARMUS_EDITOR_DATA !== 'undefined' && STARMUS_EDITOR_DATA.transcript)
            ? STARMUS_EDITOR_DATA.transcript
            : mockTranscriptData;

        transcriptController = new StarmusTranscript(
          peaks,
          'starmus-transcript-panel',
          transcriptData
        );
        console.log('Starmus Linguistic Engine: Online');
        if (window.STARMUS_DEBUG) {
          window.StarmusTranscriptInstance = transcriptController;
        }
      }

      const elCur = document.getElementById('starmus-time-cur');
      const elDur = document.getElementById('starmus-time-dur');
      const fmt = (s) => {
        if (!Number.isFinite(s)) return '0:00';
        const m = Math.floor(s / 60);
        const sec = Math.floor(s % 60);
        return m + ':' + String(sec).padStart(2, '0');
      };
      elDur.textContent = fmt(audio.duration);
      audio.addEventListener('timeupdate', () => {
        elCur.textContent = fmt(audio.currentTime);
      });
      btnPlay.addEventListener('click', () => {
        audio.paused ? audio.play() : audio.pause();
      });
      audio.addEventListener('play', () => {
        btnPlay.textContent = 'Pause';
        btnPlay.setAttribute('aria-pressed', 'true');
      });
      audio.addEventListener('pause', () => {
        btnPlay.textContent = 'Play';
        btnPlay.setAttribute('aria-pressed', 'false');
      });

      document.getElementById('back5').onclick = () => {
        audio.currentTime = Math.max(0, audio.currentTime - 5);
      };
      document.getElementById('fwd5').onclick = () => {
        audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + 5);
      };

      document.getElementById('zoom-in').onclick = () => peaks.zoom.zoomIn();
      document.getElementById('zoom-out').onclick = () => peaks.zoom.zoomOut();
      document.getElementById('zoom-fit').onclick = () => peaks.zoom.zoom(0);

      let loopOn = false;
      let loopRegion = null;
      const loopCb = document.getElementById('loop');
      loopCb.addEventListener('change', () => {
        loopOn = loopCb.checked;
      });
      audio.addEventListener('timeupdate', () => {
        if (!loopOn) return;
        const t = audio.currentTime;
        const regs = peaks.segments.getSegments();
        if (!regs.length) return;
        loopRegion = regs.find((r) => t >= r.startTime && t < r.endTime) || loopRegion || regs[0];
        if (t >= loopRegion.endTime) {
          audio.currentTime = loopRegion.startTime;
        }
      });

      document.addEventListener('keydown', (e) => {
        if (e.target.matches('input,textarea')) return;
        if (e.code === 'Space') {
          e.preventDefault();
          btnPlay.click();
        }
        if (e.key === 'ArrowLeft') {
          e.preventDefault();
          document.getElementById('back5').click();
        }
        if (e.key === 'ArrowRight') {
          e.preventDefault();
          document.getElementById('fwd5').click();
        }
        if (e.key === '=') {
          e.preventDefault();
          peaks.zoom.zoomIn();
        }
        if (e.key === '-') {
          e.preventDefault();
          peaks.zoom.zoomOut();
        }
        // ➕ New: Escape to blur (end editing mode)
        if (e.key === 'Escape') {
          if (document.activeElement) {
            document.activeElement.blur();
          }
        }
      });

      function renderRegions() {
        const tbody = document.getElementById('regions-list');
        tbody.innerHTML = '';
        const regs = peaks.segments.getSegments();
        if (!regs.length) {
          const tr = document.createElement('tr');
          tr.innerHTML = '<td colspan="5">No annotations yet. Click "Add Region" to start.</td>';
          tbody.appendChild(tr);
          setDirty(dirty);
          return;
        }

        regs.forEach((seg) => {
          const dur = seg.endTime - seg.startTime;
          const tr = document.createElement('tr');

          // Escape annotation label for HTML safety
          const safeLabel = (seg.label || '').replace(/[&<>"']/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]
          );

          tr.innerHTML = `
            <td><input data-k="label" data-id="${seg.id}" value="${safeLabel}" maxlength="200" placeholder="Annotation" class="widefat" /></td>
            <td><input data-k="startTime" data-id="${seg.id}" type="number" step="0.01" min="0" value="${seg.startTime.toFixed(2)}" class="small-text" /></td>
            <td><input data-k="endTime" data-id="${seg.id}" type="number" step="0.01" min="0" value="${seg.endTime.toFixed(2)}" class="small-text" /></td>
            <td>${fmt(dur)}</td>
            <td>
              <button class="button" data-act="jump" data-id="${seg.id}">Jump</button>
              <button class="button button-link-delete" data-act="del" data-id="${seg.id}">Delete</button>
            </td>`;
          tbody.appendChild(tr);
        });
      }

      renderRegions();
      setDirty(false); // ensure save button remains disabled after initial render

      list.addEventListener('input', (e) => {
        if (!e.target.dataset.id) return;
        let inputTimeout;
        clearTimeout(inputTimeout);
        inputTimeout = setTimeout(() => {
          setDirty(true);
          const id = e.target.dataset.id;
          const key = e.target.dataset.k;
          const value = e.target.type === 'number' ? parseFloat(e.target.value) : e.target.value;
          const segment = peaks.segments.getSegment(id);
          if (segment) {
            segment.update({ [key]: value });
          }
        }, 250);
      });

      list.addEventListener('click', (e) => {
        const id = e.target.dataset.id;
        const act = e.target.dataset.act;
        if (!id || !act) return;

        if (act === 'jump') {
          const seg = peaks.segments.getSegment(id);
          if (seg) {
            audio.currentTime = seg.startTime;
            audio.play();
          }
        } else if (act === 'del') {
          const seg = peaks.segments.getSegment(id);
          if (seg) {
            peaks.segments.removeById(id);
            setDirty(true);
            renderRegions();
          }
        }
      });

      btnAdd.onclick = () => {
        const currentTime = audio.currentTime || 0;
        const start = Math.max(0, currentTime - 2);
        const end = Math.min(audio.duration, currentTime + 2);
        peaks.segments.add({
          id: getUUID(),
          startTime: start,
          endTime: end,
          labelText: '',
          editable: true,
          color: '#ff6b6b',
        });
        setDirty(true);
        renderRegions();
      };

      let saveLock = false;
      btnSave.onclick = async () => {
        if (saveLock) return;
        saveLock = true;
        btnSave.textContent = 'Saving...';
        btnSave.disabled = true;
        showInlineNotice(null);

        let payload = peaks.segments.getSegments().map((s) => ({
          id: s.id,
          startTime: s.startTime,
          endTime: s.endTime,
          label: (s.labelText || s.label || '').trim().slice(0, 200),
        }));
        payload = normalizeAnnotations(payload);

        try {
          const response = await fetch(restUrl, {
            method: 'POST',
            headers: {
              'X-WP-Nonce': nonce,
              'Content-Type': 'application/json; charset=utf-8'  // ➕ enforce utf8 charset
            },
            body: JSON.stringify({
              postId: postId,
              annotations: payload,
            }),
          });
          const data = await response.json();

          if (!response.ok) {
            throw new Error(data.message || response.statusText);
          }

          if (data.success) {
            setDirty(false);
            showInlineNotice('Annotations saved successfully!', 'success');
            if (data.annotations) {
              peaks.segments.removeAll();
              peaks.segments.add(data.annotations);
              renderRegions();
            }
          } else {
            throw new Error(data.message || 'An unknown error occurred.');
          }
        } catch (err) {
          console.error('Save failed:', err);
          showInlineNotice('Save failed: ' + err.message, 'error');
        } finally {
          saveLock = false;
          btnSave.textContent = 'Save';
          btnSave.disabled = !dirty;
        }
      };
    });
  });

  // Function declaration needs to come after its use
  function showInlineNotice(msg, type = 'error') {
    const notice = document.getElementById('starmus-editor-notice');
    if (!notice) return;
    if (!msg) {
      notice.hidden = true;
      return;
    }
    notice.textContent = msg;
    notice.className = `starmus-editor__notice starmus-editor__notice--${type}`;
    notice.hidden = false;
  }
})();
