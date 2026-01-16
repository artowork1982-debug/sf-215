/**
 * Safetyflash - View-sivun yleiset toiminnot
 * Modal helpers, log toggles, avatars, footer buttons
 */
(function () {
    'use strict';

    // Utilities
    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

    // Modal helpers
    function openModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');
        var focusable = el.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable) focusable.focus();
    }

    function closeModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.add('hidden');
        var anyOpen = document.querySelector('.sf-modal:not(.hidden)');
        if (!anyOpen) {
            document.body.classList.remove('sf-modal-open');
        }
    }
    // Log "Näytä lisää" toggles
    function attachLogMoreHandlers() {
        var showMore = window.SF_LOG_SHOW_MORE || 'Näytä lisää';
        var showLess = window.SF_LOG_SHOW_LESS || 'Näytä vähemmän';

        qsa('.sf-log-more').forEach(function (btn) {
            if (btn._sf_attached) return;
            btn.addEventListener('click', function () {
                var item = this.closest('.sf-log-item');
                if (!item) return;
                var msg = item.querySelector('.sf-log-message');
                if (!msg) return;
                var expanded = msg.classList.toggle('expanded');
                this.textContent = expanded ? showLess : showMore;
                this.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            });
            btn._sf_attached = true;
        });
    }

    // Avatar initials
    function initAvatars() {
        qsa('.sf-log-avatar[data-name]').forEach(function (el) {
            if (el.textContent.trim() !== '') return;
            var name = el.getAttribute('data-name') || '';
            var initials = name.split(/\s+/).map(function (s) { return s.charAt(0); }).join('').substring(0, 2).toUpperCase();
            el.textContent = initials || 'SF';
        });
    }

    // Footer buttons -> modals
    function attachFooterActions() {
        var footerMap = [
            { id: 'footerEdit', modal: 'modalEdit' },
            { id: 'footerRequest', modal: 'modalRequestInfo' },
            { id: 'footerComms', modal: 'modalToComms' },
            { id: 'footerPublish', modal: 'modalPublish' },
            { id: 'footerDelete', modal: 'modalDelete' },
            { id: 'footerComment', modal: 'modalComment' },
            { id: 'footerArchive', modal: 'modalArchive' }
        ];

        footerMap.forEach(function (mapping) {
            var el = document.getElementById(mapping.id);
            if (!el || el._sf_attached) return;
            el.addEventListener('click', function () {
                openModal(mapping.modal);
            });
            el._sf_attached = true;
        });

        // Edit OK button (normaali muokkaus)
        var modalEditOk = document.getElementById('modalEditOk');
        if (modalEditOk && !modalEditOk._sf_attached) {
            modalEditOk.addEventListener('click', function () {
                if (window.SF_EDIT_URL) {
                    window.location.href = window.SF_EDIT_URL;
                }
            });
            modalEditOk._sf_attached = true;
        }

        // Viestinnän/Turvatiimin inline-muokkaus (ei muuta tilaa)
        var footerEditInline = document.getElementById('footerEditInline');
        if (footerEditInline && !footerEditInline._sf_attached) {
            footerEditInline.addEventListener('click', function () {
                // Vie form-sivulle inline-muokkaustilassa
                if (window.SF_EDIT_URL) {
                    var editUrl = window.SF_EDIT_URL + '&mode=inline';
                    window.location.href = editUrl;
                }
            });
            footerEditInline._sf_attached = true;
        }
    }

    // Modal close buttons
    function attachModalCloseButtons() {
        qsa('[data-modal-close]').forEach(function (btn) {
            if (btn._sf_attached) return;
            btn.addEventListener('click', function () {
                var target = this.getAttribute('data-modal-close');
                if (target) closeModal(target);
            });
            btn._sf_attached = true;
        });
    }

    // UUSI JA YKSINKERTAISTETTU KOODI
    function attachDownloadPreviewHandlers() {
        document.addEventListener('click', function (e) {
            // Etsi latauslinkki, jota klikattiin
            const downloadLink = e.target.closest('a.btn-download-preview[download]');
            if (!downloadLink) {
                return;
            }

            // AINOASTAAN estetään tapahtuman kupliminen globaalille skriptille,
            // jotta lataus-overlay ei aktivoidu.
            // Annetaan selaimen oman, normaalin lataustoiminnon hoitaa loput.
            e.stopPropagation();
        });
    }
    // Footer keyboard support
    function attachFooterKeyboardSupport() {
        qsa('.footer-btn').forEach(function (btn) {
            if (btn._sf_keyboardAttached) return;
            btn.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
            btn._sf_keyboardAttached = true;
        });
    }

    // Init
    function init() {
        attachLogMoreHandlers();
        initAvatars();
        attachFooterActions();
        attachModalCloseButtons();
        attachFooterKeyboardSupport();
        attachDownloadPreviewHandlers();

        // Varmista että kaikki modaalit ovat piilossa sivun latautuessa
        qsa('.sf-modal').forEach(function (modal) {
            if (!modal.classList.contains('hidden')) {
                modal.classList.add('hidden');
            }
        });
        // Varmista että body ei ole modal-open tilassa
        document.body.classList.remove('sf-modal-open');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for debugging
    window._sf = window._sf || {};
    window._sf.openModal = openModal;
    window._sf.closeModal = closeModal;

    // Modaalin preview-kortin skaalaus
    function scaleModalPreview() {
        var container = document.getElementById('sfTranslationPreviewContainer');
        if (!container) return;

        var card = container.querySelector('.sf-preview-card');
        if (!card) return;

        var containerWidth = container.offsetWidth;
        if (containerWidth <= 0) return;

        var scale = containerWidth / 1920;
        card.style.transform = 'scale(' + scale + ')';
    }

    // Kutsu kun modaali avataan (muokattu openModal-funktio)
    var originalOpenModal = openModal;
    openModal = function (id) {
        originalOpenModal(id);
        if (id === 'modalTranslation') {
            // Pieni viive että modaali ehtii renderöityä
            setTimeout(scaleModalPreview, 50);
        }
    };

    // Resize-kuuntelija modaalille
    window.addEventListener('resize', function () {
        var modal = document.querySelector('#modalTranslation:not(.hidden)');
        if (modal) scaleModalPreview();
    });

    // Expose
    window._sf.scaleModalPreview = scaleModalPreview;
})();

