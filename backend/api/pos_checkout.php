<?php
declare(strict_types=1);

require_method('POST');
$pdo = database_connection();
$user = require_pos_operator($pdo);
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
$maxDiscountBps = $user['role'] === 'admin'
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
    'items' => $quantities,
    'payment_method' => $paymentMethod,
    'discount_bps' => $discountBps,
    'amount_received_cents' => $amountReceived,
    'customer_name' => $customerName,
], JSON_THROW_ON_ERROR));

$pdo->beginTransaction();
try {
    $existingQuery = $pdo->prepare('SELECT id, idempotency_hash FROM sales WHERE idempotency_key = :key');
    $existingQuery->execute(['key' => $idempotencyKey]);
    $existing = $existingQuery->fetch();
    if ($existing !== false) {
        $pdo->commit();
        if (!hash_equals($existing['idempotency_hash'], $requestHash)) {
            json_response(409, ['message' => 'This idempotency key was already used for a different checkout.']);
        }
        json_response(200, ['sale' => pos_sale($pdo, (int) $existing['id']), 'replayed' => true]);
    }

    $products = [];
    $subtotal = 0;
    $productQuery = $pdo->prepare(
        'SELECT id, name, price_cents, stock_quantity FROM products WHERE id = :id AND is_active = 1',
    );
    foreach ($quantities as $productId => $quantity) {
        $productQuery->execute(['id' => $productId]);
        $product = $productQuery->fetch();
        if ($product === false) json_response(409, ['message' => 'A cart product is no longer available. Refresh the product list.']);
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
            transaction_number, idempotency_key, idempotency_hash, cashier_id, customer_name,
            subtotal_cents, discount_cents, tax_cents, total_cents, payment_method,
            amount_received_cents, change_cents, payment_status
         ) VALUES (
            :transaction_number, :idempotency_key, :idempotency_hash, :cashier_id, :customer_name,
            :subtotal_cents, :discount_cents, :tax_cents, :total_cents, :payment_method,
            :amount_received_cents, :change_cents, :payment_status
         )',
    );
    $saleInsert->execute([
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
        'INSERT INTO sale_items (sale_id, product_id, product_name, unit_price_cents, quantity, line_total_cents)
         VALUES (:sale_id, :product_id, :product_name, :unit_price_cents, :quantity, :line_total_cents)',
    );
    $stockUpdate = $pdo->prepare(
        'UPDATE products SET stock_quantity = stock_quantity - :quantity, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND is_active = 1 AND stock_quantity >= :required_quantity',
    );
    $movementInsert = $pdo->prepare(
        "INSERT INTO inventory_movements (product_id, user_id, movement_type, quantity_change, reference_sale_id, notes)
         VALUES (:product_id, :user_id, 'sale', :quantity_change, :sale_id, :notes)",
    );
    foreach ($products as $product) {
        $stockUpdate->execute([
            'quantity' => $product['quantity'],
            'id' => $product['id'],
            'required_quantity' => $product['quantity'],
        ]);
        if ($stockUpdate->rowCount() !== 1) json_response(409, ['message' => 'Stock changed during checkout. Please retry the sale.']);
        $itemInsert->execute([
            'sale_id' => $saleId,
            'product_id' => $product['id'],
            'product_name' => $product['name'],
            'unit_price_cents' => $product['unit_price_cents'],
            'quantity' => $product['quantity'],
            'line_total_cents' => $product['line_total_cents'],
        ]);
        $movementInsert->execute([
            'product_id' => $product['id'],
            'user_id' => $user['id'],
            'quantity_change' => -$product['quantity'],
            'sale_id' => $saleId,
            'notes' => 'POS transaction ' . $transactionNumber,
        ]);
    }
    $audit = $pdo->prepare("INSERT INTO sale_audit_log (sale_id, user_id, action) VALUES (:sale_id, :user_id, 'created')");
    $audit->execute(['sale_id' => $saleId, 'user_id' => $user['id']]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode() === '23000') {
        $existingQuery = $pdo->prepare('SELECT id, idempotency_hash FROM sales WHERE idempotency_key = :key');
        $existingQuery->execute(['key' => $idempotencyKey]);
        $existing = $existingQuery->fetch();
        if ($existing !== false && hash_equals($existing['idempotency_hash'], $requestHash)) {
            json_response(200, ['sale' => pos_sale($pdo, (int) $existing['id']), 'replayed' => true]);
        }
        json_response(409, ['message' => 'This idempotency key has already been used.']);
    }
    throw $error;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

$sale = pos_sale($pdo, $saleId);
json_response(201, [
    'message' => $paymentStatus === 'paid'
        ? 'Cash sale completed.'
        : 'Order recorded as pending; no external payment provider confirmed this payment.',
    'sale' => $sale,
    'replayed' => false,
]);