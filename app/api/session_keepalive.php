<?php
// app/api/session_keepalive.php
// Extends the session to prevent timeout
// POST request (just extends the session activity timestamp)

declare(strict_types=1);

define('SF_SKIP_AUTO_CSRF', true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

function sf_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sf_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

// Get current user
$user = sf_current_user();
if (!$user) {
    sf_json(['ok' => false, 'error' => 'Authentication required'], 401);
}

// The protect.php already updated the session activity via sf_session_activity_tick()
// So we just need to confirm the session is alive
$_SESSION['sf_last_activity'] = time();

sf_json([
    'ok' => true,
    'message' => 'Session extended',
    'timestamp' => date('Y-m-d H:i:s')
]);