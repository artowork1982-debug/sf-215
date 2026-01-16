<?php
// app/api/users_update.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type:  application/json; charset=utf-8');

sf_require_role([1]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$mysqli = sf_db();

$id    = (int)($_POST['id'] ?? 0);
$first = trim($_POST['first_name'] ?? '');
$last  = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$role  = (int)($_POST['role_id'] ?? 0);
$pass  = $_POST['password'] ??  '';

// KOTITYÖMAA
$homeWorksiteId = $_POST['home_worksite_id'] ??  '';
if ($homeWorksiteId === '' || $homeWorksiteId === null) {
    $homeWorksiteId = null;
} else {
    $homeWorksiteId = (int)$homeWorksiteId;
    if ($homeWorksiteId <= 0) {
        $homeWorksiteId = null;
    }
}

if ($id <= 0 || $first === '' || $last === '' || $email === '' || $role <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Puuttuvia tietoja']);
    exit;
}

// Onko email jonkun toisen käytössä? 
$stmt = $mysqli->prepare('SELECT id FROM sf_users WHERE email = ? AND id != ? AND is_active = 1 LIMIT 1');
$stmt->bind_param('si', $email, $id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    echo json_encode(['ok' => false, 'error' => 'Tällä sähköpostilla on jo toinen käyttäjä']);
    exit;
}
$stmt->close();

// Päivitetään perustiedot + koti työmaa
if ($pass !== '') {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    
    if ($homeWorksiteId === null) {
        $stmt = $mysqli->prepare(
            'UPDATE sf_users
             SET first_name = ?, last_name = ?, email = ?, role_id = ?, home_worksite_id = NULL, password_hash = ? 
             WHERE id = ? '
        );
        $stmt->bind_param('sssisi', $first, $last, $email, $role, $hash, $id);
    } else {
        $stmt = $mysqli->prepare(
            'UPDATE sf_users
             SET first_name = ?, last_name = ?, email = ?, role_id = ?, home_worksite_id = ?, password_hash = ? 
             WHERE id = ?'
        );
        $stmt->bind_param('sssiisi', $first, $last, $email, $role, $homeWorksiteId, $hash, $id);
    }
} else {
    if ($homeWorksiteId === null) {
        $stmt = $mysqli->prepare(
            'UPDATE sf_users
             SET first_name = ?, last_name = ?, email = ?, role_id = ?, home_worksite_id = NULL
             WHERE id = ?'
        );
        $stmt->bind_param('sssii', $first, $last, $email, $role, $id);
    } else {
        $stmt = $mysqli->prepare(
            'UPDATE sf_users
             SET first_name = ?, last_name = ?, email = ?, role_id = ?, home_worksite_id = ?  
             WHERE id = ?'
        );
        $stmt->bind_param('sssiii', $first, $last, $email, $role, $homeWorksiteId, $id);
    }
}

if (! $stmt->execute()) {
    echo json_encode(['ok' => false, 'error' => 'DB-virhe päivityksessä']);
    exit;
}

// Lokita onnistuminen (ilman arkaluontoisia tietoja)
$logPost = $_POST;
unset($logPost['password']);
sf_app_log("users_update: Käyttäjä päivitetty, id=$id, email=$email", LOG_LEVEL_INFO, $logPost);

echo json_encode(['ok' => true]);