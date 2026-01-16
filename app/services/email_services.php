<?php
// app/services/email_services.php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../lib/phpmailer/Exception.php';
require_once __DIR__ . '/../lib/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../lib/phpmailer/SMTP.php';
require_once __DIR__ . '/email_template.php';
require_once __DIR__ . '/render_services.php';

/**
 * Roolien ID:t sf_roles-taulussa.
 * Nämä vastaavat tietokannan arvoja:
 * 1 = Pääkäyttäjä, 2 = Kirjoittaja, 3 = Turvatiimi, 4 = Viestintä
 * 5 = Jakelu (Suomi), 6 = Jakelu (Ruotsi), 7 = Jakelu (Englanti)
 * 8 = Jakelu (Italia), 9 = Jakelu (Kreikka)
 */
const SF_ROLE_ID_ADMIN        = 1;
const SF_ROLE_ID_AUTHOR       = 2; // Kirjoittaja
const SF_ROLE_ID_SAFETY_TEAM  = 3; // Turvatiimi
const SF_ROLE_ID_COMMS        = 4; // Viestintä
const SF_ROLE_ID_DISTRIBUTION_FI = 5; // SafetyFlash-jakelu (Suomi)
const SF_ROLE_ID_DISTRIBUTION_SV = 6; // SafetyFlash-jakelu (Ruotsi)
const SF_ROLE_ID_DISTRIBUTION_EN = 7; // SafetyFlash-jakelu (Englanti)
const SF_ROLE_ID_DISTRIBUTION_IT = 8; // SafetyFlash-jakelu (Italia)
const SF_ROLE_ID_DISTRIBUTION_EL = 9; // SafetyFlash-jakelu (Kreikka)

// Legacy constant for backward compatibility
const SF_ROLE_ID_DISTRIBUTION = 5;

/**
 * Hae yksittäinen asetus sf_settings-taulusta.
 * Jos asetusta ei ole, palautetaan oletusarvo.
 */
function sf_get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM sf_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $default;
    }
    return (string)$row['setting_value'];
}

/**
 * Lähettää sähköpostin käyttäen SMTP-asetuksia (PHPMailer).
 * Supports both plain text and HTML/multipart emails.
 *
 * @param string   $subject      Sähköpostin otsikko
 * @param string   $htmlBody     HTML sisältö (if empty, uses plain text only)
 * @param string   $textBody     Plain text sisältö
 * @param string[] $recipients   Vastaanottajat
 * @param array[]  $attachments  Optional array of attachment paths. Each element should have 'path' and optionally 'name' keys
 */
