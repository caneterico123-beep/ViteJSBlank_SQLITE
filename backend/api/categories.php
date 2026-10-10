<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_role($pdo, ['owner', 'admin', 'manager', 'inventory_staff', 'cashier']);
$includeInactive = ($_GET['include_inactive'] ?? '') === '1';
$query = $pdo->prepare(
    'SELECT categories.id, categories.name, categories.description, categories.is_active, categories.created_at,
            COUNT(products.id) AS product_count
     FROM categories LEFT JOIN products ON products.category_id = categories.id AND products.business_id = categories.business_id
     WHERE categories.business_id = :business_id ' . ($includeInactive ? '' : 'AND categories.is_active = 1 ') .
    'GROUP BY categories.id ORDER BY categories.name COLLATE NOCASE',
);
$query->execute(['business_id' => $user['business_id']]);
$categories = $query->fetchAll();
foreach ($categories as &$category) {
    $category['id'] = (int) $category['id'];
    $category['product_count'] = (int) $category['product_count'];
    $category['is_active'] = (bool) $category['is_active'];
}
unset($category);
json_response(200, ['categories' => $categories]);