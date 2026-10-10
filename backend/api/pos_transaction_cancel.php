<?php
declare(strict_types=1);

require_method('POST');
$pdo = database_connection();
$user = require_pos_enabled_user($pdo);
$saleId = pos_route_id();
$input = request_json();
$reason = $input['reason'] ?? '';
if (!is_string($reason) || mb_strlen($reason, 'UTF-8') > 500) json_response(422, ['message' => 'Reason must be 500 characters or fewer.']);

$pdo->beginTransaction();
try {
    $saleQuery = $pdo->prepare('SELECT * FROM sales WHERE id = :id AND business_id = :business_id');
    $saleQuery->execute(['id' => $saleId, 'business_id' => $user['business_id']]);
    $sale = $saleQuery->fetch();
    if ($sale === false) {
        $pdo->rollBack();
        json_response(404, ['message' => 'Transaction not found.']);
    }
    if (!in_array($user['role'], ['owner', 'admin', 'manager'], true) && (int) $sale['cashier_id'] !== (int) $user['id']) {
        $pdo->rollBack();
        json_response(403, ['message' => 'You do not have access to this transaction.']);
    }
    if (in_array($sale['payment_status'], ['voided', 'cancelled'], true)) {
        $pdo->rollBack();
        json_response(409, ['message' => 'This transaction has already been cancelled or voided.']);
    }
    if ($sale['payment_status'] === 'paid' && !in_array($user['role'], ['owner', 'admin', 'manager'], true)) {
        $pdo->rollBack();
        json_response(403, ['message' => 'Only an administrator can void a completed payment.']);
    }

    $newStatus = $sale['payment_status'] === 'paid' ? 'voided' : 'cancelled';
    $update = $pdo->prepare('UPDATE sales SET payment_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND business_id = :business_id');
    $update->execute(['status' => $newStatus, 'id' => $saleId, 'business_id' => $user['business_id']]);
    $items = $pdo->prepare('SELECT id, product_id, quantity FROM sale_items WHERE sale_id = :sale_id AND business_id = :business_id');
    $items->execute(['sale_id' => $saleId, 'business_id' => $user['business_id']]);
    $stock = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + :quantity, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND business_id = :business_id');
    $movement = $pdo->prepare(
        'INSERT INTO inventory_movements (business_id, product_id, user_id, movement_type, quantity_change, reference_sale_id, notes)
         VALUES (:business_id, :product_id, :user_id, :movement_type, :quantity_change, :sale_id, :notes)',
    );
    $detail = $pdo->prepare(
        'INSERT INTO inventory_movement_details
         (business_id, movement_id, transaction_type, quantity_before, quantity_after, reason, reference_number)
         VALUES (:business_id, :movement_id, :transaction_type, :quantity_before, :quantity_after, :reason, :reference_number)',
    );
    $batchAllocations = $pdo->prepare(
        'SELECT allocations.batch_id, allocations.quantity, batches.available_quantity
         FROM medicine_batch_allocations AS allocations
         JOIN medicine_batches AS batches
           ON batches.id = allocations.batch_id AND batches.business_id = allocations.business_id
         WHERE allocations.business_id = :business_id AND allocations.sale_id = :sale_id
           AND allocations.sale_item_id = :sale_item_id',
    );
    $batchRestore = $pdo->prepare(
        'UPDATE medicine_batches SET available_quantity = available_quantity + :quantity, updated_at = CURRENT_TIMESTAMP
         WHERE id = :batch_id AND business_id = :business_id',
    );
    $batchRestoreMovement = $pdo->prepare(
        "INSERT INTO medicine_batch_movements (
            business_id, batch_id, user_id, movement_type, quantity_change,
            quantity_before, quantity_after, reference_type, reference_id, reason
         ) VALUES (:business_id, :batch_id, :user_id, 'void', :quantity, :before, :after, 'sale', :sale_id, :reason)",
    );
    foreach ($items->fetchAll() as $item) {
        $quantityBeforeQuery = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :business_id');
        $quantityBeforeQuery->execute(['id' => $item['product_id'], 'business_id' => $user['business_id']]);
        $quantityBefore = (int) $quantityBeforeQuery->fetchColumn();
        $stock->execute(['quantity' => $item['quantity'], 'id' => $item['product_id'], 'business_id' => $user['business_id']]);
        $movement->execute([
            'business_id' => $user['business_id'],
            'product_id' => $item['product_id'],
            'user_id' => $user['id'],
            'movement_type' => $newStatus === 'voided' ? 'void' : 'return',
            'quantity_change' => $item['quantity'],
            'sale_id' => $saleId,
            'notes' => trim($reason) === '' ? 'Transaction ' . $newStatus : trim($reason),
        ]);
        $detail->execute([
            'business_id' => $user['business_id'],
            'movement_id' => (int) $pdo->lastInsertId(),
            'transaction_type' => $newStatus === 'voided' ? 'stock_in' : 'stock_in',
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityBefore + (int) $item['quantity'],
            'reason' => trim($reason) === '' ? 'Transaction ' . $newStatus : trim($reason),
            'reference_number' => $sale['transaction_number'],
        ]);
        $batchAllocations->execute([
            'business_id' => (int) $user['business_id'],
            'sale_id' => $saleId,
            'sale_item_id' => (int) $item['id'],
        ]);
        foreach ($batchAllocations->fetchAll() as $allocation) {
            $batchBefore = (int) $allocation['available_quantity'];
            $batchQuantity = (int) $allocation['quantity'];
            $batchRestore->execute([
                'quantity' => $batchQuantity,
                'batch_id' => (int) $allocation['batch_id'],
                'business_id' => (int) $user['business_id'],
            ]);
            $batchRestoreMovement->execute([
                'business_id' => (int) $user['business_id'],
                'batch_id' => (int) $allocation['batch_id'],
                'user_id' => (int) $user['id'],
                'quantity' => $batchQuantity,
                'before' => $batchBefore,
                'after' => $batchBefore + $batchQuantity,
                'sale_id' => $saleId,
                'reason' => 'Transaction ' . $newStatus . ': ' . trim($reason),
            ]);
        }
        $batchAllocations->closeCursor();
    }
    $audit = $pdo->prepare('INSERT INTO sale_audit_log (business_id, sale_id, user_id, action, reason) VALUES (:business_id, :sale_id, :user_id, :action, :reason)');
    $audit->execute([
        'business_id' => $user['business_id'],
        'sale_id' => $saleId,
        'user_id' => $user['id'],
        'action' => $newStatus,
        'reason' => trim($reason) === '' ? null : trim($reason),
    ]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

$message = $newStatus === 'voided'
    ? 'Sale voided and inventory restored. Any cash refund must be handled outside this application.'
    : 'Pending transaction cancelled and inventory restored.';
json_response(200, ['message' => $message, 'sale' => pos_sale($pdo, $saleId, $user['business_id'])]);