function sf_send_email(string $subject, string $htmlBody, string $textBody, array $recipients, array $attachments = []): void
{
    sf_app_log('sf_send_email: CALLED, recipients=' . implode(',', $recipients));

    if (empty($recipients)) {
        sf_app_log('sf_send_email: EMPTY RECIPIENTS, abort');
        return;
    }

    // Luodaan oma PDO-yhteys asetuksia varten (ei käytetä sf_get_pdo:a)
    try {
        require __DIR__ . '/../../config.php';
        $pdo = new PDO(
            "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
            $config['db']['user'],
            $config['db']['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable $e) {
        sf_app_log('sf_send_email: PDO INIT ERROR: ' . $e->getMessage());
        return;
    }

    // Luetaan SMTP-asetukset tietokannasta
    $host       = sf_get_setting($pdo, 'smtp_host', 'localhost');
    $port       = (int) (sf_get_setting($pdo, 'smtp_port', '25'));
    $encryption = sf_get_setting($pdo, 'smtp_encryption', 'none'); // tls/ssl/none
    $username   = sf_get_setting($pdo, 'smtp_username', '');
    $password   = sf_get_setting($pdo, 'smtp_password', '');
    $fromEmail  = sf_get_setting($pdo, 'smtp_from_email', 'no-reply@tapojarvi.online');
    $fromName   = sf_get_setting($pdo, 'smtp_from_name', 'Safetyflash');

    $mail = new PHPMailer(true);

    try {
        // Palvelinasetukset
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = ($username !== '' || $password !== '');
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false; // ei salausta
        }
        if ($mail->SMTPAuth) {
            $mail->Username = $username;
            $mail->Password = $password;
        }

        // UTF-8
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';

        // From
        $mail->setFrom($fromEmail, $fromName);

        // Vastaanottajat
        foreach ($recipients as $to) {
            $to = trim($to);
            if ($to !== '') {
                $mail->addAddress($to);
            }
        }

        // Sisältö - Check if HTML is provided
        if (!empty($htmlBody)) {
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;      // HTML version
            $mail->AltBody = $textBody;      // Plain text alternative
        } else {
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body    = $textBody;      // Plain text only (backward compatible)
        }

        // Add attachments if provided
        foreach ($attachments as $attachment) {
            if (isset($attachment['path']) && file_exists($attachment['path'])) {
                $name = $attachment['name'] ?? basename($attachment['path']);
                $mail->addAttachment($attachment['path'], $name);
            }
        }

        $mail->send();
        sf_app_log('sf_send_email: MAIL SENT OK');
    } catch (Exception $e) {
        sf_app_log('sf_send_email: SMTP ERROR: ' . $mail->ErrorInfo);
    }
}

/**
 * Build HTML and plain text email from template
 * 
 * @param array $data Email data for template
 * @param string $lang Language code (fi, sv, en, it, el)
 * @return array ['html' => string, 'text' => string]
 */
function sf_build_email_html(array $data, string $lang = 'fi'): array
{
    return [
        'html' => sf_generate_email_html($data, $lang),
        'text' => sf_generate_email_text($data, $lang),
    ];
}

/**
 * Get user's preferred language from database
 * 
 * @param PDO $pdo Database connection
 * @param string $email User email
 * @return string Language code (fi, sv, en, it, el)
 */
function sf_get_user_language(PDO $pdo, string $email): string
{
    $stmt = $pdo->prepare("SELECT ui_lang FROM sf_users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $lang = $row['ui_lang'] ?? 'fi';
    
    // Validate language code
    $validLangs = ['fi', 'sv', 'en', 'it', 'el'];
    if (!in_array($lang, $validLangs, true)) {
        $lang = 'fi';
    }
    
    return $lang;
}

/**
 * Get flash details for email
 * 
 * @param PDO $pdo Database connection
 * @param int $flashId SafetyFlash ID
 * @return array|null Flash details or null if not found
 */
function sf_get_flash_details(PDO $pdo, int $flashId): ?array
{
    $stmt = $pdo->prepare("
        SELECT 
            f.id,
            f.type,
            f.title,
            f.site as worksite,
            f.preview_filename,
            f.translation_group_id
        FROM sf_flashes f
        WHERE f.id = ?
        LIMIT 1
    ");
    $stmt->execute([$flashId]);
    $flash = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $flash ?: null;
}

/**
 * Build SafetyFlash URL
 * 
 * @param int $flashId SafetyFlash ID
 * @return string Full URL to SafetyFlash
 */
function sf_build_flash_url(int $flashId): string
{
    // Try to get base URL from config
    try {
        require __DIR__ . '/../../config.php';
        $baseUrl = $config['base_url'] ?? 'https://tapojarvi.online/safetyflash';
    } catch (Throwable $e) {
        // Fallback if config can't be loaded
        $baseUrl = 'https://tapojarvi.online/safetyflash';
    }
    
    return $baseUrl . '/index.php?page=view&id=' . $flashId;
}

/**
 * Build preview attachment array for email
 * 
 * @param string|null $previewFilename Preview filename from database
 * @param int $flashId SafetyFlash ID for attachment naming
 * @return array Empty array if no preview, or array with attachment data
 */
function sf_build_preview_attachment(?string $previewFilename, int $flashId): array
{
    if (empty($previewFilename)) {
        return [];
    }
    
    $previewPath = __DIR__ . '/../../uploads/previews/' . $previewFilename;
    if (!file_exists($previewPath)) {
        return [];
    }
    
    return [[
        'path' => $previewPath,
        'name' => 'safetyflash_' . $flashId . '.jpg'
    ]];
}

/**
 * Palauttaa annettua roolia vastaavien aktiivisten käyttäjien sähköpostit.
 *
 * @param PDO $pdo
 * @param int $roleId sf_roles.id
 * @return string[]
 */
function sf_get_emails_by_role(PDO $pdo, int $roleId): array
{
    $stmt = $pdo->prepare("
        SELECT email
        FROM sf_users
        WHERE role_id = :role_id
          AND is_active = 1
          AND email <> ''
    ");
    $stmt->execute([':role_id' => $roleId]);

    $emails = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $email = trim((string)$row['email']);
        if ($email !== '') {
            $emails[] = $email;
        }
    }

    return array_values(array_unique($emails));
}

/**
 * Turvatiimille menevät viestit (rooli: SF_ROLE_ID_SAFETY_TEAM).
 */
function sf_get_safety_team_emails(PDO $pdo): array
{
    return sf_get_emails_by_role($pdo, SF_ROLE_ID_SAFETY_TEAM);
}

/**
 * Viestintä-tiimille menevät viestit (rooli: SF_ROLE_ID_COMMS).
 */
function sf_get_comms_team_emails(PDO $pdo): array
{
    return sf_get_emails_by_role($pdo, SF_ROLE_ID_COMMS);
}

/**
 * Jakeluryhmälle menevät viestit (rooli: SF_ROLE_ID_DISTRIBUTION).
 */
function sf_get_distribution_emails(PDO $pdo): array
{
    return sf_get_emails_by_role($pdo, SF_ROLE_ID_DISTRIBUTION);
}

/**
 * Palauttaa maakohtaisen jakeluryhmän role_id:n.
 * 
 * @param string $countryCode Maakoodi (fi, sv, en, it, el)
 * @return int Role ID
 */
function sf_get_distribution_role_id(string $countryCode): int
{
    $roleMap = [
        'fi' => SF_ROLE_ID_DISTRIBUTION_FI,
        'sv' => SF_ROLE_ID_DISTRIBUTION_SV,
        'en' => SF_ROLE_ID_DISTRIBUTION_EN,
        'it' => SF_ROLE_ID_DISTRIBUTION_IT,
        'el' => SF_ROLE_ID_DISTRIBUTION_EL,
    ];
    return $roleMap[$countryCode] ?? SF_ROLE_ID_DISTRIBUTION_FI; // Default to Finland
}

/**
 * Hakee maakohtaisen jakeluryhmän sähköpostit.
 * 
 * @param PDO $pdo Database connection
 * @param string $countryCode Maakoodi (fi, sv, en, it, el)
 * @return string[] Email addresses
 */
function sf_get_distribution_emails_by_country(PDO $pdo, string $countryCode): array
{
    $roleId = sf_get_distribution_role_id($countryCode);
    return sf_get_emails_by_role($pdo, $roleId);
}

/**
 * Get preview attachments from database
 * 
 * @param PDO $pdo Database connection
 * @param int $flashId SafetyFlash ID
 * @return array Attachment data for sf_send_email
 */
function sf_get_preview_attachments(PDO $pdo, int $flashId): array
{
    $stmt = $pdo->prepare("SELECT preview_filename FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmt->execute([$flashId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row || empty($row['preview_filename'])) {
        return [];
    }
    
    return sf_build_preview_attachment($row['preview_filename'], $flashId);
}

/**
 * Lähettää SafetyFlashin maakohtaiselle jakeluryhmälle kyseisen maan kielellä.
 * 
 * @param PDO $pdo Database connection
 * @param int $flashId SafetyFlash ID (original, will find language version)
 * @param string $countryCode Maakoodi (fi, sv, en, it, el)
 * @param bool $hasPersonalInjury Onko henkilövahinkoja
 * @return int Lähetettyjen sähköpostien määrä
 */
function sf_mail_to_distribution_by_country(PDO $pdo, int $flashId, string $countryCode, bool $hasPersonalInjury = false): int
{
    sf_app_log("sf_mail_to_distribution_by_country: flashId={$flashId}, country={$countryCode}");
    
    $recipients = sf_get_distribution_emails_by_country($pdo, $countryCode);
    if (empty($recipients)) {
        sf_app_log("sf_mail_to_distribution_by_country: No recipients for country {$countryCode}");
        return 0;
    }
    
    // Hae kyseisen maan kieliversio flashista
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_to_distribution_by_country: Flash {$flashId} not found");
        return 0;
    }
    
    $groupId = !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : $flashId;
    
    // Etsi kyseisen kielen versio
    $langStmt = $pdo->prepare("
        SELECT id FROM sf_flashes 
        WHERE (id = ? OR translation_group_id = ?) AND lang = ?
        LIMIT 1
    ");
    $langStmt->execute([$groupId, $groupId, $countryCode]);
    $langFlash = $langStmt->fetch();
    
    $targetFlashId = $langFlash ? (int)$langFlash['id'] : $flashId;
    
    // Käytä maan kieltä sähköpostissa
    $emailLang = $countryCode;
    
    // Rakenna otsikko kyseisellä kielellä
    $typeEmoji = match($flash['type'] ?? 'yellow') {
        'red' => '🔴',
        'yellow' => '🟡',
        'green' => '🟢',
        default => '🟡',
    };
    $typeName = sf_email_term("email_type_{$flash['type']}", $emailLang);
    $title = $flash['title'] ?? '';
    $site = $flash['worksite'] ?? $flash['site'] ?? '';
    
    $subjectParts = [];
    if ($hasPersonalInjury && $flash['type'] === 'red') {
        $injuryWarning = sf_email_term('email_personal_injury_warning', $emailLang);
        $subjectParts[] = "⚠️ {$injuryWarning}";
    }
    $subjectParts[] = "{$typeEmoji} {$typeName}";
    if ($title) $subjectParts[] = $title;
    if ($site) $subjectParts[] = "({$site})";
    
    $subject = implode(' - ', array_filter($subjectParts));
    
    // Rakenna sähköpostidata
    $emailData = [
        'type' => $flash['type'] ?? 'yellow',
        'flash_id' => $targetFlashId,
        'subject' => $subject,
        'body_text' => sf_email_term('email_distribution_body', $emailLang),
        'flash_title' => $flash['title'] ?? '',
        'flash_worksite' => $flash['worksite'] ?? $flash['site'] ?? '',
        'flash_url' => sf_build_flash_url($targetFlashId),
        'lang' => $emailLang,
    ];
    
    // Add injury warning to body if applicable
    if ($hasPersonalInjury && $flash['type'] === 'red') {
        $emailData['message'] = sf_email_term('email_personal_injury_notice', $emailLang);
        $emailData['message_label'] = '⚠️ ' . sf_email_term('email_warning', $emailLang);
    }
    
    // Build email
    $email = sf_build_email_html($emailData, $emailLang);
    
    // Lähetä sähköposti
    sf_send_email(
        $subject,
        $email['html'],
        $email['text'],
        $recipients,
        sf_get_preview_attachments($pdo, $targetFlashId)
    );
    
    return count($recipients);
}

/**
 * Julkaisuosoitteet.
 * TESTIVAIHEESSA kovakoodattu arto.huhta@gmail.com
 * (myöhemmin kannattaa lukea tämäkin sf_settings-taulusta).
 */
function sf_get_publish_target_emails(): array
{
    return ['arto.huhta@gmail.com'];
}

/**
 * Haetaan tekijän sähköposti flashin perusteella (sf_flashes.created_by -> sf_users.email).
 */
function sf_get_flash_creator_email(PDO $pdo, int $flashId): ?string
{
    $stmt = $pdo->prepare("
        SELECT u.email
        FROM sf_flashes f
        LEFT JOIN sf_users u ON u.id = f.created_by
        WHERE f.id = ?
        LIMIT 1
    ");
    $stmt->execute([$flashId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['email'])) {
        return null;
    }

    return trim((string)$row['email']);
}

/**
 * Turvatiimille: uusi tai uudelleen lähetetty tarkistukseen.
 *
 * Käyttö:
 *  - kun tila vaihtuu esim. draft -> pending_review TAI request_info -> pending_review,
 *    kutsu sf_mail_to_safety_team($pdo, $flashId, $stateBefore)
 *
 * $stateBefore:
 *  - jos ennen oli 'request_info' -> teksti kertoo että tekijä on päivittänyt ja lähettänyt uudelleen
 *  - muuten -> "Uusi Safetyflash on lähetetty tarkistettavaksi."
 */
function sf_mail_to_safety_team(PDO $pdo, int $flashId, string $stateBefore): void
{
    sf_app_log("sf_mail_to_safety_team: CALLED for flashId={$flashId}, stateBefore={$stateBefore}");

    $recipients = sf_get_safety_team_emails($pdo);
    if (empty($recipients)) {
        sf_app_log('sf_mail_to_safety_team: NO RECIPIENTS (Turvatiimi-ryhmä tyhjä)');
        return;
    }

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_to_safety_team: Flash {$flashId} not found");
        return;
    }

    // Group recipients by language for efficient sending
    $recipientsByLang = [];
    foreach ($recipients as $email) {
        $lang = sf_get_user_language($pdo, $email);
        if (!isset($recipientsByLang[$lang])) {
            $recipientsByLang[$lang] = [];
        }
        $recipientsByLang[$lang][] = $email;
    }

    // Send email in each language
    foreach ($recipientsByLang as $lang => $langRecipients) {
        // Build email data
        $emailData = [
            'type' => $flash['type'] ?? 'yellow',
            'flash_id' => $flashId,
            'subject' => sf_email_term('email_new_flash_for_review_subject', $lang),
            'body_text' => $stateBefore === 'request_info' 
                ? sf_email_term('email_resubmitted_for_review_body', $lang)
                : sf_email_term('email_new_flash_for_review_body', $lang),
            'flash_title' => $flash['title'] ?? '',
            'flash_worksite' => $flash['worksite'] ?? '',
            'flash_url' => sf_build_flash_url($flashId),
        ];

        // Build email
        $email = sf_build_email_html($emailData, $lang);
        $subject = sf_email_term('email_new_flash_for_review_subject', $lang) . " (ID: {$flashId})";

        sf_send_email($subject, $email['html'], $email['text'], $langRecipients);
    }
}

/**
 * Tekijälle: turvatiimi pyytää lisätietoja (request_info).
 * Tämä EI mene rooliryhmille, vaan vain Safetyflashin luojalle.
 */
function sf_mail_request_info(PDO $pdo, int $flashId, string $message): void
{
    sf_app_log("sf_mail_request_info: CALLED for flashId={$flashId}");

    $email = sf_get_flash_creator_email($pdo, $flashId);
    if ($email === null) {
        sf_app_log("sf_mail_request_info: NO CREATOR EMAIL for flashId={$flashId}");
        return;
    }

    sf_app_log("sf_mail_request_info: SENDING TO {$email}");

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_request_info: Flash {$flashId} not found");
        return;
    }

    // Get user language
    $lang = sf_get_user_language($pdo, $email);

    // Build email data
    $emailData = [
        'type' => $flash['type'] ?? 'yellow',
        'flash_id' => $flashId,
        'subject' => sf_email_term('email_request_info_subject', $lang),
        'body_text' => sf_email_term('email_request_info_body', $lang),
        'flash_title' => $flash['title'] ?? '',
        'flash_worksite' => $flash['worksite'] ?? '',
        'flash_url' => sf_build_flash_url($flashId),
        'message' => $message,
        'message_label' => sf_email_term('email_message_from_safety_team', $lang),
    ];

    // Build email
    $email_content = sf_build_email_html($emailData, $lang);
    $subject = sf_email_term('email_request_info_subject', $lang) . " (ID: {$flashId})";

    sf_send_email($subject, $email_content['html'], $email_content['text'], [$email]);
}

/**
 * Viestinnälle: turvatiimi lähetti flashin viestintään (to_comms).
 * Lisäksi voidaan cc:llä tekijä (ccCreator = true).
 * Tämä kutsutaan, kun tila vaihtuu to_comms-tilaan.
 */
function sf_mail_to_comms(PDO $pdo, int $flashId, string $message, bool $ccCreator = true): void
{
    sf_app_log("sf_mail_to_comms: CALLED for flashId={$flashId}");

    $recipients = sf_get_comms_team_emails($pdo);

    if ($ccCreator) {
        $creator = sf_get_flash_creator_email($pdo, $flashId);
        if ($creator !== null) {
            $recipients[] = $creator;
        }
    }

    if (empty($recipients)) {
        sf_app_log('sf_mail_to_comms: NO RECIPIENTS (Viestintä-ryhmä + cc tyhjä)');
        return;
    }

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_to_comms: Flash {$flashId} not found");
        return;
    }

    // Group recipients by language
    $recipientsByLang = [];
    foreach ($recipients as $email) {
        $lang = sf_get_user_language($pdo, $email);
        if (!isset($recipientsByLang[$lang])) {
            $recipientsByLang[$lang] = [];
        }
        $recipientsByLang[$lang][] = $email;
    }

    // Send email in each language
    foreach ($recipientsByLang as $lang => $langRecipients) {
        // Build email data
        $emailData = [
            'type' => $flash['type'] ?? 'yellow',
            'flash_id' => $flashId,
            'subject' => sf_email_term('email_to_comms_subject', $lang),
            'body_text' => sf_email_term('email_to_comms_body', $lang),
            'flash_title' => $flash['title'] ?? '',
            'flash_worksite' => $flash['worksite'] ?? '',
            'flash_url' => sf_build_flash_url($flashId),
            'message' => $message,
            'message_label' => sf_email_term('email_message_for_comms', $lang),
        ];

        // Build email
        $email = sf_build_email_html($emailData, $lang);
        $subject = sf_email_term('email_to_comms_subject', $lang) . " (ID: {$flashId})";

        sf_send_email($subject, $email['html'], $email['text'], $langRecipients);
    }
}

/**
 * Turvatiimille: viestintä kommentoi to_comms-tilassa (lisäkysymys tms.).
 *
 * Tämä funktio EI lähetä viestiä luojalle, vaan nimenomaan turvatiimiroolille.
 * Kutsu tätä, kun:
 *  - tila on 'to_comms'
 *  - kommentoija on viestintä-roolissa
 *  - lisätään kommentti lokiin
 */
function sf_mail_comms_comment_to_safety(
    PDO $pdo,
    int $logFlashId,
    string $message,
    ?int $fromUserId,
    ?int $creatorId
): void {
    sf_app_log("sf_mail_comms_comment_to_safety: CALLED for groupId={$logFlashId}");

    $recipients = sf_get_safety_team_emails($pdo);
    if (empty($recipients)) {
        sf_app_log('sf_mail_comms_comment_to_safety: NO RECIPIENTS (Turvatiimi-ryhmä tyhjä)');
        return;
    }

    // Get flash details
    $flash = sf_get_flash_details($pdo, $logFlashId);
    if (!$flash) {
        sf_app_log("sf_mail_comms_comment_to_safety: Flash {$logFlashId} not found");
        return;
    }

    // Group recipients by language
    $recipientsByLang = [];
    foreach ($recipients as $email) {
        $lang = sf_get_user_language($pdo, $email);
        if (!isset($recipientsByLang[$lang])) {
            $recipientsByLang[$lang] = [];
        }
        $recipientsByLang[$lang][] = $email;
    }

    // Send email in each language
    foreach ($recipientsByLang as $lang => $langRecipients) {
        // Build email data
        $emailData = [
            'type' => $flash['type'] ?? 'yellow',
            'flash_id' => $logFlashId,
            'subject' => sf_email_term('email_comms_comment_subject', $lang),
            'body_text' => sf_email_term('email_comms_comment_body', $lang),
            'flash_title' => $flash['title'] ?? '',
            'flash_worksite' => $flash['worksite'] ?? '',
            'flash_url' => sf_build_flash_url($logFlashId),
            'message' => $message,
            'message_label' => sf_email_term('email_comment_label', $lang),
        ];

        // Build email
        $email = sf_build_email_html($emailData, $lang);
        $subject = sf_email_term('email_comms_comment_subject', $lang) . " (ID: {$logFlashId})";

        sf_send_email($subject, $email['html'], $email['text'], $langRecipients);
    }
}

/**
 * Julkaisu: valmis flash julkaistu, lähetetään esim. safetyflash@tapojarvi.online:hin.
 * Nyt testissä: arto.huhta@gmail.com (sf_get_publish_target_emails()).
 */
function sf_mail_published(PDO $pdo, int $flashId): void
{
    sf_app_log("sf_mail_published: CALLED for flashId={$flashId}");

    $recipients = sf_get_publish_target_emails();
    if (empty($recipients)) {
        sf_app_log('sf_mail_published: NO RECIPIENTS');
        return;
    }

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_published: Flash {$flashId} not found");
        return;
    }

    // Get user language (use default 'fi' since this is a general publish target)
    $lang = 'fi';

    // Get language versions (translations)
    $translationUrls = [];
    $groupId = !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : $flashId;
    $translationsData = sf_get_flash_translations($pdo, $groupId);
    
    foreach ($translationsData as $tlang => $tid) {
        if ($tid != $flashId) { // Don't include current flash
            $translationUrls[$tlang] = sf_build_flash_url($tid);
        }
    }

    // Build email data
    $emailData = [
        'type' => $flash['type'] ?? 'yellow',
        'flash_id' => $flashId,
        'subject' => sf_email_term('email_published_subject', $lang),
        'body_text' => sf_email_term('email_published_body', $lang) . "\n\n" . sf_email_term('email_login_to_view', $lang),
        'flash_title' => $flash['title'] ?? '',
        'flash_worksite' => $flash['worksite'] ?? '',
        'flash_url' => sf_build_flash_url($flashId),
        'translations' => $translationUrls,
    ];

    // Build email
    $email = sf_build_email_html($emailData, $lang);
    $subject = sf_email_term('email_published_subject', $lang) . " (ID: {$flashId})";

    // Prepare attachments (preview image if available)
    $attachments = sf_build_preview_attachment($flash['preview_filename'] ?? null, $flashId);

    sf_send_email($subject, $email['html'], $email['text'], $recipients, $attachments);
}

