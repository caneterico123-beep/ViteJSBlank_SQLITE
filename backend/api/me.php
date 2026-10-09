<?php
declare(strict_types=1);

require_method('GET');
$user = require_authenticated_user(database_connection());
json_response(200, ['user' => $user, 'csrfToken' => $_SESSION['csrf_token'] ?? new_csrf_token()]);