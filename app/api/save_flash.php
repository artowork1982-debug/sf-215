<?php
// app/api/save_flash.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../lib/sf_terms.php';

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function sf_json_response(array $data, int $statusCode = 200): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function sf_finish_request(): void
{
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }

    if (function_exists('litespeed_finish_request')) {
        @litespeed_finish_request();
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ob_flush();
    @flush();
}

function sf_shell_exec_available(): bool
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = (string) ini_get('disable_functions');
    if ($disabled === '') {
        return true;
    }

    $disabledList = array_map('trim', explode(',', $disabled));
    return !in_array('shell_exec', $disabledList, true);
}

// -----------------------------------------------------------------------------
// Request validation
// -----------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sf_json_response(['ok' => false, 'error' => 'Method Not Allowed'], 405);
    exit;
}

// CSRF protection
$csrfToken = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    sf_json_response(['ok' => false, 'error' => sf_term('error_csrf_invalid', $currentUiLang)], 403);
    exit;
}

$post  = $_POST;
$files = $_FILES;

$id = isset($post['id']) ? (int) $post['id'] : 0;

$title = trim((string) ($post['title'] ?? ''));
if ($title === '') {
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    sf_json_response(['ok' => false, 'error' => sf_term('error_title_required', $currentUiLang)], 400);
    exit;
}

$submissionType = trim((string) ($post['submission_type'] ?? 'review'));
$newState = ($submissionType === 'draft') ? 'draft' : 'pending_review';

// Map form field names -> DB field names
$site = trim((string) ($post['site'] ?? $post['worksite'] ?? ''));

$occurredRaw = trim((string) ($post['occurred_at'] ?? $post['event_date'] ?? ''));
$occurredAt  = null;
if ($occurredRaw !== '') {
    $ts = strtotime($occurredRaw);
    if ($ts !== false) {
        $occurredAt = date('Y-m-d H:i:s', $ts);
    }
}

$titleShort = trim((string) ($post['title_short'] ?? $post['short_text'] ?? ''));
$summary    = trim((string) ($post['summary'] ?? ''));
if ($summary === '' && $titleShort !== '') {
    $summary = $titleShort;
}

// Ensure created_by is set
$currentUser = sf_current_user();
$createdBy = null;

if ($currentUser && isset($currentUser['id'])) {
    $createdBy = (int) $currentUser['id'];
} elseif (isset($_SESSION['user_id'])) {
    $createdBy = (int) $_SESSION['user_id'];
}

