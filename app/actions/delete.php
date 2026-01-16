<?php
// app/actions/delete.php
declare(strict_types=1);


require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/statuses.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/log.php';

$base = rtrim($config['base_url'] ?? '', '/');

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    header("Location: {$base}/index.php?page=view&id=" . ($id ? (int)$id : ''));
    exit;
}

try {
    $id = sf_validate_id();
    if ($id <= 0) {
        header("Location: {$base}/index.php?page=list&notice=error");
        exit;
    }

    $pdo = sf_get_pdo();

    // Fetch the row
    $stmt = $pdo->prepare("
        SELECT 
            id,
            translation_group_id,
            image_main,
            image_2,
            image_3,
            preview_filename,
            state,
            title
        FROM sf_flashes 
        WHERE id = :id 
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        header("Location: {$base}/index.php?page=list&notice=notfound");
        exit;
    }

    $flashTitle  = $row['title'] ?? '';
    $isRoot      = empty($row['translation_group_id']) || (int)$row['translation_group_id'] === (int)$row['id'];
    $groupRootId = $isRoot ? (int)$row['id'] : (int)($row['translation_group_id'] ?? $row['id']);

        // Determine which rows to delete
    if ($isRoot) {
$sel = $pdo->prepare("
    SELECT id, image_main, image_2, image_3, preview_filename, preview_filename_2 
    FROM sf_flashes 
    WHERE translation_group_id = :gid1 OR id = :gid2
");
        $sel->execute([':gid1' => $groupRootId, ':gid2' => $groupRootId]);
        $toDelete = $sel->fetchAll();
    } else {
        $toDelete = [
            [
                'id'               => $row['id'],
                'image_main'       => $row['image_main'] ?? null,
                'image_2'          => $row['image_2'] ??  null,
                'image_3'          => $row['image_3'] ?? null,
                'preview_filename' => $row['preview_filename'] ?? null,
                'preview_filename_2' => $row['preview_filename_2'] ?? null,
            ]
        ];
    }

    if (empty($toDelete)) {
        header("Location: {$base}/index.php?page=list&notice=notfound");
        exit;
    }

    // Begin transaction
    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    $ids = array_map(fn($r) => (int)$r['id'], $toDelete);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $del = $pdo->prepare("DELETE FROM sf_flashes WHERE id IN ($placeholders)");
    $del->execute($ids);

    // Commit DB changes
    if ($startedTx) {
        $pdo->commit();
    }

    // Remove files from disk
    $imgDirRel  = __DIR__ . '/../../uploads/images/';
    $prevDirRel = __DIR__ . '/../../uploads/previews/';
    $imgDirAlt  = __DIR__ . '/../../img/';

    foreach ($toDelete as $r) {
        foreach (['image_main', 'image_2', 'image_3'] as $k) {
            $fn = $r[$k] ?? null;
            if ($fn) {
                $p1 = $imgDirRel . $fn;
                $p2 = $imgDirAlt . $fn;
                if (is_file($p1)) {
                    @unlink($p1);
                }
                if (is_file($p2)) {
                    @unlink($p2);
                }
            }
        }
        $preview = $r['preview_filename'] ?? null;
        if ($preview) {
            $p1 = $prevDirRel . $preview;
            $p2 = $imgDirAlt . $preview;
            if (is_file($p1)) {
                @unlink($p1);
            }
            if (is_file($p2)) {
                @unlink($p2);
            }
        }
        
        // LISÄTTY:  Poista preview_filename_2 (tutkintatiedotteen kortti 2)
        $preview2 = $r['preview_filename_2'] ?? null;
        if ($preview2) {
            $p1 = $prevDirRel . $preview2;
            $p2 = $imgDirAlt . $preview2;
            if (is_file($p1)) {
                @unlink($p1);
            }
            if (is_file($p2)) {
                @unlink($p2);
            }
        }
    }

    // Log deletion event to safetyflash_logs
    $logFlashId = $groupRootId;
    $userId     = $_SESSION['user_id'] ?? null;
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
// Tallennetaan avaimella
$desc = $isRoot
    ? "log_group_deleted|id:{$logFlashId}"
    : "log_version_deleted|id:{$id}";

    // LISÄTTY: Poista lokit kaikille poistetuille versiosta
    $logIds = implode(',', array_fill(0, count($ids), '?'));
    $stmtDelLogs = $pdo->prepare("DELETE FROM safetyflash_logs WHERE flash_id IN ($logIds)");
    $stmtDelLogs->execute($ids);

    if (function_exists('sf_log_event')) {
        sf_log_event($logFlashId, 'deleted', $desc);
    }

    // ========== AUDIT LOG ==========
    $user = sf_current_user();

    sf_audit_log(
        'flash_delete',                 // action
        'flash',                        // target type
        (int)$id,                       // target id (yksittäinen flash; ryhmästä kertoo is_group)
        [
            'title'         => $flashTitle,
            'is_group'      => $isRoot,
            'deleted_count' => count($toDelete),
        ],                              // details
        $user ? (int)$user['id'] : null // user id
    );
    // ================================

    header("Location: {$base}/index.php?page=list&notice=deleted");
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('delete.php ERROR: ' . $e->getMessage());
    header("Location: {$base}/index.php?page=view&id=" . (int)($id ?? 0) . "&notice=error");
    exit;
}