/**
 * Click-to-source PDF overlay for upload_intake_form/view.php (S18).
 *
 * Hovering or focusing a `.upload-intake-field[data-citation-id]` element
 * draws an overlay rectangle on the embedded PDF at the citation's page
 * + bbox. Clicking the field scrolls the PDF to that page and flashes
 * the overlay. Clicking a `.upload-intake-guideline-chip` opens a side
 * panel with the chunk's snippet and source URL.
 *
 * Coordinate convention: bboxes are stored in PDF points (origin at
 * bottom-left of the page) — see agent-service/agent_service/schemas/
 * citation.py. pdf.js's PageViewport translates points to top-left
 * raster coordinates, so we let it do the conversion via
 * `viewport.convertToViewportPoint`.
 *
 * The script depends on `window.pdfjsLib` from the CDN reference in
 * view.php; bail out cleanly when it is not available.
 *
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   GPL-3.0-or-later
 */

(function () {
    "use strict";

    var dataNode = document.getElementById("upload-intake-citation-data");
    var pdfPane = document.getElementById("upload-intake-pdf-pane");
    if (!dataNode || !pdfPane) {
        return;
    }

    var payload;
    try {
        payload = JSON.parse(dataNode.textContent || "{}");
    } catch (e) {
        // Malformed JSON in the embedded payload — bail out silently
        // since there is nothing the user can do about it.
        return void e;
    }

    if (!window.pdfjsLib) {
        pdfPane.innerHTML = '<p class="text-danger">' +
            "PDF viewer (pdf.js) failed to load." +
            "</p>";
        return;
    }

    // Some pdf.js builds require an explicit worker URL.
    if (
        window.pdfjsLib.GlobalWorkerOptions &&
        !window.pdfjsLib.GlobalWorkerOptions.workerSrc
    ) {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc =
            "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
    }

    var pdfUrl = payload.pdf_url || pdfPane.getAttribute("data-pdf-url") || "";
    if (!pdfUrl) {
        pdfPane.innerHTML = '<p class="text-muted">No PDF available.</p>';
        return;
    }

    var pdfBboxes = Array.isArray(payload.pdf_bbox) ? payload.pdf_bbox : [];
    var guidelines = Array.isArray(payload.guideline) ? payload.guideline : [];

    var bboxById = {};
    pdfBboxes.forEach(function (cit) {
        if (cit && typeof cit.id !== "undefined") {
            bboxById[String(cit.id)] = cit;
        }
    });
    var guidelineById = {};
    guidelines.forEach(function (cit) {
        if (cit && typeof cit.id !== "undefined") {
            guidelineById[String(cit.id)] = cit;
        }
    });

    // -----------------------------------------------------------------
    // Render the PDF pages, one canvas per page, each wrapped so the
    // overlay rectangles can position absolutely against the canvas.
    // -----------------------------------------------------------------
    var pageWrappers = []; // index = pageNumber - 1
    var bboxRects = {};    // citation id -> { rect: DOM, pageWrapper: DOM }

    pdfPane.innerHTML = "";

    var loadingTask = window.pdfjsLib.getDocument({ url: pdfUrl });
    loadingTask.promise.then(function (pdfDoc) {
        var numPages = pdfDoc.numPages;
        var paneWidth = Math.max(pdfPane.clientWidth - 16, 320);

        var renderChain = Promise.resolve();
        for (var pageNum = 1; pageNum <= numPages; pageNum++) {
            (function (currentPage) {
                renderChain = renderChain.then(function () {
                    return pdfDoc.getPage(currentPage).then(function (page) {
                        return renderPage(page, currentPage, paneWidth);
                    });
                });
            })(pageNum);
        }

        renderChain
            .then(function () {
                drawAllBboxes();
                wireFieldInteractions();
                wireGuidelineInteractions();
            })
            .catch(function (err) {
                console.error("upload_intake_form citation overlay: render failed", err);
            });
    }).catch(function (err) {
        pdfPane.innerHTML =
            '<p class="text-danger">Failed to load PDF: ' +
            (err && err.message ? err.message : "unknown error") +
            "</p>";
    });

    function renderPage(page, pageNumber, targetWidth) {
        var unscaledViewport = page.getViewport({ scale: 1 });
        var scale = targetWidth / unscaledViewport.width;
        var viewport = page.getViewport({ scale: scale });

        var wrapper = document.createElement("div");
        wrapper.className = "upload-intake-pdf-page";
        wrapper.style.width = viewport.width + "px";
        wrapper.style.height = viewport.height + "px";
        wrapper.setAttribute("data-page-number", String(pageNumber));

        var canvas = document.createElement("canvas");
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        wrapper.appendChild(canvas);

        var overlay = document.createElement("div");
        overlay.className = "upload-intake-bbox-overlay";
        overlay.setAttribute("data-overlay-page", String(pageNumber));
        wrapper.appendChild(overlay);

        pdfPane.appendChild(wrapper);
        pageWrappers[pageNumber - 1] = {
            wrapper: wrapper,
            overlay: overlay,
            viewport: viewport,
        };

        var renderTask = page.render({
            canvasContext: canvas.getContext("2d"),
            viewport: viewport,
        });
        return renderTask.promise;
    }

    function drawAllBboxes() {
        pdfBboxes.forEach(function (cit) {
            var idKey = String(cit.id);
            var pageEntry = pageWrappers[cit.page - 1];
            if (!pageEntry) {
                return;
            }
            var rect = buildRectElement(cit, pageEntry.viewport);
            if (!rect) {
                return;
            }
            pageEntry.overlay.appendChild(rect);
            bboxRects[idKey] = { rect: rect, pageWrapper: pageEntry.wrapper };
        });
    }

    function buildRectElement(cit, viewport) {
        if (!Array.isArray(cit.bbox) || cit.bbox.length !== 4) {
            return null;
        }
        var x0 = Number(cit.bbox[0]);
        var y0 = Number(cit.bbox[1]);
        var x1 = Number(cit.bbox[2]);
        var y1 = Number(cit.bbox[3]);
        if (
            !isFinite(x0) || !isFinite(y0) ||
            !isFinite(x1) || !isFinite(y1)
        ) {
            return null;
        }

        // pdf.js's convertToViewportPoint flips PDF (bottom-left origin)
        // into viewport (top-left origin) and applies the current scale.
        var topLeft = viewport.convertToViewportPoint(x0, y1);
        var bottomRight = viewport.convertToViewportPoint(x1, y0);
        var left = Math.min(topLeft[0], bottomRight[0]);
        var top = Math.min(topLeft[1], bottomRight[1]);
        var width = Math.abs(bottomRight[0] - topLeft[0]);
        var height = Math.abs(bottomRight[1] - topLeft[1]);

        var div = document.createElement("div");
        div.className = "upload-intake-bbox-rect";
        div.style.left = left + "px";
        div.style.top = top + "px";
        div.style.width = width + "px";
        div.style.height = height + "px";
        div.setAttribute("data-bbox-citation-id", String(cit.id));
        return div;
    }

    function wireFieldInteractions() {
        var fields = document.querySelectorAll(".upload-intake-field[data-citation-id]");
        fields.forEach(function (field) {
            var idAttr = field.getAttribute("data-citation-id") || "";
            if (!idAttr || !bboxRects[idAttr]) {
                return;
            }

            field.addEventListener("mouseenter", function () {
                activateRect(idAttr);
            });
            field.addEventListener("mouseleave", function () {
                deactivateRect(idAttr);
            });
            field.addEventListener("focus", function () {
                activateRect(idAttr);
            });
            field.addEventListener("blur", function () {
                deactivateRect(idAttr);
            });
            field.addEventListener("click", function () {
                scrollAndFlash(idAttr);
            });
            field.addEventListener("keydown", function (event) {
                if (event.key === "Enter" || event.key === " ") {
                    event.preventDefault();
                    scrollAndFlash(idAttr);
                }
            });
        });
    }

    function activateRect(idKey) {
        var entry = bboxRects[idKey];
        if (!entry) {
            return;
        }
        entry.rect.classList.add("is-active");
    }

    function deactivateRect(idKey) {
        var entry = bboxRects[idKey];
        if (!entry) {
            return;
        }
        entry.rect.classList.remove("is-active");
    }

    function scrollAndFlash(idKey) {
        var entry = bboxRects[idKey];
        if (!entry) {
            return;
        }
        entry.pageWrapper.scrollIntoView({ behavior: "smooth", block: "center" });
        entry.rect.classList.add("is-active");
        entry.rect.classList.remove("is-flashing");
        // Restart the animation by forcing a reflow.
        void entry.rect.offsetWidth;
        entry.rect.classList.add("is-flashing");
        setTimeout(function () {
            entry.rect.classList.remove("is-flashing");
        }, 1300);
    }

    function wireGuidelineInteractions() {
        var panel = document.getElementById("upload-intake-guideline-panel");
        var snippetNode = document.getElementById("upload-intake-guideline-snippet");
        var sectionNode = document.getElementById("upload-intake-guideline-section");
        var sourceNode = document.getElementById("upload-intake-guideline-source");
        if (!panel || !snippetNode || !sectionNode || !sourceNode) {
            return;
        }

        var chips = document.querySelectorAll(".upload-intake-guideline-chip[data-citation-id]");
        chips.forEach(function (chip) {
            chip.addEventListener("click", function () {
                var idAttr = chip.getAttribute("data-citation-id") || "";
                var cit = guidelineById[idAttr];
                if (!cit) {
                    return;
                }
                snippetNode.textContent = cit.snippet || "";
                sectionNode.textContent = cit.section || cit.chunk_id || "";
                if (cit.source_url) {
                    sourceNode.setAttribute("href", cit.source_url);
                    sourceNode.style.display = "";
                } else {
                    sourceNode.removeAttribute("href");
                    sourceNode.style.display = "none";
                }
                panel.classList.add("is-open");
            });
        });
    }

    // bboxById is referenced for potential future click lookup from a
    // PDF location to a field — keep the index hot so reviewers don't
    // need to thread state again.
    void bboxById;
})();
