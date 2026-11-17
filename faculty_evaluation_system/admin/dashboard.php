<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal.php';
require_once __DIR__ . '/../config/database.php';

$user = requireRole('admin');
$minimumResponses = max(1, (int) (getenv('MIN_EVALUATIONS_FOR_FACULTY_RESULTS') ?: 5));

$countQueries = [
    'students' => 'SELECT COUNT(*) FROM students',
    'faculty' => 'SELECT COUNT(*) FROM faculty',
    'departments' => 'SELECT COUNT(*) FROM departments',
    'subjects' => 'SELECT COUNT(*) FROM subjects',
    'evaluations' => 'SELECT COUNT(*) FROM evaluations',
    'periods' => "SELECT COUNT(*) FROM evaluation_periods WHERE status = 'open' AND CURDATE() BETWEEN start_date AND end_date",
    'completed_periods' => "SELECT COUNT(*) FROM evaluation_periods WHERE status = 'closed' OR end_date < CURDATE()",
    'pending' => "SELECT COUNT(*)
                   FROM (
                       SELECT DISTINCT e.student_id, e.class_section_id, ep.id AS period_id
                       FROM enrollments e
                       INNER JOIN class_sections cs ON cs.id = e.class_section_id
                       INNER JOIN evaluation_periods ep ON ep.academic_term_id = cs.academic_term_id
                       WHERE ep.status = 'open'
                         AND CURDATE() BETWEEN ep.start_date AND ep.end_date
                   ) eligible
                   WHERE NOT EXISTS (
                       SELECT 1 FROM evaluations ev
                       WHERE ev.student_id = eligible.student_id
                         AND ev.class_section_id = eligible.class_section_id
                         AND ev.evaluation_period_id = eligible.period_id
                   )",
];
$stats = [];
foreach ($countQueries as $key => $query) {
    $result = $conn->query($query);
    $stats[$key] = (int) $result->fetch_row()[0];
    $result->free();
}

$eligible = $conn->query(
    "SELECT COUNT(*) AS eligible,
            COUNT(DISTINCT assignments.student_id) AS enrolled_students,
            COUNT(DISTINCT assignments.faculty_id) AS assigned_faculty,
            SUM(CASE WHEN EXISTS (
                SELECT 1 FROM evaluations ev
                WHERE ev.student_id = assignments.student_id
                  AND ev.class_section_id = assignments.class_section_id
                  AND ev.evaluation_period_id = assignments.period_id
            ) THEN 1 ELSE 0 END) AS completed
     FROM (
         SELECT DISTINCT en.student_id, en.class_section_id, cs.faculty_id, ep.id AS period_id
         FROM enrollments en
         INNER JOIN class_sections cs ON cs.id = en.class_section_id
         INNER JOIN evaluation_periods ep ON ep.academic_term_id = cs.academic_term_id
         WHERE ep.status = 'open'
           AND CURDATE() BETWEEN ep.start_date AND ep.end_date
     ) assignments"
)->fetch_assoc();
$completedCount = (int) ($eligible['completed'] ?? 0);
$eligibleCount = (int) ($eligible['eligible'] ?? 0);
$completionRate = $eligibleCount > 0 ? round($completedCount / $eligibleCount * 100, 1) : 0;
$monitoringStudents = (int) ($eligible['enrolled_students'] ?? 0);
$monitoringFaculty = (int) ($eligible['assigned_faculty'] ?? 0);

$monitoringStmt = $conn->prepare(
    "SELECT cs.id, cs.section_name, s.subject_code,
            at.school_year, at.semester, COUNT(*) AS expected,
            SUM(CASE WHEN EXISTS (
                SELECT 1 FROM evaluations ev
                WHERE ev.student_id = en.student_id
                  AND ev.class_section_id = cs.id
                  AND ev.evaluation_period_id = ep.id
            ) THEN 1 ELSE 0 END) AS completed
     FROM enrollments en
     INNER JOIN class_sections cs ON cs.id = en.class_section_id
     INNER JOIN subjects s ON s.id = cs.subject_id
     INNER JOIN academic_terms at ON at.id = cs.academic_term_id
     INNER JOIN evaluation_periods ep ON ep.academic_term_id = cs.academic_term_id
     WHERE ep.status = 'open' AND CURDATE() BETWEEN ep.start_date AND ep.end_date
     GROUP BY cs.id, cs.section_name, s.subject_code, at.id, at.school_year, at.semester
     ORDER BY at.id DESC, s.subject_code, cs.section_name
     LIMIT 6"
);
$monitoringStmt->execute();
$monitoringSections = $monitoringStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$monitoringStmt->close();

