<?php
declare(strict_types=1);

require_method('POST');
$pdo = database_connection();
require_admin($pdo);
$input = request_json();
$nameInput = $input['name'] ?? null;
$skuInput = $input['sku'] ?? null;
$barcodeInput = $input['barcode'] ?? null;
$categoryInput = $input['category_id'] ?? null;
$price = pos_int($input['price_cents'] ?? null, 0, 1000000000);
$stock = pos_int($input['stock_quantity'] ?? null, 0, 1000000);
$name = is_string($nameInput) ? trim($nameInput) : '';
$errors = [];
if ($name === '' || mb_strlen($name, 'UTF-8') > 120) $errors['name'] = 'Enter a product name up to 120 characters.';
if ($price === null) $errors['price_cents'] = 'Price must be a non-negative amount in cents.';
if ($stock === null) $errors['stock_quantity'] = 'Stock must be a non-negative whole number.';
if ($skuInput !== null && (!is_string($skuInput) || strlen(trim($skuInput)) > 80)) $errors['sku'] = 'SKU must be 80 characters or fewer.';
if ($barcodeInput !== null && (!is_string($barcodeInput) || strlen(trim($barcodeInput)) > 80)) $errors['barcode'] = 'Barcode must be 80 characters or fewer.';
$imagePath = pos_image_path($input['image_path'] ?? null);
if (($input['image_path'] ?? null) !== null && ($input['image_path'] ?? '') !== '' && $imagePath === null) $errors['image_path'] = 'Use a local absolute path or an HTTPS image URL.';

$categoryId = null;
if ($categoryInput !== null) {
    $categoryId = pos_int($categoryInput, 1, PHP_INT_MAX);
    if ($categoryId === null) $errors['category_id'] = 'Choose a valid category.';
    else {
        $check = $pdo->prepare('SELECT 1 FROM categories WHERE id = :id AND is_active = 1');
        $check->execute(['id' => $categoryId]);
        if (!$check->fetchColumn()) $errors['category_id'] = 'Choose an active category.';
    }
}
if ($errors !== []) json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);

try {
    $insert = $pdo->prepare(
        'INSERT INTO products (category_id, sku, barcode, name, description, price_cents, stock_quantity, image_path)
         VALUES (:category_id, :sku, :barcode, :name, :description, :price_cents, :stock_quantity, :image_path)',
    );
    $insert->execute([
        'category_id' => $categoryId,
        'sku' => is_string($skuInput) && trim($skuInput) !== '' ? trim($skuInput) : null,
        'barcode' => is_string($barcodeInput) && trim($barcodeInput) !== '' ? trim($barcodeInput) : null,
        'name' => $name,
        'description' => is_string($input['description'] ?? null) ? mb_substr(trim($input['description']), 0, 1000) : null,
        'price_cents' => $price,
        'stock_quantity' => $stock,
        'image_path' => $imagePath,
    ]);
} catch (PDOException $error) {
    if ($error->getCode() === '23000') json_response(409, ['message' => 'SKU or barcode is already in use.']);
    throw $error;
}
json_response(201, ['message' => 'Product created.', 'id' => (int) $pdo->lastInsertId()]);