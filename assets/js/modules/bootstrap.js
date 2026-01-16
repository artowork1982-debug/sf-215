// assets/js/modules/bootstrap.js

import { state, getters, setSelectedLang, setSelectedType } from './state.js';
import { updatePreview, updatePreviewLabels, handleConditionalFields } from './preview-update.js';
import { validateStep, showValidationErrors } from './validation.js';
import { showStep, bindStepButtons } from './navigation.js';
import { bindUploads } from './uploads.js';
import { bindRelatedFlash } from './related-flash.js';
import { bindSubmit } from './submit.js';
import { autoSave } from './autosave.js';

// Importataan preview-moduulit
import { Preview } from './preview-core.js';
import { PreviewTutkinta } from './preview-tutkinta.js';

const { getEl, qsa, qs } = getters;

// =====================================================
// 1) APUTOIMINNOT
// =====================================================

function updateStep1NextButton() {
    const nextBtn = getEl('sfNext');
    if (!nextBtn) return;

    const langSelected = qs('input[name="lang"]:checked');
    const typeSelected = qs('input[name="type"]:checked');

    if ((langSelected || state.selectedLang) && (typeSelected || state.selectedType)) {
        nextBtn.disabled = false;
        nextBtn.classList.remove('disabled');
    } else {
        nextBtn.disabled = true;
        nextBtn.classList.add('disabled');
    }
}

/**
 * Huom: Smooth-navigaatiossa DOM vaihtuu, joten
 * (a) elementtikohtaisia eventtejä ei kannata sitoa "kerran ja valmis" -mallilla
 * (b) document-tason delegointi toimii aina
 */

// =====================================================
// 2) EVENT-DELEGOINNIT (SITOUDUTAAN VAIN KERRAN)
// =====================================================

let documentDelegationBound = false;

function bindDocumentDelegationOnce() {
    if (documentDelegationBound) return;
    documentDelegationBound = true;

    // Document-level event delegation for form interactions
    document.addEventListener('click', (e) => {
        // Kieli-valinta (delegointi)
        const langBox = e.target.closest('.sf-lang-box');
        if (langBox) {
            e.preventDefault();
            e.stopPropagation();

            const radio = langBox.querySelector('input[type="radio"]');
            if (!radio) return;

            qsa('input[name="lang"]').forEach((r) => (r.checked = false));
            radio.checked = true;

            setSelectedLang(radio.value);

            qsa('.sf-lang-box').forEach((b) => b.classList.remove('selected'));
            langBox.classList.add('selected');

            updatePreviewLabels();
            updatePreview();
            updateStep1NextButton();
            return;
        }

        // Tyyppi-valinta (delegointi)
        const typeBox = e.target.closest('.sf-type-box');
        if (typeBox) {
            e.preventDefault();
            e.stopPropagation();

            const radio = typeBox.querySelector('input[type="radio"]');
            if (!radio) return;

            qsa('input[name="type"]').forEach((r) => (r.checked = false));
            radio.checked = true;

            setSelectedType(radio.value);

            qsa('.sf-type-box').forEach((b) => b.classList.remove('selected'));
            typeBox.classList.add('selected');

            const formEl = getEl('sf-form');
            if (formEl) {
                formEl.classList.remove('type-red', 'type-yellow', 'type-green');
                formEl.classList.add('type-' + state.selectedType);
            }

            handleConditionalFields();
            updatePreview();
            updateStep1NextButton();
            return;
        }

        // Grid-napit (delegointi)
        const gridBtn = e.target.closest('.sf-grid-btn');
        if (gridBtn) {
            const forCount = gridBtn.getAttribute('data-for');
            const container = gridBtn.closest('.sf-grid-buttons');

            if (container) {
                container.querySelectorAll('.sf-grid-btn').forEach((b) => {
                    if (b.getAttribute('data-for') === forCount) b.classList.remove('active');
                });
            }
            gridBtn.classList.add('active');

            const isGreen = gridBtn.closest('#sfGridSelectorGreen') !== null;
            const gridType = gridBtn.dataset.grid;

            if (isGreen && window.PreviewTutkinta) {
                window.PreviewTutkinta.applyGridClass(gridType);
            } else if (!isGreen && window.Preview) {
                window.Preview.applyGridClass(gridType);
            }
            return;
        }

        // Tools-tabit (delegointi)
        const toolsTab = e.target.closest('.sf-tools-tab');
        if (toolsTab) {
            const targetPanel = toolsTab.getAttribute('data-panel');
            const parentTabs = toolsTab.closest('.sf-tools-tabs');

            if (parentTabs) {
                parentTabs.querySelectorAll('.sf-tools-tab').forEach((t) => t.classList.remove('active'));
            }
            toolsTab.classList.add('active');

            const panels = document.querySelectorAll('.sf-tools-panel');
            const isGreen = targetPanel && targetPanel.includes('Green');

            panels.forEach((panel) => {
                const panelId = panel.getAttribute('data-panel');
                const panelIsGreen = panelId && panelId.includes('Green');

                if (isGreen === panelIsGreen) {
                    if (panelId === targetPanel) panel.classList.add('active');
                    else panel.classList.remove('active');
                }
            });

            return;
        }
    });

    // Preview-kenttien input (delegointi)
    document.addEventListener('input', (e) => {
        const el = e.target;
        if (!(el instanceof HTMLElement)) return;

        if (
            el.matches(
                '#sf-short-text, #sf-description, #sf-date, #sf-worksite, #sf-site-detail, #sf-root-causes, #sf-actions'
            )
        ) {
            updatePreview();
        }
    });
}

