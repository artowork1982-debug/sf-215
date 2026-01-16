import { getters } from './state.js';

const { getEl } = getters;

function createLoadingOverlay() {
    const overlay = document.createElement('div');
    overlay.id = 'sf-loading-overlay';
    const i18n = window.SF_I18N || {};
    const savingText = i18n.saving_flash || 'Saving... ';
    const previewText = i18n.generating_preview || 'Generating preview';
    overlay.innerHTML = `
        <div class="sf-loading-content">
            <div class="sf-loading-spinner"></div>
            <div class="sf-loading-text">${savingText}</div>
            <div class="sf-loading-subtext">${previewText}</div>
        </div>
    `;
    document.body.appendChild(overlay);
    return overlay;
}

function showLoading(message, subtext) {
    let overlay = getEl('sf-loading-overlay');
    if (!overlay) overlay = createLoadingOverlay();
    const i18n = window.SF_I18N || {};
    const textEl = overlay.querySelector('.sf-loading-text');
    const subtextEl = overlay.querySelector('.sf-loading-subtext');
    if (textEl) textEl.textContent = message || i18n.saving_flash || 'Saving...';
    if (subtextEl) subtextEl.textContent = subtext || i18n.generating_preview || 'Generating preview';
    overlay.classList.add('visible');
}

function hideLoading() {
    const overlay = getEl('sf-loading-overlay');
    if (overlay) overlay.classList.remove('visible');
}

function showToast(message, type = 'info') {
    // käytä mieluummin globaalista headerista löytyvää toastia jos on
    if (typeof window.sfToast === 'function') {
        window.sfToast(type, message);
        return;
    }

    const toast = document.createElement('div');
    toast.className = `sf-toast sf-toast-${type} visible`;
    toast.innerHTML = `<div class="sf-toast-content">${message}</div>`;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.classList.remove('visible');
        setTimeout(() => toast.remove(), 500);
    }, 5000);
}

function showProgressToast(flashId) {
    let toast = document.getElementById('sfProgressToast');
    if (toast) toast.remove();

    toast = document.createElement('div');
    toast.className = 'sf-toast sf-toast-info visible';
    toast.id = 'sfProgressToast';
    toast.innerHTML = `
        <div class="sf-toast-content">
                        ${window.SF_I18N?.processing_flash || 'Safetyflashia prosessoidaan taustalla...'}
            <div class="sf-progress-bar">
                <span id="sfProgressValue" style="width: 0%;"></span>
            </div>
            <span id="sfProgressText">0%</span>
        </div>
    `;
    document.body.appendChild(toast);
    trackProcessStatus(flashId);
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function trackProcessStatus(flashId) {
    const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
    const intervalId = setInterval(async () => {
        try {
            const url = `${baseUrl}/app/api/check-status.php?flash_id=${encodeURIComponent(flashId)}`;
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error(`Server responded with status: ${response.status}`);
            }

            const data = await response.json();
            const { status, progress } = data;

            const progressBar = document.getElementById('sfProgressValue');
            const progressText = document.getElementById('sfProgressText');

            if (progressBar && progressText) {
                progressBar.style.width = `${progress}%`;
                progressText.textContent = `${progress}%`;
            }

            if (status === 'completed' || progress >= 100) {
                clearInterval(intervalId);
                const toast = document.getElementById('sfProgressToast');
                if (toast) {
                    const i18n = window.SF_I18N || {};
                    toast.querySelector('.sf-toast-content').innerHTML = i18n.processing_complete || 'Processing complete!';
                    toast.classList.remove('sf-toast-info');
                    toast.classList.add('sf-toast-success');
                    setTimeout(() => {
                        toast.classList.remove('visible');
                        setTimeout(() => toast.remove(), 2000);
                    }, 2000);
                }
            } else if (status === 'error') {
                throw new Error('Processing failed on the server.');
            }
        } catch (err) {
            console.error('Error tracking process status:', err);
            clearInterval(intervalId);
            const toast = document.getElementById('sfProgressToast');
            if (toast) {
                toast.className = 'sf-toast sf-toast-danger visible';
                const i18n = window.SF_I18N || {};
                toast.querySelector('.sf-toast-content').innerHTML = i18n.processing_failed || 'Processing failed.';
            }
        }
    }, 3000);
}

