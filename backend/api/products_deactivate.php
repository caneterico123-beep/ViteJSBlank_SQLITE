<?php
declare(strict_types=1);

require_method('DELETE');
$pdo = database_connection();
$user = require_inventory_manager($pdo);
$id = pos_route_id();
$pdo->beginTransaction();
try {
$update = $pdo->prepare('UPDATE products SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND business_id = :business_id AND is_active = 1');
$update->execute(['id' => $id, 'business_id' => $user['business_id']]);
if ($update->rowCount() === 0) {
    $pdo->rollBack();
    json_response(404, ['message' => 'Product not found.']);
}
$audit = $pdo->prepare(
    "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id)
     VALUES (:business_id, :actor_id, 'product_archived', 'product', :entity_id)",
);
$audit->execute(['business_id' => $user['business_id'], 'actor_id' => $user['id'], 'entity_id' => $id]);
$pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
json_response(200, ['message' => 'Product deactivated. Historical sale items are preserved.']);