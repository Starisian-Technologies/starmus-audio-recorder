class RhythmEngine {
	constructor() {
		const StarmusProsodyData = window.StarmusProsodyData;

		// DEFENSIVE: 1. Check for data payload
		if (typeof StarmusProsodyData === 'undefined') {
			console.error('Starmus Prosody: Data payload missing.');
			return;
		}
		this.config = StarmusProsodyData; // Correct: Data is already the flat object

		// DEFENSIVE: 2. Check for required DOM elements
		this.els = {
			stage: document.getElementById('scaffold-stage'),
			container: document.getElementById('text-flow'),
			calibration: document.getElementById('calibration-layer'),
			controls: document.getElementById('main-controls'),
			tapZone: document.getElementById('btn-tap'),
			tapFeedback: document.getElementById('tap-feedback'),
			playBtn: document.getElementById('btn-engage'),
			recalBtn: document.getElementById('btn-recal'),
			slider: document.getElementById('pace-regulator'),
		};

		for (const [key, el] of Object.entries(this.els)) {
			if (!el) {
				console.error(`Starmus Prosody: Critical DOM element missing: ${key}`);
				return;
			}
		}

		// Engine State
		this.units = [];
		this.currentIndex = -1;
		this.isPlaying = false;
		this.timer = null;
		this.paceDebounce = null;

		// Settings
		this.chunkSize = parseInt(this.config.density) || 28;
		this.paceMs = parseInt(this.config.startPace) || 3000;

		// Calibration State
		this.tapTimes = [];
		this.requiredTaps = 4;

		this.init();
	}

	init() {
		this.renderChunks(this.config.source);
		this.bindEvents();
		this.bindRecorderIntegration();

		this.els.slider.value = this.paceMs;

		// If a saved pace exists, show feedback but stay in calibration mode
		if (this.paceMs && this.config.startPace > 0) {
			this.els.tapFeedback.innerText = `Saved Rhythm: ${this.paceMs}ms`;
			this.els.tapFeedback.style.opacity = 0.6;
		}
	}

	/**
   * SEGMENTATION
   * Breaks text into units and handles Silence Beats (|)
   */
	renderChunks(rawText) {
		try {
			if (!rawText || typeof rawText !== 'string') {
				throw new Error('Invalid Source');
			}
			const safeText = rawText.replace(/\|/g, ' | ');
			const words = safeText.split(/\s+/);

			let buffer = [];
			let len = 0;

			this.els.container.innerHTML = '';
			this.units = [];

			words.forEach((word) => {
				if (word === '|') {
					if (buffer.length > 0) {
						this.createUnit(buffer.join(' '), false);
						buffer = [];
						len = 0;
					}
					this.createUnit('', true); // Silence Unit
				} else {
					if (len + word.length > this.chunkSize && buffer.length > 0) {
						this.createUnit(buffer.join(' '), false);
						buffer = [];
						len = 0;
					}
					buffer.push(word);
					len += word.length;
				}
			});

			if (buffer.length > 0) {
				this.createUnit(buffer.join(' '), false);
			}

			// Initial Visual State (All Future)
			this.units.forEach((u) => u.classList.add('future'));

			// Ready at start (but don't scroll yet)
			if (this.units.length > 0) {
				this.currentIndex = -1;
				this.units[0].classList.remove('future');
				this.units[0].classList.add('current');

				// Add this to the end of your renderChunks() method in JS
				const endSpacer = document.createElement('div');
				endSpacer.className = 'spacer';
				this.els.container.appendChild(endSpacer);
			}
		} catch (e) {
			this.els.container.innerText = 'Error loading prosody text.';
			console.error(e);
		}
	}

	createUnit(text, isSilence) {
		const span = document.createElement('span');
		// Base state is handled in batch later for performance
		span.className = 'prosodic-unit';

		if (isSilence) {
			span.classList.add('silence-beat');
			span.innerHTML = '&bull;';
		} else {
			span.innerText = text;
		}

		// Emergency Jump (Manual Intervention)
		span.addEventListener('click', (e) => {
			e.stopPropagation();
			this.stop();
			this.jumpTo(this.units.indexOf(span));
		});

		this.els.container.appendChild(span);
		this.units.push(span);
	}

	/**
   * OPTIMIZED VISUAL ENGINE (O(1))
   * Only updates the specific nodes changing state.
   */
	jumpTo(index) {
		// Full Reset (Expensive, but rarely used)
		this.units.forEach((u, i) => {
			u.classList.remove('past', 'current', 'future');
			if (i < index) {
				u.classList.add('past');
			} else if (i === index) {
				u.classList.add('current');
			} else {
				u.classList.add('future');
			}
		});

		this.currentIndex = index;
		this.performScroll(index);
	}

	tick() {
		// Calculate Next
		const nextIndex = this.currentIndex + 1;

		if (nextIndex >= this.units.length) {
			this.stop();
			return;
		}

		// 1. Update Old Current -> Past
		if (this.units[this.currentIndex]) {
			const oldEl = this.units[this.currentIndex];
			oldEl.classList.remove('current');
			oldEl.classList.add('past');
		}

		// 2. Update New Current -> Current
		if (this.units[nextIndex]) {
			const newEl = this.units[nextIndex];
			newEl.classList.remove('future');
			newEl.classList.add('current');
		}

		this.currentIndex = nextIndex;
		this.performScroll(nextIndex);
	}

	/**
   * OPTIMIZED SCROLL (Jank-Free)
   * Uses math instead of scrollIntoView to avoid layout thrashing.
   */
	performScroll(index) {
		const el = this.units[index];
		if (!el) {
			return;
		}

		// Perform calculation in the animation frame to sync with refresh rate
		requestAnimationFrame(() => {
			const stageHeight = this.els.stage.clientHeight;
			// Calculate position relative to container
			const elTop = el.offsetTop;
			const elHeight = el.offsetHeight;

			// Math: Center the element
			const targetScroll = elTop - stageHeight / 2 + elHeight / 2;

			this.els.stage.scrollTo({
				top: targetScroll,
				behavior: 'smooth',
			});
		});
	}

	/**
   * ENGINE CONTROLS
   */
	play() {
		if (this.isPlaying) {
			return;
		}
		this.isPlaying = true;

		// Immediate tick to start moving
		this.tick();

		this.timer = setInterval(() => this.tick(), this.paceMs);
		this.updatePlayBtn();
	}

	stop() {
		this.isPlaying = false;
		clearInterval(this.timer);
		this.updatePlayBtn();
	}

	toggle() {
		this.isPlaying ? this.stop() : this.play();
	}

	updatePace(ms) {
		this.paceMs = ms;
		this.els.slider.value = ms;

		if (this.isPlaying) {
			clearInterval(this.timer);
			this.timer = setInterval(() => this.tick(), this.paceMs);
		}
	}

	updatePlayBtn() {
		const icon = this.els.playBtn.querySelector('.icon');
		const label = this.els.playBtn.querySelector('.label');

		if (this.isPlaying) {
			icon.innerText = 'II';
			label.innerText = 'PAUSE FLOW';
			this.els.playBtn.classList.add('active');
		} else {
			icon.innerText = '▶';
			label.innerText = 'ENGAGE FLOW';
			this.els.playBtn.classList.remove('active');
		}
	}

	/**
   * CALIBRATION LOGIC
   */
	recordTap() {
		const now = Date.now();
		this.els.tapZone.classList.add('flash');
		setTimeout(() => this.els.tapZone.classList.remove('flash'), 100);

		// Reset logic
		if (this.tapTimes.length > 0 && now - this.tapTimes[this.tapTimes.length - 1] > 2000) {
			this.tapTimes = [];
			this.els.tapFeedback.innerText = 'Rhythm lost. Start again.';
			this.els.tapFeedback.style.opacity = 1;
		}

		this.tapTimes.push(now);

		if (this.tapTimes.length > 1) {
			const intervals = [];
			for (let i = 1; i < this.tapTimes.length; i++) {
				intervals.push(this.tapTimes[i] - this.tapTimes[i - 1]);
			}
			const avg = Math.round(intervals.reduce((a, b) => a + b) / intervals.length);

			this.els.tapFeedback.innerText = `Detecting... ${avg}ms`;
			this.els.tapFeedback.style.opacity = 1;

			if (this.tapTimes.length >= this.requiredTaps) {
				this.transitionToStage(avg);
			}
		}
	}

	transitionToStage(ms) {
		if (ms < 1000) {
			ms = 1000;
		}
		if (ms > 6000) {
			ms = 6000;
		}

		this.updatePace(ms);
		this.savePaceToDatabase(ms);

		this.els.tapFeedback.innerText = 'RHYTHM LOCKED';
		this.els.tapFeedback.style.color = '#fff';

		setTimeout(() => {
			this.els.calibration.classList.add('fade-out');
			setTimeout(() => {
				this.els.calibration.style.display = 'none';
				this.els.stage.classList.remove('hidden');
				this.els.controls.classList.remove('hidden');
			}, 500);
		}, 600);
	}

	resetCalibration() {
		this.stop();
		this.tapTimes = [];
		this.els.calibration.style.display = 'flex';
		this.els.calibration.classList.remove('fade-out');
		this.els.stage.classList.add('hidden');
		this.els.controls.classList.add('hidden');
		this.els.tapFeedback.innerText = 'Tap to set new pace';
		this.els.tapFeedback.style.color = 'var(--accent)';
	}

	/**
   * NETWORK / SAVE
   * Added AbortController for network timeouts and offline check.
   */
	savePaceToDatabase(ms) {
		if (!this.config.nonce) {
			return;
		}

		// Offline check
		if (navigator.onLine === false) {
			console.warn('Offline: Pace change cached locally only.');
			return;
		}

		const controller = new AbortController();
		const timeoutId = setTimeout(() => controller.abort(), 3000); // 3s Timeout

		const data = new FormData();
		data.append('action', 'starmus_save_pace');
		data.append('post_id', this.config.postID);
		data.append('pace_ms', ms);
		data.append('nonce', this.config.nonce);

		fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			body: data,
			signal: controller.signal,
		})
			.then((res) => res.json())
			.then((res) => {
				clearTimeout(timeoutId);
				if (!res.success) {
					console.warn('Save warning:', res);
				}
			})
			.catch((err) => console.error('Save failed or timed out:', err));
	}

	bindEvents() {
		this.els.tapZone.addEventListener('click', () => this.recordTap());
		this.els.playBtn.addEventListener('click', () => this.toggle());
		this.els.recalBtn.addEventListener('click', () => this.resetCalibration());

		// PERFORMANCE: Debounced Slider Input
		this.els.slider.addEventListener('input', (e) => {
			clearTimeout(this.paceDebounce);
			const val = parseInt(e.target.value);
			// Wait 80ms before updating engine to save CPU
			this.paceDebounce = setTimeout(() => this.updatePace(val), 80);
		});

		// Save only on release
		this.els.slider.addEventListener('change', (e) => {
			this.savePaceToDatabase(parseInt(e.target.value));
		});

		document.addEventListener('keydown', (e) => {
			if (e.code === 'Space') {
				e.preventDefault();
				if (this.els.calibration.style.display !== 'none') {
					this.recordTap();
				} else {
					this.toggle();
				}
			}
			// Emergency Forward Nudge
			if (e.code === 'ArrowRight' && this.els.calibration.style.display === 'none') {
				this.stop();
				this.tick();
			}
		});

		// Click saved rhythm text to skip calibration
		this.els.tapFeedback.addEventListener('click', () => {
			if (this.paceMs > 0) {
				this.transitionToStage(this.paceMs);
			}
		});
		this.els.tapFeedback.style.cursor = 'pointer';
	}

	bindRecorderIntegration() {
		// Poll for the store in case of load order race conditions
		const checkStore = setInterval(() => {
			if (window.StarmusStore && window.StarmusStore.subscribe) {
				clearInterval(checkStore);
				console.log('Starmus Prosody: Connected to Recorder Store');

				let lastStatus = window.StarmusStore.getState().status;

				window.StarmusStore.subscribe((state) => {
					const status = state.status;

					// RECORDER STARTED -> PLAY
					if (status === 'recording' && lastStatus !== 'recording') {
						console.log('Starmus Prosody: Recorder Start detected -> Playing');

						// If in calibration mode, force transition to main stage
						if (this.els.calibration && this.els.calibration.style.display !== 'none') {
							this.transitionToStage(this.paceMs || 3000);
						}

						// Small delay to ensure UI transition and sync
						setTimeout(() => this.play(), 200);
					}

					// RECORDER STOPPED -> STOP
					if (lastStatus === 'recording' && status !== 'recording' && status !== 'paused') {
						this.stop();
					}

					lastStatus = status;
				});
			}
		}, 500);

		// Stop checking after 10 seconds
		setTimeout(() => clearInterval(checkStore), 10000);
	}
}

document.addEventListener('DOMContentLoaded', () => {
	new RhythmEngine();
});
