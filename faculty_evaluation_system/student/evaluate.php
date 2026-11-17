<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal.php';
require_once __DIR__ . '/../config/database.php';

$user = requireRole('student');
$studentLookup = $conn->prepare('SELECT id FROM students WHERE user_id = ? LIMIT 1');
$userId = (int) $user['id'];
$studentLookup->bind_param('i', $userId);
$studentLookup->execute();
$studentRow = $studentLookup->get_result()->fetch_assoc();
$studentLookup->close();

if ($studentRow === null) {
    http_response_code(403);
    exit('Your account is not linked to a student profile.');
}

$studentId = (int) $studentRow['id'];
$readOptionalId = static function (string $key): ?int {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }

    if (!is_string($_GET[$key])) {
        http_response_code(400);
        exit('A selected evaluation item is invalid.');
    }

    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
        http_response_code(400);
        exit('A selected evaluation item is invalid.');
    }

    return $value;
};
$sectionId = $readOptionalId('section_id');
$subjectId = $readOptionalId('subject_id');
$facultyId = $readOptionalId('faculty_id');
$periodId = $readOptionalId('period_id');
$continueToQuestionnaire = isset($_GET['continue']) && $_GET['continue'] === '1';

$enrolledSectionsStmt = $conn->prepare(
    'SELECT cs.id AS section_id, cs.subject_id, cs.faculty_id,
            cs.section_name, s.subject_code, s.subject_name
     FROM enrollments en
     INNER JOIN class_sections cs ON cs.id = en.class_section_id
     INNER JOIN subjects s ON s.id = cs.subject_id
     WHERE en.student_id = ?
     ORDER BY s.subject_code, s.subject_name, cs.section_name'
);
$enrolledSectionsStmt->bind_param('i', $studentId);
$enrolledSectionsStmt->execute();
$enrolledSections = $enrolledSectionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$enrolledSectionsStmt->close();

$selectedSection = null;
$evaluationPeriods = [];
$selectedPeriod = null;
if ($sectionId !== null) {
    $selectedSectionStmt = $conn->prepare(
        'SELECT cs.id AS section_id, cs.subject_id, cs.faculty_id,
                cs.academic_term_id, cs.section_name, s.subject_code,
                s.subject_name, u.full_name AS faculty_name,
                at.school_year, at.semester
         FROM enrollments en
         INNER JOIN class_sections cs ON cs.id = en.class_section_id
         INNER JOIN subjects s ON s.id = cs.subject_id
         INNER JOIN faculty f ON f.id = cs.faculty_id
         INNER JOIN users u ON u.id = f.user_id
         INNER JOIN academic_terms at ON at.id = cs.academic_term_id
         WHERE en.student_id = ? AND cs.id = ?
         LIMIT 1'
    );
    $selectedSectionStmt->bind_param('ii', $studentId, $sectionId);
    $selectedSectionStmt->execute();
    $selectedSection = $selectedSectionStmt->get_result()->fetch_assoc();
    $selectedSectionStmt->close();

    if (
        $selectedSection === null
        || ($subjectId !== null && $subjectId !== (int) $selectedSection['subject_id'])
        || ($facultyId !== null && $facultyId !== (int) $selectedSection['faculty_id'])
    ) {
        http_response_code(404);
        exit('This subject, section, or faculty assignment is not available to your account.');
    }

    $sectionId = (int) $selectedSection['section_id'];
    $subjectId = (int) $selectedSection['subject_id'];
    $facultyId = (int) $selectedSection['faculty_id'];
    $periodsStmt = $conn->prepare(
        "SELECT ep.id AS period_id, ep.title AS period_title, ep.status AS period_status,
                ep.start_date, ep.end_date, CURDATE() AS today,
                ev.id AS evaluation_id,
                CASE
                    WHEN ev.id IS NOT NULL THEN 'Completed'
                    WHEN ep.status = 'open' AND CURDATE() BETWEEN ep.start_date AND ep.end_date THEN 'Pending'
                    WHEN ep.status = 'upcoming' OR ep.start_date > CURDATE() THEN 'Upcoming'
                    ELSE 'Closed'
                END AS evaluation_status
         FROM evaluation_periods ep
         LEFT JOIN evaluations ev
           ON ev.student_id = ?
          AND ev.class_section_id = ?
          AND ev.evaluation_period_id = ep.id
         WHERE ep.academic_term_id = ?
         ORDER BY ep.start_date DESC, ep.id DESC"
    );
    $selectedTermId = (int) $selectedSection['academic_term_id'];
    $periodsStmt->bind_param('iii', $studentId, $sectionId, $selectedTermId);
    $periodsStmt->execute();
    $evaluationPeriods = $periodsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $periodsStmt->close();

    if ($periodId !== null) {
        foreach ($evaluationPeriods as $evaluationPeriod) {
            if ((int) $evaluationPeriod['period_id'] === $periodId) {
                $selectedPeriod = $evaluationPeriod;
                break;
            }
        }
        if ($selectedPeriod === null) {
            http_response_code(404);
            exit('This evaluation period does not belong to your selected class section.');
        }
    }
}

