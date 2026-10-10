<?php
declare(strict_types=1);

require_method('POST');
$pdo = database_connection();
$user = require_pos_enabled_user($pdo);
$input = request_json();
$idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
if (!is_string($idempotencyKey) || !preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $idempotencyKey)) {
    json_response(422, ['message' => 'A valid Idempotency-Key header is required.']);
}

$itemsInput = $input['items'] ?? null;
$paymentMethod = $input['payment_method'] ?? null;
$discountBpsInput = $input['discount_bps'] ?? 0;
$amountReceivedInput = $input['amount_received_cents'] ?? null;
$customerNameInput = $input['customer_name'] ?? null;
if (!is_array($itemsInput) || count($itemsInput) < 1 || count($itemsInput) > 100) {
    json_response(422, ['message' => 'Add between 1 and 100 distinct products to the cart.', 'errors' => ['items' => 'The cart is empty or too large.']]);
}
if (!is_string($paymentMethod) || !in_array($paymentMethod, ['cash', 'card', 'ewallet'], true)) {
    json_response(422, ['message' => 'Choose Cash, Card, or E-wallet.', 'errors' => ['payment_method' => 'Choose a valid payment method.']]);
}
if (!is_string($customerNameInput ?? '') || mb_strlen($customerNameInput ?? '', 'UTF-8') > 120) {
    json_response(422, ['message' => 'Customer name must be 120 characters or fewer.']);
}
$customerName = trim($customerNameInput ?? '');

$discountBps = pos_int($discountBpsInput, 0, 10000);
$maxDiscountBps = in_array($user['role'], ['owner', 'admin', 'manager'], true)
    ? 10000
    : filter_var(getenv('POS_CASHIER_MAX_DISCOUNT_BPS') ?: '500', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000]]);
if ($discountBps === null) json_response(422, ['message' => 'Discount must be between 0 and 100 percent.']);
if ($maxDiscountBps === false) throw new RuntimeException('Invalid cashier discount configuration.');
if ($discountBps > $maxDiscountBps) {
    json_response(403, ['message' => 'This role is not authorized for that discount.']);
}

$amountReceived = null;
if ($paymentMethod === 'cash') {
    $amountReceived = pos_int($amountReceivedInput, 0, PHP_INT_MAX);
    if ($amountReceived === null) {
        json_response(422, ['message' => 'Enter the cash received in cents.', 'errors' => ['amount_received_cents' => 'Enter a valid cash amount.']]);
    }
} elseif ($amountReceivedInput !== null) {
    json_response(422, ['message' => 'Amount received is only used for cash payments.']);
}

$quantities = [];
foreach ($itemsInput as $line) {
    $lineData = is_object($line) ? get_object_vars($line) : (is_array($line) ? $line : []);
    $productId = pos_int($lineData['product_id'] ?? null, 1, PHP_INT_MAX);
    $quantity = pos_int($lineData['quantity'] ?? null, 1, 999);
    if ($productId === null || $quantity === null) {
        json_response(422, ['message' => 'Each cart line must have a product ID and quantity from 1 to 999.']);
    }
    $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
    if ($quantities[$productId] > 999) json_response(422, ['message' => 'A product quantity cannot exceed 999.']);
}
ksort($quantities, SORT_NUMERIC);
$requestHash = hash('sha256', json_encode([
    'cashier_id' => (int) $user['id'],
    'items' => $quantities,
    'payment_method' => $paymentMethod,
    'discount_bps' => $discountBps,
    'amount_received_cents' => $amountReceived,
    'customer_name' => $customerName,
], JSON_THROW_ON_ERROR));

