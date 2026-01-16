// assets/js/modules/preview_tutkinta.js
// Tutkintatiedotteen laajennus PreviewCore:sta

import { PreviewCore } from './preview-core.js';

class PreviewTutkintaClass extends PreviewCore {
    constructor() {
        super({
            idSuffix: 'Green',
            cardId: 'sfPreviewCard',
            gridSelectorId: 'sfGridSelector',
            sliderXId: 'sfPreviewSliderX',
            sliderYId: 'sfPreviewSliderY',
            sliderZoomId: 'sfPreviewSliderZoom',
            slidersPanelId: 'sfSlidersPanel',
            annotationsPanelId: 'sfAnnotationsPanel'
        });

        this.tutkintaIds = {
            card1: 'sfPreviewCardGreen',
            card2: 'sfPreviewCard2Green',
            tabs: 'sfPreviewTabsTutkinta',
            tab2: 'sfPreviewTab2Green',
            bg1: 'sfPreviewBgGreen',
            bg2: 'sfPreviewBg2Green',
            title1: 'sfPreviewTitleGreen',
            title2: 'sfPreviewTitle2Green',
            desc: 'sfPreviewDescGreen',
            site1: 'sfPreviewSiteGreen',
            site2: 'sfPreviewSite2Green',
            date1: 'sfPreviewDateGreen',
            date2: 'sfPreviewDate2Green',
            rootCauses: 'sfPreviewRootCausesGreen',
            rootCausesCard1: 'sfPreviewRootCausesCard1Green',
            actions: 'sfPreviewActionsGreen',
            actionsCard1: 'sfPreviewActionsCard1Green'
        };

        this.activeCard = 1;
        this._tutkintaEventsBound = false;

        this.LIMITS = {
            shortText: 85,
            descSingleSlide: 300,
            descTwoSlides: 650,
            rootCausesSingleSlide: 150,
            actionsSingleSlide: 150,
            rootCausesTwoSlides: 800,
            actionsTwoSlides: 800,
            lineBreakCost: 30
        };

        this.SINGLE_SLIDE_TOTAL_LIMIT = 700;
    }

    init() {
        if (this.initialized) {
            console.log('PreviewTutkinta already initialized');
            return this;
        }

        const card = document.getElementById(this.tutkintaIds.card1);
        if (!card) {
            console.warn('PreviewTutkinta init: Card not found');
            return this;
        }

        super.init();

        if (!this._tutkintaEventsBound) {
            this._initTabs();
            this._bindFormEvents();
            this._tutkintaEventsBound = true;
        }

        this.updatePreviewContent();

        console.log('PreviewTutkinta initialized');
        return this;
    }

