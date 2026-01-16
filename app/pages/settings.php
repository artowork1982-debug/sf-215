<?php
// app/pages/settings.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/statuses.php';

$baseUrl = rtrim($config['base_url'] ?? '', '/');

// Vain Pääkäyttäjä (role_id = 1) pääsee asetuksiin
$user = sf_current_user();
if (!$user || (int) $user['role_id'] !== 1) {
    http_response_code(403);
    echo 'Ei käyttöoikeutta asetussivulle.';
    exit;
}

// UI-kieli
$currentUiLang = $uiLang ?? ($_SESSION['ui_lang'] ?? 'fi');

// DB-yhteys
$mysqli = sf_db();

// Aktiivinen välilehti
$tab        = $_GET['tab'] ?? 'users';
$allowedTabs = ['users', 'worksites', 'image_library', 'audit_log'];

if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'users';
}
?>
<div class="sf-settings-page">
    <h1>
        <?= htmlspecialchars(
            sf_term('settings_heading', $currentUiLang) ?? 'Asetukset',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </h1>

<!-- Välilehdet -->
<div class="sf-tabs">

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=users"
       class="sf-tab <?= $tab === 'users' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/users.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_users', $currentUiLang) ?? 'Käyttäjät',
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=worksites"
       class="sf-tab <?= $tab === 'worksites' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/worksite.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_worksites', $currentUiLang) ?? 'Työmaat',
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=image_library"
       class="sf-tab <?= $tab === 'image_library' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/image.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_image_library', $currentUiLang) ?? 'Kuvapankki',
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=audit_log"
       class="sf-tab <?= $tab === 'audit_log' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/calendar.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_audit_log', $currentUiLang) ?? 'Tapahtumaloki',
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

</div>

    <div class="sf-tabs-content">
        <?php
        // Lataa aktiivinen välilehti
        $tabFile = __DIR__ . '/settings/tab_' . $tab . '.php';
        if (file_exists($tabFile)) {
            include $tabFile;
        } else {
            echo '<p>Välilehteä ei löydy.</p>';
        }
        ?>
    </div>
</div>