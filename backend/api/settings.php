<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_authenticated_user($pdo);
$ensure = $pdo->prepare('INSERT INTO user_settings (user_id) VALUES (:user_id) ON CONFLICT(user_id) DO NOTHING');
$ensure->execute(['user_id' => $user['id']]);
$query = $pdo->prepare('SELECT theme, email_notifications FROM user_settings WHERE user_id = :user_id');
$query->execute(['user_id' => $user['id']]);
$settings = $query->fetch();
$settings['email_notifications'] = (bool) $settings['email_notifications'];
json_response(200, ['settings' => $settings]);