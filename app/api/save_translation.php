<?php
// app/api/save_translation.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';   // varmista, että käyttäjä on kirjautunut (tarvittaessa)
require_once __DIR__ . '/../includes/statuses.php';  // sf_status_label, sf_current_ui_lang yms.
require_once __DIR__ . '/../includes/log.php';       // sf_log_event
require_once __DIR__ . '/../lib/sf_terms.php';

// CSRF protection
$csrfToken = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    echo sf_term('error_csrf_invalid', $currentUiLang);
    exit;
}

// --- DB: PDO (sama malli kuin save_flash.php:ssa) ---
try {
    $pdo = new PDO(
        'mysql:host=' . $config['db']['host'] .
        ';dbname='   . $config['db']['name'] .
        ';charset='  . $config['db']['charset'],
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    error_log('save_translation.php DB ERROR: ' . $e->getMessage());
    http_response_code(500);
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    echo sf_term('error_db_connection', $currentUiLang);
    exit;
}

// Lukeminen POSTista (turvallista trimmausta)
$fromId  = isset($_POST['from_id']) ? (int) $_POST['from_id'] : 0;
$newLang = isset($_POST['lang']) ? trim((string)$_POST['lang']) : '';
$groupId = isset($_POST['translation_group_id']) ? (int) $_POST['translation_group_id'] : 0;

$titleShort  = isset($_POST['title_short']) ? trim((string)$_POST['title_short']) : '';
$summary     = isset($_POST['summary']) ? trim((string)$_POST['summary']) : '';
$description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';

if ($fromId <= 0 || $newLang === '') {
    http_response_code(400);
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    echo sf_term('error_missing_params', $currentUiLang);
    exit;
}

try {
    // Hae pohjaflash
    $stmt = $pdo->prepare('SELECT * FROM sf_flashes WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $fromId]);
    $baseFlash = $stmt->fetch();

    if (!$baseFlash) {
        http_response_code(404);
        $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
        echo sf_term('error_base_flash_not_found', $currentUiLang);
        exit;
    }

    // Varmista translation_group_id
    if ($groupId <= 0) {
        if (!empty($baseFlash['translation_group_id'])) {
            $groupId = (int) $baseFlash['translation_group_id'];
        } else {
            $groupId = (int) $baseFlash['id'];
        }
    }

    // Päivitä pohjatiedotteelle group id, jos puuttui
    if (empty($baseFlash['translation_group_id'])) {
        $u = $pdo->prepare('UPDATE sf_flashes SET translation_group_id = :gid WHERE id = :id');
        $u->execute([
            ':gid' => $groupId,
            ':id'  => (int) $baseFlash['id'],
        ]);
    }

    // Valmistele rivin data (kopioidaan tarvittavat kentät pohjaflashista)
    $type       = $baseFlash['type'] ?? '';
    $title      = $baseFlash['title'] ?? '';
    $site       = $baseFlash['site'] ?? '';
    $siteDetail = $baseFlash['site_detail'] ?? null;
    $occurredAt = $baseFlash['occurred_at'] ??  null;
    $imageMain  = $baseFlash['image_main'] ?? null;
    $image2     = $baseFlash['image_2'] ?? null;
    $image3     = $baseFlash['image_3'] ?? null;
    $preview    = $baseFlash['preview_filename'] ?? null;
    $rootCauses = $baseFlash['root_causes'] ?? '';
    $actions    = $baseFlash['actions'] ?? '';
    
    // UUSI:  Kopioi kuvien merkinnät ja transform-tiedot
    $annotationsData  = $baseFlash['annotations_data'] ?? '{}';
    $image1Transform  = $baseFlash['image1_transform'] ?? '';
    $image2Transform  = $baseFlash['image2_transform'] ?? '';
    $image3Transform  = $baseFlash['image3_transform'] ?? '';
    $gridLayout       = $baseFlash['grid_layout'] ?? 'grid-1';
    $gridBitmap       = $baseFlash['grid_bitmap'] ?? '';

    // Käännös perii alkuperäisen tilan - ei muuteta
    $state = $baseFlash['state'] ??  'to_comms';
    
    // Set created_by to current user
    $createdBy = $_SESSION['user_id'] ?? null;
    
    // Handle JavaScript-generated preview image
    $previewFilename = $preview; // Oletuksena pohjaflashin preview
    
    $previewDataUrl = isset($_POST['preview_image_data']) ? trim((string)$_POST['preview_image_data']) : '';
    if (! empty($previewDataUrl) && strpos($previewDataUrl, 'data:image') === 0) {
        // Tallenna dataURL JPG: ksi
        $previewDir = __DIR__ . '/../../uploads/previews/';
        if (!is_dir($previewDir)) {
            @mkdir($previewDir, 0755, true);
        }
        
        $parts = explode(',', $previewDataUrl);
        if (count($parts) === 2) {
            $imageData = base64_decode($parts[1], true);
            if ($imageData !== false) {
                $newPreviewFilename = 'preview_' .  time() . '_' . uniqid() . '.jpg';
                $targetPath = $previewDir . $newPreviewFilename;
                
                if (extension_loaded('gd')) {
                    $image = @imagecreatefromstring($imageData);
                    if ($image !== false) {
                        $dst = imagecreatetruecolor(1920, 1080);
                        $white = imagecolorallocate($dst, 255, 255, 255);
                        imagefill($dst, 0, 0, $white);
                        imagecopyresampled($dst, $image, 0, 0, 0, 0, 1920, 1080, imagesx($image), imagesy($image));
                        if (imagejpeg($dst, $targetPath, 90)) {
                            $previewFilename = $newPreviewFilename;
                        }
                        imagedestroy($image);
                        imagedestroy($dst);
                    }
                } else {
                    // Fallback: tallenna suoraan
                    if (file_put_contents($targetPath, $imageData) !== false) {
                        $previewFilename = $newPreviewFilename;
                    }
                }
            }
        }
    }
    // INSERT uusi kieliversio
    $ins = $pdo->prepare('
        INSERT INTO sf_flashes 
        (translation_group_id, lang, type, title, title_short, summary, description, 
         site, site_detail, occurred_at, state, 
         image_main, image_2, image_3, preview_filename, preview_filename_2,
         annotations_data, image1_transform, image2_transform, image3_transform,
         grid_layout, grid_bitmap, root_causes, actions, created_by)
        VALUES
        (:tgid, :lang, :type, :title, :title_short, :summary, :description,
         :site, :site_detail, :occurred_at, :state,
         :image_main, :image_2, :image_3, :preview_filename, :preview_filename_2,
         :annotations_data, :image1_transform, :image2_transform, :image3_transform,
         :grid_layout, :grid_bitmap, :root_causes, :actions, :created_by)
    ');

    $ins->execute([
        ':tgid'               => $groupId,
        ':lang'               => $newLang,
        ':type'               => $type,
        ':title'              => $title,
        ':title_short'        => $titleShort,
        ':summary'            => $summary,
        ':description'        => $description,
        ':site'               => $site,
        ':site_detail'        => $siteDetail,
        ':occurred_at'        => $occurredAt,
        ':state'              => $state,
        ':image_main'         => $imageMain,
        ':image_2'            => $image2,
        ':image_3'            => $image3,
        ':preview_filename'   => $previewFilename,
        ':preview_filename_2' => '',
        ':annotations_data'   => $annotationsData,
        ':image1_transform'   => $image1Transform,
        ':image2_transform'   => $image2Transform,
        ':image3_transform'   => $image3Transform,
        ':grid_layout'        => $gridLayout,
        ':grid_bitmap'        => $gridBitmap,
        ':root_causes'        => $rootCauses,
        ':actions'            => $actions,
        ':created_by'         => $createdBy,
    ]);    
    $newId = (int) $pdo->lastInsertId();

    if ($newId) {
        // Lokissa käytetään aina ryhmän juurta → $groupId
        $logFlashId    = (int)$groupId;
        $currentUiLang = function_exists('sf_current_ui_lang') ? sf_current_ui_lang() : 'fi';
        $statusLabel   = function_exists('sf_status_label') ? sf_status_label($state, $currentUiLang) : $state;

        // 1) Perus merkintä: kieliversio tallennettu
        $descTemplate = sf_term('log_translation_saved', $currentUiLang);
        $statusPrefix = sf_term('log_status_prefix', $currentUiLang);
        $desc = str_replace('{lang}', $newLang, $descTemplate) . ". {$statusPrefix}:  {$statusLabel}.";

        if (function_exists('sf_log_event')) {
            sf_log_event($logFlashId, 'translation_saved', $desc);
        } else {
            // fallback: suora insert jos sf_log_event puuttuu
            $log = $pdo->prepare("
                INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
                VALUES (:flash_id, :user_id, :event_type, :description, NOW())
            ");
            $userId = $_SESSION['user_id'] ?? null;
            $log->execute([
                ':flash_id'   => $logFlashId,
                ':user_id'    => $userId,
                ':event_type' => 'translation_saved',
                ':description'=> $desc,
            ]);
        }

        // Lopuksi redirect uuteen kieliversioon + notice
        $base = rtrim($config['base_url'] ?? '', '/');
        header('Location:' .  $base . '/index.php?page=view&id=' .  $newId . '&notice=translation_saved');
        exit;
    }
    // jos ei insertattu
    http_response_code(500);
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    echo sf_term('error_translation_save_failed', $currentUiLang);
    exit;

} catch (Throwable $e) {
    error_log('save_translation.php ERROR: ' . $e->getMessage());
    http_response_code(500);
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    echo sf_term('error_save', $currentUiLang);
    exit;
}