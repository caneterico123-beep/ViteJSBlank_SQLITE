<?php
declare(strict_types=1);

require_method('PUT');
$pdo = database_connection();
$user = require_inventory_manager($pdo);
$input = request_json();
$name = $input['name'] ?? null;
if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name), 'UTF-8') > 60) {
    json_response(422, ['message' => 'Enter a category name up to 60 characters.', 'errors' => ['name' => 'Enter a category name up to 60 characters.']]);
}
$description = $input['description'] ?? '';
if (!is_string($description) || mb_strlen($description, 'UTF-8') > 500) {
    json_response(422, ['message' => 'Description must be 500 characters or fewer.', 'errors' => ['description' => 'Description must be 500 characters or fewer.']]);
}

try {
    $pdo->beginTransaction();
    $id = pos_route_id();
    $update = $pdo->prepare('UPDATE categories SET name = :name, description = :description WHERE id = :id AND business_id = :business_id AND is_active = 1');
    $update->execute(['name' => trim($name), 'description' => trim($description) === '' ? null : trim($description), 'id' => $id, 'business_id' => $user['business_id']]);
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode() === '23000') {
        json_response(409, ['message' => 'That category already exists.']);
    }
    throw $error;
}
if ($update->rowCount() === 0) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(404, ['message' => 'Category not found.']);
}
$audit = $pdo->prepare("INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id) VALUES (:business_id, :actor_id, 'category_updated', 'category', :entity_id)");
$audit->execute(['business_id' => $user['business_id'], 'actor_id' => $user['id'], 'entity_id' => $id]);
$pdo->commit();
json_response(200, ['message' => 'Category updated.']);