/**
 * Tekijälle: SafetyFlash on julkaistu.
 * Lähetetään ilmoitus tekijälle kun hänen SafetyFlashinsa julkaistaan.
 */
function sf_mail_published_to_creator(PDO $pdo, int $flashId): void
{
    sf_app_log("sf_mail_published_to_creator: CALLED for flashId={$flashId}");

    $email = sf_get_flash_creator_email($pdo, $flashId);
    if ($email === null) {
        sf_app_log("sf_mail_published_to_creator: NO CREATOR EMAIL for flashId={$flashId}");
        return;
    }

    sf_app_log("sf_mail_published_to_creator: SENDING TO {$email}");

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_published_to_creator: Flash {$flashId} not found");
        return;
    }

    // Get user language
    $lang = sf_get_user_language($pdo, $email);

    // Build email data
    $emailData = [
        'type' => $flash['type'] ?? 'yellow',
        'flash_id' => $flashId,
        'subject' => sf_email_term('email_your_flash_published_subject', $lang),
        'body_text' => sf_email_term('email_your_flash_published_body', $lang),
        'flash_title' => $flash['title'] ?? '',
        'flash_worksite' => $flash['worksite'] ?? '',
        'flash_url' => sf_build_flash_url($flashId),
    ];

    // Build email
    $email_content = sf_build_email_html($emailData, $lang);
    $subject = sf_email_term('email_your_flash_published_subject', $lang) . " (ID: {$flashId})";

    // Prepare attachments (preview image)
    $attachments = sf_build_preview_attachment($flash['preview_filename'] ?? null, $flashId);

    sf_send_email($subject, $email_content['html'], $email_content['text'], [$email], $attachments);
}

