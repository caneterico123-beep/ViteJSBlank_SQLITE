<?php
declare(strict_types=1);

require_method('POST');
$pdo = database_connection();
$user = require_inventory_manager($pdo);
$id = pos_route_id();
$pdo->beginTransaction();
try {
    $restore = $pdo->prepare('UPDATE categories SET is_active = 1 WHERE id = :id AND business_id = :business_id AND is_active = 0');
    $restore->execute(['id' => $id, 'business_id' => $user['business_id']]);
    if ($restore->rowCount() !== 1) {
        $pdo->rollBack();
        json_response(404, ['message' => 'Inactive category not found.']);
    }
    $audit = $pdo->prepare(
        "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id)
         VALUES (:business_id, :actor_id, 'category_restored', 'category', :entity_id)",
    );
    $audit->execute(['business_id' => $user['business_id'], 'actor_id' => $user['id'], 'entity_id' => $id]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
json_response(200, ['message' => 'Category reactivated.']);
