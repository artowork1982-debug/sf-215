<?php
// app/pages/list.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/statuses.php';

$baseUrl = rtrim($config['base_url'] ?? '', '/');

// Käyttöliittymän kieli
$uiLang = $_SESSION['ui_lang'] ?? 'fi';

// Tarkista onko käyttäjä ylläpitäjä
$user = sf_current_user();
$isAdmin = $user && (int)($user['role_id'] ?? 0) === 1;

// POISTETTU: Ei enää käytetä "recently processed" logiikkaa

// Tarkista onko juuri luotu flash (URL-parametrista)
$bgProcessId = isset($_GET['bg_process']) ? (int)$_GET['bg_process'] : 0;

// --- DB connection ---
try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p>' . htmlspecialchars(sf_term('db_error', $uiLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// Tuetut kielet ja lippukuvat (käytetään 'el' kreikalle)
$supportedLangs = [
    'fi' => ['label' => 'FI', 'icon' => 'finnish-flag.png'],
    'sv' => ['label' => 'SV', 'icon' => 'swedish-flag.png'],
    'en' => ['label' => 'EN', 'icon' => 'english-flag.png'],
    'it' => ['label' => 'IT', 'icon' => 'italian-flag.png'],
    'el' => ['label' => 'EL', 'icon' => 'greece-flag.png'],
];

// Tarkista onko käyttäjällä kotityömaa ja ohjaa automaattisesti suodatettuun näkymään
$homeWorksiteName = '';
if ($user) {
    $homeWorksiteId = $user['home_worksite_id'] ?? null;
    if ($homeWorksiteId) {
        $stmtWs = $pdo->prepare("SELECT name FROM sf_worksites WHERE id = :id LIMIT 1");
        $stmtWs->execute([':id' => $homeWorksiteId]);
        $wsRow = $stmtWs->fetch();
        if ($wsRow && ! empty($wsRow['name'])) {
            $homeWorksiteName = $wsRow['name'];
        }
    }
}

// Filters (from URL)
$type     = $_GET['type']      ??  '';
$state    = $_GET['state']     ?? '';
$site     = $_GET['site']      ?? '';
$q        = trim((string)($_GET['q']    ?? ''));
$from     = $_GET['date_from'] ?? '';
$to       = $_GET['date_to']   ??  '';
$archived = $_GET['archived']  ?? '';

// Tarkista onko käyttäjällä mitään suodattimia URL:ssa
$hasAnyFilter = isset($_GET['type']) || isset($_GET['state']) || isset($_GET['site']) || isset($_GET['q']) || isset($_GET['date_from']) || isset($_GET['date_to']) || isset($_GET['archived']);

// Jos ei ole suodattimia URL:ssa ja käyttäjällä on kotityömaa, käytä sitä automaattisesti
if (! $hasAnyFilter && $homeWorksiteName !== '') {
    $site = $homeWorksiteName;
}

// Dropdown näyttää valitun työmaan (joko URL:sta tai automaattisesti asetetun)
$autoSite = $site;
// --- Työmaat dropdownia varten (kaikki aktiiviset työmaat) ---
$sites = $pdo->query("SELECT name FROM sf_worksites WHERE is_active = 1 ORDER BY name ASC")
             ->fetchAll(PDO::FETCH_COLUMN);

// --- Build SQL ---
$where  = [];
$params = [];

if ($type !== '') {
    $where[]         = "f.type = :type";
    $params[':type'] = $type;
}

if ($state !== '') {
    $where[]          = "f.state = :state";
    $params[':state'] = $state;
}

if ($site !== '') {
    $where[]         = "f.site = :site";
    $params[':site'] = $site;
}

if ($q !== '') {
    $escapedQ = addcslashes($q, '%_\\');
    $where[]      = "(f.title LIKE :q 
                  OR f.title_short LIKE :q 
                  OR f.summary LIKE :q 
                  OR f.description LIKE :q)";
    $params[':q'] = "%" . $escapedQ . "%";
}

if ($from !== '') {
    $where[]         = "f.occurred_at >= :from";
    $params[':from'] = "$from 00:00:00";
}

if ($to !== '') {
    $where[]       = "f.occurred_at <= :to";
    $params[':to'] = "$to 23:59:59";
}

// Archived filter: default is to hide archived unless specifically requested
if ($archived === 'only') {
    $where[] = "f.is_archived = 1";
} elseif ($archived === 'all') {
    // Show both archived and non-archived - no filter
} else {
    // Default: hide archived
    $where[] = "f.is_archived = 0";
}

$sql = "SELECT f.*,
        DATE_FORMAT(f.occurred_at, '%d.%m.%Y %H:%i') AS occurredFmt,
        DATE_FORMAT(f.updated_at, '%d.%m.%Y %H:%i') AS updatedFmt,
        (SELECT COUNT(*) 
         FROM safetyflash_logs sl 
         WHERE sl.flash_id = f.id 
           AND sl.event_type = 'comment_added'
           AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ) AS new_comment_count
        FROM sf_flashes f";

// Sisällytä kaikki kortit, mukaan lukien prosessoinnissa olevat
// Piilotus tehdään frontendissä CSS:llä
$processingFilter = "1"; // SQL: always true (include all)
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where) . " AND " . $processingFilter;
} else {
    $sql .= " WHERE " . $processingFilter;
}

$sql .= " ORDER BY f.created_at DESC LIMIT 1000";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$fetched = $stmt->fetchAll();

// Hae prosessoinnissa olevat flashit (vain polling-dataa varten, ei näytetä kortteja)
$processingFlashes = [];
try {
    $procSql = "SELECT f.id, f.processing_status
                FROM sf_flashes f 
                WHERE f.is_processing = 1 
                ORDER BY f.created_at DESC 
                LIMIT 20";
    $procStmt = $pdo->query($procSql);
    $processingFlashes = $procStmt->fetchAll();
} catch (Throwable $e) {
    error_log('list.php: Error fetching processing flashes: ' . $e->getMessage());
}

$groups = [];
foreach ($fetched as $r) {
    $groupId = !empty($r['translation_group_id']) ? (int)$r['translation_group_id'] : (int)$r['id'];
    $groups[$groupId][] = $r;
}

