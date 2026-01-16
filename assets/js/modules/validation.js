import { getters, state } from './state.js';
const { qs, getEl } = getters;

export function validateStep(stepNumber) {
    const errors = [];
    const currentType = qs('input[name="type"]:checked')?.value;
    const i18n = window.SF_I18N || {};

    if (stepNumber === 1) {
        if (!qs('input[name="lang"]:checked')) errors.push(i18n.validation_select_language || 'Select language');
        if (!qs('input[name="type"]:checked')) errors.push(i18n.validation_select_type || 'Select flash type');
    }
    if (stepNumber === 2) {
        if (currentType === 'green') {
            const worksite = getEl('sf-worksite-investigation')?.value?.trim();
            const relatedFlash = getEl('sf-related-flash')?.value;
            if (!worksite && !relatedFlash) errors.push(i18n.validation_select_base_or_worksite || 'Select base flash or enter worksite');
        } else {
            const worksite = getEl('sf-worksite')?.value?.trim();
            const eventDate = getEl('sf-date')?.value;
            if (!worksite) errors.push(i18n.validation_select_worksite || 'Select worksite');
            if (!eventDate) {
                errors.push(i18n.validation_enter_event_time || 'Enter event time');
            } else {
                const selectedDate = new Date(eventDate);
                const now = new Date();
                if (selectedDate > now) {
                    errors.push(i18n.validation_time_not_future || 'Event time cannot be in the future');
                }
            }
        }
    }
    if (stepNumber === 3) {
        const title = getEl('sf-title')?.value?.trim();
        const shortText = getEl('sf-short-text')?.value?.trim();
        const description = getEl('sf-description')?.value?.trim();
        if (!title) errors.push(i18n.validation_enter_title || 'Enter internal title');
        if (!shortText) errors.push(i18n.validation_enter_short_desc || 'Enter short description');
        else if (shortText.length > 85) errors.push(i18n.validation_short_desc_too_long || 'Short description is too long (max 125 characters)');
        if (!description) errors.push(i18n.validation_enter_description || 'Enter event description');
        else if (description.length > 950) errors.push(i18n.validation_desc_too_long || 'Description is too long (max 650 characters)');
        if (currentType === 'green') {
            const rootCauses = getEl('sf-root-causes')?.value?.trim();
            const actions = getEl('sf-actions')?.value?.trim();
            if (rootCauses && rootCauses.length > 1500) errors.push(i18n.validation_root_causes_too_long || 'Root causes text is too long (max 1500 characters)');
            if (actions && actions.length > 1500) errors.push(i18n.validation_actions_too_long || 'Actions text is too long (max 1500 characters)');
        }
    }
    if (stepNumber === 4) {
        const fileInput1 = getEl('sf-image1');
        const libraryImage1 = getEl('sfLibraryImage1');
        const imageThumb1 = getEl('sfImageThumb1');
        const legacyPreview1 = getEl('sf-upload-preview1');
        const hasFileUpload = fileInput1?.files?.length > 0;
        const hasLibraryImage = libraryImage1 && libraryImage1.value.trim() !== '';
        const thumbEl = imageThumb1 || legacyPreview1;
        const isPlaceholder = (src) => !src || src.includes('camera-placeholder') || src.endsWith('/');
        const hasExistingImage = thumbEl && thumbEl.src && !isPlaceholder(thumbEl.src);
        if (!hasFileUpload && !hasLibraryImage && !hasExistingImage) {
            errors.push(i18n.validation_image_required || 'Add at least one image');
        }
    }
    return errors;
}

export function showValidationErrors(errors) {
    if (errors.length === 0) return true;
    const i18n = window.SF_I18N || {};
    let errorBox = getEl('sf-validation-errors');
    if (!errorBox) {
        errorBox = document.createElement('div');
        errorBox.id = 'sf-validation-errors';
        errorBox.className = 'sf-validation-errors';
        const activeStep = qs('.sf-step-content.active');
        if (activeStep) activeStep.insertBefore(errorBox, activeStep.firstChild);
    }
    errorBox.innerHTML = `
    <div class="sf-validation-icon">⚠️</div>
    <div class="sf-validation-content">
      <strong>${i18n.validation_fill_missing || 'Fill in missing information:'}</strong>
      <ul>${errors.map(e => `<li>${e}</li>`).join('')}</ul>
    </div>
    <button type="button" class="sf-validation-close" onclick="this.parentElement.remove()">×</button>
  `;
    errorBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return false;
}