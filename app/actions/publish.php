<?php
// app/actions/publish.php
declare(strict_types=1);


require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/statuses.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../services/email_services.php';
require_once __DIR__ . '/../includes/file_cleanup.php';

$id  = sf_validate_id();
$pdo = sf_get_pdo();

// Haetaan flash
$stmt = $pdo->prepare("
    SELECT id, translation_group_id, title, state 
    FROM sf_flashes 
    WHERE id = :id 
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$flash = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flash) {
    sf_redirect($config['base_url'] . "/index.php?page=list");
    exit;
}

// Tallenna vanha tila
$oldState = (string)($flash['state'] ?? '');

// Get translation group ID
$groupId = $flash['translation_group_id'] ?: $flash['id'];

$logFlashId = !empty($flash['translation_group_id'])
    ? (int)$flash['translation_group_id']
    : (int)$flash['id'];

// Lue POST-parametrit (julkaisumodaalista)
$sendToDistribution = isset($_POST['send_to_distribution']) && $_POST['send_to_distribution'] === '1';
$hasPersonalInjury = isset($_POST['has_personal_injury']) && $_POST['has_personal_injury'] === '1';

// Lue valitut maat (POST)
$selectedCountries = $_POST['distribution_countries'] ?? ['fi']; // Default: Suomi

// Publish ALL language versions in the translation group
$updateStmt = $pdo->prepare("
    UPDATE sf_flashes 
    SET state = 'published', 
        status = 'JULKAISTU', 
        has_personal_injury = :injury,
        sent_to_distribution = :distribution,
        updated_at = NOW()
    WHERE id = :groupId OR translation_group_id = :groupId2
");
$updateStmt->execute([
    ':groupId' => $groupId,
    ':groupId2' => $groupId,
    ':injury' => $hasPersonalInjury ? 1 : 0,
    ':distribution' => $sendToDistribution ? 1 : 0,
]);

// Lokimerkintä safetyflash_logs-tauluun
$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
$statusLabel   = sf_status_label('published', $currentUiLang);

// Tallennetaan avaimella
$desc = "log_status_set: published";
$userId = $_SESSION['user_id'] ?? null;

$log = $pdo->prepare("
    INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
    VALUES (:flash_id, :user_id, :event_type, :description, NOW())
");
$log->execute([
    ':flash_id'   => $logFlashId,
    ':user_id'    => $userId,
    ':event_type' => 'published',
    ':description'=> $desc,
]);

// Kirjataan myös erillinen state_changed tapahtuma
require_once __DIR__ . '/../lib/sf_terms.php';
if ($oldState !== 'published') {
    $oldStateLabel = sf_status_label($oldState, $currentUiLang);
    $newStateLabel = sf_status_label('published', $currentUiLang);
    $stateChangeDesc = sf_term('log_state_changed', $currentUiLang) . ": {$oldStateLabel} → {$newStateLabel}";
    
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

// ========== AUDIT LOG ==========
$user = sf_current_user();

sf_audit_log(
    'flash_publish',                 // action (vastaa sf_audit_action_label-listaa)
    'flash',                         // target type
    (int)$id,                        // target id
    [
        'title'      => $flash['title'] ?? null,
        'new_status' => 'published',
    ],
    $user ? (int)$user['id'] : null  // user id
);
// ================================



// Lähetetään julkaisu-sähköposti
if (function_exists('sf_mail_published')) {
    try {
        sf_mail_published($pdo, $id);
    } catch (Throwable $e) {
        sf_app_log('publish: sf_mail_published ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    }
}

// Lähetetään julkaisu-ilmoitus TEKIJÄLLE
if (function_exists('sf_mail_published_to_creator')) {
    try {
        sf_mail_published_to_creator($pdo, $id);
    } catch (Throwable $e) {
        sf_app_log('publish: sf_mail_published_to_creator ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    }
}

// Lähetä maakohtaisille jakeluryhmille
$distributionResults = [];
if ($sendToDistribution && function_exists('sf_mail_to_distribution_by_country')) {
    foreach ($selectedCountries as $countryCode) {
        try {
            $recipientCount = sf_mail_to_distribution_by_country($pdo, $id, $countryCode, $hasPersonalInjury);
            $distributionResults[$countryCode] = $recipientCount;
            sf_app_log("publish.php: Sent to {$countryCode}, recipients: {$recipientCount}");
        } catch (Throwable $e) {
            sf_app_log("publish.php: Distribution error for {$countryCode}: " . $e->getMessage(), LOG_LEVEL_ERROR);
            $distributionResults[$countryCode] = 0;
        }
    }
}

// Lokimerkintä jakeluista
if (!empty($distributionResults)) {
    $distParts = [];
    foreach ($distributionResults as $country => $count) {
        $countryName = sf_term("country_name_{$country}", $currentUiLang) ?? strtoupper($country);
        $recipientsLabel = sf_term('log_recipients_count', $currentUiLang) ?? 'recipients';
        $distParts[] = "{$countryName}: {$count} {$recipientsLabel}";
    }
    
    $distDesc = "log_distribution_sent|countries:" . implode(',', array_keys($distributionResults)) . 
                "|details:" . implode('; ', $distParts);
    
    $logDist = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
        VALUES (:flash_id, :user_id, :event_type, :description, NOW())
    ");
    $logDist->execute([
        ':flash_id'   => $logFlashId,
        ':user_id'    => $userId,
        ':event_type' => 'distribution_sent',
        ':description'=> $distDesc,
    ]);
}

// Huom: korjattu väli "? page" -> "?page"
sf_redirect($config['base_url'] . "/index.php?page=view&id={$id}&notice=published");