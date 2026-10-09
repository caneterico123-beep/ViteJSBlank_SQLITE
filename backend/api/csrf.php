<?php
declare(strict_types=1);

require_method('GET');
json_response(200, ['csrfToken' => $_SESSION['csrf_token'] ?? new_csrf_token()]);