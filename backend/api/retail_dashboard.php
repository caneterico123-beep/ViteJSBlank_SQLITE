<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_authenticated_user($pdo);
if ($user['business']['industry'] !== 'retail') {
    json_response(403, ['message' => 'The Retail dashboard is only available to retail businesses.']);
}
if (!in_array($user['role'], ['owner', 'admin', 'manager', 'cashier', 'inventory_staff'], true)) {
    json_response(403, ['message' => 'You do not have permission to view retail dashboard data.']);
}

try {
    $timezone = new DateTimeZone($user['business']['timezone']);
} catch (Throwable $error) {
    throw new RuntimeException('The business timezone is invalid.', 0, $error);
}
$utc = new DateTimeZone('UTC');
$today = new DateTimeImmutable('today', $timezone);
$dateFromInput = $_GET['date_from'] ?? $today->modify('-29 days')->format('Y-m-d');
$dateToInput = $_GET['date_to'] ?? $today->format('Y-m-d');
$groupBy = $_GET['group_by'] ?? 'day';

function retail_dashboard_date(mixed $value, string $field, DateTimeZone $timezone): DateTimeImmutable
{
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        json_response(422, ['message' => 'Date filters must use YYYY-MM-DD.', 'errors' => [$field => 'Use YYYY-MM-DD.']]);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value) {
        json_response(422, ['message' => 'Enter a valid calendar date.', 'errors' => [$field => 'Enter a real date.']]);
    }
    return $date;
}

$dateFrom = retail_dashboard_date($dateFromInput, 'date_from', $timezone);
$dateTo = retail_dashboard_date($dateToInput, 'date_to', $timezone);
if ($dateFrom > $dateTo || $dateFrom->diff($dateTo)->days > 365) {
    json_response(422, ['message' => 'Choose a date range of at most 366 days with the start before the end.']);
}
if (!is_string($groupBy) || !in_array($groupBy, ['day', 'week', 'month'], true)) {
    json_response(422, ['message' => 'Choose day, week, or month grouping.', 'errors' => ['group_by' => 'Choose a valid grouping.']]);
}

$todayStart = $today->setTimezone($utc)->format('Y-m-d H:i:s');
$tomorrowStart = $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
$rangeStart = $dateFrom->setTimezone($utc)->format('Y-m-d H:i:s');
$rangeEnd = $dateTo->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
$businessId = (int) $user['business_id'];

$todayQuery = $pdo->prepare(
    "SELECT COALESCE(SUM(subtotal_cents), 0) AS gross_sales_cents,
            COALESCE(SUM(discount_cents), 0) AS discounts_cents,
            COALESCE(SUM(total_cents - tax_cents), 0) AS net_sales_cents,
            COUNT(*) AS transaction_count
     FROM sales
     WHERE business_id = :business_id AND payment_status = 'paid'
       AND created_at >= :starts_at AND created_at < :ends_at",
);
$todayQuery->execute(['business_id' => $businessId, 'starts_at' => $todayStart, 'ends_at' => $tomorrowStart]);
$todaySummary = $todayQuery->fetch();
$todayTransactions = (int) $todaySummary['transaction_count'];

$inventoryQuery = $pdo->prepare(
    "SELECT COUNT(*) AS active_products,
            COALESCE(SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= reorder_level THEN 1 ELSE 0 END), 0) AS low_stock,
            COALESCE(SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
            COALESCE(SUM(stock_quantity * cost_price_cents), 0) AS inventory_value_cents,
            COALESCE(SUM(CASE WHEN stock_quantity > reorder_level THEN 1 ELSE 0 END), 0) AS in_stock
     FROM products
     WHERE business_id = :business_id AND is_active = 1",
);
$inventoryQuery->execute(['business_id' => $businessId]);
$inventory = $inventoryQuery->fetch();

$salesRowsQuery = $pdo->prepare(
    "SELECT created_at, subtotal_cents, discount_cents, total_cents, tax_cents, payment_method
     FROM sales
     WHERE business_id = :business_id AND payment_status = 'paid'
       AND created_at >= :starts_at AND created_at < :ends_at
     ORDER BY created_at",
);
$salesRowsQuery->execute(['business_id' => $businessId, 'starts_at' => $rangeStart, 'ends_at' => $rangeEnd]);
$salesRows = $salesRowsQuery->fetchAll();

$periodKey = static fn (DateTimeImmutable $date): string => match ($groupBy) {
    'week' => $date->format('o-W'),
    'month' => $date->format('Y-m'),
    default => $date->format('Y-m-d'),
};
$periodLabel = static fn (DateTimeImmutable $date): string => match ($groupBy) {
    'week' => $date->format('M j'),
    'month' => $date->format('M Y'),
    default => $date->format('M j'),
};
$salesByPeriod = [];
$salesByPaymentMethod = [];
foreach ($salesRows as $sale) {
    $localDate = (new DateTimeImmutable($sale['created_at'], $utc))->setTimezone($timezone);
    $key = $periodKey($localDate);
    if (!isset($salesByPeriod[$key])) {
        $salesByPeriod[$key] = ['label' => $periodLabel($localDate), 'sales_cents' => 0];
    }
    $salesByPeriod[$key]['sales_cents'] += (int) $sale['subtotal_cents'] - (int) $sale['discount_cents'];
    $method = $sale['payment_method'];
    $salesByPaymentMethod[$method] = ($salesByPaymentMethod[$method] ?? 0) + (int) $sale['total_cents'];
}

