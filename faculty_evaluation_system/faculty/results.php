<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal.php';
require_once __DIR__ . '/../config/database.php';

$user = requireRole('faculty');
$minimumResponses = max(1, (int) (getenv('MIN_EVALUATIONS_FOR_FACULTY_RESULTS') ?: 5));

function facultyResultsBind(mysqli_stmt $stmt, string $types, array &$values): void
{
    $args = [$types];
    foreach ($values as &$value) {
        $args[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $args);
}

function facultyResultsCsvValue($value): string
{
    $value = (string) $value;
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }

    return $value;
}

$profileStmt = $conn->prepare('SELECT id FROM faculty WHERE user_id = ? LIMIT 1');
$userId = (int) $user['id'];
$profileStmt->bind_param('i', $userId);
$profileStmt->execute();
$faculty = $profileStmt->get_result()->fetch_assoc();
$profileStmt->close();

$term = filter_input(INPUT_GET, 'term', FILTER_VALIDATE_INT);
$term = $term !== false && $term !== null && $term > 0 ? $term : 0;
$period = filter_input(INPUT_GET, 'period', FILTER_VALIDATE_INT);
$period = $period !== false && $period !== null && $period > 0 ? $period : 0;
$termsResult = $conn->query('SELECT id, school_year, semester FROM academic_terms ORDER BY id DESC');
$terms = $termsResult->fetch_all(MYSQLI_ASSOC);
$termsResult->free();
$periodsResult = $conn->query(
    'SELECT ep.id, ep.title, ep.academic_term_id, at.school_year, at.semester
     FROM evaluation_periods ep
     INNER JOIN academic_terms at ON at.id = ep.academic_term_id
     ORDER BY at.id DESC, ep.start_date DESC, ep.id DESC'
);
$periods = $periodsResult->fetch_all(MYSQLI_ASSOC);
$periodsResult->free();

$responseCount = 0;
$averageRating = null;
$answerCount = 0;
$questions = [];
$categories = [];
$sectionResults = [];
$termResults = [];
$ratingDistribution = array_fill(1, 5, 0);

