<?php
declare(strict_types=1);

require_method('POST');
require_csrf();
$pdo = database_connection();
enforce_rate_limit($pdo, 'login', 10, 900);
$input = request_json();

$emailInput = $input['email'] ?? null;
$passwordInput = $input['password'] ?? null;
$email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
$password = is_string($passwordInput) ? $passwordInput : '';
$errors = [];
if (!is_string($emailInput) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    $errors['email'] = 'Enter a valid email address.';
}
if (!is_string($passwordInput) || $password === '' || strlen($password) > 72) {
    $errors['password'] = 'Enter your password.';
}
if ($errors !== []) {
    json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);
}

$query = $pdo->prepare('SELECT id, name, email, password, role, created_at FROM users WHERE email = :email');
$query->execute(['email' => $email]);
$record = $query->fetch();

if ($record === false || !password_verify($password, $record['password'])) {
    json_response(401, ['message' => 'Invalid email or password.']);
}

if (password_needs_rehash($record['password'], PASSWORD_DEFAULT)) {
    $update = $pdo->prepare('UPDATE users SET password = :password, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $update->execute(['password' => password_hash($password, PASSWORD_DEFAULT), 'id' => $record['id']]);
}

session_regenerate_id(true);
$now = time();
$rememberInput = $input['remember_me'] ?? false;
$rememberMe = is_bool($rememberInput)
    ? $rememberInput
    : (is_string($rememberInput) && filter_var($rememberInput, FILTER_VALIDATE_BOOL));
$sessionLifetime = $rememberMe ? 2592000 : 1800;
$lastLogin = $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
$lastLogin->execute(['id' => $record['id']]);
$_SESSION['user_id'] = (int) $record['id'];
$_SESSION['last_activity'] = $now;
$_SESSION['auth_expires_at'] = $now + $sessionLifetime;
$csrfToken = new_csrf_token();
refresh_session_cookie($rememberMe ? $now + $sessionLifetime : 0);

unset($record['password']);
json_response(200, [
    'message' => 'Signed in successfully.',
    'user' => [
        'id' => (int) $record['id'],
        'name' => $record['name'],
        'email' => $record['email'],
        'role' => $record['role'],
        'created_at' => $record['created_at'],
    ],
    'csrfToken' => $csrfToken,
]);