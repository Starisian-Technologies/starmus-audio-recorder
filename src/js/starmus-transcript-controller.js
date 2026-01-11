/**
 * @file starmus-transcript-controller.js
 * @version 1.3.0
 * @description Handles the "karaoke‑style" transcript panel that syncs with audio playback.
 * Provides click-to-seek, auto-scroll with user-scroll detection, confidence indicators,
 * clean re-init/destroy, and fallback safety for older browsers.
 *
 * Features:
 * - Real-time word highlighting synchronized with audio playback
 * - Click-to-seek functionality on individual words
 * - Auto-scrolling with intelligent user scroll detection
 * - Confidence indicators for low-accuracy transcription
 * - Binary search for efficient time-based word lookup
 * - Clean initialization and destruction for memory management
 * - Cross-browser compatibility with graceful fallbacks
 */

'use strict';

/**
 * Import StarmusHooks for event bus functionality.
 * Ensures global hooks are available for command dispatching.
 */
// --- Dependency Import (ensure global hooks exist) ---
import './starmus-hooks.js';

/**
 * Global command bus reference with fallback.
 * Used for dispatching transcript events and debugging.
 * @type {object}
 */
const BUS = window.CommandBus || window.StarmusHooks;

/**
 * Debug logging function with fallback no-op.
 * @type {function}
 */
const debugLog = BUS && BUS.debugLog ? BUS.debugLog : function () {};

// ------------------------------------------------------------
// CORE CLASS
// ------------------------------------------------------------

/**
 * StarmusTranscript class for synchronized transcript display.
 * Manages word-level highlighting, click-to-seek, and auto-scrolling
 * synchronized with audio playback through Peaks.js integration.
 *
 * @class
 * @example
 * const transcript = new StarmusTranscript(
 *   peaksInstance,
 *   'transcript-container',
 *   [{ text: 'Hello', start: 0.0, end: 0.5, confidence: 0.95 }]
 * );
 */
class StarmusTranscript {
	/**
   * Creates a StarmusTranscript instance.
   *
   * @constructor
   * @param {Object} peaksInstance - Peaks.js waveform instance with player
   * @param {Object} peaksInstance.player - Audio player with seek functionality
   * @param {function} peaksInstance.player.seek - Function to seek to time position
   * @param {function} peaksInstance.player.getMediaElement - Function to get media element
   * @param {string} peaksInstance.instanceId - Instance ID for event dispatching
   * @param {string} containerId - DOM element ID for transcript container
   * @param {Array<Object>} transcriptData - Array of word timing objects
   * @param {string} transcriptData[].text - Word text content
   * @param {number} transcriptData[].start - Start time in seconds
   * @param {number} transcriptData[].end - End time in seconds
   * @param {number} [transcriptData[].confidence] - Confidence score (0.0-1.0)
   */
	constructor(peaksInstance, containerId, transcriptData) {
		/**
     * Peaks.js instance for audio control.
     * @type {Object}
     */
		this.peaks = peaksInstance;

		/**
     * DOM container element for transcript display.
     * @type {HTMLElement|null}
     */
		this.container = document.getElementById(containerId);

		/**
     * Array of word timing data objects.
     * @type {Array<Object>}
     */
		this.data = Array.isArray(transcriptData) ? transcriptData : [];

		/**
     * Index of currently highlighted word.
     * @type {number}
     */
		this.activeTokenIndex = -1;

		/**
     * Flag indicating user is manually scrolling.
     * @type {boolean}
     */
		this.isUserScrolling = false;

		/**
     * Timeout ID for scroll detection reset.
     * @type {number|null}
     */
		this.scrollTimeout = null;

		/**
     * Bound timeupdate event handler reference.
     * @type {function|null}
     */
		this.boundOnTimeUpdate = null;

		/**
     * Bound seeked event handler reference.
     * @type {function|null}
     */
		this.boundOnSeeked = null;

		/**
     * Bound click event handler reference.
     * @type {function|null}
     */
		this.boundOnClick = null;

		/**
     * Bound scroll event handler reference.
     * @type {function|null}
     */
		this.boundOnScroll = null;

		this.init();
	}

	/**
   * Initializes the transcript controller.
   * Sets up DOM rendering and event binding if container exists.
   *
   * @method
   * @returns {void}
   */
	init() {
		if (!this.container) {
			console.warn('[StarmusTranscript] Container not found. Transcript sync disabled.');
			return;
		}
		this.render();
		this.bindEvents();
	}

