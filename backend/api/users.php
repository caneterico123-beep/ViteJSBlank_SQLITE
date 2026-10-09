<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
require_admin($pdo);

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?: 1;
$perPage = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 1, 'max_range' => 100]]) ?: 10;
$searchInput = $_GET['search'] ?? '';
if (!is_string($searchInput) || mb_strlen($searchInput, 'UTF-8') > 100) {
    json_response(422, ['message' => 'Search must be 100 characters or fewer.', 'errors' => ['search' => 'Search must be 100 characters or fewer.']]);
}
$search = trim($searchInput);
$escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
$pattern = '%' . $escapedSearch . '%';
$where = $search === '' ? '' : "WHERE name LIKE :name ESCAPE '!' OR email LIKE :email ESCAPE '!'";

if ($search === '') {
    $count = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $query = $pdo->prepare(
        "SELECT id, name, email, role, created_at, last_login_at,
            CASE WHEN last_login_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END AS is_active
         FROM users ORDER BY id DESC LIMIT :limit OFFSET :offset",
    );
} else {
    $countQuery = $pdo->prepare("SELECT COUNT(*) FROM users {$where}");
    $countQuery->execute(['name' => $pattern, 'email' => $pattern]);
    $count = $countQuery->fetchColumn();
    $query = $pdo->prepare(
        "SELECT id, name, email, role, created_at, last_login_at,
            CASE WHEN last_login_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END AS is_active
         FROM users {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset",
    );
    $query->bindValue(':name', $pattern, PDO::PARAM_STR);
    $query->bindValue(':email', $pattern, PDO::PARAM_STR);
}
$query->bindValue(':limit', $perPage, PDO::PARAM_INT);
$query->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$query->execute();

json_response(200, [
    'users' => $query->fetchAll(),
    'pagination' => [
        'page' => $page,
        'perPage' => $perPage,
        'total' => (int) $count,
        'totalPages' => max(1, (int) ceil((int) $count / $perPage)),
    ],
]);