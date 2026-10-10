<?php
declare(strict_types=1);

$pdo = database_connection();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $user = require_authenticated_user($pdo);
    json_response(200, [
        'business' => $user['business'],
        'memberships' => $user['businesses'],
    ]);
}

require_method('PUT');
$user = require_admin($pdo);
$input = request_json();
$allowedIndustries = ['retail', 'grocery', 'pharmacy', 'clinic', 'bakery', 'hotel', 'restaurant', 'rent_a_car', 'computer_store', 'coffee_shop'];
$updates = [];
$parameters = ['business_id' => $user['business_id']];

if (array_key_exists('name', $input)) {
    if (!is_string($input['name']) || trim($input['name']) === '' || mb_strlen(trim($input['name']), 'UTF-8') > 120) {
        json_response(422, ['message' => 'Enter a business name no longer than 120 characters.']);
    }
    $updates[] = 'name = :name';
    $parameters['name'] = trim($input['name']);
}
if (array_key_exists('industry', $input)) {
    if (!is_string($input['industry']) || !in_array($input['industry'], $allowedIndustries, true)) {
        json_response(422, ['message' => 'Choose a supported business type.']);
    }
    if ($input['industry'] !== $user['business']['industry'] && ($input['confirm_industry_change'] ?? false) !== true) {
        json_response(422, ['message' => 'Confirm the business type change. Existing records will be retained.']);
    }
    $updates[] = 'industry = :industry';
    $parameters['industry'] = $input['industry'];
}
foreach (['currency' => '/^[A-Z]{3}$/', 'timezone' => '/^[A-Za-z_]+(?:\/[A-Za-z0-9_+.-]+)*$/'] as $field => $pattern) {
    if (!array_key_exists($field, $input)) {
        continue;
    }
    if (!is_string($input[$field]) || preg_match($pattern, $input[$field]) !== 1) {
        json_response(422, ['message' => 'Enter a valid business ' . $field . '.']);
    }
    if ($field === 'timezone' && !in_array($input[$field], timezone_identifiers_list(), true)) {
        json_response(422, ['message' => 'Choose a valid timezone identifier.']);
    }
    $updates[] = $field . ' = :' . $field;
    $parameters[$field] = $input[$field];
}
if (array_key_exists('settings', $input)) {
    if (!is_array($input['settings'])) {
        json_response(422, ['message' => 'Business settings must be an object.']);
    }
    $updates[] = 'settings = :settings';
    $parameters['settings'] = json_encode($input['settings'], JSON_THROW_ON_ERROR);
}
if ($updates === []) {
    json_response(422, ['message' => 'Provide at least one business setting to update.']);
}
$updates[] = 'updated_at = CURRENT_TIMESTAMP';
$update = $pdo->prepare('UPDATE businesses SET ' . implode(', ', $updates) . ' WHERE id = :business_id');
$update->execute($parameters);
$audit = $pdo->prepare(
    "INSERT INTO inventory_audit_logs (business_id, actor_id, action, entity_type, entity_id, details)
     VALUES (:business_id, :actor_id, 'business_updated', 'business', :entity_id, :details)",
);
$audit->execute([
    'business_id' => $user['business_id'],
    'actor_id' => $user['id'],
    'entity_id' => $user['business_id'],
    'details' => json_encode(['fields' => array_keys(array_diff_key($input, ['confirm_industry_change' => true]))], JSON_THROW_ON_ERROR),
]);
$fresh = require_authenticated_user($pdo);
json_response(200, ['message' => 'Business settings saved.', 'business' => $fresh['business']]);
