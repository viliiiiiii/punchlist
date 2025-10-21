<?php
require_once __DIR__ . '/helpers.php';

function attempt_login(string $email, string $password): bool
{
    $stmt = get_pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
        return true;
    }
    return false;
}

function logout(): void
{
    unset($_SESSION['user']);
    session_regenerate_id(true);
}

function require_post_csrf(): void
{
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        http_response_code(422);
        exit('Invalid CSRF token');
    }
}
