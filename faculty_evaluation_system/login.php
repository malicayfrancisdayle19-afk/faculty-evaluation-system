<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/branding.php';
require_once __DIR__ . '/config/database.php';

$user = currentUser();
if ($user !== null) {
    header('Location: ' . dashboardPathForRole($user['role']));
    exit;
}

$error = '';
if (isset($_SESSION['auth_error']) && is_string($_SESSION['auth_error'])) {
    $error = $_SESSION['auth_error'];
    unset($_SESSION['auth_error']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfTokenIsValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $email = isset($_POST['email']) && is_string($_POST['email'])
            ? trim($_POST['email'])
            : '';
        $submittedPassword = isset($_POST['password']) && is_string($_POST['password'])
            ? $_POST['password']
            : '';

        if ($email === '' || $submittedPassword === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address and password.';
        } else {
            $stmt = $conn->prepare(
                'SELECT id, full_name, email, password_hash, role, status
                 FROM users
                 WHERE email = ?
                 LIMIT 1'
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (
                $account === null
                || $account['status'] !== 'active'
                || !password_verify($submittedPassword, $account['password_hash'])
            ) {
                $error = 'The email or password is incorrect.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => (int) $account['id'],
                    'full_name' => $account['full_name'],
                    'email' => $account['email'],
                    'role' => $account['role'],
                ];
                unset($_SESSION['csrf_token']);

                header('Location: ' . dashboardPathForRole($account['role']));
                exit;
            }
        }
    }
}

$token = csrfToken();
$campusImage = htmlspecialchars(systemImagePath('campus'), ENT_QUOTES, 'UTF-8');
$logoImage = htmlspecialchars(systemImagePath('logo'), ENT_QUOTES, 'UTF-8');
$graduationImage = htmlspecialchars(systemImagePath('graduation'), ENT_QUOTES, 'UTF-8');
$isufstImage = htmlspecialchars(systemImagePath('isufst'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0d47a1">
    <title>Sign in | Faculty Evaluation System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(appPath('assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body class="login-page">
    <main class="login-layout">
        <section class="login-visual" style="--campus-image: url('<?= $campusImage ?>')">
            <div class="visual-brand">
                <img class="visual-cap" src="<?= $graduationImage ?>" alt="">
                <span>ISUFST<br><small>Academic portal</small></span>
            </div>
            <div class="visual-copy">
                <p class="visual-kicker">Iloilo State University of Fisheries Science and Technology</p>
                <h1>FACULTY<br>EVALUATION<br>SYSTEM</h1>
                <p class="visual-tagline">Evaluate. Analyze. Improve.</p>
                <div class="visual-rule"></div>
                <p class="visual-description">A thoughtful space for academic feedback and continuous teaching excellence.</p>
            </div>
            <div class="visual-footer">
                <img src="<?= $logoImage ?>" alt="ISUFST logo">
                <span>Excellence in teaching<br>begins with listening.</span>
            </div>
        </section>

        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-panel-inner">
                <img class="university-banner" src="<?= $isufstImage ?>" alt="Iloilo State University of Fisheries Science and Technology campus">
                <p class="eyebrow">University community</p>
                <h2 id="login-title">Welcome back</h2>
                <p class="login-intro">Sign in with your university account to continue.</p>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= htmlspecialchars(appPath('login.php'), ENT_QUOTES, 'UTF-8') ?>" data-login-form>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label class="form-label" for="email">Email address</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-envelope" aria-hidden="true"></i>
                            <input
                                class="form-control form-control-lg"
                                id="email"
                                name="email"
                                type="email"
                                autocomplete="username"
                                maxlength="150"
                                inputmode="email"
                                placeholder="name@university.edu"
                                required
                                value="<?= htmlspecialchars(
                                    isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="password">Password</label>
                        <div class="input-icon-wrap password-wrap">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input
                                class="form-control form-control-lg"
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                placeholder="Enter your password"
                                required
                            >
                            <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" data-password-toggle>
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-lg sign-in-button w-100" type="submit">
                        Sign in <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i>
                    </button>
                </form>

                <div class="login-help">
                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                    <span>Authorized university accounts only</span>
                </div>
                <p class="login-copyright mb-0">Iloilo State University of Fisheries Science and Technology</p>
            </div>
        </section>
    </main>
    <script src="<?= htmlspecialchars(appPath('assets/js/script.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body>
</html>
