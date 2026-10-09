<?php
declare(strict_types=1);

require_method('POST');
require_admin(database_connection());
$input = request_json();
$name = $input['name'] ?? null;
if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name), 'UTF-8') > 60) {
    json_response(422, ['message' => 'Enter a category name up to 60 characters.', 'errors' => ['name' => 'Enter a category name up to 60 characters.']]);
}

try {
    $insert = database_connection()->prepare('INSERT INTO categories (name) VALUES (:name)');
    $insert->execute(['name' => trim($name)]);
} catch (PDOException $error) {
    if ($error->getCode() === '23000') {
        json_response(409, ['message' => 'That category already exists.']);
    }
    throw $error;
}
json_response(201, ['category' => ['id' => (int) database_connection()->lastInsertId(), 'name' => trim($name), 'is_active' => true]]);