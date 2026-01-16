<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// Ladataan sovelluksen lokifunktio
require_once __DIR__ . '/../includes/log_app.php';

try {
    sf_app_log("[create_language_version] API called - starting");

    // Polut
    $configPath = __DIR__ . '/../../config.php';
    $authPath   = __DIR__ . '/../includes/auth.php';

    if (!file_exists($configPath)) {
        sf_app_log("[create_language_version] ERROR: config.php not found at: {$configPath}");
        echo json_encode(['success' => false, 'error' => 'Konfiguraatiotiedostoa ei loydy']);
        exit;
    }
    if (!file_exists($authPath)) {
        sf_app_log("[create_language_version] ERROR: auth.php not found at: {$authPath}");
        echo json_encode(['success' => false, 'error' => 'Auth-tiedostoa ei loydy']);
        exit;
    }

    require_once $configPath;
    require_once $authPath;

    sf_app_log("[create_language_version] Config and auth loaded");

    if (!isset($config)) {
        sf_app_log("[create_language_version] ERROR: \$config not defined");
        echo json_encode(['success' => false, 'error' => 'Konfiguraatio puuttuu']);
        exit;
    }

    if (!function_exists('sf_current_user')) {
        sf_app_log("[create_language_version] ERROR: sf_current_user function not found");
        echo json_encode(['success' => false, 'error' => 'Auth-funktio puuttuu']);
        exit;
    }

    $currentUser = sf_current_user();
    if (!$currentUser) {
        sf_app_log("[create_language_version] ERROR: User not logged in");
        echo json_encode(['success' => false, 'error' => 'Kirjautuminen vaaditaan']);
        exit;
    }

    sf_app_log("[create_language_version] User authenticated: ID=" . $currentUser['id']);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        sf_app_log("[create_language_version] ERROR: Invalid method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
        echo json_encode(['success' => false, 'error' => 'Vain POST sallittu']);
        exit;
    }

    // Hae parametrit
    $sourceId         = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
    $targetLang       = isset($_POST['target_lang']) ? trim($_POST['target_lang']) : '';
    $titleShort       = isset($_POST['title_short']) ? trim($_POST['title_short']) : '';
    $description      = isset($_POST['description']) ? trim($_POST['description']) : '';
    $rootCauses       = isset($_POST['root_causes']) ? trim($_POST['root_causes']) : '';
    $actions          = isset($_POST['actions']) ? trim($_POST['actions']) : '';
    $previewImageData = $_POST['preview_image_data'] ?? '';

    sf_app_log("[create_language_version] Params: source_id={$sourceId}, target_lang={$targetLang}");

    if ($sourceId <= 0) {
        sf_app_log("[create_language_version] ERROR: Invalid source_id: {$sourceId}");
        echo json_encode(['success' => false, 'error' => 'Virheellinen lahde-ID']);
        exit;
    }

    $allowedLangs = ['fi', 'sv', 'en', 'it', 'el'];
    if (!in_array($targetLang, $allowedLangs, true)) {
        sf_app_log("[create_language_version] ERROR: Invalid target_lang: {$targetLang}");
        echo json_encode(['success' => false, 'error' => 'Virheellinen kohdekieli']);
        exit;
    }

    if ($titleShort === '' || $description === '') {
        sf_app_log("[create_language_version] ERROR: Missing required fields");
        echo json_encode(['success' => false, 'error' => 'Otsikko ja kuvaus ovat pakollisia']);
        exit;
    }

    // Tietokantayhteys
    sf_app_log("[create_language_version] Connecting to database");
    $pdo = Database::getInstance();
    sf_app_log("[create_language_version] Database connected");

    // Hae lahde-flash
    $stmt = $pdo->prepare("SELECT * FROM sf_flashes WHERE id = ?");
    $stmt->execute([$sourceId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        sf_app_log("[create_language_version] ERROR: Source flash not found: {$sourceId}");
        echo json_encode(['success' => false, 'error' => 'Lahdetiedotetta ei loytynyt']);
        exit;
    }

    sf_app_log("[create_language_version] Source flash found: type=" . $source['type']);

    // Translation group
    $groupId = !empty($source['translation_group_id'])
        ? (int)$source['translation_group_id']
        : (int)$source['id'];

    sf_app_log("[create_language_version] Translation group ID: {$groupId}");

    // Tarkista ettei kieliversio jo ole olemassa
    $checkStmt = $pdo->prepare("
        SELECT id FROM sf_flashes 
        WHERE (translation_group_id = ? OR id = ?) AND lang = ?
    ");
    $checkStmt->execute([$groupId, $groupId, $targetLang]);
    if ($checkStmt->fetch()) {
        sf_app_log("[create_language_version] ERROR: Translation already exists for lang: {$targetLang}");
        echo json_encode(['success' => false, 'error' => 'Kieliversio on jo olemassa']);
        exit;
    }

    // Preview-kuva
        $previewFilename = '';
        if (!empty($previewImageData) && strpos($previewImageData, 'data:image') === 0) {
            sf_app_log("[create_language_version] Processing preview image");
            
            // Käytä samaa funktiota kuin save_flash.php:ssä
            // Tarvitaan ENSIN funktio (lisää config.php:n jälkeen)
            if (!function_exists('sf_save_dataurl_preview_v2')) {
                /**
                 * Tallentaa dataURL-muotoisen kuvan JPG: ksi
                 */
                function sf_save_dataurl_preview_v2($dataurl, $uploadDir, $prefix = 'preview') {
                    if (empty($dataurl) || strpos($dataurl, 'data:image') !== 0) {
                        error_log('sf_save_dataurl_preview_v2: Invalid dataurl');
                        return false;
                    }

                    $parts = explode(',', $dataurl);
                    if (count($parts) !== 2) {
                        error_log('sf_save_dataurl_preview_v2: Could not parse dataurl');
                        return false;
                    }

                    $imageData = base64_decode($parts[1], true);
                    if ($imageData === false) {
                        error_log('sf_save_dataurl_preview_v2: base64_decode failed');
                        return false;
                    }

                    $filename = $prefix . '_' . time() . '_' . uniqid() . '.jpg';
                    $targetPath = $uploadDir . $filename;

                    $saved = false;

                    try {
                        if (extension_loaded('imagick')) {
                            error_log('sf_save_dataurl_preview_v2: Using Imagick');
                            $im = new Imagick();
                            $im->readImageBlob($imageData);
                            
                            if ($im->getImageAlphaChannel()) {
                                $im->setImageBackgroundColor('white');
                                $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                            }
                            
                            $im->cropThumbnailImage(1920, 1080);
                            $im->setImageFormat('jpeg');
                            $im->setImageCompression(Imagick::COMPRESSION_JPEG);
                            $im->setImageCompressionQuality(88);
                            $im->writeImage($targetPath);
                            $saved = true;
                            error_log('sf_save_dataurl_preview_v2: Saved with Imagick:  ' . $filename);
                        } else if (extension_loaded('gd')) {
                            error_log('sf_save_dataurl_preview_v2: Using GD');
                            $image = @imagecreatefromstring($imageData);
                            if ($image !== false) {
                                $src_width = imagesx($image);
                                $src_height = imagesy($image);
                                
                                $dst_width = 1920;
                                $dst_height = 1080;
                                $dst = imagecreatetruecolor($dst_width, $dst_height);
                                
                                $white = imagecolorallocate($dst, 255, 255, 255);
                                imagefill($dst, 0, 0, $white);
                                
                                imagecopyresampled(
                                    $dst, $image,
                                    0, 0, 0, 0,
                                    $dst_width, $dst_height,
                                    $src_width, $src_height
                                );
                                
                                imagejpeg($dst, $targetPath, 88);
                                imagedestroy($image);
                                imagedestroy($dst);
                                $saved = true;
                                error_log('sf_save_dataurl_preview_v2: Saved with GD: ' .  $filename);
                            }
                        }
                    } catch (Exception $e) {
                        error_log('sf_save_dataurl_preview_v2: Exception: ' . $e->getMessage());
                    }

                    if (!$saved) {
                        error_log('sf_save_dataurl_preview_v2: Failed to save image');
                        return false;
                    }

                    return $filename;
                }
            }
            
            $saved = sf_save_dataurl_preview_v2($previewImageData, __DIR__ . '/../../uploads/previews/', 'preview');
            if ($saved) {
                $previewFilename = $saved;
                sf_app_log("[create_language_version] Preview image saved: {$previewFilename}");
            } else {
                sf_app_log("[create_language_version] ERROR: Could not save preview image");
            }
        } else {
            sf_app_log("[create_language_version] No preview image data received");
        }
    // Lisaa uusi kieliversio
    sf_app_log("[create_language_version] Inserting new translation");

    $insertStmt = $pdo->prepare("
        INSERT INTO sf_flashes (
            type, title, title_short, summary, description, 
            root_causes, actions, site, site_detail, occurred_at,
            image_main, image_2, image_3,
            image1_transform, image2_transform, image3_transform,
            grid_style,
            lang, translation_group_id, state, preview_filename,
            created_by, created_at, updated_at
        ) VALUES (
            :type, :title, :title_short, :summary, :description,
            :root_causes, :actions, :site, :site_detail, :occurred_at,
            :image_main, :image_2, :image_3,
            :image1_transform, :image2_transform, :image3_transform,
            :grid_style,
            :lang, :translation_group_id, :state, :preview_filename,
            :created_by, NOW(), NOW()
        )
    ");

    $insertStmt->execute([
        ':type'                 => $source['type'],
        ':title'                => $source['title'],
        ':title_short'          => $titleShort,
        ':summary'              => $titleShort,
        ':description'          => $description,
        ':root_causes'          => $rootCauses,
        ':actions'              => $actions,
        ':site'                 => $source['site'],
        ':site_detail'          => $source['site_detail'],
        ':occurred_at'          => $source['occurred_at'],
        ':image_main'           => $source['image_main'],
        ':image_2'              => $source['image_2'],
        ':image_3'              => $source['image_3'],
        ':image1_transform'     => $source['image1_transform'],
        ':image2_transform'     => $source['image2_transform'],
        ':image3_transform'     => $source['image3_transform'],
        ':grid_style'           => $source['grid_style'] ?? 'grid-3-main-top',
        ':lang'                 => $targetLang,
        ':translation_group_id' => $groupId,
        ':state'                => 'draft',
        ':preview_filename'     => $previewFilename,
        ':created_by'           => (int)$currentUser['id'],
    ]);

    $newId = (int)$pdo->lastInsertId();
    sf_app_log("[create_language_version] New translation created with ID: {$newId}");

    // Paivita alkuperaisen flashin translation_group_id jos tyhja
    if (empty($source['translation_group_id'])) {
        $updateStmt = $pdo->prepare("UPDATE sf_flashes SET translation_group_id = ? WHERE id = ?");
        $updateStmt->execute([$source['id'], $source['id']]);
        sf_app_log("[create_language_version] Updated source flash translation_group_id");
    }

    // Kirjaa tapahtuma myös safetyflash_logs-tauluun
    require_once __DIR__ . '/../includes/log.php';
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
    $logDesc = sf_term('log_translation_created', $currentUiLang) . ":  {$targetLang}";
    sf_log_event($newId, 'CREATED', $logDesc);

    $base = rtrim($config['base_url'] ?? '', '/');

    sf_app_log("[create_language_version] SUCCESS - Translation created, redirecting to view");

    echo json_encode([
        'success'  => true,
        'message'  => sf_term('notice_translation_created', $currentUiLang),
        'new_id'   => $newId,
        'redirect' => $base . '/index.php?page=view&id=' . $newId,
    ]);

} catch (PDOException $e) {
    sf_app_log(
        "[create_language_version] PDO ERROR: " .
        $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine()
    );
    echo json_encode([
        'success' => false,
        'error'   => 'Tietokantavirhe: ' . $e->getMessage(),
    ]);
} catch (Throwable $e) {
    sf_app_log(
        "[create_language_version] ERROR: " .
        $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine()
    );
    sf_app_log("[create_language_version] Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'error'   => 'Palvelinvirhe: ' . $e->getMessage(),
    ]);
}