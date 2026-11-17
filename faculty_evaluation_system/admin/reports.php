<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal.php';
require_once __DIR__ . '/../config/database.php';

$admin = requireRole('admin');

function reportBind(mysqli_stmt $stmt, string $types, array &$values): void
{
    if ($types === '') {
        return;
    }

    $args = [$types];
    foreach ($values as &$value) {
        $args[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $args);
}

function reportCsvValue($value): string
{
    $value = (string) $value;
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }

    return $value;
}

$termResult = $conn->query('SELECT id, school_year, semester FROM academic_terms ORDER BY id DESC');
$terms = $termResult->fetch_all(MYSQLI_ASSOC);
$termResult->free();
$validAcademicYears = array_values(array_unique(array_column($terms, 'school_year')));
$validSemesters = array_values(array_unique(array_column($terms, 'semester')));

$filters = [
    'term' => filter_input(INPUT_GET, 'term', FILTER_VALIDATE_INT),
    'period' => filter_input(INPUT_GET, 'period', FILTER_VALIDATE_INT),
    'department' => filter_input(INPUT_GET, 'department', FILTER_VALIDATE_INT),
    'faculty' => filter_input(INPUT_GET, 'faculty', FILTER_VALIDATE_INT),
    'subject' => filter_input(INPUT_GET, 'subject', FILTER_VALIDATE_INT),
    'section' => filter_input(INPUT_GET, 'section', FILTER_VALIDATE_INT),
];
foreach ($filters as $key => $value) {
    $filters[$key] = $value !== false && $value !== null && $value > 0 ? $value : 0;
}
$requestedYear = isset($_GET['year']) && is_string($_GET['year']) ? trim($_GET['year']) : '';
$requestedSemester = isset($_GET['semester']) && is_string($_GET['semester']) ? trim($_GET['semester']) : '';
$filters['year'] = in_array($requestedYear, $validAcademicYears, true) ? $requestedYear : '';
$filters['semester'] = in_array($requestedSemester, $validSemesters, true) ? $requestedSemester : '';
$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$requestedView = isset($_GET['view']) && is_string($_GET['view']) ? $_GET['view'] : '';
$view = in_array($requestedView, ['results', 'completion'], true) ? $requestedView : 'reports';
$title = [
    'results' => 'Evaluation results',
    'completion' => 'Evaluation completion monitoring',
    'reports' => 'Evaluation reports',
][$view];

$where = [];
$params = [];
$types = '';
if ($filters['term'] > 0) {
    $where[] = 'at.id = ?';
    $params[] = $filters['term'];
    $types .= 'i';
}
if ($filters['year'] !== '') {
    $where[] = 'at.school_year = ?';
    $params[] = $filters['year'];
    $types .= 's';
}
if ($filters['semester'] !== '') {
    $where[] = 'at.semester = ?';
    $params[] = $filters['semester'];
    $types .= 's';
}
if ($filters['period'] > 0) {
    $where[] = 'ep.id = ?';
    $params[] = $filters['period'];
    $types .= 'i';
}
if ($filters['department'] > 0) {
    $where[] = 'f.department_id = ?';
    $params[] = $filters['department'];
    $types .= 'i';
}
if ($filters['faculty'] > 0) {
    $where[] = 'f.id = ?';
    $params[] = $filters['faculty'];
    $types .= 'i';
}
if ($filters['subject'] > 0) {
    $where[] = 's.id = ?';
    $params[] = $filters['subject'];
    $types .= 'i';
}
if ($filters['section'] > 0) {
    $where[] = 'cs.id = ?';
    $params[] = $filters['section'];
    $types .= 'i';
}
if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR s.subject_code LIKE ? OR s.subject_name LIKE ? OR cs.section_name LIKE ? OR ep.title LIKE ?)';
    for ($index = 0; $index < 5; $index++) {
        $params[] = '%' . $search . '%';
        $types .= 's';
    }
}
$whereSql = $where === [] ? '' : ' AND ' . implode(' AND ', $where);
$fromSql = "FROM evaluations ev
            INNER JOIN class_sections cs ON cs.id = ev.class_section_id
            INNER JOIN subjects s ON s.id = cs.subject_id
            INNER JOIN faculty f ON f.id = cs.faculty_id
            INNER JOIN users u ON u.id = f.user_id
            LEFT JOIN departments d ON d.id = f.department_id
            INNER JOIN academic_terms at ON at.id = cs.academic_term_id
            INNER JOIN evaluation_periods ep
                ON ep.id = ev.evaluation_period_id
               AND ep.academic_term_id = cs.academic_term_id
            LEFT JOIN evaluation_answers a
                ON a.evaluation_id = ev.id
               AND a.rating BETWEEN 1 AND 5";