$pdo->beginTransaction();
try {
    $existingQuery = $pdo->prepare('SELECT id, cashier_id, idempotency_hash FROM sales WHERE business_id = :business_id AND idempotency_key = :key');
    $existingQuery->execute(['key' => $idempotencyKey, 'business_id' => $user['business_id']]);
    $existing = $existingQuery->fetch();
    if ($existing !== false) {
        $pdo->commit();
        if ((int) $existing['cashier_id'] !== (int) $user['id'] || !hash_equals($existing['idempotency_hash'], $requestHash)) {
            json_response(409, ['message' => 'This idempotency key was already used for a different checkout.']);
        }
        json_response(200, ['sale' => pos_sale($pdo, (int) $existing['id'], $user['business_id']), 'replayed' => true]);
    }

    $products = [];
    $subtotal = 0;
    $isPharmacy = $user['business']['industry'] === 'pharmacy';
    $productQuery = $pdo->prepare(
        'SELECT products.id, products.name, products.price_cents, products.stock_quantity,
                pharmacy_product_details.prescription_required, pharmacy_product_details.batch_tracking
         FROM products
         LEFT JOIN pharmacy_product_details
           ON pharmacy_product_details.product_id = products.id AND pharmacy_product_details.business_id = products.business_id
         WHERE products.id = :id AND products.business_id = :business_id AND products.is_active = 1',
    );
    foreach ($quantities as $productId => $quantity) {
        $productQuery->execute(['id' => $productId, 'business_id' => $user['business_id']]);
        $product = $productQuery->fetch();
        if ($product === false) json_response(409, ['message' => 'A cart product is no longer available. Refresh the product list.']);
        if ($isPharmacy && $product['prescription_required'] === null) {
            json_response(409, ['message' => $product['name'] . ' needs pharmacy medicine details before it can be sold.']);
        }
        if ($isPharmacy && (int) $product['prescription_required'] === 1) {
            json_response(403, ['message' => 'Prescription-required medicines must be dispensed through the authorized prescription workflow.']);
        }
        if ($isPharmacy && (int) $product['batch_tracking'] !== 1) {
            json_response(409, ['message' => $product['name'] . ' must use batch tracking before pharmacy checkout.']);
        }
        if ((int) $product['stock_quantity'] < $quantity) {
            json_response(409, ['message' => $product['name'] . ' does not have enough stock.']);
        }
        $unitPrice = (int) $product['price_cents'];
        if ($unitPrice > intdiv(PHP_INT_MAX, $quantity)) json_response(422, ['message' => 'Cart total is too large.']);
        $lineTotal = $unitPrice * $quantity;
        if ($subtotal > PHP_INT_MAX - $lineTotal) json_response(422, ['message' => 'Cart total is too large.']);
        $subtotal += $lineTotal;
        $product['unit_price_cents'] = $unitPrice;
        $product['quantity'] = $quantity;
        $product['line_total_cents'] = $lineTotal;
        $products[] = $product;
    }

    $discountCents = intdiv(($subtotal * $discountBps) + 5000, 10000);
    $taxBps = filter_var(getenv('POS_TAX_BPS') ?: '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000]]);
    if ($taxBps === false) throw new RuntimeException('Invalid POS tax configuration.');
    $taxable = $subtotal - $discountCents;
    $taxCents = intdiv(($taxable * $taxBps) + 5000, 10000);
    $total = $taxable + $taxCents;
    if ($paymentMethod === 'cash' && $amountReceived < $total) {
        json_response(422, ['message' => 'Cash received is less than the amount due.', 'errors' => ['amount_received_cents' => 'Insufficient cash received.']]);
    }

    $paymentStatus = $paymentMethod === 'cash' ? 'paid' : 'pending';
    $change = $paymentMethod === 'cash' ? $amountReceived - $total : 0;
    $transactionNumber = 'POS-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $saleInsert = $pdo->prepare(
        'INSERT INTO sales (
            business_id, transaction_number, idempotency_key, idempotency_hash, cashier_id, customer_name,
            subtotal_cents, discount_cents, tax_cents, total_cents, payment_method,
            amount_received_cents, change_cents, payment_status
         ) VALUES (
            :business_id, :transaction_number, :idempotency_key, :idempotency_hash, :cashier_id, :customer_name,
            :subtotal_cents, :discount_cents, :tax_cents, :total_cents, :payment_method,
            :amount_received_cents, :change_cents, :payment_status
         )',
    );
    $saleInsert->execute([
        'business_id' => $user['business_id'],
        'transaction_number' => $transactionNumber,
        'idempotency_key' => $idempotencyKey,
        'idempotency_hash' => $requestHash,
        'cashier_id' => $user['id'],
        'customer_name' => $customerName === '' ? null : $customerName,
        'subtotal_cents' => $subtotal,
        'discount_cents' => $discountCents,
        'tax_cents' => $taxCents,
        'total_cents' => $total,
        'payment_method' => $paymentMethod,
        'amount_received_cents' => $amountReceived,
        'change_cents' => $change,
        'payment_status' => $paymentStatus,
    ]);
    $saleId = (int) $pdo->lastInsertId();
    $itemInsert = $pdo->prepare(
        'INSERT INTO sale_items (business_id, sale_id, product_id, product_name, unit_price_cents, quantity, line_total_cents)
         VALUES (:business_id, :sale_id, :product_id, :product_name, :unit_price_cents, :quantity, :line_total_cents)',
    );
    $stockUpdate = $pdo->prepare(
        'UPDATE products SET stock_quantity = stock_quantity - :quantity, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND business_id = :business_id AND is_active = 1 AND stock_quantity >= :required_quantity',
    );
    $movementInsert = $pdo->prepare(
        "INSERT INTO inventory_movements (business_id, product_id, user_id, movement_type, quantity_change, reference_sale_id, notes)
         VALUES (:business_id, :product_id, :user_id, 'sale', :quantity_change, :sale_id, :notes)",
    );
    $detailInsert = $pdo->prepare(
        "INSERT INTO inventory_movement_details
         (business_id, movement_id, transaction_type, quantity_before, quantity_after, reason, reference_number)
         VALUES (:business_id, :movement_id, 'stock_out', :quantity_before, :quantity_after, :reason, :reference_number)",
    );
    $localToday = (new DateTimeImmutable('now', new DateTimeZone($user['business']['timezone'])))->format('Y-m-d');
    $fefoQuery = $isPharmacy ? $pdo->prepare(
        "SELECT id, batch_number, expires_on, available_quantity
         FROM medicine_batches
         WHERE business_id = :business_id AND product_id = :product_id
           AND status = 'available' AND expires_on > :today AND available_quantity > 0
         ORDER BY expires_on ASC, id ASC",
    ) : null;
    $batchUpdate = $isPharmacy ? $pdo->prepare(
        "UPDATE medicine_batches SET available_quantity = available_quantity - :quantity, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND business_id = :business_id AND status = 'available'
           AND expires_on > :today AND available_quantity >= :required",
    ) : null;
    $batchAllocationInsert = $isPharmacy ? $pdo->prepare(
        'INSERT INTO medicine_batch_allocations (business_id, sale_id, sale_item_id, batch_id, quantity)
         VALUES (:business_id, :sale_id, :sale_item_id, :batch_id, :quantity)',
    ) : null;
    $batchMovementInsert = $isPharmacy ? $pdo->prepare(
        "INSERT INTO medicine_batch_movements (
            business_id, batch_id, user_id, movement_type, quantity_change,
            quantity_before, quantity_after, reference_type, reference_id, reason
         ) VALUES (:business_id, :batch_id, :user_id, 'sale', :change, :before, :after, 'sale_item', :reference_id, :reason)",
    ) : null;
    foreach ($products as $product) {
        $quantityBefore = (int) $product['stock_quantity'];
        $quantityAfter = $quantityBefore - (int) $product['quantity'];
        $stockUpdate->execute([
            'quantity' => $product['quantity'],
            'id' => $product['id'],
            'business_id' => $user['business_id'],
            'required_quantity' => $product['quantity'],
        ]);
        if ($stockUpdate->rowCount() !== 1) json_response(409, ['message' => 'Stock changed during checkout. Please retry the sale.']);
        $itemInsert->execute([
            'business_id' => $user['business_id'],
            'sale_id' => $saleId,
            'product_id' => $product['id'],
            'product_name' => $product['name'],
            'unit_price_cents' => $product['unit_price_cents'],
            'quantity' => $product['quantity'],
            'line_total_cents' => $product['line_total_cents'],
        ]);
        $saleItemId = (int) $pdo->lastInsertId();
        if ($isPharmacy) {
            $fefoQuery->execute([
                'business_id' => (int) $user['business_id'],
                'product_id' => (int) $product['id'],
                'today' => $localToday,
            ]);
            $remaining = (int) $product['quantity'];
            foreach ($fefoQuery->fetchAll() as $batch) {
                if ($remaining === 0) break;
                $batchAvailable = (int) $batch['available_quantity'];
                $allocated = min($remaining, $batchAvailable);
                $batchUpdate->execute([
                    'quantity' => $allocated,
                    'id' => (int) $batch['id'],
                    'business_id' => (int) $user['business_id'],
                    'today' => $localToday,
                    'required' => $allocated,
                ]);
                if ($batchUpdate->rowCount() !== 1) {
                    json_response(409, ['message' => 'Eligible batch stock changed during checkout. Retry the sale.']);
                }
                $batchAllocationInsert->execute([
                    'business_id' => (int) $user['business_id'],
                    'sale_id' => $saleId,
                    'sale_item_id' => $saleItemId,
                    'batch_id' => (int) $batch['id'],
                    'quantity' => $allocated,
                ]);
                $batchMovementInsert->execute([
                    'business_id' => (int) $user['business_id'],
                    'batch_id' => (int) $batch['id'],
                    'user_id' => (int) $user['id'],
                    'change' => -$allocated,
                    'before' => $batchAvailable,
                    'after' => $batchAvailable - $allocated,
                    'reference_id' => $saleItemId,
                    'reason' => 'POS transaction ' . $transactionNumber . '; batch ' . $batch['batch_number'] . ' expires ' . $batch['expires_on'],
                ]);
                $remaining -= $allocated;
            }
            $fefoQuery->closeCursor();
            if ($remaining !== 0) json_response(409, ['message' => $product['name'] . ' does not have enough unexpired, eligible batch stock.']);
        }
        $movementInsert->execute([
            'business_id' => $user['business_id'],
            'product_id' => $product['id'],
            'user_id' => $user['id'],
            'quantity_change' => -$product['quantity'],
            'sale_id' => $saleId,
            'notes' => 'POS transaction ' . $transactionNumber,
        ]);
        $detailInsert->execute([
            'business_id' => $user['business_id'],
            'movement_id' => (int) $pdo->lastInsertId(),
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'reason' => 'POS sale',
            'reference_number' => $transactionNumber,
        ]);
    }
    $audit = $pdo->prepare("INSERT INTO sale_audit_log (business_id, sale_id, user_id, action) VALUES (:business_id, :sale_id, :user_id, 'created')");
    $audit->execute(['business_id' => $user['business_id'], 'sale_id' => $saleId, 'user_id' => $user['id']]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode() === '23000') {
        $existingQuery = $pdo->prepare('SELECT id, idempotency_hash FROM sales WHERE business_id = :business_id AND idempotency_key = :key');
        $existingQuery->execute(['key' => $idempotencyKey, 'business_id' => $user['business_id']]);
        $existing = $existingQuery->fetch();
        if ($existing !== false && hash_equals($existing['idempotency_hash'], $requestHash)) {
            json_response(200, ['sale' => pos_sale($pdo, (int) $existing['id'], $user['business_id']), 'replayed' => true]);
        }
        json_response(409, ['message' => 'This idempotency key has already been used.']);
    }
    throw $error;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

$sale = pos_sale($pdo, $saleId, $user['business_id']);
json_response(201, [
    'message' => $paymentStatus === 'paid'
        ? 'Cash sale completed.'
        : 'Order recorded as pending; no external payment provider confirmed this payment.',
    'sale' => $sale,
    'replayed' => false,
]);