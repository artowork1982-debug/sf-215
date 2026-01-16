<?php
// app/api/profile_password.php
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

$currentPassword = $_POST['current_password'] ?? '';
$newPassword     = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    echo json_encode(['ok' => false, 'error' => 'Täytä kaikki kentät']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['ok' => false, 'error' => 'Salasanat eivät täsmää']);
    exit;
}

if (strlen($newPassword) < 8) {
    echo json_encode(['ok' => false, 'error' => 'Salasanan on oltava vähintään 8 merkkiä']);
    exit;
}

// Hae nykyinen salasana-hash
$stmt = $mysqli->prepare('SELECT password_hash FROM sf_users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
    echo json_encode(['ok' => false, 'error' => 'Nykyinen salasana on väärin']);
    exit;
}

// Päivitä uusi salasana
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $mysqli->prepare('UPDATE sf_users SET password_hash = ? WHERE id = ?');
$stmt->bind_param('si', $newHash, $user['id']);

if (!$stmt->execute()) {
    echo json_encode(['ok' => false, 'error' => 'Tietokantavirhe']);
    exit;
}

echo json_encode(['ok' => true]);