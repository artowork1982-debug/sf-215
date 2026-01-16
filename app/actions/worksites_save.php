<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../lib/sf_terms.php'; // translations (sf_term)
require_once __DIR__ .  '/../includes/protect.php';   // auth + CSRF (POST)
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/audit_log.php';

// Allow admins (and optionally role 3 if you use it as admin-like)
sf_require_role([1, 3]);

$currentUser = sf_current_user();

function sf_is_fetch(): bool {
    $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($xrw === 'xmlhttprequest') return true;
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    return strpos($accept, 'application/json') !== false;
}

function sf_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$base = rtrim((string)($config['base_url'] ?? ''), '/');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Method not allowed'], 405);
    header("Location: {$base}/index.php?page=settings&tab=worksites");
    exit;
}

// DB connection (mysqli)
$db = $config['db'] ?? null;
if (!is_array($db)) {
    if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'DB config missing'], 500);
    exit('DB config missing');
}

$mysqli = new mysqli((string)$db['host'], (string)$db['user'], (string)$db['pass'], (string)$db['name']);
if ($mysqli->connect_errno) {
    if (sf_is_fetch()) sf_json(['ok' => false, 'error' => $mysqli->connect_error], 500);
    exit('DB connect failed');
}
$mysqli->set_charset((string)($db['charset'] ?? 'utf8mb4'));

// Accept both "form_action" (settings tab) and legacy "action"
$action = (string)($_POST['form_action'] ?? ($_POST['action'] ?? ''));

try {
    // ---------------------------------------------------------------------
    // ADD (used by settings tab: form_action=add, field: name)
    // ---------------------------------------------------------------------
if ($action === 'add') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Missing fields'], 400);
        header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
        exit;
    }

    // Insert only columns that are guaranteed in your UI usage (name + is_active).
    $stmt = $mysqli->prepare("INSERT INTO sf_worksites (name, is_active) VALUES (?, 1)");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' .  $mysqli->error);
    }
    $stmt->bind_param('s', $name);
    $ok = $stmt->execute();
    $newWorksiteId = $mysqli->insert_id;
    $stmt->close();

    // ========== AUDIT LOG ==========
    if ($ok) {
        sf_audit_log(
            'worksite_created',
            'worksite',
            (int)$newWorksiteId,
            [
                'name' => $name,
                'is_active' => 1,
            ],
            $currentUser ?  (int)$currentUser['id'] : null
        );
    }
    // ================================

$uiLang = $_SESSION['ui_lang'] ?? 'fi';
$msg = $ok
    ? (sf_term('worksite_added', $uiLang) ?: 'Työmaa lisätty.')
    : (sf_term('error', $uiLang) ?: 'Toiminto epäonnistui.');

    if (sf_is_fetch()) {
        sf_json([
            'ok' => (bool)$ok,
            'success' => (bool)$ok,
            'notice' => $ok ? 'worksite_added' : 'error',
            'message' => $msg
        ], $ok ?  200 : 500);
    }

    header("Location:  {$base}/index.php?page=settings&tab=worksites&notice=" . ($ok ?  "worksite_added" : "error"));
    exit;
}

    // ---------------------------------------------------------------------
    // TOGGLE (used by settings tab: form_action=toggle, field: id)
    // ---------------------------------------------------------------------
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Invalid ID'], 400);
            header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
            exit;
        }

// Flip active flag
$stmt = $mysqli->prepare("UPDATE sf_worksites SET is_active = 1 - is_active WHERE id = ?");
if (!$stmt) {
    throw new Exception('Prepare failed:  ' . $mysqli->error);
}
$stmt->bind_param('i', $id);
$ok = $stmt->execute();
$stmt->close();

// Fetch new state for notification/UI (works with and without mysqlnd)
$newActive = null;
$worksiteName = null;
$stmt2 = $mysqli->prepare("SELECT name, is_active FROM sf_worksites WHERE id = ? LIMIT 1");
if ($stmt2) {
    $stmt2->bind_param('i', $id);
    $stmt2->execute();

    if (method_exists($stmt2, 'get_result')) {
        // mysqlnd available
        $res2 = $stmt2->get_result();
        $row2 = $res2 ? $res2->fetch_assoc() : null;
        $newActive = $row2 ? (int)($row2['is_active'] ??  0) : null;
        $worksiteName = $row2 ? ($row2['name'] ?? null) : null;
    } else {
        // portable fallback (no mysqlnd)
        $nameVal = null;
        $isActiveVal = null;
        $stmt2->bind_result($nameVal, $isActiveVal);
        if ($stmt2->fetch()) {
            $newActive = (int)$isActiveVal;
            $worksiteName = $nameVal;
        }
    }

    $stmt2->close();
}

// ========== AUDIT LOG ==========
if ($ok) {
    sf_audit_log(
        'worksite_updated',
        'worksite',
        $id,
        [
            'name' => $worksiteName,
            'is_active' => $newActive,
            'action' => 'toggle',
        ],
        $currentUser ? (int)$currentUser['id'] : null
    );
}
// ================================

$notice = ($newActive === 0) ? 'worksite_disabled' : 'worksite_enabled';
$uiLang = $_SESSION['ui_lang'] ?? 'fi';
$msg    = sf_term($notice, $uiLang) ?: (($newActive === 0) ? 'Työmaa asetettu passiiviseksi.' : 'Työmaa aktivoitu.');

if (sf_is_fetch()) {
    sf_json([
        'ok' => (bool)$ok,
        'success' => (bool)$ok,
        'notice' => $notice,
        'message' => $msg,
        'is_active' => $newActive,
        'id' => $id
    ], $ok ? 200 : 500);
}

header("Location: {$base}/index.php?page=settings&tab=worksites&notice={$notice}");
exit;
    }  

    // ---------------------------------------------------------------------
    // DELETE (optional; if you add a delete button later)
    // ---------------------------------------------------------------------
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Invalid ID'], 400);
            header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
            exit;
        }

        // Hae nimi ennen poistoa audit-lokia varten
        $worksiteName = null;
        $stmtName = $mysqli->prepare("SELECT name FROM sf_worksites WHERE id = ?  LIMIT 1");
        if ($stmtName) {
            $stmtName->bind_param('i', $id);
            $stmtName->execute();
            if (method_exists($stmtName, 'get_result')) {
                $resName = $stmtName->get_result();
                $rowName = $resName ? $resName->fetch_assoc() : null;
                $worksiteName = $rowName ? ($rowName['name'] ?? null) : null;
            } else {
                $nameVal = null;
                $stmtName->bind_result($nameVal);
                if ($stmtName->fetch()) {
                    $worksiteName = $nameVal;
                }
            }
            $stmtName->close();
        }

        $stmt = $mysqli->prepare("DELETE FROM sf_worksites WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();

        // ========== AUDIT LOG ==========
        if ($ok) {
            sf_audit_log(
                'worksite_deleted',
                'worksite',
                $id,
                [
                    'name' => $worksiteName,
                ],
                $currentUser ? (int)$currentUser['id'] :  null
            );
        }
        // ================================

        if (sf_is_fetch()) sf_json(['ok' => (bool)$ok, 'notice' => 'deleted']);
        header("Location: {$base}/index.php?page=settings&tab=worksites&notice=deleted");
        exit;
    }

    // Unknown action
    if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Unknown action'], 400);
    header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
    exit;

} catch (Throwable $e) {
    sf_app_log('worksites_save error: ' . $e->getMessage(), LOG_LEVEL_WARNING);
    if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Server error'], 500);
    header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
    exit;
} finally {
    $mysqli->close();
}