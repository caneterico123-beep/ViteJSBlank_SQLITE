<?php
declare(strict_types=1);

require_method('POST');
require_csrf();
$pdo = database_connection();
enforce_rate_limit($pdo, 'register', 10, 3600);
$input = request_json();

$nameInput = $input['name'] ?? null;
$emailInput = $input['email'] ?? null;
$passwordInput = $input['password'] ?? null;
$confirmationInput = $input['password_confirmation'] ?? null;
$businessNameInput = $input['business_name'] ?? ($input['name'] ?? null);
$industryInput = $input['industry'] ?? 'retail';
$name = is_string($nameInput) ? trim($nameInput) : '';
$businessName = is_string($businessNameInput) ? trim($businessNameInput) : '';
$industry = is_string($industryInput) ? trim($industryInput) : '';
$email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
$password = is_string($passwordInput) ? $passwordInput : '';
$confirmation = is_string($confirmationInput) ? $confirmationInput : '';
$errors = [];

if ($businessName === '' || mb_strlen($businessName, 'UTF-8') > 120) {
    $errors['business_name'] = 'Enter a business name no longer than 120 characters.';
}
if (!in_array($industry, ['retail', 'grocery', 'pharmacy', 'clinic', 'bakery', 'hotel', 'restaurant', 'rent_a_car', 'computer_store', 'coffee_shop'], true)) {
    $errors['industry'] = 'Choose a supported business type.';
}
if (!is_string($nameInput) || $name === '' || mb_strlen($name, 'UTF-8') > 120) {
    $errors['name'] = 'Enter a name no longer than 120 characters.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    $errors['email'] = 'Enter a valid email address.';
}
if (!is_string($passwordInput) || mb_strlen($password, 'UTF-8') < 8 || strlen($password) > 72) {
    $errors['password'] = 'Use at least 8 characters and no more than 72 bytes.';
}
if (!is_string($confirmationInput) || $password !== $confirmation) {
    $errors['password_confirmation'] = 'Passwords do not match.';
}
if ($errors !== []) {
    json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);
}

try {
    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        'INSERT INTO users (name, email, password) VALUES (:name, :email, :password)',
    );
    $insert->execute([
        'name' => $name,
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $pdo->lastInsertId();
    $settings = $pdo->prepare('INSERT INTO user_settings (user_id) VALUES (:user_id)');
    $settings->execute(['user_id' => $userId]);
    $businessInsert = $pdo->prepare(
        "INSERT INTO businesses (name, industry, currency, timezone) VALUES (:name, :industry, 'PHP', 'Asia/Manila')",
    );
    $businessInsert->execute(['name' => $businessName, 'industry' => $industry]);
    $businessId = (int) $pdo->lastInsertId();
    $memberInsert = $pdo->prepare(
        "INSERT INTO business_members (business_id, user_id, role) VALUES (:business_id, :user_id, 'owner')",
    );
    $memberInsert->execute(['business_id' => $businessId, 'user_id' => $userId]);
    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($error->getCode() === '23000') {
        json_response(409, ['message' => 'An account with this email already exists.']);
    }
    throw $error;
}

session_regenerate_id(true);
$now = time();
$_SESSION['user_id'] = $userId;
$_SESSION['last_activity'] = $now;
$_SESSION['auth_expires_at'] = $now + 1800;
$_SESSION['business_id'] = $businessId;
unset($_SERVER['HTTP_X_BUSINESS_ID']);
$user = require_authenticated_user($pdo);
$csrfToken = new_csrf_token();
json_response(201, [
    'message' => 'Business workspace created successfully.',
    'user' => $user,
    'csrfToken' => $csrfToken,
]);