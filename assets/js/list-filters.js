// assets/js/list-filters.js
// Client-side filtering for list page with chip-based UI

(function () {
    'use strict';

    // ===== GET FILTER ELEMENTS =====
    // Hidden form elements (used for storing values)
    const filterType = document.getElementById('f-type');
    const filterState = document.getElementById('f-state');
    const filterSite = document.getElementById('f-site');
    const filterSearch = document.getElementById('f-q');
    const filterDateFrom = document.getElementById('f-from');
    const filterDateTo = document.getElementById('f-to');
    const filterArchived = document.getElementById('f-archived');

    // New chip-based elements
    const searchInput = document.getElementById('sf-search-input');
    const clearAllBtn = document.getElementById('sf-clear-all-btn');
    const resetAllBtn = document.getElementById('sf-reset-all-btn');
    const searchBtn = document.getElementById('sf-search-btn');
    const chips = document.querySelectorAll('.sf-chip');
    const toggleBtns = document.querySelectorAll('.sf-toggle-btn');

    // Check if we're on the list page - need at least one filter element
    if (!filterType && !filterState && !filterSite && !searchInput) {
        return; // Not on list page, exit
    }

    // Flag to prevent infinite loops during bidirectional search sync
    let isSyncingSearch = false;

    // ===== SYNC SEARCH INPUTS =====
    // Bidirectional sync between sf-search-input and f-q
    if (searchInput && filterSearch) {
        searchInput.addEventListener('input', function () {
            if (!isSyncingSearch) {
                isSyncingSearch = true;
                filterSearch.value = this.value;
                applyClientSideFilters();
                isSyncingSearch = false;
            }
        });

        filterSearch.addEventListener('input', function () {
            if (!isSyncingSearch) {
                isSyncingSearch = true;
                searchInput.value = this.value;
                applyClientSideFilters();
                isSyncingSearch = false;
            }
        });

        // Handle Enter key on search input
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyClientSideFilters();
            }
        });
    }

    // ===== SEARCH BUTTON =====
    if (searchBtn) {
        searchBtn.addEventListener('click', function (e) {
            e.preventDefault();
            applyClientSideFilters();
        });
    }

    // ===== ARCHIVED TOGGLE (SEGMENTED CONTROL) =====
    toggleBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const value = this.dataset.archivedValue;

            // If already active, do nothing
            if (this.classList.contains('active')) {
                return;
            }

            // Update UI
            toggleBtns.forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-pressed', 'false');
            });
            this.classList.add('active');
            this.setAttribute('aria-pressed', 'true');

            // Update hidden field
            if (filterArchived) {
                filterArchived.value = value;
            }

            // Apply client-side filter
            applyClientSideFilters();
            updateClearButtonVisibility();
        });
    });

    // ===== CHIP CLICK HANDLERS =====
    chips.forEach(chip => {
        chip.addEventListener('click', function (e) {
            const filterName = this.dataset.filter;

            // Toggle dropdown
            const wasOpen = this.classList.contains('open');

            // Close all dropdowns first
            chips.forEach(c => c.classList.remove('open'));

            if (!wasOpen) {
                this.classList.add('open');
                renderDropdown(this, filterName);
            }
        });
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.sf-chip') && !e.target.closest('.sf-chip-dropdown')) {
            chips.forEach(c => c.classList.remove('open'));
        }
    });

    // ===== RENDER DROPDOWN =====
    function renderDropdown(chip, filterName) {
        // Remove old dropdown
        const oldDropdown = chip.querySelector('.sf-chip-dropdown');
        if (oldDropdown) oldDropdown.remove();

        const dropdown = document.createElement('div');
        dropdown.className = 'sf-chip-dropdown';

        // ===== DATE FILTER - Special handling =====
        if (filterName === 'date') {
            renderDateDropdown(chip, dropdown);
            chip.appendChild(dropdown);
            return;
        }

        let options = [];
        let currentValue = '';
        let sourceElement = null;

        if (filterName === 'type' && filterType) {
            sourceElement = filterType;
            currentValue = filterType.value;
        } else if (filterName === 'state' && filterState) {
            sourceElement = filterState;
            currentValue = filterState.value;
        } else if (filterName === 'site' && filterSite) {
            sourceElement = filterSite;
            currentValue = filterSite.value;
        }

        if (sourceElement) {
            options = Array.from(sourceElement.options).map(opt => ({
                value: opt.value,
                label: opt.textContent
            }));
        }

        options.forEach(opt => {
            const optEl = document.createElement('div');
            optEl.className = 'sf-chip-dropdown-option' + (opt.value === currentValue ? ' selected' : '');

            const radio = document.createElement('span');
            radio.className = 'sf-chip-dropdown-radio';

            const label = document.createElement('span');
            label.className = 'sf-chip-dropdown-label';
            label.textContent = opt.label;

            optEl.appendChild(radio);
            optEl.appendChild(label);

            optEl.addEventListener('click', function (e) {
                e.stopPropagation();

                // Update hidden field
                if (sourceElement) {
                    sourceElement.value = opt.value;
                }

                // Update chip display
                const chipLabel = chip.querySelector('.chip-label');
                if (chipLabel) {
                    chipLabel.textContent = opt.label;
                }

                // Update chip active state
                if (opt.value) {
                    chip.classList.add('active');
                } else {
                    chip.classList.remove('active');
                }

                // Close dropdown
                chip.classList.remove('open');

                // Apply filter
                applyClientSideFilters();
                updateClearButtonVisibility();
            });

            dropdown.appendChild(optEl);
        });

        chip.appendChild(dropdown);
    }

    // ===== CLIENT-SIDE FILTERING =====
    function applyClientSideFilters() {
        const typeVal = filterType ? filterType.value : '';
        const stateVal = filterState ? filterState.value : '';
        const siteVal = filterSite ? filterSite.value : '';
        const searchVal = (searchInput ? searchInput.value : (filterSearch ? filterSearch.value : '')).toLowerCase().trim();
        const dateFromVal = filterDateFrom ? filterDateFrom.value : '';
        const dateToVal = filterDateTo ? filterDateTo.value : '';
        const archivedVal = filterArchived ? filterArchived.value : '';

        const cards = document.querySelectorAll('.card');
        let visibleCount = 0;

        cards.forEach(function (card) {
            let show = true;

            // Type filter
            if (typeVal && card.dataset.type !== typeVal) {
                show = false;
            }

            // State filter
            if (stateVal && card.dataset.state !== stateVal) {
                show = false;
            }

            // Site filter
            if (siteVal && card.dataset.site !== siteVal) {
                show = false;
            }

            // Search filter (title)
            if (searchVal && !(card.dataset.title || '').toLowerCase().includes(searchVal)) {
                show = false;
            }

            // Date from filter - only apply if card has a date
            if (dateFromVal && card.dataset.date) {
                if (card.dataset.date < dateFromVal) {
                    show = false;
                }
            }

            // Date to filter - only apply if card has a date
            if (dateToVal && card.dataset.date) {
                if (card.dataset.date > dateToVal) {
                    show = false;
                }
            }

            // Archived filter
            const cardArchivedValue = card.dataset.archived || '0';
            if (archivedVal === '' && cardArchivedValue === '1') {
                show = false;
            } else if (archivedVal === 'only' && cardArchivedValue !== '1') {
                show = false;
            }

            // Apply visibility
            if (show) {
                card.style.removeProperty('display');
                visibleCount++;
            } else {
                card.style.setProperty('display', 'none', 'important');
            }
        });

        // Update "no results" message
        updateNoResultsMessage(visibleCount);

        // Update URL without reloading
        updateListUrl();
    }

    // ===== UPDATE URL =====
    function updateListUrl() {
        const params = new URLSearchParams(window.location.search);

        // Preserve page parameter
        if (!params.has('page')) {
            params.set('page', 'list');
        }

        const typeVal = filterType ? filterType.value : '';
        const stateVal = filterState ? filterState.value : '';
        const siteVal = filterSite ? filterSite.value : '';
        const searchVal = (searchInput ? searchInput.value : (filterSearch ? filterSearch.value : '')).trim();
        const dateFromVal = filterDateFrom ? filterDateFrom.value : '';
        const dateToVal = filterDateTo ? filterDateTo.value : '';
        const archivedVal = filterArchived ? filterArchived.value : '';

        if (typeVal) params.set('type', typeVal); else params.delete('type');
        if (stateVal) params.set('state', stateVal); else params.delete('state');
        if (siteVal) params.set('site', siteVal); else params.delete('site');
        if (searchVal) params.set('q', searchVal); else params.delete('q');
        if (dateFromVal) params.set('date_from', dateFromVal); else params.delete('date_from');
        if (dateToVal) params.set('date_to', dateToVal); else params.delete('date_to');
        if (archivedVal) params.set('archived', archivedVal); else params.delete('archived');

        const newUrl = window.location.pathname + '?' + params.toString();
        window.history.replaceState({}, '', newUrl);
    }

    // ===== NO RESULTS MESSAGE =====
    function updateNoResultsMessage(visibleCount) {
        const cardList = document.querySelector('.card-list');
        if (!cardList) return;

        let noResultsBox = cardList.querySelector('.js-filter-no-results');

        if (visibleCount === 0) {
            if (!noResultsBox) {
                noResultsBox = document.createElement('div');
                noResultsBox.className = 'no-results-box js-filter-no-results';

                // Create elements safely without innerHTML
                const iconWrap = document.createElement('div');
                iconWrap.className = 'no-results-icon-wrap';
                iconWrap.innerHTML = `
                    <svg class="no-results-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        <line x1="8" y1="11" x2="14" y2="11"/>
                    </svg>
                `;

                const textPara = document.createElement('p');
                textPara.className = 'no-results-text';
                textPara.textContent = window.SF_LIST_I18N?.filterNoResults || 'Ei tuloksia';

                const hintPara = document.createElement('p');
                hintPara.className = 'no-results-hint';
                hintPara.textContent = window.SF_LIST_I18N?.noResultsHint || 'Kokeile muuttaa suodattimia';

                noResultsBox.appendChild(iconWrap);
                noResultsBox.appendChild(textPara);
                noResultsBox.appendChild(hintPara);
                cardList.appendChild(noResultsBox);
            } else {
                noResultsBox.style.display = '';
            }
        } else {
            if (noResultsBox) {
                noResultsBox.style.display = 'none';
            }
        }
    }

    // ===== CLEAR/RESET BUTTONS =====
    function updateClearButtonVisibility() {
        const hasFilters =
            (filterType && filterType.value !== '') ||
            (filterState && filterState.value !== '') ||
            (filterSite && filterSite.value !== '') ||
            (searchInput && searchInput.value !== '') ||
            (filterSearch && filterSearch.value !== '') ||
            (filterDateFrom && filterDateFrom.value !== '') ||
            (filterDateTo && filterDateTo.value !== '');

        if (clearAllBtn) {
            clearAllBtn.classList.toggle('hidden', !hasFilters);
        }
        if (resetAllBtn) {
            resetAllBtn.classList.toggle('hidden', !hasFilters);
        }
    }

    // ===== UPDATE RESULTS BAR =====
    function updateResultsBar() {
        const allCards = document.querySelectorAll('.card');
        const visibleCards = document.querySelectorAll('.card:not([style*="display: none"])');
        
        let resultsBar = document.querySelector('.sf-results-bar');
        
        const hasFilters = 
            (filterType && filterType.value !== '') ||
            (filterState && filterState.value !== '') ||
            (filterSite && filterSite.value !== '') ||
            (searchInput && searchInput.value !== '') ||
            (filterDateFrom && filterDateFrom.value !== '') ||
            (filterDateTo && filterDateTo.value !== '');
        
        if (hasFilters) {
            if (!resultsBar) {
                resultsBar = document.createElement('div');
                resultsBar.className = 'sf-results-bar';
                const cardList = document.querySelector('.card-list');
                if (cardList && cardList.parentNode) {
                    cardList.parentNode.insertBefore(resultsBar, cardList);
                }
            }
            
            const i18n = window.SF_LIST_I18N || {};
            const countText = (i18n.filterResultsCount || 'Näytetään {visible} / {total} tulosta')
                .replace('{visible}', visibleCards.length)
                .replace('{total}', allCards.length);
            
            resultsBar.innerHTML = '<span class="sf-results-count">' + countText + '</span>';
            resultsBar.style.display = '';
        } else {
            if (resultsBar) {
                resultsBar.style.display = 'none';
            }
        }
    }

    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', function () {
            clearAllFilters();
        });
    }

    if (resetAllBtn) {
        resetAllBtn.addEventListener('click', function (e) {
            e.preventDefault();
            clearAllFilters();
        });
    }

    function clearAllFilters() {
        if (filterType) filterType.value = '';
        if (filterState) filterState.value = '';
        if (filterSite) filterSite.value = '';
        if (filterSearch) filterSearch.value = '';
        if (searchInput) searchInput.value = '';
        if (filterDateFrom) filterDateFrom.value = '';
        if (filterDateTo) filterDateTo.value = '';
        if (filterArchived) filterArchived.value = '';

        // Reset toggle buttons
        toggleBtns.forEach(btn => {
            btn.classList.remove('active');
            btn.setAttribute('aria-pressed', 'false');
            if (btn.dataset.archivedValue === '') {
                btn.classList.add('active');
                btn.setAttribute('aria-pressed', 'true');
            }
        });

        // Reset chip labels
        chips.forEach(chip => {
            chip.classList.remove('active');
            const chipLabel = chip.querySelector('.chip-label');
            const filterName = chip.dataset.filter;
            if (chipLabel && window.SF_LIST_I18N) {
                if (filterName === 'type') chipLabel.textContent = window.SF_LIST_I18N.filterChipTypeAll || 'Kaikki tyypit';
                if (filterName === 'state') chipLabel.textContent = window.SF_LIST_I18N.filterChipStateAll || 'Kaikki tilat';
                if (filterName === 'site') chipLabel.textContent = window.SF_LIST_I18N.filterChipSiteAll || 'Kaikki työmaat';
                if (filterName === 'date') chipLabel.textContent = window.SF_LIST_I18N.filterDate || 'Päivämäärä';
            }
        });

        applyClientSideFilters();
        updateClearButtonVisibility();
        updateResultsBar();
    }

    // ===== DATE DROPDOWN =====
    function renderDateDropdown(chip, dropdown) {
        dropdown.classList.add('sf-chip-dropdown-date');
        
        const i18n = window.SF_LIST_I18N || {};
        
        // === QUICK PRESETS ===
        const presetsSection = document.createElement('div');
        presetsSection.className = 'sf-date-section';
        
        const presetsTitle = document.createElement('div');
        presetsTitle.className = 'sf-date-section-title';
        presetsTitle.textContent = i18n.dateTimespanHeader || 'Pikavalinta';
        presetsSection.appendChild(presetsTitle);
        
        const presetsGrid = document.createElement('div');
        presetsGrid.className = 'sf-date-presets';
        
        const today = new Date();
        const presets = [
            { 
                label: i18n.datePreset7days || '7 pv', 
                shortLabel: i18n.datePreset7daysShort || '7 pv',
                from: daysAgo(7), 
                to: formatDateISO(today) 
            },
            { 
                label: i18n.datePreset30days || '30 pv', 
                shortLabel: i18n.datePreset30daysShort || '30 pv',
                from: daysAgo(30), 
                to: formatDateISO(today) 
            },
            { 
                label: i18n.datePresetMonth || 'Tämä kk', 
                shortLabel: i18n.datePresetMonthShort || getMonthShortName(today.getMonth()),
                from: firstDayOfMonth(today), 
                to: lastDayOfMonth(today) 
            },
            { 
                label: i18n.datePresetYear || String(today.getFullYear()), 
                shortLabel: String(today.getFullYear()),
                from: today.getFullYear() + '-01-01', 
                to: today.getFullYear() + '-12-31' 
            },
            { 
                label: i18n.datePresetAll || 'Kaikki', 
                shortLabel: i18n.filterDate || 'Päivämäärä',
                from: '', 
                to: '' 
            }
        ];
        
        presets.forEach(preset => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'sf-date-preset-btn';
            btn.textContent = preset.label;
            
            // Check if this preset is currently active
            const currentFrom = filterDateFrom ? filterDateFrom.value : '';
            const currentTo = filterDateTo ? filterDateTo.value : '';
            if (currentFrom === preset.from && currentTo === preset.to) {
                btn.classList.add('active');
            }
            
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                applyDateFilter(chip, preset.from, preset.to, preset.shortLabel);
            });
            
            presetsGrid.appendChild(btn);
        });
        
        presetsSection.appendChild(presetsGrid);
        dropdown.appendChild(presetsSection);
        
        // === MONTH PICKER ===
        const monthSection = document.createElement('div');
        monthSection.className = 'sf-date-section';
        
        const monthTitle = document.createElement('div');
        monthTitle.className = 'sf-date-section-title';
        monthTitle.textContent = i18n.dateMonthHeader || 'Kuukausi';
        monthSection.appendChild(monthTitle);
        
        let selectedYear = today.getFullYear();
        
        const yearNav = document.createElement('div');
        yearNav.className = 'sf-date-year-nav';
        
        const prevYearBtn = document.createElement('button');
        prevYearBtn.type = 'button';
        prevYearBtn.className = 'sf-date-year-btn';
        prevYearBtn.innerHTML = '◄';
        prevYearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            selectedYear--;
            yearLabel.textContent = selectedYear;
            updateMonthButtons();
        });
        
        const yearLabel = document.createElement('span');
        yearLabel.className = 'sf-date-year-label';
        yearLabel.textContent = selectedYear;
        
        const nextYearBtn = document.createElement('button');
        nextYearBtn.type = 'button';
        nextYearBtn.className = 'sf-date-year-btn';
        nextYearBtn.innerHTML = '►';
        nextYearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            selectedYear++;
            yearLabel.textContent = selectedYear;
            updateMonthButtons();
        });
        
        yearNav.appendChild(prevYearBtn);
        yearNav.appendChild(yearLabel);
        yearNav.appendChild(nextYearBtn);
        monthSection.appendChild(yearNav);
        
        const monthGrid = document.createElement('div');
        monthGrid.className = 'sf-date-month-grid';
        
        const monthNames = i18n.monthNamesShort || ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'];
        
        function updateMonthButtons() {
            monthGrid.innerHTML = '';
            for (let m = 0; m < 12; m++) {
                const monthBtn = document.createElement('button');
                monthBtn.type = 'button';
                monthBtn.className = 'sf-date-month-btn';
                monthBtn.textContent = monthNames[m];
                
                const monthFrom = formatDateISO(new Date(selectedYear, m, 1));
                const monthTo = lastDayOfMonthByYearMonth(selectedYear, m);
                
                // Check if this month is currently selected
                const currentFrom = filterDateFrom ? filterDateFrom.value : '';
                const currentTo = filterDateTo ? filterDateTo.value : '';
                if (currentFrom === monthFrom && currentTo === monthTo) {
                    monthBtn.classList.add('active');
                }
                
                // Disable future months - compare year and month only
                if (selectedYear > today.getFullYear() || (selectedYear === today.getFullYear() && m > today.getMonth())) {
                    monthBtn.disabled = true;
                    monthBtn.classList.add('disabled');
                }
                
                monthBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const shortLabel = monthNames[m] + ' ' + selectedYear;
                    applyDateFilter(chip, monthFrom, monthTo, shortLabel);
                });
                
                monthGrid.appendChild(monthBtn);
            }
        }
        
        updateMonthButtons();
        monthSection.appendChild(monthGrid);
        dropdown.appendChild(monthSection);
        
        // === CUSTOM DATE RANGE ===
        const customSection = document.createElement('div');
        customSection.className = 'sf-date-section';
        
        const customTitle = document.createElement('div');
        customTitle.className = 'sf-date-section-title';
        customTitle.textContent = i18n.datePresetCustom || 'Oma aikaväli';
        customSection.appendChild(customTitle);
        
        const customRow = document.createElement('div');
        customRow.className = 'sf-date-custom-row';
        
        const fromInput = document.createElement('input');
        fromInput.type = 'date';
        fromInput.className = 'sf-date-input';
        fromInput.value = filterDateFrom ? filterDateFrom.value : '';
        fromInput.placeholder = i18n.filterDateFrom || 'Alkaen';
        
        const separator = document.createElement('span');
        separator.className = 'sf-date-separator';
        separator.textContent = '–';
        
        const toInput = document.createElement('input');
        toInput.type = 'date';
        toInput.className = 'sf-date-input';
        toInput.value = filterDateTo ? filterDateTo.value : '';
        toInput.placeholder = i18n.filterDateTo || 'Asti';
        
        customRow.appendChild(fromInput);
        customRow.appendChild(separator);
        customRow.appendChild(toInput);
        customSection.appendChild(customRow);
        
        const customBtnRow = document.createElement('div');
        customBtnRow.className = 'sf-date-custom-btn-row';
        
        const applyBtn = document.createElement('button');
        applyBtn.type = 'button';
        applyBtn.className = 'sf-date-apply-btn';
        applyBtn.textContent = i18n.filterApply || 'Suodata';
        applyBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            let shortLabel = '';
            if (fromInput.value && toInput.value) {
                shortLabel = formatDateShort(fromInput.value) + ' - ' + formatDateShort(toInput.value);
            } else if (fromInput.value) {
                shortLabel = formatDateShort(fromInput.value) + ' →';
            } else if (toInput.value) {
                shortLabel = '→ ' + formatDateShort(toInput.value);
            } else {
                shortLabel = i18n.filterDate || 'Päivämäärä';
            }
            applyDateFilter(chip, fromInput.value, toInput.value, shortLabel);
        });
        
        customBtnRow.appendChild(applyBtn);
        customSection.appendChild(customBtnRow);
        dropdown.appendChild(customSection);
    }

    // ===== APPLY DATE FILTER =====
    function applyDateFilter(chip, fromVal, toVal, chipLabel) {
        // Update hidden fields
        if (filterDateFrom) filterDateFrom.value = fromVal;
        if (filterDateTo) filterDateTo.value = toVal;
        
        // Update chip display
        const chipLabelEl = chip.querySelector('.chip-label');
        if (chipLabelEl) {
            chipLabelEl.textContent = chipLabel;
        }
        
        // Update chip active state
        if (fromVal || toVal) {
            chip.classList.add('active');
        } else {
            chip.classList.remove('active');
        }
        
        // Close dropdown
        chip.classList.remove('open');
        
        // Apply filter with animation
        applyClientSideFiltersWithAnimation();
        updateClearButtonVisibility();
        updateResultsBar();
    }

    // ===== APPLY FILTERS WITH ANIMATION =====
    function applyClientSideFiltersWithAnimation() {
        const cards = document.querySelectorAll('.card');
        
        // First, mark cards that will be hidden
        cards.forEach(card => {
            const shouldShow = shouldCardBeVisible(card);
            if (!shouldShow && card.style.display !== 'none') {
                card.classList.add('sf-filtering-out');
            }
        });
        
        // After animation, actually hide/show
        setTimeout(() => {
            applyClientSideFilters();
            
            // Add appear animation to newly visible cards
            cards.forEach(card => {
                card.classList.remove('sf-filtering-out');
                if (card.style.display !== 'none') {
                    card.classList.add('sf-filtering-in');
                    setTimeout(() => card.classList.remove('sf-filtering-in'), 300);
                }
            });
        }, 150);
    }

    // ===== HELPER FUNCTIONS =====
    function formatDateISO(date) {
        return date.getFullYear() + '-' + 
               String(date.getMonth() + 1).padStart(2, '0') + '-' + 
               String(date.getDate()).padStart(2, '0');
    }

    function formatDateShort(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length === 3) {
            return parts[2] + '.' + parts[1] + '.';
        }
        return dateStr;
    }

    function daysAgo(days) {
        const d = new Date();
        d.setDate(d.getDate() - days);
        return formatDateISO(d);
    }

    function firstDayOfMonth(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-01';
    }

    function lastDayOfMonth(date) {
        const lastDay = new Date(date.getFullYear(), date.getMonth() + 1, 0);
        return formatDateISO(lastDay);
    }

    function lastDayOfMonthByYearMonth(year, month) {
        const lastDay = new Date(year, month + 1, 0);
        return formatDateISO(lastDay);
    }

    function getMonthShortName(monthIndex) {
        const i18n = window.SF_LIST_I18N || {};
        // Use English month names as fallback for language neutrality
        const names = i18n.monthNamesShort || ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                       'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return names[monthIndex] || '';
    }

    function shouldCardBeVisible(card) {
        const typeVal = filterType ? filterType.value : '';
        const stateVal = filterState ? filterState.value : '';
        const siteVal = filterSite ? filterSite.value : '';
        const searchVal = (searchInput ? searchInput.value : '').toLowerCase().trim();
        const dateFromVal = filterDateFrom ? filterDateFrom.value : '';
        const dateToVal = filterDateTo ? filterDateTo.value : '';
        const archivedVal = filterArchived ? filterArchived.value : '';
        
        if (typeVal && card.dataset.type !== typeVal) return false;
        if (stateVal && card.dataset.state !== stateVal) return false;
        if (siteVal && card.dataset.site !== siteVal) return false;
        if (searchVal && !(card.dataset.title || '').toLowerCase().includes(searchVal)) return false;
        if (dateFromVal && card.dataset.date && card.dataset.date < dateFromVal) return false;
        if (dateToVal && card.dataset.date && card.dataset.date > dateToVal) return false;
        
        const cardArchivedValue = card.dataset.archived || '0';
        if (archivedVal === '' && cardArchivedValue === '1') return false;
        if (archivedVal === 'only' && cardArchivedValue !== '1') return false;
        
        return true;
    }

    // ===== INITIALIZE =====
    // Apply filters on page load
    applyClientSideFilters();
    updateClearButtonVisibility();
    updateResultsBar();

})();
