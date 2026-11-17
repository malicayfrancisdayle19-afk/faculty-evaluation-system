<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$serverName = strtolower($_SERVER['SERVER_NAME'] ?? '');
$serverName = preg_replace('/:\d+$/', '', $serverName);
$defaultEnv = in_array($serverName, ['localhost', '127.0.0.1', '::1'], true)
    ? 'local'
    : 'production';
$appEnv = getenv('APP_ENV') ?: $defaultEnv;
$isLocal = $appEnv === 'local';

$host = getenv('DB_HOST');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
$database = getenv('DB_DATABASE');

if ($isLocal) {
    $host = $host !== false ? $host : 'localhost';
    $username = $username !== false ? $username : 'root';
    $password = $password !== false ? $password : '';
    $database = $database !== false ? $database : 'faculty_evaluation_system';
} elseif (
    $host === false || $host === ''
    || $username === false || $username === ''
    || $password === false || $password === ''
    || $database === false || $database === ''
) {
    throw new RuntimeException('Database environment configuration is incomplete.');
}

$conn = new mysqli($host, $username, $password, $database);
$conn->set_charset('utf8mb4');
