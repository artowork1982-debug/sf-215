<?php
// app/api/process_flash_worker.php
declare(strict_types=1);

// This worker is primarily designed to be run from CLI.
// On shared hosting (where shell_exec is disabled) we also allow it to be
// executed inline from save_flash.php by defining SF_ALLOW_WEB_WORKER.
if (php_sapi_name() !== 'cli' && !defined('SF_ALLOW_WEB_WORKER')) {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

set_time_limit(300);

require_once __DIR__ . '/../../config.php';
// Database class is loaded via config.php (app/lib/Database.php)
require_once __DIR__ . '/../includes/log_app.php';

// =========================================================================
// KAIKKI KUVANKÄSITTELYFUNKTIOT ALKUPERÄISESTÄ SAVE_FLASH.PHP:STÄ TÄSSÄ
// =========================================================================

function sf_save_dataurl_preview_v2($dataurl, $uploadDir, $prefix = 'preview') {
    if (empty($dataurl) || strpos($dataurl, 'data:image') !== 0) {
        sf_app_log('sf_save_dataurl_preview_v2: Invalid dataurl', 'ERROR');
        return false;
    }
    $parts = explode(',', $dataurl);
    if (count($parts) !== 2) {
        sf_app_log('sf_save_dataurl_preview_v2: Could not parse dataurl', 'ERROR');
        return false;
    }
    $imageData = base64_decode($parts[1], true);
    if ($imageData === false) {
        sf_app_log('sf_save_dataurl_preview_v2: base64_decode failed', 'ERROR');
        return false;
    }
    $filename = $prefix . '_' . time() . '_' . uniqid() . '.jpg';
    $targetPath = $uploadDir . $filename;
    $saved = false;
    try {
        if (extension_loaded('imagick')) {
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
        } else if (extension_loaded('gd')) {
            $image = @imagecreatefromstring($imageData);
            if ($image !== false) {
                $src_width = imagesx($image);
                $src_height = imagesy($image);
                $dst_width = 1920; $dst_height = 1080;
                $dst = imagecreatetruecolor($dst_width, $dst_height);
                $white = imagecolorallocate($dst, 255, 255, 255);
                imagefill($dst, 0, 0, $white);
                imagecopyresampled($dst, $image, 0, 0, 0, 0, $dst_width, $dst_height, $src_width, $src_height);
                imagejpeg($dst, $targetPath, 88);
                imagedestroy($image); imagedestroy($dst);
                $saved = true;
            }
        }
    } catch (Exception $e) {
        sf_app_log('sf_save_dataurl_preview_v2: Exception: ' . $e->getMessage(), 'ERROR');
    }
    return $saved ? $filename : false;
}

function sf_safe_filename(string $name): string {
    $name = preg_replace('/[^\w\-. ]+/u', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    $name = trim($name, '._');
    if ($name === '') $name = bin2hex(random_bytes(4));
    return mb_substr($name, 0, 200);
}

function sf_unique_filename(string $dir, string $basename, string $ext): string {
    $i = 0;
    do {
        $suffix = $i === 0 ? '' : "-$i";
        $name = $basename . $suffix . '.' . $ext;
        $i++;
    } while (file_exists($dir . $name) && $i < 1000);
    return $name;
}

function sf_compress_image(string $source, string $dest, string $mime): bool {
    $maxWidth = 1920; $maxHeight = 1920; $jpegQuality = 85;
    switch ($mime) {
        case 'image/jpeg': $srcImage = @imagecreatefromjpeg($source); break;
        case 'image/png': $srcImage = @imagecreatefrompng($source); break;
        case 'image/webp': $srcImage = @imagecreatefromwebp($source); break;
        default: return false;
    }
    if (!$srcImage) return false;
    $origWidth = imagesx($srcImage); $origHeight = imagesy($srcImage);
    $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight, 1.0);
    $newWidth = (int) round($origWidth * $ratio); $newHeight = (int) round($origHeight * $ratio);
    $newImage = imagecreatetruecolor($newWidth, $newHeight);
    if (!$newImage) { imagedestroy($srcImage); return false; }
    $white = imagecolorallocate($newImage, 255, 255, 255);
    imagefill($newImage, 0, 0, $white);
    $resized = imagecopyresampled($newImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
    if (!$resized) { imagedestroy($srcImage); imagedestroy($newImage); return false; }
    $saved = imagejpeg($newImage, $dest, $jpegQuality);
    imagedestroy($srcImage); imagedestroy($newImage);
    return $saved;
}

function sf_handle_uploaded_image(array $file, ?string $destDir = null): ?string {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $destDir = $destDir ?: __DIR__ . '/../../uploads/images/';
    $tmp = $file['tmp_name'];
    $maxUploadSize = 20 * 1024 * 1024;
    if (filesize($tmp) > $maxUploadSize) return null;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
    $origName = basename($file['name'] ?? ('img_' . time()));
    $base = sf_safe_filename(pathinfo($origName, PATHINFO_FILENAME));
    $filename = sf_unique_filename($destDir, $base, 'jpg');
    $dest = $destDir . $filename;
    if (sf_compress_image($tmp, $dest, $mime)) {
        @chmod($dest, 0644);
        return $filename;
    }
    return null;
}

// =========================================================================
// TYÖNTEKIJÄN PÄÄLOGIIKKA
// =========================================================================

$flash_id = 0;
if (php_sapi_name() === 'cli') {
    $flash_id = (int)($argv[1] ?? 0);
} else {
    $flash_id = (int)($_GET['flash_id'] ?? 0);
}
if ($flash_id <= 0) {
    error_log("Worker: Invalid flash_id provided.");
    exit(1);
}

sf_app_log("Worker starting for flash_id: $flash_id", 'INFO');
$pdo = Database::getInstance();

try {
    $pdo->prepare("UPDATE sf_flashes SET processing_status = 'in_progress' WHERE id = ?")->execute([$flash_id]);

    $temp_data_dir = __DIR__ . '/../../uploads/processes/';
    $job_file = $temp_data_dir . $flash_id . '.jobdata';
    if (!file_exists($job_file)) {
        throw new Exception("Job data file not found: $job_file");
    }
    $job_data = json_decode(file_get_contents($job_file), true);
    if ($job_data === null) {
        throw new Exception("Failed to decode job data from $job_file");
    }
    $post = $job_data['post'];
    $uploadedFiles = $job_data['files'];

    $update_fields = [];

    // Käsittele Preview 1 - JavaScript generoi tämän
    $preview_dataurl = trim((string) ($post['preview_image_data'] ?? ''));
    if ($preview_dataurl) {
        $saved = sf_save_dataurl_preview_v2($preview_dataurl, __DIR__ . '/../../uploads/previews/', 'preview');
        if ($saved) {
            $update_fields['preview_filename'] = $saved;
        }
    }
    // POISTETTU: Ei enää fallback GD-generointia - JavaScript hoitaa previewin

    // Käsittele Preview 2 (tutkintatiedote)
    $preview_dataurl_2 = trim((string) ($post['preview_image_data_2'] ?? ''));
    $type = trim((string) ($post['type'] ?? 'yellow'));
    if ($type === 'green' && $preview_dataurl_2) {
        $saved2 = sf_save_dataurl_preview_v2($preview_dataurl_2, __DIR__ . '/../../uploads/previews/', 'preview2');
        if ($saved2) {
            $update_fields['preview_filename_2'] = $saved2;
        }
    }

    // Käsittele ladatut kuvat
    define('UPLOADS_IMAGES_DIR', __DIR__ . '/../../uploads/images/');
    if (!is_dir(UPLOADS_IMAGES_DIR)) @mkdir(UPLOADS_IMAGES_DIR, 0755, true);

    foreach (['image1' => 'image_main', 'image2' => 'image_2', 'image3' => 'image_3'] as $field => $dbcol) {
        if (!empty($uploadedFiles[$field]) && $uploadedFiles[$field]['error'] === UPLOAD_ERR_OK) {
            $saved_image = sf_handle_uploaded_image($uploadedFiles[$field], UPLOADS_IMAGES_DIR);
            if ($saved_image) {
                $update_fields[$dbcol] = $saved_image;
            }
        }
    }
    
    // Päivitä tietokanta
    if (!empty($update_fields)) {
        $set_parts = [];
        foreach (array_keys($update_fields) as $key) {
            $set_parts[] = "`$key` = :$key";
        }
        $sql = "UPDATE sf_flashes SET " . implode(', ', $set_parts) . ", processing_status = 'completed', is_processing = 0, updated_at = NOW() WHERE id = :id";        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($update_fields + ['id' => $flash_id]);
    } else {
        // Jos mitään kuvia ei ollut, merkitään silti valmiiksi
                $pdo->prepare("UPDATE sf_flashes SET processing_status = 'completed', is_processing = 0, updated_at = NOW() WHERE id = ?")->execute([$flash_id]);
    }

    // Siivoa väliaikaiset tiedostot
    @unlink($job_file);
    foreach ($uploadedFiles as $file_info) {
        if (isset($file_info['tmp_name'])) {
            @unlink($file_info['tmp_name']);
        }
    }
    
    sf_app_log("Worker successfully processed flash_id: $flash_id", 'INFO');

} catch (Throwable $e) {
    sf_app_log("Worker FAILED for flash_id: $flash_id. Error: " . $e->getMessage() . "\n" . $e->getTraceAsString(), 'ERROR');
    if (isset($pdo)) {
                $pdo->prepare("UPDATE sf_flashes SET processing_status = 'error', is_processing = 0 WHERE id = ?")->execute([$flash_id]);
    }
}