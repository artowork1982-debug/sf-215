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
    }

    // ===== INITIALIZE =====
    // Apply filters on page load
    applyClientSideFilters();
    updateClearButtonVisibility();

})();
