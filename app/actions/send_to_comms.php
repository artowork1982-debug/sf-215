<?php
// app/actions/send_to_comms.php
declare(strict_types=1);

// Poista nämä tuotannossa - aiheuttavat "headers already sent" -virheen
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/statuses.php';
require_once __DIR__ . '/../includes/log.php';
if (is_file(__DIR__ . '/helpers.php')) {
    require_once __DIR__ . '/helpers.php';
}
require_once __DIR__ . '/../services/email_services.php';

$base = rtrim($config['base_url'] ?? '', '/');

// Tämä endpoint käsittelee vain POST-pyynnön
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: {$base}/index.php?page=list");
    exit;
}

// --- ID URL-parametrista ---
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    sf_app_log("send_to_comms.php: Invalid ID");
    http_response_code(400);
    echo 'Virheellinen ID.';
    exit;
}

// --- Viesti lomakkeelta (rajoitettu) ---
$message = trim((string) ($_POST['message'] ?? ''));
if ($message !== '') {
    $message = mb_substr($message, 0, 2000);
}

try {
    sf_app_log("send_to_comms.php: Processing flash {$id}");
    
    // DB-yhteys (Database singleton)
    $pdo = Database::getInstance();

    // Haetaan flash, jotta tiedetään ryhmätunnus
    $stmt = $pdo->prepare("SELECT id, translation_group_id, state FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $flash = $stmt->fetch();

    if (!$flash) {
        sf_app_log("send_to_comms.php: Flash not found, id={$id}");
        header("Location: {$base}/index.php?page=list&notice=error");
        exit;
    }

    // Määritetään logille käytettävä flash_id (ryhmän juuri)
    $logFlashId = !empty($flash['translation_group_id'])
        ? (int) $flash['translation_group_id']
        : (int) $flash['id'];

    // Tallenna vanha tila
    $oldState = (string)($flash['state'] ?? '');

    // Päivitetään tila: to_comms (KAIKILLE kieliversioille)
    $newState = 'to_comms';
    $updatedCount = sf_update_state_all_languages($pdo, $id, $newState);

    sf_app_log("send_to_comms.php: Flash {$id} state updated to {$newState} for {$updatedCount} language version(s)");

    // UI-kieli lokimerkintää varten
    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

    // Tallennetaan avaimilla, käännetään näyttöhetkellä
    $desc = "log_status_set|status:{$newState}";
    if ($message !== '') {
        $desc .= "\nlog_message_to_comms_label: " . $message;
    }
    // Käyttäjä
    $userId = null;
    if (function_exists('sf_current_user')) {
        $user = sf_current_user();
        $userId = isset($user['id']) ? (int)$user['id'] : null;
    } else {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    // Kirjataan loki
    if (function_exists('sf_log_event')) {
        sf_log_event($logFlashId, 'sent_to_comms', $desc);
    } else {
        $log = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
            VALUES (:flash_id, :user_id, :event_type, :description, NOW())
        ");
        $log->execute([
            ':flash_id'   => $logFlashId,
            ':user_id'    => $userId,
            ':event_type' => 'sent_to_comms',
            ':description'=> $desc,
        ]);
    }

    // Kirjataan myös erillinen state_changed tapahtuma
    if ($oldState !== $newState) {
        require_once __DIR__ . '/../lib/sf_terms.php';
        $oldStateLabel = sf_status_label($oldState, $currentUiLang);
        $newStateLabel = sf_status_label($newState, $currentUiLang);
        $stateChangeDesc = sf_term('log_state_changed', $currentUiLang) . ": {$oldStateLabel} → {$newStateLabel}";
        
        if (function_exists('sf_log_event')) {
            sf_log_event($logFlashId, 'state_changed', $stateChangeDesc);
        } else {
            $logStateChange = $pdo->prepare("
                INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
                VALUES (:flash_id, :user_id, :event_type, :description, NOW())
            ");
            $logStateChange->execute([
                ':flash_id'   => $logFlashId,
                ':user_id'    => $userId,
                ':event_type' => 'state_changed',
                ':description'=> $stateChangeDesc,
            ]);
        }
    }

    // ========== SÄHKÖPOSTIN LÄHETYS (try-catch) ==========
    try {
        if (function_exists('sf_mail_to_comms')) {
            sf_app_log("send_to_comms.php: Attempting to send email for flash {$id}");
            sf_mail_to_comms($pdo, $id, $message, true);
            sf_app_log("send_to_comms.php: Email sent successfully for flash {$id}");
        } else {
            sf_app_log("send_to_comms.php: sf_mail_to_comms function not found");
        }
    } catch (Throwable $emailError) {
        // Logita virhe mutta älä kaada sivua - tilamuutos onnistui silti
        sf_app_log("send_to_comms.php: EMAIL ERROR for flash {$id}: " . $emailError->getMessage(), LOG_LEVEL_ERROR);
        error_log("send_to_comms.php EMAIL ERROR: " . $emailError->getMessage() . "\n" . $emailError->getTraceAsString());
    }

    // Audit log
    require_once __DIR__ . '/../includes/audit_log.php';
    $user = function_exists('sf_current_user') ? sf_current_user() : null;
    sf_audit_log(
        'flash_to_comms',
        'flash',
        (int)$id,
        [
            'new_status' => $newState,
            'has_message' => ($message !== ''),
        ],
        $user ? (int)$user['id'] : null
    );

    // --- Uudelleenohjaus takaisin view-sivulle ---
    header("Location: {$base}/index.php?page=view&id=" . (int)$id . "&notice=comms_sent");
    exit;

} catch (Throwable $e) {
    sf_app_log('send_to_comms.php FATAL ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    error_log('send_to_comms.php FATAL ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    header("Location: {$base}/index.php?page=view&id=" . (int)$id . "&notice=error");
    exit;
}