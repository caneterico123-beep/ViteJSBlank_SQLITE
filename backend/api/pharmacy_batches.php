<?php
declare(strict_types=1);

$pdo = database_connection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_method('GET');
    $user = require_pharmacy_user($pdo, ['owner', 'admin', 'manager', 'pharmacist', 'inventory_staff']);
    $productId = $_GET['product_id'] ?? '';
    $where = ['batches.business_id = :business_id'];
    $params = ['business_id' => (int) $user['business_id']];
    if ($productId !== '') {
        $productId = filter_var($productId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false) json_response(422, ['message' => 'Choose a valid medicine product.']);
        $where[] = 'batches.product_id = :product_id';
        $params['product_id'] = $productId;
    }
    $status = $_GET['status'] ?? '';
    if ($status !== '') {
        if (!is_string($status) || !in_array($status, ['available', 'quarantined', 'recalled', 'damaged', 'disposed', 'expired'], true)) {
            json_response(422, ['message' => 'Choose a valid batch status.']);
        }
        if ($status === 'expired') {
            $where[] = "batches.expires_on <= :today";
            $params['today'] = (new DateTimeImmutable('now', new DateTimeZone($user['business']['timezone'])))->format('Y-m-d');
        } else {
            $where[] = 'batches.status = :status';
            $params['status'] = $status;
        }
    }
    $query = $pdo->prepare(
        'SELECT batches.id, batches.product_id, products.name AS product_name, products.sku, products.barcode,
                batches.batch_number, batches.supplier_id, suppliers.name AS supplier_name,
                batches.manufacturer, batches.manufactured_on, batches.expires_on,
                batches.quantity_received, batches.available_quantity, batches.purchase_cost_cents,
                batches.status, batches.receipt_reference, batches.created_at,
                CASE WHEN batches.expires_on <= :as_of THEN 1 ELSE 0 END AS is_expired,
                CAST(julianday(batches.expires_on) - julianday(:as_of) AS INTEGER) AS days_until_expiry
         FROM medicine_batches AS batches
         JOIN products ON products.id = batches.product_id AND products.business_id = batches.business_id
         LEFT JOIN suppliers ON suppliers.id = batches.supplier_id AND suppliers.business_id = batches.business_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY batches.expires_on, batches.id LIMIT 1000',
    );
    $params['as_of'] = (new DateTimeImmutable('now', new DateTimeZone($user['business']['timezone'])))->format('Y-m-d');
    $query->execute($params);
    $batches = $query->fetchAll();
    foreach ($batches as &$batch) {
        foreach (['id', 'product_id', 'quantity_received', 'available_quantity', 'purchase_cost_cents', 'is_expired', 'days_until_expiry'] as $field) {
            $batch[$field] = (int) $batch[$field];
        }
    }
    unset($batch);
    json_response(200, ['batches' => $batches]);
}

if ($method !== 'POST') json_response(405, ['message' => 'Method not allowed.']);
$user = require_pharmacy_user($pdo, ['owner', 'admin', 'manager', 'pharmacist', 'inventory_staff']);
$input = request_json();
$productId = pos_int($input['product_id'] ?? null, 1, PHP_INT_MAX);
$quantity = pos_int($input['quantity'] ?? null, 1, 1000000);
$cost = pos_int($input['purchase_cost_cents'] ?? null, 0, 1000000000);
$batchNumber = pharmacy_text($input['batch_number'] ?? null, 80, 'batch_number', true);
$receiptKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($input['receipt_key'] ?? null);
$reference = pharmacy_text($input['reference_number'] ?? null, 100, 'reference_number');
$manufacturer = pharmacy_text($input['manufacturer'] ?? null, 200, 'manufacturer');
$expiresOn = pharmacy_date($input['expires_on'] ?? null, 'expires_on');
$manufacturedOn = pharmacy_date($input['manufactured_on'] ?? null, 'manufactured_on', false);
$supplierId = null;
if (($input['supplier_id'] ?? null) !== null && ($input['supplier_id'] ?? '') !== '') {
    $supplierId = pos_int($input['supplier_id'], 1, PHP_INT_MAX);
}
$errors = [];
if ($productId === null) $errors['product_id'] = 'Choose a valid medicine.';
if ($quantity === null) $errors['quantity'] = 'Enter a positive whole-number quantity.';
if ($cost === null) $errors['purchase_cost_cents'] = 'Enter a non-negative cost in cents.';
if (!is_string($receiptKey) || preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $receiptKey) !== 1) {
    $errors['receipt_key'] = 'A unique idempotency key of 16 to 100 characters is required.';
}
if ($supplierId === null && isset($input['supplier_id']) && $input['supplier_id'] !== '') $errors['supplier_id'] = 'Choose a valid supplier.';
if ($manufacturedOn !== null && $manufacturedOn > $expiresOn) $errors['manufactured_on'] = 'Manufacturing date cannot be after expiry.';
if ($errors !== []) json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);

