

(function ($) {
  if (typeof STARMUS_EDITOR_DATA === 'undefined') {
    showInlineNotice('Editor data not found. Cannot initialize.');
    return;
  }
  const { restUrl, nonce, postId, audioUrl, annotations = [] } = STARMUS_EDITOR_DATA;

  // Check for required DOM elements
  const overviewEl = document.getElementById('overview');
  const zoomviewEl = document.getElementById('zoomview');
  const btnPlay = document.getElementById('play');
  const btnAdd = document.getElementById('add-region');
  const btnSave = document.getElementById('save');
  const list = document.getElementById('regions-list');
  const peaksContainer = document.getElementById('peaks-container');
  if (!audioUrl || !overviewEl || !zoomviewEl || !btnPlay || !btnAdd || !btnSave || !list || !peaksContainer) {
    showInlineNotice('Missing required elements for the audio editor.');
    return;
  }

  let dirty = false;
  function setDirty(val) {
    dirty = val;
    btnSave.disabled = !dirty;
  }
  window.addEventListener('beforeunload', function(e) {
    if (dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  function showInlineNotice(msg) {
    let notice = document.getElementById('starmus-editor-notice');
    if (!notice) {
      notice = document.createElement('div');
      notice.id = 'starmus-editor-notice';
      notice.style = 'background: #ffe0e0; color: #a00; padding: 8px; margin: 8px 0; border: 1px solid #a00;';
      peaksContainer.prepend(notice);
    }
    notice.textContent = msg;
  }

  const audio = new Audio(audioUrl);
  audio.crossOrigin = 'anonymous';

  let corsError = false;
    audio.addEventListener('error', function() {
    corsError = true;
    showInlineNotice('Audio failed to load. This may be a CORS or Cross-Origin-Resource-Policy issue. Please ensure the server sends Access-Control-Allow-Origin: * and Cross-Origin-Resource-Policy: cross-origin.');
  });
  audio.addEventListener('loadedmetadata', function() {
    if (corsError || !Number.isFinite(audio.duration) || audio.duration === 0) {
      showInlineNotice('Audio failed to load or has invalid duration.');
      return;
    }

    // Normalize and sort annotations, remove overlaps
    function normalizeAnnotations(arr) {
      let sorted = arr.slice().filter(a => Number.isFinite(a.startTime) && Number.isFinite(a.endTime) && a.endTime > a.startTime && a.startTime >= 0 && a.endTime <= audio.duration);
      sorted.sort((a, b) => a.startTime - b.startTime);
      // Optionally merge overlaps (here: just remove overlaps, keep first)
      let result = [];
      let lastEnd = -1;
      for (const ann of sorted) {
        if (ann.startTime >= lastEnd) {
          result.push(ann);
          lastEnd = ann.endTime;
        }
      }
      return result;
    }

    // Use stable IDs for new segments
    function getUUID() {
      if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
      // fallback
      return 'id-' + Math.random().toString(36).slice(2) + Date.now();
    }

    let initialSegments = normalizeAnnotations(annotations).map(a => ({
      ...a,
      id: a.id || getUUID(),
      label: (a.label || '').trim().slice(0, 200)
    }));

    Peaks.init({
      containers: { overview: overviewEl, zoomview: zoomviewEl },
      mediaElement: audio,
      height: 160,
      zoomLevels: [64, 128, 256, 512, 1024],
      keyboard: true,
      segments: initialSegments,
      allowSeeking: true,
    }, function (err, peaks) {
      if (err) {
        console.error('Peaks.js initialization error:', err);
        showInlineNotice('Could not load audio waveform. Please check the browser console for details.');
        return;
      }

      list.setAttribute('role', 'list');

      function renderRegions() {
        list.innerHTML = '';
        const segments = peaks.segments.getSegments();
        if (segments.length === 0) {
          const emptyMsg = document.createElement('div');
          emptyMsg.textContent = 'No annotations yet.';
          emptyMsg.setAttribute('aria-live', 'polite');
          list.appendChild(emptyMsg);
          btnSave.disabled = true;
        } else {
          btnSave.disabled = !dirty;
        }
        segments.forEach(seg => {
          const row = document.createElement('div');
          row.style.margin = '8px 0';
          row.setAttribute('role', 'listitem');
          row.innerHTML = `
            <input data-k="label" data-id="${seg.id}" value="${seg.label ? String(seg.label).replace(/"/g, '&quot;') : ''}" placeholder="Annotation Text" maxlength="200" style="width: 50%;" aria-label="Annotation label" />
            <input data-k="startTime" data-id="${seg.id}" type="number" step="0.01" min="0" value="${seg.startTime.toFixed(2)}" style="width: 80px;" aria-label="Start time" />
            <input data-k="endTime" data-id="${seg.id}" type="number" step="0.01" min="0" value="${seg.endTime.toFixed(2)}" style="width: 80px;" aria-label="End time" />
            <button data-act="del" data-id="${seg.id}" class="button" aria-label="Delete annotation">Delete</button>`;
          list.appendChild(row);
        });
      }

      renderRegions();

      let inputTimeout;
      list.addEventListener('input', e => {
        clearTimeout(inputTimeout);
        inputTimeout = setTimeout(() => {
          setDirty(true);
          const id = e.target.dataset.id;
          const key = e.target.dataset.k;
          let value = e.target.type === 'number' ? parseFloat(e.target.value) : e.target.value;
          if (key === 'label' && typeof value === 'string') {
            value = value.trim().slice(0, 200);
          }
          const segment = peaks.segments.getSegment(id);
          if (!segment) return;
          // Validate start/end times, always check audio.duration
          if (key === 'startTime' || key === 'endTime') {
            const otherKey = key === 'startTime' ? 'endTime' : 'startTime';
            const otherValue = segment[otherKey];
            if (!Number.isFinite(audio.duration) || audio.duration === 0) {
              showInlineNotice('Audio duration is not available.');
              return;
            }
            if (key === 'startTime' && value >= otherValue) {
              showInlineNotice('Start time must be less than end time.');
              e.target.value = segment.startTime.toFixed(2);
              return;
            }
            if (key === 'endTime' && value <= otherValue) {
              showInlineNotice('End time must be greater than start time.');
              e.target.value = segment.endTime.toFixed(2);
              return;
            }
            if (value < 0 || value > audio.duration) {
              showInlineNotice('Time must be within the audio duration.');
              e.target.value = segment[key].toFixed(2);
              return;
            }
            // Overlap check (optional but smart)
            const segments = peaks.segments.getSegments().filter(s => s.id !== id);
            const newStart = key === 'startTime' ? value : segment.startTime;
            const newEnd = key === 'endTime' ? value : segment.endTime;
            for (const s of segments) {
              if (!(newEnd <= s.startTime || newStart >= s.endTime)) {
                showInlineNotice('Annotations cannot overlap.');
                e.target.value = segment[key].toFixed(2);
                return;
              }
            }
          }
          segment.update({ [key]: value });
        }, 150);
      });

      list.addEventListener('click', e => {
        if (e.target.dataset.act === 'del') {
          showInlineNotice('Click again to confirm deletion.');
          if (e.target.dataset.confirmed === '1') {
            setDirty(true);
            const segment = peaks.segments.getSegment(e.target.dataset.id);
            if (segment) {
              peaks.segments.remove(segment);
              renderRegions();
            }
            e.target.removeAttribute('data-confirmed');
            showInlineNotice('Annotation deleted.');
          } else {
            e.target.dataset.confirmed = '1';
            setTimeout(() => {
              e.target.removeAttribute('data-confirmed');
              showInlineNotice('');
            }, 2000);
          }
        }
      });

      btnPlay.onclick = () => {
        if (audio.paused) {
          audio.play();
        } else {
          audio.pause();
        }
      };

      btnAdd.onclick = () => {
        if (!Number.isFinite(audio.duration) || audio.duration === 0) {
          showInlineNotice('Audio duration is not available.');
          return;
        }
        const currentTime = peaks.time.getCurrentTime();
        const start = Math.max(0, currentTime - 5);
        const end = currentTime;
        if (end <= start) {
          alert('Cannot add annotation: end time must be after start time.');
          return;
        }
        peaks.segments.add({
          id: getUUID(),
          startTime: start,
          endTime: end,
          label: '',
          editable: true
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

        let payload = peaks.segments.getSegments().map(s => ({
          id: s.id || getUUID(),
          startTime: s.startTime,
          endTime: s.endTime,
          label: (s.label || '').trim().slice(0, 200)
        }));

        // Normalize and sort before saving
        payload = normalizeAnnotations(payload);

        // Validate all segments before saving
        for (const s of payload) {
          if (!Number.isFinite(audio.duration) || audio.duration === 0 || typeof s.startTime !== 'number' || typeof s.endTime !== 'number' || s.startTime < 0 || s.endTime <= s.startTime || s.endTime > audio.duration) {
            showInlineNotice('Invalid annotation times detected. Please check your annotations.');
            btnSave.textContent = 'Save Annotations';
            btnSave.disabled = false;
            saveLock = false;
            return;
          }
        }

        // Save with retry/backoff for 429/413
        let retries = 0;
        let maxRetries = 4;
        let backoff = 1000;
        let chunkSize = 50;
        let lastError = null;
        async function saveChunk(chunk) {
          try {
            const response = await fetch(restUrl, {
              method: 'POST',
              headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({
                postId: postId,
                annotations: chunk
              })
            });
            if (response.status === 429 || response.status === 413) {
              throw { retry: true, status: response.status };
            }
            if (!response.ok) {
              let errorMsg = 'Save failed. Please try again.';
              try {
                const errorData = await response.json();
                errorMsg = errorData.message || errorMsg;
              } catch (_) {}
              throw new Error(errorMsg);
            }
            // Update local IDs with server-saved IDs if returned
            try {
              const data = await response.json();
              if (Array.isArray(data.annotations)) {
                // Replace local IDs with server IDs
                data.annotations.forEach((serverAnn, i) => {
                  if (serverAnn.id && chunk[i] && chunk[i].id !== serverAnn.id) {
                    // Update segment in Peaks
                    const seg = peaks.segments.getSegment(chunk[i].id);
                    if (seg) seg.update({ id: serverAnn.id });
                  }
                });
              }
            } catch (_) {}
            return true;
          } catch (err) {
            if (err.retry && retries < maxRetries) {
              retries++;
              showInlineNotice('Server busy or payload too large. Retrying in ' + (backoff/1000) + 's...');
              await new Promise(res => setTimeout(res, backoff));
              backoff *= 2;
              return saveChunk(chunk);
            } else if (err.retry && chunk.length > 1) {
              // Try chunking
              showInlineNotice('Splitting annotations and retrying...');
              let mid = Math.floor(chunk.length/2);
              await saveChunk(chunk.slice(0, mid));
              await saveChunk(chunk.slice(mid));
              return true;
            } else {
              lastError = err;
              return false;
            }
          }
        }

        let ok = await saveChunk(payload);
        if (ok) {
          setDirty(false);
          showInlineNotice('Annotations saved successfully!');
        } else {
          showInlineNotice('Save failed: ' + (lastError && lastError.message ? lastError.message : 'Unknown error.'));
        }
        btnSave.textContent = 'Save Annotations';
        btnSave.disabled = !dirty;
        setTimeout(() => { saveLock = false; }, 1000); // debounce 1s
      };
    });
  });

  // Helper for inline notices
  function showInlineNotice(msg) {
    let notice = document.getElementById('starmus-editor-notice');
    if (!notice) {
      notice = document.createElement('div');
      notice.id = 'starmus-editor-notice';
      notice.style = 'background: #ffe0e0; color: #a00; padding: 8px; margin: 8px 0; border: 1px solid #a00;';
      (document.getElementById('peaks-container') || document.body).prepend(notice);
    }
    notice.textContent = msg;
  }

})(jQuery);