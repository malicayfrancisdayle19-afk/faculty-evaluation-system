<?php

require_once __DIR__ . '/branding.php';

function portalNavigation(string $role): array
{
    $navigation = [
        'admin' => [
            ['Overview', 'speedometer2', 'admin/dashboard.php', true],
            ['Students', 'people', 'admin/manage.php?module=students', true],
            ['Faculty Members', 'person-video3', 'admin/manage.php?module=faculty', true],
            ['Departments', 'diagram-3', 'admin/manage.php?module=departments', true],
            ['Subjects', 'book', 'admin/manage.php?module=subjects', true],
            ['Class Sections', 'collection', 'admin/manage.php?module=sections', true],
            ['Enrollments', 'person-check', 'admin/manage.php?module=enrollments', true],
            ['Academic Terms', 'calendar3', 'admin/manage.php?module=terms', true],
            ['Evaluation Periods', 'calendar-event', 'admin/manage.php?module=periods', true],
            ['Evaluation Questions', 'patch-question', 'admin/manage.php?module=questions', true],
            ['Evaluation monitoring', 'clipboard-check', 'admin/reports.php?view=completion', true],
            ['Evaluation results', 'clipboard-data', 'admin/reports.php?view=results', true],
            ['Reports', 'bar-chart', 'admin/reports.php?view=reports', true],
            ['Settings', 'gear', null, false],
        ],
        'student' => [
            ['Overview', 'speedometer2', 'student/dashboard.php', true],
            ['My subjects', 'book', 'student/dashboard.php#classes', true],
            ['Evaluate faculty', 'clipboard-check', 'student/dashboard.php#classes', true],
            ['Evaluation history', 'clock-history', 'student/history.php', true],
        ],
        'faculty' => [
            ['Overview', 'speedometer2', 'faculty/dashboard.php', true],
            ['My subjects', 'book', 'faculty/dashboard.php#assignments', true],
            ['Performance results', 'bar-chart', 'faculty/results.php', true],
        ],
    ];

    return $navigation[$role] ?? [];
}

function renderPortalPage(string $title, array $user, callable $content): void
{
    $role = (string) ($user['role'] ?? '');
    $logoutPath = appPath('logout.php');
    $token = csrfToken();
    $logoImage = htmlspecialchars(systemImagePath('logo'), ENT_QUOTES, 'UTF-8');
    $graduationImage = htmlspecialchars(systemImagePath('graduation'), ENT_QUOTES, 'UTF-8');
    $navigation = portalNavigation($role);
    $initial = strtoupper(substr((string) ($user['full_name'] ?? 'U'), 0, 1));
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#0d47a1">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | Faculty Evaluation System</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link href="<?= htmlspecialchars(appPath('assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    </head>
    <body class="portal-page">
        <div class="portal-shell">
            <aside class="portal-sidebar" id="portal-sidebar">
                <a class="sidebar-brand" href="<?= htmlspecialchars(appPath($role . '/dashboard.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <img class="sidebar-cap" src="<?= $graduationImage ?>" alt="">
                    <span class="sidebar-brand-copy">
                        <strong>Faculty Evaluation</strong>
                        <small>ISUFST Academic Portal</small>
                    </span>
                </a>
                <button class="sidebar-close" type="button" aria-label="Close navigation" data-sidebar-close>
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
                <div class="sidebar-section-label">WORKSPACE</div>
                <nav class="sidebar-nav" aria-label="Main navigation">
                    <?php foreach ($navigation as [$label, $icon, $path, $enabled]): ?>
                        <?php if ($enabled): ?>
                            <?php
                            $pathOnly = parse_url($path, PHP_URL_PATH) ?: $path;
                            $isManagementLink = strpos($path, 'module=') !== false;
                            $isReportLink = strpos($path, 'admin/reports.php?view=') === 0;
                            $isDashboardLink = basename($pathOnly) === 'dashboard.php';
                            $isActive = false;
                            if ($isManagementLink) {
                                $isActive = isset($_GET['module'])
                                    && strpos($path, 'module=' . rawurlencode((string) $_GET['module'])) !== false;
                            } elseif ($isReportLink) {
                                $reportQuery = parse_url($path, PHP_URL_QUERY);
                                $expectedView = $reportQuery === 'view=results'
                                    ? 'results'
                                    : ($reportQuery === 'view=completion' ? 'completion' : 'reports');
                                $isActive = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'reports.php'
                                    && ($_GET['view'] ?? 'reports') === $expectedView;
                            } elseif ($isDashboardLink && $path === $role . '/dashboard.php') {
                                $isActive = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'dashboard.php';
                            } elseif (!$isDashboardLink) {
                                $isActive = basename($_SERVER['SCRIPT_NAME'] ?? '') === basename($pathOnly);
                            }
                            ?>
                            <a class="sidebar-link<?= $isActive ? ' active' : '' ?>" href="<?= htmlspecialchars(appPath($path), ENT_QUOTES, 'UTF-8') ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                                <i class="bi bi-<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        <?php else: ?>
                            <span class="sidebar-link disabled" aria-disabled="true" title="Available in a later development stage">
                                <i class="bi bi-<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
                <div class="sidebar-bottom">
                    <div class="sidebar-university">
                        <img src="<?= $logoImage ?>" alt="ISUFST logo">
                        <span><strong>ISUFST</strong><small>Learn. Lead. Serve.</small></span>
                    </div>
                    <form method="post" action="<?= htmlspecialchars($logoutPath, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                        <button class="sidebar-logout" type="submit">
                            <i class="bi bi-box-arrow-left" aria-hidden="true"></i>
                            <span>Sign out</span>
                        </button>
                    </form>
                </div>
            </aside>
            <button class="sidebar-backdrop" type="button" aria-label="Close navigation" data-sidebar-close></button>

            <div class="portal-main">
                <header class="portal-topbar">
                    <div class="d-flex align-items-center gap-3">
                        <button class="mobile-menu-button" type="button" aria-label="Open navigation" aria-controls="portal-sidebar" aria-expanded="false" data-sidebar-toggle>
                            <i class="bi bi-list" aria-hidden="true"></i>
                        </button>
                        <div class="topbar-context">
                            <span>ISUFST</span><i class="bi bi-chevron-right" aria-hidden="true"></i>
                            <strong><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    </div>
                    <div class="topbar-user">
                        <div class="topbar-user-copy">
                            <strong><?= htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="user-avatar" aria-hidden="true"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </header>
                <main class="portal-content">
                    <?php $content(); ?>
                    <footer class="portal-footer">
                        <span>Faculty Evaluation System</span>
                        <span>Iloilo State University of Fisheries Science and Technology</span>
                    </footer>
                </main>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
        <script src="<?= htmlspecialchars(appPath('assets/js/script.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    </body>
    </html>
    <?php
}

function dashboardStat(string $label, $value, string $icon, string $accent, string $detail): void
{
    ?>
    <article class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="stat-icon stat-<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
            </span>
        </div>
        <div class="stat-value"><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="stat-detail"><?= htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') ?></div>
    </article>
    <?php
}

function dashboardEmptyState(string $message): void
{
    ?>
    <div class="empty-state">
        <span class="empty-state-icon"><i class="bi bi-inbox" aria-hidden="true"></i></span>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <?php
}