// ===== TUTKINTATIEDOTTEEN PREVIEW-VÄLILEHDET =====
(function () {
    'use strict';

    function initViewPreviewTabs() {
        var tabsContainer = document.getElementById('sfViewPreviewTabs');
        if (!tabsContainer) return;

        var buttons = tabsContainer.querySelectorAll('.sf-view-tab-btn');
        var preview1 = document.getElementById('viewPreview1');
        var preview2 = document.getElementById('viewPreview2');

        if (!preview1 || !preview2) return;

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = this.getAttribute('data-target');

                // Päivitä nappien tila
                buttons.forEach(function (b) {
                    b.classList.remove('active');
                });
                this.classList.add('active');

                // Näytä oikea kortti
                if (target === 'preview1') {
                    preview1.style.display = '';
                    preview1.classList.add('active');
                    preview2.style.display = 'none';
                    preview2.classList.remove('active');
                } else if (target === 'preview2') {
                    preview1.style.display = 'none';
                    preview1.classList.remove('active');
                    preview2.style.display = '';
                    preview2.classList.add('active');
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initViewPreviewTabs);
    } else {
        initViewPreviewTabs();
    }
})();
// Archive functionality
(function () {
    function initArchive() {
        var archiveConfirmBtn = document.getElementById('modalArchiveConfirm');
        if (!archiveConfirmBtn) return;

        // Prevent duplicate event listener
        if (archiveConfirmBtn._sf_attached) return;
        archiveConfirmBtn._sf_attached = true;

        archiveConfirmBtn.addEventListener('click', function () {
            var flashId = window.SF_FLASH_DATA ? window.SF_FLASH_DATA.id : null;
            var csrfToken = window.SF_CSRF_TOKEN;

            if (!flashId || !csrfToken) {
                alert('Error: Missing required data');
                return;
            }

            // Disable button during request
            archiveConfirmBtn.disabled = true;
            archiveConfirmBtn.textContent = window.SF_ARCHIVING_TEXT || 'Archiving...';

            // Create form data
            var formData = new FormData();
            formData.append('flash_id', flashId);
            formData.append('csrf_token', csrfToken);

            // Send archive request
            fetch(window.SF_BASE_URL + '/app/api/archive_flash.php', {
                method: 'POST',
                body: formData
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.success) {
                        // Redirect with success notice
                        var currentUrl = new URL(window.location.href);
                        currentUrl.searchParams.set('notice', 'archived');
                        window.location.href = currentUrl.toString();
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                        archiveConfirmBtn.disabled = false;
                        archiveConfirmBtn.textContent = window.SF_ARCHIVE_BTN_TEXT || 'Archive';
                    }
                })
                .catch(function (error) {
                    console.error('Archive error:', error);
                    alert('Error: Failed to archive SafetyFlash');
                    archiveConfirmBtn.disabled = false;
                    archiveConfirmBtn.textContent = window.SF_ARCHIVE_BTN_TEXT || 'Archive';
                });
        });  // <-- LISÄTTY:  Sulkee addEventListener callback
    }  // <-- LISÄTTY:  Sulkee initArchive funktion

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initArchive);
    } else {
        initArchive();
    }
})();