<?php
declare(strict_types=1);

$pdo = database_connection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_method('GET');
    $user = require_pharmacy_user($pdo, ['owner', 'admin', 'manager', 'pharmacist', 'inventory_staff']);
    $searchInput = $_GET['search'] ?? '';
    if (!is_string($searchInput) || mb_strlen($searchInput, 'UTF-8') > 100) {
        json_response(422, ['message' => 'Search must be 100 characters or fewer.']);
    }
    $where = ['products.business_id = :business_id', 'products.is_active = 1'];
    $params = ['business_id' => (int) $user['business_id']];
    $search = trim($searchInput);
    if ($search !== '') {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
        $where[] = "(products.name LIKE :search ESCAPE '!' OR products.sku LIKE :search ESCAPE '!'
          OR products.barcode LIKE :search ESCAPE '!' OR details.generic_name LIKE :search ESCAPE '!'
          OR details.brand_name LIKE :search ESCAPE '!' OR details.active_ingredients LIKE :search ESCAPE '!')";
        $params['search'] = '%' . $escaped . '%';
    }
    $query = $pdo->prepare(
        "SELECT products.id, products.name, products.sku, products.barcode, products.description,
                products.category_id, categories.name AS category_name, products.supplier_id,
                suppliers.name AS supplier_name, products.price_cents, products.cost_price_cents,
                products.stock_quantity, products.reorder_level, details.id AS pharmacy_detail_id,
                details.generic_name, details.brand_name,
                details.manufacturer, details.active_ingredients, details.strength, details.dosage_form,
                details.route, details.package_size, details.unit_of_measure, details.prescription_required,
                details.controlled_classification, details.storage_instructions, details.cold_chain,
                details.batch_tracking, details.warnings,
                COALESCE(SUM(CASE WHEN batches.status = 'available' AND batches.expires_on > :today
                                  THEN batches.available_quantity ELSE 0 END), 0) AS eligible_batch_stock,
                MIN(CASE WHEN batches.status = 'available' AND batches.available_quantity > 0
                               AND batches.expires_on > :today
                         THEN batches.expires_on END) AS nearest_expiry
         FROM products
         LEFT JOIN pharmacy_product_details AS details ON details.product_id = products.id AND details.business_id = products.business_id
         LEFT JOIN categories ON categories.id = products.category_id AND categories.business_id = products.business_id
         LEFT JOIN suppliers ON suppliers.id = products.supplier_id AND suppliers.business_id = products.business_id
         LEFT JOIN medicine_batches AS batches ON batches.product_id = products.id AND batches.business_id = products.business_id
         WHERE " . implode(' AND ', $where) . "
         GROUP BY products.id ORDER BY products.name COLLATE NOCASE LIMIT 500",
    );
    $today = (new DateTimeImmutable('now', new DateTimeZone($user['business']['timezone'])))->format('Y-m-d');
    $params['today'] = $today;
    $query->execute($params);
    $medicines = $query->fetchAll();
    foreach ($medicines as &$medicine) {
        foreach (['id', 'price_cents', 'cost_price_cents', 'stock_quantity', 'reorder_level', 'eligible_batch_stock'] as $field) {
            $medicine[$field] = (int) $medicine[$field];
        }
        if ($medicine['pharmacy_detail_id'] !== null) $medicine['pharmacy_detail_id'] = (int) $medicine['pharmacy_detail_id'];
        foreach (['prescription_required', 'cold_chain', 'batch_tracking'] as $field) {
            $medicine[$field] = $medicine[$field] === null ? false : (bool) $medicine[$field];
        }
    }
    unset($medicine);
    json_response(200, ['medicines' => $medicines]);
}

