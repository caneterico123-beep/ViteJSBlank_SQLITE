<?php
declare(strict_types=1);

function require_authenticated_user(PDO $pdo): array
{
    $userId = $_SESSION['user_id'] ?? null;
    $lastActivity = $_SESSION['last_activity'] ?? null;
    $expiresAt = $_SESSION['auth_expires_at'] ?? null;

    if (!is_int($userId) || !is_int($lastActivity) || !is_int($expiresAt)
        || time() - $lastActivity > 1800 || time() >= $expiresAt) {
        unset($_SESSION['user_id'], $_SESSION['last_activity'], $_SESSION['auth_expires_at']);
        json_response(401, ['message' => 'Authentication required.']);
    }

    $query = $pdo->prepare('SELECT id, name, email, created_at FROM users WHERE id = :id');
    $query->execute(['id' => $userId]);
    $user = $query->fetch();

    if ($user === false) {
        unset($_SESSION['user_id'], $_SESSION['last_activity'], $_SESSION['auth_expires_at']);
        json_response(401, ['message' => 'Authentication required.']);
    }

    $_SESSION['last_activity'] = time();
    return $user;
}