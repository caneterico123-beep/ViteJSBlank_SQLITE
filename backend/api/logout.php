<?php
declare(strict_types=1);

require_method('POST');
require_csrf();

$_SESSION = [];
refresh_session_cookie(time() - 42000);
session_destroy();

json_response(200, ['message' => 'Signed out successfully.']);