$performanceStmt = $conn->prepare(
    'SELECT u.full_name, AVG(a.rating) AS average_rating, COUNT(DISTINCT ev.id) AS response_count
     FROM faculty f
     INNER JOIN users u ON u.id = f.user_id
     INNER JOIN class_sections cs ON cs.faculty_id = f.id
     INNER JOIN evaluations ev ON ev.class_section_id = cs.id
     INNER JOIN evaluation_answers a ON a.evaluation_id = ev.id
     GROUP BY f.id, u.full_name
     HAVING COUNT(DISTINCT ev.id) >= ?
     ORDER BY average_rating DESC, u.full_name
     LIMIT 6'
);
$performanceStmt->bind_param('i', $minimumResponses);
$performanceStmt->execute();
$performanceResult = $performanceStmt->get_result();
$performance = $performanceResult->fetch_all(MYSQLI_ASSOC);
$performanceStmt->close();

$periodResult = $conn->query(
    "SELECT ep.title, ep.start_date, ep.end_date, at.school_year, at.semester
     FROM evaluation_periods ep
     INNER JOIN academic_terms at ON at.id = ep.academic_term_id
     WHERE ep.status = 'open' AND CURDATE() BETWEEN ep.start_date AND ep.end_date
     ORDER BY ep.end_date ASC
     LIMIT 5"
);
$periods = $periodResult->fetch_all(MYSQLI_ASSOC);
$periodResult->free();

$activityResult = $conn->query(
    "SELECT ev.submitted_at, s.subject_code, cs.section_name, u.full_name AS faculty_name
     FROM evaluations ev
     INNER JOIN class_sections cs ON cs.id = ev.class_section_id
     INNER JOIN subjects s ON s.id = cs.subject_id
     INNER JOIN faculty f ON f.id = cs.faculty_id
     INNER JOIN users u ON u.id = f.user_id
     ORDER BY ev.submitted_at DESC
     LIMIT 6"
);
$activities = $activityResult->fetch_all(MYSQLI_ASSOC);
$activityResult->free();

$performanceNames = array_column($performance, 'full_name');
$performanceScores = array_map(static function (array $row): float {
    return round((float) $row['average_rating'], 2);
}, $performance);

