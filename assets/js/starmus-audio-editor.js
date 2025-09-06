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
 *
 * @typedef {object} Annotation
 * @property {string} id - A unique identifier for the annotation.
 * @property {number} startTime - The start time of the annotation in seconds.
 * @property {number} endTime - The end time of the annotation in seconds.
 * @property {string} [label] - The user-defined text for the annotation.
 */

(function () {
	'use strict';

	// --- Basic Setup & Data Validation ---

	if (typeof STARMUS_EDITOR_DATA === 'undefined') {
		console.error(
			'Starmus Error: Editor data (STARMUS_EDITOR_DATA) not found. Cannot initialize.'
		);
		return;
	}

	/**
	 * @const {object} STARMUS_EDITOR_DATA - Data localized from PHP.
	 * @property {string} restUrl - The URL for the REST API endpoint.
	 * @property {string} nonce - The nonce for REST API authentication.
	 * @property {number} postId - The ID of the post being edited.
	 * @property {string} audioUrl - The URL of the audio file to load.
	 * @property {Annotation[]} [annotations=[]] - The initial array of annotation objects.
	 */
	const {
		restUrl,
		nonce,
		postId,
		audioUrl,
		annotations = [],
	} = STARMUS_EDITOR_DATA;

	// --- DOM Element Caching & Validation ---

	/** @type {HTMLElement} */
	const editorRoot = document.querySelector('.starmus-editor');
	if (!editorRoot) return; // No editor on this page, exit gracefully.

	/** @type {HTMLElement}
	const overviewEl = document.getElementById('overview');
	/** @type {HTMLElement}
	const zoomviewEl = document.getElementById('zoomview');
	/** @type {HTMLButtonElement}
	const btnPlay = document.getElementById('play');
	/** @type {HTMLButtonElement}
	const btnAdd = document.getElementById('add-region');
	/** @type {HTMLButtonElement}
	const btnSave = document.getElementById('save');
	/** @type {HTMLTableSectionElement}
	const list = document.getElementById('regions-list');
	/** @type {HTMLElement}
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

	// --- State Management ---

	/**
	 * @type {boolean}
	 */
	let dirty = false;

	/**
	 * Sets the editor's dirty state and updates the Save button's disabled status.
	 * @param {boolean} val - The new dirty state.
	 */
	function setDirty(val) {
		dirty = val;
		btnSave.disabled = !dirty;
	}

	// Warn the user before they leave the page with unsaved changes.
	window.addEventListener('beforeunload', function (e) {
		if (dirty) {
			e.preventDefault();
			e.returnValue = ''; // Required for modern browsers.
		}
	});

	// --- UI Helpers ---

	/**
	 * Displays a message in the inline notice bar.
	 * @param {string|null} msg - The message to display. If null, the notice is hidden.
	 * @param {string} [type='error'] - The type of notice (e.g., 'error', 'success').
	 */
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

	/**
	 * @const {HTMLAudioElement} - The main audio element for playback.
	 */
	const audio = new Audio(audioUrl);
	audio.crossOrigin = 'anonymous'; // Required for Peaks.js to process audio from a different origin.

	audio.addEventListener('error', function () {
		showInlineNotice(
			'Audio failed to load. This may be a CORS issue. Ensure the server sends correct Cross-Origin-Resource-Policy headers.'
		);
	});

	// Initialize the editor only after the audio file's metadata (like duration) is loaded.
	audio.addEventListener('loadedmetadata', function () {
		if (!Number.isFinite(audio.duration) || audio.duration === 0) {
			showInlineNotice('Audio failed to load or has an invalid duration.');
			return;
		}

		// --- Data Normalization ---

		/**
		 * Cleans, sorts, and removes overlaps from an array of annotations.
		 * This is a critical defensive function to ensure data integrity.
		 * @param {Annotation[]} arr - The raw array of annotations.
		 * @returns {Annotation[]} The sanitized and sorted array of annotations.
		 */
		function normalizeAnnotations(arr) {
			// 1. Filter out any invalid or out-of-bounds segments.
			let sorted = arr
				.slice()
				.filter(
					(a) =>
						Number.isFinite(a.startTime) &&
						Number.isFinite(a.endTime) &&
						a.endTime > a.startTime &&
						a.startTime >= 0 &&
						a.endTime <= audio.duration
				);
			// 2. Sort by start time.
			sorted.sort((a, b) => a.startTime - b.startTime);

			// 3. Iterate and remove any segments that overlap with the previous one.
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

		/**
		 * Generates a universally unique identifier (UUID).
		 * Falls back to a less-unique ID if crypto is unavailable.
		 * @returns {string} The generated UUID.
		 */
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

		// --- Peaks.js Initialization ---
		const peaksOptions = {
			containers: { overview: overviewEl, zoomview: zoomviewEl },
			mediaElement: audio,
			height: 180,
			zoomLevels: [64, 128, 256, 512, 1024],
			keyboard: false, // Disable default keyboard to use our custom one.
			segments: initialSegments,
			allowSeeking: true,
		};

		Peaks.init(peaksOptions, function (err, peaks) {
			if (err) {
				console.error('Peaks.js initialization error:', err);
				showInlineNotice(
					'Could not load audio waveform. Please check the browser console for details.'
				);
				return;
			}

			// --- Time Display ---
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

			// --- Transport & Seek ---
			document.getElementById('back5').onclick = () => {
				audio.currentTime = Math.max(0, audio.currentTime - 5);
			};
			document.getElementById('fwd5').onclick = () => {
				audio.currentTime = Math.min(
					audio.duration || 0,
					audio.currentTime + 5
				);
			};

			// --- Zoom Controls ---
			document.getElementById('zoom-in').onclick = () =>
				peaks.zoom.zoomIn();
			document.getElementById('zoom-out').onclick = () =>
				peaks.zoom.zoomOut();
			document.getElementById('zoom-fit').onclick = () =>
				peaks.zoom.setZoom(0);

			// --- Loop Control ---
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
				loopRegion =
					regs.find((r) => t >= r.startTime && t < r.endTime) ||
					loopRegion ||
					regs[0];
				if (t >= loopRegion.endTime)
					audio.currentTime = loopRegion.startTime;
			});

			// --- Keyboard Shortcuts ---
			document.addEventListener('keydown', (e) => {
				if (e.target.matches('input,textarea')) return; // Ignore keypresses in input fields.
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
			});

			/**
			 * Renders the list of annotations/regions into the HTML table.
			 */
			function renderRegions() {
				const tbody = document.getElementById('regions-list');
				tbody.innerHTML = '';
				const regs = peaks.segments.getSegments();
				if (!regs.length) {
					const tr = document.createElement('tr');
					tr.innerHTML =
						'<td colspan="5">No annotations yet. Click "Add Region" to start.</td>';
					tbody.appendChild(tr);
					setDirty(dirty); // Re-evaluate save button state
					return;
				}

				regs.forEach((seg) => {
					const dur = seg.endTime - seg.startTime;
					const tr = document.createElement('tr');
					tr.innerHTML = `
            <td><input data-k="label" data-id="${
							seg.id
						}" value="${
						seg.label
							? String(seg.label).replace(/"/g, '&quot;')
							: ''
					}" maxlength="200" placeholder="Annotation" class="widefat" /></td>
            <td><input data-k="startTime" data-id="${
							seg.id
						}" type="number" step="0.01" min="0" value="${seg.startTime.toFixed(
						2
					)}" class="small-text" /></td>
            <td><input data-k="endTime" data-id="${
							seg.id
						}" type="number" step="0.01" min="0" value="${seg.endTime.toFixed(
						2
					)}" class="small-text" /></td>
            <td>${fmt(dur)}</td>
            <td>
              <button class="button" data-act="jump" data-id="${
								seg.id
							}">Jump</button>
              <button class="button button-link-delete" data-act="del" data-id="${
								seg.id
							}">Delete</button>
            </td>`;
					tbody.appendChild(tr);
				});
			}

			renderRegions();

			// --- Event Listeners for Annotation List (Delegated) ---
			let inputTimeout;
			list.addEventListener('input', (e) => {
				if (!e.target.dataset.id) return;
				clearTimeout(inputTimeout);
				// Debounce input to avoid excessive updates while typing.
				inputTimeout = setTimeout(() => {
					setDirty(true);
					const id = e.target.dataset.id;
					const key = e.target.dataset.k;
					const value =
						e.target.type === 'number'
							? parseFloat(e.target.value)
							: e.target.value;
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

			// --- Event Listeners for Main Controls ---
			btnAdd.onclick = () => {
				const currentTime = peaks.player.getCurrentTime();
				const start = Math.max(0, currentTime - 5);
				peaks.segments.add({
					id: getUUID(),
					startTime: start,
					endTime: currentTime,
					labelText: '',
					editable: true,
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
				showInlineNotice(null); // Clear previous notices.

				let payload = peaks.segments.getSegments().map((s) => ({
					id: s.id,
					startTime: s.startTime,
					endTime: s.endTime,
					label: (s.labelText || s.label || '').trim().slice(0, 200),
				}));
				payload = normalizeAnnotations(payload); // Final client-side cleanup

				try {
					const response = await fetch(restUrl, {
						method: 'POST',
						headers: {
							'X-WP-Nonce': nonce,
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({
							postId: postId,
							annotations: payload,
						}),
					});
					const data = await response.json(); // Try to parse JSON even on error

					if (!response.ok) {
						// Use server message if available, otherwise use generic status text.
						throw new Error(data.message || response.statusText);
					}

					if (data.success) {
						setDirty(false);
						showInlineNotice(
							'Annotations saved successfully!',
							'success'
						);
						// Re-render regions with the sanitized data from the server for consistency.
						if (data.annotations) {
							peaks.segments.removeAll();
							peaks.segments.add(data.annotations); // Server data is the source of truth.
							renderRegions();
						}
					} else {
						throw new Error(
							data.message || 'An unknown error occurred.'
						);
					}
				} catch (err) {
					console.error('Save failed:', err);
					showInlineNotice('Save failed: ' + err.message, 'error');
				} finally {
					saveLock = false;
					btnSave.textContent = 'Save';
					// Re-evaluate save button state based on dirty flag.
					btnSave.disabled = !dirty;
				}
			};
		});
	});
})();