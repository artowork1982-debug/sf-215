<?php
// app/pages/form.php
// THE COMPLETE, UNTRUNCATED, AND CORRECTED FILE
declare(strict_types=1);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/statuses.php';

$base = rtrim($config['base_url'] ?? '/', '/');

// --- Työmaat kannasta (sf_worksites) ---
$worksites = [];

try {
    $worksites = Database::fetchAll(
        "SELECT id, name FROM sf_worksites WHERE is_active = 1 ORDER BY name ASC"
    );
} catch (Throwable $e) {
    error_log('form.php worksites error: ' . $e->getMessage());
    $worksites = [];
}

// --- Tutkintatiedotteen pohjana olevat julkaistut ensitiedotteet / vaaratilanteet ---
$relatedOptions = [];

try {
    $relatedOptions = Database::fetchAll("
        SELECT id, type, title, title_short, site, site_detail, description, 
               occurred_at, image_main, image_2, image_3,
               annotations_data, image1_transform, image2_transform, image3_transform,
               grid_layout, grid_bitmap, lang
        FROM sf_flashes
        WHERE state = 'published' 
          AND type IN ('red', 'yellow')
          AND (translation_group_id IS NULL OR translation_group_id = id)
        ORDER BY occurred_at DESC
    ");
} catch (Throwable $e) {
    error_log('form.php load related flashes error: ' . $e->getMessage());
}

// --- Jos muokkaus (id annettu), ladataan tietue PDO:lla ja esitäytetään kentät ---
$editing = false;
$flash   = [];
$editId  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$commsEditMode = isset($_GET['mode']) && $_GET['mode'] === 'comms';
$inlineEditMode = isset($_GET['mode']) && $_GET['mode'] === 'inline';

if ($editId > 0) {
    try {
        $flash = Database::fetchOne(
            "SELECT * FROM sf_flashes WHERE id = :id LIMIT 1",
            [':id' => $editId]
        );
        if ($flash) {
            $editing = true;
        } else {
            $flash = [];
        }
    } catch (Throwable $e) {
        error_log('form.php load flash error: ' . $e->getMessage());
    }
}

// Check if user has unfinished drafts
$userDrafts = [];
$currentUser = sf_current_user();
if ($currentUser && !$editing) {
    try {
        $pdo = Database::getInstance();
        $draftStmt = $pdo->prepare("
            SELECT id, flash_type, form_data, updated_at 
            FROM sf_drafts 
            WHERE user_id = ? 
            ORDER BY updated_at DESC
        ");
        $draftStmt->execute([(int)$currentUser['id']]);
        $userDrafts = $draftStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('form.php drafts check error: ' . $e->getMessage());
    }
}
$hasDrafts = !empty($userDrafts);

$uiLang    = $_SESSION['ui_lang'] ?? 'fi';
$flashLang = $flash['language'] ?? 'fi';

$termsData = sf_get_terms_config();
$configLanguages = $termsData['languages'] ?? ['fi'];

if (!in_array($flashLang, $configLanguages, true)) {
    $flashLang = 'fi';
}
if (!in_array($uiLang, $configLanguages, true)) {
    $uiLang = 'fi';
}

$term = function (string $key) use ($termsData, $uiLang): string {
    $t = $termsData['terms'][$key][$uiLang] ?? $termsData['terms'][$key]['fi'] ?? $key;
    return (string) $t;
};

$sfI18n = [
    // Editor / yleiset (nämä avaimet löytyy zipin safetyflash_terms.php:stä sellaisenaan)
    'IMAGE_EDIT_MAIN' => $term('IMAGE_EDIT_MAIN'),
    'IMAGE_EDIT_EXTRA_PREFIX' => $term('IMAGE_EDIT_EXTRA_PREFIX'),
    'IMAGE_SAVED' => $term('IMAGE_SAVED'),
    'LABEL_PROMPT' => $term('LABEL_PROMPT'),

    // Grid-asettelut: terms-tiedostossa nämä ovat lowercase-avaimilla (zipin app/config/safetyflash_terms.php)
    'GRID_LAYOUT_1'  => $term('grid_layout_1'),
    'GRID_LAYOUT_2A' => $term('grid_layout_2a'),
    'GRID_LAYOUT_2B' => $term('grid_layout_2b'),
    'GRID_LAYOUT_3A' => $term('grid_layout_3a'),
    'GRID_LAYOUT_3B' => $term('grid_layout_3b'),
    'GRID_LAYOUT_3C' => $term('grid_layout_3c'),

    // Help-tekstit: zipin terms-tiedostossa on grid_help ja img_edit_help
    'GRID_HELP' => $term('grid_help'),
    'EDITOR_HELP_PLACE' => $term('img_edit_help'),
    
    // Progress-viestit
    'processing_flash' => $term('processing_flash'),
];
// --- Esitäytettävät arvot ---
$title            = $flash['title'] ?? '';
$title_short      = $flash['title_short'] ?? ($flash['summary'] ?? '');
$short_text       = $title_short;
$summary          = $flash['summary'] ?? '';
$description      = $flash['description'] ?? '';
$root_causes      = $flash['root_causes'] ?? '';
$actions          = $flash['actions'] ?? '';
$worksite_val     = $flash['site'] ?? '';
$site_detail_val  = $flash['site_detail'] ?? '';
$event_date_val   = !empty($flash['occurred_at']) ? date('Y-m-d\TH:i', strtotime($flash['occurred_at'])) : '';
$type_val         = $flash['type'] ?? '';
$state_val        = $flash['state'] ?? '';
$preview_filename = $flash['preview_filename'] ?? '';
$image_main       = $flash['image_main'] ?? '';

// Mahdolliset transform-arvot (JSON) kolmelle kuvalle
$image1_transform = $flash['image1_transform'] ?? '';
$image2_transform = $flash['image2_transform'] ?? '';
$image3_transform = $flash['image3_transform'] ?? '';

// initial step param (optional)
$initialStep = isset($_GET['step']) ? (int) $_GET['step'] : 1;

// Kuvapolku muokkaustilassa
$getImageUrl = function ($filename) use ($base) {
    $filename = is_string($filename) ? basename($filename) : '';
    if (empty($filename)) {
        return "{$base}/assets/img/camera-placeholder.png";
    }
    $path = "uploads/images/{$filename}";
    if (file_exists(__DIR__ . "/../../{$path}")) {
        return "{$base}/{$path}";
    }
    $oldPath = "img/{$filename}";
    if (file_exists(__DIR__ . "/../../{$oldPath}")) {
        return "{$base}/{$oldPath}";
    }
    return "{$base}/assets/img/camera-placeholder.png";
};
?>
<?php if ($hasDrafts && !$editing): ?>
<div id="sfDraftRecoveryOverlay" class="sf-draft-overlay">
    <div class="sf-draft-modal">
        <h2><?= htmlspecialchars(sf_term('draft_recovery_title', $uiLang)) ?></h2>
        <p><?= htmlspecialchars(sf_term('draft_recovery_message', $uiLang)) ?></p>
        
        <div class="sf-draft-list">
            <?php foreach ($userDrafts as $draft): 
                $draftData = json_decode($draft['form_data'], true);
                $draftType = $draft['flash_type'] ?? 'unknown';
                // Normalize: remove possible 'type_' prefix
                $draftType = preg_replace('/^type_/', '', $draftType);
                $draftDate = date('d.m.Y H:i', strtotime($draft['updated_at']));
            ?>
            <div class="sf-draft-item" data-draft-id="<?= (int)$draft['id'] ?>">
                <div class="sf-draft-info">
                    <span class="sf-draft-type sf-type-<?= in_array($draftType, ['red', 'yellow', 'green'], true) ? htmlspecialchars($draftType) : 'unknown' ?>">
<?php 
$typeLabels = [
    'red' => sf_term('first_release', $uiLang) ?: 'Ensitiedote',
    'yellow' => sf_term('dangerous_situation', $uiLang) ?: 'Vaaratilanne', 
    'green' => sf_term('investigation_report', $uiLang) ?: 'Tutkintatiedote',
];
$typeLabel = $typeLabels[$draftType] ?? ucfirst($draftType);
?>
<?= htmlspecialchars($typeLabel) ?>
                    </span>
                    <span class="sf-draft-date"><?= htmlspecialchars($draftDate) ?></span>
                </div>
                <div class="sf-draft-actions">
                    <button type="button" class="sf-btn sf-btn-primary sf-draft-continue" 
                            data-draft-id="<?= (int)$draft['id'] ?>">
                        <?= htmlspecialchars(sf_term('draft_continue', $uiLang)) ?>
                    </button>
                    <button type="button" class="sf-btn sf-btn-secondary sf-draft-discard"
                            data-draft-id="<?= (int)$draft['id'] ?>">
                        <?= htmlspecialchars(sf_term('draft_discard', $uiLang)) ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="sf-draft-new">
            <button type="button" class="sf-btn sf-btn-outline" id="sfDraftStartNew">
                <?= htmlspecialchars(sf_term('draft_start_new', $uiLang)) ?>
            </button>
        </div>
    </div>
</div>
<script>
window.SF_USER_DRAFTS = <?= json_encode($userDrafts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?php endif; ?>

<form
  id="sf-form"
  method="post"
  action="<?php echo $base; ?>/app/api/save_flash.php"
  class="sf-form"
  enctype="multipart/form-data"
  novalidate
>
  <?= sf_csrf_field() ?>
  <?php if ($editing): ?>
    <input type="hidden" name="id" value="<?= (int) $editId ?>">
  <?php endif; ?>
  <input type="hidden" id="initialStep" value="<?= (int) $initialStep ?>">
  
  <!-- Related flash ID tutkintatiedotteelle (päivittää alkuperäisen) -->
<input
  type="hidden"
  id="sf-related-flash-id"
  value="<?= htmlspecialchars($flash['related_flash_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
>

  <!-- Modern Navigable Progress Bar -->
  <nav class="sf-form-progress" aria-label="<?= htmlspecialchars(sf_term('form_progress_label', $uiLang) ?: 'Lomakkeen vaiheet', ENT_QUOTES, 'UTF-8') ?>">
    <div class="sf-form-progress__track" role="progressbar" aria-valuenow="<?= (int) $initialStep ?>" aria-valuemin="1" aria-valuemax="6">
      <div class="sf-form-progress__fill" id="sfProgressFill"></div>
    </div>
    <div class="sf-form-progress__steps">
      <button type="button" class="sf-form-progress__step" data-step="1" title="<?= htmlspecialchars(sf_term('step1_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step1_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
        <span class="sf-form-progress__number" aria-hidden="true" data-step-num="1"></span>
        <span class="sf-form-progress__label"><?= htmlspecialchars(sf_term('step1_short', $uiLang) ?: 'Tyyppi', ENT_QUOTES, 'UTF-8') ?></span>
      </button>
      <button type="button" class="sf-form-progress__step" data-step="2" title="<?= htmlspecialchars(sf_term('step2_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step2_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
        <span class="sf-form-progress__number" aria-hidden="true" data-step-num="2"></span>
        <span class="sf-form-progress__label"><?= htmlspecialchars(sf_term('step2_short', $uiLang) ?: 'Konteksti', ENT_QUOTES, 'UTF-8') ?></span>
      </button>
      <button type="button" class="sf-form-progress__step" data-step="3" title="<?= htmlspecialchars(sf_term('step3_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step3_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
        <span class="sf-form-progress__number" aria-hidden="true" data-step-num="3"></span>
        <span class="sf-form-progress__label"><?= htmlspecialchars(sf_term('step3_short', $uiLang) ?: 'Sisältö', ENT_QUOTES, 'UTF-8') ?></span>
      </button>
      <button type="button" class="sf-form-progress__step" data-step="4" title="<?= htmlspecialchars(sf_term('step4_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step4_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
        <span class="sf-form-progress__number" aria-hidden="true" data-step-num="4"></span>
        <span class="sf-form-progress__label"><?= htmlspecialchars(sf_term('step4_short', $uiLang) ?: 'Kuvat', ENT_QUOTES, 'UTF-8') ?></span>
      </button>
      <button type="button" class="sf-form-progress__step" data-step="5" title="<?= htmlspecialchars(sf_term('step5_heading', $uiLang) ?: 'Asettelu', ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step5_heading', $uiLang) ?: 'Asettelu', ENT_QUOTES, 'UTF-8') ?>">
        <span class="sf-form-progress__number" aria-hidden="true" data-step-num="5"></span>
        <span class="sf-form-progress__label"><?= htmlspecialchars(sf_term('step5_short', $uiLang) ?: 'Asettelu', ENT_QUOTES, 'UTF-8') ?></span>
      </button>
      <button type="button" class="sf-form-progress__step" data-step="6" title="<?= htmlspecialchars(sf_term('step6_heading', $uiLang) ?: 'Esikatselu', ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('step6_heading', $uiLang) ?: 'Esikatselu', ENT_QUOTES, 'UTF-8') ?>">
        <span class="sf-form-progress__number" aria-hidden="true" data-step-num="6"></span>
        <span class="sf-form-progress__label"><?= htmlspecialchars(sf_term('step6_short', $uiLang) ?: 'Lähetä', ENT_QUOTES, 'UTF-8') ?></span>
      </button>
    </div>
  </nav>

  <!-- VAIHE 1: kieli ja tyyppivalinta -->
  <div class="sf-step-content active" data-step="1">
    <h2><?= htmlspecialchars(sf_term('step1_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
    <!-- Kielivalinta -->
    <div class="sf-lang-selection">
      <label class="sf-label"><?= htmlspecialchars(sf_term('lang_selection_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
      <div class="sf-lang-options">
<?php
        $langOptions = [
            'fi' => ['label' => 'Suomi',    'flag' => 'finnish-flag.png'],
            'sv' => ['label' => 'Svenska',  'flag' => 'swedish-flag.png'],
            'en' => ['label' => 'English',  'flag' => 'english-flag.png'],
            'it' => ['label' => 'Italiano', 'flag' => 'italian-flag.png'],
            'el' => ['label' => 'Ελληνικά', 'flag' => 'greece-flag.png'],
        ];
        $selectedLang = $flash['language'] ?? 'fi';
        foreach ($langOptions as $langCode => $langData):
        ?>
          <label class="sf-lang-box" data-lang="<?php echo $langCode; ?>">
            <input type="radio" name="lang" value="<?php echo $langCode; ?>" <?php echo $selectedLang === $langCode ? 'checked' : ''; ?>>
            <div class="sf-lang-box-content">
              <img src="<?php echo $base; ?>/assets/img/<?php echo $langData['flag']; ?>" alt="<?php echo $langData['label']; ?>" class="sf-lang-flag">
              <span class="sf-lang-label"><?php echo htmlspecialchars($langData['label']); ?></span>
            </div>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="sf-help-text"><?= htmlspecialchars(sf_term('lang_selection_help', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <hr class="sf-divider">

    <!-- Tyyppivalinta -->
    <h3><?= htmlspecialchars(sf_term('type_selection_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>

    <div class="sf-type-selection" role="radiogroup" aria-label="Valitse tiedotteen tyyppi">

      <!-- RED -->
      <label class="sf-type-box" data-type="red">
        <input type="radio" name="type" value="red" <?= $type_val === 'red' ? 'checked' : '' ?>>
        <div class="sf-type-box-content">
          <img src="<?= $base ?>/assets/img/icon-red.png" alt="" class="sf-type-icon" aria-hidden="true">
          <div class="sf-type-text">
            <h4 class="sf-type-title">
              <?= htmlspecialchars(sf_term('first_release', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h4>
            <p><?= htmlspecialchars(sf_term('type_red_desc', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>
        <img src="<?= $base ?>/assets/img/icons/checkmark_icon.png" alt="Valittu" class="sf-type-checkmark">
      </label>

      <!-- YELLOW -->
      <label class="sf-type-box" data-type="yellow">
        <input type="radio" name="type" value="yellow" <?= $type_val === 'yellow' ? 'checked' : '' ?>>
        <div class="sf-type-box-content">
          <img src="<?= $base ?>/assets/img/icon-yellow.png" alt="" class="sf-type-icon" aria-hidden="true">
          <div class="sf-type-text">
            <h4 class="sf-type-title">
              <?= htmlspecialchars(sf_term('dangerous_situation', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h4>
            <p><?= htmlspecialchars(sf_term('type_yellow_desc', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>
        <img src="<?= $base ?>/assets/img/icons/checkmark_icon.png" alt="Valittu" class="sf-type-checkmark">
      </label>

      <!-- GREEN -->
      <label class="sf-type-box" data-type="green">
        <input type="radio" name="type" value="green" <?= $type_val === 'green' ? 'checked' : '' ?>>
        <div class="sf-type-box-content">
          <img src="<?= $base ?>/assets/img/icon-green.png" alt="" class="sf-type-icon" aria-hidden="true">
          <div class="sf-type-text">
            <h4 class="sf-type-title">
              <?= htmlspecialchars(sf_term('investigation_report', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h4>
            <p><?= htmlspecialchars(sf_term('type_green_desc', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
          </div>
        </div>
        <img src="<?= $base ?>/assets/img/icons/checkmark_icon.png" alt="Valittu" class="sf-type-checkmark">
      </label>

    </div>

    <!-- Vaihe 1 napit (alhaalla) -->
<div class="sf-step-actions sf-step-actions-bottom">
  <button
    type="button"
    id="sfNext"
    class="sf-btn sf-btn-primary sf-next-btn disabled"
    disabled
    aria-disabled="true"
  >
    <?= htmlspecialchars(sf_term('btn_next', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
  </button>
</div>
  </div>

  <!-- VAIHE 2: konteksti -->
  <div class="sf-step-content" data-step="2">
    <h2><?= htmlspecialchars(sf_term('step2_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>

    <div id="sf-step2-incident" class="sf-step2-section">
      <div class="sf-field">
        <label for="sf-related-flash" class="sf-label">
          <?= htmlspecialchars(sf_term('related_flash_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </label>
        <select name="related_flash_id" id="sf-related-flash" class="sf-select">
          <option value="">
            <?= htmlspecialchars(sf_term('related_flash_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>
          </option>
          <?php 
              // Kielilippujen määritys
              $langFlags = [
                  'fi' => '🇫🇮',
                  'sv' => '🇸🇪',
                  'en' => '🇬🇧',
                  'it' => '🇮🇹',
                  'el' => '🇬🇷',
              ];
              
              foreach ($relatedOptions as $opt):
              $optDate = !empty($opt['occurred_at'])
                  ? date('d.m.Y', strtotime($opt['occurred_at']))
                  : '–';

              $optSite  = $opt['site'] ?? '–';
              $optTitle = $opt['title'] ?? $opt['title_short'] ?? '–';

              // Väripallo tyypin mukaan
              $colorDot = ($opt['type'] === 'red') ? '🔴' :  '🟡';
              
              // Kielilippu
              $optLang = $opt['lang'] ?? 'fi';
              $langFlag = $langFlags[$optLang] ?? '🇫🇮';

              // Muoto: väripallo + kielilippu + päivämäärä + työmaa + otsikko
              $optLabel = "{$colorDot} {$langFlag} {$optDate} – {$optSite} – {$optTitle}";
          ?>
            <option
              value="<?= (int) $opt['id'] ?>"
              data-site="<?= htmlspecialchars($opt['site'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-site-detail="<?= htmlspecialchars($opt['site_detail'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-date="<?= htmlspecialchars($opt['occurred_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-title="<?= htmlspecialchars($opt['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-title-short="<?= htmlspecialchars($opt['title_short'] ??  '', ENT_QUOTES, 'UTF-8') ?>"
              data-description="<?= htmlspecialchars($opt['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-image-main="<?= htmlspecialchars($opt['image_main'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-image-2="<?= htmlspecialchars($opt['image_2'] ??  '', ENT_QUOTES, 'UTF-8') ?>"
              data-image-3="<?= htmlspecialchars($opt['image_3'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-annotations-data="<?= htmlspecialchars($opt['annotations_data'] ?? '{}', ENT_QUOTES, 'UTF-8') ?>"
              data-image1-transform="<?= htmlspecialchars($opt['image1_transform'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-image2-transform="<?= htmlspecialchars($opt['image2_transform'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-image3-transform="<?= htmlspecialchars($opt['image3_transform'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              data-grid-layout="<?= htmlspecialchars($opt['grid_layout'] ?? 'grid-1', ENT_QUOTES, 'UTF-8') ?>"
              data-grid-bitmap="<?= htmlspecialchars($opt['grid_bitmap'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              <?= (isset($flash['related_flash_id']) && (int) $flash['related_flash_id'] === (int) $opt['id']) ? 'selected' :  '' ?>
            >
              <?= htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="sf-help-text" id="sf-related-flash-help">
          <?= htmlspecialchars(sf_term('related_flash_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>
    </div>

    <!-- Alkuperäisen tiedotteen kompakti näkymä (näkyy kun related flash valittu) -->
    <div id="sf-original-flash-preview" class="sf-original-flash-compact hidden">
      <img src="<?= $base ?>/assets/img/icon-yellow.png" alt="" class="sf-original-icon" id="sf-original-icon">
      <div class="sf-original-info">
        <span class="sf-original-title" id="sf-original-title">--</span>
        <span class="sf-original-meta">
          <span id="sf-original-site">--</span>
          <span id="sf-original-date">--</span>
        </span>
      </div>
    </div>

    <!-- Tutkintatiedotteen osio (ei tarvitse erillistä info-tekstiä) -->
    <div id="sf-step2-investigation-worksite" class="sf-step2-section"></div>

<!-- Työmaa ja päivämäärä - käytetään KAIKILLE tyypeille (red, yellow, green) -->
<div id="sf-step2-worksite" class="sf-step2-section">
  <div class="sf-field-row">
    <div class="sf-field">
      <label for="sf-worksite" class="sf-label">
        <?= htmlspecialchars(sf_term('site_label', $flashLang), ENT_QUOTES, 'UTF-8') ?>
      </label>
      <select name="worksite" id="sf-worksite" class="sf-select">
        <option value="">
          <?= htmlspecialchars(sf_term('worksite_select_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
        <?php foreach ($worksites as $site): ?>
          <option
            value="<?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?>"
            <?= $worksite_val === $site['name'] ? 'selected' : '' ?>
          >
            <?= htmlspecialchars($site['name'], ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sf-field">
      <label for="sf-site-detail" class="sf-label">
        <?= htmlspecialchars(sf_term('site_detail_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </label>
      <input
        type="text"
        name="site_detail"
        id="sf-site-detail"
        class="sf-input"
        placeholder="<?= htmlspecialchars(sf_term('site_detail_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
        value="<?= htmlspecialchars($site_detail_val, ENT_QUOTES, 'UTF-8') ?>"
      >
    </div>
  </div>

  <div class="sf-field-row">
    <div class="sf-field">
      <label for="sf-date" class="sf-label">
        <?= htmlspecialchars(sf_term('when_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </label>
      <input
        type="datetime-local"
        name="event_date"
        id="sf-date"
        class="sf-input"
        required
        max="<?= date('Y-m-d\TH:i') ?>"
        step=""
        value="<?= htmlspecialchars($event_date_val, ENT_QUOTES, 'UTF-8') ?>"
      >
    </div>
  </div>

  <p class="sf-help-text">
    <?= htmlspecialchars(sf_term('step2_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
  </p>
</div>

    <!-- Vaihe 2 napit -->
    <div class="sf-step-actions sf-step-actions-bottom">
<button type="button" id="sfPrev" class="sf-btn sf-btn-secondary sf-prev-btn">
  <?= htmlspecialchars(sf_term('btn_prev', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
</button>
<button type="button" class="sf-btn sf-btn-primary sf-next-btn">
  <?= htmlspecialchars(sf_term('btn_next', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
</button>
    </div>
  </div>

  <!-- VAIHE 3: itse sisältö -->
  <div class="sf-step-content" data-step="3">
    <h2><?= htmlspecialchars(sf_term('step3_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>

    <div class="sf-field">
      <label for="sf-title" class="sf-label">
        <?= htmlspecialchars(sf_term('title_internal_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </label>
      <input
        type="text"
        name="title"
        id="sf-title"
        class="sf-input"
        required
        placeholder="<?= htmlspecialchars(sf_term('title_internal_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
        value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
      >
    </div>

    <div class="sf-field">
      <label for="sf-short-text" class="sf-label">
        <?= htmlspecialchars(sf_term('short_title_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </label>
      <textarea
        name="short_text"
        id="sf-short-text"
        class="sf-textarea"
        rows="2"
        required
        maxlength="85"
        placeholder="<?= htmlspecialchars(sf_term('short_text_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
      ><?= htmlspecialchars($short_text, ENT_QUOTES, 'UTF-8') ?></textarea>
      <p class="sf-char-count"><span id="sf-short-text-count">0</span>/85</p>
    </div>

    <div class="sf-field">
      <label for="sf-description" class="sf-label">
        <?= htmlspecialchars(sf_term('description_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </label>
      <textarea
        name="description"
        id="sf-description"
        class="sf-textarea"
        rows="8"
        required
        placeholder="<?= htmlspecialchars(sf_term('description_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
      ><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></textarea>
      <p class="sf-char-count"><span id="sf-description-count">0</span>/950</p>
    </div>

    <div id="sf-investigation-extra" class="sf-step3-investigation hidden">
      <div class="sf-field">
        <label for="sf-root-causes" class="sf-label">
          <?= htmlspecialchars(sf_term('root_cause_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </label>
        <textarea
          name="root_causes"
          id="sf-root-causes"
          class="sf-textarea"
          rows="4"
          maxlength="1500"
          placeholder="<?= htmlspecialchars(sf_term('root_causes_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
        ><?= htmlspecialchars($root_causes, ENT_QUOTES, 'UTF-8') ?></textarea>
        <p class="sf-help-text">
          <?= htmlspecialchars(sf_term('root_causes_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>

      <div class="sf-field">
        <label for="sf-actions" class="sf-label">
          <?= htmlspecialchars(sf_term('actions_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </label>
        <textarea
          name="actions"
          id="sf-actions"
          class="sf-textarea"
          rows="4"
          maxlength="1500"
          placeholder="<?= htmlspecialchars(sf_term('actions_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
        ><?= htmlspecialchars($actions, ENT_QUOTES, 'UTF-8') ?></textarea>
        <p class="sf-help-text">
          <?= htmlspecialchars(sf_term('actions_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
      </div>
    </div>

<div class="sf-two-slides-notice" id="sfTwoSlidesNotice" style="display: none;">
    <div class="sf-notice-icon">ⓘ</div>
    <div class="sf-notice-text">
        <strong><?= sf_term('two_slides_notice_title', $uiLang) ?></strong>
        <span><?= sf_term('two_slides_notice_text', $uiLang) ?></span>
    </div>
</div>

    <!-- Vaihe 3 napit -->
    <div class="sf-step-actions sf-step-actions-bottom">
      <button type="button" id="sfPrev2" class="sf-btn sf-btn-secondary sf-prev-btn">
        <?= htmlspecialchars(sf_term('btn_prev', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
      </button>
      <button type="button" id="sfNext2" class="sf-btn sf-btn-primary sf-next-btn">
        <?= htmlspecialchars(sf_term('btn_next', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
      </button>
    </div>
  </div>

  <!-- VAIHE 4: Kuvat -->
  <div class="sf-step-content" data-step="4">
    <h2><?= htmlspecialchars(sf_term('step4_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>

    <p class="sf-help-text">
      <?= htmlspecialchars(sf_term('step4_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
    </p>


    <div class="sf-image-upload-grid">
      <!-- Pääkuva -->
      <div class="sf-image-upload-card" data-slot="1">
        <label class="sf-image-upload-label">
          <?= htmlspecialchars(sf_term('img_main_label', $uiLang) ?? 'Pääkuva', ENT_QUOTES, 'UTF-8') ?> *
        </label>
        <div class="sf-image-upload-area">
          <div class="sf-image-preview" id="sfImagePreview1">
            <img
              src="<?= $getImageUrl($flash['image_main'] ?? null) ?>"
              alt="Pääkuva"
              id="sfImageThumb1"
              data-placeholder="<?= $base ?>/assets/img/camera-placeholder.png"
            >

            <span
              class="sf-image-edited-badge hidden"
              id="sfImageEditedBadge1"
            >
              <?= htmlspecialchars(sf_term('edited', $uiLang) ?? 'Muokattu', ENT_QUOTES, 'UTF-8') ?>
            </span>

            <button
              type="button"
              class="sf-image-remove-btn <?= empty($flash['image_main']) ? 'hidden' : '' ?>"
              data-slot="1"
              title="<?= htmlspecialchars(sf_term('btn_remove_image', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
            >
              <svg width="16" height="16" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 6L6 18M6 6l12 12"/>
              </svg>
            </button>
          </div>
<div class="sf-image-upload-actions">
            <label class="sf-image-upload-btn">
              <input type="file" name="image1" accept="image/*" id="sf-image1" class="sf-image-input">
              <img src="<?= $base ?>/assets/img/icons/upload.svg" alt="" class="sf-btn-icon">
                           <span><?= htmlspecialchars(sf_term('btn_upload', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </label>

            <button type="button" class="sf-image-library-btn" data-slot="1">
              <img src="<?= $base ?>/assets/img/icons/image.svg" alt="" class="sf-btn-icon">
                            <span><?= htmlspecialchars(sf_term('image_library_btn', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>

<button type="button" class="sf-image-edit-inline-btn hidden" data-slot="1" disabled>
  <img src="<?= $base ?>/assets/img/icons/edit_icon.svg" alt="" class="sf-btn-icon">
    <span><?= htmlspecialchars(sf_term('btn_edit', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
</button>
          </div>
        </div>
      </div>

      <!-- Lisäkuva 1 -->
      <div class="sf-image-upload-card" data-slot="2">
        <label class="sf-image-upload-label">
          <?= htmlspecialchars(sf_term('img_2_label', $uiLang) ?? 'Lisäkuva 1', ENT_QUOTES, 'UTF-8') ?>
        </label>
        <div class="sf-image-upload-area">
          <div class="sf-image-preview" id="sfImagePreview2">
            <img
              src="<?= $getImageUrl($flash['image_2'] ?? null) ?>"
              alt="Lisäkuva 1"
              id="sfImageThumb2"
              data-placeholder="<?= $base ?>/assets/img/camera-placeholder.png"
            >

            <span
              class="sf-image-edited-badge hidden"
              id="sfImageEditedBadge2"
            >
              <?= htmlspecialchars(sf_term('edited', $uiLang) ?? 'Muokattu', ENT_QUOTES, 'UTF-8') ?>
            </span>

            <button
              type="button"
              class="sf-image-remove-btn <?= empty($flash['image_2']) ? 'hidden' : '' ?>"
              data-slot="2"
              title="Poista kuva"
            >
              <svg width="16" height="16" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 6L6 18M6 6l12 12"/>
              </svg>
            </button>
          </div>
<div class="sf-image-upload-actions">
            <label class="sf-image-upload-btn">
              <input type="file" name="image2" accept="image/*" id="sf-image2" class="sf-image-input">
              <img src="<?= $base ?>/assets/img/icons/upload.svg" alt="" class="sf-btn-icon">
                            <span><?= htmlspecialchars(sf_term('btn_upload', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </label>

            <button type="button" class="sf-image-library-btn" data-slot="2">
              <img src="<?= $base ?>/assets/img/icons/image.svg" alt="" class="sf-btn-icon">
                            <span><?= htmlspecialchars(sf_term('image_library_btn', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>

<button type="button" class="sf-image-edit-inline-btn hidden" data-slot="2" disabled>
  <img src="<?= $base ?>/assets/img/icons/edit_icon.svg" alt="" class="sf-btn-icon">
    <span><?= htmlspecialchars(sf_term('btn_edit', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
</button>
          </div>
        </div>
      </div>

      <!-- Lisäkuva 2 -->
      <div class="sf-image-upload-card" data-slot="3">
        <label class="sf-image-upload-label">
          <?= htmlspecialchars(sf_term('img_3_label', $uiLang) ?? 'Lisäkuva 2', ENT_QUOTES, 'UTF-8') ?>
        </label>
        <div class="sf-image-upload-area">
          <div class="sf-image-preview" id="sfImagePreview3">
            <img
              src="<?= $getImageUrl($flash['image_3'] ?? null) ?>"
              alt="Lisäkuva 2"
              id="sfImageThumb3"
              data-placeholder="<?= $base ?>/assets/img/camera-placeholder.png"
            >

            <span
              class="sf-image-edited-badge hidden"
              id="sfImageEditedBadge3"
            >
              <?= htmlspecialchars(sf_term('edited', $uiLang) ?? 'Muokattu', ENT_QUOTES, 'UTF-8') ?>
            </span>

            <button
              type="button"
              class="sf-image-remove-btn <?= empty($flash['image_3']) ? 'hidden' : '' ?>"
              data-slot="3"
              title="Poista kuva"
            >
              <svg width="16" height="16" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 6L6 18M6 6l12 12"/>
              </svg>
            </button>
          </div>
<div class="sf-image-upload-actions">
            <label class="sf-image-upload-btn">
              <input type="file" name="image3" accept="image/*" id="sf-image3" class="sf-image-input">
              <img src="<?= $base ?>/assets/img/icons/upload.svg" alt="" class="sf-btn-icon">
                            <span><?= htmlspecialchars(sf_term('btn_upload', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </label>

            <button type="button" class="sf-image-library-btn" data-slot="3">
              <img src="<?= $base ?>/assets/img/icons/image.svg" alt="" class="sf-btn-icon">
                            <span><?= htmlspecialchars(sf_term('image_library_btn', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>

<button type="button" class="sf-image-edit-inline-btn hidden" data-slot="3" disabled>
  <img src="<?= $base ?>/assets/img/icons/edit_icon.svg" alt="" class="sf-btn-icon">
    <span><?= htmlspecialchars(sf_term('btn_edit', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
</button>
          </div>
        </div>
      </div>
    </div>

<div class="sf-step-actions sf-step-actions-bottom">
<button type="button" class="sf-btn sf-btn-secondary sf-prev-btn">
          <?= htmlspecialchars(sf_term('btn_prev', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
        </button>

        <button type="button" class="sf-btn sf-btn-primary sf-next-btn">
          <?= htmlspecialchars(sf_term('btn_next', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
        </button>
      </div>
  </div>

<!-- VAIHE 5: Grid-asettelu -->
<div class="sf-step-content" data-step="5">
  <h2><?= htmlspecialchars(sf_term('grid_heading', $uiLang) ?? 'Kuvien asettelu', ENT_QUOTES, 'UTF-8') ?></h2>
  <p class="sf-help-text"><?= htmlspecialchars(sf_term('grid_help', $uiLang) ?? 'Valitse asettelu. Tämän jälkeen järjestelmä generoi lopullisen kuva-alueen.', ENT_QUOTES, 'UTF-8') ?></p>

  <!-- GRID-VALINTAKORTIT (JS täyttää sisällön) -->
  <div class="sf-grid-options" id="sfGridPicker"></div>

  <div class="sf-step-actions sf-step-actions-bottom">
    <button type="button" class="sf-btn sf-btn-secondary sf-prev-btn">
      <?= htmlspecialchars(sf_term('btn_prev', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
    </button>
    <button type="button" class="sf-btn sf-btn-primary sf-next-btn">
      <?= htmlspecialchars(sf_term('btn_next', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
    </button>
  </div>
</div>

  <!-- Piilotetut transform-kentät (ennen vaihetta 5, lomakkeen sisällä) -->
  <input
    type="hidden"
    id="sf-image1-transform"
    name="image1_transform"
    value="<?= htmlspecialchars($image1_transform, ENT_QUOTES, 'UTF-8') ?>"
  >
  <input
    type="hidden"
    id="sf-image2-transform"
    name="image2_transform"
    value="<?= htmlspecialchars($image2_transform, ENT_QUOTES, 'UTF-8') ?>"
  >
  <input
    type="hidden"
    id="sf-image3-transform"
    name="image3_transform"
    value="<?= htmlspecialchars($image3_transform, ENT_QUOTES, 'UTF-8') ?>"
  >

  <!-- Piilotetut editoidut kuvat (dataURL) - täytetään kuvaeditorissa -->
  <input
    type="hidden"
    id="sf-image1-edited-data"
    name="image1_edited_data"
    value="<?= htmlspecialchars($flash['image1_edited_data'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
  >
  <input
    type="hidden"
    id="sf-image2-edited-data"
    name="image2_edited_data"
    value="<?= htmlspecialchars($flash['image2_edited_data'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
  >
  <input
    type="hidden"
    id="sf-image3-edited-data"
    name="image3_edited_data"
    value="<?= htmlspecialchars($flash['image3_edited_data'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
  >

  <input
    type="hidden"
    id="sf-edit-annotations-data"
    name="annotations_data"
    value="<?= htmlspecialchars($flash['annotations_data'] ?? '{}', ENT_QUOTES, 'UTF-8') ?>"
  >
  <input type="hidden" id="sf-grid-layout" name="grid_layout" value="<?= htmlspecialchars($flash['grid_layout'] ?? 'grid-1', ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" id="sf-grid-bitmap" name="grid_bitmap" value="<?= htmlspecialchars($flash['grid_bitmap'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  <input
    type="hidden"
    id="sf-annotations-data"
    value="[]"
  >

<!-- VAIHE 6: Esikatselu ja lähetys -->
  <div class="sf-step-content" data-step="6">
    <?php
    // HUOM: Tämä lataa molemmat, ja JS päättää kumpi näytetään.
    // Tämä ratkaisee ongelman, jossa tutkintapreview ei lataudu uutta luodessa.
    ?>
    <div id="sfPreviewContainerRedYellow" class="sf-preview-container">
      <?php require __DIR__ . '/../partials/preview.php'; ?>
    </div>
    <div id="sfPreviewContainerGreen" class="sf-preview-container hidden">
      <?php require __DIR__ . '/../partials/preview_tutkinta.php'; ?>
    </div>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        // PreviewScaler ei ole käytössä - Preview ja PreviewTutkinta hoitavat skaalauksen
        // Alustetaan oikea preview tyypistä riippuen
        var currentType = document.querySelector('input[name="type"]:checked');
        if (currentType && currentType.value === 'green') {
          if (typeof PreviewTutkinta !== 'undefined' && PreviewTutkinta.init) {
            PreviewTutkinta.init();
          }
        } else {
          if (typeof Preview !== 'undefined' && Preview.init) {
            Preview.init();
          }
        }
      });
    </script>

    <!-- Submit-painikkeet (lomakkeen sisällä) -->
    <div class="sf-preview-actions">
      <?php 
      // Määritä näytettävät painikkeet tilan mukaan
      // - draft ja request_info: näytä "Tallenna luonnos" + "Lähetä tarkistettavaksi"
      // - muut tilat (pending_review, reviewed, to_comms, published): näytä vain "Tallenna"
      $showSendToReview = ! $editing 
          || $state_val === 'draft' 
          || $state_val === 'request_info'
          || $state_val === '';
      
      if ($commsEditMode || $inlineEditMode): ?>
        <!-- Inline-muokkaustila (viestintä/turvatiimi) - tallenna suoraan ilman tilamuutosta -->
        <button
          type="button"
          id="sfSaveInline"
          class="sf-btn sf-btn-primary"
          data-action-url="<?= htmlspecialchars($base . '/app/actions/save_comms_edit.php', ENT_QUOTES, 'UTF-8') ?>"
          data-flash-id="<?= (int)$editId ?>"
        >
          <?= htmlspecialchars(sf_term('btn_save', $uiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>
        </button>
      <?php elseif ($editing && ! $showSendToReview): ?>
        <!-- Muokkaus tilassa joka EI ole draft/request_info - vain tallenna -->
        <button
          type="button"
          id="sfSaveInline"
          class="sf-btn sf-btn-primary"
          data-action-url="<?= htmlspecialchars($base . '/app/actions/save_edit.php', ENT_QUOTES, 'UTF-8') ?>"
          data-flash-id="<?= (int)$editId ?>"
        >
          <?= htmlspecialchars(sf_term('btn_save', $uiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>
        </button>
      <?php else: ?>
        <!-- Uusi tai draft/request_info - näytä molemmat painikkeet -->
        <button
          type="submit"
          name="submission_type"
          value="draft"
          id="sfSaveDraft"
          class="sf-btn sf-btn-secondary"
        >
          <?= htmlspecialchars(sf_term('btn_save_draft', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <button
          type="submit"
          name="submission_type"
          value="review"
          id="sfSubmitReview"
          class="sf-btn sf-btn-primary"
        >
          <?= htmlspecialchars(sf_term('btn_send_review', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
      <?php endif; ?>
    </div>
    <!-- Vaihe 6 napit -->
    <div class="sf-step-actions sf-step-actions-bottom">
      <button type="button" class="sf-btn sf-btn-secondary sf-prev-btn">
        <?= htmlspecialchars(sf_term('btn_prev', $uiLang), ENT_QUOTES, 'UTF-8'); ?>
      </button>
    </div>
  </div>
  <!-- Lopullinen preview-kuva base64:na -->
  <input type="hidden" name="preview_image_data" id="sf-preview-image-data" value="">
  <input type="hidden" name="preview_image_data_2" id="sf-preview-image-data-2" value="">

  <div id="sfTextModal" class="sf-modal hidden">
  <div class="sf-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="sfTextModalTitle">
    <div class="sf-modal-header">
      <h3 id="sfTextModalTitle"><?= htmlspecialchars(sf_term('anno_text', $uiLang) ?? 'Teksti', ENT_QUOTES, 'UTF-8') ?></h3>
      <button type="button" class="sf-modal-close" data-modal-close>×</button>
    </div>

    <div class="sf-modal-body">
      <label class="sf-label" for="sfTextModalInput">
        <?= htmlspecialchars(sf_term('LABEL_PROMPT', $uiLang) ?? 'Kirjoita merkintä:', ENT_QUOTES, 'UTF-8') ?>
      </label>
<textarea
  id="sfTextModalInput"
  class="sf-textarea"
  rows="5"
  placeholder="<?= htmlspecialchars(sf_term('anno_text_placeholder', $uiLang) ?? 'Kirjoita teksti… (Enter = uusi rivi)', ENT_QUOTES, 'UTF-8') ?>"
></textarea>
    </div>

    <div class="sf-modal-footer">
      <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
      </button>
      <button type="button" id="sfTextModalSave" class="sf-btn sf-btn-primary">
        <?= htmlspecialchars(sf_term('btn_save', $uiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>
      </button>
    </div>
  </div>
</div>

<!-- KUVAEDITORI MODAL (ei osa steppejä) -->
<div id="sfEditStep" class="hidden sf-edit-modal" aria-hidden="true">
  <div class="sf-edit-modal-card sf-edit-compact">
    
    <!-- Header:  otsikko + close -->
    <div class="sf-edit-modal-header-compact">
      <div class="sf-edit-header-left">
        <h2 data-sf-edit-title><?= htmlspecialchars(sf_term('img_edit_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
      </div>
      <button type="button" id="sf-edit-close" class="sf-edit-close-compact" aria-label="<?= htmlspecialchars(sf_term('btn_close', $uiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M18 6L6 18M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <!-- Body: canvas + sivupaneeli samalla rivillä -->
    <div class="sf-edit-modal-body-compact">
      
      <!-- Vasen:  Canvas -->
      <div class="sf-edit-canvas-area">
        <div id="sf-edit-img-canvas-wrap" class="sf-edit-canvas-wrap">
          <canvas id="sf-edit-img-canvas" width="1920" height="1080" class="sf-edit-canvas"></canvas>
        </div>
        
        <!-- Zoom/pan kontrollit canvasin alla -->
        <div class="sf-edit-canvas-controls">
          <div class="sf-edit-control-group">
            <button type="button" id="sf-edit-img-zoom-out" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_zoom_out', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
            <button type="button" id="sf-edit-img-zoom-in" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_zoom_in', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
            <span class="sf-edit-ctrl-divider"></span>
            <button type="button" id="sf-edit-img-move-left" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_move_left', $uiLang), ENT_QUOTES, 'UTF-8') ?>">←</button>
            <button type="button" id="sf-edit-img-move-up" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_move_up', $uiLang), ENT_QUOTES, 'UTF-8') ?>">↑</button>
            <button type="button" id="sf-edit-img-move-down" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_move_down', $uiLang), ENT_QUOTES, 'UTF-8') ?>">↓</button>
            <button type="button" id="sf-edit-img-move-right" class="sf-edit-ctrl-btn" title="<?= htmlspecialchars(sf_term('edit_move_right', $uiLang), ENT_QUOTES, 'UTF-8') ?>">→</button>
            <span class="sf-edit-ctrl-divider"></span>
            <button type="button" id="sf-edit-img-reset" class="sf-edit-ctrl-btn sf-edit-ctrl-text" title="<?= htmlspecialchars(sf_term('edit_reset', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars(sf_term('btn_reset', $uiLang) ?? 'Reset', ENT_QUOTES, 'UTF-8') ?>
            </button>
          </div>
        </div>
      </div>

      <!-- Oikea: Merkinnät paneeli -->
      <div class="sf-edit-sidebar">
        <div class="sf-edit-sidebar-section">
          <h3 class="sf-edit-sidebar-title"><?= htmlspecialchars(sf_term('anno_title', $uiLang) ?? 'Merkinnät', ENT_QUOTES, 'UTF-8') ?></h3>
          <p class="sf-edit-sidebar-hint">
            <?= htmlspecialchars(sf_term('anno_help_short', $uiLang) ?? 'Valitse ikoni ja klikkaa kuvaa', ENT_QUOTES, 'UTF-8') ?>
          </p>
          
          <!-- Ikonivalitsin -->
          <div class="sf-edit-anno-grid">
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="arrow" title="<?= htmlspecialchars(sf_term('anno_arrow', $uiLang) ?? 'Nuoli', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/arrow-red.png" alt="" class="sf-anno-icon">
            </button>
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="circle" title="<?= htmlspecialchars(sf_term('anno_circle', $uiLang) ?? 'Ympyrä', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/circle-red.png" alt="" class="sf-anno-icon">
            </button>
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="crash" title="<?= htmlspecialchars(sf_term('anno_crash', $uiLang) ?? 'Törmäys', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/crash.png" alt="" class="sf-anno-icon">
            </button>
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="warning" title="<?= htmlspecialchars(sf_term('anno_warning', $uiLang) ?? 'Varoitus', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/warning.png" alt="" class="sf-anno-icon">
            </button>
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="injury" title="<?= htmlspecialchars(sf_term('anno_injury', $uiLang) ?? 'Vamma', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/injury.png" alt="" class="sf-anno-icon">
            </button>
            <button type="button" class="sf-edit-anno-btn" data-sf-tool="cross" title="<?= htmlspecialchars(sf_term('anno_cross', $uiLang) ?? 'Risti', ENT_QUOTES, 'UTF-8') ?>">
              <img src="<?= $base ?>/assets/img/annotations/cross-red.png" alt="" class="sf-anno-icon">
            </button>
          </div>
          
          <!-- Valitun merkinnän kontrollit -->
          <div class="sf-edit-selected-controls" id="sfEditSelectedControls">
            <div class="sf-edit-selected-row">
              <button type="button" id="sf-edit-anno-rotate" class="sf-edit-sel-btn" disabled title="<?= htmlspecialchars(sf_term('anno_rotate_45', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
              </button>
              <button type="button" id="sf-edit-anno-size-down" class="sf-edit-sel-btn" disabled title="<?= htmlspecialchars(sf_term('anno_size_down', $uiLang), ENT_QUOTES, 'UTF-8') ?>">−</button>
              <button type="button" id="sf-edit-anno-size-up" class="sf-edit-sel-btn" disabled title="<?= htmlspecialchars(sf_term('anno_size_up', $uiLang), ENT_QUOTES, 'UTF-8') ?>">+</button>
              <button type="button" id="sf-edit-anno-delete" class="sf-edit-sel-btn sf-edit-sel-danger" disabled title="<?= htmlspecialchars(sf_term('anno_delete_selected', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
              </button>
            </div>
          </div>
          
          <!-- Teksti-nappi -->
          <button type="button" id="sf-edit-img-add-label" class="sf-edit-text-btn" disabled>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></svg>
            <?= htmlspecialchars(sf_term('anno_text', $uiLang) ?? 'Lisää teksti', ENT_QUOTES, 'UTF-8') ?>
          </button>
        </div>
      </div>
    </div>

    <!-- Footer: Tallenna -->
    <div class="sf-edit-modal-footer-compact">
      <button type="button" id="sf-edit-img-save" class="sf-btn sf-btn-primary">
        <?= htmlspecialchars(sf_term('btn_save', $uiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8'); ?>
      </button>
    </div>

  </div>
</div>
<script>
window.SF_I18N = <?= json_encode(array_merge($sfI18n, [
    'saving_flash' => sf_term('saving_flash', $uiLang),
    'generating_preview' => sf_term('generating_preview', $uiLang),
    'btn_cancel' => sf_term('btn_cancel', $uiLang),
    'btn_save' => sf_term('btn_save', $uiLang),
    'error_prefix' => sf_term('error_prefix', $uiLang),
]), JSON_UNESCAPED_UNICODE) ?>;
window.SF_BASE_URL = "<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>";
</script>
<script src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/js/SFEditImage.js"></script>
<script src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/js/sf-image-edit-flow.js"></script>
<script src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/js/sf-grid-step.js"></script>

<?php if ($commsEditMode || $inlineEditMode || ($editing && !$showSendToReview)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var saveBtn = document.getElementById('sfSaveInline');
    if (!saveBtn) return;

    saveBtn.addEventListener('click', async function() {
        var form = document.getElementById('sf-form');
        if (!form) return;

        saveBtn.disabled = true;
        saveBtn.textContent = '<?= htmlspecialchars(sf_term('status_generating_image', $uiLang), ENT_QUOTES, 'UTF-8') ?>';

        try {
            if (typeof captureAllPreviews === 'function') {
                await captureAllPreviews();
            } else if (typeof window.capturePreviewCard1 === 'function') {
                await window.capturePreviewCard1();
                var currentType = document.querySelector('input[name="type"]:checked');
                if (currentType && currentType.value === 'green' && typeof window.capturePreviewCard2 === 'function') {
                    await window.capturePreviewCard2();
                }
            }
        } catch (err) {
            console.error('Preview capture error:', err);
        }

        saveBtn.textContent = '<?= htmlspecialchars(sf_term('status_saving', $uiLang), ENT_QUOTES, 'UTF-8') ?>';

        var formData = new FormData(form);
        formData.append('id', saveBtn.dataset.flashId);

        fetch(saveBtn.dataset.actionUrl, {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.ok && data.redirect) {
                window.location.href = data.redirect;
            } else {
                alert(data.error || '<?= htmlspecialchars(sf_term('error_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>');
                saveBtn.disabled = false;
                saveBtn.textContent = '<?= htmlspecialchars(sf_term('btn_save', $uiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>';
            }
        })
        .catch(function(err) {
            console.error(err);
            alert('<?= htmlspecialchars(sf_term('error_network', $uiLang), ENT_QUOTES, 'UTF-8') ?>');
            saveBtn.disabled = false;
            saveBtn.textContent = '<?= htmlspecialchars(sf_term('btn_save', $uiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>';
        });
    });
});
</script>
<?php endif; ?></form>

<!-- VAHVISTUSMODAL - Lomakkeen ulkopuolella jotta JS löytää sen -->
<div
  class="sf-modal hidden"
  id="sfConfirmModal"
  role="dialog"
  aria-modal="true"
  aria-labelledby="sfConfirmModalTitle"
>
  <div class="sf-modal-content">
    <h2 id="sfConfirmModalTitle">
      <?= htmlspecialchars(sf_term('confirm_submit_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
    </h2>
    <p><?= htmlspecialchars(sf_term('confirm_submit_text', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="sf-help-text">
      <?= htmlspecialchars(sf_term('confirm_submit_help', $uiLang), ENT_QUOTES, 'UTF-8') ?>
    </p>
    <div class="sf-modal-actions">
      <button
        type="button"
        class="sf-btn sf-btn-secondary"
        data-modal-close="sfConfirmModal"
      >
        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </button>
      <button type="button" class="sf-btn sf-btn-primary" id="sfConfirmSubmit">
        <?= htmlspecialchars(sf_term('btn_confirm_yes', $uiLang), ENT_QUOTES, 'UTF-8') ?>
      </button>
    </div>
  </div>
</div>

<?php
// Kuvapankki-modaali
$currentUiLang = $uiLang;
include __DIR__ . '/../partials/image_library_modal.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
  if (window.ImageLibrary) {
    ImageLibrary.init('<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>');
  }
});
</script>

<div id="sfConfirmRemoveModal" class="sf-modal hidden">
  <div class="sf-modal-dialog sf-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="sfConfirmRemoveTitle">
    <div class="sf-modal-header">
      <h3 id="sfConfirmRemoveTitle">
        <?= htmlspecialchars(sf_term('confirm_remove_image_title', $uiLang) ?? 'Poista kuva', ENT_QUOTES, 'UTF-8') ?>
      </h3>
      <button type="button" class="sf-modal-close" id="sfConfirmRemoveClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $uiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">×</button>
    </div>

    <div class="sf-modal-body">
      <p id="sfConfirmRemoveText" class="sf-confirm-text">
        <?= htmlspecialchars(sf_term('confirm_remove_image_text', $uiLang) ?? 'Haluatko poistaa tämän kuvan? Kuva ja sen säädöt poistetaan.', ENT_QUOTES, 'UTF-8') ?>
      </p>
    </div>

    <div class="sf-modal-footer">
      <button type="button" id="sfConfirmRemoveNo" class="sf-btn sf-btn-secondary">
        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
      </button>
      <button type="button" id="sfConfirmRemoveYes" class="sf-btn sf-btn-danger">
        <?= htmlspecialchars(sf_term('btn_delete', $uiLang) ?? 'Poista', ENT_QUOTES, 'UTF-8') ?>
      </button>
    </div>
  </div>
</div>
</script>