if ($faculty !== null) {
    $facultyId = (int) $faculty['id'];
    $filterSql = '';
    $filterParams = [$facultyId];
    $filterTypes = 'i';
    if ($term > 0) {
        $filterSql .= ' AND at.id = ?';
        $filterParams[] = $term;
        $filterTypes .= 'i';
    }
    if ($period > 0) {
        $filterSql .= ' AND ep.id = ?';
        $filterParams[] = $period;
        $filterTypes .= 'i';
    }
    $baseSql = 'FROM class_sections cs
                INNER JOIN academic_terms at ON at.id = cs.academic_term_id
                INNER JOIN evaluations ev ON ev.class_section_id = cs.id
                INNER JOIN evaluation_periods ep
                    ON ep.id = ev.evaluation_period_id
                   AND ep.academic_term_id = cs.academic_term_id
                LEFT JOIN evaluation_answers a
                    ON a.evaluation_id = ev.id
                   AND a.rating BETWEEN 1 AND 5';

    $summaryStmt = $conn->prepare(
        "SELECT COUNT(DISTINCT ev.id) AS responses, COUNT(a.id) AS answer_count,
                AVG(a.rating) AS average_rating
         $baseSql
         WHERE cs.faculty_id = ? $filterSql"
    );
    $summaryParams = $filterParams;
    facultyResultsBind($summaryStmt, $filterTypes, $summaryParams);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc();
    $summaryStmt->close();
    $responseCount = (int) ($summary['responses'] ?? 0);
    $answerCount = (int) ($summary['answer_count'] ?? 0);
    $averageRating = $summary['average_rating'] !== null ? (float) $summary['average_rating'] : null;

    if ($responseCount >= $minimumResponses) {
        $sectionStmt = $conn->prepare(
            "SELECT s.subject_code, s.subject_name, cs.section_name,
                    at.school_year, at.semester, ep.title AS period_title,
                    COUNT(DISTINCT ev.id) AS responses, AVG(a.rating) AS average_rating
             $baseSql
             INNER JOIN subjects s ON s.id = cs.subject_id
             WHERE cs.faculty_id = ? $filterSql
             GROUP BY cs.id, s.subject_code, s.subject_name, cs.section_name,
                      at.id, at.school_year, at.semester, ep.id, ep.title
             HAVING COUNT(DISTINCT ev.id) >= ?
             ORDER BY at.id DESC, ep.start_date DESC, s.subject_code, cs.section_name"
        );
        $sectionParams = $filterParams;
        $sectionParams[] = $minimumResponses;
        facultyResultsBind($sectionStmt, $filterTypes . 'i', $sectionParams);
        $sectionStmt->execute();
        $sectionResults = $sectionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sectionStmt->close();

        $questionStmt = $conn->prepare(
            "SELECT q.category, q.question_text, COUNT(DISTINCT ev.id) AS responses,
                    COUNT(a.id) AS answer_count, AVG(a.rating) AS average_rating
             $baseSql
             INNER JOIN evaluation_questions q ON q.id = a.question_id
             WHERE cs.faculty_id = ? $filterSql
             GROUP BY q.id, q.category, q.question_text, q.sort_order
             HAVING COUNT(DISTINCT ev.id) >= ?
             ORDER BY q.category, q.sort_order, q.id"
        );
        $questionParams = $filterParams;
        $questionParams[] = $minimumResponses;
        facultyResultsBind($questionStmt, $filterTypes . 'i', $questionParams);
        $questionStmt->execute();
        $questions = $questionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $questionStmt->close();

        $categoryStmt = $conn->prepare(
            "SELECT q.category, COUNT(DISTINCT ev.id) AS responses,
                    COUNT(a.id) AS answer_count, AVG(a.rating) AS average_rating
             $baseSql
             INNER JOIN evaluation_questions q ON q.id = a.question_id
             WHERE cs.faculty_id = ? $filterSql
             GROUP BY q.category
             HAVING COUNT(DISTINCT ev.id) >= ?
             ORDER BY q.category"
        );
        $categoryParams = $filterParams;
        $categoryParams[] = $minimumResponses;
        facultyResultsBind($categoryStmt, $filterTypes . 'i', $categoryParams);
        $categoryStmt->execute();
        $categories = $categoryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $categoryStmt->close();

        $termResultsStmt = $conn->prepare(
            "SELECT at.school_year, at.semester, COUNT(DISTINCT ev.id) AS responses,
                    AVG(a.rating) AS average_rating
             $baseSql
             WHERE cs.faculty_id = ? $filterSql
             GROUP BY at.id, at.school_year, at.semester
             HAVING COUNT(DISTINCT ev.id) >= ?
             ORDER BY at.id"
        );
        $termParams = $filterParams;
        $termParams[] = $minimumResponses;
        facultyResultsBind($termResultsStmt, $filterTypes . 'i', $termParams);
        $termResultsStmt->execute();
        $termResults = $termResultsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $termResultsStmt->close();

        $distributionStmt = $conn->prepare(
            "SELECT a.rating, COUNT(*) AS rating_count
             $baseSql
             WHERE cs.faculty_id = ? $filterSql
               AND a.rating BETWEEN 1 AND 5
             GROUP BY a.rating
             ORDER BY a.rating"
        );
        $distributionParams = $filterParams;
        facultyResultsBind($distributionStmt, $filterTypes, $distributionParams);
        $distributionStmt->execute();
        foreach ($distributionStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $distributionRow) {
            $ratingDistribution[(int) $distributionRow['rating']] = (int) $distributionRow['rating_count'];
        }
        $distributionStmt->close();
    }
}

$performanceSummary = 'Not enough responses';
if ($responseCount >= $minimumResponses && $averageRating !== null) {
    $performanceSummary = $averageRating >= 4.5
        ? 'Excellent'
        : ($averageRating >= 3.5
            ? 'Very Good'
            : ($averageRating >= 2.5
                ? 'Good'
                : ($averageRating >= 1.5 ? 'Fair' : 'Poor')));
}
$termLabels = array_map(static function (array $row): string {
    return $row['school_year'] . ' · ' . $row['semester'];
}, $termResults);
$termScores = array_map(static function (array $row): float {
    return round((float) $row['average_rating'], 2);
}, $termResults);
$ratingValues = array_values($ratingDistribution);

