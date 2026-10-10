<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_inventory_operator($pdo);
$searchInput = $_GET['search'] ?? '';
$type = $_GET['type'] ?? '';
$page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
$perPage = filter_var($_GET['per_page'] ?? '20', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$allowedTypes = ['', 'sale', 'void', 'return', 'adjustment', 'stock_in', 'stock_out', 'adjustment_increase', 'adjustment_decrease', 'opening_balance'];
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if (!is_string($searchInput) || mb_strlen($searchInput, 'UTF-8') > 100 || !is_string($type)
    || !in_array($type, $allowedTypes, true) || $page === false || $perPage === false
    || !is_string($dateFrom) || !is_string($dateTo)
    || ($dateFrom !== '' && preg_match($datePattern, $dateFrom) !== 1)
    || ($dateTo !== '' && preg_match($datePattern, $dateTo) !== 1)) {
    json_response(422, ['message' => 'One or more history filters are invalid.']);
}
foreach ([$dateFrom, $dateTo] as $date) {
    if ($date !== '') {
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            json_response(422, ['message' => 'Enter valid history dates.']);
        }
    }
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    json_response(422, ['message' => 'The start date must be on or before the end date.']);
}
$where = ['movements.business_id = :business_id'];
$params = ['business_id' => $user['business_id']];
if (trim($searchInput) !== '') {
    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($searchInput));
    $where[] = "(products.name LIKE :name ESCAPE '!' OR products.sku LIKE :sku ESCAPE '!')";
    $params['name'] = '%' . $escaped . '%';
    $params['sku'] = '%' . $escaped . '%';
}
if ($type !== '') {
    $where[] = 'COALESCE(details.transaction_type, movements.movement_type) = :type';
    $params['type'] = $type;
}
if ($dateFrom !== '') {
    $where[] = "movements.created_at >= :date_from";
    $params['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'movements.created_at < :date_to';
    $params['date_to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
}
$filter = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
$count = $pdo->prepare(
    "SELECT COUNT(*) FROM inventory_movements movements
     JOIN products ON products.id = movements.product_id AND products.business_id = movements.business_id
     LEFT JOIN inventory_movement_details details ON details.movement_id = movements.id
     {$filter}",
);
$count->execute($params);
$total = (int) $count->fetchColumn();
$query = $pdo->prepare(
    "SELECT movements.id, movements.product_id, products.name AS product_name, products.sku,
            movements.movement_type, movements.quantity_change, movements.notes,
            movements.created_at, users.name AS performed_by,
            details.transaction_type, details.quantity_before, details.quantity_after,
            details.reason, details.reference_number, suppliers.name AS supplier_name
     FROM inventory_movements movements
     JOIN products ON products.id = movements.product_id AND products.business_id = movements.business_id
     JOIN users ON users.id = movements.user_id
     LEFT JOIN inventory_movement_details details ON details.movement_id = movements.id
     LEFT JOIN suppliers ON suppliers.id = details.supplier_id AND suppliers.business_id = movements.business_id
     {$filter}
     ORDER BY movements.created_at DESC, movements.id DESC
     LIMIT :limit OFFSET :offset",
);
foreach ($params as $name => $value) $query->bindValue(':' . $name, $value, PDO::PARAM_STR);
$query->bindValue(':limit', $perPage, PDO::PARAM_INT);
$query->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$query->execute();
$transactions = $query->fetchAll();
foreach ($transactions as &$transaction) {
    foreach (['id', 'product_id', 'quantity_change'] as $field) $transaction[$field] = (int) $transaction[$field];
    foreach (['quantity_before', 'quantity_after'] as $field) {
        $transaction[$field] = $transaction[$field] === null ? null : (int) $transaction[$field];
    }
    $transaction['transaction_type'] ??= $transaction['movement_type'];
    $transaction['reason'] ??= $transaction['notes'];
}
unset($transaction);
json_response(200, [
    'transactions' => $transactions,
    'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage)],
]);
