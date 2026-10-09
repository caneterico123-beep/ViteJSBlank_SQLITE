<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
require_pos_operator($pdo);
$includeInactive = ($_GET['include_inactive'] ?? '') === '1';
$query = $includeInactive
    ? $pdo->query('SELECT id, name, is_active, created_at FROM categories ORDER BY name COLLATE NOCASE')
    : $pdo->query('SELECT id, name, is_active, created_at FROM categories WHERE is_active = 1 ORDER BY name COLLATE NOCASE');
$categories = $query->fetchAll();
foreach ($categories as &$category) {
    $category['id'] = (int) $category['id'];
    $category['is_active'] = (bool) $category['is_active'];
}
unset($category);
json_response(200, ['categories' => $categories]);