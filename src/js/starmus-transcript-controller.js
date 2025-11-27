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
 *
 * @typedef {object} TranscriptToken
 * @property {number} start - Start time in seconds
 * @property {number} end - End time in seconds
 * @property {string} text - The word or phrase text
 * @property {number} [confidence] - Optional confidence score (0-1)
 */

/**
 * Starmus Transcript Controller
 * Handles the bidirectional sync between audio time and text tokens.
 *
 * @class StarmusTranscript
 */
class StarmusTranscript {
  /**
   * Creates an instance of StarmusTranscript.
   *
   * @param {object} peaksInstance - The initialized Peaks.js instance
   * @param {string} containerId - ID of the transcript container element
   * @param {TranscriptToken[]} transcriptData - Array of time-stamped tokens
   */
  constructor(peaksInstance, containerId, transcriptData) {
    this.peaks = peaksInstance;
    this.container = document.getElementById(containerId);
    this.data = transcriptData || []; // Expects [{start, end, text, confidence?}, ...]
    this.activeTokenIndex = -1;
    this.isUserScrolling = false;

    // Scroll timeout to detect when user stops manual scrolling
    this.scrollTimeout = null;

    this.init();
  }

  /**
   * Initialize the transcript controller
   */
  init() {
    if (!this.container) {
      console.warn(
        "Starmus Transcript: Container not found. Transcript sync disabled.",
      );
      return;
    }

    this.render();
    this.bindEvents();
  }

  /**
   * Renders JSON transcript data to HTML spans
   */
  render() {
    this.container.innerHTML = "";
    const fragment = document.createDocumentFragment();

    this.data.forEach((token, index) => {
      const span = document.createElement("span");
      span.textContent = token.text;
      span.className = "starmus-word";

      // Critical Data Attributes
      span.dataset.index = index;
      span.dataset.start = token.start;
      span.dataset.end = token.end;

      // Optional: Confidence or Speaker coloring
      if (token.confidence && token.confidence < 0.8) {
        span.dataset.confidence = "low";
        span.title = `Low confidence: ${Math.round(token.confidence * 100)}%`;
      }

      fragment.appendChild(span);

      // Add space after each word (except last)
      if (index < this.data.length - 1) {
        fragment.appendChild(document.createTextNode(" "));
      }
    });

    this.container.appendChild(fragment);
  }

  /**
   * Attach event listeners for interaction
   */
  bindEvents() {
    // 1. CLICK TO SEEK (User -> Audio)
    this.container.addEventListener("click", (e) => {
      if (e.target.classList.contains("starmus-word")) {
        const startTime = parseFloat(e.target.dataset.start);
        // Seek Peaks.js player
        this.peaks.player.seek(startTime);
      }
    });

    // 2. DETECT MANUAL SCROLL (Don't auto-scroll while user is reading)
    this.container.addEventListener("scroll", () => {
      this.isUserScrolling = true;
      clearTimeout(this.scrollTimeout);
      this.scrollTimeout = setTimeout(() => {
        this.isUserScrolling = false;
      }, 1000); // Resume auto-scroll 1s after user stops scrolling
    });

    // 3. AUDIO SYNC (Audio -> UI)
    // Peaks.js exposes the underlying audio element events
    const mediaElement = this.peaks.player.getMediaElement();
    if (mediaElement) {
      mediaElement.addEventListener("timeupdate", () => {
        this.syncHighlight(this.peaks.player.getCurrentTime());
      });
    }
  }

  /**
   * The Sync Logic
   * Highlights the current word based on playback time
   *
   * @param {number} currentTime - Current playback time in seconds
   */
  syncHighlight(currentTime) {
    // Optimization: Check if we are still within the current token
    const currentToken = this.data[this.activeTokenIndex];

    if (
      currentToken &&
      currentTime >= currentToken.start &&
      currentTime <= currentToken.end
    ) {
      return; // Nothing changed, exit early
    }

    // Find the new active token
    // (Simple loop is fine for < 1hr audio. Binary search needed for audiobooks)
    const newIndex = this.data.findIndex(
      (t) => currentTime >= t.start && currentTime <= t.end,
    );

    if (newIndex !== -1 && newIndex !== this.activeTokenIndex) {
      this.updateDOM(newIndex);
    }
  }

  /**
   * Update the DOM to reflect the new active token
   *
   * @param {number} newIndex - Index of the new active token
   */
  updateDOM(newIndex) {
    // Remove old highlight
    if (this.activeTokenIndex !== -1) {
      const oldNode = this.container.children[this.activeTokenIndex * 2]; // *2 because of text nodes
      if (oldNode) {
        oldNode.classList.remove("is-active");
      }
    }

    // Add new highlight
    this.activeTokenIndex = newIndex;
    const newNode = this.container.children[newIndex * 2]; // *2 because of text nodes

    if (newNode) {
      newNode.classList.add("is-active");

      // Auto-scroll logic
      if (!this.isUserScrolling) {
        this.scrollToWord(newNode);
      }
    }
  }

  /**
   * Scroll the container to bring the active word into view
   *
   * @param {HTMLElement} element - The element to scroll to
   */
  scrollToWord(element) {
    const parentRect = this.container.getBoundingClientRect();
    const elementRect = element.getBoundingClientRect();

    // Check if element is out of the visible viewport (top or bottom)
    const isAbove = elementRect.top < parentRect.top;
    const isBelow = elementRect.bottom > parentRect.bottom;

    if (isAbove || isBelow) {
      element.scrollIntoView({
        behavior: "smooth",
        block: "center", // Put the active word in the middle of the panel
      });
    }
  }

  /**
   * Update transcript data (e.g., when loading new audio)
   *
   * @param {TranscriptToken[]} newData - New transcript data
   */
  updateData(newData) {
    this.data = newData || [];
    this.activeTokenIndex = -1;
    this.render();
  }

  /**
   * Destroy the controller and clean up event listeners
   */
  destroy() {
    if (this.scrollTimeout) {
      clearTimeout(this.scrollTimeout);
    }
    if (this.container) {
      this.container.innerHTML = "";
    }
  }
}

// Export for module bundlers or attach to window for global access
if (typeof module !== "undefined" && module.exports) {
  module.exports = StarmusTranscript;
} else {
  window.StarmusTranscript = StarmusTranscript;
}
