<?php

require_once __DIR__ . '/config.php';

// Debug-asetukset konfiguraation mukaan
if ($config['debug'] ?? false) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/app/logs/php_errors.log');
}

require_once __DIR__ . '/app/includes/log_app.php';
require_once __DIR__ . '/app/includes/statuses.php';
require_once __DIR__ . '/app/includes/csrf.php';
require_once __DIR__ . '/app/includes/auth.php';

// Require authentication BEFORE any HTML output (prevents 'headers already sent')
sf_require_login();
require_once __DIR__ . '/app/lib/sf_terms.php';

// UI-kieli (FI/EN)
$uiLang = $_SESSION['ui_lang'] ?? 'fi';
$termsConfig = sf_get_terms_config();
if (!in_array($uiLang, $termsConfig['languages'] ?? [], true)) {
    $uiLang = 'fi';
    $_SESSION['ui_lang'] = 'fi';
}

// Mikä sivu halutaan ladata?
$page = $_GET['page'] ?? 'list';

// Sallitut sivut
$allowed = [
    'list'          => '/app/pages/list.php',
    'form'          => '/app/pages/form.php',
    'form_language' => '/app/pages/form_language.php',
    'view'          => '/app/pages/view.php',
    'users'         => '/app/pages/users.php',
    'settings'      => '/app/pages/settings.php',
    'profile'       => '/app/pages/profile.php',
];

if (!isset($allowed[$page])) {
    $page = 'list';
}

$file = $allowed[$page];
$base = rtrim($config['base_url'], '/');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($uiLang) ?>">
<head>
    <meta charset="UTF-8">

    <!-- ===== MOBIILI META TAGIT ===== -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="format-detection" content="telephone=no">

    <!-- PWA -->
    <link rel="manifest" href="<?= $base; ?>/manifest.php">
    <meta name="theme-color" content="#0f172a">
    <link rel="apple-touch-icon" href="<?= $base; ?>/assets/img/icons/pwa-icon-192.png">

    <title>Safetyflash</title>

    <!-- Yleiset CSS -->
    <link rel="stylesheet" href="<?= $base; ?>/assets/css/nav.css?v=4">
    <link rel="stylesheet" href="<?= $base; ?>/assets/css/global.css?v=4">
    <link rel="stylesheet" href="<?= $base; ?>/assets/css/skeleton.css?v=1">

    <!-- Sivukohtaiset CSS -->
    <?php if ($page === 'list'): ?>
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/list.css?v=4">
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/copy-to-clipboard.css">
    <?php elseif ($page === 'form'): ?>
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/form.css?v=5">
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/render.css?v=4">
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/preview.css?v=5">
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/image-library.css?v=2">
    <?php elseif ($page === 'form_language'): ?>
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/form.css?v=5">
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/render.css?v=4">
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/preview.css?v=5">
    <?php elseif ($page === 'view'): ?>
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/view.css?v=4">
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/render.css?v=4">
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/copy-to-clipboard.css">
    <?php elseif ($page === 'settings'): ?>
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/settings.css?v=3">
    <?php elseif ($page === 'profile'): ?>
        <link rel="stylesheet" href="<?= $base; ?>/assets/css/settings.css?v=3">
    <?php endif; ?>

    <!-- MODALS VIIMEISENÄ - yliajaa sivukohtaiset -->
    <link rel="stylesheet" href="<?= $base; ?>/assets/css/modals.css?v=5">

    <!-- html2canvas -->
    <script src="<?= $base; ?>/assets/js/vendor/html2canvas.min.js"></script>

    <!-- ===== PROGRESS BAR + SIVUN FADE (EI AJAX) ===== -->
    <style>
        /* Progress bar (yläreuna) */
        #sfProgress {
            position: fixed;
            top: 0;
            left: 0;
            height: 3px;
            width: 0%;
            background: #FEE000;
            z-index: 999999;
            opacity: 0;
            transform: translateZ(0);
            transition: width 220ms ease, opacity 180ms ease;
        }
        body.sf-loading #sfProgress {
            opacity: 1;
            width: 65%;
        }
        body.sf-loading.sf-loading-long #sfProgress {
            width: 90%;
        }

        /* Fade-out / fade-in */
        .sf-container {
            opacity: 1;
            transition: opacity 200ms ease;
        }
        body.sf-loading .sf-container {
            opacity: 0.35;
        }

        @media (prefers-reduced-motion: reduce) {
            .sf-container { transition: none; }
            #sfProgress { transition: none; }
        }
    </style>

    <!-- JS-konstantit -->
    <script>
        const SF_BASE_URL   = "<?= rtrim($config['base_url'], '/'); ?>";
        const SF_UPLOAD_URL = SF_BASE_URL + "/upload.php";
        const SF_SAVE_URL   = SF_BASE_URL + "/app/api/save_flash.php";
        const SF_IMAGES_URL = SF_BASE_URL + "/uploads/images";
        // Session timeout for warning (rounded to nearest 5 minutes)
        const SF_SESSION_TIMEOUT = <?= (int)(floor(($config['session']['timeout'] ?? 1800) / 300) * 300) ?>;

        // Tee saataville myös window.* (käytössä moduuleissa ja muissa skripteissä)
        window.SF_BASE_URL   = SF_BASE_URL;
        window.SF_UPLOAD_URL = SF_UPLOAD_URL;
        window.SF_SAVE_URL   = SF_SAVE_URL;
        window.SF_IMAGES_URL = SF_IMAGES_URL;
        window.SF_SESSION_TIMEOUT = SF_SESSION_TIMEOUT;

        // Translations for JavaScript
        window.SF_I18N = {
            autosave_saved: "<?= htmlspecialchars(sf_term('autosave_saved', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
        };
        
        // Profile modal translations
        window.SF_PROFILE_I18N = {
            profileUpdated: "<?= htmlspecialchars(sf_term('notice_profile_updated', $uiLang), ENT_QUOTES, 'UTF-8') ?>",
            passwordChanged: "<?= htmlspecialchars(sf_term('notice_password_changed', $uiLang), ENT_QUOTES, 'UTF-8') ?>",
            passwordsMismatch: "<?= htmlspecialchars(sf_term('profile_passwords_mismatch', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
        };
    </script>
