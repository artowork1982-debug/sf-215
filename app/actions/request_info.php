<?php
// app/actions/request_info.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/statuses.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../services/email_services.php';

$base = rtrim($config['base_url'], '/');

// Tämä sivu käsittelee vain Palauta-lomakkeen POST-pyynnön
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: {$base}/index.php?page=list");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: {$base}/index.php?page=list");
    exit;
}

// Tässä käytetään helpers.php:n sf_get_pdo()-funktiota
$pdo = sf_get_pdo();

// Haetaan flash, jotta tiedetään ryhmätunnus (yhteinen loki kieliversioille)
$stmt = $pdo->prepare("SELECT id, translation_group_id, state FROM sf_flashes WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$flash = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flash) {
    header("Location: {$base}/index.php?page=list");
    exit;
}

$logFlashId = !empty($flash['translation_group_id'])
    ? (int)$flash['translation_group_id']
    : (int)$flash['id'];

$oldState = (string)($flash['state'] ?? '');
$newState = 'request_info';

// Päivitetään tila KAIKILLE kieliversioille
$updatedCount = sf_update_state_all_languages($pdo, $id, $newState);

// Lomakkeelta tullut viesti
$message = trim($_POST['message'] ?? '');

// Loki-otsikko ja kuvaus
$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
$statusLabel   = sf_status_label($newState, $currentUiLang);

// Kirjataan info_requested tapahtuma
$desc = "log_status_set|status:{$newState}";
if ($message !== '') {
    $safeMsg = mb_substr($message, 0, 2000);
    $desc .= "\nlog_return_reason_label: " . $safeMsg;
}

// Kirjataan loki RYHMÄN JUUREEN → näkyy kaikissa kieliversioissa
sf_log_event($logFlashId, 'info_requested', $desc);

// Kirjataan myös erillinen state_changed tapahtuma
if ($oldState !== $newState) {
    $oldStateLabel = sf_status_label($oldState, $currentUiLang);
    $newStateLabel = sf_status_label($newState, $currentUiLang);
    $stateChangeDesc = sf_term('log_state_changed', $currentUiLang) . ": {$oldStateLabel} → {$newStateLabel}";
    sf_log_event($logFlashId, 'state_changed', $stateChangeDesc);
}

// Audit log
require_once __DIR__ . '/../includes/audit_log.php';
$user = sf_current_user();
sf_audit_log(
    'flash_info_request',
    'flash',
    (int)$id,
    [
        'new_status' => $newState,
        'message_length' => mb_strlen($message),
    ],
    $user ? (int)$user['id'] : null
);

// Lähetä sähköposti tekijälle
if (function_exists('sf_mail_request_info')) {
    try {
        sf_app_log("request_info: calling sf_mail_request_info for flashId={$id}");
        // HUOM: käytetään yksittäisen flashin id:tä ($id), ei translation_group_id:tä
        sf_mail_request_info($pdo, $id, $message);
        sf_app_log("request_info: sf_mail_request_info DONE for flashId={$id}");
    } catch (Throwable $e) {
        // Kirjoitetaan omaan sovelluslokiin, mutta EI kaadeta käyttäjää
        sf_app_log('request_info: sf_mail_request_info ERROR: ' . $e->getMessage());
    }
}

// Takaisin katselunäkymään
header("Location: {$base}/index.php?page=view&id={$id}&notice=request_info");
exit;