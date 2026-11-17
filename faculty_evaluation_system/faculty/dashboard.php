<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal.php';
require_once __DIR__ . '/../config/database.php';

$user = requireRole('faculty');
$userId = (int) $user['id'];
$minimumResponses = max(1, (int) (getenv('MIN_EVALUATIONS_FOR_FACULTY_RESULTS') ?: 5));

$profileStmt = $conn->prepare(
    'SELECT f.id, f.employee_number, d.department_name
     FROM faculty f
     LEFT JOIN departments d ON d.id = f.department_id
     WHERE f.user_id = ?
     LIMIT 1'
);
$profileStmt->bind_param('i', $userId);
$profileStmt->execute();
$faculty = $profileStmt->get_result()->fetch_assoc();
$profileStmt->close();

$responseCount = 0;
$overallAverage = null;
$performanceRating = '';
$sections = [];
$categories = [];
$history = [];

if ($faculty !== null) {
    $facultyId = (int) $faculty['id'];
    $summaryStmt = $conn->prepare(
        'SELECT COUNT(DISTINCT ev.id) AS responses, AVG(a.rating) AS average_rating
         FROM class_sections cs
         INNER JOIN evaluations ev ON ev.class_section_id = cs.id
         INNER JOIN evaluation_periods ep
             ON ep.id = ev.evaluation_period_id
            AND ep.academic_term_id = cs.academic_term_id
         LEFT JOIN evaluation_answers a
             ON a.evaluation_id = ev.id
            AND a.rating BETWEEN 1 AND 5
         WHERE cs.faculty_id = ?'
    );
    $summaryStmt->bind_param('i', $facultyId);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();
    $responseCount = (int) ($summary['responses'] ?? 0);
    if ($responseCount >= $minimumResponses && $summary['average_rating'] !== null) {
        $overallAverage = (float) $summary['average_rating'];
        if ($overallAverage >= 4.5) {
            $performanceRating = 'Excellent';
        } elseif ($overallAverage >= 3.5) {
            $performanceRating = 'Very Good';
        } elseif ($overallAverage >= 2.5) {
            $performanceRating = 'Good';
        } elseif ($overallAverage >= 1.5) {
            $performanceRating = 'Fair';
        } else {
            $performanceRating = 'Poor';
        }
    }

    $sectionsStmt = $conn->prepare(
        'SELECT cs.section_name, s.subject_code, s.subject_name, at.school_year, at.semester
         FROM class_sections cs
         INNER JOIN subjects s ON s.id = cs.subject_id
         INNER JOIN academic_terms at ON at.id = cs.academic_term_id
         WHERE cs.faculty_id = ?
         ORDER BY at.id DESC, s.subject_code, cs.section_name'
    );
    $sectionsStmt->bind_param('i', $facultyId);
    $sectionsStmt->execute();
    $sections = $sectionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sectionsStmt->close();

    if ($responseCount >= $minimumResponses) {
        $categoryStmt = $conn->prepare(
            'SELECT q.category, AVG(a.rating) AS average_rating
             FROM class_sections cs
             INNER JOIN evaluations ev ON ev.class_section_id = cs.id
             INNER JOIN evaluation_periods ep
                 ON ep.id = ev.evaluation_period_id
                AND ep.academic_term_id = cs.academic_term_id
             INNER JOIN evaluation_answers a
                 ON a.evaluation_id = ev.id
                AND a.rating BETWEEN 1 AND 5
             INNER JOIN evaluation_questions q ON q.id = a.question_id
             WHERE cs.faculty_id = ?
             GROUP BY q.category
             ORDER BY q.category'
        );
        $categoryStmt->bind_param('i', $facultyId);
        $categoryStmt->execute();
        $categories = $categoryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $categoryStmt->close();

        $historyStmt = $conn->prepare(
            'SELECT at.school_year, at.semester, AVG(a.rating) AS average_rating,
                    COUNT(DISTINCT ev.id) AS responses
             FROM class_sections cs
             INNER JOIN academic_terms at ON at.id = cs.academic_term_id
             INNER JOIN evaluations ev ON ev.class_section_id = cs.id
             INNER JOIN evaluation_periods ep
                 ON ep.id = ev.evaluation_period_id
                AND ep.academic_term_id = cs.academic_term_id
             INNER JOIN evaluation_answers a
                 ON a.evaluation_id = ev.id
                AND a.rating BETWEEN 1 AND 5
             WHERE cs.faculty_id = ?
             GROUP BY at.id, at.school_year, at.semester
             HAVING COUNT(DISTINCT ev.id) >= ?
             ORDER BY at.id'
        );
        $historyStmt->bind_param('ii', $facultyId, $minimumResponses);
        $historyStmt->execute();
        $history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $historyStmt->close();

    }
}

$historyLabels = array_map(static function (array $row): string {
    return $row['school_year'] . ' · ' . $row['semester'];
}, $history);
$historyScores = array_map(static function (array $row): float {
    return round((float) $row['average_rating'], 2);
}, $history);

