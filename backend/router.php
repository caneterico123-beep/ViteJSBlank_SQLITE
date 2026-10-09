<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/middleware/auth.php';
require __DIR__ . '/pos_helpers.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';
$routes = [
    'GET /api/csrf' => __DIR__ . '/api/csrf.php',
    'POST /api/register' => __DIR__ . '/api/register.php',
    'POST /api/login' => __DIR__ . '/api/login.php',
    'POST /api/logout' => __DIR__ . '/api/logout.php',
    'GET /api/me' => __DIR__ . '/api/me.php',
    'GET /api/dashboard/stats' => __DIR__ . '/api/dashboard_stats.php',
    'GET /api/dashboard/registrations' => __DIR__ . '/api/dashboard_registrations.php',
    'GET /api/users' => __DIR__ . '/api/users.php',
    'GET /api/profile' => __DIR__ . '/api/profile.php',
    'PUT /api/profile' => __DIR__ . '/api/profile_update.php',
    'GET /api/settings' => __DIR__ . '/api/settings.php',
    'PUT /api/settings' => __DIR__ . '/api/settings_update.php',
    'GET /api/pos/config' => __DIR__ . '/api/pos_config.php',
    'GET /api/products' => __DIR__ . '/api/products.php',
    'GET /api/categories' => __DIR__ . '/api/categories.php',
    'POST /api/products' => __DIR__ . '/api/products_create.php',
    'PUT /api/products' => __DIR__ . '/api/products_update.php',
    'DELETE /api/products' => __DIR__ . '/api/products_deactivate.php',
    'POST /api/categories' => __DIR__ . '/api/categories_create.php',
    'PUT /api/categories' => __DIR__ . '/api/categories_update.php',
    'DELETE /api/categories' => __DIR__ . '/api/categories_deactivate.php',
    'GET /api/inventory' => __DIR__ . '/api/inventory.php',
    'POST /api/inventory/adjustments' => __DIR__ . '/api/inventory_adjustment.php',
    'POST /api/pos/checkout' => __DIR__ . '/api/pos_checkout.php',
    'GET /api/pos/transactions' => __DIR__ . '/api/pos_transactions.php',
    'GET /api/pos/transactions/detail' => __DIR__ . '/api/pos_transaction_detail.php',
    'POST /api/pos/transactions/cancel' => __DIR__ . '/api/pos_transaction_cancel.php',
];
$route = $routes[$method . ' ' . $path] ?? null;

if ($route === null && preg_match('#^/api/products/(\d+)$#', $path, $matches) === 1) {
    $_GET['route_id'] = $matches[1];
    $route = $routes[$method . ' /api/products'] ?? null;
}
if ($route === null && preg_match('#^/api/categories/(\d+)$#', $path, $matches) === 1) {
    $_GET['route_id'] = $matches[1];
    $route = $routes[$method . ' /api/categories'] ?? null;
}
if ($route === null && preg_match('#^/api/pos/transactions/(\d+)/cancel$#', $path, $matches) === 1) {
    $_GET['route_id'] = $matches[1];
    $route = $routes[$method . ' /api/pos/transactions/cancel'] ?? null;
}
if ($route === null && preg_match('#^/api/pos/transactions/(\d+)$#', $path, $matches) === 1) {
    $_GET['route_id'] = $matches[1];
    $route = $routes[$method . ' /api/pos/transactions/detail'] ?? null;
}

if ($route === null) {
    json_response(404, ['message' => 'API endpoint not found.']);
}

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
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