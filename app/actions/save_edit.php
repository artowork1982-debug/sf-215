<?php
// app/actions/save_edit.php
// Tallentaa muokkaukset ilman tilamuutosta ja ilman sähköpostilähetystä
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../services/render_services.php';
require_once __DIR__ . '/../includes/audit_log.php'; // LISÄÄ TÄMÄ

header('Content-Type:  application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$flashId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($flashId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid flash ID']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Hae nykyinen tila - EI muuteta sitä
    $stmt = $pdo->prepare("SELECT state, type, title FROM sf_flashes WHERE id = ?  LIMIT 1");
    $stmt->execute([$flashId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Flash not found']);
        exit;
    }
    
    // Rakenna UPDATE-lause POST-datasta (EI muuteta tilaa!)
    $title = trim((string) ($_POST['title'] ?? ''));
    $titleShort = trim((string) ($_POST['title_short'] ?? ''));
    $summary = trim((string) ($_POST['summary'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $type = trim((string) ($_POST['type'] ?? ''));
    $site = trim((string) ($_POST['site'] ?? ''));
    $siteDetail = trim((string) ($_POST['site_detail'] ?? ''));
    $lang = trim((string) ($_POST['lang'] ?? 'fi'));
    $rootCauses = trim((string) ($_POST['root_causes'] ?? ''));
    $actions = trim((string) ($_POST['actions'] ?? ''));
    $annotationsData = trim((string) ($_POST['annotations_data'] ?? '[]'));
    $image1Transform = trim((string) ($_POST['image1_transform'] ?? ''));
    $image2Transform = trim((string) ($_POST['image2_transform'] ?? ''));
    $image3Transform = trim((string) ($_POST['image3_transform'] ?? ''));
    $gridLayout = trim((string) ($_POST['grid_layout'] ?? 'grid-1'));
    $gridBitmap = trim((string) ($_POST['grid_bitmap'] ?? ''));
    
    $occurredRaw = trim((string) ($_POST['occurred_at'] ?? ''));
    $occurredAt = null;
    if ($occurredRaw !== '') {
        $ts = strtotime($occurredRaw);
        if ($ts !== false) {
            $occurredAt = date('Y-m-d H:i:s', $ts);
        }
    }
    
    // UPDATE ilman tilan muutosta
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
        root_causes = :root_causes,
        actions = :actions,
        annotations_data = :annotations_data,
        image1_transform = :image1_transform,
        image2_transform = :image2_transform,
        image3_transform = :image3_transform,
        grid_layout = :grid_layout,
        grid_bitmap = :grid_bitmap,
        updated_at = NOW()
        WHERE id = :id";
    
    $params = [
        ':title'            => $title,
        ':title_short'      => $titleShort,
        ':summary'          => $summary,
        ':description'      => $description,
        ':type'             => $type,
        ':site'             => $site,
        ':site_detail'      => $siteDetail,
        ':occurred_at'      => $occurredAt,
        ':lang'             => $lang,
        ':root_causes'      => $rootCauses,
        ':actions'          => $actions,
        ':annotations_data' => $annotationsData,
        ':image1_transform' => $image1Transform,
        ':image2_transform' => $image2Transform,
        ':image3_transform' => $image3Transform,
        ':grid_layout'      => $gridLayout,
        ':grid_bitmap'      => $gridBitmap,
        ':id'               => $flashId,
    ];
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Lokita muokkaus safetyflash_logs-tauluun
    require_once __DIR__ . '/../includes/log.php';
    sf_log_event($flashId, 'edited', 'log_edited_no_status');
    
    // ========== AUDIT LOG ========== (LISÄÄ TÄMÄ)
    $user = sf_current_user();
    sf_audit_log(
        'flash_edit',
        'flash',
        $flashId,
        [
            'title' => $current['title'] ?? null,
            'state' => $current['state'] ??  null,
            'action' => 'inline_edit_no_status_change',
        ],
        $user ?  (int)$user['id'] : null
    );
    // ================================
    
    $base = rtrim($config['base_url'] ?? '', '/');
    echo json_encode([
        'ok' => true,
        'redirect' => $base . '/index.php?page=view&id=' . $flashId
    ]);
    
} catch (Throwable $e) {
    error_log('save_edit.php error:' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Tallennusvirhe: ' . $e->getMessage()]);
}