    _initTabs() {
        const tabsWrapper = document.getElementById(this.tutkintaIds.tabs);
        if (!tabsWrapper) return;

        const buttons = tabsWrapper.querySelectorAll('.sf-preview-tab-btn');
        const self = this;

        buttons.forEach(btn => {
            if (btn.dataset.tutkintaTabBound) return;
            btn.dataset.tutkintaTabBound = '1';

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                self._switchCard(this.dataset.target, buttons);
            });
        });

        this._switchCard(this.tutkintaIds.card1, buttons);
    }

    _switchCard(targetId, buttons) {
        const card1 = document.getElementById(this.tutkintaIds.card1);
        const card2 = document.getElementById(this.tutkintaIds.card2);
        const showCard1 = !targetId || targetId === this.tutkintaIds.card1;

        if (card1) {
            card1.style.display = showCard1 ? 'block' : 'none';
        }
        if (card2) {
            card2.style.display = showCard1 ? 'none' : 'block';
        }

        this.activeCard = showCard1 ? 1 : 2;

        if (buttons) {
            buttons.forEach(btn => {
                const isActive =
                    btn.dataset.target === (showCard1 ? this.tutkintaIds.card1 : this.tutkintaIds.card2);
                btn.classList.toggle('sf-preview-tab-active', isActive);
            });
        }

        this._toggleTools(showCard1);

        if (showCard1) {
            this.applyGridClass();
            this._syncSlidersToState();
        }
    }
    _toggleTools(show) {
        const gridSelector = document.getElementById(this.ids.gridSelector);
        const toolsTabs = document.querySelector('.sf-tools-tabs.sf-green-card1-only');
        const toolsPanels = document.querySelectorAll('.sf-tools-panel.sf-green-card1-only');
        const slidersPanel = document.getElementById(this.ids.slidersPanel);
        const annotationsPanel = document.getElementById(this.ids.annotationsPanel);

        if (gridSelector) gridSelector.style.display = show ? '' : 'none';
        if (toolsTabs) toolsTabs.style.display = show ? '' : 'none';

        toolsPanels.forEach(p => {
            if (!show) {
                p.style.display = 'none';
            } else if (p.classList.contains('active')) {
                p.style.display = 'block';
            }
        });

        if (slidersPanel) slidersPanel.style.display = show ? '' : 'none';
        if (annotationsPanel) annotationsPanel.style.display = show ? '' : 'none';
    }

    _bindFormEvents() {
        const self = this;
        const fields = [
            'sf-short-text', 'sf-description', 'sf-worksite',
            'sf-site-detail', 'sf-date', 'sf-root-causes', 'sf-actions'
        ];

        fields.forEach(id => {
            const el = document.getElementById(id);
            if (el && !el.dataset.tutkintaInputBound) {
                el.dataset.tutkintaInputBound = '1';
                el.addEventListener('input', () => self.updatePreviewContent());
            }
        });
    }

    _calculateTextLength(text) {
        if (!text) return 0;
        const lineBreaks = (text.match(/\n/g) || []).length;
        return text.length + (lineBreaks * this.LIMITS.lineBreakCost);
    }

    _shouldUseTwoSlides(title, desc, rootCauses, actions) {
        const hasRootCauses = (rootCauses || '').trim().length > 0;
        const hasActions = (actions || '').trim().length > 0;

        if (!hasRootCauses && !hasActions) {
            return false;
        }

        const titleLen = this._calculateTextLength(title);
        const descLen = this._calculateTextLength(desc);
        const rootLen = this._calculateTextLength(rootCauses);
        const actionsLen = this._calculateTextLength(actions);

        const totalLen = titleLen + descLen + rootLen + actionsLen;

        if (totalLen > this.SINGLE_SLIDE_TOTAL_LIMIT) return true;
        if (rootLen > this.LIMITS.rootCausesSingleSlide) return true;
        if (actionsLen > this.LIMITS.actionsSingleSlide) return true;
        if (descLen > this.LIMITS.descSingleSlide) return true;

        return false;
    }
    _updateTwoSlidesNotice(show) {
        const notice = document.getElementById('sfTwoSlidesNotice');
        if (notice) {
            notice.style.display = show ? 'flex' : 'none';
        }
    }
    updatePreviewContent() {
        const title = document.getElementById('sf-short-text')?.value || '';
        const desc = document.getElementById('sf-description')?.value || '';
        const site = document.getElementById('sf-worksite')?.value || '';
        const siteDetail = document.getElementById('sf-site-detail')?.value || '';
        const siteText = [site, siteDetail].filter(Boolean).join(' – ');
        const rootCauses = document.getElementById('sf-root-causes')?.value || '';
        const actions = document.getElementById('sf-actions')?.value || '';

        const formattedDate = this._formatDate();

        const useTwoSlides = this._shouldUseTwoSlides(title, desc, rootCauses, actions);
        const hasRootOrActions = (rootCauses.trim().length > 0) || (actions.trim().length > 0);

        const tab2 = document.getElementById(this.tutkintaIds.tab2);
        if (tab2) tab2.style.display = useTwoSlides ? '' : 'none';

        this._setMultiline(this.tutkintaIds.title1, title, 'Lyhyt kuvaus tapahtumasta');
        this._setMultiline(this.tutkintaIds.desc, desc, 'Tarkempi kuvaus');
        this._setMultiline(this.tutkintaIds.site1, siteText, '–');
        this._setMultiline(this.tutkintaIds.date1, formattedDate, '–');

        // Kortti 1:n juurisyyt/toimenpiteet rivi
        const rootActionsRow = document.getElementById('sfRootActionsCard1Green');
        const rootCausesCard1 = document.getElementById('sfPreviewRootCausesCard1Green');
        const actionsCard1 = document.getElementById('sfPreviewActionsCard1Green');

        if (useTwoSlides) {
            // Piilota kortti 1:n juurisyyt, näytä kortti 2:lla
            if (rootActionsRow) rootActionsRow.style.display = 'none';
        } else {
            // Näytä kortti 1:llä jos on sisältöä
            if (rootActionsRow) {
                rootActionsRow.style.display = hasRootOrActions ? 'grid' : 'none';
            }
            if (rootCausesCard1) {
                rootCausesCard1.innerHTML = this._formatBulletList(rootCauses);
            }
            if (actionsCard1) {
                actionsCard1.innerHTML = this._formatBulletList(actions);
            }
        }

        if (useTwoSlides) {
            this._setMultiline(this.tutkintaIds.title2, title, 'Kuvaus');
            this._setMultiline(this.tutkintaIds.site2, siteText, '–');
            this._setMultiline(this.tutkintaIds.date2, formattedDate, '–');

            const rootEl = document.getElementById(this.tutkintaIds.rootCauses);
            if (rootEl) rootEl.innerHTML = this._formatBulletList(rootCauses);

            const actionsEl = document.getElementById(this.tutkintaIds.actions);
            if (actionsEl) actionsEl.innerHTML = this._formatBulletList(actions);
        }

        this._updateTwoSlidesNotice(useTwoSlides);
        this._updateBackgroundImages(useTwoSlides);
        this.applyGridClass();
    }

    _formatDate() {
        const dateEl = document.getElementById('sf-date');
        if (!dateEl?.value) return '–';

        const d = new Date(dateEl.value);
        if (isNaN(d.getTime())) return '–';

        const pad = n => (n < 10 ? '0' + n : '' + n);
        return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    _escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    _setMultiline(id, text, fallback) {
        const el = document.getElementById(id);
        if (el) {
            const value = (text?.trim()) ? text : (fallback || '–');
            el.innerHTML = this._escapeHtml(value).replace(/\n/g, '<br>');
        }
    }

    _formatBulletList(text) {
        if (!text || !text.trim()) return '–';

        const lines = text.split('\n');
        const result = [];

        for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed) continue;

            // Tarkista alkaako rivi bullet-merkillä
            const bulletMatch = trimmed.match(/^[-•·*]\s*(.+)$/);
            if (bulletMatch) {
                result.push(
                    '<div class="sf-bullet-line">' +
                    '<span class="sf-bullet">•</span>' +
                    '<span class="sf-bullet-text">' + this._escapeHtml(bulletMatch[1]) + '</span>' +
                    '</div>'
                );
            } else {
                result.push('<div>' + this._escapeHtml(trimmed) + '</div>');
            }
        }

        return result.join('');
    }

    _updateBackgroundImages(hasTwoCards) {
        const card1 = document.getElementById(this.tutkintaIds.card1);
        if (!card1) return;

        const lang = card1.dataset.lang || 'fi';
        const base = card1.dataset.baseUrl || '';

        const bg1 = document.getElementById(this.tutkintaIds.bg1);
        const bg2 = document.getElementById(this.tutkintaIds.bg2);

        if (hasTwoCards) {
            if (bg1) bg1.src = `${base}/assets/img/templates/SF_bg_green_1_${lang}.jpg`;
            if (bg2) bg2.src = `${base}/assets/img/templates/SF_bg_green_2_${lang}.jpg`;
        } else {
            if (bg1) bg1.src = `${base}/assets/img/templates/SF_bg_green_${lang}.jpg`;
        }

        card1.dataset.hasCard2 = hasTwoCards ? '1' : '0';
    }
}

export const PreviewTutkinta = new PreviewTutkintaClass();

if (typeof window !== 'undefined') {
    window.PreviewTutkinta = PreviewTutkinta;
}

export default PreviewTutkinta;