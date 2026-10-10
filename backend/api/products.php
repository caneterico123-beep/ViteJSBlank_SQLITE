<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_pos_enabled_user($pdo);
$isPharmacy = $user['business']['industry'] === 'pharmacy';
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

$where = ['products.business_id = :business_id', 'products.is_active = 1'];
$params = ['business_id' => $user['business_id']];
$search = trim($searchInput);
if ($search !== '') {
    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
    $where[] = "(products.name LIKE :name ESCAPE '!' OR products.sku LIKE :sku ESCAPE '!' OR products.barcode LIKE :barcode ESCAPE '!'
        OR (:is_pharmacy = 1 AND (pharmacy_product_details.generic_name LIKE :name ESCAPE '!'
            OR pharmacy_product_details.brand_name LIKE :name ESCAPE '!'
            OR pharmacy_product_details.active_ingredients LIKE :name ESCAPE '!')))";
    $params['name'] = '%' . $escaped . '%';
    $params['sku'] = '%' . $escaped . '%';
    $params['barcode'] = '%' . $escaped . '%';
    $params['is_pharmacy'] = $isPharmacy ? 1 : 0;
}
if ($categoryId !== null) {
    $where[] = 'products.category_id = :category_id';
    $params['category_id'] = $categoryId;
}

$sql = 'SELECT products.id, products.category_id, categories.name AS category_name,
               products.sku, products.barcode, products.name, products.description,
               products.price_cents, products.stock_quantity, products.image_path,
               pharmacy_product_details.generic_name, pharmacy_product_details.brand_name,
               pharmacy_product_details.active_ingredients, pharmacy_product_details.prescription_required,
               pharmacy_product_details.batch_tracking
        FROM products LEFT JOIN categories ON categories.id = products.category_id AND categories.business_id = products.business_id
        LEFT JOIN pharmacy_product_details ON pharmacy_product_details.product_id = products.id AND pharmacy_product_details.business_id = products.business_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY products.name COLLATE NOCASE LIMIT 200';
$query = $pdo->prepare($sql);
$query->execute($params);
$products = $query->fetchAll();
foreach ($products as &$product) {
    foreach (['id', 'price_cents', 'stock_quantity'] as $field) {
        $product[$field] = (int) $product[$field];
    }
    if (!$isPharmacy) {
        unset($product['generic_name'], $product['brand_name'], $product['active_ingredients'], $product['prescription_required'], $product['batch_tracking']);
    }
    if ($product['category_id'] !== null) {
        $product['category_id'] = (int) $product['category_id'];
    }
}
unset($product);
json_response(200, ['products' => $products]);