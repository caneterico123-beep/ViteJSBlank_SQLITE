<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_authenticated_user($pdo);
json_response(200, ['profile' => $user]);