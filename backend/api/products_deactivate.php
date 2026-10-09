<?php
declare(strict_types=1);

require_method('DELETE');
$pdo = database_connection();
require_admin($pdo);
$update = $pdo->prepare('UPDATE products SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND is_active = 1');
$update->execute(['id' => pos_route_id()]);
if ($update->rowCount() === 0) json_response(404, ['message' => 'Product not found.']);
json_response(200, ['message' => 'Product deactivated. Historical sale items are preserved.']);