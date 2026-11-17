<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal.php';
require_once __DIR__ . '/../config/database.php';

$user = requireRole('student');
$userId = (int) $user['id'];
$profileStmt = $conn->prepare('SELECT id FROM students WHERE user_id = ? LIMIT 1');
$profileStmt->bind_param('i', $userId);
$profileStmt->execute();
$profile = $profileStmt->get_result()->fetch_assoc();
$profileStmt->close();

$history = [];
if ($profile !== null) {
    $studentId = (int) $profile['id'];
    $historyStmt = $conn->prepare(
        'SELECT ev.id, ev.submitted_at, ep.title AS period_title,
                at.school_year, at.semester, s.subject_code, s.subject_name,
                cs.section_name, u.full_name AS faculty_name
         FROM evaluations ev
         INNER JOIN class_sections cs ON cs.id = ev.class_section_id
         INNER JOIN subjects s ON s.id = cs.subject_id
         INNER JOIN faculty f ON f.id = cs.faculty_id
         INNER JOIN users u ON u.id = f.user_id
         INNER JOIN academic_terms at ON at.id = cs.academic_term_id
         INNER JOIN evaluation_periods ep ON ep.id = ev.evaluation_period_id
         WHERE ev.student_id = ?
         ORDER BY ev.submitted_at DESC'
    );
    $historyStmt->bind_param('i', $studentId);
    $historyStmt->execute();
    $history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $historyStmt->close();
}

renderPortalPage('Evaluation history', $user, static function () use ($profile, $history): void {
    ?>
    <section class="page-heading-row">
        <div>
            <p class="eyebrow">MY SUBMISSIONS</p>
            <h1>Evaluation history</h1>
            <p>Review the evaluations you have submitted. Your answers cannot be changed after submission.</p>
        </div>
        <a class="btn btn-outline-primary" href="<?= htmlspecialchars(appPath('student/dashboard.php#classes'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-book me-2" aria-hidden="true"></i>My classes</a>
    </section>
    <section class="content-card management-list-card">
        <?php if ($profile === null): ?>
            <div class="alert alert-warning portal-alert" role="alert">Your account is not linked to a student profile. Please contact the system administrator.</div>
        <?php elseif ($history === []): ?>
            <?php dashboardEmptyState('You have not submitted any evaluations yet.'); ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table portal-table align-middle mb-0">
                    <thead><tr><th>Subject</th><th>Faculty member</th><th>Evaluation period</th><th>Submitted</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $submission): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($submission['subject_code'], ENT_QUOTES, 'UTF-8') ?></strong><span class="table-subtext"><?= htmlspecialchars($submission['subject_name'] . ' · ' . $submission['section_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars($submission['faculty_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($submission['period_title'] . ' · ' . $submission['school_year'] . ' ' . $submission['semester'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $submission['submitted_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="status-badge status-complete">Completed</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
});
