<?php
declare(strict_types=1);

require_method('POST');
$pdo = database_connection();
$user = require_pos_operator($pdo);
$saleId = pos_route_id();
$input = request_json();
$reason = $input['reason'] ?? '';
if (!is_string($reason) || mb_strlen($reason, 'UTF-8') > 500) json_response(422, ['message' => 'Reason must be 500 characters or fewer.']);

$pdo->beginTransaction();
try {
    $saleQuery = $pdo->prepare('SELECT * FROM sales WHERE id = :id');
    $saleQuery->execute(['id' => $saleId]);
    $sale = $saleQuery->fetch();
    if ($sale === false) {
        $pdo->rollBack();
        json_response(404, ['message' => 'Transaction not found.']);
    }
    if ($user['role'] !== 'admin' && (int) $sale['cashier_id'] !== (int) $user['id']) {
        $pdo->rollBack();
        json_response(403, ['message' => 'You do not have access to this transaction.']);
    }
    if (in_array($sale['payment_status'], ['voided', 'cancelled'], true)) {
        $pdo->rollBack();
        json_response(409, ['message' => 'This transaction has already been cancelled or voided.']);
    }
    if ($sale['payment_status'] === 'paid' && $user['role'] !== 'admin') {
        $pdo->rollBack();
        json_response(403, ['message' => 'Only an administrator can void a completed payment.']);
    }

    $newStatus = $sale['payment_status'] === 'paid' ? 'voided' : 'cancelled';
    $update = $pdo->prepare('UPDATE sales SET payment_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $update->execute(['status' => $newStatus, 'id' => $saleId]);
    $items = $pdo->prepare('SELECT product_id, quantity FROM sale_items WHERE sale_id = :sale_id');
    $items->execute(['sale_id' => $saleId]);
    $stock = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + :quantity, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $movement = $pdo->prepare(
        'INSERT INTO inventory_movements (product_id, user_id, movement_type, quantity_change, reference_sale_id, notes)
         VALUES (:product_id, :user_id, :movement_type, :quantity_change, :sale_id, :notes)',
    );
    foreach ($items->fetchAll() as $item) {
        $stock->execute(['quantity' => $item['quantity'], 'id' => $item['product_id']]);
        $movement->execute([
            'product_id' => $item['product_id'],
            'user_id' => $user['id'],
            'movement_type' => $newStatus === 'voided' ? 'void' : 'return',
            'quantity_change' => $item['quantity'],
            'sale_id' => $saleId,
            'notes' => trim($reason) === '' ? 'Transaction ' . $newStatus : trim($reason),
        ]);
    }
    $audit = $pdo->prepare('INSERT INTO sale_audit_log (sale_id, user_id, action, reason) VALUES (:sale_id, :user_id, :action, :reason)');
    $audit->execute([
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
json_response(200, ['message' => $message, 'sale' => pos_sale($pdo, $saleId)]);