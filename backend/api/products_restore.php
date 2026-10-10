<?php
declare(strict_types=1);

require_method('POST');
$pdo = database_connection();
$user = require_inventory_manager($pdo);
$id = pos_route_id();
$pdo->beginTransaction();
try {
$restore = $pdo->prepare('UPDATE products SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND business_id = :business_id AND is_active = 0');
$restore->execute(['id' => $id, 'business_id' => $user['business_id']]);
if ($restore->rowCount() !== 1) {
    $pdo->rollBack();
    json_response(404, ['message' => 'Archived product not found.']);
}
$audit = $pdo->prepare(
    "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id)
     VALUES (:business_id, :actor_id, 'product_restored', 'product', :entity_id)",
);
$audit->execute(['business_id' => $user['business_id'], 'actor_id' => $user['id'], 'entity_id' => $id]);
$pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
json_response(200, ['message' => 'Product restored.']);
