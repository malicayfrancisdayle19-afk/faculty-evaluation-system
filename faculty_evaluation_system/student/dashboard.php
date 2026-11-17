<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal.php';
require_once __DIR__ . '/../config/database.php';

$user = requireRole('student');
$userId = (int) $user['id'];

$profileStmt = $conn->prepare(
    'SELECT s.id, s.student_number, s.year_level, d.department_name
     FROM students s
     LEFT JOIN departments d ON d.id = s.department_id
     WHERE s.user_id = ?
     LIMIT 1'
);
$profileStmt->bind_param('i', $userId);
$profileStmt->execute();
$student = $profileStmt->get_result()->fetch_assoc();
$profileStmt->close();

$termResult = $conn->query(
    'SELECT id, school_year, semester
     FROM academic_terms
     WHERE is_active = 1
     ORDER BY id DESC
     LIMIT 1'
);
$activeTerm = $termResult->fetch_assoc() ?: null;
$termResult->free();

$classes = [];
if ($student !== null) {
    $studentId = (int) $student['id'];
    $termFilter = $activeTerm !== null ? ' AND cs.academic_term_id = ?' : '';
    $sql = "SELECT cs.id AS section_id, cs.section_name, s.subject_code, s.subject_name,
                   f.id AS faculty_id, u.full_name AS faculty_name, ep.id AS period_id, ep.title AS period_title,
                   ep.start_date, ep.end_date, ep.status AS period_status, ev.id AS evaluation_id,
                   CASE
                       WHEN ev.id IS NOT NULL THEN 'Completed'
                       WHEN ep.id IS NULL THEN 'Closed'
                       WHEN ep.status = 'open' AND CURDATE() BETWEEN ep.start_date AND ep.end_date THEN 'Pending'
                       WHEN ep.status = 'upcoming' OR ep.start_date > CURDATE() THEN 'Upcoming'
                       ELSE 'Closed'
                   END AS evaluation_status
            FROM enrollments en
            INNER JOIN class_sections cs ON cs.id = en.class_section_id
            INNER JOIN subjects s ON s.id = cs.subject_id
            INNER JOIN faculty f ON f.id = cs.faculty_id
            INNER JOIN users u ON u.id = f.user_id
            LEFT JOIN evaluation_periods ep ON ep.academic_term_id = cs.academic_term_id
            LEFT JOIN evaluations ev
                ON ev.student_id = en.student_id
               AND ev.class_section_id = cs.id
               AND ev.evaluation_period_id = ep.id
            WHERE en.student_id = ?" . $termFilter . "
            ORDER BY s.subject_code, cs.section_name, ep.start_date DESC";
    $classesStmt = $conn->prepare($sql);
    if ($activeTerm !== null) {
        $termId = (int) $activeTerm['id'];
        $classesStmt->bind_param('ii', $studentId, $termId);
    } else {
        $classesStmt->bind_param('i', $studentId);
    }
    $classesStmt->execute();
    $classes = $classesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $classesStmt->close();
}

$pendingCount = 0;
$completedCount = 0;
$assignedFacultyIds = [];
$enrolledSectionIds = [];
foreach ($classes as $class) {
    $assignedFacultyIds[(int) $class['faculty_id']] = true;
    $enrolledSectionIds[(int) $class['section_id']] = true;
    if ($class['evaluation_status'] === 'Pending') {
        $pendingCount++;
    } elseif ($class['evaluation_status'] === 'Completed') {
        $completedCount++;
    }
}
$totalEnrolledCount = count($enrolledSectionIds);
$totalAssignedFacultyCount = count($assignedFacultyIds);
$completionTotal = $pendingCount + $completedCount;
$completionPercentage = $completionTotal > 0
    ? round($completedCount / $completionTotal * 100)
    : 0;
$studentName = htmlspecialchars(explode(' ', $user['full_name'])[0], ENT_QUOTES, 'UTF-8');
$termLabel = $activeTerm !== null
    ? $activeTerm['school_year'] . ' · ' . $activeTerm['semester']
    : 'No active academic term';

