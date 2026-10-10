<?php
declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = database_connection();
$user = require_inventory_operator($pdo);

if ($method === 'GET') {
    $includeInactive = ($_GET['include_inactive'] ?? '') === '1';
    $query = $pdo->query(
        'SELECT id, name, contact_person, email, phone, address, notes, is_active, created_at, updated_at
         FROM suppliers WHERE business_id = :business_id ' . ($includeInactive ? '' : 'AND is_active = 1 ') . 'ORDER BY name COLLATE NOCASE',
    );
    $query->execute(['business_id' => $user['business_id']]);
    $suppliers = $query->fetchAll();
    foreach ($suppliers as &$supplier) {
        $supplier['id'] = (int) $supplier['id'];
        $supplier['is_active'] = (bool) $supplier['is_active'];
    }
    unset($supplier);
    json_response(200, ['suppliers' => $suppliers]);
}

if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    header('Allow: GET, POST, PUT, DELETE, OPTIONS');
    json_response(405, ['message' => 'Method not allowed.']);
}
if (!in_array($user['role'], ['owner', 'admin', 'manager'], true)) {
    json_response(403, ['message' => 'You do not have permission to change supplier records.']);
}

if ($method === 'DELETE') {
    $id = pos_route_id();
    $pdo->beginTransaction();
    try {
        $deactivate = $pdo->prepare('UPDATE suppliers SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND business_id = :business_id AND is_active = 1');
        $deactivate->execute(['id' => $id, 'business_id' => $user['business_id']]);
        if ($deactivate->rowCount() !== 1) {
            $pdo->rollBack();
            json_response(404, ['message' => 'Active supplier not found.']);
        }
        $audit = $pdo->prepare(
            "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id)
             VALUES (:business_id, :actor_id, 'supplier_deactivated', 'supplier', :entity_id)",
        );
        $audit->execute(['business_id' => $user['business_id'], 'actor_id' => $user['id'], 'entity_id' => $id]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    json_response(200, ['message' => 'Supplier deactivated.']);
}

$input = request_json();
$id = null;
if ($method === 'PUT') $id = pos_route_id();
$nameInput = $input['name'] ?? null;
$name = is_string($nameInput) ? trim($nameInput) : '';
$fields = [];
$errors = [];
if ($name === '' || mb_strlen($name, 'UTF-8') > 120) $errors['name'] = 'Enter a supplier name up to 120 characters.';
foreach (['contact_person' => 120, 'email' => 254, 'phone' => 40, 'address' => 500, 'notes' => 1000] as $field => $maxLength) {
    $value = $input[$field] ?? '';
    if (!is_string($value) || mb_strlen($value, 'UTF-8') > $maxLength) {
        $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " must be {$maxLength} characters or fewer.";
        continue;
    }
    $fields[$field] = trim($value) === '' ? null : trim($value);
}
if (($fields['email'] ?? null) !== null && filter_var($fields['email'], FILTER_VALIDATE_EMAIL) === false) {
    $errors['email'] = 'Enter a valid email address.';
}
if ($errors !== []) json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);

try {
    if ($method === 'POST') {
        $pdo->beginTransaction();
        $save = $pdo->prepare(
            'INSERT INTO suppliers (business_id, name, contact_person, email, phone, address, notes)
             VALUES (:business_id, :name, :contact_person, :email, :phone, :address, :notes)',
        );
        $save->execute(['business_id' => $user['business_id'], 'name' => $name] + $fields);
        $id = (int) $pdo->lastInsertId();
        $audit = $pdo->prepare(
            "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id)
             VALUES (:business_id, :actor_id, 'supplier_created', 'supplier', :entity_id)",
        );
        $audit->execute(['business_id' => $user['business_id'], 'actor_id' => $user['id'], 'entity_id' => $id]);
        $pdo->commit();
        json_response(201, ['message' => 'Supplier created.', 'id' => $id]);
    }
    $pdo->beginTransaction();
    $save = $pdo->prepare(
        'UPDATE suppliers SET name = :name, contact_person = :contact_person, email = :email,
         phone = :phone, address = :address, notes = :notes, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND business_id = :business_id AND is_active = 1',
    );
    $save->execute(['name' => $name, 'id' => $id, 'business_id' => $user['business_id']] + $fields);
    if ($save->rowCount() !== 1) {
        $pdo->rollBack();
        json_response(404, ['message' => 'Active supplier not found.']);
    }
    $audit = $pdo->prepare(
        "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id)
         VALUES (:business_id, :actor_id, 'supplier_updated', 'supplier', :entity_id)",
    );
    $audit->execute(['business_id' => $user['business_id'], 'actor_id' => $user['id'], 'entity_id' => $id]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode() === '23000') json_response(409, ['message' => 'Supplier could not be saved because it conflicts with existing data.']);
    throw $error;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
json_response(200, ['message' => 'Supplier updated.']);
