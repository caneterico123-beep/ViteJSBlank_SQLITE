<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_pharmacy_user($pdo, ['owner', 'admin', 'manager', 'pharmacist', 'inventory_staff', 'cashier']);
try {
    $timezone = new DateTimeZone($user['business']['timezone']);
} catch (Throwable $error) {
    throw new RuntimeException('The business timezone is invalid.', 0, $error);
}
$utc = new DateTimeZone('UTC');
$today = new DateTimeImmutable('today', $timezone);
$dateFromInput = $_GET['date_from'] ?? $today->modify('-29 days')->format('Y-m-d');
$dateToInput = $_GET['date_to'] ?? $today->format('Y-m-d');
$dateFrom = new DateTimeImmutable(pharmacy_date($dateFromInput, 'date_from'), $timezone);
$dateTo = new DateTimeImmutable(pharmacy_date($dateToInput, 'date_to'), $timezone);
if ($dateFrom > $dateTo || $dateFrom->diff($dateTo)->days > 365) {
    json_response(422, ['message' => 'Choose a date range of at most 366 days with the start before the end.']);
}
$businessId = (int) $user['business_id'];
$todayText = $today->format('Y-m-d');
$todayStart = $today->setTimezone($utc)->format('Y-m-d H:i:s');
$tomorrowStart = $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
$rangeStart = $dateFrom->setTimezone($utc)->format('Y-m-d H:i:s');
$rangeEnd = $dateTo->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

$inventoryQuery = $pdo->prepare(
    "SELECT COUNT(*) AS medicine_count,
            SUM(CASE WHEN eligible_stock > 0 THEN 1 ELSE 0 END) AS in_stock_count,
            SUM(CASE WHEN eligible_stock > 0 AND eligible_stock <= reorder_level THEN 1 ELSE 0 END) AS low_stock_count,
            SUM(CASE WHEN eligible_stock = 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
            COALESCE(SUM(eligible_stock * cost_price_cents), 0) AS inventory_value_cents
     FROM (
         SELECT products.id, products.reorder_level, products.cost_price_cents,
                COALESCE(SUM(CASE WHEN batches.status = 'available' AND batches.expires_on > :today
                                  THEN batches.available_quantity ELSE 0 END), 0) AS eligible_stock
         FROM products
         JOIN pharmacy_product_details AS details ON details.product_id = products.id AND details.business_id = products.business_id
         LEFT JOIN medicine_batches AS batches ON batches.product_id = products.id AND batches.business_id = products.business_id
         WHERE products.business_id = :business_id AND products.is_active = 1
         GROUP BY products.id
     ) AS stock",
);
$inventoryQuery->execute(['today' => $todayText, 'business_id' => $businessId]);
$inventory = $inventoryQuery->fetch();

$expiryQuery = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'available' AND available_quantity > 0 AND expires_on <= :today THEN available_quantity ELSE 0 END), 0) AS expired_quantity,
        COALESCE(SUM(CASE WHEN status = 'available' AND available_quantity > 0 AND expires_on > :today AND expires_on <= :days30 THEN available_quantity ELSE 0 END), 0) AS expiring_30_quantity,
        COALESCE(SUM(CASE WHEN status = 'available' AND available_quantity > 0 AND expires_on > :days30 AND expires_on <= :days60 THEN available_quantity ELSE 0 END), 0) AS expiring_60_quantity,
        COALESCE(SUM(CASE WHEN status = 'available' AND available_quantity > 0 AND expires_on > :days60 AND expires_on <= :days90 THEN available_quantity ELSE 0 END), 0) AS expiring_90_quantity,
        COALESCE(SUM(CASE WHEN status = 'available' AND available_quantity > 0 AND expires_on > :days90 AND expires_on <= :days180 THEN available_quantity ELSE 0 END), 0) AS expiring_180_quantity,
        COUNT(CASE WHEN status = 'available' AND available_quantity > 0 AND expires_on <= :today THEN 1 END) AS expired_batches,
        COUNT(CASE WHEN status = 'available' AND available_quantity > 0 AND expires_on > :today AND expires_on <= :days90 THEN 1 END) AS expiring_batches_90
     FROM medicine_batches WHERE business_id = :business_id",
);
$expiryQuery->execute([
    'today' => $todayText,
    'days30' => $today->modify('+30 days')->format('Y-m-d'),
    'days60' => $today->modify('+60 days')->format('Y-m-d'),
    'days90' => $today->modify('+90 days')->format('Y-m-d'),
    'days180' => $today->modify('+180 days')->format('Y-m-d'),
    'business_id' => $businessId,
]);
$expiry = $expiryQuery->fetch();

