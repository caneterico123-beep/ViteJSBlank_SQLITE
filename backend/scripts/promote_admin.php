<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run in the CLI.\n");
    exit(1);
}

require __DIR__ . '/../config/database.php';

$action = $argv[1] ?? '';
$roles = ['--admin' => 'admin', '--cashier' => 'cashier', '--revoke' => 'user'];
$role = isset($roles[$action]) ? $roles[$action] : 'admin';
$email = isset($roles[$action]) ? ($argv[2] ?? '') : $action;
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php backend/scripts/promote_admin.php [--admin|--cashier|--revoke] user@example.com\n");
    exit(2);
}

$pdo = database_connection();
$promote = $pdo->prepare('UPDATE users SET role = :role, updated_at = CURRENT_TIMESTAMP WHERE email = :email');
$promote->execute(['role' => $role, 'email' => strtolower(trim($email))]);

if ($promote->rowCount() !== 1) {
    fwrite(STDERR, "No matching user was updated. Check the email address.\n");
    exit(3);
}

$labels = ['admin' => 'Administrator', 'cashier' => 'Cashier', 'user' => 'Regular user'];
fwrite(STDOUT, $labels[$role] . " role set for {$email}.\n");