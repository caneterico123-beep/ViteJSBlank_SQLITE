<?php
declare(strict_types=1);

require_method('POST');
$pdo = database_connection();
$user = require_admin($pdo);
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

$pdo->beginTransaction();
try {
    $update = $pdo->prepare(
        'UPDATE products SET stock_quantity = stock_quantity + :change, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND is_active = 1 AND stock_quantity + :change >= 0',
    );
    $update->execute(['change' => $quantityChange, 'id' => $productId]);
    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        json_response(409, ['message' => 'Product is missing, inactive, or the adjustment would make stock negative.']);
    }
    $movement = $pdo->prepare(
        "INSERT INTO inventory_movements (product_id, user_id, movement_type, quantity_change, notes)
         VALUES (:product_id, :user_id, 'adjustment', :quantity_change, :notes)",
    );
    $movement->execute([
        'product_id' => $productId,
        'user_id' => $user['id'],
        'quantity_change' => $quantityChange,
        'notes' => trim($notes) === '' ? null : trim($notes),
    ]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

json_response(200, ['message' => 'Inventory adjusted.']);