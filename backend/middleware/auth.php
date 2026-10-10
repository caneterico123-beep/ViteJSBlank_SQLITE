<?php
declare(strict_types=1);

function require_authenticated_user(PDO $pdo): array
{
    $userId = $_SESSION['user_id'] ?? null;
    $lastActivity = $_SESSION['last_activity'] ?? null;
    $expiresAt = $_SESSION['auth_expires_at'] ?? null;

    if (!is_int($userId) || !is_int($lastActivity) || !is_int($expiresAt)
        || time() - $lastActivity > 1800 || time() >= $expiresAt) {
        unset($_SESSION['user_id'], $_SESSION['last_activity'], $_SESSION['auth_expires_at']);
        json_response(401, ['message' => 'Authentication required.']);
    }

    $query = $pdo->prepare('SELECT id, name, email, created_at FROM users WHERE id = :id');
    $query->execute(['id' => $userId]);
    $user = $query->fetch();

    if ($user === false) {
        unset($_SESSION['user_id'], $_SESSION['last_activity'], $_SESSION['auth_expires_at']);
        json_response(401, ['message' => 'Authentication required.']);
    }

    $requestedBusinessId = $_SERVER['HTTP_X_BUSINESS_ID'] ?? null;
    if ($requestedBusinessId !== null && (!is_string($requestedBusinessId) || !ctype_digit($requestedBusinessId))) {
        json_response(400, ['message' => 'Choose a valid business workspace.']);
    }
    $businessId = $requestedBusinessId === null
        ? ($_SESSION['business_id'] ?? null)
        : (int) $requestedBusinessId;
    if (!is_int($businessId) || $businessId < 1) {
        $businessId = null;
    }
    if ($businessId !== null) {
        $membershipQuery = $pdo->prepare(
            'SELECT business_members.business_id, business_members.role, businesses.name AS business_name,
                    businesses.industry, businesses.currency, businesses.timezone, businesses.settings
             FROM business_members JOIN businesses ON businesses.id = business_members.business_id
             WHERE business_members.user_id = :user_id AND business_members.business_id = :business_id
               AND business_members.is_active = 1',
        );
        $membershipQuery->execute(['user_id' => $userId, 'business_id' => $businessId]);
        $membership = $membershipQuery->fetch();
    } else {
        $membershipQuery = $pdo->prepare(
            'SELECT business_members.business_id, business_members.role, businesses.name AS business_name,
                    businesses.industry, businesses.currency, businesses.timezone, businesses.settings
             FROM business_members JOIN businesses ON businesses.id = business_members.business_id
             WHERE business_members.user_id = :user_id AND business_members.is_active = 1
             ORDER BY business_members.id LIMIT 1',
        );
        $membershipQuery->execute(['user_id' => $userId]);
        $membership = $membershipQuery->fetch();
    }
    if ($membership === false) {
        json_response(403, ['message' => 'No active business membership is available.']);
    }
    $membershipsQuery = $pdo->prepare(
        'SELECT businesses.id, businesses.name, businesses.industry, business_members.role
         FROM business_members JOIN businesses ON businesses.id = business_members.business_id
         WHERE business_members.user_id = :user_id AND business_members.is_active = 1
         ORDER BY businesses.name COLLATE NOCASE',
    );
    $membershipsQuery->execute(['user_id' => $userId]);
    $user['businesses'] = $membershipsQuery->fetchAll();
    foreach ($user['businesses'] as &$business) {
        $business['id'] = (int) $business['id'];
    }
    unset($business);
    $user['business_id'] = (int) $membership['business_id'];
    $user['role'] = $membership['role'];
    $user['business'] = [
        'id' => (int) $membership['business_id'],
        'name' => $membership['business_name'],
        'industry' => $membership['industry'],
        'currency' => $membership['currency'],
        'timezone' => $membership['timezone'],
        'settings' => json_decode($membership['settings'], true, 16, JSON_THROW_ON_ERROR),
    ];
    $_SESSION['business_id'] = $user['business_id'];
    $_SESSION['last_activity'] = time();
    return $user;
}

function require_admin(PDO $pdo): array
{
    return require_role($pdo, ['owner', 'admin']);
}

function require_inventory_manager(PDO $pdo): array
{
    return require_role($pdo, ['owner', 'admin', 'manager']);
}

function require_inventory_operator(PDO $pdo): array
{
    return require_role($pdo, ['owner', 'admin', 'manager', 'inventory_staff']);
}

function require_pos_operator(PDO $pdo): array
{
    return require_role($pdo, ['owner', 'admin', 'manager', 'cashier']);
}

function require_pos_enabled_user(PDO $pdo): array
{
    $user = require_authenticated_user($pdo);
    $allowedRoles = ['owner', 'admin', 'manager', 'cashier'];
    if ($user['business']['industry'] === 'pharmacy') {
        $allowedRoles[] = 'pharmacist';
    }
    if (!in_array($user['role'], $allowedRoles, true)) {
        json_response(403, ['message' => 'You do not have permission to use Point of Sale in the selected business.']);
    }
    if (!in_array($user['business']['industry'], ['retail', 'grocery', 'pharmacy', 'bakery', 'restaurant', 'computer_store', 'coffee_shop'], true)) {
        json_response(403, ['message' => 'Point of Sale is not enabled for this business type.']);
    }
    return $user;
}

function require_pharmacy_pos_user(PDO $pdo): array
{
    $user = require_authenticated_user($pdo);
    if ($user['business']['industry'] !== 'pharmacy'
        || !in_array($user['role'], ['owner', 'admin', 'manager', 'cashier', 'pharmacist'], true)) {
        json_response(403, ['message' => 'You do not have permission to use Pharmacy POS in this business.']);
    }
    return $user;
}

function require_role(PDO $pdo, array $roles): array
{
    $user = require_authenticated_user($pdo);
    if (!in_array($user['role'], $roles, true)) {
        json_response(403, ['message' => 'You do not have permission to perform this action in the selected business.']);
    }
    return $user;
}