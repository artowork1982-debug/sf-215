// assets/js/list-filters.js
// Client-side filtering for list page with Filter Chips + Bottom Sheet

(function () {
    'use strict';

    // Constants
    const MOBILE_BOTTOM_SHEET_CLOSE_DELAY = 300; // ms - delay before closing bottom sheet to show selection
    const DEBUG_DATE_FILTER = false; // Set to true to enable debug logging
    const DEBUG_FILTERS = false; // Set to true to enable filter debug logging

    // ===== HELPER FUNCTIONS =====

    /**
     * Check if a card should be shown based on date filtering
     * @param {string} cardDate - The card's date in YYYY-MM-DD format (may be empty)
     * @param {string} dateFromVal - Filter start date in YYYY-MM-DD format (may be empty)
     * @param {string} dateToVal - Filter end date in YYYY-MM-DD format (may be empty)
     * @returns {boolean} - True if card should be shown, false if it should be hidden
     */
    function shouldShowCardWithDateFilter(cardDate, dateFromVal, dateToVal) {
        // If no date filter is active, show the card
        if (!dateFromVal && !dateToVal) {
            return true;
        }

        // If date filter is active but card has no date, hide it
        if (!cardDate) {
            return false;
        }

        // Check if card date is before start date
        if (dateFromVal && cardDate < dateFromVal) {
            return false;
        }

        // Check if card date is after end date
        if (dateToVal && cardDate > dateToVal) {
            return false;
        }

        // Card passes all date filters
        return true;
    }

    // Get filter elements
    const filterType = document.getElementById('f-type');
    const filterState = document.getElementById('f-state');
    const filterSite = document.getElementById('f-site');
    const filterSearch = document.getElementById('f-q');
    const filterDateFrom = document.getElementById('f-from');
    const filterDateTo = document.getElementById('f-to');
    const filterArchived = document.getElementById('f-archived');
    const filtersForm = document.querySelector('.filters');
    const submitBtn = document.getElementById('filter-submit-btn');
    const clearBtn = document.getElementById('filter-clear-btn');

    // New elements for the chip-based filtering
    const searchInput = document.getElementById('sf-search-input');
    const clearAllBtn = document.getElementById('sf-clear-all-btn');

    // Check if we're on the list page
    if (!filterType || !filterState || !filterSite || !filterSearch || !filterDateFrom || !filterDateTo || !filterArchived) {
        return; // Not on list page, exit
    }

    // Hide the submit button since filtering is now real-time
    if (submitBtn) {
        submitBtn.style.display = 'none';
    }

    // ===== HELPER FUNCTIONS =====




    // ===== ARCHIVED TOGGLE (SEGMENTED CONTROL) =====
    const toggleBtns = document.querySelectorAll('.sf-toggle-btn');
    toggleBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const value = this.dataset.archivedValue;

            // If already active, do nothing
            if (this.classList.contains('active')) {
                return;
            }

            // Reload page with archived parameter
            // This is needed because SQL query filters archived items server-side
            const url = new URL(window.location.href);

            // Preserve other filters
            // No value (empty string) = show active only (default), remove parameter
            // Value 'only' = show archived only, Value 'all' = show both
            if (!value) {
                url.searchParams.delete('archived');
            } else {
                url.searchParams.set('archived', value);
            }

            // Reload page
            window.location.href = url.toString();
        });
    });

    // ===== BOTTOM SHEET =====
    const bottomSheet = document.getElementById('sfBottomSheet');
    const bottomSheetBackdrop = document.getElementById('sfBottomSheetBackdrop');
    const bottomSheetContent = document.getElementById('sfBottomSheetContent');
    const bottomSheetTitle = document.getElementById('sfBottomSheetTitle');
    const bottomSheetBody = document.getElementById('sfBottomSheetBody');
    const bottomSheetDone = document.getElementById('sfBottomSheetDone');
    const bottomSheetClear = document.getElementById('sfBottomSheetClear');

    let currentFilterType = null;
    let touchStartY = 0;
    let touchCurrentY = 0;

    function openBottomSheet(filterName, options) {
        if (window.innerWidth > 768) return; // Desktop: don't show bottom sheet

        currentFilterType = filterName;
        bottomSheetTitle.textContent = options.title;
        bottomSheetBody.textContent = ''; // Clear safely

        // Create options
        options.items.forEach(item => {
            const optionEl = document.createElement('div');
            optionEl.className = 'sf-bottom-sheet-option';
            if (item.selected) {
                optionEl.classList.add('selected');
            }

            // Create elements safely without innerHTML to prevent XSS
            const labelDiv = document.createElement('div');
            labelDiv.className = 'sf-bottom-sheet-option-label';

            const radioDiv = document.createElement('div');
            radioDiv.className = 'sf-bottom-sheet-option-radio';

            const labelSpan = document.createElement('span');
            labelSpan.textContent = item.label; // Safe - uses textContent

            labelDiv.appendChild(radioDiv);
            labelDiv.appendChild(labelSpan);
            optionEl.appendChild(labelDiv);

            if (item.count !== undefined) {
                const countSpan = document.createElement('span');
                countSpan.className = 'sf-bottom-sheet-option-count';
                countSpan.textContent = item.count;
                optionEl.appendChild(countSpan);
            }

            optionEl.addEventListener('click', () => {
                // Update selection
                bottomSheetBody.querySelectorAll('.sf-bottom-sheet-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                optionEl.classList.add('selected');

                // Update filter value
                if (currentFilterType === 'type') {
                    filterType.value = item.value;
                } else if (currentFilterType === 'state') {
                    filterState.value = item.value;
                } else if (currentFilterType === 'site') {
                    filterSite.value = item.value;
                }

                // Auto-close bottom sheet after selection with delay
                setTimeout(() => {
                    closeBottomSheet();
                    applyListFilters();
                }, MOBILE_BOTTOM_SHEET_CLOSE_DELAY);
            });

            bottomSheetBody.appendChild(optionEl);
        });

        // Show bottom sheet
        bottomSheet.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeBottomSheet() {
        bottomSheet.classList.remove('open');
        document.body.style.overflow = '';
        currentFilterType = null;
    }

    // Bottom sheet event listeners
    if (bottomSheetBackdrop) {
        bottomSheetBackdrop.addEventListener('click', closeBottomSheet);
    }

    if (bottomSheetDone) {
        bottomSheetDone.addEventListener('click', () => {
            closeBottomSheet();
            applyListFilters();
        });
    }

    if (bottomSheetClear) {
        bottomSheetClear.addEventListener('click', () => {
            if (currentFilterType === 'type') {
                filterType.value = '';
            } else if (currentFilterType === 'state') {
                filterState.value = '';
            } else if (currentFilterType === 'site') {
                filterSite.value = '';
            }
            closeBottomSheet();
            applyListFilters();
        });
    }

    // Touch gestures for bottom sheet
    if (bottomSheetContent) {
        bottomSheetContent.addEventListener('touchstart', (e) => {
            if (e.touches && e.touches.length > 0) {
                touchStartY = e.touches[0].clientY;
            }
        });

        bottomSheetContent.addEventListener('touchmove', (e) => {
            if (e.touches && e.touches.length > 0) {
                touchCurrentY = e.touches[0].clientY;
                const diff = touchCurrentY - touchStartY;

                if (diff > 0) {
                    bottomSheetContent.style.transform = `translateY(${diff}px)`;
                }
            }
        });

        bottomSheetContent.addEventListener('touchend', () => {
            const diff = touchCurrentY - touchStartY;

            if (diff > 100) {
                closeBottomSheet();
            }

            bottomSheetContent.style.transform = '';
            touchStartY = 0;
            touchCurrentY = 0;
        });
    }

    // ===== FILTER CHIPS =====
    const chips = document.querySelectorAll('.sf-chip');

    chips.forEach(chip => {
        chip.addEventListener('click', function () {
            const filterName = this.dataset.filter;

            if (window.innerWidth <= 768) {
                // Mobile: open bottom sheet
                let options = { title: '', items: [] };
                const i18n = window.SF_LIST_I18N || {};

                if (filterName === 'type') {
                    options.title = filterType.previousElementSibling?.textContent || i18n.filterType || 'Type';
                    const allTypesOption = filterType.querySelector('option[value=""]');
                    options.items = [
                        { value: '', label: allTypesOption?.textContent || 'All types', selected: filterType.value === '' },
                        { value: 'red', label: document.querySelector('#f-type option[value="red"]')?.textContent || i18n.typeRed || 'Red', selected: filterType.value === 'red' },
                        { value: 'yellow', label: document.querySelector('#f-type option[value="yellow"]')?.textContent || i18n.typeYellow || 'Yellow', selected: filterType.value === 'yellow' },
                        { value: 'green', label: document.querySelector('#f-type option[value="green"]')?.textContent || i18n.typeGreen || 'Green', selected: filterType.value === 'green' }
                    ];
                } else if (filterName === 'state') {
                    options.title = filterState.previousElementSibling?.textContent || i18n.filterState || 'State';
                    const stateOptions = Array.from(filterState.options);
                    options.items = stateOptions.map(opt => ({
                        value: opt.value,
                        label: opt.textContent,
                        selected: filterState.value === opt.value
                    }));
                } else if (filterName === 'site') {
                    options.title = filterSite.previousElementSibling?.textContent || i18n.filterSite || 'Site';
                    const siteOptions = Array.from(filterSite.options);
                    options.items = siteOptions.map(opt => ({
                        value: opt.value,
                        label: opt.textContent,
                        selected: filterSite.value === opt.value
                    }));
                } else if (filterName === 'date') {
                    options.title = i18n.filterDate || 'Date Range';
                    options.isDatePicker = true;
                    openDateBottomSheet(options);
                    return;
                }

                // Calculate counts for each option
                options.items.forEach(item => {
                    item.count = calculateResultCount(filterName, item.value);
                });

                openBottomSheet(filterName, options);
            } else {
                // Desktop: Toggle dropdown
                if (filterName === 'date') {
                    // Date filter: open dropdown with date inputs
                    openDateDropdown(this);
                } else {
                    // Other filters: open dropdown
                    const wasOpen = this.classList.contains('open');

                    // Close all dropdowns
                    document.querySelectorAll('.sf-chip.open').forEach(c => {
                        c.classList.remove('open');
                    });

                    if (!wasOpen) {
                        this.classList.add('open');
                        renderDropdown(this, filterName);
                    }
                }
            }
        });
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function (e) {
        // Don't close dropdown if clicking inside the chip or its dropdown options
        if (!e.target.closest('.sf-chip') && !e.target.closest('.sf-chip-dropdown')) {
            document.querySelectorAll('.sf-chip.open').forEach(c => {
                c.classList.remove('open');
            });
        }
    });

    // ===== DESKTOP DROPDOWN RENDERING =====
    function renderDropdown(chip, filterName) {
        // Remove old dropdown
        const oldDropdown = chip.querySelector('.sf-chip-dropdown');
        if (oldDropdown) oldDropdown.remove();

        const dropdown = document.createElement('div');
        dropdown.className = 'sf-chip-dropdown';

        let options = [];
        let currentValue = '';

        if (filterName === 'type') {
            currentValue = filterType.value;
            const typeOptions = Array.from(filterType.options);
            options = typeOptions.map(opt => ({
                value: opt.value,
                label: opt.textContent,
                count: calculateResultCount(filterName, opt.value)
            }));
        } else if (filterName === 'state') {
            currentValue = filterState.value;
            const stateOptions = Array.from(filterState.options);
            options = stateOptions.map(opt => ({
                value: opt.value,
                label: opt.textContent,
                count: calculateResultCount(filterName, opt.value)
            }));
        } else if (filterName === 'site') {
            currentValue = filterSite.value;
            const siteOptions = Array.from(filterSite.options);
            options = siteOptions.map(opt => ({
                value: opt.value,
                label: opt.textContent,
                count: calculateResultCount(filterName, opt.value)
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

            const count = document.createElement('span');
            count.className = 'sf-chip-dropdown-count';
            count.textContent = opt.count;

            optEl.appendChild(radio);
            optEl.appendChild(label);
            optEl.appendChild(count);

            optEl.addEventListener('click', (e) => {
                e.stopPropagation();

                // Set value - EMPTY when "All"
                if (filterName === 'type') {
                    filterType.value = opt.value; // '' when All
                } else if (filterName === 'state') {
                    filterState.value = opt.value;
                } else if (filterName === 'site') {
                    filterSite.value = opt.value;
                }

                // Close dropdown
                chip.classList.remove('open');

                // Update chip display BEFORE filtering
                updateChipsDisplay();

                // Apply filters (page will reload)
                applyListFilters();
            });

            dropdown.appendChild(optEl);
        });

        chip.appendChild(dropdown);
    }

    // ===== DATE PRESETS CONFIGURATION =====
    function getDatePresets() {
        const i18n = window.SF_LIST_I18N || {};

        return [
            {
                value: 'all',
                label: i18n.datePresetAll || 'Kaikki ajat',
                labelShort: i18n.filterDate || 'Päivämäärä',
                getRange: () => ({ from: '', to: '' })
            },
            {
                value: '7days',
                label: i18n.datePreset7days || 'Viimeiset 7 päivää',
                labelShort: i18n.datePreset7daysShort || 'Viim. 7 pv',
                getRange: () => {
                    const to = new Date();
                    const from = new Date();
                    from.setDate(from.getDate() - 6); // Today + 6 days ago = 7 days total
                    return {
                        from: formatDateForInput(from),
                        to: formatDateForInput(to)
                    };
                }
            },
            {
                value: '30days',
                label: i18n.datePreset30days || 'Viimeiset 30 päivää',
                labelShort: i18n.datePreset30daysShort || 'Viim. 30 pv',
                getRange: () => {
                    const to = new Date();
                    const from = new Date();
                    from.setDate(from.getDate() - 29); // Today + 29 days ago = 30 days total
                    return {
                        from: formatDateForInput(from),
                        to: formatDateForInput(to)
                    };
                }
            },
            {
                value: 'month',
                label: i18n.datePresetMonth || 'Tämä kuukausi',
                labelShort: i18n.datePresetMonthShort || 'Tämä kk',
                getRange: () => {
                    const now = new Date();
                    const from = new Date(now.getFullYear(), now.getMonth(), 1);
                    const to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                    return {
                        from: formatDateForInput(from),
                        to: formatDateForInput(to)
                    };
                }
            },
            {
                value: 'custom',
                label: i18n.datePresetCustom || 'Mukautettu aikaväli...',
                labelShort: null,
                getRange: () => null
            }
        ];
    }

    function formatDateForInput(date) {
        return date.toISOString().split('T')[0]; // YYYY-MM-DD
    }

    function formatDateDisplay(dateStr) {
        if (!dateStr) return '';
        const [y, m, d] = dateStr.split('-');
        return `${d}.${m}`; // DD.MM
    }

    function getCurrentDatePreset() {
        const from = filterDateFrom.value;
        const to = filterDateTo.value;

        if (!from && !to) return 'all';

        const presets = getDatePresets();
        for (const preset of presets) {
            if (preset.value === 'all' || preset.value === 'custom') continue;
            const range = preset.getRange();
            if (range && range.from === from && range.to === to) {
                return preset.value;
            }
        }

        return 'custom';
    }

    // ===== DATE DROPDOWN (DESKTOP) =====
    function openDateDropdown(chip) {
        const i18n = window.SF_LIST_I18N || {};

        // Remove old dropdown
        const oldDropdown = chip.querySelector('.sf-chip-dropdown');
        if (oldDropdown) oldDropdown.remove();

        const dropdown = document.createElement('div');
        dropdown.className = 'sf-chip-dropdown sf-date-dropdown';

        // Header
        const header = document.createElement('div');
        header.className = 'sf-dropdown-header';
        header.textContent = i18n.dateTimespanHeader || '📅 Aikaväli';
        dropdown.appendChild(header);

        // Date presets
        const presets = getDatePresets();
        const currentPreset = getCurrentDatePreset();

        presets.forEach(preset => {
            const option = document.createElement('div');
            option.className = 'sf-chip-dropdown-option';

            if (preset.value === currentPreset) {
                option.classList.add('selected');
            }

            const radio = document.createElement('span');
            radio.className = 'sf-chip-dropdown-radio';

            const label = document.createElement('span');
            label.className = 'sf-chip-dropdown-label';
            label.textContent = preset.label;

            option.appendChild(radio);
            option.appendChild(label);

            // Calculate and show count
            if (preset.value !== 'custom') {
                const range = preset.getRange ? preset.getRange() : null;
                if (range) {
                    const count = calculateDateResultCount(range.from, range.to);
                    const countSpan = document.createElement('span');
                    countSpan.className = 'sf-chip-dropdown-count';
                    countSpan.textContent = count;
                    option.appendChild(countSpan);
                }
            }

            option.addEventListener('click', (e) => {
                e.stopPropagation();

                if (preset.value === 'custom') {
                    // Show custom date fields
                    showCustomDateFields(dropdown, chip);
                    return;
                }

                // Set date range
                const range = preset.getRange();
                filterDateFrom.value = range.from;
                filterDateTo.value = range.to;

                // Update chip label
                updateDateChipLabel(chip, preset);

                // Close dropdown
                chip.classList.remove('open');

                // Apply filters (page will reload)
                applyListFilters();
            });

            dropdown.appendChild(option);
        });

        // Custom date fields container (hidden initially)
        const customFields = document.createElement('div');
        customFields.className = 'sf-date-custom-fields';
        customFields.style.display = 'none';

        const customRow = document.createElement('div');
        customRow.className = 'sf-date-custom-row';

        // From field
        const fromField = document.createElement('div');
        fromField.className = 'sf-date-field';
        const fromLabel = document.createElement('label');
        fromLabel.textContent = i18n.filterDateFrom || 'Alkaen';
        const fromInput = document.createElement('input');
        fromInput.type = 'date';
        fromInput.id = 'sfDateFromDesktop';
        fromInput.value = filterDateFrom.value;
        fromInput.addEventListener('change', () => {
            filterDateFrom.value = fromInput.value;
            updateCustomDateChipLabel(chip);
            applyListFilters();
        });
        fromField.appendChild(fromLabel);
        fromField.appendChild(fromInput);

        // Arrow
        const arrow = document.createElement('span');
        arrow.className = 'sf-date-arrow';
        arrow.textContent = '→';

        // To field
        const toField = document.createElement('div');
        toField.className = 'sf-date-field';
        const toLabel = document.createElement('label');
        toLabel.textContent = i18n.filterDateTo || 'Päättyen';
        const toInput = document.createElement('input');
        toInput.type = 'date';
        toInput.id = 'sfDateToDesktop';
        toInput.value = filterDateTo.value;
        toInput.addEventListener('change', () => {
            filterDateTo.value = toInput.value;
            updateCustomDateChipLabel(chip);
            applyListFilters();
        });
        toField.appendChild(toLabel);
        toField.appendChild(toInput);

        customRow.appendChild(fromField);
        customRow.appendChild(arrow);
        customRow.appendChild(toField);
        customFields.appendChild(customRow);

        // Prevent dropdown close when clicking in custom fields
        customFields.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        dropdown.appendChild(customFields);

        // Clear button
        const footer = document.createElement('div');
        footer.className = 'sf-dropdown-footer';
        const clearLink = document.createElement('a');
        clearLink.href = '#';
        clearLink.className = 'sf-date-clear';
        clearLink.textContent = i18n.dateClear || 'Tyhjennä';
        clearLink.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();

            filterDateFrom.value = '';
            filterDateTo.value = '';

            const allPreset = getDatePresets()[0];
            updateDateChipLabel(chip, allPreset);
            chip.classList.remove('open');
            applyListFilters();
        });
        footer.appendChild(clearLink);
        dropdown.appendChild(footer);

        chip.appendChild(dropdown);
        chip.classList.add('open');
    }

    function showCustomDateFields(dropdown, chip) {
        const customFields = dropdown.querySelector('.sf-date-custom-fields');
        if (customFields) {
            customFields.style.display = 'block';

            // Focus first input
            const firstInput = customFields.querySelector('input');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 100);
            }
        }

        // Mark "Custom" as selected
        dropdown.querySelectorAll('.sf-chip-dropdown-option').forEach(opt => {
            opt.classList.remove('selected');
        });
        const options = dropdown.querySelectorAll('.sf-chip-dropdown-option');
        const customOption = options[options.length - 1]; // Last option is custom
        if (customOption) {
            customOption.classList.add('selected');
        }
    }

    function updateDateChipLabel(chip, preset) {
        const chipLabel = chip.querySelector('.chip-label');

        if (preset.value === 'all' || !preset.labelShort) {
            chip.classList.remove('active');
            chipLabel.textContent = preset.labelShort || (window.SF_LIST_I18N?.filterDate || 'Päivämäärä');
        } else {
            chip.classList.add('active');
            chipLabel.textContent = preset.labelShort;
        }
    }

    function updateCustomDateChipLabel(chip) {
        const chipLabel = chip.querySelector('.chip-label');
        const from = filterDateFrom.value;
        const to = filterDateTo.value;

        if (from || to) {
            chip.classList.add('active');
            const fromDisplay = formatDateDisplay(from) || '...';
            const toDisplay = formatDateDisplay(to) || '...';
            chipLabel.textContent = `${fromDisplay} - ${toDisplay}`;
        } else {
            chip.classList.remove('active');
            chipLabel.textContent = window.SF_LIST_I18N?.filterDate || 'Päivämäärä';
        }
    }

    function calculateDateResultCount(from, to) {
        // Simplified count based on currently loaded cards
        // May not reflect server-side filtering accurately after page reload
        const cards = document.querySelectorAll('.card');
        let count = 0;

        cards.forEach(card => {
            const cardDate = card.dataset.date;
            if (shouldShowCardWithDateFilter(cardDate, from, to)) {
                count++;
            }
        });

        return count;
    }

    // ===== DATE BOTTOM SHEET (MOBILE) =====
    function openDateBottomSheet(options) {
        const i18n = window.SF_LIST_I18N || {};
        currentFilterType = 'date';
        bottomSheetTitle.textContent = i18n.dateTimespanHeader || '📅 Aikaväli';
        bottomSheetBody.textContent = '';

        // Get date chip for updating label
        const dateChip = document.querySelector('.sf-chip[data-filter="date"]');

        // Date presets
        const presets = getDatePresets();
        const currentPreset = getCurrentDatePreset();

        presets.forEach(preset => {
            if (preset.value === 'custom') return; // Skip custom in mobile, will show inputs below

            const option = document.createElement('div');
            option.className = 'sf-bottom-sheet-option';

            if (preset.value === currentPreset) {
                option.classList.add('selected');
            }

            const labelDiv = document.createElement('div');
            labelDiv.className = 'sf-bottom-sheet-option-label';

            const radioDiv = document.createElement('div');
            radioDiv.className = 'sf-bottom-sheet-option-radio';

            const labelSpan = document.createElement('span');
            labelSpan.textContent = preset.label;

            labelDiv.appendChild(radioDiv);
            labelDiv.appendChild(labelSpan);
            option.appendChild(labelDiv);

            // Calculate and show count
            const range = preset.getRange ? preset.getRange() : null;
            if (range) {
                const count = calculateDateResultCount(range.from, range.to);
                const countSpan = document.createElement('span');
                countSpan.className = 'sf-bottom-sheet-option-count';
                countSpan.textContent = count;
                option.appendChild(countSpan);
            }

            option.addEventListener('click', () => {
                // Update selection
                bottomSheetBody.querySelectorAll('.sf-bottom-sheet-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                option.classList.add('selected');

                // Set date range
                const range = preset.getRange();
                filterDateFrom.value = range.from;
                filterDateTo.value = range.to;

                // Update chip label
                if (dateChip) {
                    updateDateChipLabel(dateChip, preset);
                }

                // Close and apply (page will reload)
                setTimeout(() => {
                    closeBottomSheet();
                    applyListFilters();
                }, MOBILE_BOTTOM_SHEET_CLOSE_DELAY);
            });

            bottomSheetBody.appendChild(option);
        });

        // Custom date range section
        const customSection = document.createElement('div');
        customSection.className = 'sf-date-custom-section';

        const customHeader = document.createElement('div');
        customHeader.className = 'sf-date-custom-header';
        customHeader.textContent = i18n.datePresetCustom || 'Mukautettu aikaväli...';
        customSection.appendChild(customHeader);

        const dateInputs = document.createElement('div');
        dateInputs.className = 'sf-date-inputs';

        // From date
        const fromGroup = document.createElement('div');
        fromGroup.className = 'sf-date-input-group';
        const fromLabel = document.createElement('label');
        fromLabel.textContent = i18n.filterDateFrom || 'Alkaen';
        const fromInput = document.createElement('input');
        fromInput.type = 'date';
        fromInput.value = filterDateFrom.value;
        fromInput.id = 'sf-date-from-mobile';
        fromInput.addEventListener('change', () => {
            filterDateFrom.value = fromInput.value;
            if (dateChip) {
                updateCustomDateChipLabel(dateChip);
            }
            applyListFilters();
        });
        fromGroup.appendChild(fromLabel);
        fromGroup.appendChild(fromInput);

        // To date
        const toGroup = document.createElement('div');
        toGroup.className = 'sf-date-input-group';
        const toLabel = document.createElement('label');
        toLabel.textContent = i18n.filterDateTo || 'Päättyen';
        const toInput = document.createElement('input');
        toInput.type = 'date';
        toInput.value = filterDateTo.value;
        toInput.id = 'sf-date-to-mobile';
        toInput.addEventListener('change', () => {
            filterDateTo.value = toInput.value;
            if (dateChip) {
                updateCustomDateChipLabel(dateChip);
            }
            applyListFilters();
        });
        toGroup.appendChild(toLabel);
        toGroup.appendChild(toInput);

        dateInputs.appendChild(fromGroup);
        dateInputs.appendChild(toGroup);
        customSection.appendChild(dateInputs);
        bottomSheetBody.appendChild(customSection);

        // Show bottom sheet
        bottomSheet.classList.add('open');
        document.body.style.overflow = 'hidden';

        // Update done button handler
        bottomSheetDone.onclick = () => {
            closeBottomSheet();
            // Filters are applied on change, no need to reload here
        };

        // Update clear button handler
        bottomSheetClear.onclick = () => {
            filterDateFrom.value = '';
            filterDateTo.value = '';
            fromInput.value = '';
            toInput.value = '';

            if (dateChip) {
                const allPreset = getDatePresets()[0];
                updateDateChipLabel(dateChip, allPreset);
            }

            closeBottomSheet();
            applyListFilters();
        };
    }

    // ===== SEARCH INPUT (HEADER) =====
    // Flag to prevent infinite loops during bidirectional sync
    let isSyncingSearch = false;

    if (searchInput) {
        // Sync initial values
        if (filterSearch.value && !searchInput.value) {
            searchInput.value = filterSearch.value;
        } else if (searchInput.value && !filterSearch.value) {
            filterSearch.value = searchInput.value;
        }

        // Bidirectional sync: sf-search-input -> f-q
        searchInput.addEventListener('input', function () {
            if (!isSyncingSearch) {
                isSyncingSearch = true;
                try {
                    filterSearch.value = this.value;
                } finally {
                    isSyncingSearch = false;
                }
            }
        });

        // Handle Enter key on header search to trigger form submission
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyListFilters();
            }
        });
    }

    // ===== CLEAR ALL FILTERS BUTTON =====
    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', function () {
            filterType.value = '';
            filterState.value = '';
            filterSite.value = '';
            filterSearch.value = '';
            if (searchInput) searchInput.value = '';
            filterDateFrom.value = '';
            filterDateTo.value = '';

            // Don't reset archived toggle - keep current selection

            applyListFilters();
        });
    }

    // ===== CALCULATE RESULT COUNT =====
    // This function is kept for showing approximate counts in filter dropdowns
    // Counts are based on currently loaded cards and may not reflect server-side filtering accurately
    function calculateResultCount(filterName, filterValue) {
        const cards = document.querySelectorAll('.card');
        let count = 0;

        cards.forEach(card => {
            let show = true;

            // Apply the filter we're calculating for
            if (filterName === 'type' && filterValue && card.dataset.type !== filterValue) {
                show = false;
            }
            if (filterName === 'state' && filterValue && card.dataset.state !== filterValue) {
                show = false;
            }
            if (filterName === 'site' && filterValue && card.dataset.site !== filterValue) {
                show = false;
            }

            if (show) count++;
        });

        return count;
    }

    // ===== APPLY FILTERS =====
    function applyListFilters() {
        // Build URL with all filter parameters and reload page
        const url = new URL(window.location.href);
        
        const typeVal = filterType.value;
        const stateVal = filterState.value;
        const siteVal = filterSite.value;
        const searchVal = filterSearch.value.trim();
        const dateFromVal = filterDateFrom.value;
        const dateToVal = filterDateTo.value;
        const archivedVal = filterArchived.value;

        // Set or remove parameters
        if (typeVal) {
            url.searchParams.set('type', typeVal);
        } else {
            url.searchParams.delete('type');
        }

        if (stateVal) {
            url.searchParams.set('state', stateVal);
        } else {
            url.searchParams.delete('state');
        }

        if (siteVal) {
            url.searchParams.set('site', siteVal);
        } else {
            url.searchParams.delete('site');
        }

        if (searchVal) {
            url.searchParams.set('q', searchVal);
        } else {
            url.searchParams.delete('q');
        }

        if (dateFromVal) {
            url.searchParams.set('date_from', dateFromVal);
        } else {
            url.searchParams.delete('date_from');
        }

        if (dateToVal) {
            url.searchParams.set('date_to', dateToVal);
        } else {
            url.searchParams.delete('date_to');
        }

        if (archivedVal) {
            url.searchParams.set('archived', archivedVal);
        } else {
            url.searchParams.delete('archived');
        }

        // Reload page with new filters
        window.location.href = url.toString();
    }

    // ===== UPDATE CLEAR BUTTON VISIBILITY =====
    function updateClearButtonVisibility() {
        if (!clearAllBtn) return;

        const hasFilters = filterType.value !== '' || filterState.value !== '' ||
            filterSite.value !== '' || filterSearch.value !== '' ||
            filterDateFrom.value !== '' || filterDateTo.value !== '';

        if (hasFilters) {
            clearAllBtn.classList.remove('hidden');
        } else {
            clearAllBtn.classList.add('hidden');
        }
    }

    // ===== UPDATE CHIPS DISPLAY =====
    function updateChipsDisplay() {
        // Get locale mapping
        const localeMap = {
            'fi': 'fi-FI',
            'sv': 'sv-SE',
            'en': 'en-GB',
            'it': 'it-IT',
            'el': 'el-GR'
        };
        const i18n = window.SF_LIST_I18N || {};
        const currentLang = i18n.currentLang || 'fi';
        const locale = localeMap[currentLang] || 'fi-FI';

        chips.forEach(chip => {
            const filterName = chip.dataset.filter;
            const chipLabel = chip.querySelector('.chip-label');

            if (filterName === 'type') {
                const currentValue = filterType.value;

                // Check for empty value
                if (!currentValue) {
                    chip.classList.remove('active');
                    // Use the default label from HTML or i18n
                    const defaultLabel = i18n.filterChipTypeAll || 'Kaikki tyypit';
                    chipLabel.textContent = defaultLabel;
                } else {
                    chip.classList.add('active');
                    const selectedOption = filterType.querySelector(`option[value="${currentValue}"]`);
                    chipLabel.textContent = selectedOption?.textContent || currentValue;
                }
            } else if (filterName === 'state') {
                const currentValue = filterState.value;

                if (!currentValue) {
                    chip.classList.remove('active');
                    const defaultLabel = i18n.filterChipStateAll || 'Kaikki tilat';
                    chipLabel.textContent = defaultLabel;
                } else {
                    chip.classList.add('active');
                    const selectedOption = filterState.querySelector(`option[value="${currentValue}"]`);
                    chipLabel.textContent = selectedOption?.textContent || currentValue;
                }
            } else if (filterName === 'site') {
                const currentValue = filterSite.value;

                // Important: Check empty value to show "All sites" label
                if (!currentValue) {
                    chip.classList.remove('active');
                    const defaultLabel = i18n.filterChipSiteAll || 'Kaikki työmaat';
                    chipLabel.textContent = defaultLabel;
                } else {
                    chip.classList.add('active');
                    chipLabel.textContent = currentValue;
                }
            } else if (filterName === 'date') {
                const from = filterDateFrom.value;
                const to = filterDateTo.value;

                if (from || to) {
                    chip.classList.add('active');
                    chip.dataset.from = from;
                    chip.dataset.to = to;

                    // Check if this matches a preset
                    const currentPreset = getCurrentDatePreset();
                    const presets = getDatePresets();
                    const matchedPreset = presets.find(p => p.value === currentPreset);

                    if (matchedPreset && matchedPreset.labelShort && currentPreset !== 'custom') {
                        // Display preset short label
                        chipLabel.textContent = matchedPreset.labelShort;
                    } else {
                        // Display custom date range
                        if (from && to) {
                            const fromDate = new Date(from);
                            const toDate = new Date(to);
                            const fromFormatted = fromDate.toLocaleDateString(locale, { day: 'numeric', month: 'numeric' });
                            const toFormatted = toDate.toLocaleDateString(locale, { day: 'numeric', month: 'numeric', year: 'numeric' });
                            chipLabel.textContent = `${fromFormatted} - ${toFormatted}`;
                        } else if (from) {
                            const fromDate = new Date(from);
                            const fromFormatted = fromDate.toLocaleDateString(locale, { day: 'numeric', month: 'numeric', year: 'numeric' });
                            chipLabel.textContent = `${fromFormatted} →`;
                        } else {
                            const toDate = new Date(to);
                            const toFormatted = toDate.toLocaleDateString(locale, { day: 'numeric', month: 'numeric', year: 'numeric' });
                            chipLabel.textContent = `→ ${toFormatted}`;
                        }
                    }
                } else {
                    chip.classList.remove('active');
                    chipLabel.textContent = i18n.filterDate || 'Päivämäärä';
                }
            }
        });
    }





    // ===== FORM SUBMISSION =====
    if (filtersForm) {
        filtersForm.addEventListener('submit', function (e) {
            e.preventDefault();
            applyListFilters();
        });
    }

    // ===== EVENT LISTENERS FOR FILTERS =====
    filterType.addEventListener('change', applyListFilters);
    filterState.addEventListener('change', applyListFilters);
    filterSite.addEventListener('change', applyListFilters);
    
    // Bidirectional sync: f-q -> sf-search-input
    // Search is triggered by form submission (Enter key or button click), not on every keystroke
    filterSearch.addEventListener('input', function() {
        if (searchInput && !isSyncingSearch) {
            isSyncingSearch = true;
            try {
                searchInput.value = this.value;
            } finally {
                isSyncingSearch = false;
            }
        }
    });
    
    filterDateFrom.addEventListener('change', applyListFilters);
    filterDateTo.addEventListener('change', applyListFilters);
    // Note: filterArchived is handled by the toggle buttons above

    // ===== CLEAR FILTERS =====
    if (clearBtn) {
        clearBtn.addEventListener('click', function (e) {
            e.preventDefault();

            filterType.value = '';
            filterState.value = '';
            filterSite.value = '';
            filterSearch.value = '';
            filterDateFrom.value = '';
            filterDateTo.value = '';
            filterArchived.value = '';

            // Reset archived toggle
            toggleBtns.forEach(btn => {
                btn.classList.remove('active');
                btn.setAttribute('aria-pressed', 'false');
                if (btn.dataset.archivedValue === '') {
                    btn.classList.add('active');
                    btn.setAttribute('aria-pressed', 'true');
                }
            });

            applyListFilters();
        });
    }

    // ===== INITIAL LOAD =====
    // Server already handles filtering, just update chip display
    updateChipsDisplay();
})();