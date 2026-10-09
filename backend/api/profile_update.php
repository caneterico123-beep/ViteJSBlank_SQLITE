<?php
declare(strict_types=1);

require_method('PUT');
$pdo = database_connection();
$user = require_authenticated_user($pdo);
$input = request_json();
$nameInput = $input['name'] ?? null;
$emailInput = $input['email'] ?? null;
$name = is_string($nameInput) ? trim($nameInput) : '';
$email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
$errors = [];

if (!is_string($nameInput) || $name === '' || mb_strlen($name, 'UTF-8') > 120) {
    $errors['name'] = 'Enter a name no longer than 120 characters.';
}
if (!is_string($emailInput) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    $errors['email'] = 'Enter a valid email address.';
}
if ($errors !== []) {
    json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);
}

try {
    $update = $pdo->prepare(
        'UPDATE users SET name = :name, email = :email, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
    );
    $update->execute(['name' => $name, 'email' => $email, 'id' => $user['id']]);
} catch (PDOException $error) {
    if ($error->getCode() === '23000') {
        json_response(409, ['message' => 'An account with this email already exists.', 'errors' => ['email' => 'This email is already in use.']]);
    }
    throw $error;
}

$query = $pdo->prepare('SELECT id, name, email, role, created_at FROM users WHERE id = :id');
$query->execute(['id' => $user['id']]);
$profile = $query->fetch();
json_response(200, ['message' => 'Profile updated.', 'profile' => $profile]);