$salesQuery = $pdo->prepare(
    "SELECT COALESCE(SUM(subtotal_cents), 0) AS gross_sales_cents,
            COALESCE(SUM(discount_cents), 0) AS discounts_cents,
            COALESCE(SUM(total_cents - tax_cents), 0) AS net_sales_cents,
            COUNT(*) AS transactions
     FROM sales
     WHERE business_id = :business_id AND payment_status = 'paid'
       AND created_at >= :starts_at AND created_at < :ends_at",
);
$salesQuery->execute(['business_id' => $businessId, 'starts_at' => $todayStart, 'ends_at' => $tomorrowStart]);
$todaySales = $salesQuery->fetch();

$rangeSalesQuery = $pdo->prepare(
    "SELECT created_at, subtotal_cents, discount_cents
     FROM sales
     WHERE business_id = :business_id AND payment_status = 'paid'
       AND created_at >= :starts_at AND created_at < :ends_at
     ORDER BY created_at",
);
$rangeSalesQuery->execute(['business_id' => $businessId, 'starts_at' => $rangeStart, 'ends_at' => $rangeEnd]);
$trendByDate = [];
foreach ($rangeSalesQuery->fetchAll() as $sale) {
    $localDate = (new DateTimeImmutable($sale['created_at'], $utc))->setTimezone($timezone)->format('Y-m-d');
    $trendByDate[$localDate] = ($trendByDate[$localDate] ?? 0) + (int) $sale['subtotal_cents'] - (int) $sale['discount_cents'];
}
$trend = [];
for ($date = $dateFrom; $date <= $dateTo; $date = $date->modify('+1 day')) {
    $day = $date->format('Y-m-d');
    $trend[] = ['date' => $day, 'label' => $date->format('M j'), 'sales_cents' => (int) ($trendByDate[$day] ?? 0)];
}

$topProductsQuery = $pdo->prepare(
    "SELECT sale_items.product_id, sale_items.product_name AS name,
            SUM(sale_items.quantity) AS units_sold,
            SUM(sale_items.line_total_cents) AS sales_cents
     FROM sale_items
     JOIN sales ON sales.id = sale_items.sale_id AND sales.business_id = sale_items.business_id
     JOIN pharmacy_product_details AS details ON details.product_id = sale_items.product_id AND details.business_id = sale_items.business_id
     WHERE sale_items.business_id = :business_id AND sales.payment_status = 'paid'
       AND sales.created_at >= :starts_at AND sales.created_at < :ends_at
     GROUP BY sale_items.product_id, sale_items.product_name
     ORDER BY units_sold DESC, sales_cents DESC LIMIT 8",
);
$topProductsQuery->execute(['business_id' => $businessId, 'starts_at' => $rangeStart, 'ends_at' => $rangeEnd]);

$expiryAlertsQuery = $pdo->prepare(
    "SELECT batches.id AS batch_id, batches.batch_number, batches.expires_on, batches.available_quantity,
            products.id AS product_id, products.name AS product_name,
            CAST(julianday(batches.expires_on) - julianday(:today) AS INTEGER) AS days_remaining
     FROM medicine_batches AS batches
     JOIN products ON products.id = batches.product_id AND products.business_id = batches.business_id
     JOIN pharmacy_product_details AS details ON details.product_id = products.id AND details.business_id = products.business_id
     WHERE batches.business_id = :business_id AND batches.status = 'available'
       AND batches.available_quantity > 0 AND batches.expires_on <= :days180
     ORDER BY batches.expires_on, batches.id LIMIT 100",
);
$expiryAlertsQuery->execute(['today' => $todayText, 'days180' => $today->modify('+180 days')->format('Y-m-d'), 'business_id' => $businessId]);

