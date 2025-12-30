/**
 * @file starmus-audio-editor.js
 * @version 1.0.4-GLOBALTHIS-FIX
 * @description Peaks.js waveform editor with modern global scope handling.
 */

/**
 * Universal Module Definition (UMD) wrapper for the Starmus Audio Editor.
 * Provides a waveform editor based on Peaks.js for audio annotation and editing.
 * Supports both CommonJS and browser global environments.
 *
 * @param {Object} global - The global object (window in browser, globalThis in other environments)
 * @param {Function} factory - Factory function that creates the editor module
 * @returns {Object} The StarmusAudioEditor module
 *
 * @example
 * // Browser usage
 * const editor = window.StarmusAudioEditor;
 * editor.init();
 *
 * @example
 * // CommonJS usage
 * const editor = require('./starmus-audio-editor.js');
 * editor.init();
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

	/**
   * Initializes the Peaks.js audio editor with annotation capabilities.
   * Requires STARMUS_EDITOR_DATA to be present in the global scope.
   *
   * Features:
   * - Waveform visualization with overview and zoom views
   * - Audio playback controls (play/pause, seek, skip)
   * - Annotation regions with editable labels and time boundaries
   * - Zoom controls and loop mode
   * - Save annotations to WordPress via REST API
   * - Real-time dirty state tracking
   *
   * @function init
   * @returns {void}
   *
   * @throws {Error} When STARMUS_EDITOR_DATA is not defined
   * @throws {Error} When required DOM elements are missing
   * @throws {Error} When audio file cannot be loaded
   * @throws {Error} When Peaks.js is not available
   *
   * @example
   * // Initialize the audio editor
   * window.STARMUS_EDITOR_DATA = {
   *   restUrl: '/wp-json/star-/v1/save-annotations',
   *   nonce: 'abc123',
   *   postId: 42,
   *   audioUrl: '/uploads/recording.wav',
   *   annotations: [{ startTime: 5.0, endTime: 10.0, label: 'Intro' }]
   * };
   * StarmusAudioEditor.init();
   */
	function init() {
		return new Promise((resolve, reject) => {
			const EDITOR_DATA = window.STARMUS_EDITOR_DATA;
			if (!EDITOR_DATA) {
				showNotice('Error loading editor.')
				reject(new Error('STARMUS_EDITOR_DATA not defined'));
				return;
			}

			const {
				restUrl,
				nonce,
				postId,
				audioUrl,
				annotations: initialAnnotations = [],
			} = EDITOR_DATA;

			// DOM
			const overviewEl = document.getElementById('overview');
			const zoomviewEl = document.getElementById('zoomview');
			const btnPlay = document.getElementById('play');
			const btnBack5 = document.getElementById('back5');
			const btnFwd5 = document.getElementById('fwd5');
			const btnZoomIn = document.getElementById('zoom-in');
			const btnZoomOut = document.getElementById('zoom-out');
			const btnZoomFit = document.getElementById('zoom-fit');
			const loopCheck = document.getElementById('loop');
			const btnAdd = document.getElementById('add-region');
			const btnSave = document.getElementById('save');
			const regionTable = document.getElementById('regions-list');
			const timeCur = document.getElementById('starmus-time-cur');
			const timeDur = document.getElementById('starmus-time-dur');

			if (
				!audioUrl ||
        !overviewEl ||
        !zoomviewEl ||
        !btnPlay ||
        !btnAdd ||
        !btnSave ||
        !regionTable
			) {
				showNotice('Error initializing editor.');
				reject(new Error('Missing required DOM elements'));
				return;
			}

			let dirty = false;
			const setDirty = (v) => {
				dirty = !!v;
				btnSave.disabled = !dirty;
			};
			setDirty(false);

			// NOTE:
			// `returnValue` is deprecated in the spec but still REQUIRED
			// by all major browsers to trigger the beforeunload dialog.
			// Do not remove unless browser behavior changes.
			window.addEventListener('beforeunload', (e) => {
				if (dirty) {
					e.preventDefault();
					e.returnValue = '';
				}
			});

			const audio = new Audio(audioUrl);
			audio.crossOrigin = 'anonymous';

			audio.addEventListener('error', () => {
				showNotice('Audio failed to load. Check the file or network.', 'error');
				reject(new Error('Audio load error'));
			});

			audio.addEventListener('loadedmetadata', () => {
				const duration = audio.duration;
				if (!isFinite(duration) || duration <= 0) {
					showNotice('Audio error, file corrupt');
					reject(new Error('Invalid audio duration'));
					return;
				}

				if (typeof Peaks === 'undefined') {
					showNotice('Peaks.js library is missing', 'error');

					reject(new Error('Peaks.js not loaded'));
					return;
				}

				const getUUID = () =>
					window.crypto?.randomUUID?.() || 'id-' + Math.random().toString(36).slice(2) + Date.now();

				const normalize = (arr) => {
					const valid = (arr || []).filter(
						(a) =>
							isFinite(a.startTime) &&
              isFinite(a.endTime) &&
              a.startTime >= 0 &&
              a.endTime > a.startTime &&
              a.endTime <= duration
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
								label: (seg.label || '').trim().slice(0, 200),
							});
							lastEnd = seg.endTime;
						}
					}
					return res;
				};

				const segments = normalize(initialAnnotations);

				Peaks.init(
					{
						containers: { overview: overviewEl, zoomview: zoomviewEl },
						mediaElement: audio,
						webAudio: {
							audioContext: new (window.AudioContext || window.webkitAudioContext)(),
							multiChannel: false,
						},
						height: 180,
						zoomLevels: [64, 128, 256, 512, 1024, 2048],
						segments,
						keyboard: true,
						showPlayheadTime: true,
						emitCueEvents: true,
					},
					(err, peaks) => {
						if (err) {
							reject(err);
							return;
						}

						// UI
						timeDur.textContent = formatTime(duration);
						audio.addEventListener('timeupdate', () => {
							timeCur.textContent = formatTime(audio.currentTime);
						});

						btnPlay.onclick = () => (audio.paused ? audio.play() : audio.pause());
						audio.addEventListener('play', () => (btnPlay.textContent = 'Pause'));
						audio.addEventListener('pause', () => (btnPlay.textContent = 'Play'));

						btnBack5.onclick = () => (audio.currentTime = Math.max(0, audio.currentTime - 5));
						btnFwd5.onclick = () => (audio.currentTime = Math.min(duration, audio.currentTime + 5));
						btnZoomIn.onclick = () => peaks.zoom.zoomIn();
						btnZoomOut.onclick = () => peaks.zoom.zoomOut();
						btnZoomFit.onclick = () => peaks.zoom.zoom(0);

						let looping = false;
						loopCheck.onchange = () => (looping = loopCheck.checked);

						audio.addEventListener('timeupdate', () => {
							if (!looping) {return;}
							const t = audio.currentTime;
							const segs = peaks.segments.getSegments();
							const r = segs.find((s) => t >= s.startTime && t < s.endTime);
							if (r && t >= r.endTime) {audio.currentTime = r.startTime;}
						});

						const render = () => {
							const segs = normalize(peaks.segments.getSegments());
							regionTable.innerHTML = '';
							if (!segs.length) {
								regionTable.innerHTML = '<tr><td colspan="5">No annotations</td></tr>';
								setDirty(false);
								return;
							}
							segs.forEach((s) => {
								const tr = document.createElement('tr');
								tr.innerHTML = `<td><input data-id="${s.id}" data-key="label" value="${escapeHTML(s.label)}"></td>
               <td>${s.startTime.toFixed(2)}</td>
               <td>${s.endTime.toFixed(2)}</td>
               <td><button data-act="jump" data-id="${s.id}">Jump</button>
                   <button data-act="del" data-id="${s.id}">Delete</button></td>`;
								regionTable.appendChild(tr);
							});
							setDirty(true);
						};

						regionTable.onclick = (e) => {
							const act = e.target.dataset.act;
							const id = e.target.dataset.id;
							if (!act || !id) {return;}
							const seg = peaks.segments.getSegment(id);
							if (!seg) {return;}
							if (act === 'jump') {
								audio.currentTime = seg.startTime;
								audio.play();
							} else if (act === 'del') {
								peaks.segments.removeById(id);
								render();
							}
						};

						btnAdd.onclick = () => {
							const t = audio.currentTime || 0;
							peaks.segments.add({
								id: getUUID(),
								startTime: Math.max(0, t - 2),
								endTime: Math.min(duration, t + 2),
								label: '',
							});
							render();
						};

						btnSave.onclick = async () => {
							if (!dirty) {return;}
							setDirty(false);
							try {
								await fetch(restUrl, {
									method: 'POST',
									headers: {
										'Content-Type': 'application/json',
										'X-WP-Nonce': nonce,
									},
									body: JSON.stringify({
										postId,
										annotations: normalize(peaks.segments.getSegments()),
									}),
								});
							} catch {
								setDirty(true);
							}
						};

						render();

						// ✅ THIS IS THE ONLY RESOLVE
						resolve(peaks);
					}
				);
			});
		});
	}


	/**
   * Displays a notification message to the user.
   * Shows success or error messages in a styled alert element.
   * Auto-hides after 5 seconds to maintain clean UI.
   *
   * @param {string} msg - Message text to display
   * @param {string} [type='error'] - Message type ('success', 'error', 'warning')
   * @returns {void}
   *
   * @example
   * // Show success message
   * showNotice('Annotations saved successfully!', 'success');
   *
   * @example
   * // Show error message
   * showNotice('Failed to save annotations', 'error');
   *
   * @example
   * // Hide current notice
   * showNotice();
   */
	function showNotice(msg, type = 'error') {
		const el = document.getElementById('starmus-editor-notice');
		if (!el) {return;}
		if (!msg) {
			el.hidden = true;
		} else {
			el.textContent = msg;
			el.className = `starmus-alert starmus-alert--${type}`;
			el.hidden = false;
			setTimeout(() => el.hidden = true, 5000);
		}
	}

	/**
   * Escapes HTML special characters to prevent XSS attacks.
   * Converts ampersands, brackets, and quotes to HTML entities.
   * Essential for safely displaying user input in HTML.
   *
   * @param {*} str - Input value to escape (converted to string)
   * @returns {string} HTML-safe escaped string
   *
   * @example
   * const userInput = '<script>alert("xss")</script>';
   * const safe = escapeHTML(userInput);
   * // Returns: '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'
   *
   * @example
   * const label = 'Q&A Session';
   * const escaped = escapeHTML(label);
   * // Returns: 'Q&amp;A Session'
   */
	function escapeHTML(str) {
		return ('' + str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	/**
   * Formats seconds into a human-readable time string (M:SS format).
   * Handles invalid input gracefully by returning '0:00'.
   * Used for displaying current time and duration in the UI.
   *
   * @param {number} s - Time in seconds
   * @returns {string} Formatted time string in M:SS format
   *
   * @example
   * formatTime(65.5);
   * // Returns: '1:05'
   *
   * @example
   * formatTime(3600);
   * // Returns: '60:00'
   *
   * @example
   * formatTime(NaN);
   * // Returns: '0:00'
   */
	function formatTime(s) {
		if (isNaN(s)) {return '0:00';}
		const m = Math.floor(s / 60);
		const sec = Math.floor(s % 60);
		return m + ':' + String(sec).padStart(2, '0');
	}

	/**
   * Public API interface for the Starmus Audio Editor.
   * Exposes only the init function for external consumption.
   *
   * @exports StarmusAudioEditor
   * @type {Object}
   * @property {Function} init - Initialize the audio editor
   */
	return { init };
});

/**
 * ES6 module export for build tools.
 * Re-exports the UMD module's init function for compatibility with
 * modern bundlers like Rollup and Webpack.
 *
 * @module starmus-audio-editor
 * @exports {Object} Default export with init method
 *
 * @example
 * import StarmusAudioEditor from './starmus-audio-editor.js';
 * StarmusAudioEditor.init();
 */
export default { init: window.StarmusAudioEditor.init };
