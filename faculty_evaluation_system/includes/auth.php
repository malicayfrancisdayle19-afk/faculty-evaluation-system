<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_name('faculty_evaluation_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$applicationRoot = realpath(dirname(__DIR__));
$basePath = '';

if ($documentRoot !== false && $applicationRoot !== false) {
    $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
    $applicationRoot = str_replace('\\', '/', $applicationRoot);
    if (stripos($applicationRoot, $documentRoot . '/') === 0) {
        $basePath = substr($applicationRoot, strlen($documentRoot) + 1);
    }
}

if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', trim($basePath, '/'));
}

function appPath(string $path): string
{
    return '/' . (APP_BASE_PATH !== '' ? APP_BASE_PATH . '/' : '') . ltrim($path, '/');
}

function currentUser(): ?array
{
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        return null;
    }

    $userId = filter_var($_SESSION['user']['id'] ?? null, FILTER_VALIDATE_INT);
    if ($userId === false || $userId === null || $userId < 1) {
        unset($_SESSION['user']);
        return null;
    }

    global $conn;
    require_once __DIR__ . '/../config/database.php';

    $stmt = $conn->prepare(
        'SELECT full_name, email, role, status
         FROM users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($account === null || $account['status'] !== 'active') {
        unset($_SESSION['user']);
        $_SESSION['auth_error'] = 'Your account is no longer active. Contact the system administrator if you need help.';
        return null;
    }

    $_SESSION['user'] = [
        'id' => (int) $userId,
        'full_name' => $account['full_name'],
        'email' => $account['email'],
        'role' => $account['role'],
    ];

    return $_SESSION['user'];
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfTokenIsValid($token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function requireLogin(): array
{
    $user = currentUser();
    if ($user === null) {
        header('Location: ' . appPath('login.php'));
        exit;
    }

    return $user;
}

function requireRole(string $role): array
{
    $user = requireLogin();
    if (($user['role'] ?? null) !== $role) {
        http_response_code(403);
        exit('Access denied.');
    }

    return $user;
}

function dashboardPathForRole(string $role): string
{
    switch ($role) {
        case 'admin':
            return appPath('admin/dashboard.php');
        case 'student':
            return appPath('student/dashboard.php');
        case 'faculty':
            return appPath('faculty/dashboard.php');
        default:
            return appPath('login.php');
    }
}