$reportSql = "SELECT f.id AS faculty_id, u.full_name AS faculty_name,
                     d.department_name, s.subject_code, s.subject_name,
                     cs.id AS section_id, cs.section_name, at.school_year, at.semester,
                     ep.title AS period_title, COUNT(DISTINCT ev.id) AS responses,
                     COUNT(DISTINCT CASE WHEN EXISTS (
                         SELECT 1 FROM enrollments en
                         WHERE en.student_id = ev.student_id
                           AND en.class_section_id = ev.class_section_id
                     ) THEN ev.id END) AS enrolled_responses,
                     AVG(a.rating) AS average_rating,
                     (SELECT CASE
                         WHEN ep.status = 'closed'
                           OR ep.end_date < CURDATE()
                           OR (ep.status = 'open' AND CURDATE() BETWEEN ep.start_date AND ep.end_date)
                         THEN COUNT(*)
                         ELSE 0
                     END
                      FROM enrollments en WHERE en.class_section_id = cs.id) AS eligible_students
              $fromSql
              WHERE 1=1 $whereSql
              GROUP BY f.id, u.full_name, d.department_name, s.id, s.subject_code,
                       s.subject_name, cs.id, cs.section_name, at.id, at.school_year,
                       at.semester, ep.id, ep.title
              ORDER BY at.id DESC, u.full_name, s.subject_code, cs.section_name";
$reportStmt = $conn->prepare($reportSql);
$reportParams = $params;
reportBind($reportStmt, $types, $reportParams);
$reportStmt->execute();
$reports = $reportStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$reportStmt->close();

$categorySql = "SELECT q.category, AVG(a.rating) AS average_rating,
                       COUNT(DISTINCT ev.id) AS responses
                $fromSql
                INNER JOIN evaluation_questions q ON q.id = a.question_id
                WHERE 1=1 $whereSql
                GROUP BY q.category
                ORDER BY q.category";
$categoryStmt = $conn->prepare($categorySql);
$categoryParams = $params;
reportBind($categoryStmt, $types, $categoryParams);
$categoryStmt->execute();
$categories = $categoryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$categoryStmt->close();

$questionSql = "SELECT q.id, q.category, q.question_text,
                       COUNT(a.id) AS answer_count, AVG(a.rating) AS average_rating
                $fromSql
                INNER JOIN evaluation_questions q ON q.id = a.question_id
                WHERE 1=1 $whereSql
                GROUP BY q.id, q.category, q.question_text, q.sort_order
                ORDER BY q.category, q.sort_order, q.id";
$questionStmt = $conn->prepare($questionSql);
$questionParams = $params;
reportBind($questionStmt, $types, $questionParams);
$questionStmt->execute();
$questions = $questionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$questionStmt->close();

$distributionStmt = $conn->prepare(
    "SELECT a.rating, COUNT(*) AS rating_count
     $fromSql
     WHERE a.rating BETWEEN 1 AND 5 $whereSql
     GROUP BY a.rating
     ORDER BY a.rating"
);
$distributionParams = $params;
reportBind($distributionStmt, $types, $distributionParams);
$distributionStmt->execute();
$ratingCounts = array_fill(1, 5, 0);
foreach ($distributionStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $ratingRow) {
    $ratingCounts[(int) $ratingRow['rating']] = (int) $ratingRow['rating_count'];
}
$distributionStmt->close();
$ratingTotal = array_sum($ratingCounts);

$summaryStmt = $conn->prepare(
    "SELECT COUNT(DISTINCT ev.id) AS responses,
            COUNT(DISTINCT CASE WHEN EXISTS (
                SELECT 1 FROM enrollments en
                WHERE en.student_id = ev.student_id
                  AND en.class_section_id = ev.class_section_id
            ) THEN ev.id END) AS enrolled_responses,
            AVG(a.rating) AS average_rating
     $fromSql
     WHERE 1=1 $whereSql"
);
$summaryParams = $params;
reportBind($summaryStmt, $types, $summaryParams);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();
$responseCount = (int) ($summary['responses'] ?? 0);
$enrolledResponseCount = (int) ($summary['enrolled_responses'] ?? 0);
$averageRating = $summary['average_rating'] !== null
    ? number_format((float) $summary['average_rating'], 2)
    : '—';

