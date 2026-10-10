<?php
declare(strict_types=1);

require_method('POST');
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
    $insert = $pdo->prepare('INSERT INTO categories (business_id, name, description) VALUES (:business_id, :name, :description)');
    $insert->execute(['business_id' => $user['business_id'], 'name' => trim($name), 'description' => trim($description) === '' ? null : trim($description)]);
    $categoryId = (int) $pdo->lastInsertId();
    $audit = $pdo->prepare("INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id) VALUES (:business_id, :actor_id, 'category_created', 'category', :entity_id)");
    $audit->execute(['business_id' => $user['business_id'], 'actor_id' => $user['id'], 'entity_id' => $categoryId]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode() === '23000') {
        json_response(409, ['message' => 'That category already exists.']);
    }
    throw $error;
}
json_response(201, ['category' => ['id' => $categoryId, 'name' => trim($name), 'description' => trim($description) === '' ? null : trim($description), 'is_active' => true]]);