/**
 * Lähetä julkaistu SafetyFlash jakelulistalle.
 * 
 * @param PDO $pdo Tietokantayhteys
 * @param int $flashId SafetyFlash ID
 * @param bool $hasPersonalInjury Onko henkilövahinkoja (lisää otsikkoon varoituksen)
 */
function sf_mail_to_distribution(PDO $pdo, int $flashId, bool $hasPersonalInjury = false): void
{
    sf_app_log("sf_mail_to_distribution: CALLED for flashId={$flashId}, injury={$hasPersonalInjury}");

    $recipients = sf_get_distribution_emails($pdo);
    if (empty($recipients)) {
        sf_app_log('sf_mail_to_distribution: NO RECIPIENTS (Jakelu-ryhmä tyhjä)');
        return;
    }

    // Get flash details
    $flash = sf_get_flash_details($pdo, $flashId);
    if (!$flash) {
        sf_app_log("sf_mail_to_distribution: Flash {$flashId} not found");
        return;
    }

    // Group recipients by language
    $recipientsByLang = [];
    foreach ($recipients as $email) {
        $lang = sf_get_user_language($pdo, $email);
        if (!isset($recipientsByLang[$lang])) {
            $recipientsByLang[$lang] = [];
        }
        $recipientsByLang[$lang][] = $email;
    }

    // Get language versions (translations)
    $groupId = !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : $flashId;
    $translationsData = sf_get_flash_translations($pdo, $groupId);

    // Send email in each language
    foreach ($recipientsByLang as $lang => $langRecipients) {
        // Build translation URLs for this language
        $translationUrls = [];
        foreach ($translationsData as $tlang => $tid) {
            if ($tid != $flashId) { // Don't include current flash
                $translationUrls[$tlang] = sf_build_flash_url($tid);
            }
        }

        // Build subject with type and optional injury warning
        $typeEmoji = match($flash['type'] ?? 'yellow') {
            'red' => '🔴',
            'yellow' => '🟡',
            'green' => '🟢',
            default => '🟡',
        };
        $typeName = sf_email_term("email_type_{$flash['type']}", $lang);
        $title = $flash['title'] ?? '';
        $site = $flash['worksite'] ?? '';
        
        // Build subject line
        $subjectParts = [];
        if ($hasPersonalInjury && $flash['type'] === 'red') {
            $injuryWarning = sf_email_term('email_personal_injury_warning', $lang);
            $subjectParts[] = "⚠️ {$injuryWarning}";
        }
        $subjectParts[] = "{$typeEmoji} {$typeName}";
        if ($title) {
            $subjectParts[] = $title;
        }
        if ($site) {
            $subjectParts[] = "({$site})";
        }
        $subject = implode(' - ', array_filter($subjectParts));

        // Build email data
        $emailData = [
            'type' => $flash['type'] ?? 'yellow',
            'flash_id' => $flashId,
            'subject' => $subject,
            'body_text' => sf_email_term('email_distribution_body', $lang),
            'flash_title' => $flash['title'] ?? '',
            'flash_worksite' => $flash['worksite'] ?? '',
            'flash_url' => sf_build_flash_url($flashId),
            'translations' => $translationUrls,
        ];

        // Add injury warning to body if applicable
        if ($hasPersonalInjury && $flash['type'] === 'red') {
            $emailData['message'] = sf_email_term('email_personal_injury_notice', $lang);
            $emailData['message_label'] = '⚠️ ' . sf_email_term('email_warning', $lang);
        }

        // Build email
        $email = sf_build_email_html($emailData, $lang);

        // Prepare attachments (preview image if available)
        $attachments = sf_build_preview_attachment($flash['preview_filename'] ?? null, $flashId);

        sf_send_email($subject, $email['html'], $email['text'], $langRecipients, $attachments);
    }
}

