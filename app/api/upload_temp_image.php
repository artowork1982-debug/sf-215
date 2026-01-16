<?php
// app/api/upload_temp_image.php
// Vastaanottaa kuvan HETI kun käyttäjä valitsee sen lomakkeessa
// Tallentaa väliaikaiseen kansioon ja palauttaa tiedostonimen

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

// Väliaikainen kansio
$tempDir = __DIR__ . '/../../uploads/temp/';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0755, true);
}

// Validoi tiedosto
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Upload failed']);
    exit;
}

$file = $_FILES['image'];
$slot = isset($_POST['slot']) ? (int)$_POST['slot'] : 1;

// Tarkista tyyppi
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) {
    echo json_encode(['ok' => false, 'error' => 'Failed to initialize file type checker']);
    exit;
}

$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if ($mimeType === false) {
    echo json_encode(['ok' => false, 'error' => 'Failed to detect file type']);
    exit;
}

if (!in_array($mimeType, $allowedTypes, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid file type']);
    exit;
}

// Luo uniikki tiedostonimi (session-based)
$sessionId = session_id() ?: 'anon';
$ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
$filename = 'temp_' . $sessionId . '_slot' . $slot . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

$destPath = $tempDir . $filename;

// Siirrä tiedosto
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
    exit;
}

// Palauta tiedostonimi ja URL
$baseUrl = rtrim($config['base_url'] ?? '', '/');
echo json_encode([
    'ok' => true,
    'filename' => $filename,
    'url' => $baseUrl . '/uploads/temp/' . $filename,
    'slot' => $slot
]);