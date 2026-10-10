<?php
declare(strict_types=1);

require_method('POST');
$pdo = database_connection();
$user = require_inventory_manager($pdo);
$input = request_json();
$nameInput = $input['name'] ?? null;
$skuInput = $input['sku'] ?? null;
$barcodeInput = $input['barcode'] ?? null;
$categoryInput = $input['category_id'] ?? null;
$price = pos_int($input['price_cents'] ?? null, 0, 1000000000);
$costPrice = pos_int($input['cost_price_cents'] ?? 0, 0, 1000000000);
$stock = pos_int($input['stock_quantity'] ?? null, 0, 1000000);
$reorderLevel = pos_int($input['reorder_level'] ?? 5, 0, 1000000);
$supplierInput = $input['supplier_id'] ?? null;
$name = is_string($nameInput) ? trim($nameInput) : '';
$errors = [];
if ($name === '' || mb_strlen($name, 'UTF-8') > 120) $errors['name'] = 'Enter a product name up to 120 characters.';
if ($price === null) $errors['price_cents'] = 'Price must be a non-negative amount in cents.';
if ($costPrice === null) $errors['cost_price_cents'] = 'Cost price must be a non-negative amount in cents.';
if ($stock === null) $errors['stock_quantity'] = 'Stock must be a non-negative whole number.';
if ($reorderLevel === null) $errors['reorder_level'] = 'Reorder level must be a non-negative whole number.';
if (!is_string($skuInput) || trim($skuInput) === '' || strlen(trim($skuInput)) > 80) $errors['sku'] = 'Enter a product SKU up to 80 characters.';
if ($barcodeInput !== null && (!is_string($barcodeInput) || strlen(trim($barcodeInput)) > 80)) $errors['barcode'] = 'Barcode must be 80 characters or fewer.';
$imagePath = pos_image_path($input['image_path'] ?? null);
if (($input['image_path'] ?? null) !== null && ($input['image_path'] ?? '') !== '' && $imagePath === null) $errors['image_path'] = 'Use a local absolute path or an HTTPS image URL.';

$categoryId = null;
if ($categoryInput !== null) {
    $categoryId = pos_int($categoryInput, 1, PHP_INT_MAX);
    if ($categoryId === null) $errors['category_id'] = 'Choose a valid category.';
    else {
        $check = $pdo->prepare('SELECT 1 FROM categories WHERE id = :id AND business_id = :business_id AND is_active = 1');
        $check->execute(['id' => $categoryId, 'business_id' => $user['business_id']]);
        if (!$check->fetchColumn()) $errors['category_id'] = 'Choose an active category.';
    }
}
$supplierId = null;
if ($supplierInput !== null && $supplierInput !== '') {
    $supplierId = pos_int($supplierInput, 1, PHP_INT_MAX);
    if ($supplierId === null) $errors['supplier_id'] = 'Choose a valid supplier.';
    else {
        $check = $pdo->prepare('SELECT 1 FROM suppliers WHERE id = :id AND business_id = :business_id AND is_active = 1');
        $check->execute(['id' => $supplierId, 'business_id' => $user['business_id']]);
        if (!$check->fetchColumn()) $errors['supplier_id'] = 'Choose an active supplier.';
    }
}
if ($errors !== []) json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);

try {
    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        'INSERT INTO products (business_id, category_id, supplier_id, sku, barcode, name, description, price_cents, cost_price_cents, stock_quantity, reorder_level, image_path)
         VALUES (:business_id, :category_id, :supplier_id, :sku, :barcode, :name, :description, :price_cents, :cost_price_cents, :stock_quantity, :reorder_level, :image_path)',
    );
    $insert->execute([
        'business_id' => $user['business_id'],
        'category_id' => $categoryId,
        'supplier_id' => $supplierId,
        'sku' => strtoupper(trim($skuInput)),
        'barcode' => is_string($barcodeInput) && trim($barcodeInput) !== '' ? trim($barcodeInput) : null,
        'name' => $name,
        'description' => is_string($input['description'] ?? null) ? mb_substr(trim($input['description']), 0, 1000) : null,
        'price_cents' => $price,
        'cost_price_cents' => $costPrice,
        'stock_quantity' => $stock,
        'reorder_level' => $reorderLevel,
        'image_path' => $imagePath,
    ]);
    $productId = (int) $pdo->lastInsertId();
    if ($stock > 0) {
        $movement = $pdo->prepare(
            "INSERT INTO inventory_movements (business_id, product_id, user_id, movement_type, quantity_change, notes)
             VALUES (:business_id, :product_id, :user_id, 'adjustment', :quantity_change, 'Opening balance')",
        );
        $movement->execute(['business_id' => $user['business_id'], 'product_id' => $productId, 'user_id' => $user['id'], 'quantity_change' => $stock]);
        $movementId = (int) $pdo->lastInsertId();
        $detail = $pdo->prepare(
            "INSERT INTO inventory_movement_details
             (business_id, movement_id, transaction_type, quantity_before, quantity_after, reason)
             VALUES (:business_id, :movement_id, 'opening_balance', 0, :quantity_after, 'Opening balance')",
        );
        $detail->execute(['business_id' => $user['business_id'], 'movement_id' => $movementId, 'quantity_after' => $stock]);
    }
    $audit = $pdo->prepare(
        "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id, details)
         VALUES (:business_id, :actor_id, 'product_created', 'product', :entity_id, :details)",
    );
    $audit->execute(['business_id' => $user['business_id'], 'actor_id' => $user['id'], 'entity_id' => $productId, 'details' => json_encode(['sku' => strtoupper(trim((string) $skuInput)), 'initial_stock' => $stock], JSON_THROW_ON_ERROR)]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode() === '23000') json_response(409, ['message' => 'SKU or barcode is already in use.']);
    throw $error;
}
json_response(201, ['message' => 'Product created.', 'id' => $productId]);