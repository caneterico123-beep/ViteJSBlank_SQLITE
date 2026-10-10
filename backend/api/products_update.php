<?php
declare(strict_types=1);

require_method('PUT');
$pdo = database_connection();
$user = require_inventory_manager($pdo);
$input = request_json();
$id = pos_route_id();
$existingProductQuery = $pdo->prepare('SELECT category_id, supplier_id FROM products WHERE id = :id AND business_id = :business_id AND is_active = 1');
$existingProductQuery->execute(['id' => $id, 'business_id' => $user['business_id']]);
$existingLinks = $existingProductQuery->fetch();
$existingProductQuery->closeCursor();
if ($existingLinks === false) json_response(404, ['message' => 'Product not found.']);
$nameInput = $input['name'] ?? null;
$skuInput = $input['sku'] ?? null;
$barcodeInput = $input['barcode'] ?? null;
$categoryInput = $input['category_id'] ?? null;
$price = pos_int($input['price_cents'] ?? null, 0, 1000000000);
$costPrice = pos_int($input['cost_price_cents'] ?? 0, 0, 1000000000);
$reorderLevel = pos_int($input['reorder_level'] ?? 5, 0, 1000000);
$supplierInput = $input['supplier_id'] ?? null;
$name = is_string($nameInput) ? trim($nameInput) : '';
$errors = [];
if ($name === '' || mb_strlen($name, 'UTF-8') > 120) $errors['name'] = 'Enter a product name up to 120 characters.';
if ($price === null) $errors['price_cents'] = 'Price must be a non-negative amount in cents.';
if ($costPrice === null) $errors['cost_price_cents'] = 'Cost price must be a non-negative amount in cents.';
if ($reorderLevel === null) $errors['reorder_level'] = 'Reorder level must be a non-negative whole number.';
if (array_key_exists('stock_quantity', $input)) $errors['stock_quantity'] = 'Use an inventory operation to change stock.';
if (!is_string($skuInput) || trim($skuInput) === '' || strlen(trim($skuInput)) > 80) $errors['sku'] = 'Enter a product SKU up to 80 characters.';
if ($barcodeInput !== null && (!is_string($barcodeInput) || strlen(trim($barcodeInput)) > 80)) $errors['barcode'] = 'Barcode must be 80 characters or fewer.';
$imagePath = pos_image_path($input['image_path'] ?? null);
if (($input['image_path'] ?? null) !== null && ($input['image_path'] ?? '') !== '' && $imagePath === null) $errors['image_path'] = 'Use a local absolute path or an HTTPS image URL.';

$categoryId = null;
if ($categoryInput !== null) {
    $categoryId = pos_int($categoryInput, 1, PHP_INT_MAX);
    if ($categoryId === null) $errors['category_id'] = 'Choose a valid category.';
    else {
        $check = $pdo->prepare('SELECT is_active FROM categories WHERE id = :id AND business_id = :business_id');
        $check->execute(['id' => $categoryId, 'business_id' => $user['business_id']]);
        $categoryActive = $check->fetchColumn();
        if ($categoryActive === false || (!(bool) $categoryActive && (int) $existingLinks['category_id'] !== $categoryId)) {
            $errors['category_id'] = 'Choose an active category.';
        }
    }
}
$supplierId = null;
if ($supplierInput !== null && $supplierInput !== '') {
    $supplierId = pos_int($supplierInput, 1, PHP_INT_MAX);
    if ($supplierId === null) $errors['supplier_id'] = 'Choose a valid supplier.';
    else {
        $check = $pdo->prepare('SELECT is_active FROM suppliers WHERE id = :id AND business_id = :business_id');
        $check->execute(['id' => $supplierId, 'business_id' => $user['business_id']]);
        $supplierActive = $check->fetchColumn();
        if ($supplierActive === false || (!(bool) $supplierActive && (int) $existingLinks['supplier_id'] !== $supplierId)) {
            $errors['supplier_id'] = 'Choose an active supplier.';
        }
    }
}
if ($errors !== []) json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);

try {
    $pdo->beginTransaction();
    $current = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id AND business_id = :business_id AND is_active = 1');
    $current->execute(['id' => $id, 'business_id' => $user['business_id']]);
    $currentStock = $current->fetchColumn();
    $current->closeCursor();
    if ($currentStock === false) {
        $pdo->rollBack();
        json_response(404, ['message' => 'Product not found.']);
    }
    $update = $pdo->prepare(
        'UPDATE products SET category_id = :category_id, supplier_id = :supplier_id, sku = :sku, barcode = :barcode,
         name = :name, description = :description, price_cents = :price_cents, cost_price_cents = :cost_price_cents,
         reorder_level = :reorder_level, image_path = :image_path, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND business_id = :business_id AND is_active = 1',
    );
    $update->execute([
        'category_id' => $categoryId,
        'supplier_id' => $supplierId,
        'sku' => strtoupper(trim($skuInput)),
        'barcode' => is_string($barcodeInput) && trim($barcodeInput) !== '' ? trim($barcodeInput) : null,
        'name' => $name,
        'description' => is_string($input['description'] ?? null) ? mb_substr(trim($input['description']), 0, 1000) : null,
        'price_cents' => $price,
        'cost_price_cents' => $costPrice,
        'reorder_level' => $reorderLevel,
        'image_path' => $imagePath,
        'id' => $id,
        'business_id' => $user['business_id'],
    ]);
    $audit = $pdo->prepare(
        "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id, details)
         VALUES (:business_id, :actor_id, 'product_updated', 'product', :entity_id, :details)",
    );
    $audit->execute(['business_id' => $user['business_id'], 'actor_id' => $user['id'], 'entity_id' => $id, 'details' => json_encode(['stock_unchanged' => (int) $currentStock], JSON_THROW_ON_ERROR)]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode() === '23000') json_response(409, ['message' => 'SKU or barcode is already in use.']);
    throw $error;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
json_response(200, ['message' => 'Product updated.']);