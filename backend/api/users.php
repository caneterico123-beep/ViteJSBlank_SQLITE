<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_admin($pdo);

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?: 1;
$perPage = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT, ['options' => ['default' => 10, 'min_range' => 1, 'max_range' => 100]]) ?: 10;
$searchInput = $_GET['search'] ?? '';
if (!is_string($searchInput) || mb_strlen($searchInput, 'UTF-8') > 100) {
    json_response(422, ['message' => 'Search must be 100 characters or fewer.', 'errors' => ['search' => 'Search must be 100 characters or fewer.']]);
}
$search = trim($searchInput);
$escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
$pattern = '%' . $escapedSearch . '%';
$where = $search === '' ? '' : "AND (users.name LIKE :name ESCAPE '!' OR users.email LIKE :email ESCAPE '!')";

if ($search === '') {
    $countQuery = $pdo->prepare('SELECT COUNT(*) FROM business_members WHERE business_id = :business_id AND is_active = 1');
    $countQuery->execute(['business_id' => $user['business_id']]);
    $count = $countQuery->fetchColumn();
    $query = $pdo->prepare(
        "SELECT users.id, users.name, users.email, business_members.role, users.created_at, users.last_login_at,
            CASE WHEN users.last_login_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END AS is_active
         FROM business_members JOIN users ON users.id = business_members.user_id
         WHERE business_members.business_id = :business_id AND business_members.is_active = 1
         ORDER BY users.id DESC LIMIT :limit OFFSET :offset",
    );
    $query->bindValue(':business_id', $user['business_id'], PDO::PARAM_INT);
} else {
    $countQuery = $pdo->prepare("SELECT COUNT(*) FROM business_members JOIN users ON users.id = business_members.user_id WHERE business_members.business_id = :business_id AND business_members.is_active = 1 {$where}");
    $countQuery->execute(['business_id' => $user['business_id'], 'name' => $pattern, 'email' => $pattern]);
    $count = $countQuery->fetchColumn();
    $query = $pdo->prepare(
        "SELECT users.id, users.name, users.email, business_members.role, users.created_at, users.last_login_at,
            1 AS is_active
         FROM business_members JOIN users ON users.id = business_members.user_id
         WHERE business_members.business_id = :business_id AND business_members.is_active = 1 {$where}
         ORDER BY users.id DESC LIMIT :limit OFFSET :offset",
    );
    $query->bindValue(':business_id', $user['business_id'], PDO::PARAM_INT);
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