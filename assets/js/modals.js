(function () {
    "use strict";

    function getOpenModals() {
        return Array.from(document.querySelectorAll(".sf-modal:not(.hidden), .sf-library-modal:not(.hidden)"));
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.remove("hidden");
        document.body.classList.add("sf-modal-open");

        // Focus ensimmäiseen järkevään elementtiin
        const focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable) focusable.focus({ preventScroll: true });
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.add("hidden");

        // Poista scroll lock jos ei jää auki muita modaaleja
        if (getOpenModals().length === 0) {
            document.body.classList.remove("sf-modal-open");
        }
    }

    // Delegoitu avaus: <a data-modal-open="#sfLogoutModal">
    document.addEventListener("click", function (e) {
        const opener = e.target.closest("[data-modal-open]");
        if (opener) {
            e.preventDefault();
            const sel = opener.getAttribute("data-modal-open");
            const modal = sel ? document.querySelector(sel) : null;
            openModal(modal);
            return;
        }

        // Sulje-napit: data-modal-close
        const closer = e.target.closest("[data-modal-close]");
        if (closer) {
            e.preventDefault();
            const modal = closer.closest(".sf-modal, .sf-library-modal");
            closeModal(modal);
            return;
        }

        // Klikkaus overlayhin sulkee (jos klikataan suoraan overlayta)
        const overlay = e.target.classList && (e.target.classList.contains("sf-modal") || e.target.classList.contains("sf-library-modal"))
            ? e.target
            : null;

        if (overlay) {
            closeModal(overlay);
        }
    });

    // Escape sulkee päällimmäisen modaalin
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        const open = getOpenModals();
        if (open.length > 0) {
            closeModal(open[open.length - 1]);
        }
    });
})();