$periodResult = $conn->query(
    'SELECT ep.id, ep.title, at.school_year, at.semester
     FROM evaluation_periods ep
     INNER JOIN academic_terms at ON at.id = ep.academic_term_id
     ORDER BY at.id DESC, ep.start_date DESC, ep.id DESC'
);
$periods = $periodResult->fetch_all(MYSQLI_ASSOC);
$periodResult->free();
$departmentResult = $conn->query('SELECT id, department_name FROM departments ORDER BY department_name');
$departments = $departmentResult->fetch_all(MYSQLI_ASSOC);
$departmentResult->free();
$facultyResult = $conn->query(
    'SELECT f.id, u.full_name FROM faculty f INNER JOIN users u ON u.id=f.user_id ORDER BY u.full_name'
);
$facultyOptions = $facultyResult->fetch_all(MYSQLI_ASSOC);
$facultyResult->free();
$subjectResult = $conn->query('SELECT id, subject_code, subject_name FROM subjects ORDER BY subject_code');
$subjects = $subjectResult->fetch_all(MYSQLI_ASSOC);
$subjectResult->free();
$sectionResult = $conn->query(
    'SELECT cs.id, cs.section_name, s.subject_code, s.subject_name
     FROM class_sections cs
     INNER JOIN subjects s ON s.id = cs.subject_id
     ORDER BY s.subject_code, cs.section_name'
);
$sections = $sectionResult->fetch_all(MYSQLI_ASSOC);
$sectionResult->free();

$eligibleSql = "SELECT COUNT(en.id)
                FROM class_sections cs
                INNER JOIN subjects s ON s.id = cs.subject_id
                INNER JOIN faculty f ON f.id = cs.faculty_id
                INNER JOIN users u ON u.id = f.user_id
                LEFT JOIN departments d ON d.id = f.department_id
                INNER JOIN academic_terms at ON at.id = cs.academic_term_id
                INNER JOIN evaluation_periods ep ON ep.academic_term_id = cs.academic_term_id
                INNER JOIN enrollments en ON en.class_section_id = cs.id
                WHERE (ep.status = 'closed'
                    OR ep.end_date < CURDATE()
                    OR (ep.status = 'open' AND CURDATE() BETWEEN ep.start_date AND ep.end_date))
                  $whereSql";
$eligibleStmt = $conn->prepare($eligibleSql);
$eligibleParams = $params;
reportBind($eligibleStmt, $types, $eligibleParams);
$eligibleStmt->execute();
$eligibleCount = (int) $eligibleStmt->get_result()->fetch_row()[0];
$eligibleStmt->close();
$participationRate = $eligibleCount > 0
    ? round($enrolledResponseCount / $eligibleCount * 100, 1)
    : null;

$completionFromSql = "FROM enrollments en
                     INNER JOIN class_sections cs ON cs.id = en.class_section_id
                     INNER JOIN subjects s ON s.id = cs.subject_id
                     INNER JOIN faculty f ON f.id = cs.faculty_id
                     INNER JOIN users u ON u.id = f.user_id
                     LEFT JOIN departments d ON d.id = f.department_id
                     INNER JOIN academic_terms at ON at.id = cs.academic_term_id
                     INNER JOIN evaluation_periods ep ON ep.academic_term_id = cs.academic_term_id";
$completionScopeSql = "WHERE (
                           ep.status = 'closed'
                           OR ep.end_date < CURDATE()
                           OR (ep.status = 'open' AND CURDATE() BETWEEN ep.start_date AND ep.end_date)
                       ) $whereSql";
$completionStmt = $conn->prepare(
    "SELECT cs.id AS section_id, cs.section_name, s.subject_code, s.subject_name,
            u.full_name AS faculty_name, d.department_name, at.school_year, at.semester,
            GROUP_CONCAT(DISTINCT ep.title ORDER BY ep.start_date SEPARATOR ', ') AS period_titles,
            COUNT(*) AS expected,
            SUM(CASE WHEN EXISTS (
                SELECT 1 FROM evaluations ev
                WHERE ev.student_id = en.student_id
                  AND ev.class_section_id = cs.id
                  AND ev.evaluation_period_id = ep.id
            ) THEN 1 ELSE 0 END) AS completed
     $completionFromSql
     $completionScopeSql
     GROUP BY cs.id, cs.section_name, s.subject_code, s.subject_name,
              f.id, u.full_name, d.department_name, at.id, at.school_year, at.semester
     ORDER BY at.id DESC, s.subject_code, cs.section_name"
);
$completionParams = $params;
reportBind($completionStmt, $types, $completionParams);
$completionStmt->execute();
$completionRows = $completionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$completionStmt->close();