$statePriority = [
    'published'      => 100,
    'to_comms'       => 90,
    'request_info'   => 80,
    'reviewed'       => 70,
    'pending_review' => 60,
    'draft'          => 50,
];
$defaultPriority = 10;

$rows = [];
foreach ($groups as $gid => $items) {
    usort($items, function($a, $b) use ($statePriority, $defaultPriority) {
        $pa = $statePriority[$a['state']] ?? $defaultPriority;
        $pb = $statePriority[$b['state']] ?? $defaultPriority;
        if ($pa !== $pb) return $pb <=> $pa;
        return strtotime($b['created_at'] ?? 0) <=> strtotime($a['created_at'] ?? 0);
    });
    $rows[] = $items[0];
}

// --- NÄKYVYYSLOGIIKKA: roolien mukaan ---

$currentUserId = $user ? (int)$user['id'] : 0;
$roleId        = $user ? (int)$user['role_id'] : 0;

// 1 = Pääkäyttäjä, 2 = Kirjoittaja, 3 = Turvatiimi, 4 = Viestintä
$ROLE_ADMIN  = 1;
$ROLE_WRITER = 2;
$ROLE_SAFETY = 3;
$ROLE_COMMS  = 4;

$visibleRows = [];

foreach ($rows as $r) {
    $stateVal  = $r['state'];
    $createdBy = (int)($r['created_by'] ?? 0);

    $isOwner   = $currentUserId > 0 && $createdBy === $currentUserId;
    $isSafety  = $roleId === $ROLE_SAFETY;
    $isComms   = $roleId === $ROLE_COMMS;
    $isAdminRole = $roleId === $ROLE_ADMIN;

    $visible = false;

    if ($isAdminRole) {
        $visible = true;
    } else {
        switch ($stateVal) {
            case 'draft':
                $visible = $isOwner;
                break;

            case 'pending_review':
            case 'request_info':
                $visible = $isOwner || $isSafety;
                break;

            case 'to_comms':
                $visible = $isOwner || $isSafety || $isComms;
                break;

            case 'published':
                $visible = true;
                break;

            default:
                $visible = $isOwner || $isSafety;
                break;
        }
    }

    if ($visible) {
        $visibleRows[] = $r;
    }
}

usort($visibleRows, function($a, $b) {
    return strtotime($b['created_at'] ?? 0) <=> strtotime($a['created_at'] ?? 0);
});

$rows = $visibleRows;

// Helpers
function typeBadgeClass($t) {
    return [
        'red'    => 'badge-red',
        'yellow' => 'badge-yellow',
        'green'  => 'badge-green',
    ][$t] ?? 'badge-default';
}

function sf_get_flash_translations_list(PDO $pdo, int $groupId): array {
    $stmt = $pdo->prepare("SELECT id, lang FROM sf_flashes WHERE translation_group_id = :gid1 OR id = :gid2");
    $stmt->execute([':gid1' => $groupId, ':gid2' => $groupId]);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[$r['lang']] = (int)$r['id'];
    }
    return $map;
}

/**
 * Fetch all translations for multiple flash groups in a single query.
 * 
 * This function optimizes the N+1 query problem by fetching translations
 * for all groups at once, instead of making a separate query for each group.
 * 
 * @param PDO $pdo Database connection
 * @param array $groupIds Array of translation group IDs
 * @return array Associative array with group IDs as keys, each containing
 *               a map of language codes to flash IDs
 */
function sf_get_all_translations(PDO $pdo, array $groupIds): array {
    if (empty($groupIds)) {
        return [];
    }
    
    // Ensure all group IDs are integers for security
    $groupIds = array_map('intval', array_values($groupIds));
    
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $sql = "SELECT id, lang, COALESCE(translation_group_id, id) as group_id 
            FROM sf_flashes 
            WHERE COALESCE(translation_group_id, id) IN ($placeholders)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($groupIds);
    $rows = $stmt->fetchAll();
    
    $translations = [];
    foreach ($rows as $r) {
        $gid = (int)$r['group_id'];
        if (!isset($translations[$gid])) {
            $translations[$gid] = [];
        }
        $translations[$gid][$r['lang']] = (int)$r['id'];
    }
    
    return $translations;
}

$currentUiLang = $uiLang ?? 'fi';
?>

