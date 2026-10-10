<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$baseUrl = rtrim(getenv('AUTH_TEST_BASE_URL') ?: 'http://localhost:8000', '/');
$pdo = database_connection();
$cookieJar = tempnam(sys_get_temp_dir(), 'northstar-pos-test-');
if ($cookieJar === false) {
    throw new RuntimeException('Unable to create a temporary cookie jar.');
}
$email = 'pos-test-' . bin2hex(random_bytes(8)) . '@example.test';
$password = bin2hex(random_bytes(24));
$account = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES ('POS Test Admin', :email, :password, 'admin')");
$account->execute(['email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();
$business = $pdo->prepare("INSERT INTO businesses (name, industry) VALUES (:name, 'retail')");
$business->execute(['name' => 'POS Test ' . bin2hex(random_bytes(4))]);
$businessId = (int) $pdo->lastInsertId();
$member = $pdo->prepare("INSERT INTO business_members (business_id, user_id, role) VALUES (:business_id, :user_id, 'owner')");
$member->execute(['business_id' => $businessId, 'user_id' => $userId]);

function pos_api_request(string $baseUrl, string $cookieJar, string $method, string $path, ?array $body = null, ?string $csrfToken = null, ?string $idempotencyKey = null, ?int $businessId = null): array
{
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($csrfToken !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrfToken;
    }
    if ($idempotencyKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }
    if ($businessId !== null) {
        $headers[] = 'X-Business-Id: ' . $businessId;
    }

    $curl = curl_init($baseUrl . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $response = curl_exec($curl);
    if ($response === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Could not reach the API: ' . $message);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['status' => $status, 'body' => json_decode($response, true) ?? []];
}

function pos_expect(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . PHP_EOL;
}

function pos_stock_quantity(PDO $pdo, int $productId): int
{
    $query = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id');
    $query->execute(['id' => $productId]);
    $quantity = (int) $query->fetchColumn();
    $query->closeCursor();
    return $quantity;
}

try {
    $csrf = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/csrf');
    pos_expect($csrf['status'] === 200 && isset($csrf['body']['csrfToken']), 'start a POS test session');
    $login = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/login', [
        'email' => $email,
        'password' => $password,
    ], $csrf['body']['csrfToken']);
    pos_expect($login['status'] === 200, 'authenticate the POS test administrator');
    $csrfToken = $login['body']['csrfToken'];

    $category = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/categories', ['name' => 'POS Test ' . bin2hex(random_bytes(3))], $csrfToken);
    pos_expect($category['status'] === 201, 'create a category through the API');
    $categoryId = (int) $category['body']['category']['id'];
    $productSku = 'TEST-' . strtoupper(bin2hex(random_bytes(4)));
    $productName = 'POS test product ' . bin2hex(random_bytes(3));
    $product = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/products', [
        'category_id' => $categoryId,
        'sku' => $productSku,
        'barcode' => '98' . random_int(100000000, 999999999),
        'name' => $productName,
        'price_cents' => 2500,
        'stock_quantity' => 5,
    ], $csrfToken);
    pos_expect($product['status'] === 201, 'create a test product with stock');
    $productId = (int) $product['body']['id'];
    $movement = $pdo->prepare("SELECT quantity_change FROM inventory_movements WHERE product_id = :id AND movement_type = 'adjustment' ORDER BY id LIMIT 1");
    $movement->execute(['id' => $productId]);
    $initialMovement = (int) $movement->fetchColumn();
    $movement->closeCursor();
    pos_expect($initialMovement === 5, 'record initial product stock in inventory movements');
    $openingDetail = $pdo->prepare('SELECT transaction_type, quantity_before, quantity_after FROM inventory_movement_details WHERE movement_id = (SELECT id FROM inventory_movements WHERE product_id = :id ORDER BY id LIMIT 1)');
    $openingDetail->execute(['id' => $productId]);
    $openingBalance = $openingDetail->fetch();
    $openingDetail->closeCursor();
    pos_expect($openingBalance['transaction_type'] === 'opening_balance' && (int) $openingBalance['quantity_before'] === 0
        && (int) $openingBalance['quantity_after'] === 5, 'record opening balance quantity snapshots');

    $idempotencyKey = 'pos-test:' . bin2hex(random_bytes(16));
    $empty = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/checkout', [
        'items' => [],
        'payment_method' => 'cash',
        'amount_received_cents' => 0,
    ], $csrfToken, $idempotencyKey);
    pos_expect($empty['status'] === 422, 'reject empty checkout carts');

    $cashRequest = [
        'items' => [['product_id' => $productId, 'quantity' => 1]],
        'payment_method' => 'cash',
        'amount_received_cents' => 1,
        'discount_bps' => 0,
    ];
    $insufficient = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/checkout', $cashRequest, $csrfToken, 'pos-test:' . bin2hex(random_bytes(16)));
    pos_expect($insufficient['status'] === 422, 'reject insufficient cash before writing a sale');
    $oversell = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/checkout', [
        'items' => [['product_id' => $productId, 'quantity' => 6]],
        'payment_method' => 'card',
        'discount_bps' => 0,
    ], $csrfToken, 'pos-test:' . bin2hex(random_bytes(16)));
    pos_expect($oversell['status'] === 409, 'reject checkout quantities above available stock');
    pos_expect(pos_stock_quantity($pdo, $productId) === 5, 'failed checkout attempts leave inventory unchanged');

    $pendingKey = 'pos-test:' . bin2hex(random_bytes(16));
    $pendingRequest = [
        'items' => [['product_id' => $productId, 'quantity' => 2]],
        'payment_method' => 'card',
        'discount_bps' => 0,
    ];
    $pending = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/checkout', $pendingRequest, $csrfToken, $pendingKey);
    if ($pending['status'] !== 201) {
        fwrite(STDERR, 'Card checkout response: ' . json_encode($pending, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    pos_expect($pending['status'] === 201 && $pending['body']['sale']['payment_status'] === 'pending', 'record card checkout as pending without a provider');
    $saleId = (int) $pending['body']['sale']['id'];
    $saleSnapshot = $pdo->prepare(
        "SELECT details.quantity_before, details.quantity_after
         FROM inventory_movement_details details
         JOIN inventory_movements movements ON movements.id = details.movement_id
         WHERE movements.reference_sale_id = :sale_id AND movements.movement_type = 'sale'",
    );
    $saleSnapshot->execute(['sale_id' => $saleId]);
    $saleQuantities = $saleSnapshot->fetch();
    $saleSnapshot->closeCursor();
    pos_expect((int) $saleQuantities['quantity_before'] === 5 && (int) $saleQuantities['quantity_after'] === 3, 'record POS sale stock snapshots');
    $replay = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/checkout', $pendingRequest, $csrfToken, $pendingKey);
    pos_expect($replay['status'] === 200 && $replay['body']['replayed'] === true && (int) $replay['body']['sale']['id'] === $saleId, 'replay duplicate submissions as the original sale');
    $conflict = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/checkout', [
        'items' => [['product_id' => $productId, 'quantity' => 1]],
        'payment_method' => 'card',
        'discount_bps' => 0,
    ], $csrfToken, $pendingKey);
    pos_expect($conflict['status'] === 409, 'reject reuse of an idempotency key for different checkout data');
    pos_expect(pos_stock_quantity($pdo, $productId) === 3, 'successful pending sale decrements stock exactly once');

    $details = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/pos/transactions/' . $saleId);
    pos_expect($details['status'] === 200 && $details['body']['sale']['items'][0]['product_name'] === $productName, 'return transaction details with product snapshots');
    $cancelled = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/transactions/' . $saleId . '/cancel', ['reason' => 'POS integration test'], $csrfToken);
    pos_expect($cancelled['status'] === 200 && $cancelled['body']['sale']['payment_status'] === 'cancelled', 'cancel pending sales with an audit record');
    pos_expect(pos_stock_quantity($pdo, $productId) === 5, 'pending sale cancellation restores stock once');

    $supplier = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/suppliers', [
        'name' => 'Inventory Test Supplier ' . bin2hex(random_bytes(3)),
        'contact_person' => 'Test Contact',
        'email' => 'supplier-' . bin2hex(random_bytes(4)) . '@example.test',
        'phone' => '555-0100',
        'address' => 'Test address',
        'notes' => 'Integration test supplier',
    ], $csrfToken);
    pos_expect($supplier['status'] === 201, 'create and audit a supplier');
    $supplierId = (int) $supplier['body']['id'];
    $updateProduct = pos_api_request($baseUrl, $cookieJar, 'PUT', '/api/products/' . $productId, [
        'category_id' => $categoryId,
        'supplier_id' => $supplierId,
        'sku' => strtolower($productSku),
        'name' => $productName,
        'price_cents' => 2500,
        'cost_price_cents' => 1200,
        'reorder_level' => 6,
    ], $csrfToken);
    if ($updateProduct['status'] !== 200) {
        fwrite(STDERR, 'Product update response: ' . json_encode($updateProduct, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    pos_expect($updateProduct['status'] === 200 && pos_stock_quantity($pdo, $productId) === 5, 'update product details without changing current stock');
    $blockedStockEdit = pos_api_request($baseUrl, $cookieJar, 'PUT', '/api/products/' . $productId, [
        'sku' => 'TEST-BLOCKED',
        'name' => 'POS test product',
        'price_cents' => 2500,
        'stock_quantity' => 50,
    ], $csrfToken);
    pos_expect($blockedStockEdit['status'] === 422 && pos_stock_quantity($pdo, $productId) === 5, 'reject stock changes submitted through product editing');
    $receive = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/inventory/operations', [
        'product_id' => $productId,
        'transaction_type' => 'stock_in',
        'quantity' => 3,
        'supplier_id' => $supplierId,
        'reference_number' => 'PO-TEST-01',
        'reason' => 'Integration test delivery',
    ], $csrfToken);
    pos_expect($receive['status'] === 201 && $receive['body']['transaction']['quantity_after'] === 8, 'receive stock with atomic before/after history');
    pos_expect(pos_stock_quantity($pdo, $productId) === 8, 'persist received stock quantity');
    $overshoot = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/inventory/operations', [
        'product_id' => $productId,
        'transaction_type' => 'stock_out',
        'quantity' => 9,
        'reason' => 'Insufficient stock test',
    ], $csrfToken);
    pos_expect($overshoot['status'] === 409 && pos_stock_quantity($pdo, $productId) === 8, 'reject stock issues that would make inventory negative');
    $issue = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/inventory/operations', [
        'product_id' => $productId,
        'transaction_type' => 'stock_out',
        'quantity' => 2,
        'reference_number' => 'ISSUE-TEST-01',
        'reason' => 'Damaged items',
    ], $csrfToken);
    pos_expect($issue['status'] === 201 && $issue['body']['transaction']['quantity_before'] === 8 && $issue['body']['transaction']['quantity_after'] === 6, 'issue stock and record correct quantity snapshots');
    $inventory = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/inventory/products?stock_status=low_stock&search=' . rawurlencode($productSku));
    pos_expect($inventory['status'] === 200 && count($inventory['body']['products']) === 1
        && $inventory['body']['products'][0]['stock_status'] === 'low_stock'
        && $inventory['body']['products'][0]['inventory_value_cents'] === 7200, 'filter low stock and calculate inventory valuation');
    $inventorySummary = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/inventory/summary');
    pos_expect($inventorySummary['status'] === 200 && $inventorySummary['body']['summary']['low_stock'] >= 1, 'load database-backed inventory summary');
    $history = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/inventory/history?type=stock_out&search=' . rawurlencode($productSku));
    if ($history['status'] !== 200 || count($history['body']['transactions'] ?? []) === 0
        || ($history['body']['transactions'][0]['quantity_after'] ?? null) !== 6) {
        fwrite(STDERR, 'Inventory history response: ' . json_encode($history, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    pos_expect($history['status'] === 200 && count($history['body']['transactions']) >= 1
        && $history['body']['transactions'][0]['quantity_before'] === 8
        && $history['body']['transactions'][0]['quantity_after'] === 6
        && $history['body']['transactions'][0]['reference_number'] === 'ISSUE-TEST-01', 'search and filter append-only inventory history');
    $historyMovementId = (int) $history['body']['transactions'][0]['id'];
    try {
        $tamper = $pdo->prepare('UPDATE inventory_movement_details SET reason = :reason WHERE movement_id = :id');
        $tamper->execute(['reason' => 'tampered', 'id' => $historyMovementId]);
        pos_expect(false, 'block edits to inventory history');
    } catch (PDOException) {
        pos_expect(true, 'block edits to inventory history');
    }
    $inactiveSupplier = pos_api_request($baseUrl, $cookieJar, 'DELETE', '/api/suppliers/' . $supplierId, null, $csrfToken);
    $supplierRestore = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/suppliers/' . $supplierId . '/restore', ['restore' => true], $csrfToken);
    if ($inactiveSupplier['status'] !== 200 || $supplierRestore['status'] !== 200) {
        fwrite(STDERR, 'Supplier restore responses: ' . json_encode([$inactiveSupplier, $supplierRestore], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    pos_expect($inactiveSupplier['status'] === 200 && $supplierRestore['status'] === 200, 'deactivate and reactivate suppliers without deleting history');

    $promoteCashier = $pdo->prepare("UPDATE business_members SET role = 'cashier' WHERE user_id = (SELECT id FROM users WHERE email = :email) AND business_id = :business_id");
    $promoteCashier->execute(['email' => $email, 'business_id' => $businessId]);
    $cashierInventory = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/inventory/summary');
    $unauthorizedDiscount = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/checkout', [
        'items' => [['product_id' => $productId, 'quantity' => 1]],
        'payment_method' => 'cash',
        'amount_received_cents' => 100000000,
        'discount_bps' => 10000,
    ], $csrfToken, 'pos-test:' . bin2hex(random_bytes(16)));
    $restoreAdmin = $pdo->prepare("UPDATE business_members SET role = 'owner' WHERE user_id = (SELECT id FROM users WHERE email = :email) AND business_id = :business_id");
    $restoreAdmin->execute(['email' => $email, 'business_id' => $businessId]);
    pos_expect($cashierInventory['status'] === 403, 'deny cashier access to administrator inventory endpoints');
    pos_expect($unauthorizedDiscount['status'] === 403, 'enforce cashier discount authorization on the server');

    $paidKey = 'pos-test:' . bin2hex(random_bytes(16));
    $paid = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/checkout', [
        'items' => [['product_id' => $productId, 'quantity' => 1]],
        'payment_method' => 'cash',
        'amount_received_cents' => 100000000,
        'discount_bps' => 0,
    ], $csrfToken, $paidKey);
    pos_expect($paid['status'] === 201 && $paid['body']['sale']['payment_status'] === 'paid', 'mark cash checkout paid only after sufficient cash is received');
    $businessToday = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $retailDashboard = pos_api_request(
        $baseUrl,
        $cookieJar,
        'GET',
        '/api/retail/dashboard?date_from=' . $businessToday . '&date_to=' . $businessToday . '&group_by=day',
    );
    pos_expect($retailDashboard['status'] === 200, 'load the retail dashboard for the active business');
    pos_expect($retailDashboard['body']['today']['transactions'] === 1
        && $retailDashboard['body']['today']['gross_sales_cents'] === $paid['body']['sale']['subtotal_cents']
        && $retailDashboard['body']['today']['net_sales_cents'] === $paid['body']['sale']['subtotal_cents'] - $paid['body']['sale']['discount_cents'],
        'calculate today gross sales, net sales, and transaction totals from paid sales');
    pos_expect($retailDashboard['body']['sales_by_period'][0]['sales_cents'] === $paid['body']['sale']['subtotal_cents'] - $paid['body']['sale']['discount_cents']
        && $retailDashboard['body']['top_products'][0]['id'] === $productId
        && $retailDashboard['body']['top_products'][0]['units_sold'] === 1,
        'calculate date-filtered sales trend and top products from actual sale items');
    pos_expect($retailDashboard['body']['inventory']['in_stock'] + $retailDashboard['body']['inventory']['low_stock']
        + $retailDashboard['body']['inventory']['out_of_stock'] === $retailDashboard['body']['inventory']['active_products'],
        'keep inventory stock-status chart categories mutually exclusive');
    $invalidRetailDate = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/retail/dashboard?date_from=2099-02-31');
    pos_expect($invalidRetailDate['status'] === 422, 'reject impossible retail dashboard date filters');
    $foreignBusinessInsert = $pdo->prepare("INSERT INTO businesses (name, industry) VALUES ('Foreign retail workspace', 'retail')");
    $foreignBusinessInsert->execute();
    $foreignBusinessId = (int) $pdo->lastInsertId();
    $foreignDashboard = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/retail/dashboard', null, null, null, $foreignBusinessId);
    pos_expect($foreignDashboard['status'] === 403, 'reject dashboard access to a business without active membership');
    $history = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/pos/transactions?status=paid');
    pos_expect($history['status'] === 200 && count($history['body']['transactions']) >= 1, 'list paid transactions through paginated history');
    $void = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/transactions/' . $paid['body']['sale']['id'] . '/cancel', ['reason' => 'POS integration test'], $csrfToken);
    pos_expect($void['status'] === 200 && $void['body']['sale']['payment_status'] === 'voided', 'void paid sales with an administrator audit trail');
    $dashboardAfterVoid = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/retail/dashboard?date_from=' . $businessToday . '&date_to=' . $businessToday);
    pos_expect($dashboardAfterVoid['status'] === 200 && $dashboardAfterVoid['body']['today']['transactions'] === 0,
        'exclude voided sales from retail performance totals');
    pos_expect(pos_stock_quantity($pdo, $productId) === 6, 'voiding a sale restores its inventory');

    $deactivateProduct = pos_api_request($baseUrl, $cookieJar, 'DELETE', '/api/products/' . $productId, null, $csrfToken);
    $restoreProduct = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/products/' . $productId . '/restore', ['restore' => true], $csrfToken);
    pos_expect($deactivateProduct['status'] === 200 && $restoreProduct['status'] === 200, 'archive and restore products while preserving movement history');
    $deactivateCategory = pos_api_request($baseUrl, $cookieJar, 'DELETE', '/api/categories/' . $categoryId, null, $csrfToken);
    $restoreCategory = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/categories/' . $categoryId . '/restore', ['restore' => true], $csrfToken);
    pos_expect($deactivateCategory['status'] === 200 && $restoreCategory['status'] === 200, 'deactivate and reactivate categories without deleting product references');

    $setPharmacy = $pdo->prepare("UPDATE businesses SET industry = 'pharmacy' WHERE id = :business_id");
    $setPharmacy->execute(['business_id' => $businessId]);
    $medicineDetailsPayload = [
        'product_id' => $productId,
        'generic_name' => 'Test generic',
        'brand_name' => 'Test brand',
        'manufacturer' => 'Test manufacturer',
        'active_ingredients' => 'Example ingredient',
        'strength' => '10 mg',
        'dosage_form' => 'tablet',
        'route' => 'oral',
        'package_size' => '10 tablets',
        'unit_of_measure' => 'tablet',
        'prescription_required' => false,
        'controlled_classification' => 'none',
        'storage_instructions' => 'Store as labeled',
        'cold_chain' => false,
        'batch_tracking' => true,
        'warnings' => '',
    ];
    $medicineDetails = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pharmacy/medicines', $medicineDetailsPayload, $csrfToken);
    pos_expect($medicineDetails['status'] === 201, 'create pharmacy medicine details for an existing shared product');
    $medicineList = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/pharmacy/medicines?search=Example%20ingredient');
    pos_expect($medicineList['status'] === 200 && count($medicineList['body']['medicines']) === 1
        && $medicineList['body']['medicines'][0]['generic_name'] === 'Test generic',
        'search the shared product catalog by pharmacy ingredient');
    $todayLocal = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $receiptEarly = [
        'product_id' => $productId,
        'batch_number' => 'LOT-EARLY',
        'expires_on' => $todayLocal->modify('+20 days')->format('Y-m-d'),
        'quantity' => 3,
        'purchase_cost_cents' => 900,
        'reference_number' => 'DELIVERY-EARLY',
    ];
    $earlyKey = 'pharmacy-receipt:' . bin2hex(random_bytes(12));
    $earlyReceipt = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pharmacy/batches', $receiptEarly, $csrfToken, $earlyKey);
    pos_expect($earlyReceipt['status'] === 201, 'receive pharmacy batch stock into the shared inventory ledger');
    $stockAfterReceipt = pos_stock_quantity($pdo, $productId);
    $earlyReplay = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pharmacy/batches', $receiptEarly, $csrfToken, $earlyKey);
    pos_expect($earlyReplay['status'] === 200 && $earlyReplay['body']['replayed'] === true
        && pos_stock_quantity($pdo, $productId) === $stockAfterReceipt,
        'make pharmacy batch receipts idempotent');
    $receiptLate = [
        'product_id' => $productId,
        'batch_number' => 'LOT-LATE',
        'expires_on' => $todayLocal->modify('+40 days')->format('Y-m-d'),
        'quantity' => 4,
        'purchase_cost_cents' => 1000,
    ];
    $lateReceipt = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pharmacy/batches', $receiptLate, $csrfToken, 'pharmacy-receipt:' . bin2hex(random_bytes(12)));
    pos_expect($lateReceipt['status'] === 201, 'receive a second batch of the same medicine');
    $expiredBatchInsert = $pdo->prepare(
        "INSERT INTO medicine_batches (
            business_id, product_id, batch_number, expires_on, quantity_received,
            available_quantity, purchase_cost_cents, status, created_by
         ) VALUES (:business_id, :product_id, 'LOT-EXPIRED', :expires_on, 50, 50, 100, 'available', :user_id)",
    );
    $expiredBatchInsert->execute([
        'business_id' => $businessId,
        'product_id' => $productId,
        'expires_on' => $todayLocal->modify('-1 day')->format('Y-m-d'),
        'user_id' => $userId,
    ]);
    $insufficientEligible = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/checkout', [
        'items' => [['product_id' => $productId, 'quantity' => 8]],
        'payment_method' => 'cash',
        'amount_received_cents' => 100000000,
        'discount_bps' => 0,
    ], $csrfToken, 'pharmacy-pos:' . bin2hex(random_bytes(12)));
    pos_expect($insufficientEligible['status'] === 409, 'reject pharmacy sales when only expired batches could fulfill the quantity');
    $pharmacySale = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/checkout', [
        'items' => [['product_id' => $productId, 'quantity' => 5]],
        'payment_method' => 'cash',
        'amount_received_cents' => 100000000,
        'discount_bps' => 0,
    ], $csrfToken, 'pharmacy-pos:' . bin2hex(random_bytes(12)));
    pos_expect($pharmacySale['status'] === 201 && count($pharmacySale['body']['sale']['items'][0]['batch_allocations']) === 2
        && $pharmacySale['body']['sale']['items'][0]['batch_allocations'][0]['batch_number'] === 'LOT-EARLY'
        && $pharmacySale['body']['sale']['items'][0]['batch_allocations'][0]['quantity'] === 3
        && $pharmacySale['body']['sale']['items'][0]['batch_allocations'][1]['batch_number'] === 'LOT-LATE'
        && $pharmacySale['body']['sale']['items'][0]['batch_allocations'][1]['quantity'] === 2,
        'allocate sale stock across medicine batches in FEFO order');
    $pharmacyDashboard = pos_api_request(
        $baseUrl,
        $cookieJar,
        'GET',
        '/api/pharmacy/dashboard?date_from=' . $todayLocal->format('Y-m-d') . '&date_to=' . $todayLocal->format('Y-m-d'),
    );
    if ($pharmacyDashboard['status'] !== 200) {
        fwrite(STDERR, 'Pharmacy dashboard response: ' . json_encode($pharmacyDashboard, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    pos_expect($pharmacyDashboard['status'] === 200 && $pharmacyDashboard['body']['metrics']['medicine_count'] === 1
        && $pharmacyDashboard['body']['metrics']['today_transactions'] === 1
        && $pharmacyDashboard['body']['top_medicines'][0]['units_sold'] === 5
        && count($pharmacyDashboard['body']['expiry_alerts']) >= 1,
        'calculate pharmacy dashboard sales, medicine, and expiry metrics from saved records');
    $pharmacyBatchList = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/pharmacy/batches');
    $earlyBatchAfterSale = array_values(array_filter($pharmacyBatchList['body']['batches'], static fn (array $batch): bool => $batch['batch_number'] === 'LOT-EARLY'))[0] ?? null;
    $expiredBatch = array_values(array_filter($pharmacyBatchList['body']['batches'], static fn (array $batch): bool => $batch['batch_number'] === 'LOT-EXPIRED'))[0] ?? null;
    pos_expect($pharmacyBatchList['status'] === 200 && $earlyBatchAfterSale['available_quantity'] === 0
        && $expiredBatch['is_expired'] === 1 && $expiredBatch['available_quantity'] === 50,
        'persist batch deductions and flag expired stock without selling it');
    $voidPharmacySale = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/transactions/' . $pharmacySale['body']['sale']['id'] . '/cancel', ['reason' => 'Pharmacy batch regression test'], $csrfToken);
    $pharmacyBatchAfterVoid = pos_api_request($baseUrl, $cookieJar, 'GET', '/api/pharmacy/batches');
    $earlyBatchAfterVoid = array_values(array_filter($pharmacyBatchAfterVoid['body']['batches'], static fn (array $batch): bool => $batch['batch_number'] === 'LOT-EARLY'))[0] ?? null;
    pos_expect($voidPharmacySale['status'] === 200 && $earlyBatchAfterVoid['available_quantity'] === 3,
        'restore exact allocated batch quantities when a pharmacy sale is voided');

    $medicineDetailsPayload['prescription_required'] = true;
    $updateMedicineDetails = pos_api_request(
        $baseUrl,
        $cookieJar,
        'PUT',
        '/api/pharmacy/medicines/' . $medicineDetails['body']['id'],
        $medicineDetailsPayload,
        $csrfToken,
    );
    pos_expect($updateMedicineDetails['status'] === 200, 'mark medicine as prescription-required');
    $blockedPrescriptionSale = pos_api_request($baseUrl, $cookieJar, 'POST', '/api/pos/checkout', [
        'items' => [['product_id' => $productId, 'quantity' => 1]],
        'payment_method' => 'cash',
        'amount_received_cents' => 100000000,
        'discount_bps' => 0,
    ], $csrfToken, 'pharmacy-pos:' . bin2hex(random_bytes(12)));
    pos_expect($blockedPrescriptionSale['status'] === 403,
        'block prescription-required medicines until the authorized prescription workflow exists');
} finally {
    @unlink($cookieJar);
}
