<?php
// app/actions/comment.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/statuses.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../services/email_services.php';

$base = rtrim($config['base_url'] ?? '', '/');

// Vain POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: {$base}/index.php?page=list");
    exit;
}

// ID URL-paramista
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: {$base}/index.php?page=list");
    exit;
}

// Kommentti lomakkeelta
$rawMessage = trim($_POST['message'] ?? '');
if ($rawMessage === '') {
    header("Location: {$base}/index.php?page=view&id={$id}");
    exit;
}
$message = mb_substr($rawMessage, 0, 2000);

// PDO
$pdo = sf_get_pdo();

// Haetaan flash
$stmt = $pdo->prepare("
    SELECT id, translation_group_id, state, created_by, title
    FROM sf_flashes
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$flash = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flash) {
    header("Location: {$base}/index.php?page=list");
    exit;
}

$logFlashId = !empty($flash['translation_group_id'])
    ? (int)$flash['translation_group_id']
    : (int)$flash['id'];

$currentState   = $flash['state'] ?? '';
$flashCreatorId = isset($flash['created_by']) ? (int)$flash['created_by'] : null;

// UI-kieli
$currentUiLang = $uiLang ?? ($_SESSION['lang'] ?? 'fi');

// Kommentti teksti
$commentLabels = [
    'fi' => 'Kommentti',
    'sv' => 'Kommentar',
    'en' => 'Comment',
    'it' => 'Commento',
    'el' => 'Σχόλιο',
];
$commentLabel = $commentLabels[$currentUiLang] ?? 'Comment';

// Lokikuvaus
// Lokikuvaus - tallennetaan avaimella, käännetään näyttöhetkellä
$desc = "log_comment_label: " . $message;

// Käyttäjä
$user   = sf_current_user();
$userId = $user ? (int)$user['id'] : ($_SESSION['user_id'] ?? null);

// Kirjataan loki RYHMÄN JUUREEN safetyflash_logs-tauluun
$stmtLog = $pdo->prepare("
    INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
    VALUES (:flash_id, :user_id, :event_type, :description, NOW())
");
$stmtLog->execute([
    ':flash_id'   => $logFlashId,
    ':user_id'    => $userId,
    ':event_type' => 'comment_added',
    ':description'=> $desc,
]);

// ========== AUDIT LOG ==========
sf_audit_log(
    'flash_comment',             // action
    'flash',                     // target type
    (int)$id,                    // target id (yksittäinen flash)
    [
        'title'   => $flash['title'] ?? null,
        'comment' => mb_substr($message, 0, 200), // lyhyt snapshot kommentista
    ],
    $user ? (int)$user['id'] : null // user id
);
// ================================

// Jos ollaan viestintävaiheessa
if ($currentState === 'to_comms' && function_exists('sf_current_user_has_role')) {
    if (sf_current_user_has_role('comms') && function_exists('sf_mail_comms_comment_to_safety')) {
        sf_mail_comms_comment_to_safety($pdo, $logFlashId, $message, $userId, $flashCreatorId);
    }
}

header("Location: {$base}/index.php?page=view&id={$id}&notice=comment_added");
exit;