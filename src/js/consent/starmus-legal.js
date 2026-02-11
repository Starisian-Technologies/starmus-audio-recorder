document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    const CONFIG = {
        scrollTolerance: 10,
        minScrollStart: 10,
        minSignatureStrokes: 8,
        penColor: "#000000",
        penWidth: 2,
    };

    const form = document.getElementById("starmus-legal-form");
    const scrollArea = document.getElementById("starmus-terms-scroll-area");
    const scrollNotice = document.getElementById("starmus-scroll-notice");
    const sigSection = document.getElementById("starmus-signature-section");
    const canvas = document.getElementById("starmus-signature-pad");
    const clearBtn = document.getElementById("starmus-clear-sig");
    const fileInput = document.getElementById("starmus_contributor_signature");
    const submitBtn = document.getElementById("starmus-submit-btn");
    const fpInput = document.getElementById("sparxstar_signatory_fingerprint_id");

    if (
        !form ||
        !scrollArea ||
        !scrollNotice ||
        !sigSection ||
        !canvas ||
        !clearBtn ||
        !fileInput ||
        !submitBtn
    ) {
        return;
    }

    const ctx = canvas.getContext("2d");
    if (!ctx) {
        return;
    }

    let isDrawing = false;
    let strokes = 0;
    let lastX = 0;
    let lastY = 0;
    let hasReadTerms = false;
    let isSubmitting = false;

    const initForensics = function () {
        if (fpInput) {
            const nav = window.navigator;
            const screen = window.screen;
            const raw = [
                nav.userAgent,
                nav.language,
                screen.width + "x" + screen.height,
                window.innerWidth + "x" + window.innerHeight,
                Intl.DateTimeFormat().resolvedOptions().timeZone,
            ].join("||");

            let hash = 0;
            for (let i = 0; i < raw.length; i++) {
                const char = raw.charCodeAt(i);
                hash = (hash << 5) - hash + char;
                hash = hash & hash;
            }
            fpInput.value = "fp_" + Math.abs(hash).toString(16);
        }

        if ("geolocation" in navigator) {
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    const latEl = document.getElementById("sparxstar_lat");
                    const lngEl = document.getElementById("sparxstar_lng");
                    if (latEl) {
                        latEl.value = pos.coords.latitude;
                    }
                    if (lngEl) {
                        lngEl.value = pos.coords.longitude;
                    }
                },
                null,
                { timeout: 5000, maximumAge: 0 },
            );
        }
    };

    const resizeCanvas = function () {
        const rect = canvas.parentElement.getBoundingClientRect();
        const dpr = window.devicePixelRatio || 1;

        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        canvas.style.width = rect.width + "px";
        canvas.style.height = rect.height + "px";

        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.scale(dpr, dpr);

        ctx.lineWidth = CONFIG.penWidth;
        ctx.lineCap = "round";
        ctx.strokeStyle = CONFIG.penColor;
    };

    const unlockSignature = function () {
        sigSection.style.opacity = "1";
        sigSection.style.pointerEvents = "auto";
        scrollNotice.textContent = "Agreement read. Please sign below.";
        scrollNotice.classList.add("is-read");
    };

    const checkScroll = function () {
        if (hasReadTerms) {
            return;
        }

        const currentPos = scrollArea.scrollTop + scrollArea.clientHeight;
        const totalHeight = scrollArea.scrollHeight;
        const atBottom = currentPos >= totalHeight - CONFIG.scrollTolerance;
        const moved = scrollArea.scrollTop > CONFIG.minScrollStart;
        const isShort = scrollArea.scrollHeight <= scrollArea.clientHeight;

        if (atBottom && (moved || isShort)) {
            hasReadTerms = true;
            unlockSignature();
        }
    };

    const getPos = function (e) {
        const rect = canvas.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        return {
            x: clientX - rect.left,
            y: clientY - rect.top,
        };
    };

    const startDrawing = function (e) {
        isDrawing = true;
        const pos = getPos(e);
        lastX = pos.x;
        lastY = pos.y;
        if (e.type === "touchstart") {
            e.preventDefault();
        }
    };

    const draw = function (e) {
        if (!isDrawing) {
            return;
        }
        if (e.type === "touchmove") {
            e.preventDefault();
        }

        const pos = getPos(e);
        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();

        lastX = pos.x;
        lastY = pos.y;
        strokes++;
        validateForm();
    };

    const stopDrawing = function () {
        isDrawing = false;
    };

    const clearSignature = function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        strokes = 0;
        fileInput.value = "";
        validateForm();
    };

    const validateForm = function () {
        submitBtn.disabled = !(hasReadTerms && strokes > CONFIG.minSignatureStrokes);
    };

    const prepareSubmission = function (e) {
        if (strokes <= CONFIG.minSignatureStrokes) {
            e.preventDefault();
            alert("A valid signature is required.");
            return;
        }

        if (isSubmitting) {
            return;
        }

        e.preventDefault();
        isSubmitting = true;
        submitBtn.textContent = "Encrypting and submitting...";
        submitBtn.disabled = true;

        canvas.toBlob(function (blob) {
            if (!blob || blob.size === 0) {
                alert("Signature error. Please clear and sign again.");
                isSubmitting = false;
                submitBtn.disabled = false;
                return;
            }

            const file = new File([blob], "signwrap.png", { type: "image/png" });
            const container = new DataTransfer();
            container.items.add(file);
            fileInput.files = container.files;

            form.submit();
        }, "image/png");
    };

    scrollArea.addEventListener("scroll", checkScroll);
    setTimeout(checkScroll, 500);

    canvas.addEventListener("mousedown", startDrawing);
    canvas.addEventListener("mousemove", draw);
    canvas.addEventListener("mouseup", stopDrawing);
    canvas.addEventListener("mouseout", stopDrawing);

    canvas.addEventListener("touchstart", startDrawing, { passive: false });
    canvas.addEventListener("touchmove", draw, { passive: false });
    canvas.addEventListener("touchend", stopDrawing);

    clearBtn.addEventListener("click", clearSignature);
    form.addEventListener("submit", prepareSubmission);

    window.addEventListener("orientationchange", function () {
        setTimeout(resizeCanvas, 200);
    });
    window.addEventListener("resize", function () {
        setTimeout(resizeCanvas, 200);
    });

    initForensics();
    resizeCanvas();
});
