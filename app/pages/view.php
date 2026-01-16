<?php
// app/pages/view.php
declare(strict_types=1);

require_once __DIR__ .'/../includes/protect.php';
require_once __DIR__ .'/../includes/statuses.php';

$base = rtrim($config['base_url'] ?? '', '/');

// --- DB: PDO ---
try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    $errorLang = $_SESSION['ui_lang'] ?? 'fi';
    echo '<p>' . htmlspecialchars(sf_term('db_error', $errorLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// --- ID ---
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    $errorLang = $_SESSION['ui_lang'] ?? 'fi';
    echo '<p>' . htmlspecialchars(sf_term('invalid_id', $errorLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// --- Safetyflash ---
$stmt = $pdo->prepare("
    SELECT *,
        DATE_FORMAT(created_at, '%d.%m.%Y %H:%i')   AS createdFmt,
        DATE_FORMAT(updated_at, '%d.%m.%Y %H:%i')   AS updatedFmt,
        DATE_FORMAT(occurred_at, '%d.%m.%Y %H:%i')  AS occurredFmt
    FROM sf_flashes
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$flash = $stmt->fetch();

if (!$flash) {
    $errorLang = $_SESSION['ui_lang'] ?? 'fi';
    echo '<p>' . htmlspecialchars(sf_term('flash_not_found', $errorLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

$uiLang          = $_SESSION['ui_lang'] ?? 'fi';
$currentUiLang   = $uiLang ?? 'fi';
// Mäppää state -> CSS-luokka view-sivun pillille
$stateClassMap = [
    'draft'          => 'status-pill-draft',
    'pending_review' => 'status-pill-pending',
    'request_info'   => 'status-pill-request',
    'reviewed'       => 'status-pill-reviewed',
    'to_comms'       => 'status-pill-comms',
    'published'      => 'status-pill-published',
];
$metaStatusClass = $stateClassMap[$flash['state']] ?? '';
$statusLabel     = function_exists('sf_status_label') ? (sf_status_label($flash['state'], $currentUiLang) ?? '') : '';

// Lokia varten ryhmän juuri
$logFlashId = !empty($flash['translation_group_id'])
    ? (int)$flash['translation_group_id']
    : (int)$flash['id'];

// Hae lokit (ryhmän juurella)
$logs = [];
$logStmt = $pdo->prepare("
    SELECT 
        l.id,
        l.event_type,
        l.description,
        l.created_at,
        u.first_name,
        u.last_name
    FROM safetyflash_logs l
    LEFT JOIN sf_users u ON u.id = l.user_id
    WHERE l.flash_id = ?
    ORDER BY l.created_at DESC
");
$logStmt->execute([$logFlashId]);
$logs = $logStmt->fetchAll();

// Fallback: jos lokitaulu on tyhjä, näytä vähintään luontiaika
if (empty($logs)) {
    $creatorName = trim(($flash['created_by_first_name'] ?? '') .' ' .($flash['created_by_last_name'] ?? ''));
    if ($creatorName === '') $creatorName = null;

    $logs = [[
        'id' => 0,
        'event_type' => 'created',
        'description' => sf_term('log_created', $currentUiLang) ?? 'Created',
        'created_at' => $flash['created_at'] ?? ($flash['createdFmtRaw'] ?? null),
        'first_name' => $creatorName ? ($flash['created_by_first_name'] ?? '') : null,
        'last_name'  => $creatorName ? ($flash['created_by_last_name'] ?? '') : null,
    ]];
}

// Onko tämä kieliversio vai alkuperäinen flash?
$isTranslation = !empty($flash['translation_group_id'])
    && (int) $flash['translation_group_id'] !== (int) $flash['id'];

$editUrl  = $base .  '/index.php?page=form&id=' .  $id . '&step=3';

// --- Tuetut kielet ja lippujen ikonit ---
$supportedLangs = [
    'fi' => ['label' => 'FI', 'icon' => 'finnish-flag.png'],
    'sv' => ['label' => 'SV', 'icon' => 'swedish-flag.png'],
    'en' => ['label' => 'EN', 'icon' => 'english-flag.png'],
    'it' => ['label' => 'IT', 'icon' => 'italian-flag.png'],
    'el' => ['label' => 'EL', 'icon' => 'greece-flag.png'], // 'el' on Kreikan kielikoodi
];

// --- Kieliversiot & preview ---
require_once __DIR__ .'/../services/render_services.php';

$currentId   = (int) ($flash['id'] ?? 0);
$currentLang = $flash['lang'] ?? 'fi';

$translationGroupId = !empty($flash['translation_group_id'])
    ? (int) $flash['translation_group_id']
    : $currentId;

$translations = [];
if ($translationGroupId > 0 && function_exists('sf_get_flash_translations')) {
    $translations = sf_get_flash_translations($pdo, $translationGroupId);
    if (!isset($translations[$currentLang]) && $currentId > 0) {
        $translations[$currentLang] = $currentId;
    }
}

// Jos preview_filename puuttuu, yritä generoida se
if (empty($flash['preview_filename']) && $currentId > 0 && function_exists('sf_generate_flash_preview')) {
    try {
        sf_generate_flash_preview($pdo, $currentId);
        // Hae uudelleen
        $stmtPrev = $pdo->prepare("SELECT preview_filename FROM sf_flashes WHERE id = ?");
        $stmtPrev->execute([$currentId]);
        $prevRow = $stmtPrev->fetch();
        if ($prevRow && !empty($prevRow['preview_filename'])) {
            $flash['preview_filename'] = $prevRow['preview_filename'];
        }
    } catch (Throwable $e) {
        error_log("Could not auto-generate preview for flash {$currentId}: " .$e->getMessage());
    }
}

// --- Preview-kuva 1 ---
$previewUrl = "{$base}/assets/img/camera-placeholder.png";
if (!empty($flash['preview_filename'])) {
    $filename = $flash['preview_filename'];
    $previewPathNew = __DIR__ .'/../../uploads/previews/' .$filename;
    $previewPathOld = __DIR__ .'/../../img/' .$filename; // legacy
    if (is_file($previewPathNew)) {
        $previewUrl = "{$base}/uploads/previews/" .$filename;
    } elseif (is_file($previewPathOld)) {
        $previewUrl = "{$base}/img/" .$filename;
    }
}

// --- Preview-kuva 2 (vain tutkintatiedotteille) ---
$previewUrl2 = null;
$hasSecondCard = false;

if ($flash['type'] === 'green' && !empty($flash['preview_filename_2'])) {
    $filename2 = $flash['preview_filename_2'];
    $previewPath2New = __DIR__ .'/../../uploads/previews/' .$filename2;
    $previewPath2Old = __DIR__ .'/../../img/' .$filename2;
    
    // Tarkista että tiedosto OIKEASTI on olemassa
    if (is_file($previewPath2New)) {
        $previewUrl2 = "{$base}/uploads/previews/" .$filename2;
        $hasSecondCard = true;
    } elseif (is_file($previewPath2Old)) {
        $previewUrl2 = "{$base}/img/" .$filename2;
        $hasSecondCard = true;
    }
}
// UUSI: Editorissa generoitu rasteri (uploads/edited) – luetaan annotations_datasta
$sfAnn = json_decode($flash['annotations_data'] ?? '', true);
$sfEditedImages = (is_array($sfAnn) && isset($sfAnn['edited_images']) && is_array($sfAnn['edited_images']))
    ? $sfAnn['edited_images']
    : [];

$sfGetEditedUrl = function (int $slot) use ($sfEditedImages, $base): ?string {
    $key = (string) $slot;
    if (!empty($sfEditedImages[$key])) {
        return $base .'/uploads/edited/' .$sfEditedImages[$key];
    }
    return null;
};

// Kuvapolkujen muodostaminen JS:lle
$getImageUrlForJs = function ($filename) use ($base) {
    if (empty($filename)) {
        return '';
    }
    
    // Tarkista ensin uploads/images
    $path = "uploads/images/{$filename}";
    $fullPath = __DIR__ ."/../../{$path}";
    if (file_exists($fullPath)) {
        return "{$base}/{$path}";
    }
    
    // Tarkista uploads/library (kuvakirjasto)
    $libPath = "uploads/library/{$filename}";
    $libFullPath = __DIR__ ."/../../{$libPath}";
    if (file_exists($libFullPath)) {
        return "{$base}/{$libPath}";
    }
    
    // Vanha polku (legacy)
    $oldPath = "img/{$filename}";
    $oldFullPath = __DIR__ . "/../../{$oldPath}";
    if (file_exists($oldFullPath)) {
        return "{$base}/{$oldPath}";
    }
    
    // Palauta tyhjä jos ei löydy
    return '';
};

// Hae originaalin grid_bitmap jos tämä on kieliversio
$originalGridBitmap = $flash['grid_bitmap'] ?? '';
$originalGridBitmapUrl = '';

if (empty($originalGridBitmap) && ! empty($flash['translation_group_id'])) {
    // Hae originaalin grid_bitmap
    $origStmt = $pdo->prepare("SELECT grid_bitmap FROM sf_flashes WHERE id = ?  LIMIT 1");
    $origStmt->execute([(int)$flash['translation_group_id']]);
    $origRow = $origStmt->fetch();
    if ($origRow && !empty($origRow['grid_bitmap'])) {
        $originalGridBitmap = $origRow['grid_bitmap'];
    }
}

// Muodosta grid_bitmap URL
if (! empty($originalGridBitmap)) {
    if (strpos($originalGridBitmap, 'data:image/') === 0) {
        $originalGridBitmapUrl = $originalGridBitmap;
    } else {
        $gridPath = __DIR__ . '/../../uploads/grids/' . $originalGridBitmap;
        if (file_exists($gridPath)) {
            $originalGridBitmapUrl = $base .  '/uploads/grids/' . $originalGridBitmap;
        }
    }
}

$flashDataForJs = [
    'id' => $flash['id'],
    'type' => $flash['type'],
    'title' => $flash['title'],
    'title_short' => $flash['title_short'] ?? $flash['summary'] ?? '',
    'description' => $flash['description'] ?? '',
    'root_causes' => $flash['root_causes'] ?? '',
    'actions' => $flash['actions'] ??  '',
    'site' => $flash['site'] ??  '',
    'site_detail' => $flash['site_detail'] ?? '',
    'occurred_at' => $flash['occurred_at'] ?? '',
    'lang' => $flash['lang'] ??  'fi',
    'image_main' => $flash['image_main'] ?? '',
    'image_2' => $flash['image_2'] ?? '',
    'image_3' => $flash['image_3'] ?? '',
    'image_main_url' => ($sfGetEditedUrl(1) ?: $getImageUrlForJs($flash['image_main'] ?? null)),
    'image_2_url' => ($sfGetEditedUrl(2) ?: $getImageUrlForJs($flash['image_2'] ?? null)),
    'image_3_url' => ($sfGetEditedUrl(3) ?: $getImageUrlForJs($flash['image_3'] ?? null)),
    'image1_transform' => $flash['image1_transform'] ?? '',
    'image2_transform' => $flash['image2_transform'] ?? '',
    'image3_transform' => $flash['image3_transform'] ?? '',
    'grid_style' => $flash['grid_style'] ?? 'grid-3-main-top',
    'grid_bitmap' => $originalGridBitmap,
    'grid_bitmap_url' => $originalGridBitmapUrl,
];

// --- Tyyppien labelit termistön kautta ---
$typeKeyMap = [
    'red'    => 'first_release',
    'yellow' => 'dangerous_situation',
    'green'  => 'investigation_report',
];
$typeKey   = $typeKeyMap[$flash['type']] ?? null;
$typeLabel = $typeKey ? sf_term($typeKey, $currentUiLang) : 'Safetyflash';

// --- Apu: generaattori lokirivin avataria varten (nimi -> initials) ---
function sf_avatar_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        if (mb_strlen($initials) >= 2) break;
    }
    return $initials ?: 'SF';
}
// ===== TOIMINTOJEN MÄÄRITYS KÄYTTÄJÄN ROOLIN JA TILAN MUKAAN =====
$currentUser = sf_current_user();
$roleId = (int)($currentUser['role_id'] ?? 0);
$currentUserId = (int)($currentUser['id'] ?? 0);
$createdBy = (int)($flash['created_by'] ?? 0);
$stateVal = $flash['state'] ?? 'draft';

$isOwner = ($currentUserId > 0 && $createdBy === $currentUserId);
$isAdmin = ($roleId === 1);
$isSafety = ($roleId === 3);
$isComms = ($roleId === 4);

$actions = [];

// Check if archived - if so, disable most actions
$isArchived = !empty($flash['is_archived']);

// Kommentointi kaikille kirjautuneille (ei arkistoiduille)
if (!$isArchived) {
    $actions[] = 'comment';
}

// If archived, no further actions allowed
if ($isArchived) {
    // Archived flashes cannot be edited or modified
    // Only viewing is allowed
} else {
    // Määritä toiminnot tilan ja roolin mukaan
switch ($stateVal) {
    case 'draft':
        if ($isOwner || $isAdmin) {
            $actions[] = 'edit';
            $actions[] = 'delete';
            $actions[] = 'send_to_review';
        }
        break;

    case 'pending_review': 
        if ($isSafety || $isAdmin) {
            $actions[] = 'edit_inline'; // Muokkaa ilman tilamuutosta
            $actions[] = 'request';     // Palauta korjattavaksi
            $actions[] = 'comms';       // Lähetä viestintään
        }
        break;

case 'request_info': 
    if ($isOwner || $isAdmin) {
        $actions[] = 'edit';
        // send_to_review poistettu - se näkyy jo lomakkeen esikatselussa
    }
    break;

    case 'reviewed':
        if ($isSafety || $isAdmin) {
            $actions[] = 'edit_inline'; // Muokkaa ilman tilamuutosta
            $actions[] = 'comms';
        }
        break;

    case 'to_comms':
        if ($isComms || $isAdmin) {
            $actions[] = 'edit_inline'; // Viestinnän muokkaus
            $actions[] = 'publish';
            $actions[] = 'request';     // Palauta turvatiimille
        }
        // Turvatiimi voi myös muokata viestinnällä-tilassa
        if ($isSafety) {
            $actions[] = 'edit_inline';
        }
        break;

    case 'published': 
        if ($isAdmin) {
            $actions[] = 'edit';
        }
        if ($isComms) {
            $actions[] = 'edit_inline'; // Viestintä voi muokata julkaistua
        }
        // Add archive action for admin and safety team
        if (($isAdmin || $isSafety) && !$isArchived) {
            $actions[] = 'archive';
        }
        break;
}

// Poista duplikaatit
$actions = array_unique($actions);
// Admin voi aina poistaa
if ($isAdmin && ! in_array('delete', $actions)) {
    $actions[] = 'delete';
}
}

$hasActions = ! empty($actions);
$iconBase = $base .'/assets/img/icons/';
?>
<div class="view-container">
    <div class="view-back">
        <a
          href="<?= htmlspecialchars($base) ?>/index.php?page=list"
          class="btn-back"
          aria-label="<?= htmlspecialchars(sf_term('back_to_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        >
          ← <?= htmlspecialchars(sf_term('back_to_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>

    <div
      class="lang-switcher"
      role="tablist"
      aria-label="<?= htmlspecialchars(sf_term('view_languages_aria', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
    >
        <?php foreach ($supportedLangs as $langCode => $langData):
            $hasTranslation = isset($translations[$langCode]);
            $isActive = ($langCode === $currentLang);
            
            // LISÄTTY: Arkistoidussa näytetään vain olemassa olevat käännökset
            if ($isArchived && ! $hasTranslation) {
                continue; // Ohita puuttuvat käännökset arkistoidussa
            }
        ?>
            <div class="lang-chip <?= $isActive ? 'active' : '' ?> <?= $hasTranslation ? 'has-version' : 'no-version' ?>" role="button" tabindex="0">
                <?php if ($hasTranslation): ?>
                    <a href="index.php?page=view&id=<?= (int)$translations[$langCode] ?>" class="lang-link">
                        <img class="lang-flag-img" src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($langData['icon']) ?>" alt="<?= htmlspecialchars($langData['label']) ?>">
                        <span class="lang-label"><?= htmlspecialchars($langData['label']) ?></span>
                    </a>
                <?php elseif (! $isArchived): ?>
                    <!-- Näytä + -nappi vain jos EI arkistoitu -->
                    <button type="button" class="lang-add-button" data-lang="<?= htmlspecialchars($langCode) ?>" data-base-id="<?= (int)$currentId ?>" onclick="sfAddTranslation(this)">
                        <img class="lang-flag-img" src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($langData['icon']) ?>" alt="<?= htmlspecialchars($langData['label']) ?>">
                        <span class="lang-label">+ <?= htmlspecialchars($langData['label']) ?></span>
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- UUSI RAKENNE ALKAA TÄSTÄ -->
    <div class="view-layout">
        <!-- Vasen palsta -->
        <div class="view-left">
            <div class="view-box preview-box">
                <!-- Loading spinner for preview image -->
                <div class="preview-loading-spinner" id="previewSpinner">
                    <div class="spinner"></div>
                    <span class="spinner-text"><?= htmlspecialchars(sf_term('loading', $currentUiLang) ?: 'Ladataan...', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                
                <?php if ($flash['type'] === 'green' && $hasSecondCard): ?>
                    <!-- TUTKINTATIEDOTE: Välilehdet kahdelle kortille -->
                    <div class="sf-view-preview-tabs" id="sfViewPreviewTabs">
                        <button type="button"
                                class="sf-view-tab-btn active"
                                data-target="preview1">
                            <?= htmlspecialchars(sf_term('card_1_summary', $currentUiLang) ?? '1. Yhteenveto', ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="button"
                                class="sf-view-tab-btn"
                                data-target="preview2">
                            <?= htmlspecialchars(sf_term('card_2_investigation', $currentUiLang) ?? '2. Juurisyyt & toimenpiteet', ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>

                    <div class="sf-view-preview-cards">
                        <div class="sf-view-preview-card active" id="viewPreview1">
                            <img src="<?= htmlspecialchars($previewUrl) ?>" alt="Preview kortti 1"
                                 class="preview-image" id="viewPreviewImage1"
                                 loading="eager">
                        </div>
                        <div class="sf-view-preview-card" id="viewPreview2" style="display:none;">
                            <?php if ($previewUrl2): ?>
                                <img src="<?= htmlspecialchars($previewUrl2) ?>" alt="Preview kortti 2"
                                     class="preview-image" id="viewPreviewImage2"
                                     loading="lazy"
                                     decoding="async">
                            <?php else: ?>
                                <div class="sf-preview-placeholder">
                                    <p>
                                        <?= htmlspecialchars(
                                            sf_term('preview_2_not_generated', $currentUiLang)
                                            ?? 'Kortin 2 preview-kuvaa ei ole vielä generoitu.',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Kaksi latausnappia vierekkäin (EI dropdown) -->
                    <!-- Note: Already inside hasSecondCard block, but double-check both files exist -->
                    <?php if (!empty($flash['preview_filename']) && $previewUrl2): ?>
                    <div class="sf-download-buttons">
                        <a href="<?= htmlspecialchars($previewUrl) ?>" 
                           download="<?= htmlspecialchars(sf_generate_download_filename($flash, 1)) ?>"
                           class="sf-btn-download">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <polyline points="7 10 12 15 17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <line x1="12" y1="15" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span><?= htmlspecialchars(sf_term('card_1_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                        <a href="<?= htmlspecialchars($previewUrl2) ?>" 
                           download="<?= htmlspecialchars(sf_generate_download_filename($flash, 2)) ?>"
                           class="sf-btn-download">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <polyline points="7 10 12 15 17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <line x1="12" y1="15" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span><?= htmlspecialchars(sf_term('card_2_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- NORMAALI: Yksi preview-kuva (red/yellow tai green ilman toista korttia) -->
                    <img src="<?= htmlspecialchars($previewUrl) ?>" alt="Preview"
                         class="preview-image" id="viewPreviewImage"
                         loading="eager">

                    <?php if (!empty($flash['preview_filename'])): ?>
                        <div class="preview-download-wrapper">
                            <a href="<?= htmlspecialchars($previewUrl) ?>"
                               download="<?= htmlspecialchars(sf_generate_download_filename($flash)) ?>"
                               class="btn-download-preview"
                               title="<?= htmlspecialchars(sf_term('download_preview', $currentUiLang) ?? 'Lataa kuva', ENT_QUOTES, 'UTF-8') ?>">
                                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"
                                          stroke="currentColor" stroke-width="2"
                                          stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                    <polyline points="7 10 12 15 17 10"
                                              stroke="currentColor" stroke-width="2"
                                              stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                    <line x1="12" y1="15" x2="12" y2="3"
                                          stroke="currentColor" stroke-width="2"
                                          stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span>
                                    <?= htmlspecialchars(sf_term('download_preview', $currentUiLang) ?? 'Lataa JPG', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div> <!-- .preview-box -->

            <div class="sf-log-panel log-box view-box" aria-live="polite">
                <h2 class="section-heading section-heading-log">
                    <span class="section-heading-icon" aria-hidden="true">
                        <!-- Kello / historia-ikoni -->
                        <svg viewBox="0 0 24 24" focusable="false">
                            <circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.6"/>
                            <path d="M12 8v4l3 2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <span class="section-heading-text">
                        <?= htmlspecialchars(sf_term('log_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </h2>

                <?php if (empty($logs)): ?>
                    <p class="sf-log-empty">
                        <?= htmlspecialchars(sf_term('log_empty', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php else: ?>
                    <ul class="sf-log-list">
                        <?php foreach ($logs as $logRow):
                            $first = trim((string)($logRow['first_name'] ?? ''));
                            $last  = trim((string)($logRow['last_name'] ?? ''));
                            $fullName = trim($first . ' ' . $last);
                            $avatarTxt = sf_avatar_initials($fullName);

                            $eventKey   = $logRow['event_type'] ?? 'UNKNOWN_EVENT';
                            $eventLabel = sf_term($eventKey, $currentUiLang);

                            $descRaw = $logRow['description'] ?? '';

// Käännä lokikuvaus - tukee pipe-erotettua ja monikielistä muotoa
$descToShow = '';

// Käsittele monirivinen kuvaus (rivit erotettu \n:llä)
$lines = explode("\n", $descRaw);

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    
    $translatedLine = $line;
    
    if (preg_match('/^(log_\w+)\|status:(\w+)$/', $line, $matches)) {
        $logKey = $matches[1];
        $statusKey = $matches[2];
        
        // sf_term ja sf_status_label palauttavat aina string (joko käännöksen tai alkuperäisen avaimen)
        $logTranslated = sf_term($logKey, $currentUiLang);
        $statusTranslated = sf_status_label($statusKey, $currentUiLang);
        
        $translatedLine = $logTranslated . ': ' . $statusTranslated;
    }
    // UUSI:  Tarkista moni-parametri pipe-muoto: log_distribution_sent|countries:fi|details: Suomi: 1 vastaanottajaa
    elseif (preg_match('/^(log_\w+)\|(.+\|.+)$/', $line, $matches)) {
        $logKey = $matches[1];
        $paramsStr = $matches[2];
        
        // Käännä log-avain
        $logTranslated = sf_term($logKey, $currentUiLang);
        
$params = [];
$paramParts = explode('|', $paramsStr);

foreach ($paramParts as $part) {
    if (strpos($part, ':') !== false) {
        [$key, $val] = array_map('trim', explode(':', $part, 2));
        $params[$key] = $val;
    }
}
        
        // Muodosta siisti teksti - käytä details jos löytyy
        if (isset($params['details']) && $params['details'] !== '') {
            $translatedLine = $logTranslated . ': ' .  $params['details'];
        } else {
            $translatedLine = $logTranslated;
        }
    }
    // Tarkista kaksoispiste-muoto: log_status_set: published
    elseif (preg_match('/^(log_\w+):\s*(\w+)$/', $line, $matches)) {
        $logKey = $matches[1];
        $value = $matches[2];
        
        // sf_term ja sf_status_label palauttavat aina string (joko käännöksen tai alkuperäisen avaimen)
        $logTranslated = sf_term($logKey, $currentUiLang);
        
        // Yritä kääntää arvo statuksena ensin
        $valueTranslated = sf_status_label($value, $currentUiLang);
        
        // Jos status-käännös palautti saman avaimen, kokeile sf_term
        if ($valueTranslated === $value) {
            $valueTranslated = sf_term($value, $currentUiLang);
        }
        
        $translatedLine = $logTranslated . ': ' . $valueTranslated;
    }
    // Tarkista label-muoto:  log_comment_label: kommenttiteksti
    elseif (preg_match('/^(log_\w+_label):\s*(.+)$/', $line, $matches)) {
        $labelKey = $matches[1];
        $text = $matches[2];
        
        // sf_term palauttaa aina string (joko käännöksen tai alkuperäisen avaimen)
        $labelTranslated = sf_term($labelKey, $currentUiLang);
        
        $translatedLine = $labelTranslated .  ': ' . $text;
    }
    // Yksinkertainen log-avain ilman parametreja
    elseif (preg_match('/^log_\w+$/', $line)) {
        $translated = sf_term($line, $currentUiLang);
        if ($translated !== $line) {
            $translatedLine = $translated;
        }
    }
    // Vanha muoto "Label: teksti" - yritä kääntää label-osa
    elseif (strpos($line, ': ') !== false) {
        $parts = explode(': ', $line, 2);
        $labelKey = $parts[0];
        
        // sf_term palauttaa aina string (joko käännöksen tai alkuperäisen avaimen)
        $labelTranslated = sf_term($labelKey, $currentUiLang);
        
        // Käytä käännöstä jos se eroaa avaimesta, muuten pidä alkuperäinen rivi
        if ($labelTranslated !== $labelKey) {
            $translatedLine = $labelTranslated . ': ' .  ($parts[1] ?? '');
        }
    }    // Yritä kääntää koko rivi avaimena
    else {
        $translated = sf_term($line, $currentUiLang);
        if ($translated !== $line) {
            $translatedLine = $translated;
        }
    }
    
    if ($descToShow !== '') {
        $descToShow .= "\n";
    }
    $descToShow .= $translatedLine;
}

                            // Korvaa statukset pilleiksi ja suojaa perussisältö
                            $descProcessed = function_exists('sf_log_status_replace')
                                ? sf_log_status_replace($descToShow, $currentUiLang)
                                : htmlspecialchars($descToShow);

                            // Sallitaan vain pillien span-tagit
                            $descAllowed = strip_tags($descProcessed, '<span>');

                            // Korostetaan eri viestityypit (kommentti, viesti viestinnälle, palautus)
                            $messageLabels = [
                                'comment' => [
                                    'fi' => 'Kommentti:',
                                    'sv' => 'Kommentar:',
                                    'en' => 'Comment:',
                                    'it' => 'Commento:',
                                    'el' => 'Σχόλιο:',
                                ],
                                'comms' => [
                                    'fi' => 'Viesti viestinnälle:',
                                    'sv' => 'Meddelande till kommunikation:',
                                    'en' => 'Message to communications:',
                                    'it' => 'Messaggio alla comunicazione:',
                                    'el' => 'Μήνυμα προς επικοινωνία:',
                                ],
                                'return' => [
                                    'fi' => 'Palautteen syy:',
                                    'sv' => 'Anledning till återkoppling:',
                                    'en' => 'Reason for return:',
                                    'it' => 'Motivo del reso:',
                                    'el' => 'Λόγος επιστροφής:',
                                ],
                            ];

                            $descHighlighted = $descAllowed;

                            // Käy läpi kaikki viestityypit ja korvaa ne tyylitellyillä laatikoilla
                            foreach ($messageLabels as $msgType => $labels) {
                                $label = $labels[$currentUiLang] ?? $labels['en'];
                                $cssClass = 'sf-log-' . $msgType; // sf-log-comment, sf-log-comms, sf-log-return

                                $pattern = '/(^|\R)\s*' . preg_quote($label, '/') . '\s*(.+)$/u';
                                $descHighlighted = preg_replace(
                                    $pattern,
                                    '$1<div class="sf-log-message-box ' . $cssClass . '"><span class="sf-log-message-label">' . htmlspecialchars($label) . '</span> $2</div>',
                                    $descHighlighted
                                );
                            }
                            $plainDescLen = mb_strlen(strip_tags($descAllowed));
                            $needsMore    = $plainDescLen > 300;
                        ?>
                            <li class="sf-log-item" id="log-<?= (int)$logRow['id'] ?>">
                                <div class="sf-log-avatar" data-name="<?= htmlspecialchars($fullName) ?>">
                                    <?= htmlspecialchars($avatarTxt) ?>
                                </div>

                                <div>
                                    <div class="sf-log-header-row" role="group" aria-label="<?= htmlspecialchars($eventLabel) ?>">
                                        <span class="sf-log-type"><?= htmlspecialchars($eventLabel) ?></span>
                                        <span class="sf-log-time"><?= htmlspecialchars($logRow['created_at'] ?? '') ?></span>
                                    </div>

                                    <div class="sf-log-message<?= $needsMore ? '' : ' expanded' ?>">
                                        <?= nl2br($descHighlighted) ?>
                                    </div>

                                    <?php if ($needsMore): ?>
                                        <div
                                          class="sf-log-more"
                                          role="button"
                                          tabindex="0"
                                          aria-expanded="false"
                                        >
                                          <?= htmlspecialchars(sf_term('log_show_more', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="sf-log-meta" aria-hidden="false">
                                    <div class="sf-log-user">
                                        <?= $fullName !== '' ? htmlspecialchars($fullName) : htmlspecialchars(sf_term('log_system_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Oikea palsta -->
        <div class="view-right">
            <?php require __DIR__ . '/../partials/view_meta_box.php'; ?>
        </div>
    </div> <!-- .view-layout -->

</div> <!-- .view-container -->

<!-- ===== MODALIT ===== -->
<div class="sf-modal hidden" id="modalEdit" role="dialog" aria-modal="true" aria-labelledby="modalEditTitle">
    <div class="sf-modal-content">
        <h2 id="modalEditTitle">
            <?= htmlspecialchars(sf_term('modal_edit_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('modal_edit_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button
              type="button"
              class="sf-btn sf-btn-secondary"
              data-modal-close="modalEdit"
            >
              <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button
              type="button"
              class="sf-btn sf-btn-primary"
              id="modalEditOk"
            >
              <?= htmlspecialchars(sf_term('btn_ok_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<div class="sf-modal hidden" id="modalComment" role="dialog" aria-modal="true" aria-labelledby="modalCommentTitle">
    <div class="sf-modal-content">
        <h2 id="modalCommentTitle">
            <?= htmlspecialchars(sf_term('modal_comment_title', $currentUiLang) ?? 'Lisää kommentti', ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/comment.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>
            <label for="commentMessage">
                <?= htmlspecialchars(sf_term('modal_comment_label', $currentUiLang) ?? 'Kommentti', ENT_QUOTES, 'UTF-8') ?>
            </label>
            <textarea
              id="commentMessage"
              name="message"
              rows="4"
              placeholder="<?= htmlspecialchars(sf_term('modal_comment_placeholder', $currentUiLang) ?? '', ENT_QUOTES, 'UTF-8') ?>"
            ></textarea>
            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalComment"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                  <?= htmlspecialchars(sf_term('btn_comment_send', $currentUiLang) ?? 'Tallenna kommentti', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="sf-modal hidden" id="modalRequestInfo" role="dialog" aria-modal="true" aria-labelledby="modalRequestInfoTitle">
    <div class="sf-modal-content">
        <h2 id="modalRequestInfoTitle">
            <?= htmlspecialchars(sf_term('modal_request_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/request_info.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>
            <label for="reqMessage">
                <?= htmlspecialchars(sf_term('modal_request_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <textarea
              id="reqMessage"
              name="message"
              rows="4"
              placeholder="<?= htmlspecialchars(sf_term('modal_request_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            ></textarea>
            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalRequestInfo"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                  <?= htmlspecialchars(sf_term('btn_send_request', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="sf-modal hidden" id="modalToComms" role="dialog" aria-modal="true" aria-labelledby="modalToCommsTitle">
    <div class="sf-modal-content">
        <h2 id="modalToCommsTitle">
            <?= htmlspecialchars(sf_term('modal_to_comms_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/send_to_comms.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>
            <label for="commsMessage">
                <?= htmlspecialchars(sf_term('modal_to_comms_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <textarea
              id="commsMessage"
              name="message"
              rows="4"
              placeholder="<?= htmlspecialchars(sf_term('modal_to_comms_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            ></textarea>
            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalToComms"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                  <?= htmlspecialchars(sf_term('btn_send_comms', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="sf-modal hidden" id="modalPublish" role="dialog" aria-modal="true" aria-labelledby="modalPublishTitle">
    <div class="sf-modal-content">
        <h2 id="modalPublishTitle">
            <?= htmlspecialchars(sf_term('modal_publish_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('modal_publish_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        
        <!-- Julkaisuvaihtoehdot -->
        <form id="publishForm" method="POST" action="<?= htmlspecialchars($base) ?>/app/actions/publish.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>
            <div class="sf-publish-options">
                <!-- Lähetä jakeluryhmälle -->
                <label class="sf-checkbox-option">
                    <input type="checkbox" name="send_to_distribution" id="publishSendDistribution" value="1">
                    <span class="sf-checkbox-label">
                        <strong><?= htmlspecialchars(sf_term('publish_send_to_distribution', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></strong>
                        <small><?= htmlspecialchars(sf_term('publish_send_to_distribution_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></small>
                    </span>
                </label>
                
                <!-- Maakohtainen jakelu -->
                <div class="sf-country-selection" id="countrySelectionDiv" style="display:none;">
                    <label class="sf-label"><?= htmlspecialchars(sf_term('publish_select_countries', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
                    <div class="sf-country-flags">
                        <?php 
                        $distributionCountries = [
                            'fi' => ['label_key' => 'country_finland', 'icon' => 'finnish-flag.png', 'default' => true],
                            'sv' => ['label_key' => 'country_sweden', 'icon' => 'swedish-flag.png', 'default' => false],
                            'en' => ['label_key' => 'country_uk', 'icon' => 'english-flag.png', 'default' => false],
                            'it' => ['label_key' => 'country_italy', 'icon' => 'italian-flag.png', 'default' => false],
                            'el' => ['label_key' => 'country_greece', 'icon' => 'greece-flag.png', 'default' => false],
                        ];
                        
                        foreach ($distributionCountries as $countryCode => $countryData):
                        ?>
                            <label class="sf-flag-chip">
                                <input type="checkbox" 
                                       name="distribution_countries[]" 
                                       value="<?= htmlspecialchars($countryCode) ?>"
                                       <?= $countryData['default'] ? 'checked' : '' ?>>
                                <img src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($countryData['icon']) ?>" 
                                     alt="<?= htmlspecialchars(sf_term($countryData['label_key'], $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Henkilövahinkoja - VAIN punaisille -->
                <?php if (($flash['type'] ?? '') === 'red'): ?>
                <label class="sf-checkbox-option sf-checkbox-warning">
                    <input type="checkbox" name="has_personal_injury" id="publishPersonalInjury" value="1">
                    <span class="sf-checkbox-label">
                        <strong>⚠️ <?= htmlspecialchars(sf_term('publish_personal_injury', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></strong>
                        <small><?= htmlspecialchars(sf_term('publish_personal_injury_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></small>
                    </span>
                </label>
                <?php endif; ?>
                
                <!-- Otsikon esikatselu -->
                <div class="sf-email-subject-preview" id="emailSubjectPreview" style="display:none;">
                    <strong><?= htmlspecialchars(sf_term('publish_subject_preview', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
                    <code id="emailSubjectText"></code>
                </div>
            </div>
            
            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalPublish"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                  <?= htmlspecialchars(sf_term('btn_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript otsikon esikatseluun -->
<script>
(function() {
    const distributionCheckbox = document.getElementById('publishSendDistribution');
    const injuryCheckbox = document.getElementById('publishPersonalInjury');
    const previewDiv = document.getElementById('emailSubjectPreview');
    const subjectText = document.getElementById('emailSubjectText');
    const countryDiv = document.getElementById('countrySelectionDiv');
    
    if (!distributionCheckbox || !previewDiv) return;
    
    const flashType = <?= json_encode($flash['type'] ?? 'yellow', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const flashTitle = <?= json_encode($flash['title'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const flashSite = <?= json_encode($flash['worksite'] ?? $flash['site'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    
    const typeLabels = {
        red: '🔴 <?= addslashes(sf_term('email_type_red', $currentUiLang)) ?>',
        yellow: '🟡 <?= addslashes(sf_term('email_type_yellow', $currentUiLang)) ?>',
        green: '🟢 <?= addslashes(sf_term('email_type_green', $currentUiLang)) ?>'
    };
    
    const injuryPrefix = '⚠️ <?= addslashes(sf_term('email_personal_injury_warning', $currentUiLang)) ?>';
    
    function updatePreview() {
        const showPreview = distributionCheckbox.checked;
        previewDiv.style.display = showPreview ? 'block' : 'none';
        
        // Show/hide country selection
        if (countryDiv) {
            countryDiv.style.display = showPreview ? 'block' : 'none';
        }
        
        if (!showPreview) return;
        
        let parts = [];
        
        // Henkilövahinko-varoitus (vain jos valittu ja punainen)
        if (injuryCheckbox && injuryCheckbox.checked && flashType === 'red') {
            parts.push(injuryPrefix);
        }
        
        // Tyyppi
        parts.push(typeLabels[flashType] || typeLabels.yellow);
        
        // Otsikko
        if (flashTitle) {
            parts.push(flashTitle);
        }
        
        // Työmaa
        if (flashSite) {
            parts.push('(' + flashSite + ')');
        }
        
        subjectText.textContent = parts.join(' - ');
    }
    
    distributionCheckbox.addEventListener('change', updatePreview);
    if (injuryCheckbox) {
        injuryCheckbox.addEventListener('change', updatePreview);
    }
    
    updatePreview();
})();
</script>

<div class="sf-modal hidden" id="modalDelete" role="dialog" aria-modal="true" aria-labelledby="modalDeleteTitle">
    <div class="sf-modal-content">
        <h2 id="modalDeleteTitle">
            <?= htmlspecialchars(sf_term('modal_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <div id="deleteModalContent">
            <!-- Content will be populated by JavaScript -->
            <p>
                <?= htmlspecialchars(sf_term('modal_delete_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/delete.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>
            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalDelete"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-danger">
                  <?= htmlspecialchars(sf_term('btn_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Store flash data for delete modal
window.sfFlashData = {
    id: <?= (int)$id ?>,
    translationGroupId: <?= !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : 'null' ?>,
    lang: '<?= htmlspecialchars($flash['lang'] ?? 'fi', ENT_QUOTES, 'UTF-8') ?>',
    isTranslation: <?= !empty($flash['translation_group_id']) ? 'true' : 'false' ?>,
    uiLang: '<?= htmlspecialchars($currentUiLang, ENT_QUOTES, 'UTF-8') ?>'
};

// Translation terms for JavaScript
window.sfDeleteTerms = {
    delete_original_confirm_title: <?= json_encode(sf_term('delete_original_confirm_title', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_original_confirm_message: <?= json_encode(sf_term('delete_original_confirm_message', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_original_versions_count: <?= json_encode(sf_term('delete_original_versions_count', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_translation_confirm_title: <?= json_encode(sf_term('delete_translation_confirm_title', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_translation_confirm_message: <?= json_encode(sf_term('delete_translation_confirm_message', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_translation_which: <?= json_encode(sf_term('delete_translation_which', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_fi: <?= json_encode(sf_term('lang_name_fi', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_sv: <?= json_encode(sf_term('lang_name_sv', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_en: <?= json_encode(sf_term('lang_name_en', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_it: <?= json_encode(sf_term('lang_name_it', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_el: <?= json_encode(sf_term('lang_name_el', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>
};

// Update delete modal content when opened
document.addEventListener('DOMContentLoaded', function() {
    const deleteButtons = document.querySelectorAll('[data-modal-open="modalDelete"]');
    
    deleteButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            updateDeleteModalContent();
        });
    });
});

function updateDeleteModalContent() {
    const modalTitle = document.getElementById('modalDeleteTitle');
    const modalContent = document.getElementById('deleteModalContent');
    const flashData = window.sfFlashData;
    const terms = window.sfDeleteTerms;
    
    if (!flashData.isTranslation) {
        // Deleting original - check for translations
        const groupId = flashData.id;
        
        // Fetch translations via AJAX
        const url = new URL('<?= htmlspecialchars($base) ?>/app/api/get_flash_translations.php', window.location.origin);
        url.searchParams.set('group_id', groupId);
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.ok && data.translations && Object.keys(data.translations).length > 0) {
                    // Has translations - show warning
                    const count = Object.keys(data.translations).length;
                    const langNames = [];
                    
                    for (const lang in data.translations) {
                        const langKey = 'lang_name_' + lang;
                        if (terms[langKey]) {
                            langNames.push(terms[langKey]);
                        }
                    }
                    
                    modalTitle.textContent = terms.delete_original_confirm_title;
                    
                    let html = '<p style="margin-bottom: 1rem;">' + terms.delete_original_confirm_message + '</p>';
                    html += '<p style="margin-bottom: 0.5rem;"><strong>' + terms.delete_original_versions_count.replace('%d', count) + '</strong></p>';
                    html += '<ul style="margin-left: 1.5rem;">';
                    langNames.forEach(name => {
                        html += '<li>' + name + '</li>';
                    });
                    html += '</ul>';
                    
                    modalContent.innerHTML = html;
                } else {
                    // No translations - use default message
                    modalTitle.textContent = terms.delete_original_confirm_title;
                    modalContent.innerHTML = '<p>' + terms.delete_original_confirm_message + '</p>';
                }
            })
            .catch(error => {
                console.error('Error fetching translations:', error);
                // Fallback to default message
                modalTitle.textContent = terms.delete_original_confirm_title;
                modalContent.innerHTML = '<p>' + terms.delete_original_confirm_message + '</p>';
            });
    } else {
        // Deleting translation
        const langKey = 'lang_name_' + flashData.lang;
        const langName = terms[langKey] || flashData.lang;
        
        modalTitle.textContent = terms.delete_translation_confirm_title;
        
        let html = '<p style="margin-bottom: 1rem;">' + terms.delete_translation_confirm_message + '</p>';
        html += '<p><strong>' + terms.delete_translation_which.replace('%s', langName) + '</strong></p>';
        
        modalContent.innerHTML = html;
    }
}
</script>

<!-- ARKISTOI-MODAALI -->
<div class="sf-modal hidden" id="modalArchive" role="dialog" aria-modal="true" aria-labelledby="modalArchiveTitle">
    <div class="sf-modal-content">
        <h2 id="modalArchiveTitle">
            <?= htmlspecialchars(sf_term('archive_confirm_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('archive_confirm_message', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button
              type="button"
              class="sf-btn sf-btn-secondary"
              data-modal-close="modalArchive"
            >
              <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="modalArchiveConfirm">
              <?= htmlspecialchars(sf_term('btn_archive', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- KIELIVERSIO-MODAALI (vaiheittainen) -->
<div class="sf-modal hidden" id="modalTranslation" role="dialog" aria-modal="true" aria-labelledby="modalTranslationTitle">
    <div class="sf-modal-content sf-modal-translation">
        
        <!-- VAIHE 1: Lomake -->
        <div class="sf-translation-step" id="translationStep1">
            <h2 id="modalTranslationTitle">
                <?php echo htmlspecialchars(sf_term('modal_translation_title', $currentUiLang) ?? 'Luo kieliversio', ENT_QUOTES, 'UTF-8'); ?>
            </h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step active">1</span>
                <span class="sf-step-line"></span>
                <span class="sf-step">2</span>
            </div>

            <form id="translationForm">
                <input type="hidden" name="source_id" value="<?php echo (int)$flash['id']; ?>">
                <input type="hidden" name="target_lang" id="translationTargetLang" value="">
                
                <div class="sf-field">
                    <label class="sf-label">
                        <?php echo htmlspecialchars(sf_term('translation_target_lang', $currentUiLang) ?? 'Kohdekieli', ENT_QUOTES, 'UTF-8'); ?>
                    </label>
                    <div class="sf-translation-lang-display" id="translationLangDisplay"></div>
                </div>

                <div class="sf-field">
                    <label for="translationTitleShort" class="sf-label">
                        <?php echo htmlspecialchars(sf_term('short_title_label', $currentUiLang) ?? 'Lyhyt kuvaus', ENT_QUOTES, 'UTF-8'); ?> *
                    </label>
                    <textarea 
                        name="title_short" 
                        id="translationTitleShort" 
                        class="sf-textarea" 
                        rows="2" 
                        maxlength="125"
                        required
                    ></textarea>
                    <div class="sf-char-count"><span id="titleCharCount">0</span>/125</div>
                </div>

                <div class="sf-field">
                    <label for="translationDescription" class="sf-label">
                        <?php echo htmlspecialchars(sf_term('description_label', $currentUiLang) ?? 'Kuvaus', ENT_QUOTES, 'UTF-8'); ?> *
                    </label>
                    <textarea 
                        name="description" 
                        id="translationDescription" 
                        class="sf-textarea" 
                        rows="5"
                        maxlength="900"
                        required
                    ></textarea>
                    <div class="sf-char-count"><span id="descCharCount">0</span>/900</div>
                </div>

                <?php if ($flash['type'] === 'green'): ?>
                    <div class="sf-field">
                        <label for="translationRootCauses" class="sf-label">
                            <?php echo htmlspecialchars(sf_term('root_cause_label', $currentUiLang) ?? 'Juurisyyt', ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                        <textarea name="root_causes" id="translationRootCauses" class="sf-textarea" rows="3"></textarea>
                    </div>

                    <div class="sf-field">
                        <label for="translationActions" class="sf-label">
                            <?php echo htmlspecialchars(sf_term('actions_label', $currentUiLang) ?? 'Toimenpiteet', ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                        <textarea name="actions" id="translationActions" class="sf-textarea" rows="3"></textarea>
                    </div>
                <?php endif; ?>
            </form>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalTranslation">
                    <?php echo htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8'); ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnToStep2">
                    <?php echo htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8'); ?> →
                </button>
            </div>
        </div>

        <!-- VAIHE 2: Esikatselu -->
        <div class="sf-translation-step hidden" id="translationStep2">
            <h2>
                <?php echo htmlspecialchars(sf_term('preview_and_save', $currentUiLang) ?? 'Esikatselu ja tallennus', ENT_QUOTES, 'UTF-8'); ?>
            </h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step active">2</span>
            </div>

            <div class="sf-translation-preview-wrapper">
                <div id="sfTranslationPreviewContainer">
                    <?php require __DIR__ .'/../partials/preview_modal.php'; ?>
                </div>
            </div>

            <div id="translationStatus" class="sf-translation-status"></div>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" id="btnBackToStep1">
                    ← <?php echo htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8'); ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnSaveTranslation">
                    <?php echo htmlspecialchars(sf_term('btn_save_translation', $currentUiLang) ?? 'Tallenna kieliversio', ENT_QUOTES, 'UTF-8'); ?>
                </button>
            </div>
        </div>

    </div>
</div>


<!-- ===== FOOTER ACTION BAR (tulostetaan vain jos toimintoja on) ===== -->
<?php if ($hasActions): ?>
<div class="view-footer-actions" role="toolbar" aria-label="Toiminnot">
    <div class="view-footer-buttons-4col">

        <?php if (in_array('comment', $actions)): ?>
            <button class="footer-btn fb-comment" id="footerComment" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_comment', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $iconBase ?>comment_icon.svg" alt="" class="footer-icon">
                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_comment', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        <?php endif; ?>

        <?php if (in_array('edit', $actions)): ?>
            <button class="footer-btn fb-edit" id="footerEdit" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $iconBase ?>edit_icon.svg" alt="" class="footer-icon">
                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        <?php endif; ?>

        <?php if (in_array('edit_inline', $actions)): ?>
            <button class="footer-btn fb-edit" id="footerEditInline" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $iconBase ?>edit_icon.svg" alt="" class="footer-icon">
                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        <?php endif; ?>

        <?php if (in_array('edit_comms', $actions)): ?>
            <button class="footer-btn fb-edit" id="footerEditComms" type="button" 
                    data-edit-mode="comms"
                    aria-label="<?= htmlspecialchars(sf_term('footer_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $iconBase ?>edit_icon.svg" alt="" class="footer-icon">
                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        <?php endif; ?>

        <?php if (in_array('delete', $actions)): ?>
             <button class="footer-btn fb-delete" id="footerDelete" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $iconBase ?>delete_icon.svg" alt="" class="footer-icon">
                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        <?php endif; ?>
        
        <?php if (in_array('request', $actions)): ?>
            <button class="footer-btn fb-request" id="footerRequest" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_return', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $iconBase ?>reverse_icon.svg" alt="" class="footer-icon">
                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_return', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        <?php endif; ?>

        <?php if (in_array('comms', $actions)): ?>
             <button class="footer-btn fb-comms" id="footerComms" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_to_comms', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $iconBase ?>communications_icon.svg" alt="" class="footer-icon">
                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_to_comms', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        <?php endif; ?>

        <?php if (in_array('publish', $actions)): ?>
            <button class="footer-btn fb-publish" id="footerPublish" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $iconBase ?>publish_icon.svg" alt="" class="footer-icon">
                <span class="btn-label"><?= htmlspecialchars(sf_term('footer_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        <?php endif; ?>

        <?php if (in_array('archive', $actions)): ?>
            <button class="footer-btn fb-archive" id="footerArchive" type="button" aria-label="<?= htmlspecialchars(sf_term('btn_archive', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $iconBase ?>archive_icon.svg" alt="" class="footer-icon">
                <span class="btn-label"><?= htmlspecialchars(sf_term('btn_archive', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        <?php endif; ?>

<?php if (in_array('send_to_review', $actions)): ?>
    <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/send_to_review.php?id=<?= (int) $id ?>" class="footer-form">
        <?= sf_csrf_field() ?>
        <button class="footer-btn fb-comms" type="submit">
                    <img src="<?= $iconBase ?>communications_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_send_to_review', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            </form>
        <?php endif; ?>

    </div>
</div>
<?php endif; // End of hasActions check ?>

<!-- html2canvas tarvitaan kuvan generointiin -->
<script src="<?php echo htmlspecialchars($base); ?>/assets/js/vendor/html2canvas.min.js"></script>

<!-- Safetyflash CSS & JS -->
<link rel="stylesheet" href="<?php echo htmlspecialchars($base); ?>/assets/css/preview.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars($base); ?>/assets/css/copy-to-clipboard.css">
<script src="<?php echo htmlspecialchars($base); ?>/assets/js/view.js"></script>
<script src="<?php echo htmlspecialchars($base); ?>/assets/js/translation.js"></script>
<script src="<?php echo htmlspecialchars($base); ?>/assets/js/copy-to-clipboard.js"></script>

<!-- Sivukohtaiset datat -->
<script>
window.SF_LOG_SHOW_MORE   = <?php echo json_encode(sf_term('log_show_more', $currentUiLang)); ?>;
window.SF_LOG_SHOW_LESS   = <?php echo json_encode(sf_term('log_show_less', $currentUiLang)); ?>;
window.SF_BASE_URL        = <?php echo json_encode($base); ?>;
window.SF_EDIT_URL        = <?php echo json_encode($editUrl); ?>;
window.SF_FLASH_DATA      = <?php echo json_encode($flashDataForJs); ?>;
window.SF_SUPPORTED_LANGS = <?php echo json_encode($supportedLangs); ?>;
window.SF_CSRF_TOKEN      = <?php echo json_encode(sf_csrf_token()); ?>;
window.SF_ARCHIVE_BTN_TEXT = <?php echo json_encode(sf_term('btn_archive', $currentUiLang)); ?>;
window.SF_ARCHIVING_TEXT  = <?php echo json_encode(sf_term('archiving_in_progress', $currentUiLang) ?: 'Archiving...'); ?>;

// Käännökset translation.js:lle - kaikki tuetut kielet
window.SF_TRANSLATIONS = {
    metaLabels: {
        fi: { site: <?php echo json_encode(sf_term('preview_meta_site', 'fi')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'fi')); ?> },
        sv: { site: <?php echo json_encode(sf_term('preview_meta_site', 'sv')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'sv')); ?> },
        en: { site: <?php echo json_encode(sf_term('preview_meta_site', 'en')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'en')); ?> },
        it: { site: <?php echo json_encode(sf_term('preview_meta_site', 'it')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'it')); ?> },
        el: { site: <?php echo json_encode(sf_term('preview_meta_site', 'el')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'el')); ?> }
    },
    messages: {
        validationFillRequired: <?php echo json_encode(sf_term('validation_fill_required', $currentUiLang)); ?>,
        generatingImage: <?php echo json_encode(sf_term('generating_image', $currentUiLang)); ?>,
        saving: <?php echo json_encode(sf_term('status_saving', $currentUiLang)); ?>,
        translationSaved: <?php echo json_encode(sf_term('translation_saved', $currentUiLang)); ?>,
        errorPrefix: <?php echo json_encode(sf_term('error_prefix', $currentUiLang)); ?>,
        saveTranslationButton: <?php echo json_encode(sf_term('save_translation_button', $currentUiLang)); ?>
    }
};

// Hide loading spinner and fade in preview image
document.addEventListener('DOMContentLoaded', function() {
    const previewSpinner = document.getElementById('previewSpinner');
    const previewImages = document.querySelectorAll('.preview-box .preview-image');
    
    if (previewSpinner && previewImages.length > 0) {
        // Function to hide spinner and show image with fade-in
        const showImageFn = function(img) {
            previewSpinner.classList.add('loaded');
            img.classList.add('loaded');
        };
        
        previewImages.forEach(function(img) {
            // If image is already loaded (from cache)
            if (img.complete && img.naturalHeight !== 0) {
                showImageFn(img);
            } else {
                // Wait for image to load
                img.addEventListener('load', function() {
                    showImageFn(img);
                });
                // Handle error - still hide spinner
                img.addEventListener('error', function() {
                    previewSpinner.classList.add('loaded');
                });
            }
        });
        
        // Fallback: show everything after 3 seconds regardless
        setTimeout(function() {
            previewSpinner.classList.add('loaded');
            previewImages.forEach(function(img) {
                img.classList.add('loaded');
            });
        }, 3000);
    }

    // ===== COPY TO CLIPBOARD BUTTONS =====
    if (window.SafetyFlashCopy) {
        // Load translations for copy buttons
        window.SF_I18N = window.SF_I18N || {};
        window.SF_I18N.copy_image = <?php echo json_encode(sf_term('copy_image', $currentUiLang)); ?>;
        window.SF_I18N.copying_image = <?php echo json_encode(sf_term('copying_image', $currentUiLang)); ?>;
        window.SF_I18N.image_copied = <?php echo json_encode(sf_term('image_copied', $currentUiLang)); ?>;
        window.SF_I18N.copy_failed = <?php echo json_encode(sf_term('copy_failed', $currentUiLang)); ?>;

        // Add copy button for card 1 (all flash types)
        const viewPreview1 = document.getElementById('viewPreview1');
        const viewPreviewImage = document.getElementById('viewPreviewImage');
        const previewBox = document.querySelector('.preview-box');
        
        if (viewPreview1) {
            // Tutkintatiedote with tabs - add button to card container
            window.SafetyFlashCopy.addCopyButton(viewPreview1, {
                label: window.SF_I18N.copy_image,
                copyingLabel: window.SF_I18N.copying_image,
                successMessage: window.SF_I18N.image_copied,
                errorMessage: window.SF_I18N.copy_failed,
                position: 'top-right'
            });
        } else if (viewPreviewImage && previewBox) {
            // Normal flash (red/yellow) - add button to preview-box
            window.SafetyFlashCopy.addCopyButton(previewBox, {
                label: window.SF_I18N.copy_image,
                copyingLabel: window.SF_I18N.copying_image,
                successMessage: window.SF_I18N.image_copied,
                errorMessage: window.SF_I18N.copy_failed,
                position: 'top-right'
            });
        }

        // Add copy button for card 2 (tutkintatiedote only, if exists)
        const viewPreview2 = document.getElementById('viewPreview2');
        if (viewPreview2 && viewPreview2.querySelector('img')) {
            window.SafetyFlashCopy.addCopyButton(viewPreview2, {
                label: window.SF_I18N.copy_image,
                copyingLabel: window.SF_I18N.copying_image,
                successMessage: window.SF_I18N.image_copied,
                errorMessage: window.SF_I18N.copy_failed,
                position: 'top-right'
            });
        }
    }
});
</script>