renderPortalPage('Student dashboard', $user, static function () use (
    $student,
    $classes,
    $pendingCount,
    $completedCount,
    $completionTotal,
    $totalEnrolledCount,
    $totalAssignedFacultyCount,
    $completionPercentage,
    $assignedFacultyIds,
    $enrolledSectionIds,
    $studentName,
    $termLabel
): void {
    ?>
    <section class="student-welcome">
        <div>
            <p class="eyebrow">STUDENT PORTAL</p>
            <h1>Welcome, <?= $studentName ?></h1>
            <p>Keep up with your classes and share feedback when an evaluation period is open.</p>
        </div>
        <span class="term-pill"><i class="bi bi-calendar3" aria-hidden="true"></i><?= htmlspecialchars($termLabel, ENT_QUOTES, 'UTF-8') ?></span>
    </section>

    <?php if ($student === null): ?>
        <div class="alert alert-warning portal-alert" role="alert">
            Your account is not linked to a student profile yet. Please contact the system administrator.
        </div>
    <?php endif; ?>

    <section class="student-overview-grid">
        <article class="profile-card">
            <div class="profile-card-heading"><span class="profile-avatar"><i class="bi bi-person" aria-hidden="true"></i></span><span>My profile</span></div>
            <h2><?= htmlspecialchars($student['student_number'] ?? 'Profile pending', ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($student['department_name'] ?? 'Department not assigned', ENT_QUOTES, 'UTF-8') ?></p>
            <span class="profile-year"><?= htmlspecialchars($student['year_level'] ?? 'Year level not set', ENT_QUOTES, 'UTF-8') ?></span>
        </article>
        <div class="student-stat-cards">
            <?php
            dashboardStat('Enrolled subjects', $totalEnrolledCount, 'bookshelf', 'blue', 'Current academic term');
            dashboardStat('Assigned faculty', $totalAssignedFacultyCount, 'person-workspace', 'violet', 'Across your enrolled classes');
            dashboardStat('Pending evaluations', $pendingCount, 'hourglass-split', 'amber', 'Open evaluation periods');
            dashboardStat('Completed', $completedCount, 'check2-circle', 'green', 'Your submitted evaluations');
            ?>
        </div>
    </section>

    <section class="content-card student-progress-card" aria-labelledby="student-progress-heading">
        <div class="card-heading">
            <div><p class="eyebrow">YOUR PROGRESS</p><h2 id="student-progress-heading">Evaluation completion</h2></div>
            <strong class="student-progress-value"><?= $completionPercentage ?>%</strong>
        </div>
        <div class="progress" role="progressbar" aria-label="Evaluation completion"
             aria-valuenow="<?= $completionPercentage ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width: <?= $completionPercentage ?>%"></div>
        </div>
        <p class="text-muted mb-0 mt-2"><?= number_format($completedCount) ?> of <?= number_format($completionTotal) ?> currently available evaluations completed</p>
        <p class="small text-muted mb-0">Closed and upcoming periods are listed below but are not counted as currently available.</p>
    </section>

    <section class="content-card class-list-card" id="classes">
        <div class="card-heading">
            <div><p class="eyebrow">YOUR CLASSES</p><h2>Enrolled subjects & faculty</h2></div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary" href="<?= htmlspecialchars(appPath('student/history.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="bi bi-clock-history me-2" aria-hidden="true"></i>View evaluation history
                </a>
                <a class="btn btn-primary btn-evaluate" href="<?= htmlspecialchars(appPath('student/evaluate.php'), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="bi bi-clipboard-check me-2" aria-hidden="true"></i>Evaluate now
                </a>
            </div>
        </div>
        <?php if ($classes === []): ?>
            <?php dashboardEmptyState($student === null
                ? 'Student enrollment information is not available for this account.'
                : 'No class enrollments were found for the current academic term.'); ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table portal-table class-table align-middle mb-0">
                    <thead><tr><th>Subject</th><th>Faculty member</th><th>Evaluation period</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($classes as $class): ?>
                        <?php
                        $statusClass = [
                            'Completed' => 'status-complete',
                            'Pending' => 'status-pending',
                            'Closed' => 'status-closed',
                            'Upcoming' => 'status-upcoming',
                            'No evaluation period' => 'status-neutral',
                        ][$class['evaluation_status']] ?? 'status-neutral';
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($class['subject_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="table-subtext"><?= htmlspecialchars($class['subject_name'] . ' · ' . $class['section_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td><?= htmlspecialchars($class['faculty_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($class['period_title'] ?? 'Not scheduled', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($class['evaluation_status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <?php if ($class['evaluation_status'] === 'Pending'): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(appPath('student/evaluate.php'), ENT_QUOTES, 'UTF-8') ?>">Evaluate now</a>
                                <?php elseif ($class['evaluation_status'] === 'Completed'): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(appPath('student/history.php'), ENT_QUOTES, 'UTF-8') ?>">View history</a>
                                <?php else: ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(appPath('student/history.php'), ENT_QUOTES, 'UTF-8') ?>">View history</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <aside class="privacy-note"><i class="bi bi-shield-lock" aria-hidden="true"></i><span>Your submitted evaluation responses are confidential.</span></aside>
    <?php
});