$periods = [];
$periodCursor = match ($groupBy) {
    'week' => $dateFrom->modify('monday this week'),
    'month' => $dateFrom->modify('first day of this month'),
    default => $dateFrom,
};
$lastPeriodDate = match ($groupBy) {
    'week' => $dateTo->modify('monday this week'),
    'month' => $dateTo->modify('first day of this month'),
    default => $dateTo,
};
while ($periodCursor <= $lastPeriodDate) {
    $key = $periodKey($periodCursor);
    $periods[] = [
        'key' => $key,
        'label' => $periodLabel($periodCursor),
        'sales_cents' => (int) ($salesByPeriod[$key]['sales_cents'] ?? 0),
    ];
    $periodCursor = $periodCursor->modify(match ($groupBy) {
        'week' => '+7 days',
        'month' => '+1 month',
        default => '+1 day',
    });
}

$paymentMethodLabels = ['cash' => 'Cash', 'card' => 'Card', 'ewallet' => 'E-wallet'];
$payments = [];
foreach ($paymentMethodLabels as $method => $label) {
    $payments[] = ['name' => $label, 'amount_cents' => (int) ($salesByPaymentMethod[$method] ?? 0)];
}

$itemParams = ['business_id' => $businessId, 'starts_at' => $rangeStart, 'ends_at' => $rangeEnd];
$topProductsQuery = $pdo->prepare(
    "SELECT sale_items.product_id, sale_items.product_name AS name,
            SUM(sale_items.quantity) AS units_sold,
            SUM(sale_items.line_total_cents) AS sales_cents
     FROM sale_items
     JOIN sales ON sales.id = sale_items.sale_id AND sales.business_id = sale_items.business_id
     WHERE sale_items.business_id = :business_id AND sales.payment_status = 'paid'
       AND sales.created_at >= :starts_at AND sales.created_at < :ends_at
     GROUP BY sale_items.product_id, sale_items.product_name
     ORDER BY units_sold DESC, sales_cents DESC LIMIT 8",
);
$topProductsQuery->execute($itemParams);
$topProducts = $topProductsQuery->fetchAll();

$categoryQuery = $pdo->prepare(
    "SELECT COALESCE(categories.name, 'Uncategorized') AS name,
            SUM(sale_items.quantity) AS units_sold,
            SUM(sale_items.line_total_cents) AS sales_cents
     FROM sale_items
     JOIN sales ON sales.id = sale_items.sale_id AND sales.business_id = sale_items.business_id
     JOIN products ON products.id = sale_items.product_id AND products.business_id = sale_items.business_id
     LEFT JOIN categories ON categories.id = products.category_id AND categories.business_id = products.business_id
     WHERE sale_items.business_id = :business_id AND sales.payment_status = 'paid'
       AND sales.created_at >= :starts_at AND sales.created_at < :ends_at
     GROUP BY categories.id, categories.name
     ORDER BY sales_cents DESC LIMIT 8",
);
$categoryQuery->execute($itemParams);
$categories = $categoryQuery->fetchAll();

$recentConditions = ['sales.business_id = :business_id', 'sales.created_at >= :starts_at', 'sales.created_at < :ends_at'];
$recentParams = ['business_id' => $businessId, 'starts_at' => $rangeStart, 'ends_at' => $rangeEnd];
if (!in_array($user['role'], ['owner', 'admin', 'manager'], true)) {
    $recentConditions[] = 'sales.cashier_id = :cashier_id';
    $recentParams['cashier_id'] = (int) $user['id'];
}
$recentQuery = $pdo->prepare(
    "SELECT sales.id, sales.transaction_number, sales.customer_name, sales.total_cents,
            sales.payment_method, sales.payment_status, sales.created_at, users.name AS cashier_name
     FROM sales JOIN users ON users.id = sales.cashier_id
     WHERE " . implode(' AND ', $recentConditions) . "
     ORDER BY sales.created_at DESC, sales.id DESC LIMIT 8",
);
$recentQuery->execute($recentParams);

json_response(200, [
    'timezone' => $user['business']['timezone'],
    'date_range' => ['from' => $dateFrom->format('Y-m-d'), 'to' => $dateTo->format('Y-m-d'), 'group_by' => $groupBy],
    'today' => [
        'gross_sales_cents' => (int) $todaySummary['gross_sales_cents'],
        'net_sales_cents' => (int) $todaySummary['net_sales_cents'],
        'discounts_cents' => (int) $todaySummary['discounts_cents'],
        'transactions' => $todayTransactions,
        'average_transaction_cents' => $todayTransactions === 0 ? 0 : (int) round((int) $todaySummary['net_sales_cents'] / $todayTransactions),
    ],
    'inventory' => [
        'active_products' => (int) $inventory['active_products'],
        'low_stock' => (int) $inventory['low_stock'],
        'out_of_stock' => (int) $inventory['out_of_stock'],
        'in_stock' => (int) $inventory['in_stock'],
        'inventory_value_cents' => (int) $inventory['inventory_value_cents'],
    ],
    'sales_by_period' => $periods,
    'sales_by_payment_method' => $payments,
    'top_products' => array_map(static fn (array $product): array => [
        'id' => (int) $product['product_id'],
        'name' => $product['name'],
        'units_sold' => (int) $product['units_sold'],
        'sales_cents' => (int) $product['sales_cents'],
    ], $topProducts),
    'sales_by_category' => array_map(static fn (array $category): array => [
        'name' => $category['name'],
        'units_sold' => (int) $category['units_sold'],
        'sales_cents' => (int) $category['sales_cents'],
    ], $categories),
    'stock_status' => [
        ['name' => 'In stock', 'products' => (int) $inventory['in_stock']],
        ['name' => 'Low stock', 'products' => (int) $inventory['low_stock']],
        ['name' => 'Out of stock', 'products' => (int) $inventory['out_of_stock']],
    ],
    'recent_transactions' => $recentQuery->fetchAll(),
]);
