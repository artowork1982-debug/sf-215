// assets/js/mobile.js
(function () {
    'use strict';

    function checkOrientation() {
        const warning = document.getElementById('sfRotateWarning');
        if (!warning) return;

        const isLandscape = window.innerWidth > window.innerHeight;
        const isMobile = window.innerWidth <= 900 || window.innerHeight <= 500;

        warning.style.display = (isLandscape && isMobile) ? 'flex' : 'none';
    }

    function setVH() {
        // Aseta CSS-muuttuja viewportin korkeudelle (iOS-tuki)
        const vh = window.innerHeight * 0.01;
        document.documentElement.style.setProperty('--vh', vh + 'px');
        // Ei aseteta kiinteitä height-arvoja elementeille
    }

    function initMobileMenu() {
        const hamburgerMenu = document.querySelector('.hamburger-menu');
        const navLinksWrapper = document.querySelector('.sf-nav-links-wrapper');

        if (!hamburgerMenu || !navLinksWrapper) return;

        // Toggle menu on hamburger click
        hamburgerMenu.addEventListener('click', function (e) {
            e.stopPropagation();
            const expanded = hamburgerMenu.getAttribute('aria-expanded') === 'true';
            const newState = !expanded;

            hamburgerMenu.setAttribute('aria-expanded', String(newState));
            navLinksWrapper.classList.toggle('sf-nav-visible');

            // Lock/unlock body scroll
            if (newState) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        });

        // Close menu when clicking a link
        const navLinks = navLinksWrapper.querySelectorAll('a');
        navLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                hamburgerMenu.setAttribute('aria-expanded', 'false');
                navLinksWrapper.classList.remove('sf-nav-visible');
                // Restore body scroll
                document.body.style.overflow = '';
            });
        });

        // Close menu when clicking outside
        document.addEventListener('click', function (e) {
            const isClickInsideMenu = navLinksWrapper.contains(e.target);
            const isClickOnHamburger = hamburgerMenu.contains(e.target);

            if (!isClickInsideMenu && !isClickOnHamburger) {
                const isExpanded = hamburgerMenu.getAttribute('aria-expanded') === 'true';
                if (isExpanded) {
                    hamburgerMenu.setAttribute('aria-expanded', 'false');
                    navLinksWrapper.classList.remove('sf-nav-visible');
                    // Restore body scroll
                    document.body.style.overflow = '';
                }
            }
        });

        // Close menu with ESC key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                const isExpanded = hamburgerMenu.getAttribute('aria-expanded') === 'true';
                if (isExpanded) {
                    hamburgerMenu.setAttribute('aria-expanded', 'false');
                    navLinksWrapper.classList.remove('sf-nav-visible');
                    // Restore body scroll
                    document.body.style.overflow = '';
                }
            }
        });
    }

    function initLanguageDropdown() {
        const langToggle = document.getElementById('sfLangToggle');
        const langDropdown = document.querySelector('.sf-lang-dropdown');

        if (!langToggle || !langDropdown) return;

        // Toggle dropdown
        langToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            const isExpanded = langToggle.getAttribute('aria-expanded') === 'true';
            langToggle.setAttribute('aria-expanded', String(!isExpanded));
            langDropdown.hidden = isExpanded;
        });

        // Close when clicking outside
        document.addEventListener('click', function (e) {
            if (!langToggle.contains(e.target) && !langDropdown.contains(e.target)) {
                langToggle.setAttribute('aria-expanded', 'false');
                langDropdown.hidden = true;
            }
        });
    }

    window.addEventListener('resize', function () {
        checkOrientation();
        setVH();
    });

    window.addEventListener('orientationchange', function () {
        setTimeout(function () {
            checkOrientation();
            setVH();
        }, 100);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            checkOrientation();
            setVH();
            initMobileMenu();
            initLanguageDropdown();
        });
    } else {
        checkOrientation();
        setVH();
        initMobileMenu();
        initLanguageDropdown();
    }
})();