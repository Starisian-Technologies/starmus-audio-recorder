/**
 * @file         sparxstar-app-mode.js
 * @author       Starisian Technologies (Max Barrett)
 * @license      MIT License
 * @copyright    Copyright (c) 2026 Starisian Technologies.
 * @description  This script turns complex, scroll-heavy mobile pages into a controlled full-screen app experience so users can complete a task without the page fighting them.
 */

/* =================================================================
   SPARXSTAR APP MODE ENGINE (Final Locked v1.2)
   - SPARXSTAR Contract: Implemented
   - Stability: iOS Keyboard, Rotate, History, Memory Leaks
   - Accessibility: WAI-ARIA (aria-modal), Robust Focus Trap
   - Gestures: Momentum-aware Swipe Down + touchcancel safety
   - WebView Hardening: History guard + viewport fallbacks
   ================================================================= */
(function (window, document) {
    ("use strict");

    /* =================================================================
       SPARXSTAR APP MODE ENGINE (v1.2 - WP Safe)
       ================================================================= */

    const SparxstarApp = {
        // Configuration
        activeElement: null,
        triggerElement: null,
        threshold: 1024,
        targetClasses: [".sparxstar-app-mode"],

        // Internal State
        scrollTop: 0,
        rafId: null,
        currentScale: 1,

        // Gesture State
        _touchStartHandler: null,
        _touchMoveHandler: null,
        _touchEndHandler: null,
        _touchCancelHandler: null,
        touchStartY: 0,
        touchStartTime: 0,
        isDragging: false,

        // Memory Safety
        _handleResizeBound: null,

        init() {
            if (window.__sparxstarAppInitialized) {
                return;
            }
            window.__sparxstarAppInitialized = true;

            // 1. History Cleanup (Fixes Refresh Edge Case)
            if (history.state && history.state.sparxstarMode) {
                history.replaceState(null, "", location.href);
            }

            this._setupTriggers();

            // 2. Global History Listener
            window.addEventListener("popstate", () => {
                if (this.activeElement) {
                    this._cleanupUI();
                }
            });

            // 3. STARMUS CONTRACT: Submission Success
            window.addEventListener("sparxstar:submissionAccepted", () => {
                if (this.activeElement) {
                    this.close();
                }
            });

            // 4. Orientation Change
            window.addEventListener("orientationchange", () => {
                if (this.activeElement) {
                    setTimeout(() => this._fitToScreen(), 100);
                }
            });

            // 5. Accessibility Trap
            document.addEventListener("keydown", (e) => this._handleKeyboard(e));
        },

        _setupTriggers() {
            document.addEventListener("click", (e) => {
                if (this.activeElement) {
                    return;
                }
                const selector = this.targetClasses.join(", ");
                const target = e.target.closest(selector);
                if (target) {
                    this.open(target);
                }
            });
        },

        /* === OPEN LIFECYCLE === */
        open(element) {
            if (window.innerWidth > this.threshold) {
                return;
            }
            if (this.activeElement) {
                return;
            }

            // 1. History Guard
            if (!history.state || !history.state.sparxstarMode) {
                window.history.pushState({ sparxstarMode: true }, "");
            }

            this.activeElement = element;
            this.triggerElement = document.activeElement;

            // 2. iOS Scroll Lock
            this.scrollTop = window.scrollY;
            document.body.style.top = `-${this.scrollTop}px`;
            document.body.classList.add("sparxstar-scroll-locked");

            // 3. Activate UI
            element.classList.add("sparxstar-active");
            element.setAttribute("aria-modal", "true"); // Sufficient for modern A11y
            element.setAttribute("role", "dialog");
            element.setAttribute("tabindex", "-1");

            // Reset transition for immediate responsiveness
            element.style.transition = "";

            // 4. Scale & Focus
            this._fitToScreen();

            requestAnimationFrame(() => {
                if (this.activeElement) {
                    element.focus({ preventScroll: true });
                }
            });

            this._bindResizeListener();
            this._bindSwipeListeners();
        },

        /* === CLOSE LIFECYCLE === */
        close() {
            if (!this.activeElement) {
                return;
            }

            // WebView hardening: avoid unintended navigation if state isn't ours
            if (history.state && history.state.sparxstarMode) {
                window.history.back(); // Triggers popstate -> _cleanupUI
            } else {
                this._cleanupUI();
            }
        },

        _cleanupUI() {
            if (!this.activeElement) {
                return;
            }
            const el = this.activeElement;

            this._unbindResizeListener();
            this._unbindSwipeListeners();

            el.classList.remove("sparxstar-active");
            el.removeAttribute("aria-modal");
            el.removeAttribute("role");
            el.removeAttribute("tabindex");
            el.style.transform = "";
            el.style.transition = "";

            document.body.classList.remove("sparxstar-scroll-locked");
            document.body.style.top = "";
            window.scrollTo(0, this.scrollTop);

            if (this.triggerElement && this.triggerElement.isConnected) {
                this.triggerElement.focus();
            }

            this.activeElement = null;
            this.triggerElement = null;
            this.rafId = null;
            this.scrollTop = 0;
            this.currentScale = 1;
            this.isDragging = false;
            this.touchStartY = 0;
            this.touchStartTime = 0;
        },

        /* === VIEWPORT HELPERS === */
        _getViewportSize() {
            const vw =
                window.visualViewport?.width ||
                document.documentElement.clientWidth ||
                window.innerWidth;
            const vh =
                window.visualViewport?.height ||
                document.documentElement.clientHeight ||
                window.innerHeight;
            return { vw, vh };
        },

        /* === SCALING LOGIC === */
        _fitToScreen() {
            if (!this.activeElement) {
                return;
            }
            const el = this.activeElement;

            el.style.transform = "none";

            const { vw, vh } = this._getViewportSize();

            const contentW = el.scrollWidth;
            const contentH = el.scrollHeight;

            // Guard against WebView zero-size bugs
            if (!contentW || !contentH || !vw || !vh) {
                this.currentScale = 1;
                el.style.transform = "scale(1)";
                return;
            }

            const scaleX = vw / contentW;
            const scaleY = vh / contentH;

            // Prevent squashing on tall forms (allow scroll)
            const needsScroll = contentH > vh;
            this.currentScale = needsScroll ? Math.min(scaleX, 1) : Math.min(scaleX, scaleY, 1);

            el.style.transform = `scale(${this.currentScale})`;
        },

        /* === ACCESSIBILITY: FOCUS TRAP (ROBUST) === */
        _handleKeyboard(e) {
            if (!this.activeElement) {
                return;
            }

            if (e.key === "Escape") {
                this.close();
                return;
            }

            if (e.key === "Tab") {
                const allFocusable = this.activeElement.querySelectorAll(
                    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
                );

                // Robust visibility check
                const focusable = Array.from(allFocusable).filter((el) => {
                    if (el.disabled) {
                        return false;
                    }
                    if (el.getAttribute("aria-hidden") === "true") {
                        return false;
                    }
                    const rect = el.getBoundingClientRect();
                    return rect.width > 0 && rect.height > 0;
                });

                if (focusable.length === 0) {
                    e.preventDefault();
                    return;
                }

                const first = focusable[0];
                const last = focusable[focusable.length - 1];

                if (e.shiftKey) {
                    if (document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    }
                } else {
                    if (document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            }
        },

        /* === GESTURE: MOMENTUM SWIPE === */
        _bindSwipeListeners() {
            if (!this.activeElement) {
                return;
            }

            // Bound handlers with context
            this._touchStartHandler = (e) => this._onTouchStart(e);
            this._touchMoveHandler = (e) => this._onTouchMove(e);
            this._touchEndHandler = (e) => this._onTouchEnd(e);
            this._touchCancelHandler = (e) => this._onTouchEnd(e);

            const el = this.activeElement;
            el.addEventListener("touchstart", this._touchStartHandler, { passive: true });
            el.addEventListener("touchmove", this._touchMoveHandler, { passive: false });
            el.addEventListener("touchend", this._touchEndHandler, { passive: true });
            el.addEventListener("touchcancel", this._touchCancelHandler, { passive: true });
        },

        _unbindSwipeListeners() {
            if (!this.activeElement) {
                return;
            }
            const el = this.activeElement;

            el.removeEventListener("touchstart", this._touchStartHandler);
            el.removeEventListener("touchmove", this._touchMoveHandler);
            el.removeEventListener("touchend", this._touchEndHandler);
            el.removeEventListener("touchcancel", this._touchCancelHandler);
        },

        _onTouchStart(e) {
            const tag = e.target.tagName;

            // Don't steal gestures from inputs (including type="range")
            if (["INPUT", "TEXTAREA", "SELECT", "RANGE"].includes(tag)) {
                return;
            }

            // Only allow swipe-close when at top
            if (this.activeElement.scrollTop > 0) {
                return;
            }

            this.touchStartY = e.touches[0].clientY;
            this.touchStartTime = e.timeStamp;
            this.isDragging = true;
        },

        _onTouchMove(e) {
            if (!this.isDragging || !this.activeElement) {
                return;
            }

            const currentY = e.touches[0].clientY;
            const delta = currentY - this.touchStartY;

            if (delta > 0) {
                if (e.cancelable) {
                    e.preventDefault();
                }
                // Translate first for 1:1 pixel tracking, then scale
                this.activeElement.style.transform = `translateY(${delta}px) scale(${this.currentScale})`;
            }
        },

        _onTouchEnd(e) {
            if (!this.isDragging || !this.activeElement) {
                return;
            }
            this.isDragging = false;

            // Handle touchcancel missing changedTouches
            const endTouch = e.changedTouches && e.changedTouches[0];
            const currentY = endTouch ? endTouch.clientY : this.touchStartY;

            const delta = currentY - this.touchStartY;
            const timeDelta = e.timeStamp - this.touchStartTime;

            // Velocity (px/ms)
            const velocity = delta / (timeDelta || 1);

            if (delta > 100 && velocity > 0.3 && this.activeElement.scrollTop <= 0) {
                this.close();
            } else {
                // Snap back
                const el = this.activeElement;
                el.style.transition = "transform 0.3s ease-out";
                el.style.transform = `scale(${this.currentScale})`;
                setTimeout(() => {
                    if (this.activeElement === el) {
                        el.style.transition = "";
                    }
                }, 300);
            }
        },
    };
    /* Start Engine */
    // Expose API globally (optional, but good for debugging)
    window.SparxstarApp = SparxstarApp;

    // Initialize only when DOM is ready (Safety for Header vs Footer loading)
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => SparxstarApp.init());
    } else {
        SparxstarApp.init();
    }
})(window, document);