if (!in_array($method, ['POST', 'PUT'], true)) {
    json_response(405, ['message' => 'Method not allowed.']);
}
require_role($pdo, ['owner', 'admin', 'manager']);
$user = require_pharmacy_user($pdo, ['owner', 'admin', 'manager']);
$input = request_json();
$productId = pos_int($input['product_id'] ?? null, 1, PHP_INT_MAX);
$errors = [];
if ($productId === null) $errors['product_id'] = 'Choose a valid product.';
$fields = [
    'generic_name' => [200, false],
    'brand_name' => [200, false],
    'manufacturer' => [200, false],
    'active_ingredients' => [1000, false],
    'strength' => [100, false],
    'dosage_form' => [80, false],
    'route' => [80, false],
    'package_size' => [80, false],
    'unit_of_measure' => [40, true],
    'controlled_classification' => [80, true],
    'storage_instructions' => [500, false],
    'warnings' => [1000, false],
];
$values = [];
foreach ($fields as $field => [$maxLength, $required]) {
    $value = $input[$field] ?? ($required ? null : '');
    if (!is_string($value) || mb_strlen(trim($value), 'UTF-8') > $maxLength || ($required && trim($value) === '')) {
        $errors[$field] = $required ? 'Enter a valid value.' : 'Text is too long or invalid.';
    } else {
        $values[$field] = trim($value);
    }
}
foreach (['prescription_required', 'cold_chain', 'batch_tracking'] as $field) {
    $value = $input[$field] ?? ($field === 'batch_tracking');
    if (!in_array($value, [true, false, 0, 1], true)) {
        $errors[$field] = 'Choose a valid on/off value.';
    } else {
        $values[$field] = (int) (bool) $value;
    }
}
if ($errors !== []) json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);

$product = $pdo->prepare('SELECT id FROM products WHERE id = :id AND business_id = :business_id');
$product->execute(['id' => $productId, 'business_id' => (int) $user['business_id']]);
if (!$product->fetchColumn()) json_response(404, ['message' => 'Product not found in this pharmacy.']);

try {
    if ($method === 'POST') {
        $insert = $pdo->prepare(
            'INSERT INTO pharmacy_product_details (
                business_id, product_id, generic_name, brand_name, manufacturer, active_ingredients,
                strength, dosage_form, route, package_size, unit_of_measure, prescription_required,
                controlled_classification, storage_instructions, cold_chain, batch_tracking, warnings
             ) VALUES (
                :business_id, :product_id, :generic_name, :brand_name, :manufacturer, :active_ingredients,
                :strength, :dosage_form, :route, :package_size, :unit_of_measure, :prescription_required,
                :controlled_classification, :storage_instructions, :cold_chain, :batch_tracking, :warnings
             )',
        );
    } else {
        $detailId = filter_var($_GET['route_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($detailId === false || $detailId === null) json_response(400, ['message' => 'A valid medicine detail ID is required.']);
        $insert = $pdo->prepare(
            'UPDATE pharmacy_product_details SET product_id = :product_id, generic_name = :generic_name,
                brand_name = :brand_name, manufacturer = :manufacturer, active_ingredients = :active_ingredients,
                strength = :strength, dosage_form = :dosage_form, route = :route, package_size = :package_size,
                unit_of_measure = :unit_of_measure, prescription_required = :prescription_required,
                controlled_classification = :controlled_classification, storage_instructions = :storage_instructions,
                cold_chain = :cold_chain, batch_tracking = :batch_tracking, warnings = :warnings,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :detail_id AND business_id = :business_id',
        );
        $values['detail_id'] = $detailId;
    }
    $pdo->beginTransaction();
    $values['business_id'] = (int) $user['business_id'];
    $values['product_id'] = $productId;
    $insert->execute($values);
    if ($method === 'PUT' && $insert->rowCount() !== 1) {
        $pdo->rollBack();
        json_response(404, ['message' => 'Medicine details not found in this pharmacy.']);
    }
    $detailId = $method === 'POST' ? (int) $pdo->lastInsertId() : $detailId;
    $audit = $pdo->prepare(
        "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id, details)
         VALUES (:business_id, :actor_id, :action, 'pharmacy_product', :entity_id, :details)",
    );
    $audit->execute([
        'business_id' => (int) $user['business_id'],
        'actor_id' => (int) $user['id'],
        'action' => $method === 'POST' ? 'pharmacy_medicine_details_created' : 'pharmacy_medicine_details_updated',
        'entity_id' => $detailId,
        'details' => json_encode(['product_id' => $productId], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode() === '23000') json_response(409, ['message' => 'Pharmacy details already exist for this product.']);
    throw $error;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

json_response($method === 'POST' ? 201 : 200, ['message' => 'Pharmacy medicine details saved.', 'id' => $detailId]);