$localToday = (new DateTimeImmutable('now', new DateTimeZone($user['business']['timezone'])))->format('Y-m-d');
if ($expiresOn <= $localToday) json_response(422, ['message' => 'Expired medicine cannot be received into sellable stock.', 'errors' => ['expires_on' => 'Choose a future expiry date.']]);
$productQuery = $pdo->prepare(
    'SELECT products.id, products.stock_quantity, pharmacy_product_details.batch_tracking
     FROM products JOIN pharmacy_product_details
       ON pharmacy_product_details.product_id = products.id AND pharmacy_product_details.business_id = products.business_id
     WHERE products.id = :product_id AND products.business_id = :business_id AND products.is_active = 1',
);
$productQuery->execute(['product_id' => $productId, 'business_id' => (int) $user['business_id']]);
$product = $productQuery->fetch();
if ($product === false || (int) $product['batch_tracking'] !== 1) {
    json_response(422, ['message' => 'Choose an active, batch-tracked pharmacy medicine.']);
}
$productStockBefore = (int) $product['stock_quantity'];
if ($supplierId !== null) {
    $supplierQuery = $pdo->prepare('SELECT 1 FROM suppliers WHERE id = :supplier_id AND business_id = :business_id AND is_active = 1');
    $supplierQuery->execute(['supplier_id' => $supplierId, 'business_id' => (int) $user['business_id']]);
    if (!$supplierQuery->fetchColumn()) json_response(422, ['message' => 'Choose an active supplier from this pharmacy.']);
}

