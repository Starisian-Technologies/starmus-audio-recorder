/**
 * @file starmus-cue-events.js
 * @package Starmus Audio Recorder
 * @description Cue events integration for Starmus Audio Editor
 */

class StarmusCueEventsManager {
	constructor(peaksInstance, options = {}) {
		this.peaks = peaksInstance;
		this.options = {
			showNotifications: true,
			logEvents: true,
			autoHighlight: true,
			pointsTableId: 'points-list',   // Fixed: Specific ID for points
			segmentsTableId: 'segments-list', // Fixed: Specific ID for segments
			...options
		};

		this.init();
	}

	init() {
		if (!this.peaks) {
			console.warn('[StarmusCueEvents] No Peaks instance provided');
			return;
		}

		this.peaks.on('points.enter', (event) => this.handlePointEnter(event));
		this.peaks.on('segments.enter', (event) => this.handleSegmentEnter(event));
		this.peaks.on('segments.exit', (event) => this.handleSegmentExit(event));

		if (this.options.logEvents) {
			console.log('[StarmusCueEvents] Initialized');
		}
	}

	handlePointEnter(event) {
		const { point } = event;
		if (this.options.showNotifications) {
			this.showNotification(`Point reached: ${point.labelText}`, 'info');
		}
		if (this.options.autoHighlight) {
			this.highlightTableRow(this.options.pointsTableId, point.id);
		}
		this.dispatchCustomEvent('starmus:point:enter', { point });
	}

	handleSegmentEnter(event) {
		const { segment } = event;
		if (this.options.showNotifications) {
			this.showNotification(`Entering: ${segment.labelText}`, 'success');
		}
		if (this.options.autoHighlight) {
			this.highlightTableRow(this.options.segmentsTableId, segment.id);
		}
		this.dispatchCustomEvent('starmus:segment:enter', { segment });
	}

	handleSegmentExit(event) {
		const { segment } = event;
		if (this.options.autoHighlight) {
			this.removeHighlight(this.options.segmentsTableId, segment.id);
		}
		this.dispatchCustomEvent('starmus:segment:exit', { segment });
	}

	showNotification(message, type = 'info') {
		if (typeof window.showNotice === 'function') {
			window.showNotice(message, type);
		} else {
			console.info(`[Starmus Notification] ${message}`);
		}
	}

	highlightTableRow(tableId, id) {
		const table = document.getElementById(tableId);
		if (!table) {return;}

		// Clear previous highlights in this specific table
		table.querySelectorAll('tr.starmus-active-row').forEach(row => {
			row.classList.remove('starmus-active-row');
		});

		const row = table.querySelector(`tr[data-id="${id}"]`);
		if (row) {
			row.classList.add('starmus-active-row');
			// Optional: Smoothly scroll the row into view if it's a long list
			row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}

	removeHighlight(tableId, id) {
		const table = document.getElementById(tableId);
		if (!table) {return;}

		const row = table.querySelector(`tr[data-id="${id}"]`);
		if (row) {
			row.classList.remove('starmus-active-row');
		}
	}

	dispatchCustomEvent(eventName, detail) {
		const event = new CustomEvent(eventName, { detail, bubbles: true });
		document.dispatchEvent(event);
	}

	destroy() {
		if (this.peaks) {
			this.peaks.off('points.enter');
			this.peaks.off('segments.enter');
			this.peaks.off('segments.exit');
		}
		this.peaks = null;
	}
}

export default StarmusCueEventsManager;
