<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_pos_enabled_user($pdo);
$sale = pos_authorize_sale($pdo, $user, pos_route_id());
$audit = $pdo->prepare(
    'SELECT sale_audit_log.action, sale_audit_log.reason, sale_audit_log.created_at, users.name AS actor_name
     FROM sale_audit_log JOIN users ON users.id = sale_audit_log.user_id
     WHERE sale_audit_log.sale_id = :sale_id AND sale_audit_log.business_id = :business_id ORDER BY sale_audit_log.id',
);
$audit->execute(['sale_id' => $sale['id'], 'business_id' => $user['business_id']]);
$sale['audit'] = $audit->fetchAll();
json_response(200, ['sale' => $sale]);