<div class="sf-list-page">

    <h1 class="page-title">
        <?= htmlspecialchars(sf_term('list_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
    </h1>

<!-- FILTER HEADER WITH TOGGLE, SEARCH, AND CLEAR BUTTON -->
<div class="sf-filter-header">
    <!-- ARCHIVED/ACTIVE TOGGLE (Segmented Control) -->
    <div class="sf-archived-toggle" role="group" aria-label="<?= htmlspecialchars(sf_term('filter_archived', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" 
                class="sf-toggle-btn<?= $archived === '' ? ' active' : '' ?>" 
                data-archived-value="" 
                aria-pressed="<?= $archived === '' ? 'true' : 'false' ?>">
            <?= htmlspecialchars(sf_term('filter_chip_archived_active', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <button type="button" 
                class="sf-toggle-btn<?= $archived === 'only' ? ' active' : '' ?>" 
                data-archived-value="only" 
                aria-pressed="<?= $archived === 'only' ? 'true' : 'false' ?>">
            <?= htmlspecialchars(sf_term('filter_chip_archived_only', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <button type="button" 
                class="sf-toggle-btn<?= $archived === 'all' ? ' active' : '' ?>" 
                data-archived-value="all" 
                aria-pressed="<?= $archived === 'all' ? 'true' : 'false' ?>">
            <?= htmlspecialchars(sf_term('filter_chip_archived_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <!-- SEARCH AND CLEAR BUTTON -->
    <div class="sf-filter-search">
        <input
            type="text"
            id="sf-search-input"
            placeholder="<?= htmlspecialchars(sf_term('filter_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            value="<?= htmlspecialchars($q) ?>"
            aria-label="<?= htmlspecialchars(sf_term('filter_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        >
        <!-- Search button -->
        <button type="button" class="sf-search-btn" id="sf-search-btn" title="<?= htmlspecialchars(sf_term('filter_search', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
        </button>
        
        <!-- Clear filters -->
        <button type="button" class="sf-filter-clear-btn<?= ($type === '' && $state === '' && $site === '' && $q === '' && $from === '' && $to === '' && $archived === '') ? ' hidden' : '' ?>" id="sf-clear-all-btn" title="<?= htmlspecialchars(sf_term('filter_clear_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
        
        <!-- Reset button -->
        <button type="button" class="sf-filter-reset-btn<?= ($type === '' && $state === '' && $site === '' && $q === '' && $from === '' && $to === '' && $archived === '') ? ' hidden' : '' ?>" id="sf-reset-all-btn" title="<?= htmlspecialchars(sf_term('filter_reset', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                <path d="M3 3v5h5"/>
            </svg>
            <span><?= htmlspecialchars(sf_term('filter_reset', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </button>
    </div>

    <!-- VIEW TOGGLE (Grid / List / Compact) -->
    <div class="sf-view-toggle" id="sfViewToggle">
        <button type="button" data-view="grid" title="<?= htmlspecialchars(sf_term('view_grid', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('view_grid', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"></rect>
                <rect x="14" y="3" width="7" height="7"></rect>
                <rect x="3" y="14" width="7" height="7"></rect>
                <rect x="14" y="14" width="7" height="7"></rect>
            </svg>
        </button>
        <button type="button" data-view="list" class="active" title="<?= htmlspecialchars(sf_term('view_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('view_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="6" rx="1"></rect>
                <rect x="3" y="11" width="18" height="6" rx="1"></rect>
            </svg>
        </button>
        <button type="button" data-view="compact" title="<?= htmlspecialchars(sf_term('view_compact', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('view_compact', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
    </div>
</div>

<!-- FILTER CHIPS -->
<div class="sf-filter-chips">
    <button type="button" class="sf-chip<?= $type !== '' ? ' active' : '' ?>" data-filter="type" data-value="<?= htmlspecialchars($type) ?>">
        <span class="chip-label">
            <?php if ($type === 'red'): ?>
                <?= htmlspecialchars(sf_term('first_release', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php elseif ($type === 'yellow'): ?>
                <?= htmlspecialchars(sf_term('dangerous_situation', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php elseif ($type === 'green'): ?>
                <?= htmlspecialchars(sf_term('investigation_report', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
                <?= htmlspecialchars(sf_term('filter_chip_type_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </span>
        <svg class="chip-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>

    <button type="button" class="sf-chip<?= $state !== '' ? ' active' : '' ?>" data-filter="state" data-value="<?= htmlspecialchars($state) ?>">
        <span class="chip-label">
            <?php if ($state !== ''): ?>
                <?= htmlspecialchars(sf_status_label($state, $currentUiLang)) ?>
            <?php else: ?>
                <?= htmlspecialchars(sf_term('filter_chip_state_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </span>
        <svg class="chip-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>

    <button type="button" class="sf-chip<?= $site !== '' ? ' active' : '' ?>" data-filter="site" data-value="<?= htmlspecialchars($site) ?>">
        <span class="chip-label">
            <?php if ($site !== ''): ?>
                <?= htmlspecialchars($site) ?>
            <?php else: ?>
                <?= htmlspecialchars(sf_term('filter_chip_site_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </span>
        <svg class="chip-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>

    <button type="button" class="sf-chip<?= ($from !== '' || $to !== '') ? ' active' : '' ?>" data-filter="date" data-from="<?= htmlspecialchars($from) ?>" data-to="<?= htmlspecialchars($to) ?>">
        <span class="chip-label">
            <?php if ($from !== '' || $to !== ''): ?>
                <?php 
                if ($from !== '' && $to !== '') {
                    $fromFormatted = date('d.m.', strtotime($from));
                    $toFormatted = date('d.m.Y', strtotime($to));
                    echo htmlspecialchars($fromFormatted . ' - ' . $toFormatted);
                } elseif ($from !== '') {
                    echo htmlspecialchars(date('d.m.Y', strtotime($from)) . ' →');
                } else {
                    echo htmlspecialchars('→ ' . date('d.m.Y', strtotime($to)));
                }
                ?>
            <?php else: ?>
                <?= htmlspecialchars(sf_term('filter_chip_date', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </span>
        <svg class="chip-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>
</div>

<!-- FILTER BAR -->
<form method="get" class="filters">
    <input type="hidden" name="page" value="list">

    <!-- Mobiili: Toggle-nappi suodattimille -->
    <button type="button" class="filters-toggle" id="filtersToggle">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <!-- polygon korjattu -->
            <polygon points="22,3 2,3 10,12.46 10,19 14,21 14,12.46"/>
        </svg>

        <span><?= htmlspecialchars(sf_term('list_filters', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>

        <svg class="toggle-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>

    <div class="filters-grid" id="filtersGrid" role="search" aria-label="<?= htmlspecialchars(sf_term('list_filters', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
            <div class="filter-item">
                <label for="f-type">
                    <?= htmlspecialchars(sf_term('filter_type', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select id="f-type" name="type">
                    <option value="">
                        <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="red" <?= $type === 'red' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('first_release', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="yellow" <?= $type === 'yellow' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('dangerous_situation', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="green" <?= $type === 'green' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('investigation_report', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                </select>
            </div>

            <div class="filter-item">
                <label for="f-state">
                    <?= htmlspecialchars(sf_term('filter_state', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select id="f-state" name="state">
                    <option value="">
                        <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="draft" <?= $state==='draft' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_status_label('draft', $currentUiLang)) ?>
                    </option>
                    <option value="pending_review" <?= $state==='pending_review' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_status_label('pending_review', $currentUiLang)) ?>
                    </option>
                    <option value="reviewed" <?= $state==='reviewed' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_status_label('reviewed', $currentUiLang)) ?>
                    </option>

                    <option value="to_comms" <?= $state==='to_comms' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_status_label('to_comms', $currentUiLang)) ?>
                    </option>
                    <option value="published" <?= $state==='published' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_status_label('published', $currentUiLang)) ?>
                    </option>
                </select>
            </div>

            <div class="filter-item">
                <label for="f-site">
                    <?= htmlspecialchars(sf_term('filter_site', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select id="f-site" name="site">
                    <option value="">
                        <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"
                            <?= ($site !== '' ? $site : $autoSite) === $s ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item filter-search">
                <label for="f-q">
                    <?= htmlspecialchars(sf_term('filter_search_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <div class="search-row">
                    <input
                        id="f-q"
                        type="text"
                        name="q"
                        placeholder="<?= htmlspecialchars(sf_term('filter_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                        value="<?= htmlspecialchars($q) ?>"
                    >
                    <button class="btn-icon" type="submit">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="filter-item">
                <label for="f-from">
                    <?= htmlspecialchars(sf_term('filter_date_from', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input id="f-from" type="date" name="date_from" value="<?= htmlspecialchars($from) ?>">
            </div>

            <div class="filter-item">
                <label for="f-to">
                    <?= htmlspecialchars(sf_term('filter_date_to', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input id="f-to" type="date" name="date_to" value="<?= htmlspecialchars($to) ?>">
            </div>

            <div class="filter-item">
                <label for="f-archived">
                    <?= htmlspecialchars(sf_term('filter_archived', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select id="f-archived" name="archived">
                    <option value="" <?= $archived === '' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('filter_show_active', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="only" <?= $archived === 'only' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('filter_archived', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="all" <?= $archived === 'all' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                </select>
            </div>

            <div class="filter-actions">
                <button class="btn-primary" type="submit" id="filter-submit-btn">
                    <?= htmlspecialchars(sf_term('filter_apply', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <a class="btn-secondary" href="<?= $baseUrl ?>/index.php?page=list" id="filter-clear-btn">
                    <?= htmlspecialchars(sf_term('filter_clear', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </div>
    </form>

    <!-- LIST -->
<!-- ADMIN: MONIVALINTAPOISTO -->
<?php if ($isAdmin): ?>
<div class="sf-bulk-actions" id="sfBulkActions">
    <div class="sf-bulk-bar">
        <label class="sf-bulk-select-all">
            <input type="checkbox" id="sfSelectAll">
            <span><?= htmlspecialchars(sf_term('select_all', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </label>

        <span class="sf-bulk-count" id="sfBulkCount"><?= htmlspecialchars(sf_term('selected_count_zero', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>

<button type="button" class="sf-btn sf-btn-danger" id="sfBulkDelete" disabled>
    <img src="<?= $baseUrl ?>/assets/img/icons/delete_icon.svg" alt="" class="btn-icon-img">
    <?= htmlspecialchars(sf_term('btn_delete_selected', $uiLang), ENT_QUOTES, 'UTF-8') ?>
</button>
    </div>
</div>
<?php endif; ?>

<!-- LIST -->
<div class="sf-list-container view-list">
<div class="skeleton-wrapper">
    <!-- Skeleton loading cards (shown while data is loading, overlays content) -->
    <div class="skeleton-container" id="skeletonContainer">
        <?php for ($i = 0; $i < 5; $i++): ?>
        <div class="skeleton-card">
            <div class="skeleton skeleton-thumb"></div>
            <div class="card-mid">
                <div class="skeleton skeleton-badge"></div>
                <div class="skeleton skeleton-title"></div>
                <div class="skeleton skeleton-text"></div>
                <div class="skeleton skeleton-meta"></div>
            </div>
        </div>
        <?php endfor; ?>
    </div>

    <!-- Actual content (underneath skeleton until loaded) -->
    <div class="cards-container">
    <div class="card-list">

<?php 
// Tyypin avaimet käännöksiä varten
$typeKeyMap = [
    'red'    => 'first_release',
    'yellow' => 'dangerous_situation',
    'green'  => 'investigation_report',
];

// Fetch all translations at once to avoid N+1 queries
$allGroupIds = [];
foreach ($rows as $r) {
    $gid = !empty($r['translation_group_id']) ? (int)$r['translation_group_id'] : (int)$r['id'];
    $allGroupIds[$gid] = $gid; // Use as key to avoid duplicates
}
$allTranslations = sf_get_all_translations($pdo, array_values($allGroupIds));

// PROSESSOINNISSA OLEVAT KORTIT - POISTETTU
// Käytetään vain pollausta taustalla, ei näytetä kortteja listassa
?>

<?php if (empty($rows) && empty($processingFlashes)): ?>
    <div class="no-results-box">
        <div class="no-results-icon-wrap">
            <svg class="no-results-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                <line x1="8" y1="11" x2="14" y2="11"/>
            </svg>
        </div>

        <p class="no-results-text">
            <?= htmlspecialchars(sf_term('no_results', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <p class="no-results-hint">
            <?= htmlspecialchars(sf_term('no_results_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <a href="<?= $baseUrl ?>/index.php?page=form" class="no-results-cta">
            <img src="<?= $baseUrl ?>/assets/img/icons/add_new_icon.png"
                 alt=""
                 class="no-results-cta-icon"
                 aria-hidden="true">
            <span><?= htmlspecialchars(sf_term('nav_new_safetyflash', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </a>
    </div>
<?php endif; ?>

<?php foreach ($rows as $r):
    $badgeClass = typeBadgeClass($r['type']);

    $typeKey   = $typeKeyMap[$r['type']] ?? null;
    $typeLabel = $typeKey ? sf_term($typeKey, $currentUiLang) : 'Safetyflash';

    $stateText = sf_status_label($r['state'], $currentUiLang);
    $stateClassMap = [
        'draft'          => 'status-draft',
        'pending_review' => 'status-pending',
        'request_info'   => 'status-request-info',
        'reviewed'       => 'status-reviewed',
        'to_comms'       => 'status-to-comms',
        'published'      => 'status-published',
    ];
    $stateClass = $stateClassMap[$r['state']] ?? 'status-other';

    $thumb = $r['preview_filename']
        ? "$baseUrl/uploads/previews/" . $r['preview_filename']
        : "$baseUrl/assets/img/camera-placeholder.png";

    $title = trim((string)($r['title'] ?? ''));
    if ($title === '') {
        $title = trim((string)($r['title_short'] ?? ''));
    }
    if ($title === '') {
        $title = trim((string)($r['summary'] ?? '')) ?: sf_term('no_title', $uiLang);
    }

    $siteText    = $r['site'] . (!empty($r['site_detail']) ? " – " . $r['site_detail'] : "");
    $groupId     = !empty($r['translation_group_id']) ? (int)$r['translation_group_id'] : (int)$r['id'];
    $translations = $allTranslations[$groupId] ?? [];
    $baseLang    = $r['lang'] ?: 'fi';
    
    // Tarkista onko tämä kortti prosessoinnissa TAI juuri luotu (bg_process parametri)
    $isProcessing = false;

    // Tapa 1: Tarkista is_processing tietokannasta
    foreach ($processingFlashes as $proc) {
        if ((int)$proc['id'] === (int)$r['id']) {
            $isProcessing = true;
            break;
        }
    }

    // Tapa 2: Tarkista bg_process URL-parametri (juuri luotu flash)
    // Tämä yliajaa tietokantatilanteen jos kortti on bg_process parametrissa
    if ($bgProcessId > 0 && (int)$r['id'] === $bgProcessId) {
        $isProcessing = true;
    }

    // Lisää piilotusluokka jos prosessoinnissa
    $hiddenClass = $isProcessing ? ' sf-card-hidden-processing' : '';

    // EI animaatiota sivun latauksessa - animaatio tulee vasta kun polling paljastaa kortin
    $newCardClass = '';
    
    // Data-attribuutit suodatusta varten
    // Tapahtumapäivämäärä suodatusta varten (occurred_at, fallback to created_at)
    $cardDate = '';
    if (!empty($r['occurred_at'])) {
        $timestamp = strtotime($r['occurred_at']);
        if ($timestamp !== false) {
            $cardDate = date('Y-m-d', $timestamp);  // YYYY-MM-DD muoto
        }
    }
    
    // Jos occurred_at on tyhjä, käytä created_at fallbackina
    if (empty($cardDate) && !empty($r['created_at'])) {
        $timestamp = strtotime($r['created_at']);
        if ($timestamp !== false) {
            $cardDate = date('Y-m-d', $timestamp);
        }
    }
    
    $cardArchived = !empty($r['is_archived']) && (int)$r['is_archived'] === 1 ? '1' : '0';
?>
<div class="card type-<?= htmlspecialchars($r['type'] ?? '') ?><?= $hiddenClass ?><?= $newCardClass ?>" 
     data-flash-id="<?= (int)$r['id'] ?>"
     data-type="<?= htmlspecialchars($r['type'] ?? '') ?>"
     data-state="<?= htmlspecialchars($r['state'] ?? '') ?>"
     data-site="<?= htmlspecialchars($r['site'] ?? '') ?>"
     data-title="<?= htmlspecialchars($title) ?>"
     data-date="<?= htmlspecialchars($cardDate) ?>"
     data-archived="<?= htmlspecialchars($cardArchived) ?>">

<div class="card-thumb-wrapper">
    <a href="<?= $baseUrl ?>/index.php?page=view&id=<?= (int)$r['id'] ?>" class="card-thumb">
        <img src="<?= htmlspecialchars($thumb) ?>" 
             alt="thumb"
             loading="lazy"
             decoding="async"
             class="sf-card-image">
    </a>

    <?php if ($isAdmin): ?>
        <label class="card-checkbox" onclick="event.stopPropagation();">
            <input
                type="checkbox"
                class="sf-flash-checkbox"
                value="<?= (int)$r['id'] ?>"
                onclick="event.stopPropagation();"
            >
        </label>
    <?php endif; ?>

    <?php if (!empty($r['new_comment_count']) && (int)$r['new_comment_count'] > 0): ?>
        <span class="comment-badge"
              title="<?= (int)$r['new_comment_count'] ?> <?= htmlspecialchars(sf_term('new_comments', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg class="comment-badge-icon" viewBox="0 0 100 100" fill="currentColor">
                <path d="M100 10.495v67.2c0 2.212-1.793 4.005-4.005 4.005H68.53c-1.063 0-2.082.422-2.833 1.174L51.412 97.167c-1.564 1.565-4.1 1.565-5.665 0L31.453 82.874c-.751-.751-1.77-1.173-2.833-1.173H4.005C1.793 81.7 0 79.907 0 77.695v-67.2C0 8.283 1.793 6.49 4.005 6.49h91.99C98.207 6.49 100 8.283 100 10.495z"/>
            </svg>
            <span class="comment-badge-count"><?= (int)$r['new_comment_count'] ?></span>
        </span>
    <?php endif; ?>
</div>

    <!-- Type indicator for compact view -->
    <div class="type-indicator <?= htmlspecialchars($r['type'] ?? 'default') ?>"></div>

    <div class="card-mid">
        <div class="card-top">
            <div class="left">
                <span class="badge <?= htmlspecialchars($badgeClass) ?>">
                    <?= htmlspecialchars($typeLabel) ?>
                </span>
                <span class="status <?= htmlspecialchars($stateClass) ?>">
                    <?= htmlspecialchars($stateText) ?>
                </span>
                <?php if (!empty($r['is_archived']) && (int)$r['is_archived'] === 1): ?>
                    <span class="badge badge-archived">
                        <?= htmlspecialchars(sf_term('archived', $currentUiLang) ?: 'ARKISTOITU') ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-title"><?= htmlspecialchars($title) ?></div>
        <div class="card-site"><?= htmlspecialchars($siteText) ?></div>

        <div class="card-meta">
            <span class="card-date">
                <svg class="meta-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                <?= htmlspecialchars($r['occurredFmt'] ?? '') ?>
            </span>

            <span class="card-updated">
                <?= htmlspecialchars(sf_term('card_modified', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:
                <?= htmlspecialchars($r['updatedFmt'] ?? '') ?>
            </span>
         </div>
    </div>

    <div class="card-actions">
        <a href="<?= $baseUrl ?>/index.php?page=view&id=<?= (int)$r['id'] ?>" class="open-btn">
            <?= htmlspecialchars(sf_term('card_open', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </a>

        <div class="card-lang-actions">
            <?php if (isset($supportedLangs[$baseLang])): ?>
               <a href="<?= $baseUrl ?>/index.php?page=view&id=<?= (int)$r['id'] ?>" class="lang-flag-link">
                    <img class="list-lang-flag"
                         src="<?= $baseUrl ?>/assets/img/<?= $supportedLangs[$baseLang]['icon'] ?>"
                         alt="<?= htmlspecialchars($supportedLangs[$baseLang]['label']) ?>">
                </a>
            <?php endif; ?>

            <?php foreach ($supportedLangs as $langCode => $langData): ?>
                <?php if ($langCode !== $baseLang && isset($translations[$langCode])): ?>
                    <a href="<?= $baseUrl ?>/index.php?page=view&id=<?= (int)$translations[$langCode] ?>" class="lang-flag-link">
                        <img class="list-lang-flag"
                             src="<?= $baseUrl ?>/assets/img/<?= $langData['icon'] ?>"
                             alt="<?= htmlspecialchars($langData['label']) ?>">
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>    </div> <!-- .card-list -->
</div> <!-- .cards-container -->
</div> <!-- .skeleton-wrapper -->
</div> <!-- .sf-list-container -->
<?php if ($isAdmin): ?>
<!-- POISTOVAHVISTUS-MODAALI -->
<div class="sf-modal hidden" id="modalBulkDelete" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <h2><?= htmlspecialchars(sf_term('confirm_delete_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
        <p id="sfBulkDeleteText"><?= htmlspecialchars(sf_term('bulk_delete_confirm_text', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
        <div class="sf-bulk-delete-list" id="sfBulkDeleteList"></div>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalBulkDelete"><?= htmlspecialchars(sf_term('btn_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="sf-btn sf-btn-danger" id="sfConfirmBulkDelete"><?= htmlspecialchars(sf_term('btn_delete_permanently', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
</div>

<script>
(function() {
    const SF_I18N = {
        deleting: <?= json_encode(sf_term('deleting', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        delete_error: <?= json_encode(sf_term('delete_error', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        error_prefix: <?= json_encode(sf_term('error_prefix', $uiLang), JSON_UNESCAPED_UNICODE) ?>
    };
    const selectAll = document.getElementById('sfSelectAll');
    const bulkCount = document.getElementById('sfBulkCount');
    const bulkDeleteBtn = document.getElementById('sfBulkDelete');
    const bulkBar = document.querySelector('.sf-bulk-bar');
    const checkboxes = () => document.querySelectorAll('.sf-flash-checkbox');
    const modal = document.getElementById('modalBulkDelete');
    const confirmBtn = document.getElementById('sfConfirmBulkDelete');
    const deleteList = document.getElementById('sfBulkDeleteList');

    function updateCount() {
        const checked = document.querySelectorAll('.sf-flash-checkbox:checked');
        const count = checked.length;
        bulkCount.textContent = count + ' ' + <?= json_encode(sf_term('bulk_selected_suffix', $uiLang), JSON_UNESCAPED_UNICODE) ?>;
        bulkDeleteBtn.disabled = count === 0;
        
        // Toggle has-selections class
        if (bulkBar) {
            if (count > 0) {
                bulkBar.classList.add('has-selections');
            } else {
                bulkBar.classList.remove('has-selections');
            }
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes().forEach(cb => cb.checked = this.checked);
            updateCount();
        });
    }

    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('sf-flash-checkbox')) {
            updateCount();
            const all = checkboxes();
            const checkedCount = document.querySelectorAll('.sf-flash-checkbox:checked').length;
            if (selectAll) selectAll.checked = checkedCount === all.length && all.length > 0;
        }
    });

    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function() {
            const checked = document.querySelectorAll('.sf-flash-checkbox:checked');
            if (checked.length === 0) return;

            let html = '<ul>';
            checked.forEach(cb => {
                const card = cb.closest('.card');
                const title = card?.querySelector('.card-title')?.textContent || 'ID: ' + cb.value;
                html += '<li>' + title + '</li>';
            });
            html += '</ul>';
            deleteList.innerHTML = html;

            modal.classList.remove('hidden');
        });
    }

    document.querySelectorAll('[data-modal-close="modalBulkDelete"]').forEach(btn => {
        btn.addEventListener('click', () => modal.classList.add('hidden'));
    });

    if (confirmBtn) {
        confirmBtn.addEventListener('click', async function() {
            const checked = document.querySelectorAll('.sf-flash-checkbox:checked');
            const ids = Array.from(checked).map(cb => parseInt(cb.value));

            if (ids.length === 0) return;

confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<span class="btn-spinner"></span> ' + SF_I18N.deleting;

try {
const csrfToken = '<?= sf_csrf_token() ?>';

const response = await fetch('app/actions/bulk_delete.php', {
    method: 'POST',
    credentials: 'include',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken
    },
    body: JSON.stringify({
        ids: ids,
        csrf_token: csrfToken
    })
});

    const raw = await response.text();
    let result = null;
    try {
        result = raw ? JSON.parse(raw) : null;
    } catch (e) {
        console.error('Bulk delete non-JSON response:', raw);
        throw new Error(raw || 'Invalid server response');
    }

    // Yhtenäinen tulkinta (backendiä on ollut eri versioita: success vs ok)
    const isSuccess = !!(result && (result.success === true || result.ok === true));

    // Autentikointi / sessio vanhentunut
    if (response.status === 401) {
        window.location.href = (window.SF_BASE_URL || '') + '/app/pages/login.php?reason=expired';
        return;
    }

    if (isSuccess) {
        // Uudelleenohjaa samalle sivulle notice-parametrilla
        const url = new URL(window.location.href);
        url.searchParams.set('notice', 'bulk_deleted');
        url.searchParams.set('count', String(result.deleted || ids.length));
        window.location.href = url.toString();
    } else {
        const msg = (result && (result.error || result.message)) || 'Tuntematon virhe';
if (typeof window.sfToast === 'function') window.sfToast('error', msg);
else alert(SF_I18N.error_prefix + ' ' + msg);
    }
} catch (err) {
    console.error('Bulk delete error:', err);
if (typeof window.sfToast === 'function') window.sfToast('error', SF_I18N.delete_error);
else alert(SF_I18N.delete_error);
} finally {
    confirmBtn.disabled = false;
            confirmBtn.textContent = <?= json_encode(sf_term('btn_delete_permanently', $uiLang), JSON_UNESCAPED_UNICODE) ?>;
}
        });
    }
})();
// Lisää tämä bulk delete scriptin JÄLKEEN

// Checkbox-valittu kortin korostus (fallback :has() -selektorille)
document.addEventListener('change', function (e) {
    if (e.target.classList.contains('sf-flash-checkbox')) {
        const card = e.target.closest('.card');
        if (card) {
            card.classList.toggle('sf-card-selected', e.target.checked);
        }
    }
});
</script>


<?php endif; ?>

<!-- Suodattimien toggle (kaikille käyttäjille) -->
<script>
(function() {
    const toggle = document.getElementById('filtersToggle');
    const grid = document.getElementById('filtersGrid');

    if (toggle && grid) {
        toggle.addEventListener('click', function() {
            toggle.classList.toggle('open');
            grid.classList.toggle('open');
        });

        // Jos on aktiivisia suodattimia, avaa automaattisesti
        const hasFilters = <?= json_encode(
            $type !== '' ||
            $state !== '' ||
            ($site !== '' && $site !== $homeWorksiteName) ||
            $q !== '' ||
            $from !== '' ||
            $to !== ''
        ) ?>;

        if (hasFilters) {
            toggle.classList.add('open');
            grid.classList.add('open');
        }
    }
})();
</script>

<!-- Prosessoitavien flashien tilan seuranta - bg_process parametrilla -->
<script>
(function() {
    const baseUrl = <?= json_encode($baseUrl) ?>;
    
    // Hae bg_process parametri URL:sta
    const urlParams = new URLSearchParams(window.location.search);
    const bgProcessId = urlParams.get('bg_process');
    
    // Jos ei ole bg_process parametria, ei tarvita pollausta
    if (!bgProcessId) return;
    
    const flashId = parseInt(bgProcessId, 10);
    if (isNaN(flashId) || flashId <= 0) return;
    
    const i18n = {
        processing: <?= json_encode(sf_term('processing_flash', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        complete: <?= json_encode(sf_term('processing_complete', $uiLang), JSON_UNESCAPED_UNICODE) ?>
    };
    
    let pollInterval = 1500;
    const maxInterval = 8000;
    let pollCount = 0;
    const maxPolls = 30; // Lopeta 30 yrityksen jälkeen (noin 2-3 min)
    
    async function checkStatus() {
        try {
            const response = await fetch(baseUrl + '/app/api/check_processing_status.php?flash_id=' + flashId);
            if (!response.ok) return null;
            return await response.json();
        } catch (e) {
            console.error('Status check error:', e);
            return null;
        }
    }
    
    async function pollProcessing() {
        pollCount++;
        
        // Lopeta jos liikaa yrityksiä
        if (pollCount > maxPolls) {
            console.warn('Max polls reached for flash', flashId);
            // Näytä kortti joka tapauksessa, mutta ilman success-toastia
            const card = document.querySelector(`.card[data-flash-id="${flashId}"]`);
            if (card && card.classList.contains('sf-card-hidden-processing')) {
                card.classList.remove('sf-card-hidden-processing');
                // Ei animaatiota jos timeout
            }
            // Poista bg_process parametri
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('bg_process');
            window.history.replaceState({}, '', newUrl.toString());
            return;
        }
        
        const status = await checkStatus();
        
        if (status && status.is_processing === false) {
            // Valmis! Näytä kortti animaatiolla
            showCard();
        } else if (status === null) {
            // Virhe API-kutsussa - yritä uudelleen hitaammin
            pollInterval = Math.min(pollInterval * 1.3, maxInterval);
            setTimeout(pollProcessing, pollInterval);
        } else {
            // Vielä prosessoidaan - jatka pollausta
            pollInterval = Math.min(pollInterval * 1.3, maxInterval);
            setTimeout(pollProcessing, pollInterval);
        }
    }
    
    function showCard() {
        const card = document.querySelector(`.card[data-flash-id="${flashId}"]`);
        
        if (card && card.classList.contains('sf-card-hidden-processing')) {
            // Poista piilotus ja lisää animaatio
            card.classList.remove('sf-card-hidden-processing');
            card.classList.add('sf-card-appear');
            
            // Poista animaatioluokka 1s jälkeen
            setTimeout(() => {
                card.classList.remove('sf-card-appear');
            }, 1000);
            
            // Näytä toast
            if (typeof window.sfToast === 'function') {
                window.sfToast('success', '✓ ' + i18n.complete);
            }
            
            // Poista bg_process parametri URL:sta (estää uudelleen-animoinnin)
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('bg_process');
            window.history.replaceState({}, '', newUrl.toString());
        } else if (!card) {
            // Korttia ei löydy DOM:sta - poista bg_process ja lataa uudelleen
            // Tämä voi tapahtua jos kortti ei ole näkyvissä suodattimien takia
            console.warn('Card not found in DOM, reloading page');
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('bg_process');
            window.location.href = newUrl.toString();
        } else {
            // Kortti löytyy mutta ei ole piilotettu - poista vain bg_process
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('bg_process');
            window.history.replaceState({}, '', newUrl.toString());
        }
    }
    
    // Aloita polling pienellä viiveellä
    setTimeout(pollProcessing, 500);
})();
</script>

<!-- BOTTOM SHEET (Mobile) -->
<div class="sf-bottom-sheet" id="sfBottomSheet">
    <div class="sf-bottom-sheet-backdrop" id="sfBottomSheetBackdrop"></div>
    <div class="sf-bottom-sheet-content" id="sfBottomSheetContent">
        <div class="sf-bottom-sheet-handle"></div>
        <div class="sf-bottom-sheet-header">
            <h3 id="sfBottomSheetTitle"></h3>
        </div>
        <div class="sf-bottom-sheet-body" id="sfBottomSheetBody">
            <!-- Content will be dynamically populated -->
        </div>
        <div class="sf-bottom-sheet-footer">
            <button type="button" class="sf-btn-secondary" id="sfBottomSheetClear">
                <?= htmlspecialchars(sf_term('filter_clear', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn-primary" id="sfBottomSheetDone">
                <?= htmlspecialchars(sf_term('filter_done', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

</div> <!-- .sf-list-page -->

<!-- Client-side filtering -->
<script>
// Pass translations to JavaScript
window.SF_LIST_I18N = {
    currentLang: <?= json_encode($currentUiLang, JSON_UNESCAPED_UNICODE) ?>,
    filterNoResults: <?= json_encode(sf_term('filter_no_results', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    noResultsHint: <?= json_encode(sf_term('no_results_hint', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterResultsCount: <?= json_encode(sf_term('filter_results_count', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    typeRed: <?= json_encode(sf_term('first_release', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    typeYellow: <?= json_encode(sf_term('dangerous_situation', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    typeGreen: <?= json_encode(sf_term('investigation_report', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterType: <?= json_encode(sf_term('filter_type', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterState: <?= json_encode(sf_term('filter_state', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterSite: <?= json_encode(sf_term('filter_site', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterDate: <?= json_encode(sf_term('filter_chip_date', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterChipTypeAll: <?= json_encode(sf_term('filter_chip_type_all', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterChipStateAll: <?= json_encode(sf_term('filter_chip_state_all', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterChipSiteAll: <?= json_encode(sf_term('filter_chip_site_all', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterDateFrom: <?= json_encode(sf_term('date_from', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterDateTo: <?= json_encode(sf_term('date_to', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterApply: <?= json_encode(sf_term('filter_apply', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    dateTimespanHeader: <?= json_encode(sf_term('date_timespan_header', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePresetAll: <?= json_encode(sf_term('date_preset_all', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePreset7days: <?= json_encode(sf_term('date_preset_7days', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePreset7daysShort: <?= json_encode(sf_term('date_preset_7days_short', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePreset30days: <?= json_encode(sf_term('date_preset_30days', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePreset30daysShort: <?= json_encode(sf_term('date_preset_30days_short', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePresetMonth: <?= json_encode(sf_term('date_preset_month', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePresetMonthShort: <?= json_encode(sf_term('date_preset_month_short', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePresetYear: <?= json_encode(sf_term('date_preset_year', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePresetCustom: <?= json_encode(sf_term('date_preset_custom', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    dateMonthHeader: <?= json_encode(sf_term('date_month_header', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    dateClear: <?= json_encode(sf_term('date_clear', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    monthNamesShort: <?= json_encode(sf_term('month_names_short', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= $baseUrl ?>/assets/js/list-filters.js"></script>

<!-- Copy to Clipboard -->
<link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/copy-to-clipboard.css">
<script src="<?= $baseUrl ?>/assets/js/copy-to-clipboard.js"></script>

<script>
// Initialize copy buttons for list items
document.addEventListener('DOMContentLoaded', function() {
    if (window.SafetyFlashCopy) {
        // Set base URL for use in copy button
        window.SF_BASE_URL = window.SF_BASE_URL || <?= json_encode($baseUrl) ?>;
        
        // Load translations
        window.SF_I18N = window.SF_I18N || {};
        window.SF_I18N.copy_image = <?= json_encode(sf_term('copy_image', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;
        window.SF_I18N.copying_image = <?= json_encode(sf_term('copying_image', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;
        window.SF_I18N.image_copied = <?= json_encode(sf_term('image_copied', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;
        window.SF_I18N.copy_failed = <?= json_encode(sf_term('copy_failed', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;

        // Add copy buttons to all card thumbnails
        const cardThumbs = document.querySelectorAll('.card-thumb');
        cardThumbs.forEach(function(thumb) {
            // Skip if already has a copy button
            if (thumb.querySelector('.sf-copy-btn')) return;
            
            // Get the image element
            const img = thumb.querySelector('.sf-card-image');
            if (!img) return;

            // Add copy button with compact styling - POSITION BOTTOM LEFT
            window.SafetyFlashCopy.addCopyButton(thumb, {
                label: window.SF_I18N.copy_image,
                copyingLabel: window.SF_I18N.copying_image,
                successMessage: window.SF_I18N.image_copied,
                errorMessage: window.SF_I18N.copy_failed,
                position: 'bottom-left',  // Changed from top-right
                className: 'sf-copy-btn-compact',
                // Use the existing copy-icon.svg
                iconSvg: '<img src="' + encodeURI(window.SF_BASE_URL || '') + '/assets/img/icons/copy-icon.svg" alt="" width="14" height="14">'
            });
        });
    }
});
</script>

<!-- List Views (Grid/List/Compact) -->
<script src="<?= $baseUrl ?>/assets/js/list-views.js"></script>

<!-- Mobile: Whole card clickable -->
<script>
// Mobile: Make entire card clickable (except copy button and checkbox)
// MOBILE_BREAKPOINT: 768px (matches CSS and list-views.js)
document.addEventListener('DOMContentLoaded', function() {
    const MOBILE_BREAKPOINT = 768; // px
    if (window.innerWidth <= MOBILE_BREAKPOINT) {
        const cards = document.querySelectorAll('.card');
        cards.forEach(function(card) {
            card.addEventListener('click', function(e) {
                // Don't navigate if clicking on copy button, checkbox, or links
                if (e.target.closest('.sf-copy-btn') || 
                    e.target.closest('input[type="checkbox"]') ||
                    e.target.closest('a')) {
                    return;
                }
                
                // Get the view URL from the open button
                const openBtn = card.querySelector('.open-btn');
                if (openBtn && openBtn.href) {
                    window.location.href = openBtn.href;
                }
            });
        });
    }
});
</script>
