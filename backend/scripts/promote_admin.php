<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run in the CLI.\n");
    exit(1);
}

require __DIR__ . '/../config/database.php';

$action = $argv[1] ?? '';
$roleOptions = ['--admin' => 'admin', '--cashier' => 'cashier', '--revoke' => 'manager'];
$role = $roleOptions[$action] ?? 'admin';
$businessIdInput = isset($roleOptions[$action]) ? ($argv[2] ?? '') : $action;
$email = isset($roleOptions[$action]) ? ($argv[3] ?? '') : ($argv[2] ?? '');
$businessId = filter_var($businessIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($businessId === false || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php backend/scripts/promote_admin.php [--admin|--cashier|--revoke] business-id user@example.com\n");
    exit(2);
}

$pdo = database_connection();
$userQuery = $pdo->prepare('SELECT id FROM users WHERE email = :email COLLATE NOCASE');
$userQuery->execute(['email' => strtolower(trim($email))]);
$userId = $userQuery->fetchColumn();
if ($userId === false) {
    fwrite(STDERR, "No matching user was found. Check the email address.\n");
    exit(3);
}
$business = $pdo->prepare('SELECT industry FROM businesses WHERE id = :business_id');
$business->execute(['business_id' => $businessId]);
$industry = $business->fetchColumn();
$industryRoles = [
    'retail' => ['manager', 'cashier'], 'grocery' => ['manager', 'cashier'],
    'pharmacy' => ['manager', 'cashier'], 'clinic' => ['manager'],
    'bakery' => ['manager', 'cashier'], 'hotel' => ['manager'],
    'restaurant' => ['manager', 'cashier'], 'rent_a_car' => ['manager'],
    'computer_store' => ['manager', 'cashier'], 'coffee_shop' => ['manager', 'cashier'],
];
if (!is_string($industry) || ($role !== 'admin' && !in_array($role, $industryRoles[$industry] ?? [], true))) {
    fwrite(STDERR, "That role is not available for the selected business type.\n");
    exit(4);
}
$promote = $pdo->prepare(
    "UPDATE business_members SET role = :role
     WHERE business_id = :business_id AND user_id = :user_id AND is_active = 1 AND role <> 'owner'",
);
$promote->execute(['role' => $role, 'business_id' => $businessId, 'user_id' => $userId]);

if ($promote->rowCount() === 0) {
    fwrite(STDERR, "No non-owner active business memberships were updated.\n");
    exit(3);
}

$labels = ['admin' => 'Administrator', 'cashier' => 'Cashier', 'manager' => 'Manager'];
fwrite(STDOUT, $labels[$role] . " role set for {$email} in business {$businessId}.\n");