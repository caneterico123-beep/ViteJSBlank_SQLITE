<?php
declare(strict_types=1);

require_method('PUT');
$pdo = database_connection();
$user = require_authenticated_user($pdo);
$input = request_json();
$theme = $input['theme'] ?? null;
$emailNotifications = $input['email_notifications'] ?? null;
$errors = [];

if (!is_string($theme) || !in_array($theme, ['light', 'dark', 'system'], true)) {
    $errors['theme'] = 'Choose light, dark, or system theme.';
}
if (!is_bool($emailNotifications)) {
    $errors['email_notifications'] = 'Choose whether email notifications are enabled.';
}
if ($errors !== []) {
    json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);
}

$update = $pdo->prepare(
    'INSERT INTO user_settings (user_id, theme, email_notifications)
     VALUES (:user_id, :theme, :email_notifications)
     ON CONFLICT(user_id) DO UPDATE SET
       theme = excluded.theme,
       email_notifications = excluded.email_notifications,
       updated_at = CURRENT_TIMESTAMP',
);
$update->execute([
    'user_id' => $user['id'],
    'theme' => $theme,
    'email_notifications' => $emailNotifications ? 1 : 0,
]);

json_response(200, [
    'message' => 'Settings saved.',
    'settings' => ['theme' => $theme, 'email_notifications' => $emailNotifications],
]);