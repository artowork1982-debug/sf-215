<?php
/**
 * app/actions/save_comms_edit.php
 * Viestinnän ja turvatiimin muokkaus - EI muuta tilaa, tallentaa vain sisältömuutokset. 
 */
declare(strict_types=1);

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

function json_error_exit($message, $code = 500) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../../config.php';
} catch (Throwable $e) {
    json_error_exit('Config error: ' . $e->getMessage());
}

try {
    require_once __DIR__ . '/../includes/auth.php';
} catch (Throwable $e) {
    json_error_exit('Auth error: ' . $e->getMessage());
}

$currentUser = sf_current_user();
if (!$currentUser) {
    json_error_exit('Not logged in', 401);
}

try {
    require_once __DIR__ . '/../includes/log.php';
    require_once __DIR__ . '/../includes/statuses.php';
} catch (Throwable $e) {
    json_error_exit('Include error: ' . $e->getMessage());
}

$base = rtrim($config['base_url'] ?? '', '/');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error_exit('Method Not Allowed', 405);
}

$roleId = (int)($currentUser['role_id'] ?? 0);
$isComms = ($roleId === 4);
$isAdmin = ($roleId === 1);
$isSafety = ($roleId === 3);

if (!$isComms && !$isAdmin && !$isSafety) {
    json_error_exit('Ei oikeuksia (role:  ' . $roleId . ')', 403);
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    json_error_exit('Virheellinen ID: ' . $id, 400);
}

try {
    $pdo = Database::getInstance();
    
    $stmt = $pdo->prepare("SELECT id, state, translation_group_id, lang FROM sf_flashes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$flash) {
        json_error_exit('Tiedotetta ei löydy:  ' . $id, 404);
    }
    
    $currentState = $flash['state'];
    $isTranslation = ! empty($flash['translation_group_id']) && (int)$flash['translation_group_id'] !== (int)$flash['id'];
    
    $canEdit = $isAdmin;
    if ($isSafety && in_array($currentState, ['pending_review', 'reviewed', 'to_comms'], true)) {
        $canEdit = true;
    }
    if ($isComms && in_array($currentState, ['to_comms', 'published'], true)) {
        $canEdit = true;
    }
    
    if (!$canEdit) {
        json_error_exit('Ei muokkausoikeutta tilaan: ' . $currentState, 403);
    }
    
    $logFlashId = ! empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] :  (int)$flash['id'];
    
    $sql = "UPDATE sf_flashes SET
        title = :title,
        title_short = :title_short,
        description = :description,
        root_causes = :root_causes,
        actions = :actions,
        annotations_data = :annotations_data,
        image1_transform = :image1_transform,
        image2_transform = :image2_transform,
        image3_transform = :image3_transform,
        grid_layout = :grid_layout,
        updated_at = NOW()
        WHERE id = :id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':title' => trim((string)($_POST['title'] ?? '')),
        ':title_short' => trim((string)($_POST['title_short'] ?? $_POST['short_text'] ?? '')),
        ':description' => trim((string)($_POST['description'] ?? '')),
        ':root_causes' => trim((string)($_POST['root_causes'] ?? '')),
        ':actions' => trim((string)($_POST['actions'] ?? '')),
        ':annotations_data' => trim((string)($_POST['annotations_data'] ?? '[]')),
        ':image1_transform' => trim((string)($_POST['image1_transform'] ?? '')),
        ':image2_transform' => trim((string)($_POST['image2_transform'] ?? '')),
        ':image3_transform' => trim((string)($_POST['image3_transform'] ?? '')),
        ':grid_layout' => trim((string)($_POST['grid_layout'] ?? 'grid-1')),
        ':id' => $id,
    ]);
    
    $roleLabel = $isComms ?  'Viestintä' : ($isSafety ? 'Turvatiimi' :  'Admin');
    $desc = "log_inline_edit|role:{$roleLabel}|state:{$currentState}";
    sf_log_event($logFlashId, 'inline_edit', $desc);
    
    try {
        require_once __DIR__ . '/../includes/audit_log.php';
        sf_audit_log('flash_edit', 'flash', $id, [
            'title' => trim((string)($_POST['title'] ?? '')),
            'state' => $currentState,
            'editor_role' => $roleLabel,
        ], (int)$currentUser['id']);
    } catch (Throwable $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
    
    ob_end_clean();
    echo json_encode([
        'ok' => true,
        'flash_id' => $id,
        'state' => $currentState,
        'redirect' => $base . '/index.php?page=view&id=' . $id . '&notice=saved'
    ], JSON_UNESCAPED_UNICODE);
    exit;
    
} catch (Throwable $e) {
    error_log('save_comms_edit. php FATAL:  ' . $e->getMessage());
    json_error_exit('Tallennusvirhe: ' . $e->getMessage());
}