renderPortalPage('Administrator dashboard', $user, static function () use (
    $user,
    $stats,
    $completionRate,
    $completedCount,
    $eligibleCount,
    $monitoringStudents,
    $monitoringFaculty,
    $monitoringSections,
    $performance,
    $performanceNames,
    $performanceScores,
    $periods,
    $activities
): void {
    ?>
    <section class="welcome-banner">
        <div class="welcome-copy">
            <p class="eyebrow">ADMINISTRATION</p>
            <h1>Good day, <?= htmlspecialchars(explode(' ', $user['full_name'])[0], ENT_QUOTES, 'UTF-8') ?></h1>
            <p>Here is the current picture of your academic evaluation activity.</p>
        </div>
        <div class="welcome-decoration" aria-hidden="true"><i class="bi bi-mortarboard"></i></div>
    </section>

    <section class="stats-grid" aria-label="System statistics">
        <?php
        dashboardStat('Students', number_format($stats['students']), 'people', 'blue', 'Registered student profiles');
        dashboardStat('Faculty members', number_format($stats['faculty']), 'person-video3', 'violet', 'Registered faculty profiles');
        dashboardStat('Departments', number_format($stats['departments']), 'diagram-3', 'amber', 'Academic departments');
        dashboardStat('Subjects', number_format($stats['subjects']), 'book', 'green', 'Available subjects');
        dashboardStat('Evaluations submitted', number_format($stats['evaluations']), 'clipboard-check', 'cyan', 'All recorded submissions');
        dashboardStat('Pending evaluations', number_format($stats['pending']), 'hourglass-split', 'rose', 'Assignments in open periods');
        dashboardStat('Active periods', number_format($stats['periods']), 'calendar-event', 'blue', 'Open for evaluation today');
        dashboardStat('Completed periods', number_format($stats['completed_periods']), 'calendar-check', 'amber', 'Closed or past their end date');
        ?>
    </section>

    <div class="d-flex justify-content-end mb-4">
        <a class="btn btn-primary" href="<?= htmlspecialchars(appPath('admin/reports.php?view=reports'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-bar-chart me-2" aria-hidden="true"></i>Open evaluation reports</a>
    </div>

    <section class="dashboard-grid dashboard-grid-charts">
        <article class="content-card chart-card">
            <div class="card-heading">
                <div><p class="eyebrow">LIVE PERIODS</p><h2>Evaluation completion</h2></div>
                <span class="card-heading-icon"><i class="bi bi-pie-chart" aria-hidden="true"></i></span>
            </div>
            <?php if ($eligibleCount > 0): ?>
                <div class="completion-chart-wrap">
                    <canvas id="completion-chart" aria-label="Completed and pending evaluations"></canvas>
                    <div class="completion-center"><strong><?= $completionRate ?>%</strong><span>completed</span></div>
                </div>
                <div class="chart-legend">
                    <span><i class="legend-dot legend-blue"></i><?= number_format($completedCount) ?> completed</span>
                    <span><i class="legend-dot legend-light"></i><?= number_format($eligibleCount - $completedCount) ?> pending</span>
                </div>
            <?php else: ?>
                <?php dashboardEmptyState('No student assignments are currently eligible for an open evaluation period.'); ?>
            <?php endif; ?>
        </article>
        <article class="content-card chart-card">
            <div class="card-heading">
                <div><p class="eyebrow">CONFIDENTIAL SUMMARY</p><h2>Faculty performance</h2></div>
                <span class="card-heading-icon"><i class="bi bi-bar-chart-line" aria-hidden="true"></i></span>
            </div>
            <?php if ($performance !== []): ?>
                <div class="performance-chart-wrap"><canvas id="performance-chart" aria-label="Faculty average evaluations"></canvas></div>
                <p class="chart-note">Results are shown only after at least <?= (int) (getenv('MIN_EVALUATIONS_FOR_FACULTY_RESULTS') ?: 5) ?> submissions.</p>
            <?php else: ?>
                <?php dashboardEmptyState('Faculty results will appear after the minimum response threshold is reached.'); ?>
            <?php endif; ?>
        </article>
    </section>

    <section class="content-card admin-monitoring-card">
        <div class="card-heading">
            <div><p class="eyebrow">EVALUATION MONITORING</p><h2>Live completion overview</h2></div>
            <a class="btn btn-outline-primary" href="<?= htmlspecialchars(appPath('admin/reports.php?view=completion'), ENT_QUOTES, 'UTF-8') ?>">Filter all sections</a>
        </div>
        <div class="report-summary-grid">
            <?php
            dashboardStat('Enrolled students', number_format($monitoringStudents), 'people', 'blue', 'In currently open periods');
            dashboardStat('Assigned faculty', number_format($monitoringFaculty), 'person-video3', 'violet', 'Teaching eligible sections');
            dashboardStat('Expected evaluations', number_format($eligibleCount), 'clipboard-check', 'cyan', 'Enrolled student and section-period opportunities');
            dashboardStat('Completed / pending', number_format($completedCount) . ' / ' . number_format(max(0, $eligibleCount - $completedCount)), 'hourglass-split', 'amber', number_format($completionRate, 1) . '% completed');
            ?>
        </div>
        <?php if ($monitoringSections !== []): ?>
            <div class="table-responsive mt-4">
                <table class="table portal-table align-middle mb-0">
                    <thead><tr><th>Subject / section</th><th>Academic term</th><th>Expected</th><th>Completed</th><th>Pending</th><th>Completion</th></tr></thead>
                    <tbody>
                    <?php foreach ($monitoringSections as $section): ?>
                        <?php
                        $expected = (int) $section['expected'];
                        $completed = (int) $section['completed'];
                        $sectionRate = $expected > 0 ? round($completed / $expected * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($section['subject_code'], ENT_QUOTES, 'UTF-8') ?></strong><span class="table-subtext"><?= htmlspecialchars($section['section_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars($section['school_year'] . ' · ' . $section['semester'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format($expected) ?></td>
                            <td><?= number_format($completed) ?></td>
                            <td><?= number_format(max(0, $expected - $completed)) ?></td>
                            <td><?= number_format($sectionRate, 1) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No section currently has an open evaluation period.</p>
        <?php endif; ?>
    </section>

    <section class="dashboard-grid dashboard-grid-lower">
        <article class="content-card">
            <div class="card-heading">
                <div><p class="eyebrow">LATEST SUBMISSIONS</p><h2>Recent evaluation activity</h2></div>
            </div>
            <?php if ($activities === []): ?>
                <?php dashboardEmptyState('There are no submitted evaluations yet.'); ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table portal-table align-middle mb-0">
                        <thead><tr><th>Faculty</th><th>Class</th><th>Submitted</th></tr></thead>
                        <tbody>
                        <?php foreach ($activities as $activity): ?>
                            <tr>
                                <td><?= htmlspecialchars($activity['faculty_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($activity['subject_code'] . ' · ' . $activity['section_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $activity['submitted_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
        <article class="content-card">
            <div class="card-heading">
                <div><p class="eyebrow">NOW ACCEPTING</p><h2>Active evaluation periods</h2></div>
                <span class="card-heading-icon"><i class="bi bi-calendar2-week" aria-hidden="true"></i></span>
            </div>
            <?php if ($periods === []): ?>
                <?php dashboardEmptyState('No evaluation periods are open today.'); ?>
            <?php else: ?>
                <div class="period-list">
                    <?php foreach ($periods as $period): ?>
                        <div class="period-item">
                            <span class="period-icon"><i class="bi bi-calendar-check" aria-hidden="true"></i></span>
                            <div>
                                <strong><?= htmlspecialchars($period['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars($period['school_year'] . ' · ' . $period['semester'], ENT_QUOTES, 'UTF-8') ?></span>
                                <small>Closes <?= htmlspecialchars($period['end_date'], ENT_QUOTES, 'UTF-8') ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="active-period-count"><strong><?= (int) $stats['periods'] ?></strong><span>active period<?= $stats['periods'] === 1 ? '' : 's' ?> today</span></div>
        </article>
    </section>

    <?php if ($performance !== []): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const canvas = document.getElementById('performance-chart');
            if (!canvas || typeof Chart === 'undefined') return;
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($performanceNames, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?>,
                    datasets: [{
                        data: <?= json_encode($performanceScores, JSON_THROW_ON_ERROR) ?>,
                        backgroundColor: '#1976d2',
                        borderRadius: 7,
                        maxBarThickness: 30
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { min: 0, max: 5, grid: { color: '#edf1f7' }, ticks: { stepSize: 1 } },
                        y: { grid: { display: false } }
                    }
                }
            });
        });
        </script>
    <?php endif; ?>
    <?php if ($eligibleCount > 0): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const canvas = document.getElementById('completion-chart');
            if (!canvas || typeof Chart === 'undefined') return;
            new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: ['Completed', 'Pending'],
                    datasets: [{ data: [<?= $completedCount ?>, <?= $eligibleCount - $completedCount ?>], backgroundColor: ['#1976d2', '#e7edf5'], borderWidth: 0 }]
                },
                options: { cutout: '78%', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { enabled: true } } }
            });
        });
        </script>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
    <?php
});