try {
    $pdo->beginTransaction();
    $existingReceiptQuery = $pdo->prepare(
        'SELECT receipts.id, receipts.quantity, receipts.purchase_cost_cents, batches.id AS batch_id,
                batches.product_id, batches.batch_number, batches.expires_on
         FROM medicine_batch_receipts AS receipts
         JOIN medicine_batches AS batches ON batches.id = receipts.batch_id AND batches.business_id = receipts.business_id
         WHERE receipts.business_id = :business_id AND receipts.receipt_key = :receipt_key',
    );
    $existingReceiptQuery->execute(['business_id' => (int) $user['business_id'], 'receipt_key' => $receiptKey]);
    $existingReceipt = $existingReceiptQuery->fetch();
    if ($existingReceipt !== false) {
        $sameReceipt = (int) $existingReceipt['product_id'] === $productId
            && strcasecmp($existingReceipt['batch_number'], $batchNumber) === 0
            && $existingReceipt['expires_on'] === $expiresOn
            && (int) $existingReceipt['quantity'] === $quantity
            && (int) $existingReceipt['purchase_cost_cents'] === $cost;
        $pdo->commit();
        if (!$sameReceipt) json_response(409, ['message' => 'This receipt key was already used for different batch data.']);
        json_response(200, ['message' => 'Receipt already recorded.', 'batch_id' => (int) $existingReceipt['batch_id'], 'replayed' => true]);
    }

    $batchQuery = $pdo->prepare(
        'SELECT id, quantity_received, available_quantity, purchase_cost_cents, status, supplier_id
         FROM medicine_batches
         WHERE business_id = :business_id AND product_id = :product_id AND batch_number = :batch_number AND expires_on = :expires_on',
    );
    $batchQuery->execute([
        'business_id' => (int) $user['business_id'],
        'product_id' => $productId,
        'batch_number' => $batchNumber,
        'expires_on' => $expiresOn,
    ]);
    $batch = $batchQuery->fetch();
    if ($batch !== false && $batch['status'] !== 'available') {
        $pdo->rollBack();
        json_response(409, ['message' => 'Stock cannot be added to a batch that is quarantined, recalled, damaged, or disposed.']);
    }
    if ($batch === false) {
        $insertBatch = $pdo->prepare(
            "INSERT INTO medicine_batches (
                business_id, product_id, batch_number, supplier_id, manufacturer, manufactured_on, expires_on,
                quantity_received, available_quantity, purchase_cost_cents, receipt_reference, created_by
             ) VALUES (
                :business_id, :product_id, :batch_number, :supplier_id, :manufacturer, :manufactured_on, :expires_on,
                :quantity_received, :available_quantity, :cost, :reference, :created_by
             )",
        );
        $insertBatch->execute([
            'business_id' => (int) $user['business_id'],
            'product_id' => $productId,
            'batch_number' => $batchNumber,
            'supplier_id' => $supplierId,
            'manufacturer' => $manufacturer,
            'manufactured_on' => $manufacturedOn,
            'expires_on' => $expiresOn,
            'quantity_received' => $quantity,
            'available_quantity' => $quantity,
            'cost' => $cost,
            'reference' => $reference,
            'created_by' => (int) $user['id'],
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $quantityBefore = 0;
    } else {
        if ($batch['supplier_id'] !== null && $supplierId !== null && (int) $batch['supplier_id'] !== $supplierId) {
            $pdo->rollBack();
            json_response(409, ['message' => 'The existing medicine batch is associated with a different supplier.']);
        }
        $quantityBefore = (int) $batch['available_quantity'];
        $receivedBefore = (int) $batch['quantity_received'];
        $costBefore = (int) $batch['purchase_cost_cents'];
        $weightedCost = (int) round((($receivedBefore * $costBefore) + ($quantity * $cost)) / ($receivedBefore + $quantity));
        $batchId = (int) $batch['id'];
        $updateBatch = $pdo->prepare(
            'UPDATE medicine_batches
             SET quantity_received = quantity_received + :received,
                 available_quantity = available_quantity + :available,
                 purchase_cost_cents = :cost, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND business_id = :business_id AND status = :status',
        );
        $updateBatch->execute([
            'received' => $quantity,
            'available' => $quantity,
            'cost' => $weightedCost,
            'id' => $batchId,
            'business_id' => (int) $user['business_id'],
            'status' => 'available',
        ]);
        if ($updateBatch->rowCount() !== 1) {
            $pdo->rollBack();
            json_response(409, ['message' => 'Batch status changed during receipt. Refresh and try again.']);
        }
    }

    $receiptInsert = $pdo->prepare(
        'INSERT INTO medicine_batch_receipts (business_id, batch_id, receipt_key, quantity, purchase_cost_cents, reference_number, created_by)
         VALUES (:business_id, :batch_id, :receipt_key, :quantity, :cost, :reference, :created_by)',
    );
    $receiptInsert->execute([
        'business_id' => (int) $user['business_id'],
        'batch_id' => $batchId,
        'receipt_key' => $receiptKey,
        'quantity' => $quantity,
        'cost' => $cost,
        'reference' => $reference,
        'created_by' => (int) $user['id'],
    ]);
    $receiptId = (int) $pdo->lastInsertId();

    $productStock = $pdo->prepare(
        'UPDATE products SET stock_quantity = stock_quantity + :quantity, updated_at = CURRENT_TIMESTAMP
         WHERE id = :product_id AND business_id = :business_id AND is_active = 1',
    );
    $productStock->execute(['quantity' => $quantity, 'product_id' => $productId, 'business_id' => (int) $user['business_id']]);
    if ($productStock->rowCount() !== 1) {
        $pdo->rollBack();
        json_response(409, ['message' => 'Medicine product is no longer active. No stock was received.']);
    }
    $movement = $pdo->prepare(
        "INSERT INTO inventory_movements (business_id, product_id, user_id, movement_type, quantity_change, notes)
         VALUES (:business_id, :product_id, :user_id, 'adjustment', :quantity, :notes)",
    );
    $movement->execute([
        'business_id' => (int) $user['business_id'],
        'product_id' => $productId,
        'user_id' => (int) $user['id'],
        'quantity' => $quantity,
        'notes' => 'Pharmacy batch receipt: ' . $batchNumber,
    ]);
    $movementId = (int) $pdo->lastInsertId();
    $inventoryDetail = $pdo->prepare(
        "INSERT INTO inventory_movement_details (
            business_id, movement_id, transaction_type, quantity_before, quantity_after,
            reason, reference_number, supplier_id
         ) VALUES (:business_id, :movement_id, 'stock_in', :before, :after, 'Pharmacy batch receipt', :reference, :supplier_id)",
    );
    $inventoryDetail->execute([
        'business_id' => (int) $user['business_id'],
        'movement_id' => $movementId,
        'before' => $productStockBefore,
        'after' => $productStockBefore + $quantity,
        'reference' => $reference,
        'supplier_id' => $supplierId,
    ]);
    $batchMovement = $pdo->prepare(
        "INSERT INTO medicine_batch_movements (
            business_id, batch_id, user_id, movement_type, quantity_change,
            quantity_before, quantity_after, reference_type, reference_id, reason
         ) VALUES (:business_id, :batch_id, :user_id, 'receipt', :change, :before, :after, 'receipt', :reference_id, :reason)",
    );
    $batchMovement->execute([
        'business_id' => (int) $user['business_id'],
        'batch_id' => $batchId,
        'user_id' => (int) $user['id'],
        'change' => $quantity,
        'before' => $quantityBefore,
        'after' => $quantityBefore + $quantity,
        'reference_id' => $receiptId,
        'reason' => $reference,
    ]);
    $audit = $pdo->prepare(
        "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id, details)
         VALUES (:business_id, :actor_id, 'pharmacy_batch_received', 'medicine_batch', :entity_id, :details)",
    );
    $audit->execute([
        'business_id' => (int) $user['business_id'],
        'actor_id' => (int) $user['id'],
        'entity_id' => $batchId,
        'details' => json_encode(['product_id' => $productId, 'batch_number' => $batchNumber, 'quantity' => $quantity, 'expires_on' => $expiresOn], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode() === '23000') json_response(409, ['message' => 'This batch or receipt identifier is already in use.']);
    throw $error;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

json_response(201, ['message' => 'Medicine batch received.', 'batch_id' => $batchId, 'quantity' => $quantity]);