$recentSalesQuery = $pdo->prepare(
    "SELECT sales.id, sales.transaction_number, sales.total_cents, sales.payment_method,
            sales.payment_status, sales.created_at, users.name AS cashier_name
     FROM sales JOIN users ON users.id = sales.cashier_id
     WHERE sales.business_id = :business_id AND sales.created_at >= :starts_at AND sales.created_at < :ends_at
     ORDER BY sales.created_at DESC LIMIT 8",
);
$recentSalesQuery->execute(['business_id' => $businessId, 'starts_at' => $rangeStart, 'ends_at' => $rangeEnd]);
$recentMovementsQuery = $pdo->prepare(
    'SELECT movements.id, products.name AS product_name, movements.quantity_change, movements.movement_type,
            details.transaction_type, details.reason, movements.created_at, users.name AS staff_name
     FROM inventory_movements AS movements
     JOIN products ON products.id = movements.product_id AND products.business_id = movements.business_id
     JOIN pharmacy_product_details AS product_details ON product_details.product_id = products.id AND product_details.business_id = products.business_id
     JOIN users ON users.id = movements.user_id
     LEFT JOIN inventory_movement_details AS details ON details.movement_id = movements.id AND details.business_id = movements.business_id
     WHERE movements.business_id = :business_id AND movements.created_at >= :starts_at AND movements.created_at < :ends_at
     ORDER BY movements.created_at DESC LIMIT 8',
);
$recentMovementsQuery->execute(['business_id' => $businessId, 'starts_at' => $rangeStart, 'ends_at' => $rangeEnd]);

$medicineCount = (int) ($inventory['medicine_count'] ?? 0);
$transactionsToday = (int) ($todaySales['transactions'] ?? 0);
json_response(200, [
    'timezone' => $user['business']['timezone'],
    'date_range' => ['from' => $dateFrom->format('Y-m-d'), 'to' => $dateTo->format('Y-m-d')],
    'metrics' => [
        'medicine_count' => $medicineCount,
        'in_stock_count' => (int) ($inventory['in_stock_count'] ?? 0),
        'low_stock_count' => (int) ($inventory['low_stock_count'] ?? 0),
        'out_of_stock_count' => (int) ($inventory['out_of_stock_count'] ?? 0),
        'expired_batch_count' => (int) $expiry['expired_batches'],
        'expiring_batch_count_90_days' => (int) $expiry['expiring_batches_90'],
        'expired_quantity' => (int) $expiry['expired_quantity'],
        'expiring_quantity_30_days' => (int) $expiry['expiring_30_quantity'],
        'expiring_quantity_60_days' => (int) $expiry['expiring_60_quantity'],
        'expiring_quantity_90_days' => (int) $expiry['expiring_90_quantity'],
        'expiring_quantity_180_days' => (int) $expiry['expiring_180_quantity'],
        'inventory_value_cents' => (int) ($inventory['inventory_value_cents'] ?? 0),
        'today_gross_sales_cents' => (int) $todaySales['gross_sales_cents'],
        'today_net_sales_cents' => (int) $todaySales['net_sales_cents'],
        'today_transactions' => $transactionsToday,
        'today_average_transaction_cents' => $transactionsToday === 0 ? 0 : (int) round((int) $todaySales['net_sales_cents'] / $transactionsToday),
    ],
    'sales_trend' => $trend,
    'top_medicines' => array_map(static fn (array $row): array => [
        'product_id' => (int) $row['product_id'],
        'name' => $row['name'],
        'units_sold' => (int) $row['units_sold'],
        'sales_cents' => (int) $row['sales_cents'],
    ], $topProductsQuery->fetchAll()),
    'expiry_alerts' => array_map(static fn (array $row): array => [
        'batch_id' => (int) $row['batch_id'],
        'product_id' => (int) $row['product_id'],
        'product_name' => $row['product_name'],
        'batch_number' => $row['batch_number'],
        'expires_on' => $row['expires_on'],
        'available_quantity' => (int) $row['available_quantity'],
        'days_remaining' => (int) $row['days_remaining'],
    ], $expiryAlertsQuery->fetchAll()),
    'recent_sales' => $recentSalesQuery->fetchAll(),
    'recent_stock_movements' => $recentMovementsQuery->fetchAll(),
    'not_implemented_modules' => [
        'prescriptions',
        'dispensing',
        'purchase_orders',
        'returns_and_refunds',
        'clinical_interaction_checking',
    ],
]);
