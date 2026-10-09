<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/middleware/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';
$routes = [
    'GET /api/csrf' => __DIR__ . '/api/csrf.php',
    'POST /api/register' => __DIR__ . '/api/register.php',
    'POST /api/login' => __DIR__ . '/api/login.php',
    'POST /api/logout' => __DIR__ . '/api/logout.php',
    'GET /api/me' => __DIR__ . '/api/me.php',
];
$route = $routes[$method . ' ' . $path] ?? null;

if ($route === null) {
    json_response(404, ['message' => 'API endpoint not found.']);
}

if ($method === 'POST') {
    require_csrf();
}

try {
    require $route;
} catch (Throwable $error) {
    if ((getenv('APP_ENV') ?: 'development') !== 'production') {
        error_log($error->getMessage());
    }
    json_response(500, ['message' => 'An internal server error occurred.']);
}