// =====================================================
// 3) CHAR COUNTERIT (AJA PER PAGE-RENDER, EI DUPLICOIDA)
// =====================================================

function initCharCounters() {
    const fieldsWithLimits = [
        { id: 'sf-short-text', max: 85, lineBreakCost: 0 },
        { id: 'sf-description', max: 950, lineBreakCost: 50 },
        { id: 'sf-root-causes', max: 1500, lineBreakCost: 30 },
        { id: 'sf-actions', max: 1500, lineBreakCost: 30 }
    ];

    fieldsWithLimits.forEach(({ id, max, lineBreakCost }) => {
        const field = getEl(id);
        if (!field) return;

        // Jos counter jo olemassa tässä DOMissa, älä lisää uutta
        const existing = getEl(id + '-counter');
        let counter = existing;

        if (!counter) {
            counter = document.createElement('div');
            counter.className = 'sf-char-counter';
            counter.id = id + '-counter';
            field.parentElement?.appendChild(counter);
        }

        function calculateUsed() {
            const text = field.value || '';
            const charCount = text.length;
            const lineBreaks = (text.match(/\n/g) || []).length;
            return charCount + lineBreaks * lineBreakCost;
        }

        function enforceLimit() {
            let text = field.value || '';
            let used = calculateUsed();

            while (used > max && text.length > 0) {
                text = text.slice(0, -1);
                const lineBreaks = (text.match(/\n/g) || []).length;
                used = text.length + lineBreaks * lineBreakCost;
            }

            if (text !== field.value) {
                const cursorPos = field.selectionStart ?? text.length;
                field.value = text;
                field.setSelectionRange(
                    Math.min(cursorPos, text.length),
                    Math.min(cursorPos, text.length)
                );
            }
        }

        function updateCounter() {
            const used = calculateUsed();
            const remaining = max - used;

            counter.textContent = `${used} / ${max}`;
            counter.classList.remove('sf-counter-warning', 'sf-counter-error');

            if (remaining <= 0) counter.classList.add('sf-counter-error');
            else if (remaining < max * 0.1) counter.classList.add('sf-counter-warning');
        }

        // HUOM: nämä listenerit kiinnittyvät fieldiin joka kuuluu nyky-DOMiin
        field.addEventListener('input', () => {
            enforceLimit();
            updateCounter();
        });

        field.addEventListener('paste', () => {
            setTimeout(() => {
                enforceLimit();
                updateCounter();
            }, 0);
        });

        updateCounter();
    });
}

// =====================================================
// 4) FORM-SIVUN INIT (AJA DOMContentLoaded + sf:pagechange)
// =====================================================

function setPreviewBaseUrl() {
    try {
        const base = typeof SF_BASE_URL !== 'undefined' ? SF_BASE_URL.replace(/\/$/, '') : '';

        const card = getEl('sfPreviewCard');
        const cardGreen = getEl('sfPreviewCardGreen');

        if (card && base) card.dataset.baseUrl = base;
        if (cardGreen && base) cardGreen.dataset.baseUrl = base;
    } catch (e) {
        console.warn('SF_BASE_URL ei määritelty:', e);
    }
}

