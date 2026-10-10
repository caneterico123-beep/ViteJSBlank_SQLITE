<?php
declare(strict_types=1);

const MAX_REQUEST_BYTES = 16384;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Vary: Origin');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$allowedOrigin = getenv('AUTH_FRONTEND_ORIGIN') ?: 'http://localhost:5173';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($requestOrigin !== '' && !hash_equals($allowedOrigin, $requestOrigin)) {
    json_response(403, ['message' => 'Origin is not allowed.']);
}

if ($requestOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Idempotency-Key, X-Business-Id');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', '2592000');

$secureSetting = getenv('AUTH_COOKIE_SECURE');
$secureCookie = $secureSetting === false
    ? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    : filter_var($secureSetting, FILTER_VALIDATE_BOOL);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function json_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method . ', OPTIONS');
        json_response(405, ['message' => 'Method not allowed.']);
    }
}

function request_json(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== 0) {
        json_response(415, ['message' => 'Content-Type must be application/json.']);
    }

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > MAX_REQUEST_BYTES) {
        json_response(413, ['message' => 'Request body is too large.']);
    }

    $body = file_get_contents('php://input', false, null, 0, MAX_REQUEST_BYTES + 1);
    if ($body === false || strlen($body) > MAX_REQUEST_BYTES) {
        json_response(413, ['message' => 'Request body is too large.']);
    }

    try {
        $decoded = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(400, ['message' => 'Request body must contain valid JSON.']);
    }

    if (!is_object($decoded)) {
        json_response(400, ['message' => 'Request body must be a JSON object.']);
    }

    return get_object_vars($decoded);
}

function require_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($expected) || $expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
        json_response(419, ['message' => 'Your session token expired. Refresh the page and try again.']);
    }
}

function new_csrf_token(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function session_cookie_options(int $expires = 0): array
{
    $params = session_get_cookie_params();
    return [
        'expires' => $expires,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ];
}

function refresh_session_cookie(int $expires = 0): void
{
    setcookie(session_name(), session_id(), session_cookie_options($expires));
}

function enforce_rate_limit(PDO $pdo, string $scope, int $limit, int $windowSeconds): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = $scope . ':' . hash('sha256', $ip);
    $now = time();

    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT window_started, attempts FROM rate_limits WHERE rate_key = :rate_key');
        $query->execute(['rate_key' => $key]);
        $row = $query->fetch();

        if ($row === false || $now >= ((int) $row['window_started'] + $windowSeconds)) {
            $save = $pdo->prepare(
                'INSERT INTO rate_limits (rate_key, window_started, attempts) VALUES (:rate_key, :started, 1) '
                . 'ON CONFLICT(rate_key) DO UPDATE SET window_started = excluded.window_started, attempts = 1',
            );
            $save->execute(['rate_key' => $key, 'started' => $now]);
        } elseif ((int) $row['attempts'] >= $limit) {
            $pdo->commit();
            header('Retry-After: ' . max(1, (int) $row['window_started'] + $windowSeconds - $now));
            json_response(429, ['message' => 'Too many attempts. Please try again later.']);
        } else {
            $save = $pdo->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE rate_key = :rate_key');
            $save->execute(['rate_key' => $key]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}