/**
 * Build login URL
 * 
 * @return string Full URL to login page
 */
function sf_build_login_url(): string
{
    // Try to get base URL from config
    try {
        require __DIR__ . '/../../config.php';
        $baseUrl = $config['base_url'] ?? 'https://tapojarvi.online/safetyflash';
    } catch (Throwable $e) {
        // Fallback if config can't be loaded
        $baseUrl = 'https://tapojarvi.online/safetyflash';
    }
    
    return $baseUrl . '/app/pages/login.php';
}

/**
 * Lähetä tervetulosähköposti uudelle käyttäjälle automaattisesti generoidulla salasanalla
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $generatedPassword Plaintext password (to be sent only once)
 * @return bool Success status
 */
function sf_mail_welcome_new_user(PDO $pdo, int $userId, string $generatedPassword): bool
{
    sf_app_log("sf_mail_welcome_new_user: CALLED for userId={$userId}");
    
    // Fetch user details from database
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.email, u.role_id, u.ui_lang, r.name as role_name
        FROM sf_users u
        LEFT JOIN sf_roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        sf_app_log("sf_mail_welcome_new_user: User {$userId} not found", LOG_LEVEL_ERROR);
        return false;
    }
    
    $firstName = $user['first_name'] ?? '';
    $lastName = $user['last_name'] ?? '';
    $email = $user['email'] ?? '';
    $roleId = (int)($user['role_id'] ?? 0);
    $roleName = $user['role_name'] ?? '';
    
    // Validate email
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sf_app_log("sf_mail_welcome_new_user: Invalid email for user {$userId}", LOG_LEVEL_ERROR);
        return false;
    }
    
    // Get user language (use ui_lang from user or default to 'fi')
    $lang = $user['ui_lang'] ?? 'fi';
    $validLangs = ['fi', 'sv', 'en', 'it', 'el'];
    if (!in_array($lang, $validLangs, true)) {
        $lang = 'fi';
    }
    
    // Get localized role name
    require_once __DIR__ . '/../lib/sf_terms.php';
    $localizedRoleName = sf_role_name($roleId, $roleName, $lang);
    
    // Build email data
    $emailData = [
        'type' => 'welcome',
        'subject' => sf_email_term('email_welcome_subject', $lang),
        'body_text' => sf_email_term('email_welcome_body', $lang),
        'user_name' => trim("{$firstName} {$lastName}"),
        'user_email' => $email,
        'user_role' => $localizedRoleName,
        'generated_password' => $generatedPassword,
        'login_url' => sf_build_login_url(),
        'instructions' => sf_email_term('email_welcome_instructions', $lang),
        'lang' => $lang,
    ];
    
    // Build HTML and plain text email
    $emailContent = sf_build_email_html($emailData, $lang);
    
    // Send email
    try {
        sf_send_email(
            sf_email_term('email_welcome_subject', $lang),
            $emailContent['html'],
            $emailContent['text'],
            [$email]
        );
        
        sf_app_log("sf_mail_welcome_new_user: Welcome email sent successfully to {$email}");
        return true;
    } catch (Throwable $e) {
        sf_app_log("sf_mail_welcome_new_user: Email failed for user ID {$userId}: " . $e->getMessage(), LOG_LEVEL_ERROR);
        return false;
    }
}