function initSelectionsFromDOM() {
    const checkedLangOnLoad = qs('input[name="lang"]:checked');
    if (checkedLangOnLoad) {
        setSelectedLang(checkedLangOnLoad.value);
        qsa('.sf-lang-box').forEach((box) => box.classList.remove('selected'));
        checkedLangOnLoad.closest('.sf-lang-box')?.classList.add('selected');
    }

    const checkedTypeOnLoad = qs('input[name="type"]:checked');
    if (checkedTypeOnLoad) {
        setSelectedType(checkedTypeOnLoad.value);
        qsa('.sf-type-box').forEach((box) => box.classList.remove('selected'));
        checkedTypeOnLoad.closest('.sf-type-box')?.classList.add('selected');

        const formEl = getEl('sf-form');
        if (formEl) {
            formEl.classList.remove('type-red', 'type-yellow', 'type-green');
            formEl.classList.add('type-' + state.selectedType);
        }
    }

    // KORJAUS: Kutsu handleConditionalFields() AINA, ei vain kun tyyppi on valittuna
    // Tämä varmistaa, että sf-step2-incident on piilotettu kun tyyppi ei ole green
    handleConditionalFields();
}

function initSteps() {
    const initialStepInput = getEl('initialStep');
    const startStep = initialStepInput ? parseInt(initialStepInput.value, 10) : 1;
    showStep(isNaN(startStep) || startStep < 1 ? 1 : startStep, true);
}

function isFormPageNow() {
    // index.php asettaa <body data-page="form">
    return document.body && document.body.dataset && document.body.dataset.page === 'form';
}

export function initFormPage() {
    if (!isFormPageNow()) return;

    // Delegointi kerran koko applikaation elinkaaren aikana
    bindDocumentDelegationOnce();

    // “Per render” initit
    setPreviewBaseUrl();
    initSelectionsFromDOM();
    updateStep1NextButton();
    initSteps();

    // Nämä sitovat eventtejä suoraan DOM-elementteihin (ok koska DOM vaihtuu),
    // ja/tai tekevät muuta sivukohtaista init-logiikkaa
    bindUploads();
    bindRelatedFlash();
    bindSubmit();
    initCharCounters();
    bindStepButtons();

    // Initialize autosave
    try {
        autoSave.init();
    } catch (_) { }

    // Jos preview tarvitsee kerran "pakota päivitys" initissä:
    try {
        updatePreviewLabels();
        updatePreview();
    } catch (_) { }

    // UUSI: Lataa merkinnät esikatseluun muokkaustilassa
    try {
        loadAnnotationsToPreview();
    } catch (_) { }
}

/**
 * Lataa tallennetut merkinnät esikatseluun muokkaustilassa. 
 * Tämä varmistaa, että merkinnät näkyvät heti kun sivu avataan,
 * eikä käyttäjän tarvitse avata kuvaeditooria.
 */
function loadAnnotationsToPreview() {
    const storeEl = getEl('sf-edit-annotations-data');
    if (!storeEl || !storeEl.value) return;

    let stored;
    try {
        stored = JSON.parse(storeEl.value);
    } catch (e) {
        return;
    }

    if (!stored || typeof stored !== 'object') return;

    // Muunna sf-edit-annotations-data → sf-annotations-data muotoon
    // sf-edit-annotations-data:  {"image1": [...], "image2": [...], "image3": [...]}
    // sf-annotations-data: [{... ann, frameId: "sfPreviewImageFrame1"}, ...]
    const allAnnotations = [];

    [1, 2, 3].forEach(slot => {
        const key = `image${slot}`;
        const slotAnnotations = stored[key];

        if (Array.isArray(slotAnnotations) && slotAnnotations.length > 0) {
            slotAnnotations.forEach(ann => {
                // Varmista frameId
                const frameId = ann.frameId || `sfPreviewImageFrame${slot}`;
                allAnnotations.push({
                    ...ann,
                    frameId: frameId,
                    slot: slot
                });
            });
        }
    });

    if (allAnnotations.length === 0) return;

    // Aseta sf-annotations-data -kenttään
    const targetEl = getEl('sf-annotations-data');
    if (targetEl) {
        targetEl.value = JSON.stringify(allAnnotations);
    }

    // Alusta Annotations-moduuli, joka renderöi merkinnät
    if (window.Annotations && typeof window.Annotations.init === 'function') {
        // Pieni viive jotta DOM ehtii renderöityä
        setTimeout(() => {
            window.Annotations.init();
        }, 100);
    }
}

// =====================================================
// 5) KÄYNNISTYS: ENSILATAUS + SMOOTH-NAV PALUU
// =====================================================

document.addEventListener('DOMContentLoaded', () => {
    initFormPage();
});

// Kun smooth-navigaatio vaihtaa sisällön, kutsu sama init uudestaan
window.addEventListener('sf:pagechange', () => {
    initFormPage();
});