/* ================================
   SPARXSTAR APP MODE ENGINE
   ================================ */

const SparxstarApp = {
    activeElement: null,
    historyReady: false,

    /* Initialize global back-button listener */
    init() {
        window.addEventListener("popstate", () => {
            if (this.activeElement) {
                this._cleanupUI();
            }
        });

        // Safari edge-case: ensure history system ready
        requestAnimationFrame(() => {
            this.historyReady = true;
        });
    },

    /* Open App Mode */
    open(selector) {
        if (this.activeElement) {
            return;
        } // Prevent double-open

        const element = document.querySelector(selector);
        if (!element) {
            return;
        }

        this.activeElement = element;

        // Push history state (Back button support)
        if (this.historyReady) {
            window.history.pushState({ sparxstarMode: true }, "");
        }

        // Lock background scroll
        document.body.classList.add("sparxstar-scroll-locked");

        // Activate fullscreen mode
        element.classList.add("sparxstar-app-mode");

        // Initial scale calculation
        this._fitToScreen();

        // Robust resize listeners
        window.addEventListener("resize", this._handleResizeBound);
        if (window.visualViewport) {
            window.visualViewport.addEventListener("resize", this._handleResizeBound);
        }
    },

    /* Close App Mode — unified with Back button */
    close() {
        if (!this.activeElement) {
            return;
        }
        window.history.back();
    },

    /* Internal cleanup */
    _cleanupUI() {
        if (!this.activeElement) {
            return;
        }

        this.activeElement.classList.remove("sparxstar-app-mode");
        document.body.classList.remove("sparxstar-scroll-locked");

        this.activeElement.style.transform = "";

        window.removeEventListener("resize", this._handleResizeBound);
        if (window.visualViewport) {
            window.visualViewport.removeEventListener("resize", this._handleResizeBound);
        }

        this.activeElement = null;
    },

    /* Scaling logic */
    _fitToScreen() {
        if (!this.activeElement) {
            return;
        }

        const el = this.activeElement;

        // Reset transform for measurement
        el.style.transform = "none";

        const vw = window.visualViewport ? window.visualViewport.width : window.innerWidth;
        const vh = window.visualViewport ? window.visualViewport.height : window.innerHeight;

        const padding = 20;
        const availW = vw - padding;
        const availH = vh - padding;

        const contentW = el.scrollWidth;
        const contentH = el.scrollHeight;

        const scaleX = availW / contentW;
        const scaleY = availH / contentH;
        const scale = Math.min(scaleX, scaleY, 1);

        el.style.transform = `scale(${scale})`;
    },

    /* Bound resize handler reference */
    _handleResizeBound: null,
};

/* Bind resize handler once to preserve reference */
SparxstarApp._handleResizeBound = () => {
    requestAnimationFrame(() => SparxstarApp._fitToScreen());
};

/* Initialize engine */
SparxstarApp.init();
