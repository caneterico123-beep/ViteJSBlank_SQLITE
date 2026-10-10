<?php
declare(strict_types=1);

$baseUrl = rtrim(getenv('AUTH_TEST_BASE_URL') ?: 'http://localhost:8000', '/');
$cookieJar = tempnam(sys_get_temp_dir(), 'northstar-auth-test-');
if ($cookieJar === false) {
    fwrite(STDERR, "Unable to create a temporary cookie jar.\n");
    exit(1);
}

function api_request(string $baseUrl, string $cookieJar, string $method, string $path, ?array $body = null, ?string $csrfToken = null, ?int $businessId = null): array
{
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($csrfToken !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrfToken;
    }
    if ($businessId !== null) {
        $headers[] = 'X-Business-Id: ' . $businessId;
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
    expect($registered['body']['user']['role'] === 'owner' && $registered['body']['user']['business']['industry'] === 'retail', 'new accounts create an owner membership and retail business');
    expect($registered['body']['user']['business']['currency'] === 'PHP' && $registered['body']['user']['business']['timezone'] === 'Asia/Manila', 'apply default business currency and timezone');
    $businessId = (int) $registered['body']['user']['business_id'];
    $ownerUserId = (int) $registered['body']['user']['id'];
    require_once __DIR__ . '/../config/database.php';
    $testDatabase = database_connection();

    $businessSettings = api_request($baseUrl, $cookieJar, 'GET', '/api/business');
    expect($businessSettings['status'] === 200 && $businessSettings['body']['business']['industry'] === 'retail', 'load the authenticated business configuration');
    $industryChange = api_request($baseUrl, $cookieJar, 'PUT', '/api/business', [
        'industry' => 'grocery',
    ], $csrfToken);
    expect($industryChange['status'] === 422, 'require confirmation before changing business industry');
    $industryChange = api_request($baseUrl, $cookieJar, 'PUT', '/api/business', [
        'industry' => 'grocery',
        'confirm_industry_change' => true,
        'currency' => 'PHP',
        'timezone' => 'Asia/Manila',
    ], $csrfToken);
    expect($industryChange['status'] === 200 && $industryChange['body']['business']['industry'] === 'grocery', 'update business industry and configuration');
    $clinicSwitch = api_request($baseUrl, $cookieJar, 'PUT', '/api/business', [
        'industry' => 'clinic',
        'confirm_industry_change' => true,
    ], $csrfToken);
    $clinicPos = api_request($baseUrl, $cookieJar, 'GET', '/api/pos/config');
    expect($clinicSwitch['status'] === 200 && $clinicPos['status'] === 403, 'enforce the industry-specific POS module restriction in the API');
    api_request($baseUrl, $cookieJar, 'PUT', '/api/business', [
        'industry' => 'grocery',
        'confirm_industry_change' => true,
    ], $csrfToken);
    $csrfToken = api_request($baseUrl, $cookieJar, 'GET', '/api/csrf')['body']['csrfToken'];
    $foreignBusiness = $testDatabase->prepare("INSERT INTO businesses (name, industry) VALUES ('Foreign workspace', 'retail')");
    $foreignBusiness->execute();
    $foreignBusinessId = (int) $testDatabase->lastInsertId();
    $foreignWorkspace = api_request($baseUrl, $cookieJar, 'GET', '/api/me', null, null, $foreignBusinessId);
    expect($foreignWorkspace['status'] === 403, 'reject switching to a business without active membership');
    $categoryName = 'Tenant category ' . bin2hex(random_bytes(3));
    $tenantCategory = api_request($baseUrl, $cookieJar, 'POST', '/api/categories', ['name' => $categoryName], $csrfToken);
    expect($tenantCategory['status'] === 201, 'create a category in the owner business');
    $secondBusiness = $testDatabase->prepare("INSERT INTO businesses (name, industry) VALUES ('Second workspace', 'retail')");
    $secondBusiness->execute();
    $secondBusinessId = (int) $testDatabase->lastInsertId();
    $secondMembership = $testDatabase->prepare("INSERT INTO business_members (business_id, user_id, role) VALUES (:business_id, :user_id, 'owner')");
    $secondMembership->execute(['business_id' => $secondBusinessId, 'user_id' => $ownerUserId]);
    $secondCategories = api_request($baseUrl, $cookieJar, 'GET', '/api/categories', null, null, $secondBusinessId);
    expect($secondCategories['status'] === 200 && count($secondCategories['body']['categories']) === 0, 'isolate category reads between memberships');
    $tenantProducts = $testDatabase->prepare('INSERT INTO products (business_id, sku, name, price_cents) VALUES (:business_id, :sku, :name, 100)');
    $tenantProducts->execute(['business_id' => $businessId, 'sku' => 'TENANT-A-' . bin2hex(random_bytes(3)), 'name' => 'Business A product']);
    $tenantProducts->execute(['business_id' => $secondBusinessId, 'sku' => 'TENANT-B-' . bin2hex(random_bytes(3)), 'name' => 'Business B product']);
    $productsBusinessB = api_request($baseUrl, $cookieJar, 'GET', '/api/products', null, null, $secondBusinessId);
    expect($productsBusinessB['status'] === 200 && count($productsBusinessB['body']['products']) === 1
        && $productsBusinessB['body']['products'][0]['name'] === 'Business B product', 'scope product catalog reads to selected business');
    api_request($baseUrl, $cookieJar, 'GET', '/api/me', null, null, $businessId);
    $productsBusinessA = api_request($baseUrl, $cookieJar, 'GET', '/api/products');
    expect($productsBusinessA['status'] === 200 && count($productsBusinessA['body']['products']) === 1
        && $productsBusinessA['body']['products'][0]['name'] === 'Business A product', 'prevent cross-business product disclosure');
    $staffEmail = 'team-' . bin2hex(random_bytes(5)) . '@example.test';
    $staffInsert = $testDatabase->prepare("INSERT INTO users (name, email, password) VALUES ('Team Member', :email, :password)");
    $staffInsert->execute(['email' => $staffEmail, 'password' => password_hash('TeamPassword123!', PASSWORD_DEFAULT)]);
    $addedMember = api_request($baseUrl, $cookieJar, 'POST', '/api/business/members', [
        'email' => $staffEmail,
        'role' => 'cashier',
    ], $csrfToken);
    if ($addedMember['status'] !== 201) {
        fwrite(STDERR, 'Business member API response: ' . json_encode($addedMember, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    expect($addedMember['status'] === 201 && $addedMember['body']['role'] === 'cashier', 'add a registered account to the active business with a business role');
    $duplicateMember = api_request($baseUrl, $cookieJar, 'POST', '/api/business/members', [
        'email' => $staffEmail,
        'role' => 'cashier',
    ], $csrfToken);
    expect($duplicateMember['status'] === 409, 'prevent duplicate active business memberships');
    $memberList = api_request($baseUrl, $cookieJar, 'GET', '/api/business/members');
    $staffMembership = array_values(array_filter($memberList['body']['members'] ?? [], static fn (array $member): bool => $member['email'] === $staffEmail))[0] ?? null;
    expect($memberList['status'] === 200 && $staffMembership !== null, 'list only members of the current business');
    expect(in_array('owner', $memberList['body']['assignable_roles'] ?? [], true)
        && in_array('admin', $memberList['body']['assignable_roles'] ?? [], true),
        'return privileged roles only to the business owner');
    $membersBusinessB = api_request($baseUrl, $cookieJar, 'GET', '/api/business/members', null, null, $secondBusinessId);
    expect($membersBusinessB['status'] === 200
        && count(array_filter($membersBusinessB['body']['members'] ?? [], static fn (array $member): bool => $member['email'] === $staffEmail)) === 0,
        'keep member listings isolated to the selected business');

    $elevationTargetEmail = 'elevation-' . bin2hex(random_bytes(5)) . '@example.test';
    $elevationTarget = $testDatabase->prepare("INSERT INTO users (name, email, password) VALUES ('Role Test Target', :email, :password)");
    $elevationTarget->execute(['email' => $elevationTargetEmail, 'password' => password_hash('RoleTargetPassword123!', PASSWORD_DEFAULT)]);
    $demoteOwner = $testDatabase->prepare("UPDATE business_members SET role = 'admin' WHERE user_id = :user_id AND business_id = :business_id");
    $demoteOwner->execute(['user_id' => $ownerUserId, 'business_id' => $businessId]);
    $adminRoles = api_request($baseUrl, $cookieJar, 'GET', '/api/business/members', null, null, $businessId);
    expect($adminRoles['status'] === 200 && !in_array('owner', $adminRoles['body']['assignable_roles'] ?? [], true)
        && !in_array('admin', $adminRoles['body']['assignable_roles'] ?? [], true),
        'exclude privileged assignments from administrator role options');
    $unauthorizedElevation = api_request($baseUrl, $cookieJar, 'POST', '/api/business/members', [
        'email' => $elevationTargetEmail,
        'role' => 'admin',
    ], $csrfToken, $businessId);
    expect($unauthorizedElevation['status'] === 403, 'block administrator attempts to grant privileged roles');
    $restoreOwner = $testDatabase->prepare("UPDATE business_members SET role = 'owner' WHERE user_id = :user_id AND business_id = :business_id");
    $restoreOwner->execute(['user_id' => $ownerUserId, 'business_id' => $businessId]);

    $memberRole = api_request($baseUrl, $cookieJar, 'PUT', '/api/business/members/' . $staffMembership['membership_id'], ['role' => 'manager'], $csrfToken, $businessId);
    expect($memberRole['status'] === 200, 'update a business-specific member role');
    $memberDeactivate = api_request($baseUrl, $cookieJar, 'DELETE', '/api/business/members/' . $staffMembership['membership_id'], ['confirm' => true], $csrfToken, $businessId);
    expect($memberDeactivate['status'] === 200, 'deactivate business membership without deleting the account');

    $profile = api_request($baseUrl, $cookieJar, 'GET', '/api/profile');
    expect($profile['status'] === 200 && $profile['body']['profile']['email'] === $email, 'read the current user profile');
    $profileUpdate = api_request($baseUrl, $cookieJar, 'PUT', '/api/profile', [
        'name' => 'Updated Test Account',
        'email' => $email,
        'role' => 'admin',
    ], $csrfToken);
    expect($profileUpdate['status'] === 200 && $profileUpdate['body']['profile']['role'] === 'owner', 'profile updates cannot change the active business role');

    $settings = api_request($baseUrl, $cookieJar, 'GET', '/api/settings');
    expect($settings['status'] === 200 && $settings['body']['settings']['theme'] === 'system', 'load default account settings');
    $settingsUpdate = api_request($baseUrl, $cookieJar, 'PUT', '/api/settings', [
        'theme' => 'light',
        'email_notifications' => false,
    ], $csrfToken);
    expect($settingsUpdate['status'] === 200 && $settingsUpdate['body']['settings']['email_notifications'] === false, 'update own settings');

    try {
        $restrictTestMember = $testDatabase->prepare("UPDATE business_members SET role = 'inventory_staff' WHERE user_id = (SELECT id FROM users WHERE email = :email)");
        $restrictTestMember->execute(['email' => $email]);
        $deniedStats = api_request($baseUrl, $cookieJar, 'GET', '/api/dashboard/stats');
        expect($deniedStats['status'] === 403, 'enforce active business membership role permissions');

        $restoreTestMember = $testDatabase->prepare("UPDATE business_members SET role = 'owner' WHERE user_id = (SELECT id FROM users WHERE email = :email)");
        $restoreTestMember->execute(['email' => $email]);

        $adminStats = api_request($baseUrl, $cookieJar, 'GET', '/api/dashboard/stats');
        $adminAnalytics = api_request($baseUrl, $cookieJar, 'GET', '/api/dashboard/registrations');
        $adminUsers = api_request($baseUrl, $cookieJar, 'GET', '/api/users?page=1&per_page=5&search=Test');
        if ($adminUsers['status'] !== 200 || ($adminUsers['body']['pagination']['total'] ?? 0) < 1) {
            fwrite(STDERR, 'Business users API response: ' . json_encode($adminUsers, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }
        expect($adminStats['status'] === 200 && $adminStats['body']['stats']['totalUsers'] > 0, 'return database-backed admin statistics');
        expect($adminAnalytics['status'] === 200 && count($adminAnalytics['body']['registrations']) === 30, 'return 30 days of registration analytics');
        expect($adminUsers['status'] === 200 && $adminUsers['body']['pagination']['total'] > 0, 'search and paginate users for administrators');
        expect(!isset($adminUsers['body']['users'][0]['password']), 'never expose credentials in user listings');
    } finally {
        $restoreTestMember = $testDatabase->prepare("UPDATE business_members SET role = 'owner' WHERE user_id = (SELECT id FROM users WHERE email = :email)");
        $restoreTestMember->execute(['email' => $email]);
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
    $username = 'auth-' . bin2hex(random_bytes(4));
    $setUsername = $testDatabase->prepare('UPDATE users SET username = :username WHERE email = :email');
    $setUsername->execute(['username' => $username, 'email' => $email]);
    $setUsername->closeCursor();
    $usernameLogin = api_request($baseUrl, $cookieJar, 'POST', '/api/login', [
        'email' => $username,
        'password' => $account['password'],
    ], $csrfToken);
    expect($usernameLogin['status'] === 200 && $usernameLogin['body']['user']['email'] === $email, 'log in using a unique username');
    $csrfToken = $usernameLogin['body']['csrfToken'];

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