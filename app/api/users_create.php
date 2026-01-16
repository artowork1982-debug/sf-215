<?php
// app/api/users_create.php
declare(strict_types=1);

// Ladataan konfiguraatio ja suojaukset
// HUOM: protect.php hoitaa session_start(), auth-tarkistuksen JA CSRF-validoinnin
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../services/email_services.php';

header('Content-Type: application/json; charset=utf-8');

// Vain pääkäyttäjä (role_id = 1)
sf_require_role([1]);

// Vain POST-metodi (protect.php on jo tarkastanut CSRF:n)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

/**
 * Generoi satunnainen salasana turvallisesti
 * 
 * @param int $length Salasanan pituus (oletus 10)
 * @return string Generoitu salasana
 */
function sf_generate_random_password(int $length = 10): string
{
    // Merkkivalikoima: ei sekaantuvia merkkejä (I/l/1, O/0)
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $charsLength = strlen($chars);
    $password = '';
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $charsLength - 1)];
    }
    
    return $password;
}

// Yhdistä tietokantaan
$mysqli = sf_db();
if (!$mysqli) {
    sf_app_log('users_create: Tietokantayhteys epäonnistui', LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => 'Tietokantavirhe']);
    exit;
}

// Lue lomakedata
$first = trim($_POST['first_name'] ?? '');
$last  = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$role  = (int)($_POST['role_id'] ?? 0);

// Kotityömaa (valinnainen)
$homeWorksiteId = $_POST['home_worksite_id'] ?? '';
if ($homeWorksiteId === '' || $homeWorksiteId === null) {
    $homeWorksiteId = null;
} else {
    $homeWorksiteId = (int)$homeWorksiteId;
    if ($homeWorksiteId <= 0) {
        $homeWorksiteId = null;
    }
}

// Validoi pakolliset kentät (salasana ei enää pakollinen, generoidaan automaattisesti)
if ($first === '' || $last === '' || $email === '' || $role <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Täytä kaikki pakolliset kentät']);
    exit;
}

// Validoi sähköpostin muoto
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Virheellinen sähköpostiosoite']);
    exit;
}

// Tarkista onko sähköposti jo käytössä
$stmt = $mysqli->prepare('SELECT id FROM sf_users WHERE email = ? AND is_active = 1 LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    echo json_encode(['ok' => false, 'error' => 'Tällä sähköpostilla on jo käyttäjä']);
    exit;
}
$stmt->close();

// Generoi salasana automaattisesti
$generatedPassword = sf_generate_random_password(10);
$hash = password_hash($generatedPassword, PASSWORD_DEFAULT);

// Lisää käyttäjä tietokantaan
if ($homeWorksiteId === null) {
    $stmt = $mysqli->prepare(
        'INSERT INTO sf_users (first_name, last_name, email, role_id, home_worksite_id, password_hash, is_active, created_at)
         VALUES (?, ?, ?, ?, NULL, ?, 1, NOW())'
    );
    $stmt->bind_param('sssis', $first, $last, $email, $role, $hash);
} else {
    $stmt = $mysqli->prepare(
        'INSERT INTO sf_users (first_name, last_name, email, role_id, home_worksite_id, password_hash, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW())'
    );
    $stmt->bind_param('sssiis', $first, $last, $email, $role, $homeWorksiteId, $hash);
}

if (!$stmt->execute()) {
    sf_app_log('users_create: INSERT epäonnistui: ' . $stmt->error, LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => 'Käyttäjän luonti epäonnistui']);
    exit;
}

$newUserId = $stmt->insert_id;
$stmt->close();

// Lähetä tervetulosähköposti
$emailSent = false;
try {
    // Create PDO connection for email service
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    
    $emailSent = sf_mail_welcome_new_user($pdo, $newUserId, $generatedPassword);
    
    if ($emailSent) {
        sf_app_log("users_create: Uusi käyttäjä luotu (id=$newUserId), welcome email lähetetty osoitteeseen $email", LOG_LEVEL_INFO);
    } else {
        sf_app_log("users_create: Käyttäjä luotu (id=$newUserId), mutta sähköpostin lähetys epäonnistui", LOG_LEVEL_WARNING);
    }
} catch (Throwable $e) {
    sf_app_log("users_create: Email sending exception: " . $e->getMessage(), LOG_LEVEL_ERROR);
    $emailSent = false;
}

$mysqli->close();

// Palauta vastaus
$response = ['ok' => true, 'id' => $newUserId, 'password_sent' => $emailSent];
if (!$emailSent) {
    $response['warning'] = 'Email failed';
}

echo json_encode($response);