</head>

<body
    data-page="<?= htmlspecialchars($page); ?>"
    class="<?= ($page === 'form' || $page === 'form_language') ? 'form-page' : ''; ?>"
>
<!-- Progress bar -->
<div id="sfProgress" aria-hidden="true"></div>

<!-- ===== VAAKAKÄYTÖN VAROITUS ===== -->
<div class="sf-rotate-warning" id="sfRotateWarning">
    <div class="sf-rotate-warning-icon">📱</div>
    <div class="sf-rotate-warning-text"><?= htmlspecialchars(sf_term('rotate_phone', $uiLang), ENT_QUOTES, 'UTF-8') ?></div>
    <div class="sf-rotate-warning-subtext"><?= htmlspecialchars(sf_term('rotate_phone_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?></div>
</div>

<?php include __DIR__ . '/app/includes/header.php'; ?>

<?php if ($page === 'form' || $page === 'form_language'): ?>
<!-- Draft Recovery Banner -->
<div id="sfDraftBanner" class="sf-draft-banner" style="display: none;">
    <div class="sf-draft-banner-content">
        <div class="sf-draft-banner-text">
            <span class="sf-draft-banner-icon">💾</span>
            <span><?= htmlspecialchars(sf_term('draft_recovery_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="sf-draft-banner-actions">
            <button type="button" id="sfDraftContinue" class="sf-draft-btn sf-draft-btn-primary">
                <?= htmlspecialchars(sf_term('draft_continue', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" id="sfDraftDiscard" class="sf-draft-btn sf-draft-btn-secondary">
                <?= htmlspecialchars(sf_term('draft_discard', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="sf-container" id="sfContainer">
    <?php include __DIR__ . $file; ?>
</div>

<?php require_once __DIR__ . '/app/includes/footer.php'; ?>

<!-- Session Timeout Warning Modal -->
<div id="sfSessionModal" class="sf-session-modal hidden">
    <div class="sf-session-modal-content">
        <div class="sf-session-modal-header">
            <span class="sf-session-modal-icon">⏰</span>
            <h3 class="sf-session-modal-title"><?= htmlspecialchars(sf_term('session_expiring_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
        </div>
        <p class="sf-session-modal-message"><?= htmlspecialchars(sf_term('session_expiring_message', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
        <div class="sf-session-modal-actions">
            <button type="button" id="sfSessionContinue" class="sf-session-btn">
                <?= htmlspecialchars(sf_term('session_continue', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<?php if ($page === 'form'): ?>
    <!-- Preview-kortin skaalaus -->
    <script src="<?= $base; ?>/assets/js/previewScaler.js?v=1"></script>
    <!-- Uusi modulaarinen lomakelogiikka -->
    <script type="module" src="<?= $base; ?>/assets/js/form.js?v=7"></script>
    <script src="<?= $base; ?>/assets/js/image-library.js?v=2"></script>
    <!-- Auto-save module -->
    <script type="module" src="<?= $base; ?>/assets/js/modules/autosave.js?v=1"></script>

<?php elseif ($page === 'form_language'): ?>
    <script src="<?= $base; ?>/assets/js/form_language.js?v=4"></script>

<?php elseif ($page === 'view'): ?>
    <script src="<?= $base; ?>/assets/js/view.js?v=4"></script>
    <script src="<?= $base; ?>/assets/js/copy-to-clipboard.js"></script>

<?php elseif ($page === 'users'): ?>
    <script src="<?= $base; ?>/assets/js/users.js?v=4"></script>

<?php elseif ($page === 'settings'): ?>
    <script src="<?= $base; ?>/assets/js/users.js?v=4"></script>
    <script src="<?= $base; ?>/assets/js/settings.js?v=2"></script>
<?php endif; ?>

<!-- Globaali modaalien hallinta -->
<script src="<?= $base; ?>/assets/js/modals.js?v=1"></script>

<!-- Profile modal -->
<script src="<?= $base; ?>/assets/js/profile-modal.js?v=1"></script>

<!-- Skeleton loading handler -->
<script src="<?= $base; ?>/assets/js/skeleton.js?v=1"></script>

<!-- Mobiilituki -->
<script src="<?= $base; ?>/assets/js/mobile.js?v=2"></script>

<!-- Taustaprosessoinnin seuranta (bg_process=ID) -->
<script src="<?= $base; ?>/assets/js/background-process.js?v=1"></script>

<script>
(function () {
    const progress = document.getElementById("sfProgress");

    function startLoading() {
        document.body.classList.add("sf-loading");
        // jos lataus kestää > 600ms, nostetaan palkki lähemmäs loppua
        window.__sfLoadingTimer = window.setTimeout(() => {
            document.body.classList.add("sf-loading-long");
        }, 600);
    }

    function stopLoading() {
        window.clearTimeout(window.__sfLoadingTimer);
        document.body.classList.remove("sf-loading-long");
        if (progress) progress.style.width = "100%";
        // pieni viive, jotta “100%” näkyy
        window.setTimeout(() => {
            document.body.classList.remove("sf-loading");
            if (progress) progress.style.width = "";
        }, 140);
    }

// Linkkiklikit (vain samaan originin navigaatio)
document.addEventListener("click", (e) => {
    const a = e.target.closest("a");
    if (!a) return;

    // LISÄTTY: Ohita, jos linkillä on 'download'-attribuutti.
    if (a.hasAttribute("download")) return;

    // ohita: uuteen välilehteen, ankkurit, javascript:, tyhjät
    const href = a.getAttribute("href") || "";
    if (!href || href.startsWith("#") || href.startsWith("javascript:")) return;
    if (a.target && a.target !== "_self") return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

    // Varmista sama origin (käytä URL-parsintaa)
    try {
        const url = new URL(href, window.location.href);
        if (url.origin !== window.location.origin) return;
    } catch (err) {
        return;
    }

    startLoading();
}, true);
    // Lomakkeen submit
    document.addEventListener("submit", () => {
        startLoading();
    }, true);

    // Kun sivu on valmis
    window.addEventListener("pageshow", () => {
        // pageshow triggaa myös BFCache-paluuissa -> pysyy “freshinä”
        stopLoading();
    });

    window.addEventListener("load", () => {
        stopLoading();
    });

    // Toastin piilotus (kuten sinulla)
    document.addEventListener("DOMContentLoaded", function () {
        const t = document.getElementById('toastNotice');
        if (t) setTimeout(() => t.classList.add('hide'), 4000);
    });
})();
</script>

<!-- Session Timeout Monitor & Draft Banner -->
<script>
(function() {
    // Session timeout monitoring
    const SESSION_TIMEOUT = window.SF_SESSION_TIMEOUT || 1800; // 30 minutes default
    const WARNING_TIME = 300; // 5 minutes before expiry
    let lastActivityTime = Date.now();
    let warningShown = false;

    function resetActivity() {
        lastActivityTime = Date.now();
        warningShown = false;
        hideSessionWarning();
    }

    function checkSessionTimeout() {
        const now = Date.now();
        const elapsed = Math.floor((now - lastActivityTime) / 1000);
        const remaining = SESSION_TIMEOUT - elapsed;

        // Show warning 5 minutes before expiry
        if (remaining <= WARNING_TIME && remaining > 0 && !warningShown) {
            showSessionWarning();
            warningShown = true;
        }
    }

    function showSessionWarning() {
        const modal = document.getElementById('sfSessionModal');
        if (modal) {
            modal.classList.remove('hidden');
        }
    }

    function hideSessionWarning() {
        const modal = document.getElementById('sfSessionModal');
        if (modal) {
            modal.classList.add('hidden');
        }
    }

    async function extendSession() {
        try {
            const csrfInput = document.querySelector('input[name="csrf_token"]');
            const csrfToken = csrfInput ? csrfInput.value : '';

            const response = await fetch(window.SF_BASE_URL + '/app/api/session_keepalive.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken
                }
            });

            if (response.ok) {
                resetActivity();
            }
        } catch (error) {
            console.error('Failed to extend session:', error);
        }
    }

    // Track user activity
    ['click', 'keydown', 'mousemove', 'touchstart', 'scroll'].forEach(event => {
        document.addEventListener(event, () => {
            resetActivity();
        }, { passive: true, once: false });
    });

    // Check session timeout every 30 seconds
    setInterval(checkSessionTimeout, 30000);

    // Session continue button
    const continueBtn = document.getElementById('sfSessionContinue');
    if (continueBtn) {
        continueBtn.addEventListener('click', () => {
            extendSession();
        });
    }

    // Draft banner functionality (form pages only)
    const draftBanner = document.getElementById('sfDraftBanner');
    if (draftBanner) {
        // Check for drafts when page loads
        if (window.SF_AVAILABLE_DRAFTS && window.SF_AVAILABLE_DRAFTS.length > 0) {
            draftBanner.style.display = 'block';

            const continueBtn = document.getElementById('sfDraftContinue');
            const discardBtn = document.getElementById('sfDraftDiscard');

            if (continueBtn) {
                continueBtn.addEventListener('click', async () => {
                    // Load the first available draft
                    const draft = window.SF_AVAILABLE_DRAFTS[0];
                    
                    // Use autosave module if available
                    if (window.autoSave) {
                        await window.autoSave.loadDraft(draft.id);
                    }

                    draftBanner.style.display = 'none';
                });
            }

            if (discardBtn) {
                discardBtn.addEventListener('click', async () => {
                    const draft = window.SF_AVAILABLE_DRAFTS[0];

                    // Use autosave module if available
                    if (window.autoSave) {
                        await window.autoSave.deleteDraft(draft.id);
                    }

                    draftBanner.style.display = 'none';
                });
            }
        }
    }
})();
</script>

<!-- Service Worker Registration -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('<?= $base; ?>/sw.js.php')
            .then(function(registration) {
                console.log('SW registered:', registration.scope);
            })
            .catch(function(error) {
                console.log('SW registration failed:', error);
            });
    });
}
</script>

<!-- PWA Update Handler -->
<script src="<?= $base; ?>/assets/js/pwa-update.js"></script>

<!-- Copy to Clipboard for list page -->
<?php if ($page === 'list'): ?>
<script src="<?= $base; ?>/assets/js/copy-to-clipboard.js"></script>
<?php endif; ?>

</body>
</html>