renderPortalPage('Faculty dashboard', $user, static function () use (
    $user,
    $faculty,
    $responseCount,
    $minimumResponses,
    $overallAverage,
    $performanceRating,
    $sections,
    $categories,
    $history,
    $historyLabels,
    $historyScores
): void {
    ?>
    <section class="faculty-welcome">
        <div>
            <p class="eyebrow">FACULTY PORTAL</p>
            <h1>Welcome, <?= htmlspecialchars(explode(' ', $user['full_name'])[0], ENT_QUOTES, 'UTF-8') ?></h1>
            <p>Your teaching feedback and course assignments, in one place.</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <?php if ($faculty !== null): ?>
                <a class="btn btn-primary" href="<?= htmlspecialchars(appPath('faculty/results.php'), ENT_QUOTES, 'UTF-8') ?>">Detailed results</a>
            <?php endif; ?>
            <span class="faculty-welcome-icon" aria-hidden="true"><i class="bi bi-mortarboard"></i></span>
        </div>
    </section>

    <?php if ($faculty === null): ?>
        <div class="alert alert-warning portal-alert" role="alert">Your account is not linked to a faculty profile yet. Please contact the system administrator.</div>
    <?php endif; ?>

    <section class="faculty-summary-grid">
        <article class="faculty-profile-card">
            <span class="faculty-profile-avatar"><i class="bi bi-person-workspace" aria-hidden="true"></i></span>
            <div>
                <p class="eyebrow">FACULTY PROFILE</p>
                <h2><?= htmlspecialchars($faculty['employee_number'] ?? 'Profile pending', ENT_QUOTES, 'UTF-8') ?></h2>
                <p><?= htmlspecialchars($faculty['department_name'] ?? 'Department not assigned', ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </article>
        <div class="faculty-metrics">
            <?php
            dashboardStat('Overall average', $overallAverage === null ? '—' : number_format($overallAverage, 2) . ' / 5', 'star', 'blue', $overallAverage === null ? 'Hidden until response threshold' : $performanceRating);
            dashboardStat('Evaluation responses', number_format($responseCount), 'clipboard-check', 'violet', 'Anonymous submissions');
            dashboardStat('Assigned classes', count($sections), 'bookshelf', 'green', 'All academic terms');
            ?>
        </div>
    </section>

    <?php if ($responseCount < $minimumResponses): ?>
        <aside class="privacy-note mb-4"><i class="bi bi-shield-lock" aria-hidden="true"></i><span>Individual results remain private until at least <?= $minimumResponses ?> evaluations have been submitted. Current response count: <?= $responseCount ?>.</span></aside>
    <?php endif; ?>

    <section class="dashboard-grid dashboard-grid-charts" id="performance">
        <article class="content-card">
            <div class="card-heading">
                <div><p class="eyebrow">EVALUATION BREAKDOWN</p><h2>Performance by category</h2></div>
                <span class="card-heading-icon"><i class="bi bi-clipboard-data" aria-hidden="true"></i></span>
            </div>
            <?php if ($responseCount < $minimumResponses): ?>
                <?php dashboardEmptyState('Category results are hidden to protect student anonymity.'); ?>
            <?php elseif ($categories === []): ?>
                <?php dashboardEmptyState('No category ratings are available yet.'); ?>
            <?php else: ?>
                <div class="category-list">
                    <?php foreach ($categories as $category): ?>
                        <?php $score = (float) $category['average_rating']; ?>
                        <div class="category-row">
                            <div class="category-label"><span><?= htmlspecialchars($category['category'], ENT_QUOTES, 'UTF-8') ?></span><strong><?= number_format($score, 2) ?></strong></div>
                            <div class="category-track"><span style="width: <?= max(0, min(100, $score / 5 * 100)) ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
        <article class="content-card">
            <div class="card-heading">
                <div><p class="eyebrow">ACADEMIC TERMS</p><h2>Historical performance</h2></div>
                <span class="card-heading-icon"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></span>
            </div>
            <?php if ($responseCount < $minimumResponses): ?>
                <?php dashboardEmptyState('Historical results will appear when the privacy threshold is met.'); ?>
            <?php elseif ($history === []): ?>
                <?php dashboardEmptyState('There are no term summaries with enough responses yet.'); ?>
            <?php else: ?>
                <div class="history-chart-wrap"><canvas id="history-chart" aria-label="Average evaluation scores by academic term"></canvas></div>
            <?php endif; ?>
        </article>
    </section>

    <section class="dashboard-grid dashboard-grid-lower faculty-lower-grid">
        <article class="content-card" id="assignments">
            <div class="card-heading"><div><p class="eyebrow">TEACHING ASSIGNMENTS</p><h2>Subjects & sections</h2></div></div>
            <?php if ($sections === []): ?>
                <?php dashboardEmptyState('No assigned subjects or class sections were found.'); ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table portal-table align-middle mb-0">
                        <thead><tr><th>Subject</th><th>Section</th><th>Academic term</th></tr></thead>
                        <tbody>
                        <?php foreach ($sections as $section): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($section['subject_code'], ENT_QUOTES, 'UTF-8') ?></strong><span class="table-subtext"><?= htmlspecialchars($section['subject_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars($section['section_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($section['school_year'] . ' · ' . $section['semester'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <?php if ($responseCount >= $minimumResponses && $history !== []): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const canvas = document.getElementById('history-chart');
            if (!canvas || typeof Chart === 'undefined') return;
            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: <?= json_encode($historyLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?>,
                    datasets: [{
                        data: <?= json_encode($historyScores, JSON_THROW_ON_ERROR) ?>,
                        borderColor: '#1976d2',
                        backgroundColor: 'rgba(25, 118, 210, .1)',
                        fill: true,
                        tension: .35,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { min: 0, max: 5, grid: { color: '#edf1f7' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        });
        </script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
    <?php endif; ?>
    <?php
});
