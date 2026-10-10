<?php
declare(strict_types=1);

if (getenv('APP_ENV') !== 'development') {
    fwrite(STDERR, "POS sample data can only be seeded when APP_ENV=development.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
$pdo = database_connection();
$membershipQuery = $pdo->query("SELECT user_id, business_id FROM business_members WHERE role IN ('owner', 'admin') AND is_active = 1 ORDER BY id LIMIT 1");
$membership = $membershipQuery->fetch();
if ($membership === false) {
    fwrite(STDERR, "Create a business owner or administrator before seeding the development catalog.\n");
    exit(1);
}
$adminId = (int) $membership['user_id'];
$businessId = (int) $membership['business_id'];
$samples = [
    ['Food', 'DEV-FOOD-001', '900000000001', 'Sample sandwich', 850, 20],
    ['Drinks', 'DEV-DRINK-001', '900000000002', 'Sample bottled water', 200, 36],
    ['Snacks', 'DEV-SNACK-001', '900000000003', 'Sample snack pack', 325, 18],
];

$pdo->beginTransaction();
try {
    $findCategory = $pdo->prepare('SELECT id FROM categories WHERE business_id = :business_id AND name = :name COLLATE NOCASE');
    $createCategory = $pdo->prepare('INSERT INTO categories (business_id, name) VALUES (:business_id, :name)');
    $findProduct = $pdo->prepare('SELECT id FROM products WHERE business_id = :business_id AND sku = :sku');
    $createProduct = $pdo->prepare(
        'INSERT INTO products (business_id, category_id, sku, barcode, name, price_cents, stock_quantity)
         VALUES (:business_id, :category_id, :sku, :barcode, :name, :price_cents, :stock_quantity)',
    );
    $recordStock = $pdo->prepare(
        "INSERT INTO inventory_movements (business_id, product_id, user_id, movement_type, quantity_change, notes)
         VALUES (:business_id, :product_id, :user_id, 'adjustment', :quantity_change, 'Development sample stock')",
    );
    $recordDetail = $pdo->prepare(
        "INSERT INTO inventory_movement_details (business_id, movement_id, transaction_type, quantity_before, quantity_after, reason)
         VALUES (:business_id, :movement_id, 'opening_balance', 0, :quantity_after, 'Development sample stock')",
    );
    foreach ($samples as [$categoryName, $sku, $barcode, $name, $price, $stock]) {
        $findCategory->execute(['name' => $categoryName, 'business_id' => $businessId]);
        $categoryId = $findCategory->fetchColumn();
        if ($categoryId === false) {
            $createCategory->execute(['name' => $categoryName, 'business_id' => $businessId]);
            $categoryId = $pdo->lastInsertId();
        }
        $findProduct->execute(['sku' => $sku, 'business_id' => $businessId]);
        if ($findProduct->fetchColumn() === false) {
            $createProduct->execute([
                'business_id' => $businessId,
                'category_id' => $categoryId,
                'sku' => $sku,
                'barcode' => $barcode,
                'name' => $name,
                'price_cents' => $price,
                'stock_quantity' => $stock,
            ]);
            $recordStock->execute([
                'business_id' => $businessId,
                'product_id' => $pdo->lastInsertId(),
                'user_id' => $adminId,
                'quantity_change' => $stock,
            ]);
            $movementId = (int) $pdo->lastInsertId();
            $recordDetail->execute(['business_id' => $businessId, 'movement_id' => $movementId, 'quantity_after' => $stock]);
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

fwrite(STDOUT, "Development-only sample catalog is ready (DEV-* SKUs).\n");
