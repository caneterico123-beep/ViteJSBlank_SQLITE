<?php
declare(strict_types=1);

require_method('POST');
require_csrf();
$pdo = database_connection();
enforce_rate_limit($pdo, 'login', 10, 900);
$input = request_json();

$emailInput = $input['email'] ?? null;
$passwordInput = $input['password'] ?? null;
$identifier = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
$isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false && strlen($identifier) <= 254;
$isUsername = preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $identifier) === 1;
$password = is_string($passwordInput) ? $passwordInput : '';
$errors = [];
if (!is_string($emailInput) || (!$isEmail && !$isUsername)) {
    $errors['email'] = 'Enter a valid email address or username.';
}
if (!is_string($passwordInput) || $password === '' || strlen($password) > 72) {
    $errors['password'] = 'Enter your password.';
}
if ($errors !== []) {
    json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);
}

$lookupColumn = $isEmail ? 'email' : 'username';
$query = $pdo->prepare(
    "SELECT id, name, email, password, role, created_at
     FROM users WHERE {$lookupColumn} = :identifier COLLATE NOCASE LIMIT 1",
);
$query->execute(['identifier' => $identifier]);
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
$membership = $pdo->prepare('SELECT business_id FROM business_members WHERE user_id = :user_id AND is_active = 1 ORDER BY id LIMIT 1');
$membership->execute(['user_id' => (int) $record['id']]);
$businessId = $membership->fetchColumn();
if ($businessId === false) {
    unset($_SESSION['user_id'], $_SESSION['last_activity'], $_SESSION['auth_expires_at']);
    json_response(403, ['message' => 'No active business membership is available. Contact your administrator.']);
}
$_SESSION['business_id'] = (int) $businessId;
unset($_SERVER['HTTP_X_BUSINESS_ID']);
$user = require_authenticated_user($pdo);
$csrfToken = new_csrf_token();
refresh_session_cookie($rememberMe ? $now + $sessionLifetime : 0);

json_response(200, [
    'message' => 'Signed in successfully.',
    'user' => $user,
    'csrfToken' => $csrfToken,
]);