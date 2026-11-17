<?php

require_once __DIR__ . '/includes/auth.php';

$user = currentUser();
if ($user !== null) {
    header('Location: ' . dashboardPathForRole($user['role']));
    exit;
}

header('Location: ' . appPath('login.php'));
exit;
