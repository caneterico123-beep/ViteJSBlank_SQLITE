<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
require_pos_operator($pdo);
$searchInput = $_GET['search'] ?? '';
$categoryInput = $_GET['category_id'] ?? '';
if (!is_string($searchInput) || mb_strlen($searchInput, 'UTF-8') > 100) {
    json_response(422, ['message' => 'Search must be 100 characters or fewer.']);
}
$categoryId = null;
if ($categoryInput !== '') {
    $categoryId = filter_var($categoryInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($categoryId === false) {
        json_response(422, ['message' => 'Choose a valid category.']);
    }
}

$where = ['products.is_active = 1'];
$params = [];
$search = trim($searchInput);
if ($search !== '') {
    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
    $where[] = "(products.name LIKE :name ESCAPE '!' OR products.sku LIKE :sku ESCAPE '!' OR products.barcode LIKE :barcode ESCAPE '!')";
    $params['name'] = '%' . $escaped . '%';
    $params['sku'] = '%' . $escaped . '%';
    $params['barcode'] = '%' . $escaped . '%';
}
if ($categoryId !== null) {
    $where[] = 'products.category_id = :category_id';
    $params['category_id'] = $categoryId;
}

$sql = 'SELECT products.id, products.category_id, categories.name AS category_name,
               products.sku, products.barcode, products.name, products.description,
               products.price_cents, products.stock_quantity, products.image_path
        FROM products LEFT JOIN categories ON categories.id = products.category_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY products.name COLLATE NOCASE LIMIT 200';
$query = $pdo->prepare($sql);
$query->execute($params);
$products = $query->fetchAll();
foreach ($products as &$product) {
    foreach (['id', 'price_cents', 'stock_quantity'] as $field) {
        $product[$field] = (int) $product[$field];
    }
    if ($product['category_id'] !== null) {
        $product['category_id'] = (int) $product['category_id'];
    }
}
unset($product);
json_response(200, ['products' => $products]);