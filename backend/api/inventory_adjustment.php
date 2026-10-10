<?php
declare(strict_types=1);

require_method('POST');
$pdo = database_connection();
$user = require_inventory_operator($pdo);
$input = request_json();
$productId = pos_int($input['product_id'] ?? null, 1, PHP_INT_MAX);
$quantityChange = pos_int($input['quantity_change'] ?? null, -1000000, 1000000);
$notes = $input['notes'] ?? '';
if ($productId === null || $quantityChange === null || $quantityChange === 0) {
    json_response(422, ['message' => 'Choose a product and a non-zero whole-number adjustment.']);
}
if (!is_string($notes) || mb_strlen($notes, 'UTF-8') > 500) {
    json_response(422, ['message' => 'Notes must be 500 characters or fewer.', 'errors' => ['notes' => 'Notes must be 500 characters or fewer.']]);
}

$quantityAfter = 0;
$quantityBefore = 0;
$pdo->beginTransaction();
try {
    $update = $pdo->prepare(
        'UPDATE products SET stock_quantity = stock_quantity + :change_apply, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND business_id = :business_id AND is_active = 1 AND stock_quantity + :change_check >= 0',
    );
    $update->execute(['change_apply' => $quantityChange, 'change_check' => $quantityChange, 'id' => $productId, 'business_id' => $user['business_id']]);
    if ($update->rowCount() !== 1) {
        $currentQuery = $pdo->prepare('SELECT 1 FROM products WHERE id = :id AND business_id = :business_id AND is_active = 1');
        $currentQuery->execute(['id' => $productId, 'business_id' => $user['business_id']]);
        $exists = $currentQuery->fetchColumn();
        $pdo->rollBack();
        if ($exists === false) json_response(404, ['message' => 'Product is missing or inactive.']);
        json_response(409, ['message' => 'The adjustment would make stock negative.']);
    }
    $afterQuery = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :business_id');
    $afterQuery->execute(['id' => $productId, 'business_id' => $user['business_id']]);
    $quantityAfter = (int) $afterQuery->fetchColumn();
    $afterQuery->closeCursor();
    $quantityBefore = $quantityAfter - $quantityChange;
    $movement = $pdo->prepare(
        "INSERT INTO inventory_movements (business_id, product_id, user_id, movement_type, quantity_change, notes)
         VALUES (:business_id, :product_id, :user_id, 'adjustment', :quantity_change, :notes)",
    );
    $movement->execute([
        'business_id' => $user['business_id'],
        'product_id' => $productId,
        'user_id' => $user['id'],
        'quantity_change' => $quantityChange,
        'notes' => trim($notes) === '' ? null : trim($notes),
    ]);
    $movementId = (int) $pdo->lastInsertId();
    $detail = $pdo->prepare(
        'INSERT INTO inventory_movement_details
         (business_id, movement_id, transaction_type, quantity_before, quantity_after, reason)
         VALUES (:business_id, :movement_id, :transaction_type, :quantity_before, :quantity_after, :reason)',
    );
    $detail->execute([
        'business_id' => $user['business_id'],
        'movement_id' => $movementId,
        'transaction_type' => $quantityChange > 0 ? 'adjustment_increase' : 'adjustment_decrease',
        'quantity_before' => $quantityBefore,
        'quantity_after' => $quantityAfter,
        'reason' => trim($notes) === '' ? null : trim($notes),
    ]);
    $audit = $pdo->prepare(
        "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id, details)
         VALUES (:business_id, :actor_id, 'inventory_adjusted', 'product', :entity_id, :details)",
    );
    $audit->execute([
        'business_id' => $user['business_id'],
        'actor_id' => $user['id'],
        'entity_id' => $productId,
        'details' => json_encode(['quantity_change' => $quantityChange, 'quantity_before' => $quantityBefore, 'quantity_after' => $quantityAfter], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

json_response(200, ['message' => 'Inventory adjusted.']);