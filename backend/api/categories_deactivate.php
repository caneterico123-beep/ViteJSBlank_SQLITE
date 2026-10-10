<?php
declare(strict_types=1);

require_method('DELETE');
$pdo = database_connection();
$user = require_inventory_manager($pdo);
$id = pos_route_id();
$pdo->beginTransaction();
try {
$update = $pdo->prepare('UPDATE categories SET is_active = 0 WHERE id = :id AND business_id = :business_id AND is_active = 1');
$update->execute(['id' => $id, 'business_id' => $user['business_id']]);
if ($update->rowCount() === 0) {
    $pdo->rollBack();
    json_response(404, ['message' => 'Category not found.']);
}
$audit = $pdo->prepare("INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id) VALUES (:business_id, :actor_id, 'category_deactivated', 'category', :entity_id)");
$audit->execute(['business_id' => $user['business_id'], 'actor_id' => $user['id'], 'entity_id' => $id]);
$pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
json_response(200, ['message' => 'Category deactivated. Products remain available in transaction history.']);