<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$admin = require_admin($pdo);

$statsQuery = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN users.last_login_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END) AS active_users,
        SUM(CASE WHEN business_members.created_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS new_registrations
     FROM business_members JOIN users ON users.id = business_members.user_id
     WHERE business_members.business_id = :business_id AND business_members.is_active = 1",
);
$statsQuery->execute(['business_id' => $admin['business_id']]);
$stats = $statsQuery->fetch();

json_response(200, [
    'stats' => [
        'totalUsers' => (int) ($stats['total_users'] ?? 0),
        'activeUsers' => (int) ($stats['active_users'] ?? 0),
        'newRegistrations' => (int) ($stats['new_registrations'] ?? 0),
        'accountStatus' => 'Active',
    ],
    'viewer' => ['id' => (int) $admin['id'], 'role' => $admin['role']],
]);