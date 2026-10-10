<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_pos_enabled_user($pdo);
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?: 1;
$perPage = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT, ['options' => ['default' => 20, 'min_range' => 1, 'max_range' => 100]]) ?: 20;
$search = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$status = $_GET['status'] ?? '';
$method = $_GET['payment_method'] ?? '';
if (!is_string($search) || mb_strlen($search, 'UTF-8') > 100) json_response(422, ['message' => 'Search must be 100 characters or fewer.']);
foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $key => $date) {
    if ($date !== '' && (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1)) {
        json_response(422, ['message' => 'Date filters must use YYYY-MM-DD.', 'errors' => [$key => 'Use YYYY-MM-DD.']]);
    }
}
if ($status !== '' && !in_array($status, ['paid', 'pending', 'voided', 'cancelled'], true)) json_response(422, ['message' => 'Choose a valid payment status.']);
if ($method !== '' && !in_array($method, ['cash', 'card', 'ewallet'], true)) json_response(422, ['message' => 'Choose a valid payment method.']);

$where = ['sales.business_id = :business_id'];
$params = ['business_id' => $user['business_id']];
if (!in_array($user['role'], ['owner', 'admin', 'manager'], true)) {
    $where[] = 'sales.cashier_id = :cashier_id';
    $params['cashier_id'] = $user['id'];
}
if (trim($search) !== '') {
    $where[] = '(sales.transaction_number LIKE :search OR sales.customer_name LIKE :search OR users.name LIKE :search)';
    $params['search'] = '%' . trim($search) . '%';
}
if ($dateFrom !== '') {
    $where[] = 'date(sales.created_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'date(sales.created_at) <= :date_to';
    $params['date_to'] = $dateTo;
}
if ($status !== '') {
    $where[] = 'sales.payment_status = :status';
    $params['status'] = $status;
}
if ($method !== '') {
    $where[] = 'sales.payment_method = :payment_method';
    $params['payment_method'] = $method;
}
$clause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
$count = $pdo->prepare("SELECT COUNT(*) FROM sales JOIN users ON users.id = sales.cashier_id {$clause}");
$count->execute($params);
$total = (int) $count->fetchColumn();
$query = $pdo->prepare(
    "SELECT sales.id, sales.transaction_number, sales.cashier_id, users.name AS cashier_name,
            sales.customer_name, sales.subtotal_cents, sales.discount_cents, sales.tax_cents,
            sales.total_cents, sales.payment_method, sales.amount_received_cents,
            sales.change_cents, sales.payment_status, sales.created_at
     FROM sales JOIN users ON users.id = sales.cashier_id {$clause}
     ORDER BY sales.id DESC LIMIT :limit OFFSET :offset",
);
foreach ($params as $key => $value) $query->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
$query->bindValue(':limit', $perPage, PDO::PARAM_INT);
$query->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$query->execute();
json_response(200, [
    'transactions' => $query->fetchAll(),
    'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))],
]);