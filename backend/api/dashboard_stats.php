<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$admin = require_admin($pdo);

$stats = $pdo->query(
    "SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN last_login_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END) AS active_users,
        SUM(CASE WHEN created_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS new_registrations
     FROM users",
)->fetch();

json_response(200, [
    'stats' => [
        'totalUsers' => (int) ($stats['total_users'] ?? 0),
        'activeUsers' => (int) ($stats['active_users'] ?? 0),
        'newRegistrations' => (int) ($stats['new_registrations'] ?? 0),
        'accountStatus' => 'Active',
    ],
    'viewer' => ['id' => (int) $admin['id'], 'role' => $admin['role']],
]);