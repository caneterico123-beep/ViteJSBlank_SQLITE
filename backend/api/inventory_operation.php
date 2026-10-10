<?php
declare(strict_types=1);

require_method('POST');
$pdo = database_connection();
$user = require_inventory_operator($pdo);
$input = request_json();
$productId = pos_int($input['product_id'] ?? null, 1, PHP_INT_MAX);
$quantity = pos_int($input['quantity'] ?? null, 1, 1000000);
$type = $input['transaction_type'] ?? null;
$reasonInput = $input['reason'] ?? '';
$referenceInput = $input['reference_number'] ?? '';
$supplierIdInput = $input['supplier_id'] ?? null;
$validTypes = ['stock_in', 'stock_out', 'adjustment_increase', 'adjustment_decrease'];

$errors = [];
if ($productId === null) $errors['product_id'] = 'Choose a valid product.';
if ($quantity === null) $errors['quantity'] = 'Enter a positive whole-number quantity.';
if (!is_string($type) || !in_array($type, $validTypes, true)) $errors['transaction_type'] = 'Choose a valid stock operation.';
if (!is_string($reasonInput) || mb_strlen($reasonInput, 'UTF-8') > 500) $errors['reason'] = 'Reason must be 500 characters or fewer.';
if (is_string($type) && in_array($type, ['stock_out', 'adjustment_increase', 'adjustment_decrease'], true)
    && (!is_string($reasonInput) || trim($reasonInput) === '')) {
    $errors['reason'] = 'A reason is required for this operation.';
}
if (!is_string($referenceInput) || mb_strlen($referenceInput, 'UTF-8') > 100) $errors['reference_number'] = 'Reference number must be 100 characters or fewer.';
$supplierId = null;
if ($supplierIdInput !== null && $supplierIdInput !== '') {
    $supplierId = pos_int($supplierIdInput, 1, PHP_INT_MAX);
    if ($supplierId === null) $errors['supplier_id'] = 'Choose a valid supplier.';
}
if ($errors !== []) json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);

if ($supplierId !== null) {
    $supplierCheck = $pdo->prepare('SELECT 1 FROM suppliers WHERE id = :id AND business_id = :business_id AND is_active = 1');
    $supplierCheck->execute(['id' => $supplierId, 'business_id' => $user['business_id']]);
    if (!$supplierCheck->fetchColumn()) {
        json_response(422, ['message' => 'Choose an active supplier.', 'errors' => ['supplier_id' => 'Choose an active supplier.']]);
    }
}

$quantityChange = in_array($type, ['stock_in', 'adjustment_increase'], true) ? $quantity : -$quantity;
try {
    $pdo->beginTransaction();
    $update = $pdo->prepare(
        'UPDATE products SET stock_quantity = stock_quantity + :delta_apply, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND business_id = :business_id AND is_active = 1 AND stock_quantity + :delta_check >= 0',
    );
    $update->execute(['delta_apply' => $quantityChange, 'id' => $productId, 'business_id' => $user['business_id'], 'delta_check' => $quantityChange]);
    if ($update->rowCount() !== 1) {
        $productQuery = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :business_id AND is_active = 1');
        $productQuery->execute(['id' => $productId, 'business_id' => $user['business_id']]);
        $productExists = $productQuery->fetchColumn();
        $productQuery->closeCursor();
        $pdo->rollBack();
        if ($productExists === false) json_response(404, ['message' => 'Product not found.']);
        json_response(409, ['message' => 'There is not enough stock for this operation.']);
    }
    $quantityAfterQuery = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :business_id');
    $quantityAfterQuery->execute(['id' => $productId, 'business_id' => $user['business_id']]);
    $quantityAfter = (int) $quantityAfterQuery->fetchColumn();
    $quantityAfterQuery->closeCursor();
    $quantityBefore = $quantityAfter - $quantityChange;
    $reason = trim($reasonInput);
    $referenceNumber = trim($referenceInput);
    $movement = $pdo->prepare(
        "INSERT INTO inventory_movements (business_id, product_id, user_id, movement_type, quantity_change, notes)
         VALUES (:business_id, :product_id, :user_id, 'adjustment', :quantity_change, :notes)",
    );
    $movement->execute([
        'business_id' => $user['business_id'],
        'product_id' => $productId,
        'user_id' => $user['id'],
        'quantity_change' => $quantityChange,
        'notes' => $reason === '' ? null : $reason,
    ]);
    $movementId = (int) $pdo->lastInsertId();
    $detail = $pdo->prepare(
        'INSERT INTO inventory_movement_details
         (business_id, movement_id, transaction_type, quantity_before, quantity_after, reason, reference_number, supplier_id)
         VALUES (:business_id, :movement_id, :transaction_type, :quantity_before, :quantity_after, :reason, :reference_number, :supplier_id)',
    );
    $detail->execute([
        'business_id' => $user['business_id'],
        'movement_id' => $movementId,
        'transaction_type' => $type,
        'quantity_before' => $quantityBefore,
        'quantity_after' => $quantityAfter,
        'reason' => $reason === '' ? null : $reason,
        'reference_number' => $referenceNumber === '' ? null : $referenceNumber,
        'supplier_id' => $supplierId,
    ]);
    $audit = $pdo->prepare(
        'INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id, details)
         VALUES (:business_id, :actor_id, :action, :entity_type, :entity_id, :details)',
    );
    $audit->execute([
        'business_id' => $user['business_id'],
        'actor_id' => $user['id'],
        'action' => $type,
        'entity_type' => 'product',
        'entity_id' => $productId,
        'details' => json_encode(['quantity_change' => $quantityChange, 'quantity_before' => $quantityBefore, 'quantity_after' => $quantityAfter, 'reference_number' => $referenceNumber], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

json_response(201, [
    'message' => 'Inventory updated.',
    'transaction' => [
        'id' => $movementId,
        'transaction_type' => $type,
        'quantity_change' => $quantityChange,
        'quantity_before' => $quantityBefore,
        'quantity_after' => $quantityAfter,
    ],
]);
