<?php
declare(strict_types=1);

$pdo = database_connection();
$actor = require_admin($pdo);
$industryRoles = [
    'retail' => ['manager', 'cashier', 'inventory_staff'],
    'grocery' => ['manager', 'cashier', 'inventory_staff'],
    'pharmacy' => ['manager', 'cashier', 'inventory_staff', 'pharmacist'],
    'clinic' => ['manager', 'receptionist', 'clinician', 'inventory_staff'],
    'bakery' => ['manager', 'cashier', 'inventory_staff', 'kitchen_staff'],
    'hotel' => ['manager', 'receptionist', 'housekeeper'],
    'restaurant' => ['manager', 'cashier', 'kitchen_staff'],
    'rent_a_car' => ['manager', 'mechanic'],
    'computer_store' => ['manager', 'cashier', 'inventory_staff'],
    'coffee_shop' => ['manager', 'cashier', 'kitchen_staff'],
];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$memberId = filter_var($_GET['route_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$industry = $actor['business']['industry'];
$assignableRoles = $industryRoles[$industry] ?? [];
$rolesAvailableToActor = $actor['role'] === 'owner'
    ? array_merge($assignableRoles, ['admin', 'owner'])
    : $assignableRoles;

if ($method === 'GET') {
    $query = $pdo->prepare(
        'SELECT business_members.id AS membership_id, users.id AS user_id, users.name, users.email, business_members.role,
                business_members.is_active, business_members.created_at
         FROM business_members JOIN users ON users.id = business_members.user_id
         WHERE business_members.business_id = :business_id
         ORDER BY users.name COLLATE NOCASE',
    );
    $query->execute(['business_id' => $actor['business_id']]);
    $members = $query->fetchAll();
    foreach ($members as &$member) {
        $member['user_id'] = (int) $member['user_id'];
        $member['membership_id'] = (int) $member['membership_id'];
        $member['is_active'] = (bool) $member['is_active'];
    }
    unset($member);
    json_response(200, ['members' => $members, 'assignable_roles' => $rolesAvailableToActor]);
}

if ($method === 'POST') {
    $input = request_json();
    $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
    $role = $input['role'] ?? null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($role) || !in_array($role, array_merge(['owner', 'admin'], $assignableRoles), true)) {
        json_response(422, ['message' => 'Enter an existing account email and a role available for this business.']);
    }
    $lookup = $pdo->prepare('SELECT id FROM users WHERE email = :email COLLATE NOCASE');
    $lookup->execute(['email' => $email]);
    $memberUserId = $lookup->fetchColumn();
    if ($memberUserId === false) {
        json_response(404, ['message' => 'Create the user account before adding it to this business.']);
    }
    $existing = $pdo->prepare('SELECT id, is_active FROM business_members WHERE business_id = :business_id AND user_id = :user_id');
    $existing->execute(['business_id' => $actor['business_id'], 'user_id' => $memberUserId]);
    $membership = $existing->fetch();
    if ($membership !== false && (bool) $membership['is_active']) {
        json_response(409, ['message' => 'This user is already an active member of the business.']);
    }
    if (in_array($role, ['owner', 'admin'], true) && $actor['role'] !== 'owner') {
        json_response(403, ['message' => 'Only the business owner can assign owner or administrator access.']);
    }
    $pdo->beginTransaction();
    if ($membership === false) {
        $save = $pdo->prepare('INSERT INTO business_members (business_id, user_id, role) VALUES (:business_id, :user_id, :role)');
    } else {
        $save = $pdo->prepare('UPDATE business_members SET role = :role, is_active = 1 WHERE business_id = :business_id AND user_id = :user_id');
    }
    $save->execute(['business_id' => $actor['business_id'], 'user_id' => $memberUserId, 'role' => $role]);
    $audit = $pdo->prepare(
        'INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id, details)
         VALUES (:business_id, :actor_id, :action, :entity_type, :entity_id, :details)',
    );
    $audit->execute([
        'business_id' => $actor['business_id'],
        'actor_id' => $actor['id'],
        'action' => $membership === false ? 'member_added' : 'member_reactivated',
        'entity_type' => 'business_member',
        'entity_id' => (int) $memberUserId,
        'details' => json_encode(['role' => $role], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    json_response(201, ['message' => 'Business membership saved.', 'user_id' => (int) $memberUserId, 'role' => $role]);
}

if ($method === 'PUT') {
    if ($memberId === false || $memberId === null) {
        json_response(400, ['message' => 'A valid member ID is required.']);
    }
    $input = request_json();
    $role = $input['role'] ?? null;
    if (!is_string($role) || !in_array($role, array_merge(['owner', 'admin'], $assignableRoles), true)) {
        json_response(422, ['message' => 'Choose a role available for this business.']);
    }
    if (in_array($role, ['owner', 'admin'], true) && $actor['role'] !== 'owner') {
        json_response(403, ['message' => 'Only the business owner can assign owner or administrator access.']);
    }
    $pdo->beginTransaction();
    $target = $pdo->prepare('SELECT user_id, role FROM business_members WHERE id = :id AND business_id = :business_id AND is_active = 1');
    $target->execute(['id' => $memberId, 'business_id' => $actor['business_id']]);
    $member = $target->fetch();
    if ($member === false) json_response(404, ['message' => 'Active business member not found.']);
    if ($member['role'] === 'owner' && $role !== 'owner') {
        $owners = $pdo->prepare("SELECT COUNT(*) FROM business_members WHERE business_id = :business_id AND is_active = 1 AND role = 'owner'");
        $owners->execute(['business_id' => $actor['business_id']]);
        if ((int) $owners->fetchColumn() <= 1) json_response(409, ['message' => 'A business must retain at least one active owner.']);
    }
    $update = $pdo->prepare('UPDATE business_members SET role = :role WHERE id = :id AND business_id = :business_id');
    $update->execute(['role' => $role, 'id' => $memberId, 'business_id' => $actor['business_id']]);
    $audit = $pdo->prepare(
        "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id, details)
         VALUES (:business_id, :actor_id, 'member_role_changed', 'business_member', :entity_id, :details)",
    );
    $audit->execute([
        'business_id' => $actor['business_id'],
        'actor_id' => $actor['id'],
        'entity_id' => (int) $member['user_id'],
        'details' => json_encode(['role_before' => $member['role'], 'role_after' => $role], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    json_response(200, ['message' => 'Business member role updated.']);
}

if ($method === 'DELETE') {
    if ($memberId === false || $memberId === null) json_response(400, ['message' => 'A valid member ID is required.']);
    $pdo->beginTransaction();
    $target = $pdo->prepare('SELECT user_id, role FROM business_members WHERE id = :id AND business_id = :business_id AND is_active = 1');
    $target->execute(['id' => $memberId, 'business_id' => $actor['business_id']]);
    $member = $target->fetch();
    if ($member === false) json_response(404, ['message' => 'Active business member not found.']);
    if ((int) $member['user_id'] === (int) $actor['id']) json_response(409, ['message' => 'You cannot deactivate your own business membership.']);
    if ($member['role'] === 'owner') {
        $owners = $pdo->prepare("SELECT COUNT(*) FROM business_members WHERE business_id = :business_id AND is_active = 1 AND role = 'owner'");
        $owners->execute(['business_id' => $actor['business_id']]);
        if ((int) $owners->fetchColumn() <= 1) json_response(409, ['message' => 'A business must retain at least one active owner.']);
    }
    $update = $pdo->prepare('UPDATE business_members SET is_active = 0 WHERE id = :id AND business_id = :business_id');
    $update->execute(['id' => $memberId, 'business_id' => $actor['business_id']]);
    $audit = $pdo->prepare(
        "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id)
         VALUES (:business_id, :actor_id, 'member_deactivated', 'business_member', :entity_id)",
    );
    $audit->execute(['business_id' => $actor['business_id'], 'actor_id' => $actor['id'], 'entity_id' => (int) $member['user_id']]);
    $pdo->commit();
    json_response(200, ['message' => 'Business membership deactivated.']);
}

header('Allow: GET, POST, PUT, DELETE, OPTIONS');
json_response(405, ['message' => 'Method not allowed.']);