async function doSubmit(form, isDraft) {
    const i18n = window.SF_I18N || {};
    showLoading(
        isDraft ? (i18n.saving_draft || 'Tallentaa luonnosta...') : (i18n.generating_preview || 'Generoidaan esikatselukuvaa...'),
        i18n.please_wait || 'Odota hetki...'
    );

    const draftBtn = getEl('sfSaveDraft');
    const reviewBtn = getEl('sfSubmitReview');
    if (draftBtn) draftBtn.disabled = true;
    if (reviewBtn) reviewBtn.disabled = true;

    const confirmModal = getEl('sfConfirmModal');
    if (confirmModal) confirmModal.classList.add('hidden');

    try {
        // PALAUTETTU: Generoi preview-kuva html2canvasilla
        // Tämä on nopea koska kuvat on jo uploadattu serverille (Immediate Upload)
        // ja selaimessa on vain pienet URL-viittaukset
        const { captureAllPreviews } = await import('./capture.js');
        await captureAllPreviews();

        const formData = new FormData(form);
        formData.append('submission_type', isDraft ? 'draft' : 'review');
        formData.append('is_ajax', '1');

        const response = await fetch(form.action, {
            method: 'POST',
            body: formData,
        });

        const raw = await response.text();
        let result = null;
        try { result = JSON.parse(raw); } catch (_) { }

        if (!response.ok) {
            hideLoading();
            const msg =
                (result && (result.error || result.message))
                    ? (result.error || result.message)
                    : `Palvelin vastasi virheellä: ${response.status}`;

            const debug = result && result.debug ? ` (${result.debug})` : '';
            throw new Error(msg + debug);
        }

        if (!result) {
            hideLoading();
            throw new Error('Tuntematon vastaus palvelimelta.');
        }

        if (result.ok && result.flash_id) {
            // Mark submission in progress to prevent beforeunload from saving draft
            if (window.autoSave) {
                window.autoSave.submissionInProgress = true;
                window.autoSave.stop(); // Stop autosave interval immediately
            }

            // Delete autosave drafts - palvelin hoitaa tämän nyt luotettavammin,
            // mutta tehdään myös client-puolella varmuuden vuoksi
            // HUOM: Ei poisteta kun tallennetaan luonnosta (isDraft=true)
            if (window.autoSave && !isDraft) {
                try {
                    const deletionPromises = [];

                    // Delete current draft if exists
                    if (window.autoSave.currentDraftId) {
                        deletionPromises.push(window.autoSave.deleteDraft(window.autoSave.currentDraftId));
                    }

                    // Also delete any drafts from SF_USER_DRAFTS (shown in recovery overlay)
                    // Filter out current draft to avoid duplicate deletion attempts
                    const userDrafts = (window.SF_USER_DRAFTS || [])
                        .filter(draft => draft.id !== window.autoSave.currentDraftId);

                    // Add all user draft deletions to the promise array
                    deletionPromises.push(...userDrafts.map(draft => window.autoSave.deleteDraft(draft.id)));

                    // Use allSettled to ensure all deletions are attempted even if some fail
                    await Promise.allSettled(deletionPromises);

                    // Clear current draft ID and global state after deletions complete
                    window.autoSave.currentDraftId = null;
                    window.SF_USER_DRAFTS = []; // Tyhjennä myös tämä
                } catch (e) {
                    console.warn('Draft deletion failed:', e);
                }
            }

            // “lähetetään tarkistettavaksi” näkyy aina saman ajan (ms)
            showLoading(
                isDraft ? (i18n.draft_saved || 'Draft saved.') : (i18n.sending_for_review || 'Sending for review.'),
                i18n.processing_continues || 'Processing continues in background.'
            );
            await sleep(1200); // <-- säädä tarvittaessa
            hideLoading();

            showToast(i18n.data_received_processing || 'Data received. Processing continues in background.', 'success');

            // Set redirectInProgress flag to prevent beforeunload from saving a new draft
            if (window.autoSave) {
                window.autoSave.redirectInProgress = true;
            }

            const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
            window.location.href = `${baseUrl}/index.php?page=list&bg_process=${encodeURIComponent(result.flash_id)}`;
            return;
        }

        if (result.error) {
            throw new Error(result.error);
        }

        throw new Error('Tuntematon vastaus palvelimelta.');
    } catch (err) {
        console.error('Error during submission:', err);
        hideLoading();
        const i18n = window.SF_I18N || {};
        alert(`${i18n.save_failed || 'Save failed: '}${err.message}`);
        if (draftBtn) draftBtn.disabled = false;
        if (reviewBtn) reviewBtn.disabled = false;
    }
}

function checkAndTrackBackgroundProcess() {
    const urlParams = new URLSearchParams(window.location.search);
    const processId = urlParams.get('bg_process');
    if (processId) {
        showProgressToast(processId);

        const newUrl =
            window.location.pathname +
            window.location.search.replace(/&?bg_process=[^&]+/, '');
        window.history.replaceState({ path: newUrl }, '', newUrl);
    }
}

export function bindSubmit() {
    const form = getEl('sf-form');
    if (!form) return;

    // Ei kovakoodattuja polkuja: käytä base_url:ia
    const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
    if (baseUrl) {
        form.action = `${baseUrl}/app/api/save_flash.php`;
    }

    const draftBtn = getEl('sfSaveDraft');
    const reviewBtn = getEl('sfSubmitReview');
    const confirmModal = getEl('sfConfirmModal');
    const confirmSubmitBtn = getEl('sfConfirmSubmit');

    if (draftBtn) {
        draftBtn.addEventListener('click', (e) => {
            e.preventDefault();
            doSubmit(form, true);
        });
    }

    if (reviewBtn) {
        reviewBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (confirmModal) {
                confirmModal.classList.remove('hidden');
                document.body.classList.add('sf-modal-open');
            } else {
                doSubmit(form, false);
            }
        });
    }

    if (confirmSubmitBtn) {
        confirmSubmitBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (confirmModal) {
                confirmModal.classList.add('hidden');
                document.body.classList.remove('sf-modal-open');
            }
            doSubmit(form, false);
        });
    }

    form.addEventListener('submit', (e) => e.preventDefault());

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkAndTrackBackgroundProcess);
    } else {
        checkAndTrackBackgroundProcess();
    }
}