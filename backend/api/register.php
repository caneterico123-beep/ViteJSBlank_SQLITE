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
$name = is_string($nameInput) ? trim($nameInput) : '';
$email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
$password = is_string($passwordInput) ? $passwordInput : '';
$confirmation = is_string($confirmationInput) ? $confirmationInput : '';
$errors = [];

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

$query = $pdo->prepare('SELECT id, name, email, created_at FROM users WHERE id = :id');
$query->execute(['id' => $userId]);
json_response(201, [
    'message' => 'Account created. You can now sign in.',
    'user' => $query->fetch(),
]);