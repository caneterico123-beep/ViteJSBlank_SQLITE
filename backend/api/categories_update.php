<?php
declare(strict_types=1);

require_method('PUT');
$pdo = database_connection();
require_admin($pdo);
$input = request_json();
$name = $input['name'] ?? null;
if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name), 'UTF-8') > 60) {
    json_response(422, ['message' => 'Enter a category name up to 60 characters.', 'errors' => ['name' => 'Enter a category name up to 60 characters.']]);
}

try {
    $update = $pdo->prepare('UPDATE categories SET name = :name WHERE id = :id AND is_active = 1');
    $update->execute(['name' => trim($name), 'id' => pos_route_id()]);
} catch (PDOException $error) {
    if ($error->getCode() === '23000') {
        json_response(409, ['message' => 'That category already exists.']);
    }
    throw $error;
}
if ($update->rowCount() === 0) {
    json_response(404, ['message' => 'Category not found.']);
}
json_response(200, ['message' => 'Category updated.']);