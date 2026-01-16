(function () {
    'use strict';

    function getConfig() {
        return {
            baseUrl: window.SF_BASE_URL || '',
            flashData: window.SF_FLASH_DATA || {},
            supportedLangs: window.SF_SUPPORTED_LANGS || {}
        };
    }

    let currentTargetLang = '';

    window.sfAddTranslation = function (el) {
        if (!el) return;

        const config = getConfig();
        currentTargetLang = el.getAttribute('data-lang');
        if (!currentTargetLang) return;

        const langInput = document.getElementById('translationTargetLang');
        if (langInput) langInput.value = currentTargetLang;

        const langData = config.supportedLangs[currentTargetLang];
        const langDisplay = document.getElementById('translationLangDisplay');
        if (langDisplay && langData) {
            langDisplay.innerHTML =
                '<img src="' +
                config.baseUrl +
                '/assets/img/' +
                langData.icon +
                '" alt="' +
                langData.label +
                '">' +
                '<span>' +
                langData.label +
                '</span>';
        }

        const fields = [
            'translationTitleShort',
            'translationDescription',
            'translationRootCauses',
            'translationActions'
        ];

        fields.forEach(function (id) {
            const field = document.getElementById(id);
            if (field) field.value = '';
        });

        updateCharCount('translationTitleShort', 'titleCharCount');
        updateCharCount('translationDescription', 'descCharCount');

        const statusEl = document.getElementById('translationStatus');
        if (statusEl) {
            statusEl.textContent = '';
            statusEl.className = 'sf-translation-status';
        }

        showStep(1);

        const modal = document.getElementById('modalTranslation');
        if (modal) modal.classList.remove('hidden');
    };

    function scalePreviewCard() {
        const container = document.getElementById('sfTranslationPreviewContainer');
        const card = container ? container.querySelector('.sf-preview-card') : null;

        if (!container || !card) return;

        requestAnimationFrame(function () {
            var containerWidth = container.offsetWidth;

            if (containerWidth <= 0) {
                setTimeout(scalePreviewCard, 100);
                return;
            }

            var cardWidth = 1920;
            var cardHeight = 1080;
            var scale = containerWidth / cardWidth;

            card.style.width = cardWidth + 'px';
            card.style.height = cardHeight + 'px';
            card.style.transform = 'scale(' + scale + ')';
            card.style.transformOrigin = 'top left';

            var scaledHeight = Math.round(cardHeight * scale);
            container.style.height = scaledHeight + 'px';
            container.style.overflow = 'hidden';
        });
    }

    function showStep(step) {
        const step1 = document.getElementById('translationStep1');
        const step2 = document.getElementById('translationStep2');

        if (step === 1) {
            if (step1) step1.classList.remove('hidden');
            if (step2) step2.classList.add('hidden');
        } else {
            if (step1) step1.classList.add('hidden');
            if (step2) step2.classList.remove('hidden');

            setTimeout(function () {
                scalePreviewCard();
                updatePreviewFromForm();
            }, 100);
        }
    }

    function updateCharCount(inputId, countId) {
        const input = document.getElementById(inputId);
        const count = document.getElementById(countId);
        if (input && count) {
            count.textContent = input.value.length;
        }
    }

    // Apufunktio HTML-erikoismerkkien escapointiin (XSS-suojaus)
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    // Dynaaminen fonttikoon säätö otsikon pituuden mukaan
    function adjustTitleFontSize(element, textLength) {
        if (!element) return;

        var fontSize;

        if (textLength <= 50) {
            fontSize = 42;  // Oletus
        } else if (textLength <= 80) {
            fontSize = 38;
        } else if (textLength <= 100) {
            fontSize = 34;
        } else if (textLength <= 130) {
            fontSize = 30;
        } else if (textLength <= 160) {
            fontSize = 26;
        } else {
            fontSize = 22;  // Minimi
        }

        element.style.fontSize = fontSize + 'px';
    }

    function updatePreviewFromForm() {
        const config = getConfig();
        const flashData = config.flashData;

        const titleShort = document.getElementById('translationTitleShort');
        const description = document.getElementById('translationDescription');

        var previewTitle = document.getElementById('sfPreviewTitle');
        if (previewTitle && titleShort) {
            var titleText = titleShort.value || '';
            previewTitle.innerHTML = escapeHtml(titleText).replace(/\n/g, '<br>');

            // Dynaaminen fonttikoko otsikon pituuden mukaan
            adjustTitleFontSize(previewTitle, titleText.length);

            // Aseta kieli tavutusta varten
            if (currentTargetLang) {
                previewTitle.setAttribute('lang', currentTargetLang);
            }
        }

        var previewDesc = document.getElementById('sfPreviewDesc');
        if (previewDesc && description) {
            previewDesc.innerHTML = escapeHtml(description.value || '').replace(/\n/g, '<br>');
        }

        var previewSite = document.getElementById('sfPreviewSite');
        if (previewSite && flashData.site) {
            var siteText = flashData.site;
            if (flashData.site_detail) {
                siteText += ' – ' + flashData.site_detail;
            }
            previewSite.textContent = siteText;
        }

        var previewDate = document.getElementById('sfPreviewDate');
        if (previewDate && flashData.occurred_at) {
            var date = new Date(flashData.occurred_at);
            var formatted = date.toLocaleDateString('fi-FI', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            previewDate.textContent = formatted;
        }

        // Päivitä taustakuva valitun kohdekielen mukaan
        var bgImg = document.getElementById('sfPreviewBg');
        if (bgImg && currentTargetLang && flashData.type) {
            var bgUrl = config.baseUrl + '/assets/img/templates/SF_bg_' + flashData.type + '_' + currentTargetLang + '.jpg';
            bgImg.src = bgUrl;
        }

        // Päivitä kortin data-lang attribuutti
        var previewCard = document.getElementById('sfPreviewCard');
        if (previewCard && currentTargetLang) {
            previewCard.dataset.lang = currentTargetLang;
        }

        // Päivitä meta-labelit valitun kielen mukaan
        var metaLabels = window.SF_TRANSLATIONS && window.SF_TRANSLATIONS.metaLabels ? window.SF_TRANSLATIONS.metaLabels : null;
        if (metaLabels) {
            var labels = metaLabels[currentTargetLang] || metaLabels['fi'];
            var metaBoxes = document.querySelectorAll('#sfPreviewWrapperModal .sf-preview-meta-box');
            if (metaBoxes.length >= 2) {
                var siteLabel = metaBoxes[0].querySelector('.sf-preview-meta-label');
                var dateLabel = metaBoxes[1].querySelector('.sf-preview-meta-label');
                if (siteLabel) siteLabel.textContent = labels.site;
                if (dateLabel) dateLabel.textContent = labels.date;
            }
        }

        // Grid-bitmap päivitys - käytä aina originaalin kuvaa
        var gridBitmapFrame = document.getElementById('sfGridBitmapFrame');
        if (gridBitmapFrame) {
            var gridBitmapUrl = flashData.grid_bitmap_url || '';
            var img = gridBitmapFrame.querySelector('img');
            if (img) {
                if (gridBitmapUrl) {
                    img.src = gridBitmapUrl;
                    img.style.display = '';
                } else {
                    // Fallback: yritä image_main_url
                    var fallbackUrl = flashData.image_main_url || '';
                    if (fallbackUrl) {
                        img.src = fallbackUrl;
                        img.style.display = '';
                    }
                }
            }
        }
    }

    function validateForm() {
        const titleShort = document.getElementById('translationTitleShort');
        const description = document.getElementById('translationDescription');

        if (!titleShort || !titleShort.value.trim()) {
            return false;
        }
        if (!description || !description.value.trim()) {
            return false;
        }
        return true;
    }

    // Hae CSRF-token sivulta
    function getCsrfToken() {
        // Yritä ensin hidden input -kentästä
        var csrfInput = document.querySelector('input[name="csrf_token"]');
        if (csrfInput && csrfInput.value) {
            return csrfInput.value;
        }
        // Yritä data-attribuutista
        var csrfEl = document.querySelector('[data-csrf-token]');
        if (csrfEl) {
            return csrfEl.getAttribute('data-csrf-token');
        }
        // Yritä window-muuttujasta
        if (window.SF_CSRF_TOKEN) {
            return window.SF_CSRF_TOKEN;
        }
        return '';
    }

    function saveTranslation() {
        const config = getConfig();
        const statusEl = document.getElementById('translationStatus');
        const saveBtn = document.getElementById('btnSaveTranslation');

        if (!validateForm()) {
            if (statusEl) {
                var messages = window.SF_TRANSLATIONS && window.SF_TRANSLATIONS.messages ? window.SF_TRANSLATIONS.messages : {};
                statusEl.textContent = messages.validationFillRequired || 'Täytä pakolliset kentät';
                statusEl.className = 'sf-translation-status sf-status-error';
            }
            return;
        }

        if (saveBtn) {
            saveBtn.disabled = true;
            var messages = window.SF_TRANSLATIONS && window.SF_TRANSLATIONS.messages ? window.SF_TRANSLATIONS.messages : {};
            saveBtn.textContent = messages.generatingImage || 'Generoidaan kuvaa...';
        }

        var previewCard = document.getElementById('sfPreviewCard');
        if (!previewCard || typeof html2canvas === 'undefined') {
            doSaveTranslation(null);
            return;
        }

        // Kloonaa kortti
        var clone = previewCard.cloneNode(true);
        document.body.appendChild(clone);

        // Aseta klooni TÄYSIKOKOISEKSI (1920x1080) offscreen
        clone.style.cssText =
            'position: fixed ! important;' +
            'left: -99999px !important;' +
            'top: 0 !important;' +
            'width: 1920px !important;' +
            'height: 1080px !important;' +
            'transform: none !important;' +
            'z-index: -1 !important;' +
            'display: block !important;';

        setTimeout(function () {
            html2canvas(clone, {
                scale: 1,
                width: 1920,
                height: 1080,
                useCORS: true,
                allowTaint: true,
                backgroundColor: '#ffffff',
                logging: false,
                imageTimeout: 15000
            }).then(function (canvas) {
                clone.remove();
                var previewDataUrl = canvas.toDataURL('image/jpeg', 0.92);
                doSaveTranslation(previewDataUrl);
            }).catch(function (err) {
                console.error('html2canvas error:', err);
                clone.remove();
                doSaveTranslation(null);
            });
        }, 50);
    }

    function doSaveTranslation(previewDataUrl) {
        const config = getConfig();
        const statusEl = document.getElementById('translationStatus');
        const saveBtn = document.getElementById('btnSaveTranslation');
        var messages = window.SF_TRANSLATIONS && window.SF_TRANSLATIONS.messages ? window.SF_TRANSLATIONS.messages : {};

        if (saveBtn) {
            saveBtn.textContent = messages.saving || 'Tallennetaan...';
        }

        const formData = new FormData();

        formData.append('from_id', config.flashData.id || '');
        formData.append('lang', currentTargetLang);

        var csrfToken = getCsrfToken();
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }

        const titleShort = document.getElementById('translationTitleShort');
        const description = document.getElementById('translationDescription');
        const rootCauses = document.getElementById('translationRootCauses');
        const actions = document.getElementById('translationActions');

        if (titleShort) formData.append('title_short', titleShort.value);
        if (description) formData.append('description', description.value);
        if (rootCauses) formData.append('root_causes', rootCauses.value);
        if (actions) formData.append('actions', actions.value);
        if (titleShort) formData.append('summary', titleShort.value);

        // Lisää preview-kuvan dataURL
        if (previewDataUrl) {
            formData.append('preview_image_data', previewDataUrl);
        }

        fetch(config.baseUrl + '/app/api/save_translation.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (response.redirected) {
                    window.location.href = response.url;
                    return null;
                }

                var contentType = response.headers.get('content-type') || '';

                if (contentType.includes('application/json')) {
                    return response.json();
                } else {
                    return response.text().then(function (text) {
                        if (text.toLowerCase().includes('virhe') || response.status >= 400) {
                            throw new Error(text || 'Tuntematon virhe');
                        }
                        return { ok: true, redirect: true };
                    });
                }
            })
            .then(function (data) {
                if (data === null) return;

                var messages = window.SF_TRANSLATIONS && window.SF_TRANSLATIONS.messages ? window.SF_TRANSLATIONS.messages : {};

                if (data && (data.ok || data.success || data.id)) {
                    if (statusEl) {
                        statusEl.textContent = messages.translationSaved || 'Kieliversio tallennettu!';
                        statusEl.className = 'sf-translation-status sf-status-success';
                    }

                    if (data.id) {
                        setTimeout(function () {
                            window.location.href = config.baseUrl + '/index.php? page=view&id=' + data.id;
                        }, 1000);
                    } else {
                        setTimeout(function () {
                            window.location.reload();
                        }, 1000);
                    }
                } else if (data && data.error) {
                    throw new Error(data.error);
                }
            })
            .catch(function (err) {
                console.error('Translation save error:', err);
                var messages = window.SF_TRANSLATIONS && window.SF_TRANSLATIONS.messages ? window.SF_TRANSLATIONS.messages : {};
                if (statusEl) {
                    var errorPrefix = messages.errorPrefix || 'Virhe:';
                    statusEl.textContent = errorPrefix + ' ' + err.message;
                    statusEl.className = 'sf-translation-status sf-status-error';
                }
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = messages.saveTranslationButton || 'Tallenna kieliversio';
                }
            });
    }

    function init() {
        var btnToStep2 = document.getElementById('btnToStep2');
        if (btnToStep2) {
            btnToStep2.addEventListener('click', function () {
                if (!validateForm()) {
                    var statusEl = document.getElementById('translationStatus');
                    if (statusEl) {
                        var messages = window.SF_TRANSLATIONS && window.SF_TRANSLATIONS.messages ? window.SF_TRANSLATIONS.messages : {};
                        statusEl.textContent = messages.validationFillRequired || 'Täytä pakolliset kentät.';
                        statusEl.className = 'sf-translation-status sf-status-error';
                    }
                    return;
                }
                showStep(2);
            });
        }

        var btnBackToStep1 = document.getElementById('btnBackToStep1');
        if (btnBackToStep1) {
            btnBackToStep1.addEventListener('click', function () {
                showStep(1);
            });
        }

        var btnSaveTranslation = document.getElementById('btnSaveTranslation');
        if (btnSaveTranslation) {
            btnSaveTranslation.addEventListener('click', function () {
                saveTranslation();
            });
        }

        var titleShortInput = document.getElementById('translationTitleShort');
        if (titleShortInput) {
            titleShortInput.addEventListener('input', function () {
                updateCharCount('translationTitleShort', 'titleCharCount');

                // Päivitä preview reaaliajassa jos step 2 on näkyvissä
                var step2 = document.getElementById('translationStep2');
                if (step2 && !step2.classList.contains('hidden')) {
                    var previewTitle = document.getElementById('sfPreviewTitle');
                    if (previewTitle) {
                        var titleText = this.value || '';
                        previewTitle.innerHTML = escapeHtml(titleText).replace(/\n/g, '<br>');
                        adjustTitleFontSize(previewTitle, titleText.length);
                    }
                }
            });
        }

        var descInput = document.getElementById('translationDescription');
        if (descInput) {
            descInput.addEventListener('input', function () {
                updateCharCount('translationDescription', 'descCharCount');
            });
        }

        var closeButtons = document.querySelectorAll('[data-modal-close="modalTranslation"]');
        closeButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modal = document.getElementById('modalTranslation');
                if (modal) modal.classList.add('hidden');
            });
        });

        window.addEventListener('resize', function () {
            var modal = document.getElementById('modalTranslation');
            if (modal && !modal.classList.contains('hidden')) {
                var step2 = document.getElementById('translationStep2');
                if (step2 && !step2.classList.contains('hidden')) {
                    scalePreviewCard();
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();