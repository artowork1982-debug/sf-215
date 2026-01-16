// assets/js/list-views.js
// View mode switching (Grid / List / Compact) for list page

(function() {
    'use strict';
    
    const STORAGE_KEY = 'sf_list_view';
    const DEFAULT_VIEW = 'list';
    const VALID_VIEWS = ['grid', 'list', 'compact'];
    const MOBILE_BREAKPOINT = 768; // px - matches CSS breakpoint
    
    // Get saved view or default
    function getSavedView() {
        const saved = localStorage.getItem(STORAGE_KEY);
        return VALID_VIEWS.includes(saved) ? saved : DEFAULT_VIEW;
    }
    
    // Save view preference
    function saveView(view) {
        if (VALID_VIEWS.includes(view)) {
            localStorage.setItem(STORAGE_KEY, view);
        }
    }
    
    // Apply view to container
    function applyView(view) {
        const container = document.querySelector('.sf-list-container');
        if (!container) return;
        
        // On mobile, always use list view (ignore saved preference)
        if (window.innerWidth <= MOBILE_BREAKPOINT) {
            view = 'list';
        }
        
        // Remove all view classes
        VALID_VIEWS.forEach(v => container.classList.remove(`view-${v}`));
        
        // Add current view class
        container.classList.add(`view-${view}`);
        
        // Update toggle buttons
        const toggle = document.getElementById('sfViewToggle');
        if (toggle) {
            toggle.querySelectorAll('button').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.view === view);
            });
        }
    }
    
    // Initialize
    function init() {
        const savedView = getSavedView();
        applyView(savedView);
        
        // Add click handlers to toggle buttons
        const toggle = document.getElementById('sfViewToggle');
        if (toggle) {
            toggle.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-view]');
                if (btn) {
                    const view = btn.dataset.view;
                    saveView(view);
                    applyView(view);
                }
            });
        }
        
        // Re-apply view on window resize (to handle mobile breakpoint)
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                const savedView = getSavedView();
                applyView(savedView);
            }, 250);
        });
    }
    
    // Run on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();