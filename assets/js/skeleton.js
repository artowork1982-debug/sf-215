// assets/js/skeleton.js
// Unified skeleton loading management

(function() {
    'use strict';

    /**
     * Hide skeleton with smooth fade-out
     * @param {string} containerId - ID of the skeleton container
     * @param {number} delay - Delay in milliseconds before hiding
     */
    function hideSkeleton(containerId, delay) {
        delay = delay || 200;
        const skeleton = document.getElementById(containerId);
        if (skeleton) {
            setTimeout(function() {
                skeleton.classList.add('loaded');
            }, delay);
        }
    }

    /**
     * Auto-hide skeletons when DOM is ready
     */
    document.addEventListener('DOMContentLoaded', function() {
        // List page skeleton
        hideSkeleton('skeletonContainer', 300);
        
        // Settings/Users table skeleton
        hideSkeleton('skeletonTable', 400);
    });

    // Export for potential external use
    if (typeof window !== 'undefined') {
        window.SF_Skeleton = {
            hide: hideSkeleton
        };
    }
})();