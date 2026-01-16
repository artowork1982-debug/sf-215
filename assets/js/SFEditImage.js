window.SFImageEditor = (() => {
    let img = null;

    // Image transform (pan/zoom)
    let transform = { x: 0, y: 0, scale: 1 };
    let draggingImage = false;
    let dragStart = null;
    let pendingPan = false;

    // Annotation tool + annotations
    let currentTool = null; // 'arrow','circle','crash','warning','injury','cross' or null
    let annotations = [];   // {id,type:'icon'|'text', tool, x,y, size, text?}

    // Dragging annotation
    let draggingAnnoId = null;
    let draggingAnnoOffset = null;

    let selectedAnnoId = null;
    let didDrag = false;
    let downPos = null;
    let lastPointer = { x: 0, y: 0 };

    // Touch handling
    let touchStartDist = 0;
    let touchStartScale = 1;
    let touchStartTransform = null;

    let _eventsBound = false;

    // UUSI: raf-throttle state emit (toolbar seuraa dragissa)
    let _stateRaf = null;

    function _emitState() {
        const selected = selectedAnnoId
            ? (annotations.find(a => a && a.id === selectedAnnoId) || null)
            : null;

        document.dispatchEvent(new CustomEvent('sf:editor-state', {
            detail: {
                // aktiivinen "placement tool" (kun käyttäjä aikoo lisätä uuden)
                tool: currentTool,

                // valittu olemassa oleva merkintä (kun käyttäjä klikkaa merkintää kuvasta)
                selectedId: selected ? selected.id : null,
                selectedType: selected ? selected.type : null,

                // sijainti (canvas coords) → toolbar voidaan ankkuroida merkintään
                selectedX: selected ? Number(selected.x || 0) : null,
                selectedY: selected ? Number(selected.y || 0) : null,

                // icon-only
                selectedTool: (selected && selected.type === 'icon') ? (selected.tool || null) : null,
                selectedRot: (selected && selected.type === 'icon') ? Number(selected.rot || 0) : null,
                selectedSize: (selected && selected.type === 'icon') ? Number(selected.size || 72) : null,

                // text-only
                selectedText: (selected && selected.type === 'text') ? String(selected.text || '') : null,
                selectedTextSize: (selected && selected.type === 'text') ? Number(selected.size || 32) : null
            }
        }));
    }

    // UUSI: päivitä UI (toolbar) myös dragin aikana, mutta throttlattuna (1 / frame)
    function _emitStateThrottled() {
        if (_stateRaf) return;
        _stateRaf = requestAnimationFrame(() => {
            _stateRaf = null;
            _emitState();
        });
    }

    function _setSelected(idOrNull) {
        selectedAnnoId = idOrNull || null;
        _emitState();
        draw();
    }

    function changeSelectedSize(delta) {
        if (!selectedAnnoId) return;
        const a = annotations.find(v => v && v.id === selectedAnnoId);
        if (!a) return;

        // ICON
        if (a.type === 'icon') {
            const min = 24;
            const max = 220;

            const cur = Number(a.size || 72);
            const next = Math.max(min, Math.min(max, cur + Number(delta || 0)));
            if (next === cur) return;

            a.size = next;
            _emitState();
            draw();
            return;
        }

        // TEXT
        if (a.type === 'text') {
            const min = 14;
            const max = 96;

            const cur = Number(a.size || 32);
            const next = Math.max(min, Math.min(max, cur + Number(delta || 0)));
            if (next === cur) return;

            a.size = next;
            _emitState();
            draw();
            return;
        }
    }

    const iconFiles = {
        arrow: 'arrow-red.png',
        circle: 'circle-red.png',
        crash: 'crash.png',
        warning: 'warning.png',
        injury: 'injury.png',
        cross: 'cross-red.png'
    };

    const iconCache = {}; // tool -> Image()

    function _getCanvas() {
        return document.getElementById('sf-edit-img-canvas');
    }

    const CANVAS_W = 1920;
    const CANVAS_H = 1080;

    function _resizeCanvasToDisplay() {
        const canvas = _getCanvas();
        if (!canvas) return;

        if (canvas.width !== CANVAS_W) canvas.width = CANVAS_W;
        if (canvas.height !== CANVAS_H) canvas.height = CANVAS_H;
    }

    function _eventToCanvasXY(e) {
        const canvas = _getCanvas();
        const rect = canvas.getBoundingClientRect();

        const sx = canvas.width / rect.width;
        const sy = canvas.height / rect.height;

        return {
            x: (e.clientX - rect.left) * sx,
            y: (e.clientY - rect.top) * sy
        };
    }

    function _baseUrl() {
        const b = (window.SF_BASE_URL || '/safetyflash-system').replace(/\/$/, '');
        return b;
    }

    function _iconUrl(tool) {
        // Assumption: same icon files as preview annotation system
        return `${_baseUrl()}/assets/img/annotations/${iconFiles[tool]}`;
    }

    function _ensureIcon(tool, cb) {
        if (!iconFiles[tool]) return cb(null);

        if (iconCache[tool] && iconCache[tool].complete) return cb(iconCache[tool]);

        const im = iconCache[tool] || new Image();
        iconCache[tool] = im;

        im.onload = () => cb(im);
        im.onerror = () => cb(null);

        if (!im.src) im.src = _iconUrl(tool);
        else if (im.complete) cb(im);
    }

    function setup(src, initialState = null) {
        const canvas = _getCanvas();
        _resizeCanvasToDisplay();

        img = new window.Image();
        img.onload = () => {
            // Default fit+center if no saved transform
            const hasSaved =
                initialState &&
                typeof initialState === 'object' &&
                initialState.transform &&
                typeof initialState.transform === 'object' &&
                typeof initialState.transform.scale !== 'undefined';

            if (!hasSaved && canvas) {
                const scaleX = canvas.width / img.width;
                const scaleY = canvas.height / img.height;

                // COVER: täyttää alueen oletuksena
                const scale = Math.max(scaleX, scaleY);

                transform = {
                    scale: scale,
                    x: (canvas.width - img.width * scale) / 2,
                    y: (canvas.height - img.height * scale) / 2
                };
            }
            // Oletustyökalu: teksti
            currentTool = 'text';
            _emitState();
            draw();
        };
        img.src = src;

        // Load state
        if (initialState && typeof initialState === 'object') {
            if (initialState.transform && typeof initialState.transform === 'object') {
                transform = {
                    x: Number(initialState.transform.x ?? transform.x ?? 0),
                    y: Number(initialState.transform.y ?? transform.y ?? 0),
                    scale: Number(initialState.transform.scale ?? transform.scale ?? 1),
                };
            }

            if (Array.isArray(initialState.annotations)) {
                annotations = initialState.annotations;
            } else {
                annotations = [];
            }
        } else {
            annotations = [];
        }

        // Warm icon cache for smoother first placement
        Object.keys(iconFiles).forEach(tool => _ensureIcon(tool, () => { }));

        draw();
    }
    function drawSafeZone(ctx, canvas) {
        // Turva-alue:  neliö keskellä canvasia (1:1 aspect ratio)
        // Tämä vastaa esikatselun kuva-aluetta yhden kuvan layoutissa

        const cw = canvas.width;
        const ch = canvas.height;

        // Neliön koko = canvasin korkeus (pienempi dimensio)
        const squareSize = Math.min(cw, ch);

        // Neliö keskitetään
        const squareX = (cw - squareSize) / 2;
        const squareY = (ch - squareSize) / 2;

        ctx.save();

        // Tummennettu alue neliön ULKOPUOLELLA
        ctx.fillStyle = 'rgba(0, 0, 0, 0.45)';

        // Vasen tummennettu alue
        if (squareX > 0) {
            ctx.fillRect(0, 0, squareX, ch);
        }

        // Oikea tummennettu alue
        if (squareX > 0) {
            ctx.fillRect(squareX + squareSize, 0, cw - squareX - squareSize, ch);
        }

        // Ylä tummennettu alue
        if (squareY > 0) {
            ctx.fillRect(squareX, 0, squareSize, squareY);
        }

        // Ala tummennettu alue
        if (squareY > 0) {
            ctx.fillRect(squareX, squareY + squareSize, squareSize, ch - squareY - squareSize);
        }

        // Katkoviiva neliön reunalla (turva-alueen raja)
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.85)';
        ctx.lineWidth = 3;
        ctx.setLineDash([12, 6]);
        ctx.strokeRect(squareX, squareY, squareSize, squareSize);

        ctx.restore();
    }

    function draw() {
        const canvas = _getCanvas();
        if (!canvas) return;
        const ctx = canvas.getContext('2d');

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        // background
        ctx.fillStyle = '#fafafa';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // image
        if (img) {
            ctx.save();
            ctx.translate(transform.x, transform.y);
            ctx.scale(transform.scale, transform.scale);
            ctx.drawImage(img, 0, 0);
            ctx.restore();
        }

        // TURVAVIIVAT - näytä näkyvä alue esikatselussa
        drawSafeZone(ctx, canvas);

        // annotations (render in canvas coords)
        if (annotations && annotations.length) {
            annotations.forEach(a => {
                if (!a) return;

                // --- TEXT ---
                if (a.type === 'text') {
                    ctx.save();

                    const text = String(a.text || '').replace(/\r\n/g, '\n');
                    if (!text.trim()) { ctx.restore(); return; }

                    const x = Number(a.x ?? 0);
                    const y = Number(a.y ?? 0);

                    const fontSize = Number(a.size || 32);
                    const fontFamily = 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
                    ctx.font = `700 ${fontSize}px ${fontFamily}`;
                    ctx.textBaseline = 'top';

                    const padX = 12;
                    const padY = 10;
                    const radius = 12;
                    const maxWidth = 980;

                    const rawLines = text.split('\n');

                    const lines = [];
                    rawLines.forEach((ln) => {
                        const words = String(ln).split(/\s+/).filter(Boolean);
                        if (!words.length) { lines.push(''); return; }

                        let line = words[0];
                        for (let i = 1; i < words.length; i++) {
                            const test = line + ' ' + words[i];
                            const w = ctx.measureText(test).width;
                            if (w > maxWidth && line.length) {
                                lines.push(line);
                                line = words[i];
                            } else {
                                line = test;
                            }
                        }
                        lines.push(line);
                    });

                    const lineH = Math.round(fontSize * 1.25);
                    const textW = Math.max(0, ...lines.map(l => ctx.measureText(l).width));
                    const textH = Math.max(1, lines.length) * lineH;

                    const boxW = Math.min(maxWidth, textW) + padX * 2;
                    const boxH = textH + padY * 2;

                    const bx = x;
                    const by = y;

                    ctx.fillStyle = 'rgba(0, 0, 0, 0.55)';
                    ctx.strokeStyle = 'rgba(255, 255, 255, 0.65)';
                    ctx.lineWidth = 2;

                    const rr = (r, w, h) => Math.max(0, Math.min(r, Math.min(w, h) / 2));
                    const r = rr(radius, boxW, boxH);

                    ctx.beginPath();
                    ctx.moveTo(bx + r, by);
                    ctx.arcTo(bx + boxW, by, bx + boxW, by + boxH, r);
                    ctx.arcTo(bx + boxW, by + boxH, bx, by + boxH, r);
                    ctx.arcTo(bx, by + boxH, bx, by, r);
                    ctx.arcTo(bx, by, bx + boxW, by, r);
                    ctx.closePath();
                    ctx.fill();
                    ctx.stroke();

                    ctx.fillStyle = '#ffffff';
                    let ty = by + padY;
                    lines.forEach((l) => {
                        ctx.fillText(l, bx + padX, ty);
                        ty += lineH;
                    });

                    ctx.restore();
                    return;
                }

                // --- ICON ---
                if (a.type === 'icon') {
                    const tool = a.tool;
                    const size = Number(a.size || 140);
                    const x = Number(a.x || 0);
                    const y = Number(a.y || 0);
                    const rot = Number(a.rot || 0);

                    _ensureIcon(tool, (im) => {
                        if (!im) return;

                        ctx.save();
                        ctx.translate(x, y);
                        if (rot) ctx.rotate((rot * Math.PI) / 180);

                        ctx.drawImage(im, -size / 2, -size / 2, size, size);
                        ctx.restore();
                    });

                    return;
                }
            });
        }
    }

    function _hitTestAnnotation(x, y) {
        // Iterate from topmost
        for (let i = annotations.length - 1; i >= 0; i--) {
            const a = annotations[i];
            if (!a) continue;

            if (a.type === 'icon') {
                const size = Number(a.size || 64);
                const ax = Number(a.x || 0);
                const ay = Number(a.y || 0);
                const left = ax - size / 2;
                const top = ay - size / 2;
                if (x >= left && x <= left + size && y >= top && y <= top + size) {
                    return a;
                }
            }

            if (a.type === 'text') {
                // Text bbox (approx) – matchaa paremmin editorin fonttikokoa ja taustalaatikkoa
                const text = String(a.text || '').replace(/\r\n/g, '\n');

                // Jos ihan oikeasti tyhjä (pelkkää whitespacea), ei piirretä mitään
                if (!text.trim()) return;
                const ax = Number(a.x || 0);
                const ay = Number(a.y || 0);

                const lines = text ? text.split('\n') : [''];
                const maxLen = Math.max(...lines.map(l => (l || '').length), 1);

                // approx: ~16px per merkki + padding (editorissa fontti on selvästi isompi kuin 14–16px)
                const w = Math.min(980, Math.max(140, maxLen * 16)) + 24;
                const h = Math.max(44, lines.length * 40) + 16;

                if (x >= ax && x <= ax + w && y >= ay && y <= ay + h) {
                    return a;
                }
            }
        }
        return null;
    }

    function _getTouchPoint(touch) {
        return _eventToCanvasXY({
            clientX: touch.clientX,
            clientY: touch.clientY
        });
    }

    function _getTouchDistance(touch1, touch2) {
        return Math.hypot(
            touch2.clientX - touch1.clientX,
            touch2.clientY - touch1.clientY
        );
    }

    function _getTouchCenter(touch1, touch2) {
        return {
            x: (touch1.clientX + touch2.clientX) / 2,
            y: (touch1.clientY + touch2.clientY) / 2
        };
    }

    function initCanvasEvents() {
        if (_eventsBound) return;
        _eventsBound = true;

        const canvas = _getCanvas();
        if (!canvas) return;

        _resizeCanvasToDisplay();
        window.addEventListener('resize', () => {
            _resizeCanvasToDisplay();
            draw();
        });

        canvas.addEventListener('mousedown', (e) => {
            const p = _eventToCanvasXY(e);
            const x = p.x;
            const y = p.y;

            didDrag = false;
            downPos = { x, y };
            lastPointer = { x, y };

            const hit = _hitTestAnnotation(x, y);
            if (hit) {
                _setSelected(hit.id);
                draggingAnnoId = hit.id;
                draggingAnnoOffset = { dx: x - hit.x, dy: y - hit.y };
                return;
            }

            // Clicked background
            _setSelected(null);

            pendingPan = true;
            dragStart = { x: x - transform.x, y: y - transform.y };
        });

        canvas.addEventListener('mouseup', () => {
            pendingPan = false;
            draggingImage = false;
            draggingAnnoId = null;
            draggingAnnoOffset = null;
        });

        canvas.addEventListener('mouseout', () => {
            pendingPan = false;
            draggingImage = false;
            draggingAnnoId = null;
            draggingAnnoOffset = null;
        });

        canvas.addEventListener('mousemove', (e) => {
            const p = _eventToCanvasXY(e);
            const x = p.x;
            const y = p.y;
            lastPointer = { x, y };

            if (downPos && (Math.abs(x - downPos.x) > 2 || Math.abs(y - downPos.y) > 2)) {
                didDrag = true;
            }

            // Start panning only after user actually drags on background
            if (pendingPan && didDrag) {
                pendingPan = false;
                draggingImage = true;

                // ÄLÄ nollaa placement-toolia panoroinnissa.
                // Käyttäjän pitää voida jatkaa samalla työkalulla heti pan/drag jälkeen.
                _emitState();
            }

            if (draggingAnnoId && draggingAnnoOffset) {
                const a = annotations.find(v => v && v.id === draggingAnnoId);
                if (a) {
                    a.x = Number(x - draggingAnnoOffset.dx);
                    a.y = Number(y - draggingAnnoOffset.dy);
                    draw();

                    // UUSI: toolbar seuraa mukana (päivittyy 1/frame)
                    _emitStateThrottled();
                }
                return;
            }

            if (draggingImage) {
                transform.x = x - dragStart.x;
                transform.y = y - dragStart.y;
                draw();
            }
        });

        canvas.addEventListener('click', (e) => {
            // If user dragged, do NOT place a new annotation
            if (didDrag) {
                didDrag = false;
                return;
            }

            const p = _eventToCanvasXY(e);
            const x = p.x;
            const y = p.y;

            // If click hits existing annotation, just select (no new)
            const hit = _hitTestAnnotation(x, y);
            if (hit) {
                _setSelected(hit.id);
                return;
            }

            if (!currentTool) return;

            // ✅ TEXT tool ei lisää "icon text" -merkintää (joka aiheuttaa tyhjän kehikon)
            if (currentTool === 'text') {
                lastPointer = { x, y };
                document.dispatchEvent(new CustomEvent('sf:editor-request-text', { detail: { x, y } }));
                return;
            }

            addIcon(currentTool, x, y);
        }, { passive: true });

        function _zoomAt(cx, cy, delta) {
            const oldScale = transform.scale;
            const newScale = Math.max(0.1, oldScale + delta);
            if (newScale === oldScale) return;

            // Keep point (cx,cy) stable while zooming
            transform.x = cx - (cx - transform.x) * (newScale / oldScale);
            transform.y = cy - (cy - transform.y) * (newScale / oldScale);
            transform.scale = newScale;
            draw();
        }

        canvas.addEventListener('wheel', (e) => {
            e.preventDefault();

            // Zoomaa osoittimen kohdalta
            const p = _eventToCanvasXY(e);
            const scaleStep = (e.deltaY < 0) ? 0.05 : -0.05;

            _zoomAt(p.x, p.y, scaleStep);
        }, { passive: false });

        // ===== TOUCH EVENTS (Mobile support) =====

        function _resetTouchState() {
            pendingPan = false;
            draggingImage = false;
            draggingAnnoId = null;
            draggingAnnoOffset = null;
            touchStartDist = 0;
            touchStartScale = 1;
            touchStartTransform = null;
        }

        canvas.addEventListener('touchstart', (e) => {
            e.preventDefault();

            if (e.touches.length === 1) {
                // Single finger - pan or drag annotation
                const touch = e.touches[0];
                const p = _getTouchPoint(touch);
                const x = p.x;
                const y = p.y;

                didDrag = false;
                downPos = { x, y };
                lastPointer = { x, y };

                // Check if touching annotation
                const hit = _hitTestAnnotation(x, y);
                if (hit) {
                    _setSelected(hit.id);
                    draggingAnnoId = hit.id;
                    draggingAnnoOffset = { dx: x - hit.x, dy: y - hit.y };
                    return;
                }

                // Touching background - prepare to pan
                _setSelected(null);
                pendingPan = true;
                dragStart = { x: x - transform.x, y: y - transform.y };

            } else if (e.touches.length === 2) {
                // Two fingers - pinch zoom
                const t1 = e.touches[0];
                const t2 = e.touches[1];

                touchStartDist = _getTouchDistance(t1, t2);
                touchStartScale = transform.scale;
                touchStartTransform = { ...transform };

                // Cancel any pending pan
                pendingPan = false;
                draggingImage = false;
                draggingAnnoId = null;
            }
        }, { passive: false });

        canvas.addEventListener('touchmove', (e) => {
            e.preventDefault();

            if (e.touches.length === 1) {
                const touch = e.touches[0];
                const p = _getTouchPoint(touch);
                const x = p.x;
                const y = p.y;

                lastPointer = { x, y };

                if (downPos && (Math.abs(x - downPos.x) > 2 || Math.abs(y - downPos.y) > 2)) {
                    didDrag = true;
                }

                // Dragging annotation
                if (draggingAnnoId && draggingAnnoOffset) {
                    const a = annotations.find(v => v && v.id === draggingAnnoId);
                    if (a) {
                        a.x = Number(x - draggingAnnoOffset.dx);
                        a.y = Number(y - draggingAnnoOffset.dy);
                        draw();
                        _emitStateThrottled();
                    }
                    return;
                }

                // Start panning after drag threshold
                if (pendingPan && didDrag) {
                    pendingPan = false;
                    draggingImage = true;
                    _emitState();
                }

                // Pan image
                if (draggingImage) {
                    transform.x = x - dragStart.x;
                    transform.y = y - dragStart.y;
                    draw();
                }

            } else if (e.touches.length === 2) {
                // Pinch zoom
                const t1 = e.touches[0];
                const t2 = e.touches[1];

                const currentDist = _getTouchDistance(t1, t2);

                // Prevent division by zero if fingers are very close together
                // Use 10px threshold since touch coordinates are in pixels
                if (touchStartDist < 10 || currentDist < 10) return;

                const center = _getTouchCenter(t1, t2);
                const centerCanvas = _eventToCanvasXY(center);

                // Calculate new scale
                const scaleFactor = currentDist / touchStartDist;
                const newScale = Math.max(0.1, Math.min(5, touchStartScale * scaleFactor));

                // Zoom around pinch center
                const oldScale = touchStartTransform.scale;
                transform.scale = newScale;
                transform.x = centerCanvas.x - (centerCanvas.x - touchStartTransform.x) * (newScale / oldScale);
                transform.y = centerCanvas.y - (centerCanvas.y - touchStartTransform.y) * (newScale / oldScale);

                draw();
            }
        }, { passive: false });

        canvas.addEventListener('touchend', (e) => {
            e.preventDefault();

            if (e.touches.length === 0) {
                // All fingers lifted
                _resetTouchState();
            } else if (e.touches.length === 1) {
                // One finger still down - switch back to pan mode
                const touch = e.touches[0];
                const p = _getTouchPoint(touch);
                downPos = { x: p.x, y: p.y };
                pendingPan = true;
                dragStart = { x: p.x - transform.x, y: p.y - transform.y };

                // Reset pinch state
                touchStartDist = 0;
                touchStartScale = 1;
                touchStartTransform = null;
            }
        }, { passive: false });

        canvas.addEventListener('touchcancel', () => {
            _resetTouchState();
        });
    }

    function setTool(tool) {
        currentTool = tool || null;

        // Kun valitaan placement tool, poistetaan valinta olemassa olevasta merkinnästä
        // (ettei UI jää "valittu merkintä" -tilaan).
        if (currentTool) {
            selectedAnnoId = null;
        }

        _emitState();
        draw();
    }

    function addIcon(tool, x, y) {
        if (!tool) return;
        const id = 'a' + Math.random().toString(16).slice(2);

        annotations.push({
            id,
            type: 'icon',
            tool,
            x: Number(x),
            y: Number(y),
            size: 140
        });

        // Valitse juuri lisätty -> nyt Rotate/Delete/Size/Text voidaan aktivoida
        _setSelected(id);
    }

    function addLabelAt(x, y, text) {
        // Legacy API: ohjataan nykyiseen toteutukseen
        const t = String(text || '').trim();
        if (!t) return;

        // aseta osoitin ja lisää kuten addTextAt
        lastPointer = { x: Number(x), y: Number(y) };
        addTextAt(t);
    }

    function addLabel() {
        // Legacy API: ei käytössä enää
        return;
    }

    function zoom(delta) {
        const canvas = _getCanvas();
        if (!canvas) return;

        const cx = canvas.width / 2;
        const cy = canvas.height / 2;

        const oldScale = transform.scale;
        const newScale = Math.max(0.1, oldScale + delta);
        if (newScale === oldScale) return;

        transform.x = cx - (cx - transform.x) * (newScale / oldScale);
        transform.y = cy - (cy - transform.y) * (newScale / oldScale);
        transform.scale = newScale;

        draw();
    }

    function nudge(dx, dy) {
        transform.x += dx;
        transform.y += dy;
        draw();
    }

    function resetFit() {
        const canvas = _getCanvas();
        if (!canvas || !img) return;

        const scaleX = canvas.width / img.width;
        const scaleY = canvas.height / img.height;

        // COVER reset
        const scale = Math.max(scaleX, scaleY);

        transform = {
            scale: scale,
            x: (canvas.width - img.width * scale) / 2,
            y: (canvas.height - img.height * scale) / 2
        };
        draw();
    }

    function getState() {
        return {
            transform: { ...transform },
            annotations: Array.isArray(annotations) ? annotations : []
        };
    }

    function setState(stateObj) {
        if (!stateObj || typeof stateObj !== 'object') return;

        if (stateObj.transform && typeof stateObj.transform === 'object') {
            transform = {
                x: Number(stateObj.transform.x ?? transform.x),
                y: Number(stateObj.transform.y ?? transform.y),
                scale: Number(stateObj.transform.scale ?? transform.scale),
            };
        }
        if (Array.isArray(stateObj.annotations)) {
            annotations = stateObj.annotations;
        }
        draw();
    }
    function deleteSelected() {
        if (!selectedAnnoId) return;
        annotations = annotations.filter(a => a && a.id !== selectedAnnoId);
        selectedAnnoId = null;
        _emitState();
        draw();
    }

    function rotateSelected() {
        if (!selectedAnnoId) return;
        const a = annotations.find(v => v && v.id === selectedAnnoId);
        if (!a || a.type !== 'icon') return;

        a.rot = Number(a.rot || 0) + 45;
        if (a.rot >= 360) a.rot = a.rot - 360;

        _emitState();
        draw();
    }

    function hasSelectedText() {
        if (!selectedAnnoId) return false;
        const a = annotations.find(v => v && v.id === selectedAnnoId);
        return !!(a && a.type === 'text');
    }

    function getSelectedText() {
        if (!selectedAnnoId) return '';
        const a = annotations.find(v => v && v.id === selectedAnnoId);
        if (!a || a.type !== 'text') return '';
        return String(a.text || '');
    }

    function updateSelectedText(newText) {
        if (!selectedAnnoId) return;
        const a = annotations.find(v => v && v.id === selectedAnnoId);
        if (!a || a.type !== 'text') return;

        a.text = String(newText || '');
        _emitState();
        draw();
    }

    function addTextAt(x, y, text = '') {
        // Yhteensopivuus:  jos kutsutaan addTextAt("joku teksti")
        if (typeof x === 'string' && typeof y === 'undefined') {
            const t = x;
            return addTextAt(lastPointer.x, lastPointer.y, t);
        }

        const canvas = _getCanvas();
        const cw = canvas ? canvas.width : CANVAS_W;
        const ch = canvas ? canvas.height : CANVAS_H;

        const t = String(text || '').replace(/\r\n/g, '\n');

        // Arvioidaan tekstilaatikon koko (sama maxWidth kuin draw():ssa)
        const lines = t.split('\n');
        const maxLen = Math.max(...lines.map(l => (l || '').length), 1);

        const maxWidth = 980;
        const approxTextW = Math.min(maxWidth, Math.max(140, maxLen * 16));
        const approxTextH = Math.max(44, lines.length * 40);

        // paddingit (hitTestissä käytetyt)
        const boxW = approxTextW + 24;
        const boxH = approxTextH + 16;

        const pad = 12;

        let px = Number(x);
        let py = Number(y);

        if (!Number.isFinite(px)) px = cw / 2;
        if (!Number.isFinite(py)) py = ch / 2;

        // Clamp: pidä laatikko varmasti canvasin sisällä
        px = Math.max(pad, Math.min(cw - boxW - pad, px));
        py = Math.max(pad, Math.min(ch - boxH - pad, py));

        // Päivitä myös lastPointer, jotta seuraava toiminto ei hyppää reunaan
        lastPointer = { x: px, y: py };

        const id = 'a' + Math.random().toString(16).slice(2);

        annotations.push({
            id,
            type: 'text',
            x: px,
            y: py,
            text: t,
            size: 32
        });

        selectedAnnoId = id;
        _emitState();
        draw();
    }

    function save() {
        // Palauttaa datan:  transform + annotations + dataURL (merkinnät kiinni)
        try {
            const canvas = _getCanvas();
            if (!canvas) {
                return { dataURL: "", transform: { ...transform }, annotations: Array.isArray(annotations) ? annotations : [] };
            }
            // Luo export-canvas ja kutsu drawForExport
            const exportCanvas = document.createElement('canvas');
            exportCanvas.width = canvas.width;
            exportCanvas.height = canvas.height;
            const exportCtx = exportCanvas.getContext('2d');
            drawForExport(exportCtx, exportCanvas);
            const dataUrl = exportCanvas.toDataURL('image/png');
            return { dataURL: dataUrl, transform: { ...transform }, annotations: Array.isArray(annotations) ? annotations : [] };
        } catch (e) {
            console.error("SFImageEditor.save failed:", e);
            return { dataURL: "", transform: { ...transform }, annotations: Array.isArray(annotations) ? annotations : [] };
        }
    }

    function drawForExport(exportCtx, exportCanvas) {
        // Käytetään samaa logiikkaa kuin draw(), mutta EI piirretä turvaviivoja
        if (!exportCanvas || !exportCtx) return;

        exportCtx.clearRect(0, 0, exportCanvas.width, exportCanvas.height);

        // background
        exportCtx.fillStyle = '#fafafa';
        exportCtx.fillRect(0, 0, exportCanvas.width, exportCanvas.height);

        // image - käytä moduulin img ja transform
        if (img && img.complete) {
            exportCtx.save();
            exportCtx.translate(transform.x, transform.y);
            exportCtx.scale(transform.scale, transform.scale);
            exportCtx.drawImage(img, 0, 0);
            exportCtx.restore();
        }

        // annotations (EI turvaviivoja)
        if (annotations && annotations.length) {
            annotations.forEach(a => {
                if (!a) return;

                // --- TEXT ---
                if (a.type === 'text') {
                    exportCtx.save();

                    const text = String(a.text || '').replace(/\r\n/g, '\n');
                    if (!text.trim()) { exportCtx.restore(); return; }

                    const x = Number(a.x ?? 0);
                    const y = Number(a.y ?? 0);

                    const fontSize = Number(a.size || 32);
                    const fontFamily = 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
                    exportCtx.font = `700 ${fontSize}px ${fontFamily}`;
                    exportCtx.textBaseline = 'top';

                    const padX = 12;
                    const padY = 10;
                    const radius = 12;
                    const maxWidth = 980;

                    const rawLines = text.split('\n');
                    const lines = [];
                    rawLines.forEach((ln) => {
                        const words = String(ln).split(/\s+/).filter(Boolean);
                        if (!words.length) { lines.push(''); return; }
                        let line = words[0];
                        for (let i = 1; i < words.length; i++) {
                            const test = line + ' ' + words[i];
                            const w = exportCtx.measureText(test).width;
                            if (w > maxWidth && line.length) {
                                lines.push(line);
                                line = words[i];
                            } else {
                                line = test;
                            }
                        }
                        lines.push(line);
                    });

                    const lineH = Math.round(fontSize * 1.25);
                    const textW = Math.max(0, ...lines.map(l => exportCtx.measureText(l).width));
                    const textH = Math.max(1, lines.length) * lineH;
                    const boxW = Math.min(maxWidth, textW) + padX * 2;
                    const boxH = textH + padY * 2;
                    const bx = x;
                    const by = y;

                    exportCtx.fillStyle = 'rgba(0, 0, 0, 0.55)';
                    exportCtx.strokeStyle = 'rgba(255, 255, 255, 0.65)';
                    exportCtx.lineWidth = 2;

                    const rr = (r, w, h) => Math.max(0, Math.min(r, Math.min(w, h) / 2));
                    const r = rr(radius, boxW, boxH);

                    exportCtx.beginPath();
                    exportCtx.moveTo(bx + r, by);
                    exportCtx.arcTo(bx + boxW, by, bx + boxW, by + boxH, r);
                    exportCtx.arcTo(bx + boxW, by + boxH, bx, by + boxH, r);
                    exportCtx.arcTo(bx, by + boxH, bx, by, r);
                    exportCtx.arcTo(bx, by, bx + boxW, by, r);
                    exportCtx.closePath();
                    exportCtx.fill();
                    exportCtx.stroke();

                    exportCtx.fillStyle = '#ffffff';
                    let ty = by + padY;
                    lines.forEach((l) => {
                        exportCtx.fillText(l, bx + padX, ty);
                        ty += lineH;
                    });

                    exportCtx.restore();
                    return;
                }

                // --- ICON ---
                if (a.type === 'icon') {
                    const tool = a.tool;
                    const size = Number(a.size || 140);
                    const ax = Number(a.x || 0);
                    const ay = Number(a.y || 0);
                    const rot = Number(a.rot || 0);

                    const im = iconCache[tool];
                    if (im && im.complete) {
                        exportCtx.save();
                        exportCtx.translate(ax, ay);
                        if (rot) exportCtx.rotate((rot * Math.PI) / 180);
                        exportCtx.drawImage(im, -size / 2, -size / 2, size, size);
                        exportCtx.restore();
                    }
                }
            });
        }
    }

    return {
        setup, draw, initCanvasEvents,
        setTool, addIcon, addLabel, addLabelAt,
        addTextAt,
        hasSelectedText, getSelectedText, updateSelectedText,
        deleteSelected, rotateSelected,
        changeSelectedSize,
        zoom, nudge, resetFit,
        getState, setState, save,
        drawForExport
    };
})();