if ($sectionId === null || $periodId === null || !$continueToQuestionnaire) {
    renderPortalPage('Select an evaluation', $user, static function () use (
        $enrolledSections,
        $selectedSection,
        $evaluationPeriods,
        $selectedPeriod
    ): void {
        ?>
        <section class="page-heading-row">
            <div><p class="eyebrow">STUDENT EVALUATION</p><h1>Select a subject and faculty member</h1>
                <p>Only subjects in your own enrolled class sections are available.</p></div>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(appPath('student/dashboard.php#classes'), ENT_QUOTES, 'UTF-8') ?>">Back to classes</a>
        </section>
        <?php if ($selectedSection === null): ?>
            <?php if ($enrolledSections === []): ?>
                <div class="alert alert-info portal-alert" role="status">No enrolled class sections are available for evaluation.</div>
            <?php else: ?>
                <section class="content-card selection-step-card">
                    <p class="eyebrow">STEP 1</p><h2>Select an enrolled subject and section</h2>
                    <form method="get" class="row g-3 align-items-end">
                        <div class="col-12 col-lg-8">
                            <label class="form-label" for="evaluation-section">Subject and class section</label>
                            <select class="form-select" id="evaluation-section" name="section_id" required>
                                <option value="">Choose an enrolled class section</option>
                                <?php foreach ($enrolledSections as $section): ?>
                                    <option value="<?= (int) $section['section_id'] ?>">
                                        <?= htmlspecialchars($section['subject_code'] . ' · ' . $section['subject_name'] . ' — ' . $section['section_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-lg-auto"><button class="btn btn-primary" type="submit">Continue to faculty selection</button></div>
                    </form>
                </section>
            <?php endif; ?>
        <?php else: ?>
            <section class="content-card selection-step-card">
                <p class="eyebrow">STEP 2</p><h2>Confirm the assigned faculty and evaluation period</h2>
                <p><strong><?= htmlspecialchars($selectedSection['subject_code'] . ' · ' . $selectedSection['subject_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <span class="text-muted"> · <?= htmlspecialchars($selectedSection['section_name'], ENT_QUOTES, 'UTF-8') ?></span></p>
                <form method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="section_id" value="<?= (int) $selectedSection['section_id'] ?>">
                    <input type="hidden" name="subject_id" value="<?= (int) $selectedSection['subject_id'] ?>">
                    <div class="col-12 col-lg-5">
                        <label class="form-label" for="evaluation-faculty">Faculty member assigned to this section</label>
                        <select class="form-select" id="evaluation-faculty" name="faculty_id" required>
                            <option value="">Choose assigned faculty member</option>
                            <option value="<?= (int) $selectedSection['faculty_id'] ?>" selected><?= htmlspecialchars($selectedSection['faculty_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                    </div>
                    <div class="col-12 col-lg-5">
                        <label class="form-label" for="evaluation-period">Evaluation period</label>
                        <select class="form-select" id="evaluation-period" name="period_id" required>
                            <option value="">Choose a period</option>
                            <?php foreach ($evaluationPeriods as $evaluationPeriod): ?>
                                <option value="<?= (int) $evaluationPeriod['period_id'] ?>"<?= $selectedPeriod !== null && (int) $selectedPeriod['period_id'] === (int) $evaluationPeriod['period_id'] ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($evaluationPeriod['period_title'] . ' · ' . $evaluationPeriod['evaluation_status'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit"<?= $evaluationPeriods === [] ? ' disabled' : '' ?>>Show evaluation information</button></div>
                </form>
                <?php if ($evaluationPeriods === []): ?>
                    <div class="alert alert-info mt-3 mb-0" role="status">No evaluation period is configured for this section's academic term.</div>
                <?php endif; ?>
            </section>
            <?php if ($selectedPeriod !== null): ?>
                <section class="content-card selection-info-card mt-4">
                    <p class="eyebrow">STEP 3 · EVALUATION INFORMATION</p><h2><?= htmlspecialchars($selectedPeriod['period_title'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <dl class="row mb-3">
                        <dt class="col-sm-4">Academic year</dt><dd class="col-sm-8"><?= htmlspecialchars($selectedSection['school_year'], ENT_QUOTES, 'UTF-8') ?></dd>
                        <dt class="col-sm-4">Semester</dt><dd class="col-sm-8"><?= htmlspecialchars($selectedSection['semester'], ENT_QUOTES, 'UTF-8') ?></dd>
                        <dt class="col-sm-4">Subject</dt><dd class="col-sm-8"><?= htmlspecialchars($selectedSection['subject_code'] . ' · ' . $selectedSection['subject_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                        <dt class="col-sm-4">Section</dt><dd class="col-sm-8"><?= htmlspecialchars($selectedSection['section_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                        <dt class="col-sm-4">Faculty member</dt><dd class="col-sm-8"><?= htmlspecialchars($selectedSection['faculty_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                        <dt class="col-sm-4">Evaluation period</dt><dd class="col-sm-8"><?= htmlspecialchars($selectedPeriod['period_title'], ENT_QUOTES, 'UTF-8') ?></dd>
                        <dt class="col-sm-4">Evaluation status</dt><dd class="col-sm-8"><span class="status-badge <?= $selectedPeriod['evaluation_status'] === 'Pending' ? 'status-pending' : ($selectedPeriod['evaluation_status'] === 'Completed' ? 'status-complete' : 'status-closed') ?>"><?= htmlspecialchars($selectedPeriod['evaluation_status'], ENT_QUOTES, 'UTF-8') ?></span></dd>
                    </dl>
                    <?php if ($selectedPeriod['evaluation_status'] === 'Pending'): ?>
                        <a class="btn btn-primary" href="<?= htmlspecialchars(appPath(
                            'student/evaluate.php?section_id=' . (int) $selectedSection['section_id']
                            . '&subject_id=' . (int) $selectedSection['subject_id']
                            . '&faculty_id=' . (int) $selectedSection['faculty_id']
                            . '&period_id=' . (int) $selectedPeriod['period_id'] . '&continue=1'
                        ), ENT_QUOTES, 'UTF-8') ?>">Continue to evaluation</a>
                    <?php elseif ($selectedPeriod['evaluation_status'] === 'Completed'): ?>
                        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(appPath('student/history.php'), ENT_QUOTES, 'UTF-8') ?>">View evaluation history</a>
                    <?php else: ?>
                        <p class="alert alert-warning mb-0">This evaluation period is <?= strtolower($selectedPeriod['evaluation_status']) ?> and is not accepting submissions.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    });
    exit;
}

$assignment = array_merge($selectedSection, $selectedPeriod);
$assignment['period_id'] = (int) $selectedPeriod['period_id'];
$assignment['period_status'] = $selectedPeriod['period_status'];
$assignment['start_date'] = $selectedPeriod['start_date'];
$assignment['end_date'] = $selectedPeriod['end_date'];
$assignment['today'] = $selectedPeriod['today'];
$availabilityMessage = '';
if ($selectedPeriod['evaluation_status'] === 'Closed') {
    $availabilityMessage = $selectedPeriod['end_date'] < $selectedPeriod['today']
        ? 'This evaluation period has expired. Your responses were not submitted.'
        : 'This evaluation period is closed. Your responses were not submitted.';
} elseif ($selectedPeriod['evaluation_status'] === 'Upcoming') {
    $availabilityMessage = 'This evaluation period is upcoming and opens on '
        . $selectedPeriod['start_date'] . '. Your responses were not submitted.';
}
if ($availabilityMessage !== '') {
    http_response_code(403);
    renderPortalPage('Evaluation unavailable', $user, static function () use ($availabilityMessage): void {
        ?>
        <section class="page-heading-row"><div><p class="eyebrow">STUDENT EVALUATION</p><h1>Evaluation unavailable</h1></div>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(appPath('student/evaluate.php'), ENT_QUOTES, 'UTF-8') ?>">Choose another evaluation</a></section>
        <div class="alert alert-warning portal-alert" role="alert"><?= htmlspecialchars($availabilityMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php
    });
    exit;
}

$existingStmt = $conn->prepare(
    'SELECT id FROM evaluations
     WHERE student_id = ? AND class_section_id = ? AND evaluation_period_id = ?
     LIMIT 1'
);
$existingStmt->bind_param('iii', $studentId, $sectionId, $periodId);
$existingStmt->execute();
$existingEvaluation = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();

$questionResult = $conn->query(
    'SELECT id, category, question_text, sort_order
     FROM evaluation_questions
     WHERE is_active = 1
     ORDER BY category, sort_order, id'
);
$questions = $questionResult->fetch_all(MYSQLI_ASSOC);
$questionResult->free();

$error = '';
$submittedNow = isset($_GET['submitted']) && $_GET['submitted'] === '1';
$success = $submittedNow;
$alreadySubmitted = false;
$transactionOpen = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success && $existingEvaluation === null) {
    if (!csrfTokenIsValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif ($questions === []) {
        $error = 'There are no active evaluation questions. Contact the administrator.';
    } else {
        $submittedAnswers = $_POST['answers'] ?? null;
        if (!is_array($submittedAnswers)) {
            $error = 'Please answer every evaluation question.';
        } else {
            $validQuestionIds = array_map(static function (array $question): string {
                return (string) $question['id'];
            }, $questions);
            $postedQuestionIds = array_map('strval', array_keys($submittedAnswers));
            sort($validQuestionIds, SORT_STRING);
            sort($postedQuestionIds, SORT_STRING);

            if ($validQuestionIds !== $postedQuestionIds) {
                $error = 'Please answer every evaluation question.';
            } else {
                $answerRows = [];
                foreach ($questions as $question) {
                    $questionId = (string) $question['id'];
                    $rating = $submittedAnswers[$questionId] ?? null;
                    if (!is_string($rating) || !in_array($rating, ['1', '2', '3', '4', '5'], true)) {
                        $error = 'Select a rating from 1 to 5 for every question.';
                        break;
                    }
                    $answerRows[] = [(int) $question['id'], (int) $rating];
                }

                $comment = isset($_POST['overall_comment']) && is_string($_POST['overall_comment'])
                    ? trim($_POST['overall_comment'])
                    : '';
                if ($error === '' && strlen($comment) > 5000) {
                    $error = 'The comment must be 5,000 characters or fewer.';
                }

                if ($error === '') {
                    try {
                        $conn->begin_transaction();
                        $transactionOpen = true;
                        $periodCheck = $conn->prepare(
                            "SELECT ep.id
                             FROM evaluation_periods ep
                             INNER JOIN class_sections cs
                                ON cs.academic_term_id = ep.academic_term_id
                             INNER JOIN enrollments en
                                ON en.class_section_id = cs.id
                             WHERE ep.id = ?
                               AND cs.id = ?
                               AND en.student_id = ?
                               AND ep.status = 'open'
                               AND CURDATE() BETWEEN ep.start_date AND ep.end_date
                             FOR UPDATE"
                        );
                        $periodCheck->bind_param('iii', $periodId, $sectionId, $studentId);
                        $periodCheck->execute();
                        $periodStillOpen = $periodCheck->get_result()->fetch_assoc();
                        $periodCheck->close();

                        if ($periodStillOpen === null) {
                            $conn->rollback();
                            $transactionOpen = false;
                            $error = 'The evaluation period has closed. Your responses were not submitted.';
                        } else {
                            $activeQuestionsStmt = $conn->prepare(
                                'SELECT id FROM evaluation_questions
                                 WHERE is_active = 1
                                 ORDER BY category, sort_order, id
                                 FOR UPDATE'
                            );
                            $activeQuestionsStmt->execute();
                            $currentQuestionIds = array_map(
                                'strval',
                                array_column($activeQuestionsStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id')
                            );
                            $activeQuestionsStmt->close();
                            sort($currentQuestionIds, SORT_STRING);
                            if ($currentQuestionIds !== $validQuestionIds) {
                                $conn->rollback();
                                $transactionOpen = false;
                                $error = 'The questionnaire changed while you were completing it. Reload the page and answer the current questions.';
                            } else {
                                $duplicateCheck = $conn->prepare(
                                    'SELECT id FROM evaluations
                                     WHERE student_id = ? AND class_section_id = ? AND evaluation_period_id = ?
                                     LIMIT 1'
                                );
                                $duplicateCheck->bind_param('iii', $studentId, $sectionId, $periodId);
                                $duplicateCheck->execute();
                                $duplicate = $duplicateCheck->get_result()->fetch_assoc();
                                $duplicateCheck->close();

                                if ($duplicate !== null) {
                                    $conn->rollback();
                                    $transactionOpen = false;
                                    $existingEvaluation = $duplicate;
                                } else {
                                    $insertEvaluation = $conn->prepare(
                                        'INSERT INTO evaluations
                                            (student_id, class_section_id, evaluation_period_id, overall_comment)
                                         VALUES (?, ?, ?, ?)'
                                    );
                                    $insertEvaluation->bind_param('iiis', $studentId, $sectionId, $periodId, $comment);
                                    $insertEvaluation->execute();
                                    $evaluationId = (int) $conn->insert_id;
                                    $insertEvaluation->close();

                                    $insertAnswer = $conn->prepare(
                                        'INSERT INTO evaluation_answers (evaluation_id, question_id, rating)
                                         VALUES (?, ?, ?)'
                                    );
                                    foreach ($answerRows as [$questionId, $rating]) {
                                        $insertAnswer->bind_param('iii', $evaluationId, $questionId, $rating);
                                        $insertAnswer->execute();
                                    }
                                    $insertAnswer->close();
                                    $conn->commit();
                                    $transactionOpen = false;

                                    header(
                                        'Location: ' . appPath(
                                            'student/evaluate.php?section_id=' . $sectionId
                                            . '&subject_id=' . $subjectId
                                            . '&faculty_id=' . $facultyId
                                            . '&period_id=' . $periodId . '&continue=1&submitted=1'
                                        )
                                    );
                                    exit;
                                }
                            }
                        }
                    } catch (mysqli_sql_exception $exception) {
                        if ($transactionOpen) {
                            $conn->rollback();
                            $transactionOpen = false;
                        }
                        if ($exception->getCode() === 1062) {
                            $existingEvaluation = ['id' => 1];
                        } else {
                            error_log('Evaluation submission failed: ' . $exception->getMessage());
                            $error = 'Your evaluation could not be saved. Please try again later.';
                        }
                    }
                }
            }
        }
    }
}

if ($existingEvaluation !== null) {
    $success = true;
    $alreadySubmitted = !$submittedNow;
}

$token = csrfToken();
renderPortalPage('Faculty evaluation', $user, static function () use (
    $assignment,
    $questions,
    $error,
    $success,
    $alreadySubmitted,
    $token,
    $subjectId,
    $facultyId
): void {
    ?>
    <section class="page-heading-row">
        <div>
            <p class="eyebrow">STUDENT EVALUATION</p>
            <h1><?= htmlspecialchars($assignment['subject_code'] . ' · ' . $assignment['section_name'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= htmlspecialchars($assignment['subject_name'] . ' · ' . $assignment['school_year'] . ' · ' . $assignment['semester'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(appPath('student/dashboard.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to classes</a>
    </section>

    <?php if ($success): ?>
        <section class="content-card evaluation-message-card">
            <span class="evaluation-success-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
            <p class="eyebrow">SUBMISSION RECORDED</p>
            <h2><?= $alreadySubmitted ? 'Evaluation already submitted' : 'Thank you for your feedback' ?></h2>
            <p><?= $alreadySubmitted
                ? 'A response for this class and evaluation period is already on file. You cannot submit it a second time.'
                : 'Your evaluation has been submitted. Submitted answers cannot be changed. Your identity is not included in faculty-facing reports.' ?></p>
            <a class="btn btn-primary" href="<?= htmlspecialchars(appPath('student/dashboard.php'), ENT_QUOTES, 'UTF-8') ?>">Return to dashboard</a>
        </section>
    <?php elseif ($error !== ''): ?>
        <div class="alert alert-danger portal-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!$success && $questions === []): ?>
        <div class="alert alert-warning portal-alert" role="alert">No active questions are available. Please contact the administrator.</div>
    <?php elseif (!$success): ?>
        <section class="evaluation-intro">
            <div><i class="bi bi-shield-lock" aria-hidden="true"></i><span>Your responses are confidential and are used to improve teaching and learning.</span></div>
            <p>Rate each statement from 1 (Poor) to 5 (Excellent). All questions are required.</p>
        </section>
        <section class="evaluation-class-card">
            <span class="evaluation-class-icon"><i class="bi bi-person-workspace" aria-hidden="true"></i></span>
            <div><small>FACULTY MEMBER</small><strong><?= htmlspecialchars($assignment['faculty_name'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            <span class="evaluation-period-pill"><?= htmlspecialchars($assignment['period_title'], ENT_QUOTES, 'UTF-8') ?></span>
        </section>
        <form method="post" id="evaluation-form" class="evaluation-form" data-confirm-form data-confirm-message="Submit your faculty evaluation? You will not be able to change your answers afterward.">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">
            <input type="hidden" name="faculty_id" value="<?= (int) $facultyId ?>">
            <section class="content-card evaluation-progress-card" aria-live="polite">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                    <strong data-evaluation-progress-label>Question 1 of <?= count($questions) ?> · 0 answered</strong>
                    <span class="text-muted"><?= count($questions) ?> required</span>
                </div>
                <div class="progress" role="progressbar" aria-label="Questionnaire completion" aria-valuenow="0" aria-valuemin="0" aria-valuemax="<?= count($questions) ?>">
                    <div class="progress-bar" data-evaluation-progress-bar style="width: 0%"></div>
                </div>
            </section>
            <?php
            $currentCategory = null;
            foreach ($questions as $index => $question):
                if ($currentCategory !== $question['category']):
                    if ($currentCategory !== null): ?></div><?php endif; ?>
                    <?php $currentCategory = $question['category']; ?>
                    <section class="content-card evaluation-category">
                        <div class="evaluation-category-heading">
                            <span class="category-number"><?= htmlspecialchars(str_pad((string) (count(array_unique(array_column(array_slice($questions, 0, $index), 'category'))) + 1), 2, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?></span>
                            <h2><?= htmlspecialchars($currentCategory, ENT_QUOTES, 'UTF-8') ?></h2>
                        </div>
                        <div class="evaluation-question-list">
                <?php endif; ?>
                <fieldset class="evaluation-question" data-question-number="<?= (int) $index + 1 ?>">
                    <legend><span><?= (int) $index + 1 ?>.</span> <?= htmlspecialchars($question['question_text'], ENT_QUOTES, 'UTF-8') ?></legend>
                    <div class="rating-options">
                        <?php foreach ([5 => 'Excellent', 4 => 'Very Good', 3 => 'Good', 2 => 'Fair', 1 => 'Poor'] as $rating => $label): ?>
                            <?php $inputId = 'rating-' . (int) $question['id'] . '-' . $rating; ?>
                            <label class="rating-option" for="<?= $inputId ?>">
                                <input id="<?= $inputId ?>" type="radio" name="answers[<?= (int) $question['id'] ?>]" value="<?= $rating ?>" required>
                                <span class="rating-number"><?= $rating ?></span><span class="rating-label"><?= $label ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>
            <?php if ($currentCategory !== null): ?></div></section><?php endif; ?>

            <section class="content-card evaluation-comment-card">
                <label class="form-label" for="overall-comment">Additional comments <span>(optional)</span></label>
                <textarea class="form-control" id="overall-comment" name="overall_comment" rows="4" maxlength="5000" placeholder="Share constructive suggestions for teaching and learning."></textarea>
            </section>
            <section class="content-card evaluation-review-card" data-evaluation-review hidden aria-live="polite">
                <div class="card-heading"><div><p class="eyebrow">ANSWER REVIEW</p><h2>Review your answers</h2></div></div>
                <div class="table-responsive">
                    <table class="table portal-table align-middle mb-0">
                        <thead><tr><th>Question</th><th>Your rating</th></tr></thead>
                        <tbody data-evaluation-review-rows></tbody>
                    </table>
                </div>
            </section>
            <div class="evaluation-submit-row">
                <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(appPath('student/dashboard.php'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
                <button class="btn btn-outline-primary" type="button" data-review-evaluation>Review answers</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-send me-2" aria-hidden="true"></i>Submit evaluation</button>
            </div>
        </form>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('evaluation-form');
            if (!form) return;
            const questions = Array.from(form.querySelectorAll('.evaluation-question'));
            const progress = form.querySelector('[role="progressbar"]');
            const progressBar = form.querySelector('[data-evaluation-progress-bar]');
            const progressLabel = form.querySelector('[data-evaluation-progress-label]');
            const review = form.querySelector('[data-evaluation-review]');
            const reviewRows = form.querySelector('[data-evaluation-review-rows]');
            const reviewButton = form.querySelector('[data-review-evaluation]');

            function updateProgress() {
                let answered = 0;
                let current = questions.length;
                questions.forEach(function (question, index) {
                    const complete = Boolean(question.querySelector('input[type="radio"]:checked'));
                    question.classList.toggle('is-unanswered', !complete);
                    question.classList.toggle('is-current', !complete && current === questions.length);
                    if (complete) question.removeAttribute('aria-invalid');
                    if (complete) answered += 1;
                    else if (current === questions.length) current = index;
                });
                const percent = questions.length ? Math.round(answered / questions.length * 100) : 0;
                if (progress) progress.setAttribute('aria-valuenow', String(answered));
                if (progressBar) progressBar.style.width = percent + '%';
                if (progressLabel) progressLabel.textContent = questions.length
                    ? 'Question ' + (answered === questions.length ? questions.length : current + 1)
                        + ' of ' + questions.length + ' · ' + answered + ' answered'
                    : 'No questions are available';
            }

            form.addEventListener('change', function (event) {
                if (event.target.matches('input[type="radio"]')) {
                    if (review) review.hidden = true;
                    updateProgress();
                }
            });
            form.addEventListener('invalid', function (event) {
                const question = event.target.closest('.evaluation-question');
                if (question) {
                    question.classList.add('is-unanswered');
                    question.setAttribute('aria-invalid', 'true');
                }
            }, true);
            reviewButton.addEventListener('click', function () {
                if (!form.checkValidity()) {
                    form.reportValidity();
                    const firstUnanswered = form.querySelector('.evaluation-question.is-unanswered');
                    if (firstUnanswered) firstUnanswered.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                reviewRows.replaceChildren();
                questions.forEach(function (question) {
                    const selected = question.querySelector('input[type="radio"]:checked');
                    const row = document.createElement('tr');
                    const prompt = document.createElement('td');
                    const rating = document.createElement('td');
                    prompt.textContent = question.querySelector('legend').innerText.trim();
                    rating.textContent = selected ? selected.closest('.rating-option').querySelector('.rating-label').textContent.trim()
                        + ' (' + selected.value + ' / 5)' : '';
                    row.append(prompt, rating);
                    reviewRows.append(row);
                });
                review.hidden = false;
                review.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            updateProgress();
        });
        </script>
    <?php endif; ?>
    <?php
});
