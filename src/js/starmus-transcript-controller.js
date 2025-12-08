/**
 * STARISIAN TECHNOLOGIES CONFIDENTIAL
 * © 2023–2025 Starisian Technologies. All Rights Reserved.
 *
 * SPDX-License-Identifier:  LicenseRef-Starisian-Technologies-Proprietary
 * License URI:              https://github.com/Starisian-Technologies/starmus-audio-recorder/LICENSE.md
 */

/**
 * @file Starmus Transcript Controller - Bidirectional audio-text synchronization
 * @description Handles the "karaoke-style" transcript panel that syncs with audio playback.
 * Provides click-to-seek, auto-scroll with user-scroll detection, and confidence indicators.
 */

class StarmusTranscript {
  constructor(peaksInstance, containerId, transcriptData) {
    this.peaks = peaksInstance;
    this.container = document.getElementById(containerId);
    this.data = transcriptData || [];
    this.activeTokenIndex = -1;
    this.isUserScrolling = false;
    this.scrollTimeout = null;

    this.init();
  }

  init() {
    if (!this.container) {
      console.warn('Starmus Transcript: Container not found. Transcript sync disabled.');
      return;
    }

    this.render();
    this.bindEvents();
  }

  render() {
    const tmpContainer = document.createElement('div');

    this.data.forEach((token, index) => {
      const span = document.createElement('span');
      span.textContent = token.text;
      span.className = 'starmus-word';

      span.dataset.index = index;
      span.dataset.start = token.start;
      span.dataset.end = token.end;

      if (token.confidence && token.confidence < 0.8) {
        span.dataset.confidence = 'low';
        span.title = `Low confidence: ${Math.round(token.confidence * 100)}%`;
      }

      tmpContainer.appendChild(span);
      if (index < this.data.length - 1) {
        tmpContainer.appendChild(document.createTextNode(' '));
      }
    });

    // 🚀 Fragment Optimization (swap in bulk)
    this.container.replaceChildren(...tmpContainer.childNodes);
  }

  bindEvents() {
    this.container.addEventListener('click', (e) => {
      if (e.target.classList.contains('starmus-word')) {
        const startTime = parseFloat(e.target.dataset.start);
        this.peaks.player.seek(startTime);
      }
    });

    this.container.addEventListener('scroll', () => {
      this.isUserScrolling = true;
      clearTimeout(this.scrollTimeout);
      this.scrollTimeout = setTimeout(() => {
        this.isUserScrolling = false;
      }, 1000);
    });

    const mediaElement = this.peaks.player.getMediaElement();
    if (mediaElement) {
      mediaElement.addEventListener('timeupdate', () => {
        this.syncHighlight(this.peaks.player.getCurrentTime());
      });
    }
  }

  /**
   * Efficient binary search for the current token based on playback time
   */
  findTokenIndex(currentTime) {
    let low = 0;
    let high = this.data.length - 1;

    while (low <= high) {
      const mid = Math.floor((low + high) / 2);
      const token = this.data[mid];

      if (currentTime >= token.start && currentTime <= token.end) {
        return mid;
      } else if (currentTime < token.start) {
        high = mid - 1;
      } else {
        low = mid + 1;
      }
    }

    return -1;
  }

  syncHighlight(currentTime) {
    const currentToken = this.data[this.activeTokenIndex];

    if (currentToken && currentTime >= currentToken.start && currentTime <= currentToken.end) {
      return; // still in same token
    }

    const newIndex = this.findTokenIndex(currentTime);
    if (newIndex !== -1 && newIndex !== this.activeTokenIndex) {
      this.updateDOM(newIndex);
    }
  }

  updateDOM(newIndex) {
    const words = this.container.querySelectorAll('.starmus-word');

    if (this.activeTokenIndex !== -1) {
      const oldNode = words[this.activeTokenIndex];
      if (oldNode) {
        oldNode.classList.remove('is-active');
      }
    }

    this.activeTokenIndex = newIndex;

    const newNode = words[newIndex];
    if (newNode) {
      newNode.classList.add('is-active');

      if (!this.isUserScrolling) {
        this.scrollToWord(newNode);
      }
    }
  }

  scrollToWord(element) {
    const parentRect = this.container.getBoundingClientRect();
    const elementRect = element.getBoundingClientRect();

    const isAbove = elementRect.top < parentRect.top;
    const isBelow = elementRect.bottom > parentRect.bottom;

    if (isAbove || isBelow) {
      element.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
      });
    }
  }

  updateData(newData) {
    this.data = newData || [];
    this.activeTokenIndex = -1;
    this.render();
  }

  destroy() {
    if (this.scrollTimeout) {
      clearTimeout(this.scrollTimeout);
    }
    if (this.container) {
      this.container.innerHTML = '';
    }
  }
}

if (typeof window !== 'undefined') {
  window.StarmusTranscript = StarmusTranscript;
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = StarmusTranscript;
}
