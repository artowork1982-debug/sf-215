<?php
// app/api/profile_update.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

sf_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$user = sf_current_user();
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'Ei kirjautunut']);
    exit;
}

$mysqli = sf_db();

$id    = (int)$user['id'];
$first = trim($_POST['first_name'] ?? '');
$last  = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');

// Kotityömaa
$homeWorksiteId = $_POST['home_worksite_id'] ?? '';
if ($homeWorksiteId === '' || $homeWorksiteId === null) {
    $homeWorksiteId = null;
} else {
    $homeWorksiteId = (int)$homeWorksiteId;
    if ($homeWorksiteId <= 0) {
        $homeWorksiteId = null;
    }
}

if ($first === '' || $last === '' || $email === '') {
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
    $stmt->close();
    exit;
}
$stmt->close();

// Päivitä (EI roolia - käyttäjä ei voi muuttaa omaa rooliaan)
$stmt = $mysqli->prepare(
    'UPDATE sf_users SET first_name = ?, last_name = ?, email = ?, home_worksite_id = ? WHERE id = ?'
);
$stmt->bind_param('sssii', $first, $last, $email, $homeWorksiteId, $id);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'error' => 'Tietokantavirhe']);
    exit;
}
$stmt->close();

// Päivitä sessio
$_SESSION['sf_user']['first_name']       = $first;
$_SESSION['sf_user']['last_name']        = $last;
$_SESSION['sf_user']['email']            = $email;
$_SESSION['sf_user']['home_worksite_id'] = $homeWorksiteId;

echo json_encode(['ok' => true]);