import { getters } from './state.js';
const { getEl, qs } = getters;

async function waitFonts() {
    return document.fonts?.ready ?? Promise.resolve();
}

// Sovella transform: käytä _applyTransformToImg jos saatavilla
function applyTransformHelper(currentType, slot, img, st) {
    if (!img || !st) return;

    const isGreen = currentType === 'green';
    const host = isGreen ? window.PreviewTutkinta : window.Preview;
    const helper = host?._applyTransformToImg;

    if (helper) {
        helper.call(host, slot, img);
        return;
    }

    img.style.transformOrigin = 'center center';
    img.style.transform = `translate(-50%, -50%) translate(${st.x}px, ${st.y}px) scale(${st.scale})`;
}

function readTransform(currentType, slot) {
    const liveState =
        currentType === 'green'
            ? window.PreviewTutkinta?.state?.[slot]
            : window.Preview?.state?.[slot];

    if (liveState && typeof liveState.scale === 'number') return liveState;

    const el =
        document.getElementById(`sf-image${slot}-transform`) ||
        document.getElementById(`sf-image${slot}-transform-green`);

    if (!el || !el.value) return null;

    try {
        const parsed = JSON.parse(el.value);
        return typeof parsed.scale === 'number' ? parsed : null;
    } catch {
        return null;
    }
}

async function captureCard(previewCard, currentType) {
    const clone = previewCard.cloneNode(true);
    document.body.appendChild(clone);

    clone
        .querySelectorAll('.sf-preview-image-frame img, .sf-preview-thumb-frame img')
        .forEach((img) => {
            const src = (img.src || '').toLowerCase();
            if (src.includes('placeholder') || src.includes('camera')) {
                img.style.setProperty('display', 'none', 'important');
            }
        });

    clone.querySelectorAll('.sf-annotation-controls').forEach((ctrl) => {
        ctrl.style.setProperty('display', 'none', 'important');
    });

    [1, 2, 3].forEach((slot) => {
        const st = readTransform(currentType, slot);
        const imgId = currentType === 'green' ? `sfPreviewImg${slot}Green` : `sfPreviewImg${slot}`;
        const img = clone.querySelector(`#${imgId}`);
        if (st && img) applyTransformHelper(currentType, slot, img, st);
    });

    const rect = previewCard.getBoundingClientRect();
    const cardWidth = rect.width || 960;
    const cardHeight = rect.height || 540;
    const scale = Math.max(1920 / cardWidth, 1080 / cardHeight);

    clone.style.cssText = `
    position: fixed !important;
    left: -99999px !important;
    top: 0 !important;
    width: ${cardWidth}px !important;
    height: ${cardHeight}px !important;
    padding-bottom: 0 !important;
    z-index: -1 !important;
    display: block !important;
  `;

    await new Promise((res) => setTimeout(res, 30));

    const canvas = await html2canvas(clone, {
        scale,
        useCORS: true,
        allowTaint: true,
        backgroundColor: '#ffffff',
        logging: false,
        imageTimeout: 15000,
    });

    clone.remove();
    return canvas.toDataURL('image/jpeg', 0.92);
}

export async function capturePreviewCard1() {
    if (!window.html2canvas) return;
    await waitFonts();

    const hiddenPreviewInput =
        getEl('sf-preview-image-data') ??
        getEl('sf-form')?.querySelector('input[name="preview_image_data"]');

    if (!hiddenPreviewInput) return;

    const currentType = qs('input[name="type"]:checked')?.value;
    const previewCard = getEl(currentType === 'green' ? 'sfPreviewCardGreen' : 'sfPreviewCard');
    if (!previewCard) return;

    if (currentType === 'green') {
        window.PreviewTutkinta?.applyGridClass?.();
    } else {
        window.Preview?.applyGridClass?.();
    }

    window.Annotations?.hideForCapture?.();
    previewCard.querySelectorAll('.sf-active').forEach((el) => el.classList.remove('sf-active'));

    try {
        const dataUrl = await captureCard(previewCard, currentType);
        hiddenPreviewInput.value = dataUrl;
    } catch (err) {
        console.error('html2canvas error:', err);
    } finally {
        window.Annotations?.showAfterCapture?.();
    }
}

export async function capturePreviewCard2() {
    if (!window.html2canvas) return;
    await waitFonts();

    const hiddenPreviewInput2 =
        getEl('sf-preview-image-data-2') ??
        getEl('sf-form')?.querySelector('input[name="preview_image_data_2"]');

    if (!hiddenPreviewInput2) return;

    const previewCard2 = getEl('sfPreviewCard2Green');
    if (!previewCard2) return;

    const hasRootCauses = !!getEl('sf-root-causes')?.value.trim();
    const hasActions = !!getEl('sf-actions')?.value.trim();

    if (!hasRootCauses && !hasActions) {
        hiddenPreviewInput2.value = '';
        return;
    }

    window.PreviewTutkinta?.applyGridClass?.();

    try {
        const dataUrl = await captureCard(previewCard2, 'green');
        hiddenPreviewInput2.value = dataUrl;
    } catch (err) {
        console.error('html2canvas error (card 2):', err);
    } finally {
        window.Annotations?.showAfterCapture?.();
    }
}

export async function captureAllPreviews() {
    const currentType = qs('input[name="type"]:checked')?.value;
    await capturePreviewCard1();
    if (currentType === 'green') await capturePreviewCard2();
}

// Aseta funktiot globaaliin scopeen, jotta inline-skriptit voivat käyttää niitä
window.captureAllPreviews = captureAllPreviews;
window.capturePreviewCard1 = capturePreviewCard1;
window.capturePreviewCard2 = capturePreviewCard2;