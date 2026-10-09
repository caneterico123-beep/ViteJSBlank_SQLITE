<?php
declare(strict_types=1);

require_method('DELETE');
$pdo = database_connection();
require_admin($pdo);
$update = $pdo->prepare('UPDATE categories SET is_active = 0 WHERE id = :id AND is_active = 1');
$update->execute(['id' => pos_route_id()]);
if ($update->rowCount() === 0) {
    json_response(404, ['message' => 'Category not found.']);
}
json_response(200, ['message' => 'Category deactivated. Products remain available in transaction history.']);