$completionExpected = 0;
$completionCompleted = 0;
foreach ($completionRows as &$completionRow) {
    $completionRow['expected'] = (int) $completionRow['expected'];
    $completionRow['completed'] = (int) $completionRow['completed'];
    $completionRow['pending'] = max(0, $completionRow['expected'] - $completionRow['completed']);
    $completionRow['completion_rate'] = $completionRow['expected'] > 0
        ? round($completionRow['completed'] / $completionRow['expected'] * 100, 2)
        : 0.0;
    $completionExpected += $completionRow['expected'];
    $completionCompleted += $completionRow['completed'];
}
unset($completionRow);
$completionPending = max(0, $completionExpected - $completionCompleted);
$completionRate = $completionExpected > 0
    ? round($completionCompleted / $completionExpected * 100, 2)
    : 0.0;
$completionPopulationStmt = $conn->prepare(
    "SELECT COUNT(DISTINCT en.student_id) AS enrolled_students,
            COUNT(DISTINCT f.id) AS assigned_faculty
     $completionFromSql
     $completionScopeSql"
);
$completionPopulationParams = $params;
reportBind($completionPopulationStmt, $types, $completionPopulationParams);
$completionPopulationStmt->execute();
$completionPopulation = $completionPopulationStmt->get_result()->fetch_assoc();
$completionPopulationStmt->close();
$completionStudentCount = (int) ($completionPopulation['enrolled_students'] ?? 0);
$completionFacultyCount = (int) ($completionPopulation['assigned_faculty'] ?? 0);

$queryString = http_build_query([
    'view' => $view,
    'term' => $filters['term'] ?: null,
    'year' => $filters['year'] ?: null,
    'semester' => $filters['semester'] ?: null,
    'period' => $filters['period'] ?: null,
    'department' => $filters['department'] ?: null,
    'faculty' => $filters['faculty'] ?: null,
    'subject' => $filters['subject'] ?: null,
    'section' => $filters['section'] ?: null,
    'q' => $search !== '' ? $search : null,
]);

