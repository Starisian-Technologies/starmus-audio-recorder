/* ================================
   SPARXSTAR APP MODE ENGINE
   ================================ */

const SparxstarApp = {
    activeElement: null,
    threshold: 1024,
    isClosing: false,

    /* Initialize global back-button listener */
    init() {
        window.addEventListener("popstate", () => {
            if (this.activeElement) {
                this._cleanupUI();
            }
        });
    },

    /* Open App Mode */
    open(selector) {
        // Safety: Do not open if screen is too wide
        if (window.innerWidth > this.threshold) {
            return;
        }

        if (this.activeElement) {
            return;
        } // Prevent double-open

        const element = document.querySelector(selector);
        if (!element) {
            return;
        }

        this.activeElement = element;
        this.isClosing = false;

        // Push history state (Back button support)
        window.history.pushState({ sparxstarMode: true }, "");

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
        if (!this.activeElement || this.isClosing) {
            return;
        }
        this.isClosing = true;
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
        this.isClosing = false;
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
    // 2) Shut off App Mode if screen becomes too large
    if (window.innerWidth > SparxstarApp.threshold) {
        SparxstarApp.close();
        return;
    }
    requestAnimationFrame(() => SparxstarApp._fitToScreen());
};

/* Initialize engine */
SparxstarApp.init();
