<?php
declare(strict_types=1);

$baseUrl = rtrim(getenv('AUTH_TEST_BASE_URL') ?: 'http://localhost:8000', '/');
$cookieJar = tempnam(sys_get_temp_dir(), 'northstar-auth-test-');
if ($cookieJar === false) {
    fwrite(STDERR, "Unable to create a temporary cookie jar.\n");
    exit(1);
}

function api_request(string $baseUrl, string $cookieJar, string $method, string $path, ?array $body = null, ?string $csrfToken = null): array
{
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($csrfToken !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrfToken;
    }

    $curl = curl_init($baseUrl . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }

    $responseBody = curl_exec($curl);
    if ($responseBody === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Could not reach the API: ' . $message);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return [
        'status' => $status,
        'body' => json_decode($responseBody, true) ?? [],
    ];
}

function expect(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . PHP_EOL;
}

try {
    $csrf = api_request($baseUrl, $cookieJar, 'GET', '/api/csrf');
    expect($csrf['status'] === 200 && isset($csrf['body']['csrfToken']), 'issue a session CSRF token');
    $csrfToken = $csrf['body']['csrfToken'];

    $anonymous = api_request($baseUrl, $cookieJar, 'GET', '/api/me');
    expect($anonymous['status'] === 401, 'reject unauthenticated session lookup');

    $email = getenv('AUTH_TEST_EMAIL') ?: 'auth-test-' . bin2hex(random_bytes(6)) . '@example.test';
    $invalid = api_request($baseUrl, $cookieJar, 'POST', '/api/register', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'different',
    ], $csrfToken);
    expect($invalid['status'] === 422 && isset($invalid['body']['errors']['email']), 'return field validation errors');

    $account = [
        'name' => 'Test Account',
        'email' => $email,
        'password' => 'TestPassword123!',
        'password_confirmation' => 'TestPassword123!',
    ];
    $registered = api_request($baseUrl, $cookieJar, 'POST', '/api/register', $account, $csrfToken);
    expect($registered['status'] === 201, 'register a new account');
    expect(!isset($registered['body']['user']['password']), 'never return a password hash');
    $csrfToken = $registered['body']['csrfToken'];
    expect($registered['body']['user']['role'] === 'user', 'new accounts are never administrators');

    $profile = api_request($baseUrl, $cookieJar, 'GET', '/api/profile');
    expect($profile['status'] === 200 && $profile['body']['profile']['email'] === $email, 'read the current user profile');
    $profileUpdate = api_request($baseUrl, $cookieJar, 'PUT', '/api/profile', [
        'name' => 'Updated Test Account',
        'email' => $email,
        'role' => 'admin',
    ], $csrfToken);
    expect($profileUpdate['status'] === 200 && $profileUpdate['body']['profile']['role'] === 'user', 'profile updates cannot grant administrator role');

    $settings = api_request($baseUrl, $cookieJar, 'GET', '/api/settings');
    expect($settings['status'] === 200 && $settings['body']['settings']['theme'] === 'system', 'load default account settings');
    $settingsUpdate = api_request($baseUrl, $cookieJar, 'PUT', '/api/settings', [
        'theme' => 'light',
        'email_notifications' => false,
    ], $csrfToken);
    expect($settingsUpdate['status'] === 200 && $settingsUpdate['body']['settings']['email_notifications'] === false, 'update own settings');

    $adminStats = api_request($baseUrl, $cookieJar, 'GET', '/api/dashboard/stats');
    $adminAnalytics = api_request($baseUrl, $cookieJar, 'GET', '/api/dashboard/registrations');
    $adminUsers = api_request($baseUrl, $cookieJar, 'GET', '/api/users');
    expect($adminStats['status'] === 403 && $adminAnalytics['status'] === 403 && $adminUsers['status'] === 403, 'deny administrator endpoints to regular users');

    require_once __DIR__ . '/../config/database.php';
    $testDatabase = database_connection();
    try {
        $promoteTestUser = $testDatabase->prepare("UPDATE users SET role = 'admin' WHERE email = :email");
        $promoteTestUser->execute(['email' => $email]);

        $adminStats = api_request($baseUrl, $cookieJar, 'GET', '/api/dashboard/stats');
        $adminAnalytics = api_request($baseUrl, $cookieJar, 'GET', '/api/dashboard/registrations');
        $adminUsers = api_request($baseUrl, $cookieJar, 'GET', '/api/users?page=1&per_page=5&search=Test');
        expect($adminStats['status'] === 200 && $adminStats['body']['stats']['totalUsers'] > 0, 'return database-backed admin statistics');
        expect($adminAnalytics['status'] === 200 && count($adminAnalytics['body']['registrations']) === 30, 'return 30 days of registration analytics');
        expect($adminUsers['status'] === 200 && $adminUsers['body']['pagination']['total'] > 0, 'search and paginate users for administrators');
        expect(!isset($adminUsers['body']['users'][0]['password']), 'never expose credentials in user listings');
    } finally {
        $restoreTestUser = $testDatabase->prepare("UPDATE users SET role = 'user' WHERE email = :email");
        $restoreTestUser->execute(['email' => $email]);
    }

    $duplicate = api_request($baseUrl, $cookieJar, 'POST', '/api/register', $account, $csrfToken);
    expect($duplicate['status'] === 409, 'reject duplicate email addresses');

    $wrongPassword = api_request($baseUrl, $cookieJar, 'POST', '/api/login', [
        'email' => $email,
        'password' => 'IncorrectPassword123!',
    ], $csrfToken);
    $unknownEmail = api_request($baseUrl, $cookieJar, 'POST', '/api/login', [
        'email' => 'unknown-' . bin2hex(random_bytes(4)) . '@example.test',
        'password' => 'IncorrectPassword123!',
    ], $csrfToken);
    expect($wrongPassword['status'] === 401 && $unknownEmail['status'] === 401, 'reject invalid credentials');
    expect($wrongPassword['body']['message'] === $unknownEmail['body']['message'], 'use a generic credential error');

    $login = api_request($baseUrl, $cookieJar, 'POST', '/api/login', [
        'email' => $email,
        'password' => $account['password'],
        'remember_me' => false,
    ], $csrfToken);
    expect($login['status'] === 200 && isset($login['body']['user']['id']), 'log in and create a session');
    expect(isset($login['body']['csrfToken']), 'rotate the CSRF token after login');

    $csrfToken = $login['body']['csrfToken'];
    $me = api_request($baseUrl, $cookieJar, 'GET', '/api/me');
    expect($me['status'] === 200 && $me['body']['user']['email'] === $email, 'read the authenticated user');
    expect(!isset($me['body']['user']['password']), 'sanitize the current-user response');

    $csrfRejected = api_request($baseUrl, $cookieJar, 'POST', '/api/logout', [], 'invalid-token');
    expect($csrfRejected['status'] === 419, 'reject state changes without a valid CSRF token');

    $logout = api_request($baseUrl, $cookieJar, 'POST', '/api/logout', [], $csrfToken);
    expect($logout['status'] === 200, 'log out and clear the session');
    $afterLogout = api_request($baseUrl, $cookieJar, 'GET', '/api/me');
    expect($afterLogout['status'] === 401, 'reject the destroyed session');
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    @unlink($cookieJar);
    exit(1);
} finally {
    @unlink($cookieJar);
}