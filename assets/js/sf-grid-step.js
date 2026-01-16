// sf-grid-step.js
(function () {
    const el = (id) => document.getElementById(id);

    const CANVAS_W = 1080;
    const CANVAS_H = 1080;

    // Ei turvamarginaaleja - kuva täyttää koko alueen
    const PAD = 0;
    const GAP = 12;
    const RADIUS = 24;

    function t(key, fallback) {
        if (window.SF_I18N && window.SF_I18N[key]) return window.SF_I18N[key];
        return fallback || key;
    }

    function getOptionsByCount(count) {
        const L = (upperKey, lowerKey, fallback) => t(upperKey, t(lowerKey, fallback));

        if (count === 1) {
            return [{ id: "grid-1", label: L("GRID_LAYOUT_1", "grid_layout_1", "Layout 1") }];
        }
        if (count === 2) {
            return [
                { id: "grid-2a", label: L("GRID_LAYOUT_2A", "grid_layout_2a", "Layout 1") },
                { id: "grid-2b", label: L("GRID_LAYOUT_2B", "grid_layout_2b", "Layout 2") },
            ];
        }
        if (count === 3) {
            return [
                { id: "grid-3a", label: L("GRID_LAYOUT_3A", "grid_layout_3a", "Layout 1") },
                { id: "grid-3b", label: L("GRID_LAYOUT_3B", "grid_layout_3b", "Layout 2") },
                { id: "grid-3c", label: L("GRID_LAYOUT_3C", "grid_layout_3c", "Layout 3") },
            ];
        }
        return [];
    }

    function roundRectPath(ctx, x, y, w, h, r) {
        const rr = Math.max(0, Math.min(r, Math.floor(Math.min(w, h) / 2)));
        ctx.beginPath();
        ctx.moveTo(x + rr, y);
        ctx.arcTo(x + w, y, x + w, y + h, rr);
        ctx.arcTo(x + w, y + h, x, y + h, rr);
        ctx.arcTo(x, y + h, x, y, rr);
        ctx.arcTo(x, y, x + w, y, rr);
        ctx.closePath();
    }

    function drawImageCover(ctx, img, x, y, w, h) {
        const iw = img.naturalWidth || img.width;
        const ih = img.naturalHeight || img.height;
        if (!iw || !ih) return;

        const scale = Math.max(w / iw, h / ih); // Ensure the image fills its container.
        const dw = iw * scale;
        const dh = ih * scale;
        const dx = x + (w - dw) / 2; // Center along X axis.
        const dy = y + (h - dh) / 2; // Center along Y axis.

        ctx.save();
        ctx.beginPath();
        ctx.rect(x, y, w, h);
        ctx.clip();
        ctx.drawImage(img, dx, dy, dw, dh); // Apply scaling proportions);
        ctx.restore();
    }

    function drawSlot(ctx, img, slot) {
        const { x, y, w, h, r } = slot;

        ctx.save();

        // Piirto ja pyöristys vain kuville
        roundRectPath(ctx, x, y, w, h, r);
        ctx.clip();
        drawImageCover(ctx, img, x, y, w, h);

        ctx.restore();
    }

    function getLayout(layoutId) {
        // Kuva-alue on neliö (1080x1080) - vastaa esikatselun kuva-aluetta
        const area = {
            x: PAD,
            y: PAD,
            w: CANVAS_W - PAD * 2,
            h: CANVAS_H - PAD * 2,
        };

        // 1 kuva:  yksi iso ruutu
        if (layoutId === "grid-1") {
            return {
                slots: [
                    { key: "main", z: 1, x: area.x, y: area.y, w: area.w, h: area.h, r: RADIUS },
                ],
            };
        }

        // 2 kuvaa, asettelu 1 (overlay): iso + pieni oikeaan alakulmaan
        if (layoutId === "grid-2a") {
            const oS = Math.round(area.w * 0.35);
            const margin = Math.round(area.w * 0.03);

            return {
                slots: [
                    { key: "main", z: 1, x: area.x, y: area.y, w: area.w, h: area.h, r: RADIUS },
                    {
                        key: "img2",
                        z: 2,
                        x: area.x + area.w - oS - margin,
                        y: area.y + area.h - oS - margin,
                        w: oS,
                        h: oS,
                        r: Math.round(RADIUS * 0.85),
                        border: 8,
                    },
                ],
            };
        }

        // 2 kuvaa, asettelu 2 (päällekkäin): kaksi vaakaruutua päällekkäin
        if (layoutId === "grid-2b") {
            const totalH = area.h;
            const h1 = Math.floor((totalH - GAP) / 2);
            const h2 = totalH - GAP - h1;
            const slotW = area.w;
            const slotX = area.x;

            return {
                slots: [
                    { key: "main", z: 1, x: slotX, y: area.y, w: slotW, h: h1, r: RADIUS },
                    { key: "img2", z: 1, x: slotX, y: area.y + h1 + GAP, w: slotW, h: h2, r: RADIUS },
                ],
            };
        }

        // 3 kuvaa, asettelu 1: iso ylhäällä + 2 ruutua alhaalla vierekkäin
        if (layoutId === "grid-3a") {
            const topH = Math.round(area.h * 0.62);
            const bottomH = area.h - topH - GAP;
            const bottomW = Math.round((area.w - GAP) / 2);
            return {
                slots: [
                    { key: "main", z: 1, x: area.x, y: area.y, w: area.w, h: topH, r: RADIUS },
                    { key: "img2", z: 1, x: area.x, y: area.y + topH + GAP, w: bottomW, h: bottomH, r: RADIUS },
                    { key: "img3", z: 1, x: area.x + bottomW + GAP, y: area.y + topH + GAP, w: area.w - bottomW - GAP, h: bottomH, r: RADIUS },
                ],
            };
        }

        // 3 kuvaa, asettelu 2: iso tausta + 2 overlay-ruutua alareunaan
        if (layoutId === "grid-3b") {
            const smallS = Math.round(area.w * 0.28);
            const largeS = Math.round(area.w * 0.38);
            const margin = Math.round(area.w * 0.03);

            return {
                slots: [
                    { key: "main", z: 1, x: area.x, y: area.y, w: area.w, h: area.h, r: RADIUS },
                    {
                        key: "img3",
                        z: 2,
                        x: area.x + margin,
                        y: area.y + area.h - smallS - margin,
                        w: smallS,
                        h: smallS,
                        r: Math.round(RADIUS * 0.8),
                        border: 6,
                    },
                    {
                        key: "img2",
                        z: 3,
                        x: area.x + area.w - largeS - margin,
                        y: area.y + area.h - largeS - margin,
                        w: largeS,
                        h: largeS,
                        r: Math.round(RADIUS * 0.85),
                        border: 8,
                    },
                ],
            };
        }

        // 3 kuvaa, asettelu 3: iso vasemmalla + 2 ruutua oikealla päällekkäin
        if (layoutId === "grid-3c") {
            const leftW = Math.round(area.w * 0.58);
            const rightW = area.w - leftW - GAP;
            const rightH1 = Math.round((area.h - GAP) / 2);
            const rightH2 = area.h - GAP - rightH1;

            return {
                slots: [
                    { key: "main", z: 1, x: area.x, y: area.y, w: leftW, h: area.h, r: RADIUS },
                    { key: "img2", z: 1, x: area.x + leftW + GAP, y: area.y, w: rightW, h: rightH1, r: RADIUS },
                    { key: "img3", z: 1, x: area.x + leftW + GAP, y: area.y + rightH1 + GAP, w: rightW, h: rightH2, r: RADIUS },
                ],
            };
        }

        // fallback
        return { slots: [{ key: "main", z: 1, x: area.x, y: area.y, w: area.w, h: area.h, r: RADIUS }] };
    }

    async function generateGridBitmap(layoutId, images) {
        const canvas = document.createElement("canvas");
        canvas.width = CANVAS_W;
        canvas.height = CANVAS_H;

        const ctx = canvas.getContext("2d");
        ctx.clearRect(0, 0, CANVAS_W, CANVAS_H);

        // Pyöristä koko bitmapin ulkoreunat
        const outerRadius = 24;
        ctx.beginPath();
        ctx.moveTo(outerRadius, 0);
        ctx.arcTo(CANVAS_W, 0, CANVAS_W, CANVAS_H, outerRadius);
        ctx.arcTo(CANVAS_W, CANVAS_H, 0, CANVAS_H, outerRadius);
        ctx.arcTo(0, CANVAS_H, 0, 0, outerRadius);
        ctx.arcTo(0, 0, CANVAS_W, 0, outerRadius);
        ctx.closePath();
        ctx.clip();

        const layout = getLayout(layoutId);

        const map = {
            main: images[0],
            img2: images[1],
            img3: images[2],
        };

        const slots = [...layout.slots].sort((a, b) => (a.z || 1) - (b.z || 1));
        for (const slot of slots) {
            const img = map[slot.key];
            if (!img) continue;
            drawSlot(ctx, img, slot);
        }

        return canvas.toDataURL("image/png");
    }

    async function loadImage(src) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = reject;
            img.crossOrigin = "anonymous";
            img.src = src;
        });
    }

    function getEditedImageForSlot(slot) {
        const editedInput = document.getElementById(`sf-image${slot}-edited-data`);
        if (editedInput && editedInput.value && editedInput.value.startsWith('data:')) {
            return editedInput.value;
        }
        return null;
    }

    function setSelectedLayout(layoutId) {
        const input = el("sf-grid-layout");
        if (input) input.value = layoutId;

        document.querySelectorAll(".sf-grid-card").forEach((btn) => {
            btn.classList.toggle("active", btn.dataset.layout === layoutId);
        });
    }

    async function renderGridOptions(selectedCount, imageUrls) {
        const wrap = el("sfGridPicker");
        if (!wrap) return;

        const options = getOptionsByCount(selectedCount);
        wrap.innerHTML = "";

        const imgs = [];
        for (let i = 0; i < imageUrls.length; i++) {
            const url = imageUrls[i];
            if (!url) continue;

            const slot = i + 1;
            const editedData = getEditedImageForSlot(slot);
            const srcToUse = editedData || url;

            imgs.push(await loadImage(srcToUse));
        }

        for (const opt of options) {
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "sf-grid-card";
            btn.dataset.layout = opt.id;

            const thumb = document.createElement("img");
            thumb.className = "sf-grid-card-thumb";
            thumb.alt = opt.label;

            const dataUrl = await generateGridBitmap(opt.id, imgs);
            thumb.src = dataUrl;

            const label = document.createElement("div");
            label.className = "sf-grid-card-label";
            label.textContent = opt.label;

            btn.appendChild(thumb);
            btn.appendChild(label);

            btn.addEventListener("click", async () => {
                setSelectedLayout(opt.id);

                const finalDataUrl = await generateGridBitmap(opt.id, imgs);
                const out = el("sf-grid-bitmap");
                if (out) out.value = finalDataUrl;
            });

            wrap.appendChild(btn);
        }

        const input = el("sf-grid-layout");
        const current = (input && input.value) ? input.value : "";

        // Check if current selection is valid for this image count
        const validOptions = options.map(o => o.id);
        const isCurrentValid = current && validOptions.includes(current);

        // Auto-select first option if no valid selection exists
        const chosen = isCurrentValid ? current : (options[0] ? options[0].id : "");

        if (chosen) {
            setSelectedLayout(chosen);

            const finalDataUrl = await generateGridBitmap(chosen, imgs);
            const out = el("sf-grid-bitmap");
            if (out) out.value = finalDataUrl;
        }
    }

    window.SF_GRID_STEP_INIT = async function (selectedCount, imageUrls) {
        try {
            await renderGridOptions(selectedCount, imageUrls);
        } catch (e) {
            console.error("Grid step init failed:", e);
        }
    };

    // Helper function to re-count images and re-render grid
    function reRenderGridForImageChange() {
        // Check if we're on the grid step (step 5)
        const gridPicker = el('sfGridPicker');
        if (!gridPicker || !gridPicker.offsetParent) return; // Not visible, skip

        // Re-count images and re-render
        const isPlaceholder = (src) => {
            if (!src) return true;
            const s = String(src).toLowerCase();
            if (s.includes('camera-placeholder')) return true;
            if (s.includes('placeholder')) return true;
            if (s.includes('no-image')) return true;
            if (s === '' || s === 'about:blank') return true;
            return false;
        };

        const urls = [];
        const t1 = el('sfImageThumb1') || el('sf-upload-preview1');
        const t2 = el('sfImageThumb2') || el('sf-upload-preview2');
        const t3 = el('sfImageThumb3') || el('sf-upload-preview3');

        [t1, t2, t3].forEach((img) => {
            if (!img || !img.src) return;
            if (isPlaceholder(img.src)) return;
            urls.push(img.src);
        });

        const count = urls.length || 1;
        window.SF_GRID_STEP_INIT(count, urls);
    }

    // Listen for image changes and re-render grid options if on grid step
    document.addEventListener('sf:image-selected', reRenderGridForImageChange);
    document.addEventListener('sf:image-removed', reRenderGridForImageChange);
})();