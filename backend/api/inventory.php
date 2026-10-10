<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_inventory_operator($pdo);
$search = $_GET['search'] ?? '';
if (!is_string($search) || mb_strlen($search, 'UTF-8') > 100) {
    json_response(422, ['message' => 'Search must be 100 characters or fewer.']);
}
$params = ['business_id' => $user['business_id']];
$filter = 'WHERE products.business_id = :business_id';
if (trim($search) !== '') {
    $filter .= ' AND (products.name LIKE :search OR products.sku LIKE :search)';
    $params['search'] = '%' . trim($search) . '%';
}
$query = $pdo->prepare(
    "SELECT products.id, products.name, products.sku, products.barcode, products.stock_quantity,
            products.price_cents, categories.name AS category_name
     FROM products LEFT JOIN categories ON categories.id = products.category_id AND categories.business_id = products.business_id
     {$filter} ORDER BY products.name COLLATE NOCASE LIMIT 500",
);
$query->execute($params);
$inventory = $query->fetchAll();
foreach ($inventory as &$item) {
    foreach (['id', 'stock_quantity', 'price_cents'] as $field) $item[$field] = (int) $item[$field];
}
unset($item);
json_response(200, ['inventory' => $inventory]);