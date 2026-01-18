<?php
// Output buffering to ensure redirects work properly
ob_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/session_activity.php';
require_once __DIR__ . '/csrf.php';

// Varmista sessio ennen $_SESSION käyttöä
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$base = rtrim($config['base_url'] ?? '/', '/');
$currentPage = $_GET['page'] ?? 'list';

$allowedPages = ['list', 'form', 'form_language', 'view', 'users', 'settings', 'profile'];
if (!in_array($currentPage, $allowedPages, true)) {
    $currentPage = 'list';
}

// nykyinen käyttäjä & rooli
$user    = sf_current_user();
$isAdmin = $user && (int)$user['role_id'] === 1;

// Tuetut kielet
$availableLangs = ['fi' => 'FI', 'sv' => 'SV', 'en' => 'EN', 'it' => 'IT', 'el' => 'EL'];

// UI-kieli (sessio > cookie > fi)
$uiLang = $_SESSION['ui_lang'] ?? $_COOKIE['ui_lang'] ?? 'fi';
if (!array_key_exists($uiLang, $availableLangs)) {
    $uiLang = 'fi';
}

// Jos kieli annetaan GET-parametrilla (?lang=sv), tallenna se sessioon + cookieen ja siivoa URL
if (isset($_GET['lang']) && array_key_exists($_GET['lang'], $availableLangs)) {
    $newLang = (string)$_GET['lang'];
    $_SESSION['ui_lang'] = $newLang;

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    setcookie('ui_lang', $newLang, [
        'expires'  => time() + (365 * 24 * 60 * 60),
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Poista lang-parametri URL:sta ja ohjaa takaisin samalle sivulle
    $uri = $_SERVER['REQUEST_URI'] ?? '/index.php?page=list';
    $parts = parse_url($uri);
    $path  = $parts['path'] ?? '/index.php';
    parse_str($parts['query'] ?? '', $q);
    
    // Varmista että page-parametri säilyy aina redirectissä
    $currentPage = $_GET['page'] ?? 'list';
    unset($q['lang']);
    if (!isset($q['page'])) {
        $q['page'] = $currentPage;
    }
    
    $clean = $path . (empty($q) ? '' : ('?' . http_build_query($q)));

    header('Location: ' . $clean);
    exit;
}

// --- Yleinen notifikaatiologiikka kaikille sivuille ---
$notice = $_GET['notice'] ?? '';

$noticeData = [
    'logged_in'         => ['msg_key' => 'notice_logged_in',         'type' => 'success'],
    'sent_review'       => ['msg_key' => 'notice_sent_review',       'type' => 'success'],
    'saved_draft'       => ['msg_key' => 'notice_saved_draft',       'type' => 'info'],
    'sent'              => ['msg_key' => 'notice_sent',              'type' => 'info'],
    'saved'             => ['msg_key' => 'notice_saved',             'type' => 'info'],
    'deleted'           => ['msg_key' => 'notice_deleted',           'type' => 'danger'],
    'published'         => ['msg_key' => 'notice_published',         'type' => 'success'],
    'to_comms'          => ['msg_key' => 'notice_to_comms',          'type' => 'info'],
    'comms_sent'        => ['msg_key' => 'notice_to_comms',          'type' => 'info'],
    'info_requested'    => ['msg_key' => 'notice_info_requested',    'type' => 'info'],
    'translation_saved' => ['msg_key' => 'notice_translation_saved', 'type' => 'success'],
    'user_created'      => ['msg_key' => 'notice_user_created',      'type' => 'success'],
    'user_updated'      => ['msg_key' => 'notice_user_updated',      'type' => 'info'],
    'user_deleted'      => ['msg_key' => 'notice_user_deleted',      'type' => 'danger'],
    'user_pass_reset'   => ['msg_key' => 'notice_user_pass_reset',   'type' => 'info'],
    'bulk_deleted'      => ['msg_key' => 'notice_bulk_deleted',      'type' => 'success'],

    // Worksites
    'worksite_added'    => ['msg_key' => 'worksite_added',    'type' => 'success'],
    'worksite_enabled'  => ['msg_key' => 'worksite_enabled',  'type' => 'success'],
    'worksite_disabled' => ['msg_key' => 'worksite_disabled', 'type' => 'info'],

    // Kuvapankki
    'image_added'       => ['msg_key' => 'notice_image_added',   'type' => 'success'],
    'image_deleted'     => ['msg_key' => 'notice_image_deleted', 'type' => 'success'],
    'image_toggled'     => ['msg_key' => 'notice_image_toggled', 'type' => 'info'],
];

$noticeConfig = $noticeData[$notice] ?? null;
$noticeType   = $noticeConfig['type'] ?? '';

// Erikoiskäsittely bulk_deleted – näytä poistettujen määrä
if ($notice === 'bulk_deleted' && isset($_GET['count'])) {
    $count = (int)$_GET['count'];
    $noticeText = str_replace('{count}', (string)$count, sf_term('notice_bulk_deleted', $uiLang));
} else {
    $noticeText = $noticeConfig ? sf_term($noticeConfig['msg_key'], $uiLang) : '';
}

// Onko notifikaatioparametreja URL:ssa?
$hasNoticeParams = isset($_GET['notice']) || isset($_GET['count']) || isset($_GET['deleted']) || isset($_GET['saved']) || isset($_GET['error']) || isset($_GET['success']);

// 🔒 Vaadi kirjautuminen ennen kuin mitään HTML:ää tulostetaan
sf_require_login();
sf_session_activity_tick(['is_api' => false, 'is_fetch' => false]);
?>

<?php if ($noticeText): ?>
<div class="sf-toast sf-toast-<?= htmlspecialchars($noticeType) ?>" id="sfToast">
    <div class="sf-toast-icon">
        <?php if ($noticeType === 'success'): ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        <?php elseif ($noticeType === 'danger'): ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="15" y1="9" x2="9" y2="15"></line>
                <line x1="9" y1="9" x2="15" y2="15"></line>
            </svg>
        <?php else: ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
        <?php endif; ?>
    </div>

    <span class="sf-toast-text"><?= htmlspecialchars($noticeText) ?></span>

    <button class="sf-toast-close" type="button"
            onclick="document.getElementById('sfToast').remove();">
        ×
    </button>
</div>

<script>
    setTimeout(function () {
        const toast = document.getElementById('sfToast');
        if (toast) {
            toast.classList.add('sf-toast-hide');
            setTimeout(() => toast.remove(), 300);
        }
    }, 4000);
</script>
<?php endif; ?>

<?php if ($hasNoticeParams): ?>
<script>
(function() {
    var params = ['notice', 'count', 'deleted', 'saved', 'created', 'updated', 'error', 'success', 'reset', 'msg'];
    var url = new URL(window.location.href);
    var changed = false;

    for (var i = 0; i < params.length; i++) {
        if (url.searchParams.has(params[i])) {
            url.searchParams.delete(params[i]);
            changed = true;
        }
    }

    if (changed) {
        history.replaceState(null, '', url.pathname + url.search);
    }
})();
</script>
<?php endif; ?>

<div class="sf-nav">
    <div class="sf-nav-inner">
        <div class="sf-nav-left">
            <a href="<?= htmlspecialchars($base) ?>/index.php?page=list" class="sf-brand-link">
                <img
                  src="<?= htmlspecialchars($base) ?>/assets/img/tapojarvi_logo.png"
                  alt="Tapojärvi Logo"
                  class="tapojarvi-logo-img"
                >
            </a>
        </div>

        <div class="sf-nav-center">
            <button class="hamburger-menu" type="button" aria-label="Menu" aria-expanded="false">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <nav class="sf-nav-links-wrapper" aria-label="Päävalikko">
                <div class="sf-nav-links">

                    <a href="<?= htmlspecialchars($base) ?>/index.php?page=list"
                       class="sf-nav-link <?= $currentPage === 'list' ? 'sf-nav-active' : '' ?>">
                        <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/list_icon.png"
                             alt=""
                             class="sf-nav-link-icon"
                             aria-hidden="true">
                        <span><?= htmlspecialchars(sf_term('nav_list', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </a>

                    <a href="<?= htmlspecialchars($base) ?>/index.php?page=form"
                       class="sf-nav-cta <?= $currentPage === 'form' ? 'sf-nav-cta-active' : '' ?>">
                        <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/add_new_icon.png"
                             alt=""
                             class="sf-nav-cta-icon-img"
                             aria-hidden="true">
                        <span class="sf-nav-cta-text">
                            <?= htmlspecialchars(sf_term('nav_new_safetyflash', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </a>

                    <?php if ($user && (int)$user['role_id'] === 1): ?>
                        <a href="<?= htmlspecialchars($base) ?>/index.php?page=settings"
                           class="sf-nav-link <?= $currentPage === 'settings' ? 'sf-nav-active' : '' ?>">
                            <span><?= htmlspecialchars(sf_term('settings_heading', $uiLang) ?? 'Asetukset', ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    <?php endif; ?>

                </div>
            </nav>
        </div>

        <div class="sf-nav-right">
            <div class="sf-lang-switcher" id="sfLangSwitcher">
                <?php
                $langFlags = [
                    'fi' => 'finnish-flag.png',
                    'sv' => 'swedish-flag.png',
                    'en' => 'english-flag.png',
                    'it' => 'italian-flag.png',
                    'el' => 'greece-flag.png',
                ];

                // Rakenna kielilinkit nykyiseen URL:iin (?lang=xx)
                $uri = $_SERVER['REQUEST_URI'] ?? '/index.php?page=list';
                $parts = parse_url($uri);
                $path  = $parts['path'] ?? '/index.php';
                parse_str($parts['query'] ?? '', $q);
                unset($q['lang']);
                ?>
                
                <!-- Mobiilissa dropdown -->
                <div class="sf-lang-mobile">
                    <button type="button" class="sf-lang-current" id="sfLangToggle" aria-expanded="false">
                        <span class="sf-lang-code"><?= strtoupper($uiLang) ?></span>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                    
                    <div class="sf-lang-dropdown" hidden>
                        <?php foreach ($availableLangs as $code => $label): 
                            $q['lang'] = $code;
                            $href = $path . '?' . http_build_query($q);
                        ?>
                            <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" 
                               class="sf-lang-option <?= $uiLang === $code ? 'active' : '' ?>">
                                <?= htmlspecialchars($label) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Desktopissa liput -->
                <div class="sf-lang-desktop">
                    <?php foreach ($availableLangs as $code => $label):
                        $q['lang'] = $code;
                        $flagFile = $langFlags[$code] ?? 'finnish-flag.png';
                        $href = $path . '?' . http_build_query($q);
                    ?>
                        <a
                            href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
                            class="sf-lang-flag-btn <?= $uiLang === $code ? 'active' : '' ?>"
                            aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                            aria-pressed="<?= $uiLang === $code ? 'true' : 'false' ?>"
                            title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <img
                                src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/<?= htmlspecialchars($flagFile, ENT_QUOTES, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                                class="sf-lang-flag-img"
                            >
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($user): ?>
                <button type="button"
                   class="sf-user-info <?= $currentPage === 'profile' ? 'sf-user-active' : '' ?>"
                   title="<?= htmlspecialchars($user['email'] ?? '') ?>"
                   data-modal-open="modalProfile">
                    <span class="sf-user-name">
                        <?= htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?>
                    </span>

                    <svg class="sf-user-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                </button>

                <a href="#sfLogoutModal" class="sf-nav-logout" data-modal-open="#sfLogoutModal">
                    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/log_out.svg"
                         alt=""
                         class="logout-icon"
                         aria-hidden="true">
                    <span class="logout-text">
                        <?= htmlspecialchars(sf_term('nav_logout', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Logout confirm modal -->
<div id="sfLogoutModal" class="sf-modal hidden sf-modal-small sf-modal-centered" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3 id="sfLogoutTitle">
                <?= htmlspecialchars(sf_term('logout_confirm_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>

        <div class="sf-modal-body">
            <p>
                <?= htmlspecialchars(sf_term('logout_confirm_text', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('logout_confirm_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>

            <a class="sf-btn sf-btn-danger" href="<?= htmlspecialchars($base) ?>/app/api/logout.php">
                <?= htmlspecialchars(sf_term('logout_confirm_ok', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const m = document.getElementById("sfLogoutModal");
    if (m && m.parentElement !== document.body) {
        document.body.appendChild(m);
    }
});
</script>

<script>
window.SF_BASE_URL = <?php echo json_encode($base, JSON_UNESCAPED_SLASHES); ?>;
window.SF_NOTICE_MESSAGES = <?php
    echo json_encode([
        'worksite_added'   => sf_term('worksite_added', $uiLang),
        'worksite_enabled' => sf_term('worksite_enabled', $uiLang),
        'worksite_disabled'=> sf_term('worksite_disabled', $uiLang),
        'image_added'      => sf_term('notice_image_added', $uiLang),
        'image_deleted'    => sf_term('notice_image_deleted', $uiLang),
        'image_toggled'    => sf_term('notice_image_toggled', $uiLang),
        'error'            => sf_term('notice_error', $uiLang),
        'missing_fields'   => sf_term('notice_missing_fields', $uiLang),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>;

window.sfToast = function(type, message) {
    const mappedType = (type === "error") ? "danger" : type;

    const existing = document.getElementById("sfToast");
    if (existing) existing.remove();

    const t = document.createElement("div");
    t.id = "sfToast";
    t.className = "sf-toast sf-toast-" + mappedType;

    const escapeHtml = (txt) => {
        const div = document.createElement("div");
        div.textContent = txt ?? "";
        return div.innerHTML;
    };

    let iconSvg = "";
    if (mappedType === "success") {
        iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
        </svg>`;
    } else if (mappedType === "danger") {
        iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="15" y1="9" x2="9" y2="15"></line>
            <line x1="9" y1="9" x2="15" y2="15"></line>
        </svg>`;
    } else {
        iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="16" x2="12" y2="12"></line>
            <line x1="12" y1="8" x2="12.01" y2="8"></line>
        </svg>`;
    }

    t.innerHTML = `
        <div class="sf-toast-icon">${iconSvg}</div>
        <span class="sf-toast-text">${escapeHtml(message)}</span>
        <button class="sf-toast-close" type="button" aria-label="Close">×</button>
    `;
    t.querySelector(".sf-toast-close")?.addEventListener("click", () => t.remove());

    document.body.appendChild(t);

    clearTimeout(window.sfToast._timer);
    window.sfToast._timer = setTimeout(() => {
        if (t && t.parentElement) {
            t.classList.add("sf-toast-hide");
            setTimeout(() => {
                if (t && t.parentElement) t.remove();
            }, 300);
        }
    }, 4000);
};
</script>