if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'], true)) {
    if ($view === 'completion') {
        $exportHeaders = [
            'Subject code', 'Subject', 'Section', 'Faculty member', 'Department',
            'Academic year', 'Semester', 'Evaluation periods', 'Expected',
            'Completed', 'Pending', 'Completion rate (%)',
        ];
        $exportRows = array_map(static function (array $row): array {
            return [
                $row['subject_code'],
                $row['subject_name'],
                $row['section_name'],
                $row['faculty_name'],
                $row['department_name'],
                $row['school_year'],
                $row['semester'],
                $row['period_titles'],
                $row['expected'],
                $row['completed'],
                $row['pending'],
                number_format((float) $row['completion_rate'], 2),
            ];
        }, $completionRows);
        $filename = 'evaluation-completion-report';
    } else {
        $exportHeaders = [
            'Faculty member', 'Department', 'Subject code', 'Subject', 'Section',
            'Academic term', 'Evaluation period', 'Responses', 'Enrolled responses',
            'Enrolled students', 'Participation rate (%)', 'Average rating',
        ];
        $exportRows = array_map(static function (array $row): array {
            return [
                $row['faculty_name'],
                $row['department_name'],
                $row['subject_code'],
                $row['subject_name'],
                $row['section_name'],
                $row['school_year'] . ' ' . $row['semester'],
                $row['period_title'],
                $row['responses'],
                $row['enrolled_responses'],
                $row['eligible_students'],
                (int) $row['eligible_students'] > 0
                    ? number_format((int) $row['enrolled_responses'] / (int) $row['eligible_students'] * 100, 1)
                    : '',
                $row['average_rating'] === null ? '' : number_format((float) $row['average_rating'], 2),
            ];
        }, $reports);
        $filename = 'faculty-evaluation-report';
    }

    if ($_GET['export'] === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<!doctype html><html><head><meta charset="utf-8"></head><body><table><thead><tr>';
        foreach ($exportHeaders as $header) {
            echo '<th>' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($exportRows as $row) {
            echo '<tr>';
            foreach ($row as $value) {
                echo '<td>' . htmlspecialchars(reportCsvValue($value), ENT_QUOTES, 'UTF-8') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to open the CSV output stream.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $exportHeaders);
    foreach ($exportRows as $row) {
        fputcsv($output, array_map('reportCsvValue', $row));
    }
    fclose($output);
    exit;
}

$categoryLabels = array_column($categories, 'category');
$categoryScores = array_map(static function (array $row): float {
    return round((float) $row['average_rating'], 2);
}, $categories);
$reportUrl = appPath('admin/reports.php');

renderPortalPage($title, $admin, static function () use (
    $view,
    $title,
    $terms,
    $periods,
    $departments,
    $facultyOptions,
    $subjects,
    $sections,
    $filters,
    $search,
    $reports,
    $categories,
    $questions,
    $ratingCounts,
    $ratingTotal,
    $categoryLabels,
    $categoryScores,
    $responseCount,
    $enrolledResponseCount,
    $averageRating,
    $eligibleCount,
    $participationRate,
    $queryString,
    $reportUrl,
    $validAcademicYears,
    $validSemesters,
    $completionRows,
    $completionExpected,
    $completionCompleted,
    $completionPending,
    $completionRate,
    $completionStudentCount,
    $completionFacultyCount
): void {
    ?>
    <section class="page-heading-row">
        <div>
            <p class="eyebrow">INSTITUTIONAL ANALYTICS</p>
            <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <p>Evaluation summaries from submitted responses. Student identities are not included in this report.</p>
        </div>
        <div class="report-heading-actions no-print">
            <button class="btn btn-outline-secondary" type="button" data-print-page><i class="bi bi-printer me-2" aria-hidden="true"></i>Print / Save PDF</button>
            <a class="btn btn-outline-primary" href="<?= htmlspecialchars($reportUrl . '?' . $queryString . '&export=excel', ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-file-earmark-spreadsheet me-2" aria-hidden="true"></i>Excel</a>
            <a class="btn btn-primary" href="<?= htmlspecialchars($reportUrl . '?' . $queryString . '&export=csv', ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-download me-2" aria-hidden="true"></i>CSV</a>
        </div>
    </section>

    <section class="report-summary-grid">
        <?php
        if ($view === 'completion') {
            dashboardStat('Enrolled students', number_format($completionStudentCount), 'people', 'blue', 'Matching selected class assignments');
            dashboardStat('Assigned faculty', number_format($completionFacultyCount), 'person-video3', 'violet', 'Matching selected class assignments');
            dashboardStat('Expected evaluations', number_format($completionExpected), 'clipboard-check', 'cyan', 'Enrolled student and section-period opportunities');
            dashboardStat('Completed / pending', number_format($completionCompleted) . ' / ' . number_format($completionPending), 'hourglass-split', 'amber', number_format($completionRate, 2) . '% completion');
        } else {
            dashboardStat('Evaluation responses', number_format($responseCount), 'clipboard-check', 'blue', 'Matching submitted evaluations');
            dashboardStat('Average rating', $averageRating, 'star', 'violet', 'Based on recorded answers');
            dashboardStat('Faculty groups', count(array_unique(array_column($reports, 'faculty_id'))), 'people', 'green', 'Included by selected filters');
            dashboardStat('Participation', $eligibleCount > 0 ? number_format($enrolledResponseCount) . ' / ' . number_format($eligibleCount) : '—', 'people', 'cyan', $participationRate === null ? 'No active or completed enrollment opportunities' : $participationRate . '% of enrolled section-period opportunities');
        }
        ?>
    </section>

    <section class="content-card report-filter-card no-print">
        <form method="get" action="<?= htmlspecialchars($reportUrl, ENT_QUOTES, 'UTF-8') ?>" class="report-filter-form">
            <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?>">
            <div class="report-filter-search">
                <label class="form-label" for="report-search">Search</label>
                <input class="form-control" id="report-search" name="q" type="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Faculty, subject, section, period">
            </div>
            <div>
                <label class="form-label" for="filter-term">Academic term</label>
                <select class="form-select" id="filter-term" name="term">
                    <option value="">All terms</option>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?= (int) $term['id'] ?>"<?= (int) $filters['term'] === (int) $term['id'] ? ' selected' : '' ?>><?= htmlspecialchars($term['school_year'] . ' · ' . $term['semester'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="filter-year">Academic year</label>
                <select class="form-select" id="filter-year" name="year">
                    <option value="">All academic years</option>
                    <?php foreach ($validAcademicYears as $academicYear): ?>
                        <option value="<?= htmlspecialchars($academicYear, ENT_QUOTES, 'UTF-8') ?>"<?= $filters['year'] === $academicYear ? ' selected' : '' ?>><?= htmlspecialchars($academicYear, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="filter-semester">Semester</label>
                <select class="form-select" id="filter-semester" name="semester">
                    <option value="">All semesters</option>
                    <?php foreach ($validSemesters as $semesterOption): ?>
                        <option value="<?= htmlspecialchars($semesterOption, ENT_QUOTES, 'UTF-8') ?>"<?= $filters['semester'] === $semesterOption ? ' selected' : '' ?>><?= htmlspecialchars($semesterOption, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="filter-period">Evaluation period</label>
                <select class="form-select" id="filter-period" name="period">
                    <option value="">All periods</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= (int) $period['id'] ?>"<?= (int) $filters['period'] === (int) $period['id'] ? ' selected' : '' ?>><?= htmlspecialchars($period['title'] . ' · ' . $period['school_year'] . ' · ' . $period['semester'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="filter-department">Department</label>
                <select class="form-select" id="filter-department" name="department">
                    <option value="">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>"<?= (int) $filters['department'] === (int) $department['id'] ? ' selected' : '' ?>><?= htmlspecialchars($department['department_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="filter-faculty">Faculty member</label>
                <select class="form-select" id="filter-faculty" name="faculty">
                    <option value="">All faculty</option>
                    <?php foreach ($facultyOptions as $faculty): ?>
                        <option value="<?= (int) $faculty['id'] ?>"<?= (int) $filters['faculty'] === (int) $faculty['id'] ? ' selected' : '' ?>><?= htmlspecialchars($faculty['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="filter-subject">Subject</label>
                <select class="form-select" id="filter-subject" name="subject">
                    <option value="">All subjects</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= (int) $subject['id'] ?>"<?= (int) $filters['subject'] === (int) $subject['id'] ? ' selected' : '' ?>><?= htmlspecialchars($subject['subject_code'] . ' · ' . $subject['subject_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="filter-section">Class section</label>
                <select class="form-select" id="filter-section" name="section">
                    <option value="">All sections</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= (int) $section['id'] ?>"<?= (int) $filters['section'] === (int) $section['id'] ? ' selected' : '' ?>><?= htmlspecialchars($section['subject_code'] . ' · ' . $section['section_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary report-filter-button" type="submit"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Apply filters</button>
        </form>
    </section>

    <?php if ($view === 'completion'): ?>
        <section class="content-card report-table-card">
            <div class="card-heading">
                <div><p class="eyebrow">SECTION COMPLETION</p><h2>Expected and submitted evaluations</h2></div>
                <span class="report-row-count"><?= count($completionRows) ?> sections</span>
            </div>
            <?php if ($completionRows === []): ?>
                <?php dashboardEmptyState('No evaluation opportunities match the selected filters. Upcoming periods are not counted as expected.'); ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table portal-table align-middle mb-0">
                        <thead><tr><th>Subject / section</th><th>Faculty</th><th>Academic term</th><th>Evaluation periods</th><th>Expected</th><th>Completed</th><th>Pending</th><th>Completion</th></tr></thead>
                        <tbody>
                        <?php foreach ($completionRows as $row): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['subject_code'], ENT_QUOTES, 'UTF-8') ?></strong><span class="table-subtext"><?= htmlspecialchars($row['subject_name'] . ' · ' . $row['section_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars($row['faculty_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['school_year'] . ' · ' . $row['semester'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['period_titles'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format($row['expected']) ?></td>
                                <td><?= number_format($row['completed']) ?></td>
                                <td><?= number_format($row['pending']) ?></td>
                                <td><?= number_format($row['completion_rate'], 2) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
    <section class="dashboard-grid dashboard-grid-charts">
        <article class="content-card">
            <div class="card-heading"><div><p class="eyebrow">QUESTION CATEGORIES</p><h2>Average category ratings</h2></div></div>
            <?php if ($categories === []): ?>
                <?php dashboardEmptyState('No ratings match these report filters.'); ?>
            <?php else: ?>
                <div class="report-category-chart"><canvas id="report-category-chart" aria-label="Average ratings by evaluation category"></canvas></div>
            <?php endif; ?>
        </article>
        <article class="content-card">
            <div class="card-heading"><div><p class="eyebrow">INTERPRETATION GUIDE</p><h2>Performance scale</h2></div></div>
            <div class="rating-guide">
                <div><strong>4.50 - 5.00</strong><span>Excellent</span></div>
                <div><strong>3.50 - 4.49</strong><span>Very Good</span></div>
                <div><strong>2.50 - 3.49</strong><span>Good</span></div>
                <div><strong>1.50 - 2.49</strong><span>Fair</span></div>
                <div><strong>1.00 - 1.49</strong><span>Poor</span></div>
            </div>
        </article>
        <article class="content-card">
            <div class="card-heading"><div><p class="eyebrow">RATING DISTRIBUTION</p><h2>Recorded answers by rating</h2></div></div>
            <?php if ($ratingTotal === 0): ?>
                <?php dashboardEmptyState('No ratings match these report filters.'); ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table portal-table align-middle mb-0">
                        <thead><tr><th>Rating</th><th>Answer count</th><th>Share</th></tr></thead>
                        <tbody>
                        <?php foreach ($ratingCounts as $rating => $count): ?>
                            <tr><td><?= $rating ?> / 5</td><td><?= number_format($count) ?></td><td><?= number_format($count / $ratingTotal * 100, 1) ?>%</td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <section class="content-card mb-4">
        <div class="card-heading"><div><p class="eyebrow">QUESTION ANALYSIS</p><h2>Average rating per question</h2></div></div>
        <?php if ($questions === []): ?>
            <?php dashboardEmptyState('No question ratings match these report filters.'); ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table portal-table align-middle mb-0">
                    <thead><tr><th>Category</th><th>Question</th><th>Answers</th><th>Average rating</th></tr></thead>
                    <tbody>
                    <?php foreach ($questions as $question): ?>
                        <tr>
                            <td><?= htmlspecialchars($question['category'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($question['question_text'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((int) $question['answer_count']) ?></td>
                            <td><?= number_format((float) $question['average_rating'], 2) ?> / 5</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="content-card report-table-card">
        <div class="card-heading">
            <div><p class="eyebrow">AGGREGATED SUBMISSIONS</p><h2>Faculty and class results</h2></div>
            <span class="report-row-count"><?= count($reports) ?> result groups</span>
        </div>
        <?php if ($reports === []): ?>
            <?php dashboardEmptyState('No completed evaluations match the selected filters.'); ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table portal-table align-middle mb-0">
                    <thead><tr><th>Faculty</th><th>Department</th><th>Subject / section</th><th>Academic term</th><th>Period</th><th>Responses</th><th>Participation</th><th>Average</th></tr></thead>
                    <tbody>
                    <?php foreach ($reports as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['faculty_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td><?= htmlspecialchars($row['department_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><strong><?= htmlspecialchars($row['subject_code'], ENT_QUOTES, 'UTF-8') ?></strong><span class="table-subtext"><?= htmlspecialchars($row['subject_name'] . ' · ' . $row['section_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars($row['school_year'] . ' · ' . $row['semester'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['period_title'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) $row['responses'] ?></td>
                            <td><?= number_format((int) $row['enrolled_responses']) ?> / <?= number_format((int) $row['eligible_students']) ?> enrolled<br><span class="table-subtext"><?= (int) $row['eligible_students'] > 0 ? number_format((int) $row['enrolled_responses'] / (int) $row['eligible_students'] * 100, 1) . '% participation' : 'No active or completed opportunity' ?></span></td>
                            <td><span class="report-score"><?= $row['average_rating'] === null ? '—' : number_format((float) $row['average_rating'], 2) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($view !== 'completion' && $categories !== []): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const canvas = document.getElementById('report-category-chart');
            if (!canvas || typeof Chart === 'undefined') return;
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($categoryLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?>,
                    datasets: [{
                        data: <?= json_encode($categoryScores, JSON_THROW_ON_ERROR) ?>,
                        backgroundColor: '#1976d2',
                        borderRadius: 6,
                        maxBarThickness: 30
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { min: 0, max: 5, grid: { color: '#edf1f7' } },
                        y: { grid: { display: false } }
                    }
                }
            });
        });
        </script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
    <?php endif; ?>
    <?php
});
