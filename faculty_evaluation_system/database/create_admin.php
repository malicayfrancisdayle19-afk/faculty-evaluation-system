<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$fullName = getenv('ADMIN_SETUP_NAME');
$email = getenv('ADMIN_SETUP_EMAIL');
$password = getenv('ADMIN_SETUP_PASSWORD');

if ($fullName === false || $email === false || $password === false) {
    fwrite(STDERR, "Set ADMIN_SETUP_NAME, ADMIN_SETUP_EMAIL, and ADMIN_SETUP_PASSWORD before running this script.\n");
    exit(1);
}

$fullName = trim($fullName);
$email = strtolower(trim($email));
$nameLength = preg_match_all('/./us', $fullName, $nameCharacters);

if ($nameLength === false || $nameLength < 1 || $nameLength > 150) {
    fwrite(STDERR, "Enter a full name of up to 150 characters.\n");
    exit(1);
}
if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Enter a valid email address of up to 150 characters.\n");
    exit(1);
}
if (strlen($password) < 12 || strlen($password) > 72) {
    fwrite(STDERR, "The password must contain between 12 and 72 bytes.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

$lockName = 'faculty_evaluation_initial_admin';
$lockStmt = $conn->prepare('SELECT GET_LOCK(?, 10)');
$lockStmt->bind_param('s', $lockName);
$lockStmt->execute();
$lockStmt->bind_result($lockAcquired);
$lockStmt->fetch();
$lockStmt->close();

if ((int) $lockAcquired !== 1) {
    fwrite(STDERR, "Could not safely lock initial administrator setup. Try again.\n");
    exit(1);
}

$exitCode = 0;
try {
    $adminResult = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $adminExists = $adminResult->num_rows > 0;
    $adminResult->free();

    if ($adminExists) {
        fwrite(STDERR, "An administrator account already exists; no account was changed.\n");
        $exitCode = 2;
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $insertStmt = $conn->prepare(
            "INSERT INTO users (full_name, email, password_hash, role, status)
             VALUES (?, ?, ?, 'admin', 'active')"
        );
        $insertStmt->bind_param('sss', $fullName, $email, $passwordHash);
        $insertStmt->execute();
        $insertStmt->close();

        fwrite(STDOUT, "Initial administrator created for {$email}.\n");
    }
} finally {
    $releaseStmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    $releaseStmt->bind_param('s', $lockName);
    $releaseStmt->execute();
    $releaseStmt->close();
}

exit($exitCode);