	/**
   * Renders transcript words into DOM container.
   * Creates word spans with timing data attributes and confidence indicators.
   * Uses DocumentFragment for efficient bulk DOM updates.
   *
   * @method
   * @returns {void}
   */
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

	/**
   * Binds all event handlers for transcript functionality.
   * Sets up click-to-seek, scroll detection, and audio synchronization.
   *
   * @method
   * @returns {void}
   *
   * @description Event handlers:
   * - Click: Seeks audio to clicked word's start time
   * - Scroll: Detects user scrolling to pause auto-scroll
   * - Timeupdate: Syncs highlight with audio playback position
   * - Seeked: Updates highlight when audio position changes
   */
	bindEvents() {
		/**
     * Click-to-seek handler for word elements.
     * Extracts start time and seeks audio player to that position.
     */
		// Click-to-seek on word
		this.boundOnClick = (e) => {
			const w = e.target;
			if (w.classList.contains('starmus-word')) {
				const start = parseFloat(w.dataset.start);
				if (this.peaks && this.peaks.player && typeof this.peaks.player.seek === 'function') {
					this.peaks.player.seek(start);

					// Dispatch an event for external logic (analytics, UI state, etc.)
					if (BUS && typeof BUS.dispatch === 'function') {
						BUS.dispatch(
							'starmus/transcript/seek',
							{ time: start },
							{ instanceId: this.peaks.instanceId }
						);
					}
				}
			}
		};
		this.container.addEventListener('click', this.boundOnClick);

		/**
     * Scroll detection handler.
     * Sets user scrolling flag and resets it after timeout.
     */
		// Scroll detection (user scroll vs auto-scroll)
		this.boundOnScroll = () => {
			this.isUserScrolling = true;
			if (this.scrollTimeout) {
				clearTimeout(this.scrollTimeout);
			}
			this.scrollTimeout = setTimeout(() => {
				this.isUserScrolling = false;
			}, 1000);
		};
		this.container.addEventListener('scroll', this.boundOnScroll);

		/**
     * Audio synchronization setup.
     * Binds to media element timeupdate and seeked events.
     */
		// Playback sync: timeupdate + seeked
		const media =
      this.peaks && this.peaks.player && this.peaks.player.getMediaElement
      	? this.peaks.player.getMediaElement()
      	: null;

		if (media && typeof media.addEventListener === 'function') {
			/**
       * Timeupdate handler for continuous playback sync.
       */
			this.boundOnTimeUpdate = () => {
				const ct = media.currentTime;
				if (typeof ct === 'number' && !isNaN(ct)) {
					this.syncHighlight(ct);
				}
			};
			media.addEventListener('timeupdate', this.boundOnTimeUpdate);

			/**
       * Seeked handler for position change sync.
       */
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

	/**
   * Finds the token index for a given time using binary search.
   * Efficiently locates which word should be highlighted at the current time.
   *
   * @method
   * @param {number} time - Current audio time in seconds
   * @returns {number} Index of matching token, or -1 if not found
   *
   * @description Uses binary search algorithm for O(log n) performance.
   * Checks if time falls within token's start-end range.
   */
	findTokenIndex(time) {
		if (typeof time !== 'number' || this.data.length === 0) {
			return -1;
		}

		let low = 0,
			high = this.data.length - 1;

		while (low <= high) {
			const mid = (low + high) >> 1;
			const token = this.data[mid];
			if (time >= token.start && time <= token.end) {
				return mid;
			}
			if (time < token.start) {
				high = mid - 1;
			} else {
				low = mid + 1;
			}
		}
		return -1;
	}

	/**
   * Synchronizes word highlighting with current audio time.
   * Updates active token index and triggers DOM updates if changed.
   *
   * @method
   * @param {number} currentTime - Current audio playback time
   * @returns {void}
   */
	syncHighlight(currentTime) {
		const newIndex = this.findTokenIndex(currentTime);
		if (newIndex === -1) {
			this.clearHighlight();
		} else if (newIndex !== this.activeTokenIndex) {
			this.updateDOM(newIndex);
		}
	}

	/**
   * Updates DOM to highlight new active word.
   * Removes previous highlight and adds new one with optional auto-scroll.
   *
   * @method
   * @param {number} newIndex - Index of word to highlight
   * @returns {void}
   */
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

	/**
   * Clears all word highlighting.
   * Removes active class and resets active token index.
   *
   * @method
   * @returns {void}
   */
	clearHighlight() {
		const prev = this.container.querySelector('.starmus-word.is-active');
		if (prev) {
			prev.classList.remove('is-active');
		}
		this.activeTokenIndex = -1;
	}

	/**
   * Scrolls container to show specified word element.
   * Uses smooth scrolling with center alignment when available.
   *
   * @method
   * @param {HTMLElement} el - Word element to scroll to
   * @returns {void}
   */
	scrollToWord(el) {
		if (el.scrollIntoView) {
			el.scrollIntoView({ block: 'center', behavior: 'smooth' });
		}
	}

	/**
   * Updates transcript data and re-initializes display.
   * Replaces current data, re-renders DOM, and rebinds events.
   *
   * @method
   * @param {Array<Object>} newData - New transcript data array
   * @param {string} newData[].text - Word text content
   * @param {number} newData[].start - Start time in seconds
   * @param {number} newData[].end - End time in seconds
   * @param {number} [newData[].confidence] - Confidence score (0.0-1.0)
   * @returns {void}
   */
	updateData(newData) {
		this.data = Array.isArray(newData) ? newData : [];
		this.render();
		this.unbindEvents();
		this.bindEvents();
	}

	/**
   * Unbinds all event handlers to prevent memory leaks.
   * Removes listeners from container and media elements.
   *
   * @method
   * @returns {void}
   */
	unbindEvents() {
		if (!this.container) {
			return;
		}

		if (this.boundOnClick) {
			this.container.removeEventListener('click', this.boundOnClick);
		}
		if (this.boundOnScroll) {
			this.container.removeEventListener('scroll', this.boundOnScroll);
		}

		const media =
      this.peaks && this.peaks.player && this.peaks.player.getMediaElement
      	? this.peaks.player.getMediaElement()
      	: null;

		if (media && typeof media.removeEventListener === 'function') {
			if (this.boundOnTimeUpdate) {
				media.removeEventListener('timeupdate', this.boundOnTimeUpdate);
			}
			if (this.boundOnSeeked) {
				media.removeEventListener('seeked', this.boundOnSeeked);
			}
		}
	}

	/**
   * Destroys the transcript instance and cleans up all resources.
   * Unbinds events, clears timeouts, empties container, and resets state.
   * Call this method when transcript is no longer needed.
   *
   * @method
   * @returns {void}
   */
	destroy() {
		this.unbindEvents();
		if (this.scrollTimeout) {
			clearTimeout(this.scrollTimeout);
		}
		if (this.container) {
			this.container.innerHTML = '';
		}
		this.data = [];
		this.activeTokenIndex = -1;
		this.isUserScrolling = false;
	}
}

// --- EXPORT / GLOBAL EXPOSURE ---

/**
 * Factory function to create a new StarmusTranscript instance.
 * Provides a convenient way to initialize transcript controller.
 *
 * @function
 * @param {Object} peaksInstance - Peaks.js waveform instance
 * @param {string} containerId - DOM element ID for transcript container
 * @param {Array<Object>} transcriptData - Array of word timing objects
 * @returns {StarmusTranscript} New transcript controller instance
 *
 * @example
 * const transcript = init(peaks, 'transcript-div', wordData);
 */
function init(peaksInstance, containerId, transcriptData) {
	return new StarmusTranscript(peaksInstance, containerId, transcriptData);
}

/**
 * Global browser environment exports.
 * Makes StarmusTranscript available on window object.
 * @global
 */
if (typeof window !== 'undefined') {
	/**
   * Global StarmusTranscript class reference.
   * @global
   * @type {function}
   */
	window.StarmusTranscript = StarmusTranscript;

	/**
   * Global transcript controller object with class and factory function.
   * @global
   * @namespace StarmusTranscriptController
   * @property {function} StarmusTranscript - The main transcript class
   * @property {function} init - Factory function for creating instances
   */
	window.StarmusTranscriptController = { StarmusTranscript, init };
}

/**
 * CommonJS module exports for Node.js environments.
 */
if (typeof module !== 'undefined' && module.exports) {
	module.exports = { StarmusTranscript, init };
}

/**
 * ES6 module exports for modern build systems.
 * @exports {function} StarmusTranscript - Main transcript class
 * @exports {function} init - Factory function
 */
// also support ES module export
// (Note: in bundler context this may be tree‑shaken / replaced)
export { StarmusTranscript, init };

/**
 * Default export object for ES6 import statements.
 * @default
 */
export default { StarmusTranscript, init };
