<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_inventory_operator($pdo);

$searchInput = $_GET['search'] ?? '';
$page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
$perPage = filter_var($_GET['per_page'] ?? '20', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
$categoryInput = $_GET['category_id'] ?? '';
$supplierInput = $_GET['supplier_id'] ?? '';
$includeInactive = ($_GET['include_inactive'] ?? '') === '1';
$stockStatus = $_GET['stock_status'] ?? '';
$sort = $_GET['sort'] ?? 'name';
$categoryId = $categoryInput === '' ? null : filter_var($categoryInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$supplierId = $supplierInput === '' ? null : filter_var($supplierInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sortColumns = [
    'name' => 'products.name COLLATE NOCASE',
    'price' => 'products.price_cents',
    'cost' => 'products.cost_price_cents',
    'quantity' => 'products.stock_quantity',
    'updated_at' => 'COALESCE(products.updated_at, products.created_at)',
];
if (!is_string($searchInput) || mb_strlen($searchInput, 'UTF-8') > 100 || $page === false || $perPage === false
    || ($categoryInput !== '' && $categoryId === false) || ($supplierInput !== '' && $supplierId === false)
    || !is_string($stockStatus) || !in_array($stockStatus, ['', 'in_stock', 'low_stock', 'out_of_stock'], true)
    || !is_string($sort) || !array_key_exists($sort, $sortColumns)) {
    json_response(422, ['message' => 'One or more product filters are invalid.']);
}

$where = ['products.business_id = :business_id'];
$params = ['business_id' => $user['business_id']];
if (!$includeInactive) {
    $where[] = 'products.is_active = 1';
}
$search = trim($searchInput);
if ($search !== '') {
    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
    $where[] = "(products.name LIKE :search_name ESCAPE '!' OR products.sku LIKE :search_sku ESCAPE '!' OR products.barcode LIKE :search_barcode ESCAPE '!')";
    $wildcardSearch = '%' . $escaped . '%';
    $params['search_name'] = $wildcardSearch;
    $params['search_sku'] = $wildcardSearch;
    $params['search_barcode'] = $wildcardSearch;
}
if ($stockStatus === 'out_of_stock') {
    $where[] = 'products.stock_quantity = 0';
} elseif ($stockStatus === 'low_stock') {
    $where[] = 'products.stock_quantity > 0 AND products.stock_quantity <= products.reorder_level';
} elseif ($stockStatus === 'in_stock') {
    $where[] = 'products.stock_quantity > products.reorder_level';
}
if ($categoryId !== null) {
    $where[] = 'products.category_id = :category_id';
    $params['category_id'] = $categoryId;
}
if ($supplierId !== null) {
    $where[] = 'products.supplier_id = :supplier_id';
    $params['supplier_id'] = $supplierId;
}
$filter = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$count = $pdo->prepare("SELECT COUNT(*) FROM products {$filter}");
$count->execute($params);
$total = (int) $count->fetchColumn();
$offset = ($page - 1) * $perPage;
$query = $pdo->prepare(
    "SELECT products.id, products.category_id, products.supplier_id, products.sku, products.barcode,
            products.name, products.description, products.price_cents, products.cost_price_cents,
            products.stock_quantity, products.reorder_level, products.image_path, products.is_active,
            products.created_at, products.updated_at, categories.name AS category_name,
            suppliers.name AS supplier_name,
            CASE WHEN products.stock_quantity = 0 THEN 'out_of_stock'
                 WHEN products.stock_quantity <= products.reorder_level THEN 'low_stock'
                 ELSE 'in_stock' END AS stock_status
     FROM products
     LEFT JOIN categories ON categories.id = products.category_id AND categories.business_id = products.business_id
     LEFT JOIN suppliers ON suppliers.id = products.supplier_id AND suppliers.business_id = products.business_id
     {$filter}
     ORDER BY {$sortColumns[$sort]}, products.id
     LIMIT :limit OFFSET :offset",
);
foreach ($params as $name => $value) {
    $query->bindValue(':' . $name, $value, PDO::PARAM_STR);
}
$query->bindValue(':limit', $perPage, PDO::PARAM_INT);
$query->bindValue(':offset', $offset, PDO::PARAM_INT);
$query->execute();
$products = $query->fetchAll();
foreach ($products as &$product) {
    foreach (['id', 'price_cents', 'cost_price_cents', 'stock_quantity', 'reorder_level'] as $field) {
        $product[$field] = (int) $product[$field];
    }
    foreach (['category_id', 'supplier_id'] as $field) {
        $product[$field] = $product[$field] === null ? null : (int) $product[$field];
    }
    $product['is_active'] = (bool) $product['is_active'];
    $product['inventory_value_cents'] = $product['stock_quantity'] * $product['cost_price_cents'];
}
unset($product);

json_response(200, [
    'products' => $products,
    'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage)],
]);