if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'], true)) {
    if ($faculty === null || $responseCount < $minimumResponses) {
        http_response_code(403);
        exit('Aggregated results are not available for export.');
    }

    $exportHeaders = [
        'Summary type', 'Subject', 'Section', 'Academic term', 'Evaluation period',
        'Category', 'Question', 'Responses', 'Answer count', 'Average rating',
    ];
    $exportRows = [];
    foreach ($sectionResults as $sectionResult) {
        $exportRows[] = [
            'Class section',
            $sectionResult['subject_code'] . ' · ' . $sectionResult['subject_name'],
            $sectionResult['section_name'],
            $sectionResult['school_year'] . ' ' . $sectionResult['semester'],
            $sectionResult['period_title'],
            '',
            '',
            $sectionResult['responses'],
            '',
            $sectionResult['average_rating'] === null ? '' : number_format((float) $sectionResult['average_rating'], 2),
        ];
    }
    foreach ($categories as $category) {
        $exportRows[] = [
            'Category', '', '', '', '', $category['category'], '',
            $category['responses'], $category['answer_count'],
            number_format((float) $category['average_rating'], 2),
        ];
    }
    foreach ($questions as $question) {
        $exportRows[] = [
            'Question',
            '',
            '',
            '',
            '',
            $question['category'],
            $question['question_text'],
            $question['responses'],
            $question['answer_count'],
            number_format((float) $question['average_rating'], 2),
        ];
    }
    foreach ($termResults as $termResult) {
        $exportRows[] = [
            'Academic term', '', '',
            $termResult['school_year'] . ' ' . $termResult['semester'],
            '', '', '', $termResult['responses'], '',
            number_format((float) $termResult['average_rating'], 2),
        ];
    }
    foreach ($ratingDistribution as $rating => $count) {
        $exportRows[] = [
            'Rating distribution', '', '', '', '', '', (string) $rating . ' / 5',
            $responseCount, $count, '',
        ];
    }

    if ($_GET['export'] === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="my-evaluation-results.xls"');
        echo '<!doctype html><html><head><meta charset="utf-8"></head><body><table><thead><tr>';
        foreach ($exportHeaders as $header) {
            echo '<th>' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($exportRows as $row) {
            echo '<tr>';
            foreach ($row as $value) {
                echo '<td>' . htmlspecialchars(facultyResultsCsvValue($value), ENT_QUOTES, 'UTF-8') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-evaluation-results.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to open the CSV output stream.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $exportHeaders);
    foreach ($exportRows as $row) {
        fputcsv($output, array_map('facultyResultsCsvValue', $row));
    }
    fclose($output);
    exit;
}

$resultUrl = appPath('faculty/results.php');
$exportQuery = http_build_query([
    'term' => $term ?: null,
    'period' => $period ?: null,
    'export' => 'csv',
]);
$exportUrl = $resultUrl . '?' . $exportQuery;
$excelExportUrl = $resultUrl . '?' . http_build_query([
    'term' => $term ?: null,
    'period' => $period ?: null,
    'export' => 'excel',
]);

renderPortalPage('My evaluation results', $user, static function () use (
    $faculty,
    $terms,
    $periods,
    $term,
    $period,
    $responseCount,
    $minimumResponses,
    $averageRating,
    $answerCount,
    $questions,
    $categories,
    $sectionResults,
    $termResults,
    $termLabels,
    $termScores,
    $ratingDistribution,
    $ratingValues,
    $performanceSummary,
    $resultUrl,
    $exportUrl,
    $excelExportUrl
): void {
    ?>
    <section class="page-heading-row">
        <div>
            <p class="eyebrow">PRIVATE FACULTY REPORT</p>
            <h1>My evaluation results</h1>
            <p>Only aggregate ratings for your own assigned sections are shown. Student identities and individual submissions are never included.</p>
        </div>
        <?php if ($responseCount >= $minimumResponses): ?>
            <div class="report-heading-actions no-print">
                <button class="btn btn-outline-secondary" type="button" data-print-page><i class="bi bi-printer me-2" aria-hidden="true"></i>Print / Save PDF</button>
                <a class="btn btn-outline-primary" href="<?= htmlspecialchars($excelExportUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-file-earmark-spreadsheet me-2" aria-hidden="true"></i>Excel</a>
                <a class="btn btn-primary" href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-download me-2" aria-hidden="true"></i>CSV</a>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($faculty === null): ?>
        <div class="alert alert-warning portal-alert" role="alert">Your account is not linked to a faculty profile. Please contact the system administrator.</div>
    <?php else: ?>
        <section class="content-card report-filter-card no-print">
            <form method="get" action="<?= htmlspecialchars($resultUrl, ENT_QUOTES, 'UTF-8') ?>" class="report-filter-form">
                <div>
                    <label class="form-label" for="faculty-term">Academic term</label>
                    <select class="form-select" id="faculty-term" name="term">
                        <option value="">All terms</option>
                        <?php foreach ($terms as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"<?= $term === (int) $option['id'] ? ' selected' : '' ?>><?= htmlspecialchars($option['school_year'] . ' · ' . $option['semester'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="faculty-period">Evaluation period</label>
                    <select class="form-select" id="faculty-period" name="period">
                        <option value="">All periods</option>
                        <?php foreach ($periods as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"<?= $period === (int) $option['id'] ? ' selected' : '' ?>><?= htmlspecialchars($option['title'] . ' · ' . $option['school_year'] . ' · ' . $option['semester'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary report-filter-button" type="submit"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Apply filters</button>
            </form>
        </section>

        <section class="report-summary-grid">
            <?php
            dashboardStat('Evaluation responses', number_format($responseCount), 'clipboard-check', 'blue', 'Anonymous submissions');
            dashboardStat('Overall average', $responseCount >= $minimumResponses && $averageRating !== null ? number_format($averageRating, 2) . ' / 5' : '—', 'star', 'violet', 'Based on recorded answers');
            dashboardStat('Recorded answers', $responseCount >= $minimumResponses ? number_format($answerCount) : '—', 'patch-question', 'green', 'Shown only after privacy threshold');
            dashboardStat('Performance summary', $responseCount >= $minimumResponses ? $performanceSummary : '—', 'award', 'amber', 'Existing project rating guide');
            ?>
        </section>

        <?php if ($responseCount < $minimumResponses): ?>
            <aside class="privacy-note"><i class="bi bi-shield-lock" aria-hidden="true"></i><span>Rating details are hidden until at least <?= $minimumResponses ?> evaluations match the selected filters. Current response count: <?= $responseCount ?>.</span></aside>
        <?php else: ?>
            <section class="dashboard-grid dashboard-grid-charts mt-4">
                <article class="content-card">
                    <div class="card-heading"><div><p class="eyebrow">RATING DISTRIBUTION</p><h2>Recorded answers by rating</h2></div></div>
                    <div class="faculty-result-chart"><canvas id="faculty-rating-chart" aria-label="Distribution of submitted ratings"></canvas></div>
                    <div class="table-responsive mt-3">
                        <table class="table portal-table align-middle mb-0">
                            <thead><tr><th>Rating</th><th>Answer count</th></tr></thead>
                            <tbody>
                            <?php foreach ($ratingDistribution as $rating => $count): ?>
                                <tr><td><?= $rating ?> / 5</td><td><?= number_format($count) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
                <article class="content-card">
                    <div class="card-heading"><div><p class="eyebrow">TERM COMPARISON</p><h2>Results by academic term</h2></div></div>
                    <?php if ($termResults === []): ?>
                        <?php dashboardEmptyState('No individual term group meets the privacy threshold for these filters.'); ?>
                    <?php else: ?>
                        <div class="faculty-result-chart"><canvas id="faculty-term-chart" aria-label="Average ratings by academic term"></canvas></div>
                        <div class="table-responsive mt-3">
                            <table class="table portal-table align-middle mb-0">
                                <thead><tr><th>Academic term</th><th>Responses</th><th>Average rating</th></tr></thead>
                                <tbody>
                                <?php foreach ($termResults as $termResult): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($termResult['school_year'] . ' · ' . $termResult['semester'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= number_format((int) $termResult['responses']) ?></td>
                                        <td><?= number_format((float) $termResult['average_rating'], 2) ?> / 5</td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
            <section class="content-card mt-4">
                <div class="card-heading"><div><p class="eyebrow">ASSIGNED SECTION SUMMARIES</p><h2>Results by subject and class section</h2></div></div>
                <?php if ($sectionResults === []): ?>
                    <?php dashboardEmptyState('No class-section group meets the privacy threshold for these filters.'); ?>
                <?php else: ?>
                    <div class="table-responsive mb-4">
                        <table class="table portal-table align-middle mb-0">
                            <thead><tr><th>Subject</th><th>Section</th><th>Academic term</th><th>Evaluation period</th><th>Responses</th><th>Average rating</th></tr></thead>
                            <tbody>
                            <?php foreach ($sectionResults as $sectionResult): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($sectionResult['subject_code'], ENT_QUOTES, 'UTF-8') ?></strong><span class="table-subtext"><?= htmlspecialchars($sectionResult['subject_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars($sectionResult['section_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($sectionResult['school_year'] . ' · ' . $sectionResult['semester'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($sectionResult['period_title'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= number_format((int) $sectionResult['responses']) ?></td>
                                    <td><?= $sectionResult['average_rating'] === null ? '—' : number_format((float) $sectionResult['average_rating'], 2) . ' / 5' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="card-heading"><div><p class="eyebrow">CATEGORY SUMMARY</p><h2>Average rating by category</h2></div></div>
                <?php if ($categories === []): ?>
                    <?php dashboardEmptyState('No category ratings meet the privacy threshold for these filters.'); ?>
                <?php else: ?>
                    <div class="table-responsive mb-4">
                        <table class="table portal-table align-middle mb-0">
                            <thead><tr><th>Category</th><th>Responses</th><th>Average rating</th></tr></thead>
                            <tbody>
                            <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td><?= htmlspecialchars($category['category'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= number_format((int) $category['responses']) ?></td>
                                    <td><?= number_format((float) $category['average_rating'], 2) ?> / 5</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="card-heading"><div><p class="eyebrow">AGGREGATED QUESTION RATINGS</p><h2>Average rating per question</h2></div></div>
                <?php if ($questions === []): ?>
                    <?php dashboardEmptyState('No question ratings meet the privacy threshold for these filters.'); ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table portal-table align-middle mb-0">
                            <thead><tr><th>Category</th><th>Question</th><th>Responses</th><th>Average rating</th></tr></thead>
                            <tbody>
                            <?php foreach ($questions as $question): ?>
                                <tr>
                                    <td><?= htmlspecialchars($question['category'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($question['question_text'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= number_format((int) $question['responses']) ?></td>
                                    <td><?= number_format((float) $question['average_rating'], 2) ?> / 5</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <p class="small text-muted mt-3 mb-0">Rating scale: 1 Poor, 2 Fair, 3 Good, 4 Very Good, 5 Excellent.</p>
            </section>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof Chart === 'undefined') return;
                const ratingCanvas = document.getElementById('faculty-rating-chart');
                if (ratingCanvas) {
                    new Chart(ratingCanvas, {
                        type: 'bar',
                        data: {
                            labels: ['1 · Poor', '2 · Fair', '3 · Good', '4 · Very Good', '5 · Excellent'],
                            datasets: [{ data: <?= json_encode($ratingValues, JSON_THROW_ON_ERROR) ?>, backgroundColor: '#1976d2', borderRadius: 6 }]
                        },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
                    });
                }
                const termCanvas = document.getElementById('faculty-term-chart');
                if (termCanvas) {
                    new Chart(termCanvas, {
                        type: 'line',
                        data: {
                            labels: <?= json_encode($termLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?>,
                            datasets: [{ data: <?= json_encode($termScores, JSON_THROW_ON_ERROR) ?>, borderColor: '#1976d2', backgroundColor: 'rgba(25,118,210,.12)', fill: true, tension: .3 }]
                        },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 5 } } }
                    });
                }
            });
            </script>
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
        <?php endif; ?>
    <?php endif; ?>
    <?php
});
