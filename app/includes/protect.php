<?php
// app/includes/protect.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/session_activity.php';
require_once __DIR__ . '/filename_helpers.php';

/**
 * Protect both HTML actions and API endpoints:
 * - Require login (except explicitly public paths)
 * - For API/fetch calls: return JSON 401 instead of redirect
 * - Enforce CSRF for state-changing requests (POST/PUT/PATCH/DELETE)
 *   unless SF_SKIP_AUTO_CSRF is defined (for JSON-body endpoints that validate CSRF manually).
 */

function sf_is_fetch_request(): bool
{
    $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($xrw === 'xmlhttprequest') {
        return true;
    }

    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    return strpos($accept, 'application/json') !== false;
}

function sf_is_api_path(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return (strpos($uri, '/app/api/') !== false);
}

function sf_uri_ends_with(string $uri, string $suffix): bool
{
    return $suffix === '' ? true : (substr($uri, -strlen($suffix)) === $suffix);
}

// Allowlist: paths that must remain public
$current = $_SERVER['REQUEST_URI'] ?? '';

$publicPaths = [
    '/app/pages/login.php',
    '/app/api/login.php',
    '/app/api/login_process.php',
    '/app/pages/logout.php',
];

// If current request is a public path, do nothing
foreach ($publicPaths as $pub) {
    if (sf_uri_ends_with($current, $pub)) {
        return;
    }
}

// Auth: JSON 401 for API/fetch, redirect for normal browser navigations
if (!sf_current_user()) {
    if (sf_is_api_path() || sf_is_fetch_request()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
// yhtenäinen formaatti frontendille
echo json_encode(['success' => false, 'error' => 'Authentication required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Save the requested URL for redirect after login
    // Only save internal relative URLs to prevent open redirect vulnerabilities
    $requestedUrl = $_SERVER['REQUEST_URI'] ?? '';
    if (sf_is_safe_redirect_url($requestedUrl)) {
        $_SESSION['login_redirect'] = $requestedUrl;
    }
    
    sf_redirect_to_login();
}// Enforce inactivity timeout + audit "resume" for authenticated requests
sf_session_activity_tick([
    'is_api'   => sf_is_api_path(),
    'is_fetch' => sf_is_fetch_request(),
]);
// CSRF for state-changing requests
// NOTE: Some endpoints (e.g. JSON-body endpoints) validate CSRF manually.
// They can define SF_SKIP_AUTO_CSRF before including protect.php.
if (!defined('SF_SKIP_AUTO_CSRF')) {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        // For API/fetch: return JSON on failure
        if (sf_is_api_path() || sf_is_fetch_request()) {
            sf_csrf_check_strict();
        } else {
            sf_csrf_check();
        }
    }
}