if ($createdBy !== null && $createdBy <= 0) {
    $createdBy = null;
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();

    // Tarkista onko kyseessä tutkintatiedote joka päivittää olemassaolevan
    $relatedFlashId = isset($post['related_flash_id']) ? (int) $post['related_flash_id'] : 0;
    $type = trim((string) ($post['type'] ?? 'yellow'));
    $isInvestigationUpdate = ($type === 'green' && $relatedFlashId > 0 && $id === 0);

    // =========================================================================
    // MUOKKAUS: Olemassa olevan flashin päivitys
    // =========================================================================
    if ($id > 0) {
        $origStmt = $pdo->prepare("SELECT * FROM sf_flashes WHERE id = :id LIMIT 1");
        $origStmt->execute([':id' => $id]);
        $origFlash = $origStmt->fetch(PDO::FETCH_ASSOC);

        if (!$origFlash) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
            sf_json_response(['ok' => false, 'error' => sf_term('error_flash_not_found', $currentUiLang)], 404);
            exit;
        }

        $oldState = (string) ($origFlash['state'] ?? '');
        $oldType  = (string) ($origFlash['type'] ?? '');

        $sql = "UPDATE sf_flashes SET
            title = :title,
            title_short = :title_short,
            summary = :summary,
            description = :description,
            type = :type,
            site = :site,
            site_detail = :site_detail,
            occurred_at = :occurred_at,
            lang = :lang,
            state = :state,
            root_causes = :root_causes,
            actions = :actions,
            annotations_data = :annotations_data,
            image1_transform = :image1_transform,
            image2_transform = :image2_transform,
            image3_transform = :image3_transform,
            grid_layout = :grid_layout,
            grid_bitmap = :grid_bitmap,
            processing_status = 'pending',
            is_processing = 1,
            updated_at = NOW()
            WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':title'            => $title,
            ':title_short'      => $titleShort,
            ':summary'          => $summary,
            ':description'      => trim((string) ($post['description'] ?? '')),
            ':type'             => $type,
            ':site'             => $site,
            ':site_detail'      => trim((string) ($post['site_detail'] ?? '')),
            ':occurred_at'      => $occurredAt,
            ':lang'             => trim((string) ($post['lang'] ?? 'fi')),
            ':state'            => $newState,
            ':root_causes'      => trim((string) ($post['root_causes'] ?? '')),
            ':actions'          => trim((string) ($post['actions'] ?? '')),
            ':annotations_data' => trim((string) ($post['annotations_data'] ?? '[]')),
            ':image1_transform' => trim((string) ($post['image1_transform'] ?? '')),
            ':image2_transform' => trim((string) ($post['image2_transform'] ?? '')),
            ':image3_transform' => trim((string) ($post['image3_transform'] ?? '')),
            ':grid_layout'      => trim((string) ($post['grid_layout'] ?? 'grid-1')),
            ':grid_bitmap'      => trim((string) ($post['grid_bitmap'] ?? '')),
            ':id'               => $id,
        ]);

        $newId = $id;

        try {
            require_once __DIR__ . '/../includes/log.php';
            require_once __DIR__ . '/../includes/statuses.php';
            $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
            sf_log_event($id, 'edited', sf_term('log_flash_edited', $currentUiLang));

            if ($oldState !== $newState) {
                $oldStateLabel = sf_status_label($oldState, $currentUiLang);
                $newStateLabel = sf_status_label($newState, $currentUiLang);
                $logStatus = sf_term('log_state_changed', $currentUiLang) . ": {$oldStateLabel} → {$newStateLabel}";
                sf_log_event($id, 'state_changed', $logStatus);
            }
            if ($oldType !== $type) {
                $logType = sf_term('log_type_changed', $currentUiLang) . ": {$oldType} → {$type}";
                sf_log_event($id, 'type_changed', $logType);
            }
        } catch (Throwable $e) {
            error_log('save_flash: Lokitus epäonnistui (edit): ' . $e->getMessage());
        }

    // =========================================================================
    // TUTKINTATIEDOTE: Päivitä alkuperäinen safetyflash
    // =========================================================================
    } elseif ($isInvestigationUpdate) {
        $origStmt = $pdo->prepare("SELECT * FROM sf_flashes WHERE id = :id LIMIT 1");
        $origStmt->execute([':id' => $relatedFlashId]);
        $origFlash = $origStmt->fetch(PDO::FETCH_ASSOC);

        if (!$origFlash) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
            sf_json_response(['ok' => false, 'error' => sf_term('error_original_flash_not_found', $currentUiLang)], 404);
            exit;
        }

        $oldType = (string) ($origFlash['type'] ?? '');
        $oldState = (string) ($origFlash['state'] ?? '');
        $translationGroupId = !empty($origFlash['translation_group_id']) 
            ? (int) $origFlash['translation_group_id'] 
            : $relatedFlashId;

        // 1. Arkistoi alkuperäinen sisältö lokiin
        try {
            require_once __DIR__ . '/../includes/log.php';
            $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
            
            $originalData = [
                'id' => $origFlash['id'],
                'type' => $origFlash['type'],
                'title' => $origFlash['title'],
                'title_short' => $origFlash['title_short'],
                'description' => $origFlash['description'],
                'state' => $origFlash['state'],
                'lang' => $origFlash['lang'],
                'created_at' => $origFlash['created_at'],
                'updated_at' => $origFlash['updated_at'],
            ];
            
            $archiveDesc = sf_term('log_original_archived', $currentUiLang) . '|data:' . json_encode($originalData, JSON_UNESCAPED_UNICODE);
            sf_log_event($translationGroupId, 'original_archived', $archiveDesc);
        } catch (Throwable $e) {
            error_log('save_flash: Alkuperäisen arkistointi epäonnistui: ' . $e->getMessage());
        }

        // 2. Arkistoi ja poista kieliversiot
        try {
            $transStmt = $pdo->prepare("
                SELECT id, lang, title, title_short, description, state, created_at, updated_at
                FROM sf_flashes 
                WHERE translation_group_id = :group_id AND id != :id
            ");
            $transStmt->execute([':group_id' => $translationGroupId, ':id' => $translationGroupId]);
            $translations = $transStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($translations)) {
                $archiveData = json_encode($translations, JSON_UNESCAPED_UNICODE);
                $archiveDesc = sf_term('log_translations_archived', $currentUiLang) . '|count:' . count($translations) . '|data:' . $archiveData;
                sf_log_event($translationGroupId, 'translations_archived', $archiveDesc);
                
                // Poista kieliversiot
                $deleteStmt = $pdo->prepare("
                    DELETE FROM sf_flashes 
                    WHERE translation_group_id = :group_id AND id != :id
                ");
                $deleteStmt->execute([':group_id' => $translationGroupId, ':id' => $translationGroupId]);
            }
        } catch (Throwable $e) {
            error_log('save_flash: Kieliversioiden arkistointi epäonnistui: ' . $e->getMessage());
        }

        // 3. Päivitä alkuperäinen -> tutkintatiedote (SAMA ID!)
        $sql = "UPDATE sf_flashes SET
            type = 'green',
            title = :title,
            title_short = :title_short,
            summary = :summary,
            description = :description,
            root_causes = :root_causes,
            actions = :actions,
            state = :state,
            processing_status = 'pending',
            is_processing = 1,
            updated_at = NOW()
            WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':title'       => $title,
            ':title_short' => $titleShort,
            ':summary'     => $summary,
            ':description' => trim((string) ($post['description'] ?? '')),
            ':root_causes' => trim((string) ($post['root_causes'] ?? '')),
            ':actions'     => trim((string) ($post['actions'] ?? '')),
            ':state'       => $newState,
            ':id'          => $relatedFlashId,
        ]);

        $newId = $relatedFlashId;

        // 4. Kirjaa lokiin tutkintatiedotteen luonti
        try {
            require_once __DIR__ . '/../includes/statuses.php';
            $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
            
            sf_log_event($relatedFlashId, 'investigation_created', sf_term('log_investigation_created', $currentUiLang));
            
            if ($oldState !== $newState) {
                $oldStateLabel = sf_status_label($oldState, $currentUiLang);
                $newStateLabel = sf_status_label($newState, $currentUiLang);
                $logStatus = sf_term('log_state_changed', $currentUiLang) . ": {$oldStateLabel} → {$newStateLabel}";
                sf_log_event($relatedFlashId, 'state_changed', $logStatus);
            }
            
            $logType = sf_term('log_type_changed', $currentUiLang) . ": {$oldType} → green";
            sf_log_event($relatedFlashId, 'type_changed', $logType);
        } catch (Throwable $e) {
            error_log('save_flash: Lokitus epäonnistui (investigation): ' . $e->getMessage());
        }

    // =========================================================================
    // NORMAALI: Uuden luonti
    // =========================================================================
    } else {
        $sql = "INSERT INTO sf_flashes
            (title, title_short, summary, description, type, site, site_detail, occurred_at, lang, state, created_by,
             root_causes, actions, processing_status, is_processing, annotations_data, image1_transform, image2_transform, image3_transform, grid_layout, grid_bitmap,
             created_at, updated_at)
            VALUES
            (:title, :title_short, :summary, :description, :type, :site, :site_detail, :occurred_at, :lang, :state, :created_by,
             :root_causes, :actions, 'pending', 1, :annotations_data, :image1_transform, :image2_transform, :image3_transform, :grid_layout, :grid_bitmap,
             NOW(), NOW())";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':title'            => $title,
            ':title_short'      => $titleShort,
            ':summary'          => $summary,
            ':description'      => trim((string) ($post['description'] ?? '')),
            ':type'             => $type,
            ':site'             => $site,
            ':site_detail'      => trim((string) ($post['site_detail'] ?? '')),
            ':occurred_at'      => $occurredAt,
            ':lang'             => trim((string) ($post['lang'] ?? 'fi')),
            ':state'            => $newState,
            ':created_by'       => $createdBy,
            ':root_causes'      => trim((string) ($post['root_causes'] ?? '')),
            ':actions'          => trim((string) ($post['actions'] ?? '')),
            ':annotations_data' => trim((string) ($post['annotations_data'] ?? '[]')),
            ':image1_transform' => trim((string) ($post['image1_transform'] ?? '')),
            ':image2_transform' => trim((string) ($post['image2_transform'] ?? '')),
            ':image3_transform' => trim((string) ($post['image3_transform'] ?? '')),
            ':grid_layout'      => trim((string) ($post['grid_layout'] ?? 'grid-1')),
            ':grid_bitmap'      => trim((string) ($post['grid_bitmap'] ?? '')),
        ]);

        $newId = (int) $pdo->lastInsertId();

        try {
            require_once __DIR__ . '/../includes/log.php';
            require_once __DIR__ . '/../includes/statuses.php';
            $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
            sf_log_event($newId, 'created', 'flash_create');
            
            // Log initial state for new flashes
            if ($newState !== '') {
                $stateLabel = sf_status_label($newState, $currentUiLang);
                $logStatus = sf_term('log_state_changed', $currentUiLang) . ": → {$stateLabel}";
                sf_log_event($newId, 'state_changed', $logStatus);
            }
        } catch (Throwable $e) {
            error_log('save_flash: Lokitus epäonnistui (create): ' . $e->getMessage());
        }
    }

    // =========================================================================
    // TARKISTA JA KÄSITTELE TEMP-KUVAT (immediate upload)
    // =========================================================================
    $tempDir = __DIR__ . '/../../uploads/temp/';
    $imagesDir = __DIR__ . '/../../uploads/images/';
    
    if (!is_dir($imagesDir)) {
        @mkdir($imagesDir, 0755, true);
    }

    foreach ([1 => 'image_main', 2 => 'image_2', 3 => 'image_3'] as $slot => $dbColumn) {
        $tempFilename = trim((string)($post["temp_image{$slot}"] ?? ''));
        
        if ($tempFilename !== '' && strpos($tempFilename, 'temp_') === 0) {
            $tempPath = $tempDir . basename($tempFilename);
            
            if (is_file($tempPath)) {
                // Luo pysyvä tiedostonimi
                $ext = pathinfo($tempFilename, PATHINFO_EXTENSION) ?: 'jpg';
                $permanentFilename = 'img_' . $newId . '_' . $slot . '_' . time() . '.' . $ext;
                $permanentPath = $imagesDir . $permanentFilename;
                
                // Siirrä temp → pysyvä
                if (rename($tempPath, $permanentPath)) {
                    // Päivitä tietokantaan
                    $updateStmt = $pdo->prepare("UPDATE sf_flashes SET {$dbColumn} = :filename WHERE id = :id");
                    $updateStmt->execute([':filename' => $permanentFilename, ':id' => $newId]);
                }
            }
        }
    }

    // Tallenna väliaikaiset tiedot (kuvat ja dataURLit) omaan tiedostoon
    $tempDataDir = __DIR__ . '/../../uploads/processes/';
    if (!is_dir($tempDataDir)) {
        @mkdir($tempDataDir, 0755, true);
    }

    $jobData = ['post' => $post, 'files' => []];

    foreach ($files as $key => $file) {
        if (isset($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $safeName = preg_replace('/[^A-Za-z0-9\._-]/', '_', (string) ($file['name'] ?? 'file'));
            $tmpPath  = $tempDataDir . $newId . '_' . $key . '_' . $safeName;

            if (move_uploaded_file($file['tmp_name'], $tmpPath)) {
                $jobData['files'][$key] = $file;
                $jobData['files'][$key]['tmp_name'] = $tmpPath;
            }
        }
    }

    file_put_contents($tempDataDir . $newId . '.jobdata', json_encode($jobData, JSON_UNESCAPED_UNICODE));

    $pdo->commit();

    // =========================================================================
    // POISTA KÄYTTÄJÄN LUONNOKSET KUN LÄHETETÄÄN TARKISTUKSEEN
    // Luonnosten poisto EI tapahdu kun tallennetaan luonnosta (submission_type='draft')
    // (Transaktion ulkopuolella - varmistaa että poisto tapahtuu)
    // =========================================================================
    if ($submissionType === 'review' && $createdBy !== null && $createdBy > 0) {
        try {
            $deleteDraftStmt = $pdo->prepare("DELETE FROM sf_drafts WHERE user_id = ?");
            $deleteDraftStmt->execute([$createdBy]);
            error_log('save_flash: Deleted drafts for user_id=' . $createdBy);
        } catch (Throwable $e) {
            // Luonnosten poiston epäonnistuminen ei saa estää tallennusta
            error_log('save_flash: Draft deletion failed: ' . $e->getMessage());
        }
    }

    // Audit
    try {
        $action = ($id > 0) ? 'flash_update' :  'flash_create';
        sf_audit_log(
            $action,
            'flash',
            $newId,
            ['title' => $title, 'state' => $newState, 'type' => $type, 'site' => $site],
            $createdBy
        );
    } catch (Throwable $e) {
        error_log('save_flash:  Audit-lokitus epäonnistui: ' . $e->getMessage());
    }


    // Respond immediately
    $useShell = sf_shell_exec_available();
    $payload  = ['ok' => true, 'flash_id' => $newId, 'bg_mode' => $useShell ? 'shell_exec' : 'inline'];

    sf_json_response($payload, 200);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    sf_finish_request();

    @ignore_user_abort(true);

    if ($useShell) {
        $workerScript  = __DIR__ . '/process_flash_worker.php';
        $phpExecutable = PHP_BINARY ?: 'php';

        $command = escapeshellcmd($phpExecutable)
            . ' ' . escapeshellarg($workerScript)
            . ' ' . escapeshellarg((string) $newId)
            . ' > /dev/null 2>&1 &';

        @shell_exec($command);
        exit;
    }

    @set_time_limit(300);
    if (!defined('SF_ALLOW_WEB_WORKER')) {
        define('SF_ALLOW_WEB_WORKER', true);
    }

    $_GET['flash_id'] = (string) $newId;
    require __DIR__ . '/process_flash_worker.php';
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg = 'save_flash.php ERROR: ' . $e->getMessage();
    error_log($msg . "\n" . $e->getTraceAsString());

    if (function_exists('sf_app_log')) {
        sf_app_log($msg, LOG_LEVEL_ERROR, [
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'trace'       => $e->getTraceAsString(),
        ]);
    }
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    $resp = ['ok' => false, 'error' => sf_term('error_save_server', $currentUiLang)];
    if (!empty($config['debug'])) {
        $resp['debug'] = $e->getMessage();
    }

    sf_json_response($resp, 500);
    exit;
}