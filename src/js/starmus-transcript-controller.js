/**
 * @file starmus-transcript-controller.js
 * @version 1.3.0
 * @description Handles the "karaoke‑style" transcript panel that syncs with audio playback.
 *   Provides click-to-seek, auto-scroll with user-scroll detection, confidence indicators,
 *   clean re-init/destroy, and fallback safety for older browsers.
 */

'use strict';

// --- Dependency Import (ensure global hooks exist) ---
import './starmus-hooks.js';
const BUS = window.StarmusHooks || window.CommandBus;
const debugLog = BUS && BUS.debugLog ? BUS.debugLog : function() {};

// ------------------------------------------------------------
// CORE CLASS
// ------------------------------------------------------------

class StarmusTranscript {
  constructor(peaksInstance, containerId, transcriptData) {
    this.peaks = peaksInstance;
    this.container = document.getElementById(containerId);
    this.data = Array.isArray(transcriptData) ? transcriptData : [];
    this.activeTokenIndex = -1;
    this.isUserScrolling = false;
    this.scrollTimeout = null;
    this.boundOnTimeUpdate = null;
    this.boundOnSeeked = null;
    this.boundOnClick = null;
    this.boundOnScroll = null;

    this.init();
  }

  init() {
    if (!this.container) {
      console.warn('[StarmusTranscript] Container not found. Transcript sync disabled.');
      return;
    }
    this.render();
    this.bindEvents();
  }

  render() {
    const frag = document.createDocumentFragment();
    this.data.forEach((token, idx) => {
      const span = document.createElement('span');
      span.textContent = token.text;
      span.className = 'starmus-word';
      span.dataset.index = idx;
      span.dataset.start = token.start;
      span.dataset.end = token.end;

      if ('confidence' in token && token.confidence < 0.8) {
        span.dataset.confidence = 'low';
        span.title = `Low confidence: ${Math.round(token.confidence * 100)}%`;
      }

      frag.appendChild(span);

      if (idx < this.data.length - 1) {
        frag.appendChild(document.createTextNode(' '));
      }
    });

    // Bulk replace for performance
    this.container.innerHTML = '';
    this.container.appendChild(frag);

    // reset any existing highlight index
    this.activeTokenIndex = -1;
  }

  bindEvents() {
    // Click-to-seek on word
    this.boundOnClick = (e) => {
      const w = e.target;
      if (w.classList.contains('starmus-word')) {
        const start = parseFloat(w.dataset.start);
        if (this.peaks && this.peaks.player && typeof this.peaks.player.seek === 'function') {
          this.peaks.player.seek(start);

          // Dispatch an event for external logic (analytics, UI state, etc.)
          if (BUS && typeof BUS.dispatch === 'function') {
            BUS.dispatch('starmus/transcript/seek', { time: start }, { instanceId: this.peaks.instanceId });
          }
        }
      }
    };
    this.container.addEventListener('click', this.boundOnClick);

    // Scroll detection (user scroll vs auto-scroll)
    this.boundOnScroll = () => {
      this.isUserScrolling = true;
      if (this.scrollTimeout) clearTimeout(this.scrollTimeout);
      this.scrollTimeout = setTimeout(() => {
        this.isUserScrolling = false;
      }, 1000);
    };
    this.container.addEventListener('scroll', this.boundOnScroll);

    // Playback sync: timeupdate + seeked
    const media = this.peaks && this.peaks.player && this.peaks.player.getMediaElement
      ? this.peaks.player.getMediaElement()
      : null;

    if (media && typeof media.addEventListener === 'function') {
      this.boundOnTimeUpdate = () => {
        const ct = media.currentTime;
        if (typeof ct === 'number' && !isNaN(ct)) {
          this.syncHighlight(ct);
        }
      };
      media.addEventListener('timeupdate', this.boundOnTimeUpdate);

      this.boundOnSeeked = () => {
        const ct = media.currentTime;
        if (typeof ct === 'number' && !isNaN(ct)) {
          this.syncHighlight(ct);
        }
      };
      media.addEventListener('seeked', this.boundOnSeeked);
    } else {
      debugLog('[StarmusTranscript] Player media element not found — sync disabled');
    }
  }

  findTokenIndex(time) {
    if (typeof time !== 'number' || this.data.length === 0) return -1;

    let low = 0, high = this.data.length - 1;

    while (low <= high) {
      const mid = (low + high) >> 1;
      const token = this.data[mid];
      if (time >= token.start && time <= token.end) return mid;
      if (time < token.start) high = mid - 1;
      else low = mid + 1;
    }
    return -1;
  }

  syncHighlight(currentTime) {
    const newIndex = this.findTokenIndex(currentTime);
    if (newIndex === -1) {
      this.clearHighlight();
    } else if (newIndex !== this.activeTokenIndex) {
      this.updateDOM(newIndex);
    }
  }

  updateDOM(newIndex) {
    const words = this.container.querySelectorAll('.starmus-word');
    if (this.activeTokenIndex >= 0 && words[this.activeTokenIndex]) {
      words[this.activeTokenIndex].classList.remove('is-active');
    }
    this.activeTokenIndex = newIndex;
    const el = words[newIndex];
    if (el) {
      el.classList.add('is-active');
      if (!this.isUserScrolling) {
        this.scrollToWord(el);
      }
    }
  }

  clearHighlight() {
    const prev = this.container.querySelector('.starmus-word.is-active');
    if (prev) prev.classList.remove('is-active');
    this.activeTokenIndex = -1;
  }

  scrollToWord(el) {
    if (el.scrollIntoView) {
      el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
  }

  updateData(newData) {
    this.data = Array.isArray(newData) ? newData : [];
    this.render();
    this.unbindEvents();
    this.bindEvents();
  }

  unbindEvents() {
    if (!this.container) return;

    if (this.boundOnClick) this.container.removeEventListener('click', this.boundOnClick);
    if (this.boundOnScroll) this.container.removeEventListener('scroll', this.boundOnScroll);

    const media = this.peaks && this.peaks.player && this.peaks.player.getMediaElement
      ? this.peaks.player.getMediaElement()
      : null;

    if (media && typeof media.removeEventListener === 'function') {
      if (this.boundOnTimeUpdate) media.removeEventListener('timeupdate', this.boundOnTimeUpdate);
      if (this.boundOnSeeked) media.removeEventListener('seeked', this.boundOnSeeked);
    }
  }

  destroy() {
    this.unbindEvents();
    if (this.scrollTimeout) clearTimeout(this.scrollTimeout);
    if (this.container) this.container.innerHTML = '';
    this.data = [];
    this.activeTokenIndex = -1;
    this.isUserScrolling = false;
  }
}

// --- EXPORT / GLOBAL EXPOSURE ---

function init(peaksInstance, containerId, transcriptData) {
  return new StarmusTranscript(peaksInstance, containerId, transcriptData);
}

if (typeof window !== 'undefined') {
  window.StarmusTranscript = StarmusTranscript;
  window.StarmusTranscriptController = { StarmusTranscript, init };
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { StarmusTranscript, init };
}

// also support ES module export
// (Note: in bundler context this may be tree‑shaken / replaced)
export { StarmusTranscript, init };
export default { StarmusTranscript, init };


