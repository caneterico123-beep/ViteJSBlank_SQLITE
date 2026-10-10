<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_inventory_operator($pdo);
$summaryQuery = $pdo->prepare(
    "SELECT COUNT(*) AS total_products,
            COALESCE(SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= reorder_level THEN 1 ELSE 0 END), 0) AS low_stock,
            COALESCE(SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
            COALESCE(SUM(stock_quantity * cost_price_cents), 0) AS inventory_value_cents
     FROM products WHERE business_id = :business_id AND is_active = 1",
);
$summaryQuery->execute(['business_id' => $user['business_id']]);
$summary = $summaryQuery->fetch();
$summary['total_products'] = (int) $summary['total_products'];
$summary['low_stock'] = (int) $summary['low_stock'];
$summary['out_of_stock'] = (int) $summary['out_of_stock'];
$summary['inventory_value_cents'] = (int) $summary['inventory_value_cents'];
$count = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE business_id = :business_id AND is_active = 1');
$count->execute(['business_id' => $user['business_id']]);
$summary['total_categories'] = (int) $count->fetchColumn();
$count = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE business_id = :business_id AND is_active = 1');
$count->execute(['business_id' => $user['business_id']]);
$summary['total_suppliers'] = (int) $count->fetchColumn();
json_response(200, ['summary' => $summary]);
