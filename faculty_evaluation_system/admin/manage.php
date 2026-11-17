<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal.php';
require_once __DIR__ . '/../config/database.php';

$admin = requireRole('admin');

function managementEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function managementBind(mysqli_stmt $stmt, string $types, array &$values): void
{
    if ($types === '') {
        return;
    }

    $arguments = [$types];
    foreach ($values as &$value) {
        $arguments[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $arguments);
}

function managementOptions(mysqli $conn, string $kind): array
{
    $queries = [
        'departments' => 'SELECT id, CONCAT(department_code, " - ", department_name) AS label FROM departments ORDER BY department_code',
        'subjects' => 'SELECT id, CONCAT(subject_code, " - ", subject_name) AS label FROM subjects ORDER BY subject_code',
        'terms' => 'SELECT id, CONCAT(school_year, " - ", semester) AS label FROM academic_terms ORDER BY id DESC',
        'faculty' => "SELECT f.id, CONCAT(u.full_name, ' (', f.employee_number, ')') AS label FROM faculty f INNER JOIN users u ON u.id = f.user_id WHERE u.status = 'active' ORDER BY u.full_name",
        'faculty_all' => "SELECT f.id, CONCAT(u.full_name, ' (', f.employee_number, ')') AS label FROM faculty f INNER JOIN users u ON u.id = f.user_id ORDER BY u.full_name",
        'students' => "SELECT s.id, CONCAT(u.full_name, ' (', s.student_number, ')') AS label FROM students s INNER JOIN users u ON u.id = s.user_id WHERE u.status = 'active' ORDER BY u.full_name",
        'sections' => "SELECT cs.id, CONCAT(s.subject_code, ' - ', cs.section_name, ' / ', u.full_name, ' / ', at.school_year, ' ', at.semester) AS label FROM class_sections cs INNER JOIN subjects s ON s.id = cs.subject_id INNER JOIN faculty f ON f.id = cs.faculty_id INNER JOIN users u ON u.id = f.user_id INNER JOIN academic_terms at ON at.id = cs.academic_term_id ORDER BY at.id DESC, s.subject_code, cs.section_name",
    ];

    if (!isset($queries[$kind])) {
        throw new InvalidArgumentException('Unknown option list.');
    }

    $result = $conn->query($queries[$kind]);
    $options = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    return $options;
}

function managementModules(): array
{
    return [
        'departments' => [
            'title' => 'Departments',
            'description' => 'Maintain academic department codes and names.',
            'kind' => 'table',
            'table' => 'departments',
            'fields' => [
                ['name' => 'department_code', 'label' => 'Department code', 'type' => 'text', 'required' => true, 'maxlength' => 30],
                ['name' => 'department_name', 'label' => 'Department name', 'type' => 'text', 'required' => true, 'maxlength' => 150],
            ],
            'list_sql' => 'SELECT id, department_code, department_name FROM departments',
            'count_sql' => 'SELECT COUNT(*) FROM departments',
            'search' => ['department_code', 'department_name'],
            'columns' => ['Code' => 'department_code', 'Department' => 'department_name'],
        ],
        'subjects' => [
            'title' => 'Subjects',
            'description' => 'Manage subject codes, titles, and unit values.',
            'kind' => 'table',
            'table' => 'subjects',
            'fields' => [
                ['name' => 'subject_code', 'label' => 'Subject code', 'type' => 'text', 'required' => true, 'maxlength' => 30],
                ['name' => 'subject_name', 'label' => 'Subject name', 'type' => 'text', 'required' => true, 'maxlength' => 150],
                ['name' => 'units', 'label' => 'Units', 'type' => 'number', 'required' => false, 'min' => 1, 'max' => 99],
            ],
            'list_sql' => 'SELECT id, subject_code, subject_name, units FROM subjects',
            'count_sql' => 'SELECT COUNT(*) FROM subjects',
            'search' => ['subject_code', 'subject_name'],
            'columns' => ['Code' => 'subject_code', 'Subject' => 'subject_name', 'Units' => 'units'],
        ],
        'terms' => [
            'title' => 'Academic Terms',
            'description' => 'Manage school years, semesters, and the active academic term. Evaluation dates are managed on evaluation periods.',
            'kind' => 'table',
            'table' => 'academic_terms',
            'fields' => [
                ['name' => 'school_year', 'label' => 'Academic year', 'type' => 'text', 'required' => true, 'maxlength' => 20, 'placeholder' => '2026-2027'],
                ['name' => 'semester', 'label' => 'Semester', 'type' => 'text', 'required' => true, 'maxlength' => 30],
                ['name' => 'is_active', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['0' => 'Inactive', '1' => 'Active']],
            ],
            'list_sql' => 'SELECT id, school_year, semester, is_active FROM academic_terms',
            'count_sql' => 'SELECT COUNT(*) FROM academic_terms',
            'search' => ['school_year', 'semester'],
            'columns' => ['Academic year' => 'school_year', 'Semester' => 'semester', 'Status' => 'is_active'],
        ],
        'sections' => [
            'title' => 'Class Sections',
            'description' => 'Assign a subject, active faculty member, academic term, and section.',
            'kind' => 'table',
            'table' => 'class_sections',
            'fields' => [
                ['name' => 'subject_id', 'label' => 'Subject', 'type' => 'option', 'options_kind' => 'subjects', 'required' => true],
                ['name' => 'faculty_id', 'label' => 'Faculty member', 'type' => 'option', 'options_kind' => 'faculty', 'required' => true],
                ['name' => 'academic_term_id', 'label' => 'Academic term', 'type' => 'option', 'options_kind' => 'terms', 'required' => true],
                ['name' => 'section_name', 'label' => 'Section name', 'type' => 'text', 'required' => true, 'maxlength' => 50],
            ],
            'list_sql' => 'SELECT cs.id, s.subject_code, s.subject_name, u.full_name AS faculty_name, at.school_year, at.semester, cs.section_name FROM class_sections cs INNER JOIN subjects s ON s.id = cs.subject_id INNER JOIN faculty f ON f.id = cs.faculty_id INNER JOIN users u ON u.id = f.user_id INNER JOIN academic_terms at ON at.id = cs.academic_term_id',
            'count_sql' => 'SELECT COUNT(*) FROM class_sections cs INNER JOIN subjects s ON s.id = cs.subject_id INNER JOIN faculty f ON f.id = cs.faculty_id INNER JOIN users u ON u.id = f.user_id INNER JOIN academic_terms at ON at.id = cs.academic_term_id',
            'search' => ['s.subject_code', 's.subject_name', 'u.full_name', 'at.school_year', 'at.semester', 'cs.section_name'],
            'columns' => ['Section' => 'section_name', 'Subject code' => 'subject_code', 'Subject' => 'subject_name', 'Faculty member' => 'faculty_name', 'Academic term' => 'term_label'],
            'format' => ['term_label' => ['school_year', 'semester']],
        ],
        'enrollments' => [
            'title' => 'Student Enrollments',
            'description' => 'Link a student profile to an assigned class section.',
            'kind' => 'table',
            'table' => 'enrollments',
            'fields' => [
                ['name' => 'student_id', 'label' => 'Student', 'type' => 'option', 'options_kind' => 'students', 'required' => true],
                ['name' => 'class_section_id', 'label' => 'Class section', 'type' => 'option', 'options_kind' => 'sections', 'required' => true],
            ],
            'list_sql' => 'SELECT e.id, e.student_id, e.class_section_id, u.full_name AS student_name, st.student_number, s.subject_code, s.subject_name, cs.section_name, fu.full_name AS faculty_name, at.school_year, at.semester FROM enrollments e INNER JOIN students st ON st.id = e.student_id INNER JOIN users u ON u.id = st.user_id INNER JOIN class_sections cs ON cs.id = e.class_section_id INNER JOIN subjects s ON s.id = cs.subject_id INNER JOIN faculty f ON f.id = cs.faculty_id INNER JOIN users fu ON fu.id = f.user_id INNER JOIN academic_terms at ON at.id = cs.academic_term_id',
            'count_sql' => 'SELECT COUNT(*) FROM enrollments e INNER JOIN students st ON st.id = e.student_id INNER JOIN users u ON u.id = st.user_id INNER JOIN class_sections cs ON cs.id = e.class_section_id INNER JOIN subjects s ON s.id = cs.subject_id INNER JOIN faculty f ON f.id = cs.faculty_id INNER JOIN users fu ON fu.id = f.user_id INNER JOIN academic_terms at ON at.id = cs.academic_term_id',
            'search' => ['u.full_name', 'st.student_number', 's.subject_code', 's.subject_name', 'cs.section_name', 'fu.full_name'],
            'columns' => ['Student number' => 'student_number', 'Student name' => 'student_name', 'Section' => 'section_name', 'Subject code' => 'subject_code', 'Subject' => 'subject_name', 'Faculty member' => 'faculty_name', 'Academic term' => 'term_label'],
            'format' => ['term_label' => ['school_year', 'semester']],
        ],
        'periods' => [
            'title' => 'Evaluation Periods',
            'description' => 'Schedule evaluation windows for academic terms.',
            'kind' => 'table',
            'table' => 'evaluation_periods',
            'fields' => [
                ['name' => 'academic_term_id', 'label' => 'Academic term', 'type' => 'option', 'options_kind' => 'terms', 'required' => true],
                ['name' => 'title', 'label' => 'Period title', 'type' => 'text', 'required' => true, 'maxlength' => 100],
                ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true],
                ['name' => 'end_date', 'label' => 'End date', 'type' => 'date', 'required' => true],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['upcoming' => 'Upcoming', 'open' => 'Open', 'closed' => 'Closed']],
            ],
            'list_sql' => 'SELECT ep.id, ep.title, ep.start_date, ep.end_date, ep.status, at.school_year, at.semester FROM evaluation_periods ep INNER JOIN academic_terms at ON at.id = ep.academic_term_id',
            'count_sql' => 'SELECT COUNT(*) FROM evaluation_periods ep INNER JOIN academic_terms at ON at.id = ep.academic_term_id',
            'search' => ['ep.title', 'ep.status', 'at.school_year', 'at.semester', 'ep.start_date', 'ep.end_date'],
            'columns' => ['Period' => 'title', 'Term' => 'term_label', 'Window' => 'window_label', 'Status' => 'status'],
            'format' => ['term_label' => ['school_year', 'semester'], 'window_label' => ['start_date', 'end_date']],
        ],
        'questions' => [
            'title' => 'Evaluation Questions',
            'description' => 'Create and maintain the active questionnaire shown to students.',
            'kind' => 'table',
            'table' => 'evaluation_questions',
            'fields' => [
                ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'options' => [
                    'Teaching Effectiveness' => 'Teaching Effectiveness',
                    'Classroom Management' => 'Classroom Management',
                    'Communication Skills' => 'Communication Skills',
                    'Professionalism' => 'Professionalism',
                    'Student Engagement' => 'Student Engagement',
                ]],
                ['name' => 'question_text', 'label' => 'Question text', 'type' => 'textarea', 'required' => true, 'maxlength' => 2000],
                ['name' => 'sort_order', 'label' => 'Display order', 'type' => 'number', 'required' => true, 'min' => 0, 'max' => 9999],
                ['name' => 'is_active', 'label' => 'Active question', 'type' => 'select', 'required' => true, 'options' => ['1' => 'Yes', '0' => 'No']],
            ],
            'list_sql' => 'SELECT id, category, question_text, sort_order, is_active FROM evaluation_questions',
            'count_sql' => 'SELECT COUNT(*) FROM evaluation_questions',
            'search' => ['category', 'question_text'],
            'columns' => ['Category' => 'category', 'Question' => 'question_text', 'Order' => 'sort_order', 'Status' => 'is_active'],
        ],
        'students' => [
            'title' => 'Students',
            'description' => 'Create student portal accounts and maintain their academic profiles.',
            'kind' => 'profile',
            'role' => 'student',
            'profile_table' => 'students',
            'identifier' => 'student_number',
            'identifier_label' => 'Student number',
            'extra_fields' => [
                ['name' => 'student_number', 'label' => 'Student number', 'type' => 'text', 'required' => true, 'maxlength' => 50],
                ['name' => 'department_id', 'label' => 'Department', 'type' => 'option', 'options_kind' => 'departments', 'required' => false],
                ['name' => 'year_level', 'label' => 'Year level', 'type' => 'text', 'required' => false, 'maxlength' => 30],
            ],
            'list_sql' => 'SELECT s.id, s.user_id, u.full_name, u.email, u.status, s.student_number, d.department_name, s.year_level FROM students s INNER JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id',
            'count_sql' => 'SELECT COUNT(*) FROM students s INNER JOIN users u ON u.id = s.user_id LEFT JOIN departments d ON d.id = s.department_id',
            'search' => ['u.full_name', 'u.email', 's.student_number', 'd.department_name'],
            'columns' => ['Student' => 'full_name', 'Student number' => 'student_number', 'Department' => 'department_name', 'Year level' => 'year_level', 'Account' => 'status'],
        ],
        'faculty' => [
            'title' => 'Faculty Members',
            'description' => 'Create faculty accounts, maintain required department profiles, and manage account access.',
            'kind' => 'profile',
            'role' => 'faculty',
            'profile_table' => 'faculty',
            'identifier' => 'employee_number',
            'identifier_label' => 'Employee number',
            'extra_fields' => [
                ['name' => 'employee_number', 'label' => 'Employee number', 'type' => 'text', 'required' => true, 'maxlength' => 50],
                ['name' => 'department_id', 'label' => 'Department', 'type' => 'option', 'options_kind' => 'departments', 'required' => true],
            ],
            'list_sql' => 'SELECT f.id, f.user_id, u.full_name, u.email, u.status, f.employee_number, d.department_name FROM faculty f INNER JOIN users u ON u.id = f.user_id LEFT JOIN departments d ON d.id = f.department_id',
            'count_sql' => 'SELECT COUNT(*) FROM faculty f INNER JOIN users u ON u.id = f.user_id LEFT JOIN departments d ON d.id = f.department_id',
            'search' => ['u.full_name', 'f.employee_number', 'd.department_name', 'u.email'],
            'filter' => [
                'department' => ['column' => 'f.department_id', 'type' => 'i'],
                'status' => ['column' => 'u.status', 'type' => 's', 'options' => ['active', 'inactive']],
            ],
            'columns' => ['Faculty member' => 'full_name', 'Email' => 'email', 'Employee number' => 'employee_number', 'Department' => 'department_name', 'Account' => 'status'],
        ],
    ];
}

function managementInputValue(array $field, array $source, mysqli $conn): array
{
    $name = $field['name'];
    $raw = isset($source[$name]) && is_string($source[$name]) ? trim($source[$name]) : '';
    $label = $field['label'];
    if ($raw === '') {
        if (!empty($field['required'])) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return ['value' => null, 'type' => 's'];
    }

    if (isset($field['maxlength']) && strlen($raw) > $field['maxlength']) {
        throw new InvalidArgumentException($label . ' is too long.');
    }

    if ($field['type'] === 'number') {
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new InvalidArgumentException($label . ' must be a whole number.');
        }
        if (isset($field['min']) && $value < $field['min']) {
            throw new InvalidArgumentException($label . ' is below the allowed minimum.');
        }
        if (isset($field['max']) && $value > $field['max']) {
            throw new InvalidArgumentException($label . ' exceeds the allowed maximum.');
        }
        return ['value' => $value, 'type' => 'i'];
    }

    if ($field['type'] === 'date') {
        $date = DateTime::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw new InvalidArgumentException($label . ' must be a valid date.');
        }
        return ['value' => $raw, 'type' => 's'];
    }

    if (in_array($field['type'], ['select', 'option'], true)) {
        if ($field['type'] === 'option') {
            $value = filter_var($raw, FILTER_VALIDATE_INT);
            if ($value === false || $value < 1) {
                throw new InvalidArgumentException('Select a valid ' . strtolower($label) . '.');
            }
            $options = managementOptions($conn, $field['options_kind']);
            $validIds = array_map(static function (array $option): int {
                return (int) $option['id'];
            }, $options);
            if (!in_array($value, $validIds, true)) {
                throw new InvalidArgumentException('The selected ' . strtolower($label) . ' is not available.');
            }
            return ['value' => $value, 'type' => 'i'];
        }

        if (!array_key_exists($raw, $field['options'])) {
            throw new InvalidArgumentException('Select a valid ' . strtolower($label) . '.');
        }
        return ['value' => $raw, 'type' => is_numeric($raw) ? 'i' : 's'];
    }

    return ['value' => $raw, 'type' => 's'];
}

$modules = managementModules();
$moduleKey = isset($_GET['module']) && is_string($_GET['module']) ? $_GET['module'] : '';
if (!isset($modules[$moduleKey])) {
    http_response_code(404);
    exit('Management module not found.');
}
$module = $modules[$moduleKey];
$idColumns = [
    'departments' => 'departments.id',
    'subjects' => 'subjects.id',
    'terms' => 'academic_terms.id',
    'sections' => 'cs.id',
    'enrollments' => 'e.id',
    'periods' => 'ep.id',
    'questions' => 'evaluation_questions.id',
    'students' => 's.id',
    'faculty' => 'f.id',
];
$orderColumns = [
    'questions' => 'evaluation_questions.category ASC, evaluation_questions.sort_order ASC, evaluation_questions.id ASC',
];
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $page !== false && $page !== null ? max(1, $page) : 1;
$perPage = 10;
$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editId = $editId !== false && $editId !== null && $editId > 0 ? $editId : 0;
$viewId = $moduleKey === 'departments' ? filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) : 0;
$viewId = $viewId !== false && $viewId !== null && $viewId > 0 ? $viewId : 0;
$subjectViewId = $moduleKey === 'subjects' ? filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) : 0;
$subjectViewId = $subjectViewId !== false && $subjectViewId !== null && $subjectViewId > 0 ? $subjectViewId : 0;
$sectionViewId = $moduleKey === 'sections' ? filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) : 0;
$sectionViewId = $sectionViewId !== false && $sectionViewId !== null && $sectionViewId > 0 ? $sectionViewId : 0;
$enrollmentViewId = $moduleKey === 'enrollments' ? filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) : 0;
$enrollmentViewId = $enrollmentViewId !== false && $enrollmentViewId !== null && $enrollmentViewId > 0 ? $enrollmentViewId : 0;
$termViewId = $moduleKey === 'terms' ? filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) : 0;
$termViewId = $termViewId !== false && $termViewId !== null && $termViewId > 0 ? $termViewId : 0;
$facultyViewId = $moduleKey === 'faculty' ? filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) : 0;
$facultyViewId = $facultyViewId !== false && $facultyViewId !== null && $facultyViewId > 0 ? $facultyViewId : 0;
$studentViewId = $moduleKey === 'students' ? filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) : 0;
$studentViewId = $studentViewId !== false && $studentViewId !== null && $studentViewId > 0 ? $studentViewId : 0;
$termStatusFilter = $moduleKey === 'terms'
    && isset($_GET['status'])
    && is_string($_GET['status'])
    && in_array($_GET['status'], ['active', 'inactive'], true)
        ? $_GET['status']
        : '';
$periodStatusFilter = $moduleKey === 'periods'
    && isset($_GET['status'])
    && is_string($_GET['status'])
    && in_array($_GET['status'], ['upcoming', 'open', 'closed'], true)
        ? $_GET['status']
        : '';
$facultyDepartmentFilter = $moduleKey === 'faculty'
    ? filter_input(INPUT_GET, 'department', FILTER_VALIDATE_INT)
    : 0;
$facultyDepartmentFilter = $facultyDepartmentFilter !== false
    && $facultyDepartmentFilter !== null
    && $facultyDepartmentFilter > 0
        ? $facultyDepartmentFilter
        : 0;
$facultyStatusFilter = $moduleKey === 'faculty'
    && isset($_GET['status'])
    && is_string($_GET['status'])
    && in_array($_GET['status'], ['active', 'inactive'], true)
        ? $_GET['status']
        : '';
$studentDepartmentFilter = $moduleKey === 'students'
    ? filter_input(INPUT_GET, 'department', FILTER_VALIDATE_INT)
    : 0;
$studentDepartmentFilter = $studentDepartmentFilter !== false
    && $studentDepartmentFilter !== null
    && $studentDepartmentFilter > 0
        ? $studentDepartmentFilter
        : 0;
$studentYearLevelFilter = $moduleKey === 'students'
    && isset($_GET['year_level'])
    && is_string($_GET['year_level'])
        ? trim($_GET['year_level'])
        : '';
$studentStatusFilter = $moduleKey === 'students'
    && isset($_GET['status'])
    && is_string($_GET['status'])
    && in_array($_GET['status'], ['active', 'inactive'], true)
        ? $_GET['status']
        : '';
$enrollmentTermFilter = $moduleKey === 'enrollments'
    ? filter_input(INPUT_GET, 'term', FILTER_VALIDATE_INT)
    : 0;
$enrollmentTermFilter = $enrollmentTermFilter !== false
    && $enrollmentTermFilter !== null
    && $enrollmentTermFilter > 0
        ? $enrollmentTermFilter
        : 0;
$enrollmentFacultyFilter = $moduleKey === 'enrollments'
    ? filter_input(INPUT_GET, 'faculty', FILTER_VALIDATE_INT)
    : 0;
$enrollmentFacultyFilter = $enrollmentFacultyFilter !== false
    && $enrollmentFacultyFilter !== null
    && $enrollmentFacultyFilter > 0
        ? $enrollmentFacultyFilter
        : 0;
$enrollmentSubjectFilter = $moduleKey === 'enrollments'
    ? filter_input(INPUT_GET, 'subject', FILTER_VALIDATE_INT)
    : 0;
$enrollmentSubjectFilter = $enrollmentSubjectFilter !== false
    && $enrollmentSubjectFilter !== null
    && $enrollmentSubjectFilter > 0
        ? $enrollmentSubjectFilter
        : 0;
$departmentDetails = null;
$departmentDetailsError = '';
$subjectDetails = null;
$subjectDetailsError = '';
$sectionDetails = null;
$sectionDetailsError = '';
$termDetails = null;
$termDetailsError = '';
$facultyDetails = null;
$facultyDetailsError = '';
$studentDetails = null;
$studentDetailsError = '';
$enrollmentDetails = null;
$enrollmentDetailsError = '';
$periodViewId = $moduleKey === 'periods' ? filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) : 0;
$periodViewId = $periodViewId !== false && $periodViewId !== null && $periodViewId > 0 ? $periodViewId : 0;
$periodDetails = null;
$periodDetailsError = '';
$questionViewId = $moduleKey === 'questions' ? filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) : 0;
$questionViewId = $questionViewId !== false && $questionViewId !== null && $questionViewId > 0 ? $questionViewId : 0;
$questionDetails = null;
$questionDetailsError = '';
$record = null;
$success = '';
$error = '';
$transactionOpen = false;
$allFields = $module['kind'] === 'profile'
    ? array_merge([
        ['name' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'maxlength' => 150],
        ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'maxlength' => 150],
        ['name' => 'password', 'label' => $editId > 0 ? 'New password (leave blank to keep current)' : 'Initial password', 'type' => 'password', 'required' => $editId === 0, 'maxlength' => 200],
    ], $module['extra_fields'])
    : $module['fields'];
if (in_array($moduleKey, ['faculty', 'students'], true)) {
    foreach ($allFields as &$field) {
        if ($field['name'] === 'password') {
            $field['maxlength'] = 72;
            $field['minlength'] = 12;
        }
    }
    unset($field);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfTokenIsValid($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'Your session expired. Refresh the page and submit again.';
    } else {
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        $postedId = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : 0;
        $postedId = $postedId !== false ? (int) $postedId : 0;

        try {
            if ($module['kind'] === 'profile' && $action === 'deactivate') {
                if ($postedId < 1) {
                    throw new InvalidArgumentException('Select a valid account.');
                }
                $statusStmt = $conn->prepare(
                    'UPDATE users u INNER JOIN ' . $module['profile_table'] . ' p ON p.user_id = u.id
                     SET u.status = IF(u.status = "active", "inactive", "active")
                     WHERE p.id = ? AND u.role = ?'
                );
                $role = $module['role'];
                $statusStmt->bind_param('is', $postedId, $role);
                $statusStmt->execute();
                if ($statusStmt->affected_rows !== 1) {
                    throw new InvalidArgumentException('The account could not be updated.');
                }
                $statusStmt->close();
                $success = 'Account status updated. Existing academic and evaluation history was preserved.';
            } elseif ($moduleKey === 'students' && $module['kind'] === 'profile' && $action === 'delete') {
                if ($postedId < 1) {
                    throw new InvalidArgumentException('Select a valid student profile.');
                }

                $conn->begin_transaction();
                $transactionOpen = true;
                $studentLookup = $conn->prepare(
                    'SELECT user_id FROM students WHERE id = ? FOR UPDATE'
                );
                $studentLookup->bind_param('i', $postedId);
                $studentLookup->execute();
                $studentRecord = $studentLookup->get_result()->fetch_assoc();
                $studentLookup->close();
                if ($studentRecord === null) {
                    throw new InvalidArgumentException('The student profile no longer exists.');
                }

                $studentUserId = (int) $studentRecord['user_id'];
                $deleteStudent = $conn->prepare('DELETE FROM students WHERE id = ?');
                $deleteStudent->bind_param('i', $postedId);
                $deleteStudent->execute();
                if ($deleteStudent->affected_rows !== 1) {
                    $deleteStudent->close();
                    throw new InvalidArgumentException('The student profile could not be deleted.');
                }
                $deleteStudent->close();

                $deleteUser = $conn->prepare(
                    'DELETE FROM users WHERE id = ? AND role = ?'
                );
                $role = 'student';
                $deleteUser->bind_param('is', $studentUserId, $role);
                $deleteUser->execute();
                if ($deleteUser->affected_rows !== 1) {
                    $deleteUser->close();
                    throw new InvalidArgumentException('The associated student account could not be deleted.');
                }
                $deleteUser->close();
                $conn->commit();
                $transactionOpen = false;
                $success = 'Student profile and account deleted.';
            } elseif ($moduleKey === 'faculty' && $module['kind'] === 'profile' && $action === 'delete') {
                if ($postedId < 1) {
                    throw new InvalidArgumentException('Select a valid faculty profile.');
                }

                $conn->begin_transaction();
                $transactionOpen = true;
                $facultyLookup = $conn->prepare(
                    'SELECT user_id FROM faculty WHERE id = ? FOR UPDATE'
                );
                $facultyLookup->bind_param('i', $postedId);
                $facultyLookup->execute();
                $facultyRecord = $facultyLookup->get_result()->fetch_assoc();
                $facultyLookup->close();
                if ($facultyRecord === null) {
                    throw new InvalidArgumentException('The faculty profile no longer exists.');
                }

                $facultyUserId = (int) $facultyRecord['user_id'];
                $deleteFaculty = $conn->prepare('DELETE FROM faculty WHERE id = ?');
                $deleteFaculty->bind_param('i', $postedId);
                $deleteFaculty->execute();
                if ($deleteFaculty->affected_rows !== 1) {
                    $deleteFaculty->close();
                    throw new InvalidArgumentException('The faculty profile could not be deleted.');
                }
                $deleteFaculty->close();

                $deleteUser = $conn->prepare(
                    'DELETE FROM users WHERE id = ? AND role = ?'
                );
                $role = 'faculty';
                $deleteUser->bind_param('is', $facultyUserId, $role);
                $deleteUser->execute();
                if ($deleteUser->affected_rows !== 1) {
                    $deleteUser->close();
                    throw new InvalidArgumentException('The associated faculty account could not be deleted.');
                }
                $deleteUser->close();
                $conn->commit();
                $transactionOpen = false;
                $success = 'Faculty profile and account deleted.';
            } elseif ($moduleKey === 'sections' && $module['kind'] === 'table' && $action === 'delete') {
                if ($postedId < 1) {
                    throw new InvalidArgumentException('Select a valid class section.');
                }

                $conn->begin_transaction();
                $transactionOpen = true;
                $dependencyStmt = $conn->prepare(
                    'SELECT
                        (SELECT COUNT(*) FROM enrollments WHERE class_section_id = ?) AS enrollment_count,
                        (SELECT COUNT(*) FROM evaluations WHERE class_section_id = ?) AS evaluation_count'
                );
                $dependencyStmt->bind_param('ii', $postedId, $postedId);
                $dependencyStmt->execute();
                $dependencies = $dependencyStmt->get_result()->fetch_assoc();
                $dependencyStmt->close();
                if ((int) $dependencies['enrollment_count'] > 0) {
                    throw new InvalidArgumentException(
                        'This class section has student enrollments and cannot be deleted. Preserve the section and its academic history.'
                    );
                }
                if ((int) $dependencies['evaluation_count'] > 0) {
                    throw new InvalidArgumentException(
                        'This class section has evaluation records and cannot be deleted. Preserve the section and its academic history.'
                    );
                }

                $deleteStmt = $conn->prepare('DELETE FROM class_sections WHERE id = ?');
                $deleteStmt->bind_param('i', $postedId);
                $deleteStmt->execute();
                if ($deleteStmt->affected_rows !== 1) {
                    $deleteStmt->close();
                    throw new InvalidArgumentException('The class section no longer exists.');
                }
                $deleteStmt->close();
                $conn->commit();
                $transactionOpen = false;
                $success = 'Class section deleted.';
            } elseif ($moduleKey === 'enrollments' && $module['kind'] === 'table' && $action === 'delete') {
                if ($postedId < 1) {
                    throw new InvalidArgumentException('Select a valid enrollment.');
                }

                $conn->begin_transaction();
                $transactionOpen = true;
                $enrollmentLookup = $conn->prepare(
                    'SELECT student_id, class_section_id FROM enrollments WHERE id = ? FOR UPDATE'
                );
                $enrollmentLookup->bind_param('i', $postedId);
                $enrollmentLookup->execute();
                $enrollment = $enrollmentLookup->get_result()->fetch_assoc();
                $enrollmentLookup->close();
                if ($enrollment === null) {
                    throw new InvalidArgumentException('The enrollment no longer exists.');
                }

                $enrollmentStudentId = (int) $enrollment['student_id'];
                $enrollmentSectionId = (int) $enrollment['class_section_id'];
                $evaluationLookup = $conn->prepare(
                    'SELECT id FROM evaluations WHERE student_id = ? AND class_section_id = ? LIMIT 1 FOR UPDATE'
                );
                $evaluationLookup->bind_param('ii', $enrollmentStudentId, $enrollmentSectionId);
                $evaluationLookup->execute();
                $hasEvaluation = $evaluationLookup->get_result()->fetch_assoc() !== null;
                $evaluationLookup->close();
                if ($hasEvaluation) {
                    throw new InvalidArgumentException(
                        'This enrollment has evaluation history and cannot be removed. Preserve the enrollment and its academic history.'
                    );
                }

                $deleteStmt = $conn->prepare('DELETE FROM enrollments WHERE id = ?');
                $deleteStmt->bind_param('i', $postedId);
                $deleteStmt->execute();
                if ($deleteStmt->affected_rows !== 1) {
                    $deleteStmt->close();
                    throw new InvalidArgumentException('The enrollment could not be removed.');
                }
                $deleteStmt->close();
                $conn->commit();
                $transactionOpen = false;
                $success = 'Enrollment removed.';
            } elseif ($action === 'delete' && $module['kind'] === 'table') {
                if ($postedId < 1) {
                    throw new InvalidArgumentException('Select a valid record.');
                }
                $deleteStmt = $conn->prepare('DELETE FROM ' . $module['table'] . ' WHERE id = ?');
                $deleteStmt->bind_param('i', $postedId);
                $deleteStmt->execute();
                if ($deleteStmt->affected_rows !== 1) {
                    throw new InvalidArgumentException('The record no longer exists.');
                }
                $deleteStmt->close();
                $success = 'Record deleted.';
            } elseif ($action === 'save') {
                $values = [];
                $valueTypes = [];
                foreach ($allFields as $field) {
                    if ($module['kind'] === 'profile' && $field['name'] === 'password') {
                        $passwordValue = isset($_POST['password']) && is_string($_POST['password'])
                            ? $_POST['password']
                            : '';
                        if ($passwordValue === '') {
                            if ($editId === 0) {
                                throw new InvalidArgumentException('Initial password is required.');
                            }
                            continue;
                        }
                        $minimumPasswordBytes = in_array($moduleKey, ['faculty', 'students'], true) ? 12 : 8;
                        if (strlen($passwordValue) < $minimumPasswordBytes) {
                            throw new InvalidArgumentException(
                                'Password must be at least ' . $minimumPasswordBytes . ' characters.'
                            );
                        }
                        if (in_array($moduleKey, ['faculty', 'students'], true) && strlen($passwordValue) > 72) {
                            throw new InvalidArgumentException('Initial passwords must not exceed 72 bytes.');
                        }
                        $values['password'] = password_hash($passwordValue, PASSWORD_DEFAULT);
                        $valueTypes['password'] = 's';
                        continue;
                    }

                    $parsed = managementInputValue($field, $_POST, $conn);
                    $values[$field['name']] = $parsed['value'];
                    $valueTypes[$field['name']] = $parsed['type'];
                }

                if (
                    $moduleKey === 'periods'
                    && $values['start_date'] > $values['end_date']
                ) {
                    throw new InvalidArgumentException('The end date must be on or after the start date.');
                }
                if ($moduleKey === 'periods' && $editId > 0) {
                    $conn->begin_transaction();
                    $transactionOpen = true;
                    $periodHistoryStmt = $conn->prepare(
                        'SELECT ep.academic_term_id,
                                (SELECT COUNT(*) FROM evaluations ev WHERE ev.evaluation_period_id = ep.id) AS response_count
                         FROM evaluation_periods ep
                         WHERE ep.id = ?
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $periodHistoryStmt->bind_param('i', $editId);
                    $periodHistoryStmt->execute();
                    $periodHistory = $periodHistoryStmt->get_result()->fetch_assoc();
                    $periodHistoryStmt->close();
                    if ($periodHistory === null) {
                        throw new InvalidArgumentException('The evaluation period no longer exists.');
                    }
                    if (
                        (int) $periodHistory['response_count'] > 0
                        && (int) $periodHistory['academic_term_id'] !== (int) $values['academic_term_id']
                    ) {
                        throw new InvalidArgumentException(
                            'The academic term cannot be changed after evaluations have been submitted for this period.'
                        );
                    }
                }
                if ($moduleKey === 'questions' && $editId > 0) {
                    $conn->begin_transaction();
                    $transactionOpen = true;
                    $questionHistoryStmt = $conn->prepare(
                        'SELECT q.category, q.question_text, q.sort_order,
                                (SELECT COUNT(*) FROM evaluation_answers ea WHERE ea.question_id = q.id) AS answer_count
                         FROM evaluation_questions q
                         WHERE q.id = ?
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $questionHistoryStmt->bind_param('i', $editId);
                    $questionHistoryStmt->execute();
                    $questionHistory = $questionHistoryStmt->get_result()->fetch_assoc();
                    $questionHistoryStmt->close();
                    if ($questionHistory === null) {
                        throw new InvalidArgumentException('The evaluation question no longer exists.');
                    }
                    if (
                        (int) $questionHistory['answer_count'] > 0
                        && (
                            $questionHistory['category'] !== $values['category']
                            || $questionHistory['question_text'] !== $values['question_text']
                            || (int) $questionHistory['sort_order'] !== (int) $values['sort_order']
                        )
                    ) {
                        throw new InvalidArgumentException(
                            'This question has historical answers. Its wording, category, and order cannot be changed; deactivate it or add a new question instead.'
                        );
                    }
                }

                if ($module['kind'] === 'profile') {
                    if (!filter_var($values['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                        throw new InvalidArgumentException('Enter a valid email address.');
                    }

                    $conn->begin_transaction();
                    $transactionOpen = true;
                    $emailSql = 'SELECT id FROM users WHERE email = ?';
                    $emailValues = [$values['email']];
                    $emailTypes = 's';
                    if ($editId > 0) {
                        $emailSql .= ' AND id <> ?';
                        $existingAccount = $conn->prepare(
                            'SELECT user_id FROM ' . $module['profile_table']
                            . ' WHERE id = ? LIMIT 1 FOR UPDATE'
                        );
                        $existingAccount->bind_param('i', $editId);
                        $existingAccount->execute();
                        $existingProfile = $existingAccount->get_result()->fetch_assoc();
                        $existingAccount->close();
                        if ($existingProfile === null) {
                            throw new InvalidArgumentException('The profile no longer exists.');
                        }
                        $emailValues[] = (int) $existingProfile['user_id'];
                        $emailTypes .= 'i';
                    }
                    $emailSql .= ' LIMIT 1';
                    $emailCheck = $conn->prepare($emailSql);
                    managementBind($emailCheck, $emailTypes, $emailValues);
                    $emailCheck->execute();
                    $duplicateEmail = $emailCheck->get_result()->fetch_assoc();
                    $emailCheck->close();
                    if ($duplicateEmail !== null) {
                        throw new InvalidArgumentException('An account with this email address already exists.');
                    }

                    if ($moduleKey === 'faculty') {
                        $identifierSql = 'SELECT id FROM faculty WHERE employee_number = ?';
                        $identifierValues = [$values['employee_number']];
                        $identifierTypes = 's';
                        if ($editId > 0) {
                            $identifierSql .= ' AND id <> ?';
                            $identifierValues[] = $editId;
                            $identifierTypes .= 'i';
                        }
                        $identifierSql .= ' LIMIT 1';
                        $identifierCheck = $conn->prepare($identifierSql);
                        managementBind($identifierCheck, $identifierTypes, $identifierValues);
                        $identifierCheck->execute();
                        $duplicateIdentifier = $identifierCheck->get_result()->fetch_assoc();
                        $identifierCheck->close();
                        if ($duplicateIdentifier !== null) {
                            throw new InvalidArgumentException('A faculty member with this employee number already exists.');
                        }
                    }
                    if ($moduleKey === 'students') {
                        $identifierSql = 'SELECT id FROM students WHERE student_number = ?';
                        $identifierValues = [$values['student_number']];
                        $identifierTypes = 's';
                        if ($editId > 0) {
                            $identifierSql .= ' AND id <> ?';
                            $identifierValues[] = $editId;
                            $identifierTypes .= 'i';
                        }
                        $identifierSql .= ' LIMIT 1';
                        $identifierCheck = $conn->prepare($identifierSql);
                        managementBind($identifierCheck, $identifierTypes, $identifierValues);
                        $identifierCheck->execute();
                        $duplicateIdentifier = $identifierCheck->get_result()->fetch_assoc();
                        $identifierCheck->close();
                        if ($duplicateIdentifier !== null) {
                            throw new InvalidArgumentException('A student with this student number already exists.');
                        }
                    }

                    if ($editId > 0) {
                        $lookup = $conn->prepare(
                            'SELECT user_id FROM ' . $module['profile_table'] . ' WHERE id = ? LIMIT 1'
                        );
                        $lookup->bind_param('i', $editId);
                        $lookup->execute();
                        $existing = $lookup->get_result()->fetch_assoc();
                        $lookup->close();
                        if ($existing === null) {
                            throw new InvalidArgumentException('The profile no longer exists.');
                        }
                        $profileUserId = (int) $existing['user_id'];

                        $userFields = ['full_name', 'email'];
                        $userSqlParts = ['full_name = ?', 'email = ?'];
                        $userValues = [$values['full_name'], $values['email']];
                        $userTypes = 'ss';
                        if (isset($values['password'])) {
                            $userSqlParts[] = 'password_hash = ?';
                            $userValues[] = $values['password'];
                            $userTypes .= 's';
                        }
                        $userValues[] = $profileUserId;
                        $userTypes .= 'i';
                        $updateUser = $conn->prepare(
                            'UPDATE users SET ' . implode(', ', $userSqlParts) . ' WHERE id = ? AND role = ?'
                        );
                        $role = $module['role'];
                        $userValues[] = $role;
                        $userTypes .= 's';
                        managementBind($updateUser, $userTypes, $userValues);
                        $updateUser->execute();
                        $updateUser->close();

                        $profileNames = [];
                        $profileValues = [];
                        $profileTypes = '';
                        foreach ($module['extra_fields'] as $field) {
                            $profileNames[] = $field['name'] . ' = ?';
                            $profileValues[] = $values[$field['name']];
                            $profileTypes .= $valueTypes[$field['name']];
                        }
                        $profileValues[] = $editId;
                        $profileTypes .= 'i';
                        $updateProfile = $conn->prepare(
                            'UPDATE ' . $module['profile_table'] . ' SET ' . implode(', ', $profileNames) . ' WHERE id = ?'
                        );
                        managementBind($updateProfile, $profileTypes, $profileValues);
                        $updateProfile->execute();
                        $updateProfile->close();
                    } else {
                        $insertUser = $conn->prepare(
                            'INSERT INTO users (full_name, email, password_hash, role, status)
                             VALUES (?, ?, ?, ?, "active")'
                        );
                        $role = $module['role'];
                        $userInsertValues = [$values['full_name'], $values['email'], $values['password'], $role];
                        managementBind($insertUser, 'ssss', $userInsertValues);
                        $insertUser->execute();
                        $profileUserId = (int) $conn->insert_id;
                        $insertUser->close();

                        $profileNames = ['user_id'];
                        $profilePlaceholders = ['?'];
                        $profileValues = [$profileUserId];
                        $profileTypes = 'i';
                        foreach ($module['extra_fields'] as $field) {
                            $profileNames[] = $field['name'];
                            $profilePlaceholders[] = '?';
                            $profileValues[] = $values[$field['name']];
                            $profileTypes .= $valueTypes[$field['name']];
                        }
                        $insertProfile = $conn->prepare(
                            'INSERT INTO ' . $module['profile_table'] . ' (' . implode(', ', $profileNames) . ')
                             VALUES (' . implode(', ', $profilePlaceholders) . ')'
                        );
                        managementBind($insertProfile, $profileTypes, $profileValues);
                        $insertProfile->execute();
                        $insertProfile->close();
                    }
                    $conn->commit();
                    $transactionOpen = false;
                    $success = $editId > 0 ? 'Profile updated.' : ucfirst($module['role']) . ' account created.';
                    $editId = 0;
                } else {
                    $names = array_keys($values);
                    if ($moduleKey === 'terms') {
                        $duplicateSql = 'SELECT id FROM academic_terms WHERE school_year = ? AND semester = ?';
                        $duplicateValues = [$values['school_year'], $values['semester']];
                        $duplicateTypes = 'ss';
                        if ($editId > 0) {
                            $duplicateSql .= ' AND id <> ?';
                            $duplicateValues[] = $editId;
                            $duplicateTypes .= 'i';
                        }
                        $duplicateSql .= ' LIMIT 1';
                        $termDuplicateStmt = $conn->prepare($duplicateSql);
                        managementBind($termDuplicateStmt, $duplicateTypes, $duplicateValues);
                        $termDuplicateStmt->execute();
                        $duplicateTerm = $termDuplicateStmt->get_result()->fetch_assoc();
                        $termDuplicateStmt->close();
                        if ($duplicateTerm !== null) {
                            throw new InvalidArgumentException('An academic term with this academic year and semester already exists.');
                        }
                    }
                    if ($moduleKey === 'subjects') {
                        $duplicateSql = 'SELECT id FROM subjects WHERE subject_code = ?';
                        $duplicateValues = [$values['subject_code']];
                        $duplicateTypes = 's';
                        if ($editId > 0) {
                            $duplicateSql .= ' AND id <> ?';
                            $duplicateValues[] = $editId;
                            $duplicateTypes .= 'i';
                        }
                        $duplicateSql .= ' LIMIT 1';
                        $subjectDuplicateStmt = $conn->prepare($duplicateSql);
                        managementBind($subjectDuplicateStmt, $duplicateTypes, $duplicateValues);
                        $subjectDuplicateStmt->execute();
                        $duplicateSubject = $subjectDuplicateStmt->get_result()->fetch_assoc();
                        $subjectDuplicateStmt->close();
                        if ($duplicateSubject !== null) {
                            throw new InvalidArgumentException('A subject with this subject code already exists.');
                        }
                    }

                    if ($moduleKey === 'terms' && (int) ($values['is_active'] ?? 0) === 1) {
                        $conn->begin_transaction();
                        $transactionOpen = true;
                        $conn->query('UPDATE academic_terms SET is_active = 0');
                    }

                    $uniqueFields = [
                        'sections' => ['subject_id', 'faculty_id', 'academic_term_id', 'section_name'],
                        'enrollments' => ['student_id', 'class_section_id'],
                    ][$moduleKey] ?? [];
                    if ($uniqueFields !== []) {
                        $conditions = [];
                        $duplicateValues = [];
                        $duplicateTypes = '';
                        foreach ($uniqueFields as $fieldName) {
                            $conditions[] = $fieldName . ' = ?';
                            $duplicateValues[] = $values[$fieldName];
                            $duplicateTypes .= $valueTypes[$fieldName];
                        }
                        $duplicateSql = 'SELECT id FROM ' . $module['table']
                            . ' WHERE ' . implode(' AND ', $conditions);
                        if ($editId > 0) {
                            $duplicateSql .= ' AND id <> ?';
                            $duplicateValues[] = $editId;
                            $duplicateTypes .= 'i';
                        }
                        $duplicateSql .= ' LIMIT 1';
                        $duplicateStmt = $conn->prepare($duplicateSql);
                        managementBind($duplicateStmt, $duplicateTypes, $duplicateValues);
                        $duplicateStmt->execute();
                        $duplicate = $duplicateStmt->get_result()->fetch_assoc();
                        $duplicateStmt->close();
                        if ($duplicate !== null) {
                            throw new InvalidArgumentException(
                                $moduleKey === 'enrollments'
                                    ? 'This student is already enrolled in the selected class section.'
                                    : 'A section with this subject, faculty member, term, and section name already exists.'
                            );
                        }
                    }

                    if ($editId > 0) {
                        $setSql = implode(', ', array_map(static function (string $name): string {
                            return $name . ' = ?';
                        }, $names));
                        $updateValues = array_values($values);
                        $updateTypes = implode('', array_values($valueTypes));
                        $updateValues[] = $editId;
                        $updateTypes .= 'i';
                        $stmt = $conn->prepare('UPDATE ' . $module['table'] . ' SET ' . $setSql . ' WHERE id = ?');
                        managementBind($stmt, $updateTypes, $updateValues);
                        $stmt->execute();
                        $stmt->close();
                        $success = 'Record updated.';
                    } else {
                        $placeholders = implode(', ', array_fill(0, count($names), '?'));
                        $stmt = $conn->prepare(
                            'INSERT INTO ' . $module['table'] . ' (' . implode(', ', $names) . ')
                             VALUES (' . $placeholders . ')'
                        );
                        $insertValues = array_values($values);
                        $insertTypes = implode('', array_values($valueTypes));
                        managementBind($stmt, $insertTypes, $insertValues);
                        $stmt->execute();
                        $stmt->close();
                        $success = 'Record created.';
                    }
                    if ($transactionOpen) {
                        $conn->commit();
                        $transactionOpen = false;
                    }
                    $editId = 0;
                }
            } else {
                throw new InvalidArgumentException('Unsupported management action.');
            }
        } catch (InvalidArgumentException $exception) {
            if ($transactionOpen) {
                $conn->rollback();
                $transactionOpen = false;
            }
            $error = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            if ($transactionOpen) {
                $conn->rollback();
                $transactionOpen = false;
            }
            if ($exception->getCode() === 1062) {
                if ($moduleKey === 'terms') {
                    $error = 'An academic term with this academic year and semester already exists.';
                } elseif ($moduleKey === 'subjects') {
                    $error = 'A subject with this subject code already exists.';
                } elseif ($moduleKey === 'faculty') {
                    $error = 'A faculty member with this employee number or email address already exists.';
                } elseif ($moduleKey === 'students') {
                    $error = 'A student with this student number or email address already exists.';
                } elseif ($moduleKey === 'sections') {
                    $error = 'A class section with this subject, faculty member, academic term, and section name already exists.';
                } elseif ($moduleKey === 'enrollments') {
                    $error = 'This student is already enrolled in the selected class section.';
                } else {
                    $error = 'A record with the same unique code, email, or identifier already exists.';
                }
            } elseif ($exception->getCode() === 1451 || $exception->getCode() === 1452) {
                if ($moduleKey === 'students') {
                    $error = 'This student has enrollment or evaluation history and cannot be deleted. Deactivate the account to preserve its academic records.';
                } elseif ($moduleKey === 'subjects') {
                    $error = 'This subject is used by one or more class sections and cannot be deleted. Remove or reassign those sections through the appropriate module first.';
                } elseif ($moduleKey === 'sections') {
                    $error = 'This class section is referenced by enrollments or evaluation records and cannot be deleted.';
                } elseif ($moduleKey === 'enrollments') {
                    $error = 'This enrollment has evaluation history and cannot be removed. Preserve the enrollment and its academic history.';
                } else {
                    $error = 'This record is linked to other academic or evaluation data and cannot be changed or deleted in that way.';
                }
            } else {
                error_log('Admin management database error (' . $moduleKey . '): ' . $exception->getMessage());
                $error = 'The record could not be saved. Check the entered values and try again.';
            }
        }
    }
}

if ($editId > 0) {
    if ($module['kind'] === 'profile') {
        $lookup = $conn->prepare(
            'SELECT p.*, u.full_name, u.email
             FROM ' . $module['profile_table'] . ' p
             INNER JOIN users u ON u.id = p.user_id
             WHERE p.id = ? AND u.role = ?
             LIMIT 1'
        );
        $role = $module['role'];
        $lookup->bind_param('is', $editId, $role);
        $lookup->execute();
        $record = $lookup->get_result()->fetch_assoc();
        $lookup->close();
    } else {
        $lookup = $conn->prepare('SELECT * FROM ' . $module['table'] . ' WHERE id = ? LIMIT 1');
        $lookup->bind_param('i', $editId);
        $lookup->execute();
        $record = $lookup->get_result()->fetch_assoc();
        $lookup->close();
    }
    if ($record === null) {
        $editId = 0;
        $error = 'The requested record was not found.';
    }
}

if ($viewId > 0) {
    $departmentDetailsStmt = $conn->prepare(
        'SELECT d.id, d.department_code, d.department_name,
                (SELECT COUNT(*) FROM faculty f WHERE f.department_id = d.id) AS faculty_count,
                (SELECT COUNT(*) FROM students s WHERE s.department_id = d.id) AS student_count
         FROM departments d
         WHERE d.id = ?
         LIMIT 1'
    );
    $departmentDetailsStmt->bind_param('i', $viewId);
    $departmentDetailsStmt->execute();
    $departmentDetails = $departmentDetailsStmt->get_result()->fetch_assoc();
    $departmentDetailsStmt->close();
    if ($departmentDetails === null) {
        $departmentDetailsError = 'The requested department was not found.';
    }
}

if ($subjectViewId > 0) {
    $subjectDetailsStmt = $conn->prepare(
        'SELECT s.id, s.subject_code, s.subject_name, s.units,
                (SELECT COUNT(*) FROM class_sections cs WHERE cs.subject_id = s.id) AS section_count
         FROM subjects s
         WHERE s.id = ?
         LIMIT 1'
    );
    $subjectDetailsStmt->bind_param('i', $subjectViewId);
    $subjectDetailsStmt->execute();
    $subjectDetails = $subjectDetailsStmt->get_result()->fetch_assoc();
    $subjectDetailsStmt->close();
    if ($subjectDetails === null) {
        $subjectDetailsError = 'The requested subject was not found.';
    }
}

if ($sectionViewId > 0) {
    $sectionDetailsStmt = $conn->prepare(
        'SELECT cs.id, cs.section_name, cs.subject_id, cs.faculty_id, cs.academic_term_id,
                s.subject_code, s.subject_name, s.units,
                f.employee_number, u.full_name AS faculty_name, u.email AS faculty_email, u.status AS faculty_status,
                at.school_year, at.semester,
                (SELECT COUNT(*) FROM enrollments e WHERE e.class_section_id = cs.id) AS enrollment_count,
                (SELECT COUNT(*) FROM evaluations ev WHERE ev.class_section_id = cs.id) AS evaluation_count
         FROM class_sections cs
         INNER JOIN subjects s ON s.id = cs.subject_id
         INNER JOIN faculty f ON f.id = cs.faculty_id
         INNER JOIN users u ON u.id = f.user_id AND u.role = ?
         INNER JOIN academic_terms at ON at.id = cs.academic_term_id
         WHERE cs.id = ?
         LIMIT 1'
    );
    $sectionRole = 'faculty';
    $sectionDetailsStmt->bind_param('si', $sectionRole, $sectionViewId);
    $sectionDetailsStmt->execute();
    $sectionDetails = $sectionDetailsStmt->get_result()->fetch_assoc();
    $sectionDetailsStmt->close();
    if ($sectionDetails === null) {
        $sectionDetailsError = 'The requested class section was not found.';
    }
}

if ($termViewId > 0) {
    $termDetailsStmt = $conn->prepare(
        'SELECT at.id, at.school_year, at.semester, at.is_active,
                (SELECT COUNT(*) FROM class_sections cs WHERE cs.academic_term_id = at.id) AS section_count,
                (SELECT COUNT(*) FROM evaluation_periods ep WHERE ep.academic_term_id = at.id) AS period_count,
                (SELECT COUNT(*)
                 FROM evaluations ev
                 INNER JOIN class_sections cs ON cs.id = ev.class_section_id
                 WHERE cs.academic_term_id = at.id) AS evaluation_count
         FROM academic_terms at
         WHERE at.id = ?
         LIMIT 1'
    );
    $termDetailsStmt->bind_param('i', $termViewId);
    $termDetailsStmt->execute();
    $termDetails = $termDetailsStmt->get_result()->fetch_assoc();
    $termDetailsStmt->close();
    if ($termDetails === null) {
        $termDetailsError = 'The requested academic term was not found.';
    }
}

if ($periodViewId > 0) {
    $periodDetailsStmt = $conn->prepare(
        'SELECT ep.id, ep.title, ep.start_date, ep.end_date, ep.status,
                at.school_year, at.semester,
                (SELECT COUNT(*) FROM class_sections cs WHERE cs.academic_term_id = ep.academic_term_id) AS section_count,
                (SELECT COUNT(*) FROM evaluations ev WHERE ev.evaluation_period_id = ep.id) AS response_count,
                (SELECT COUNT(*)
                 FROM evaluation_answers ea
                 INNER JOIN evaluations ev ON ev.id = ea.evaluation_id
                 WHERE ev.evaluation_period_id = ep.id) AS answer_count
         FROM evaluation_periods ep
         INNER JOIN academic_terms at ON at.id = ep.academic_term_id
         WHERE ep.id = ?
         LIMIT 1'
    );
    $periodDetailsStmt->bind_param('i', $periodViewId);
    $periodDetailsStmt->execute();
    $periodDetails = $periodDetailsStmt->get_result()->fetch_assoc();
    $periodDetailsStmt->close();
    if ($periodDetails === null) {
        $periodDetailsError = 'The requested evaluation period was not found.';
    }
}

if ($questionViewId > 0) {
    $questionDetailsStmt = $conn->prepare(
        'SELECT q.id, q.category, q.question_text, q.sort_order, q.is_active,
                (SELECT COUNT(*) FROM evaluation_answers ea WHERE ea.question_id = q.id) AS answer_count
         FROM evaluation_questions q
         WHERE q.id = ?
         LIMIT 1'
    );
    $questionDetailsStmt->bind_param('i', $questionViewId);
    $questionDetailsStmt->execute();
    $questionDetails = $questionDetailsStmt->get_result()->fetch_assoc();
    $questionDetailsStmt->close();
    if ($questionDetails === null) {
        $questionDetailsError = 'The requested evaluation question was not found.';
    }
}

$facultyDepartments = $moduleKey === 'faculty'
    ? managementOptions($conn, 'departments')
    : [];
$studentDepartments = $moduleKey === 'students'
    ? managementOptions($conn, 'departments')
    : [];
$studentYearLevels = [];
if ($moduleKey === 'students') {
    $yearLevelResult = $conn->query(
        "SELECT DISTINCT year_level FROM students
         WHERE year_level IS NOT NULL AND year_level <> ''
         ORDER BY year_level"
    );
    $studentYearLevels = array_column($yearLevelResult->fetch_all(MYSQLI_ASSOC), 'year_level');
    $yearLevelResult->free();
}
$enrollmentTerms = $moduleKey === 'enrollments'
    ? managementOptions($conn, 'terms')
    : [];
$enrollmentFaculty = $moduleKey === 'enrollments'
    ? managementOptions($conn, 'faculty_all')
    : [];
$enrollmentSubjects = $moduleKey === 'enrollments'
    ? managementOptions($conn, 'subjects')
    : [];
if ($facultyViewId > 0) {
    $facultyDetailsStmt = $conn->prepare(
        'SELECT f.id, f.employee_number, f.department_id, u.full_name, u.email, u.status,
                d.department_name, d.department_code,
                (SELECT COUNT(*) FROM class_sections cs WHERE cs.faculty_id = f.id) AS section_count,
                (SELECT COUNT(*)
                 FROM evaluations ev
                 INNER JOIN class_sections cs ON cs.id = ev.class_section_id
                 WHERE cs.faculty_id = f.id) AS evaluation_count
         FROM faculty f
         INNER JOIN users u ON u.id = f.user_id AND u.role = ?
         LEFT JOIN departments d ON d.id = f.department_id
         WHERE f.id = ?
         LIMIT 1'
    );
    $facultyRole = 'faculty';
    $facultyDetailsStmt->bind_param('si', $facultyRole, $facultyViewId);
    $facultyDetailsStmt->execute();
    $facultyDetails = $facultyDetailsStmt->get_result()->fetch_assoc();
    $facultyDetailsStmt->close();
    if ($facultyDetails === null) {
        $facultyDetailsError = 'The requested faculty profile was not found.';
    }
}
if ($studentViewId > 0) {
    $studentDetailsStmt = $conn->prepare(
        'SELECT s.id, s.student_number, s.department_id, s.year_level,
                u.full_name, u.email, u.status,
                d.department_name, d.department_code,
                (SELECT COUNT(*) FROM enrollments e WHERE e.student_id = s.id) AS enrollment_count,
                (SELECT COUNT(*) FROM evaluations ev WHERE ev.student_id = s.id) AS evaluation_count
         FROM students s
         INNER JOIN users u ON u.id = s.user_id AND u.role = ?
         LEFT JOIN departments d ON d.id = s.department_id
         WHERE s.id = ?
         LIMIT 1'
    );
    $studentRole = 'student';
    $studentDetailsStmt->bind_param('si', $studentRole, $studentViewId);
    $studentDetailsStmt->execute();
    $studentDetails = $studentDetailsStmt->get_result()->fetch_assoc();
    $studentDetailsStmt->close();
    if ($studentDetails === null) {
        $studentDetailsError = 'The requested student profile was not found.';
    }
}
if ($enrollmentViewId > 0) {
    $enrollmentDetailsStmt = $conn->prepare(
        'SELECT e.id, e.enrolled_at, st.student_number, su.full_name AS student_name,
                su.email AS student_email, su.status AS student_status,
                cs.section_name, s.subject_code, s.subject_name, s.units,
                f.employee_number, fu.full_name AS faculty_name, fu.email AS faculty_email,
                at.school_year, at.semester
         FROM enrollments e
         INNER JOIN students st ON st.id = e.student_id
         INNER JOIN users su ON su.id = st.user_id AND su.role = ?
         INNER JOIN class_sections cs ON cs.id = e.class_section_id
         INNER JOIN subjects s ON s.id = cs.subject_id
         INNER JOIN faculty f ON f.id = cs.faculty_id
         INNER JOIN users fu ON fu.id = f.user_id AND fu.role = ?
         INNER JOIN academic_terms at ON at.id = cs.academic_term_id
         WHERE e.id = ?
         LIMIT 1'
    );
    $studentRole = 'student';
    $facultyRole = 'faculty';
    $enrollmentDetailsStmt->bind_param('ssi', $studentRole, $facultyRole, $enrollmentViewId);
    $enrollmentDetailsStmt->execute();
    $enrollmentDetails = $enrollmentDetailsStmt->get_result()->fetch_assoc();
    $enrollmentDetailsStmt->close();
    if ($enrollmentDetails === null) {
        $enrollmentDetailsError = 'The requested enrollment was not found.';
    }
}

$whereSql = '';
$searchValues = [];
$searchTypes = '';
if ($moduleKey === 'terms' && $termStatusFilter !== '') {
    $whereSql = ' WHERE is_active = ?';
    $searchValues[] = $termStatusFilter === 'active' ? 1 : 0;
    $searchTypes .= 'i';
}
if ($moduleKey === 'periods' && $periodStatusFilter !== '') {
    $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . 'ep.status = ?';
    $searchValues[] = $periodStatusFilter;
    $searchTypes .= 's';
}
if ($moduleKey === 'faculty' && $facultyDepartmentFilter > 0) {
    $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . 'f.department_id = ?';
    $searchValues[] = $facultyDepartmentFilter;
    $searchTypes .= 'i';
}
if ($moduleKey === 'faculty' && $facultyStatusFilter !== '') {
    $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . 'u.status = ?';
    $searchValues[] = $facultyStatusFilter;
    $searchTypes .= 's';
}
if ($moduleKey === 'enrollments' && $enrollmentTermFilter > 0) {
    $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . 'cs.academic_term_id = ?';
    $searchValues[] = $enrollmentTermFilter;
    $searchTypes .= 'i';
}
if ($moduleKey === 'enrollments' && $enrollmentFacultyFilter > 0) {
    $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . 'cs.faculty_id = ?';
    $searchValues[] = $enrollmentFacultyFilter;
    $searchTypes .= 'i';
}
if ($moduleKey === 'enrollments' && $enrollmentSubjectFilter > 0) {
    $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . 'cs.subject_id = ?';
    $searchValues[] = $enrollmentSubjectFilter;
    $searchTypes .= 'i';
}
if ($moduleKey === 'students' && $studentDepartmentFilter > 0) {
    $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . 's.department_id = ?';
    $searchValues[] = $studentDepartmentFilter;
    $searchTypes .= 'i';
}
if ($moduleKey === 'students' && $studentYearLevelFilter !== '') {
    $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . 's.year_level = ?';
    $searchValues[] = $studentYearLevelFilter;
    $searchTypes .= 's';
}
if ($moduleKey === 'students' && $studentStatusFilter !== '') {
    $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . 'u.status = ?';
    $searchValues[] = $studentStatusFilter;
    $searchTypes .= 's';
}
if ($search !== '') {
    $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . '(' . implode(' OR ', array_map(static function (string $column): string {
        return $column . ' LIKE ?';
    }, $module['search'])) . ')';
    foreach ($module['search'] as $_) {
        $searchValues[] = '%' . $search . '%';
        $searchTypes .= 's';
    }
}
$countStmt = $conn->prepare($module['count_sql'] . $whereSql);
if ($searchValues !== []) {
    managementBind($countStmt, $searchTypes, $searchValues);
}
$countStmt->execute();
$totalRecords = (int) $countStmt->get_result()->fetch_row()[0];
$countStmt->close();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listValues = $searchValues;
$listTypes = $searchTypes;
$listValues[] = $perPage;
$listValues[] = $offset;
$listTypes .= 'ii';
$listSql = $conn->prepare(
    $module['list_sql'] . $whereSql . ' ORDER BY '
        . ($orderColumns[$moduleKey] ?? ($idColumns[$moduleKey] . ' DESC'))
        . ' LIMIT ? OFFSET ?'
);
managementBind($listSql, $listTypes, $listValues);
$listSql->execute();
$rows = $listSql->get_result()->fetch_all(MYSQLI_ASSOC);
$listSql->close();

$optionLists = [];
foreach ($allFields as $field) {
    if ($field['type'] === 'option') {
        $optionLists[$field['name']] = managementOptions($conn, $field['options_kind']);
    }
}

$listTitle = $module['title'];
$formAction = appPath('admin/manage.php?module=' . rawurlencode($moduleKey)
    . ($editId > 0 ? '&edit=' . $editId : ''));
$formRecord = $record ?? (
    $_SERVER['REQUEST_METHOD'] === 'POST' && is_array($_POST)
        ? $_POST
        : []
);
$showForm = $editId > 0 || isset($_GET['new'])
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '' && ($_POST['action'] ?? '') === 'save');
$queryBase = appPath('admin/manage.php?module=' . rawurlencode($moduleKey));
$paginationBase = $queryBase
    . ($termStatusFilter !== '' ? '&status=' . rawurlencode($termStatusFilter) : '')
    . ($periodStatusFilter !== '' ? '&status=' . rawurlencode($periodStatusFilter) : '')
    . ($facultyDepartmentFilter > 0 ? '&department=' . $facultyDepartmentFilter : '')
    . ($facultyStatusFilter !== '' ? '&status=' . rawurlencode($facultyStatusFilter) : '')
    . ($studentDepartmentFilter > 0 ? '&department=' . $studentDepartmentFilter : '')
    . ($studentYearLevelFilter !== '' ? '&year_level=' . rawurlencode($studentYearLevelFilter) : '')
    . ($studentStatusFilter !== '' ? '&status=' . rawurlencode($studentStatusFilter) : '')
    . ($enrollmentTermFilter > 0 ? '&term=' . $enrollmentTermFilter : '')
    . ($enrollmentFacultyFilter > 0 ? '&faculty=' . $enrollmentFacultyFilter : '')
    . ($enrollmentSubjectFilter > 0 ? '&subject=' . $enrollmentSubjectFilter : '');
$token = csrfToken();

renderPortalPage($listTitle, $admin, static function () use (
    $module,
    $moduleKey,
    $allFields,
    $optionLists,
    $record,
    $formRecord,
    $editId,
    $showForm,
    $rows,
    $totalRecords,
    $page,
    $totalPages,
    $search,
    $success,
    $error,
    $viewId,
    $departmentDetails,
    $departmentDetailsError,
    $subjectViewId,
    $subjectDetails,
    $subjectDetailsError,
    $sectionViewId,
    $sectionDetails,
    $sectionDetailsError,
    $termViewId,
    $termDetails,
    $termDetailsError,
    $termStatusFilter,
    $facultyViewId,
    $facultyDetails,
    $facultyDetailsError,
    $facultyDepartments,
    $facultyDepartmentFilter,
    $facultyStatusFilter,
    $studentViewId,
    $studentDetails,
    $studentDetailsError,
    $studentDepartments,
    $studentYearLevels,
    $studentDepartmentFilter,
    $studentYearLevelFilter,
    $studentStatusFilter,
    $enrollmentViewId,
    $enrollmentDetails,
    $enrollmentDetailsError,
    $enrollmentTerms,
    $enrollmentFaculty,
    $enrollmentSubjects,
    $enrollmentTermFilter,
    $enrollmentFacultyFilter,
    $enrollmentSubjectFilter,
    $periodStatusFilter,
    $periodViewId,
    $periodDetails,
    $periodDetailsError,
    $questionViewId,
    $questionDetails,
    $questionDetailsError,
    $formAction,
    $queryBase,
    $paginationBase,
    $token
): void {
    ?>
    <section class="page-heading-row">
        <div>
            <p class="eyebrow">ADMINISTRATION</p>
            <h1><?= managementEscape($module['title']) ?></h1>
            <p><?= managementEscape($module['description']) ?></p>
        </div>
        <a class="btn btn-primary" href="<?= managementEscape($queryBase . '&new=1') ?>">
            <i class="bi bi-plus-lg me-2" aria-hidden="true"></i>Add <?= managementEscape(rtrim($module['title'], 's')) ?>
        </a>
    </section>

    <?php if ($success !== ''): ?><div class="alert alert-success portal-alert" role="status"><?= managementEscape($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger portal-alert" role="alert"><?= managementEscape($error) ?></div><?php endif; ?>
    <?php if ($departmentDetailsError !== ''): ?><div class="alert alert-warning portal-alert" role="alert"><?= managementEscape($departmentDetailsError) ?></div><?php endif; ?>
    <?php if ($subjectDetailsError !== ''): ?><div class="alert alert-warning portal-alert" role="alert"><?= managementEscape($subjectDetailsError) ?></div><?php endif; ?>
    <?php if ($sectionDetailsError !== ''): ?><div class="alert alert-warning portal-alert" role="alert"><?= managementEscape($sectionDetailsError) ?></div><?php endif; ?>
    <?php if ($termDetailsError !== ''): ?><div class="alert alert-warning portal-alert" role="alert"><?= managementEscape($termDetailsError) ?></div><?php endif; ?>
    <?php if ($facultyDetailsError !== ''): ?><div class="alert alert-warning portal-alert" role="alert"><?= managementEscape($facultyDetailsError) ?></div><?php endif; ?>
    <?php if ($studentDetailsError !== ''): ?><div class="alert alert-warning portal-alert" role="alert"><?= managementEscape($studentDetailsError) ?></div><?php endif; ?>
    <?php if ($enrollmentDetailsError !== ''): ?><div class="alert alert-warning portal-alert" role="alert"><?= managementEscape($enrollmentDetailsError) ?></div><?php endif; ?>
    <?php if ($periodDetailsError !== ''): ?><div class="alert alert-warning portal-alert" role="alert"><?= managementEscape($periodDetailsError) ?></div><?php endif; ?>
    <?php if ($questionDetailsError !== ''): ?><div class="alert alert-warning portal-alert" role="alert"><?= managementEscape($questionDetailsError) ?></div><?php endif; ?>

    <?php if ($viewId > 0 && $departmentDetails !== null): ?>
        <section class="content-card department-details-card">
            <div class="card-heading">
                <div><p class="eyebrow">DEPARTMENT DETAILS</p><h2><?= managementEscape($departmentDetails['department_name']) ?></h2></div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= managementEscape($queryBase) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to list</a>
                    <a class="btn btn-primary btn-sm" href="<?= managementEscape($queryBase . '&edit=' . (int) $departmentDetails['id']) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit department</a>
                </div>
            </div>
            <dl class="department-details-grid">
                <div><dt>Department code</dt><dd><?= managementEscape($departmentDetails['department_code']) ?></dd></div>
                <div><dt>Department name</dt><dd><?= managementEscape($departmentDetails['department_name']) ?></dd></div>
                <div><dt>Faculty profiles</dt><dd><?= number_format((int) $departmentDetails['faculty_count']) ?></dd></div>
                <div><dt>Student profiles</dt><dd><?= number_format((int) $departmentDetails['student_count']) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php if ($subjectViewId > 0 && $subjectDetails !== null): ?>
        <section class="content-card academic-term-details-card">
            <div class="card-heading">
                <div><p class="eyebrow">SUBJECT DETAILS</p><h2><?= managementEscape($subjectDetails['subject_code'] . ' · ' . $subjectDetails['subject_name']) ?></h2></div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= managementEscape($queryBase) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to list</a>
                    <a class="btn btn-primary btn-sm" href="<?= managementEscape($queryBase . '&edit=' . (int) $subjectDetails['id']) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit subject</a>
                </div>
            </div>
            <dl class="department-details-grid">
                <div><dt>Subject code</dt><dd><?= managementEscape($subjectDetails['subject_code']) ?></dd></div>
                <div><dt>Subject name</dt><dd><?= managementEscape($subjectDetails['subject_name']) ?></dd></div>
                <div><dt>Units</dt><dd><?= managementEscape($subjectDetails['units'] ?? 'Not specified') ?></dd></div>
                <div><dt>Class sections</dt><dd><?= number_format((int) $subjectDetails['section_count']) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php if ($sectionViewId > 0 && $sectionDetails !== null): ?>
        <section class="content-card academic-term-details-card">
            <div class="card-heading">
                <div><p class="eyebrow">CLASS SECTION DETAILS</p><h2><?= managementEscape($sectionDetails['section_name']) ?></h2></div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= managementEscape($queryBase) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to list</a>
                    <a class="btn btn-primary btn-sm" href="<?= managementEscape($queryBase . '&edit=' . (int) $sectionDetails['id']) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit section / assign faculty</a>
                </div>
            </div>
            <dl class="department-details-grid">
                <div><dt>Section name</dt><dd><?= managementEscape($sectionDetails['section_name']) ?></dd></div>
                <div><dt>Subject</dt><dd><?= managementEscape($sectionDetails['subject_code'] . ' · ' . $sectionDetails['subject_name']) ?></dd></div>
                <div><dt>Subject units</dt><dd><?= managementEscape($sectionDetails['units'] ?? 'Not specified') ?></dd></div>
                <div><dt>Assigned faculty</dt><dd><?= managementEscape($sectionDetails['faculty_name']) ?> (<?= managementEscape($sectionDetails['employee_number']) ?>)</dd></div>
                <div><dt>Faculty email</dt><dd><?= managementEscape($sectionDetails['faculty_email']) ?></dd></div>
                <div><dt>Faculty account status</dt><dd><?= managementEscape(ucfirst((string) $sectionDetails['faculty_status'])) ?></dd></div>
                <div><dt>Academic term</dt><dd><?= managementEscape($sectionDetails['school_year'] . ' · ' . $sectionDetails['semester']) ?></dd></div>
                <div><dt>Student enrollments</dt><dd><?= number_format((int) $sectionDetails['enrollment_count']) ?></dd></div>
                <div><dt>Evaluation records</dt><dd><?= number_format((int) $sectionDetails['evaluation_count']) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php if ($termViewId > 0 && $termDetails !== null): ?>
        <section class="content-card academic-term-details-card">
            <div class="card-heading">
                <div><p class="eyebrow">ACADEMIC TERM DETAILS</p><h2><?= managementEscape($termDetails['school_year'] . ' · ' . $termDetails['semester']) ?></h2></div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= managementEscape($queryBase) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to list</a>
                    <a class="btn btn-primary btn-sm" href="<?= managementEscape($queryBase . '&edit=' . (int) $termDetails['id']) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit term</a>
                </div>
            </div>
            <dl class="department-details-grid">
                <div><dt>Academic year</dt><dd><?= managementEscape($termDetails['school_year']) ?></dd></div>
                <div><dt>Semester</dt><dd><?= managementEscape($termDetails['semester']) ?></dd></div>
                <div><dt>Status</dt><dd><?= (int) $termDetails['is_active'] === 1 ? 'Active' : 'Inactive' ?></dd></div>
                <div><dt>Class sections</dt><dd><?= number_format((int) $termDetails['section_count']) ?></dd></div>
                <div><dt>Evaluation periods</dt><dd><?= number_format((int) $termDetails['period_count']) ?></dd></div>
                <div><dt>Evaluation responses</dt><dd><?= number_format((int) $termDetails['evaluation_count']) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php if ($facultyViewId > 0 && $facultyDetails !== null): ?>
        <section class="content-card faculty-details-card">
            <div class="card-heading">
                <div><p class="eyebrow">FACULTY PROFILE</p><h2><?= managementEscape($facultyDetails['full_name']) ?></h2></div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= managementEscape($queryBase) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to list</a>
                    <a class="btn btn-primary btn-sm" href="<?= managementEscape($queryBase . '&edit=' . (int) $facultyDetails['id']) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit faculty</a>
                </div>
            </div>
            <dl class="department-details-grid">
                <div><dt>Full name</dt><dd><?= managementEscape($facultyDetails['full_name']) ?></dd></div>
                <div><dt>Email address</dt><dd><?= managementEscape($facultyDetails['email']) ?></dd></div>
                <div><dt>Employee number</dt><dd><?= managementEscape($facultyDetails['employee_number']) ?></dd></div>
                <div><dt>Department</dt><dd><?= managementEscape($facultyDetails['department_name'] ?? 'Not assigned') ?></dd></div>
                <div><dt>Account status</dt><dd><?= managementEscape(ucfirst((string) $facultyDetails['status'])) ?></dd></div>
                <div><dt>Assigned class sections</dt><dd><?= number_format((int) $facultyDetails['section_count']) ?></dd></div>
                <div><dt>Evaluation responses</dt><dd><?= number_format((int) $facultyDetails['evaluation_count']) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php if ($studentViewId > 0 && $studentDetails !== null): ?>
        <section class="content-card faculty-details-card">
            <div class="card-heading">
                <div><p class="eyebrow">STUDENT PROFILE</p><h2><?= managementEscape($studentDetails['full_name']) ?></h2></div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= managementEscape($queryBase) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to list</a>
                    <a class="btn btn-primary btn-sm" href="<?= managementEscape($queryBase . '&edit=' . (int) $studentDetails['id']) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit student</a>
                </div>
            </div>
            <dl class="department-details-grid">
                <div><dt>Full name</dt><dd><?= managementEscape($studentDetails['full_name']) ?></dd></div>
                <div><dt>Email address</dt><dd><?= managementEscape($studentDetails['email']) ?></dd></div>
                <div><dt>Student number</dt><dd><?= managementEscape($studentDetails['student_number']) ?></dd></div>
                <div><dt>Department</dt><dd><?= managementEscape($studentDetails['department_name'] ?? 'Not assigned') ?></dd></div>
                <div><dt>Year level</dt><dd><?= managementEscape($studentDetails['year_level'] ?? 'Not specified') ?></dd></div>
                <div><dt>Account status</dt><dd><?= managementEscape(ucfirst((string) $studentDetails['status'])) ?></dd></div>
                <div><dt>Enrollments</dt><dd><?= number_format((int) $studentDetails['enrollment_count']) ?></dd></div>
                <div><dt>Evaluation responses</dt><dd><?= number_format((int) $studentDetails['evaluation_count']) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php if ($enrollmentViewId > 0 && $enrollmentDetails !== null): ?>
        <section class="content-card faculty-details-card">
            <div class="card-heading">
                <div><p class="eyebrow">ENROLLMENT DETAILS</p><h2><?= managementEscape($enrollmentDetails['student_name']) ?></h2></div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= managementEscape($queryBase) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to list</a>
                </div>
            </div>
            <dl class="department-details-grid">
                <div><dt>Student number</dt><dd><?= managementEscape($enrollmentDetails['student_number']) ?></dd></div>
                <div><dt>Student name</dt><dd><?= managementEscape($enrollmentDetails['student_name']) ?></dd></div>
                <div><dt>Student email</dt><dd><?= managementEscape($enrollmentDetails['student_email']) ?></dd></div>
                <div><dt>Student account status</dt><dd><?= managementEscape(ucfirst((string) $enrollmentDetails['student_status'])) ?></dd></div>
                <div><dt>Class section</dt><dd><?= managementEscape($enrollmentDetails['section_name']) ?></dd></div>
                <div><dt>Subject</dt><dd><?= managementEscape($enrollmentDetails['subject_code'] . ' · ' . $enrollmentDetails['subject_name']) ?></dd></div>
                <div><dt>Subject units</dt><dd><?= managementEscape($enrollmentDetails['units'] ?? 'Not specified') ?></dd></div>
                <div><dt>Faculty member</dt><dd><?= managementEscape($enrollmentDetails['faculty_name']) ?> (<?= managementEscape($enrollmentDetails['employee_number']) ?>)</dd></div>
                <div><dt>Faculty email</dt><dd><?= managementEscape($enrollmentDetails['faculty_email']) ?></dd></div>
                <div><dt>Academic term</dt><dd><?= managementEscape($enrollmentDetails['school_year'] . ' · ' . $enrollmentDetails['semester']) ?></dd></div>
                <div><dt>Enrolled at</dt><dd><?= managementEscape($enrollmentDetails['enrolled_at']) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php if ($periodViewId > 0 && $periodDetails !== null): ?>
        <section class="content-card academic-term-details-card">
            <div class="card-heading">
                <div><p class="eyebrow">EVALUATION PERIOD DETAILS</p><h2><?= managementEscape($periodDetails['title']) ?></h2></div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= managementEscape($queryBase) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to list</a>
                    <a class="btn btn-primary btn-sm" href="<?= managementEscape($queryBase . '&edit=' . (int) $periodDetails['id']) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit period</a>
                </div>
            </div>
            <dl class="department-details-grid">
                <div><dt>Period title</dt><dd><?= managementEscape($periodDetails['title']) ?></dd></div>
                <div><dt>Academic term</dt><dd><?= managementEscape($periodDetails['school_year'] . ' · ' . $periodDetails['semester']) ?></dd></div>
                <div><dt>Start date</dt><dd><?= managementEscape($periodDetails['start_date']) ?></dd></div>
                <div><dt>End date</dt><dd><?= managementEscape($periodDetails['end_date']) ?></dd></div>
                <div><dt>Status</dt><dd><?= managementEscape(ucfirst((string) $periodDetails['status'])) ?></dd></div>
                <div><dt>Class sections in term</dt><dd><?= number_format((int) $periodDetails['section_count']) ?></dd></div>
                <div><dt>Evaluation responses</dt><dd><?= number_format((int) $periodDetails['response_count']) ?></dd></div>
                <div><dt>Submitted answers</dt><dd><?= number_format((int) $periodDetails['answer_count']) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php if ($questionViewId > 0 && $questionDetails !== null): ?>
        <section class="content-card academic-term-details-card">
            <div class="card-heading">
                <div><p class="eyebrow">EVALUATION QUESTION DETAILS</p><h2><?= managementEscape($questionDetails['category']) ?></h2></div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= managementEscape($queryBase) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to list</a>
                    <a class="btn btn-primary btn-sm" href="<?= managementEscape($queryBase . '&edit=' . (int) $questionDetails['id']) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit question</a>
                </div>
            </div>
            <dl class="department-details-grid">
                <div><dt>Category</dt><dd><?= managementEscape($questionDetails['category']) ?></dd></div>
                <div><dt>Question</dt><dd><?= managementEscape($questionDetails['question_text']) ?></dd></div>
                <div><dt>Display order</dt><dd><?= number_format((int) $questionDetails['sort_order']) ?></dd></div>
                <div><dt>Status</dt><dd><?= (int) $questionDetails['is_active'] === 1 ? 'Active' : 'Inactive' ?></dd></div>
                <div><dt>Historical answers</dt><dd><?= number_format((int) $questionDetails['answer_count']) ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <?php if ($showForm): ?>
        <section class="content-card management-form-card">
            <div class="card-heading">
                <div><p class="eyebrow"><?= $editId > 0 ? 'UPDATE RECORD' : 'NEW RECORD' ?></p><h2><?= $editId > 0 ? 'Edit details' : 'Enter details' ?></h2></div>
                <a class="btn btn-outline-secondary btn-sm" href="<?= managementEscape($queryBase) ?>">Cancel</a>
            </div>
            <form method="post" action="<?= managementEscape($formAction) ?>" class="management-form" data-confirm-form>
                <input type="hidden" name="csrf_token" value="<?= managementEscape($token) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) $editId ?>">
                <div class="management-fields">
                    <?php if ($moduleKey === 'sections' && $editId > 0): ?>
                        <div class="management-field field-wide">
                            <p class="form-text mb-0">Reassigning faculty changes the faculty linked to this section; existing enrollments and evaluation records are preserved and are not deleted.</p>
                        </div>
                    <?php endif; ?>
                    <?php if (in_array($moduleKey, ['faculty', 'students'], true) && $editId === 0): ?>
                        <div class="management-field field-wide">
                            <p class="form-text mb-0">Initial passwords must be 12–72 bytes. Provide the temporary password to the account holder through a secure channel.</p>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($allFields as $field): ?>
                        <?php
                        $fieldName = $field['name'];
                        $value = $formRecord[$fieldName] ?? '';
                        if ($fieldName === 'password') {
                            $value = '';
                        }
                        $controlId = 'field-' . $fieldName;
                        ?>
                        <div class="management-field<?= $field['type'] === 'textarea' ? ' field-wide' : '' ?>">
                            <label class="form-label" for="<?= managementEscape($controlId) ?>"><?= managementEscape($field['label']) ?></label>
                            <?php if ($field['type'] === 'select'): ?>
                                <select class="form-select" id="<?= managementEscape($controlId) ?>" name="<?= managementEscape($fieldName) ?>"<?= !empty($field['required']) ? ' required' : '' ?>>
                                    <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                                        <option value="<?= managementEscape($optionValue) ?>"<?= (string) $value === (string) $optionValue ? ' selected' : '' ?>><?= managementEscape($optionLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($field['type'] === 'option'): ?>
                                <select class="form-select" id="<?= managementEscape($controlId) ?>" name="<?= managementEscape($fieldName) ?>"<?= !empty($field['required']) ? ' required' : '' ?>>
                                    <?php if (empty($field['required'])): ?><option value="">Not assigned</option><?php endif; ?>
                                    <?php foreach ($optionLists[$fieldName] ?? [] as $option): ?>
                                        <option value="<?= (int) $option['id'] ?>"<?= (string) $value === (string) $option['id'] ? ' selected' : '' ?>><?= managementEscape($option['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($moduleKey === 'faculty' && $fieldName === 'department_id' && $facultyDepartments === []): ?>
                                    <div class="form-text text-danger">Create a department before adding a faculty member.</div>
                                <?php endif; ?>
                            <?php elseif ($field['type'] === 'textarea'): ?>
                                <textarea class="form-control" id="<?= managementEscape($controlId) ?>" name="<?= managementEscape($fieldName) ?>" rows="3" maxlength="<?= (int) ($field['maxlength'] ?? 2000) ?>"<?= !empty($field['required']) ? ' required' : '' ?>><?= managementEscape($value) ?></textarea>
                            <?php else: ?>
                                <input
                                    class="form-control"
                                    id="<?= managementEscape($controlId) ?>"
                                    name="<?= managementEscape($fieldName) ?>"
                                    type="<?= managementEscape($field['type']) ?>"
                                    value="<?= managementEscape($value) ?>"
                                    <?= isset($field['maxlength']) ? 'maxlength="' . (int) $field['maxlength'] . '"' : '' ?>
                                    <?= isset($field['minlength']) ? 'minlength="' . (int) $field['minlength'] . '"' : '' ?>
                                    <?= isset($field['min']) ? 'min="' . (int) $field['min'] . '"' : '' ?>
                                    <?= isset($field['max']) ? 'max="' . (int) $field['max'] . '"' : '' ?>
                                    <?= !empty($field['placeholder']) ? 'placeholder="' . managementEscape($field['placeholder']) . '"' : '' ?>
                                    <?= !empty($field['required']) ? 'required' : '' ?>
                                    <?= $field['type'] === 'password' ? 'autocomplete="new-password"' : '' ?>
                                >
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions">
                    <a class="btn btn-outline-secondary" href="<?= managementEscape($queryBase) ?>">Cancel</a>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i><?= $editId > 0 ? 'Save changes' : 'Create record' ?></button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="content-card management-list-card">
        <div class="management-list-toolbar">
            <div><h2><?= managementEscape($module['title']) ?> list</h2><span><?= count($rows) ?> shown of <?= (int) $totalRecords ?> record<?= $totalRecords === 1 ? '' : 's' ?></span></div>
            <form class="management-search" method="get" action="<?= managementEscape(appPath('admin/manage.php')) ?>">
                <input type="hidden" name="module" value="<?= managementEscape($moduleKey) ?>">
                <label class="visually-hidden" for="management-search">Search <?= managementEscape(strtolower($module['title'])) ?></label>
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="management-search" class="form-control" name="q" type="search" value="<?= managementEscape($search) ?>" placeholder="Search records">
                <?php if ($moduleKey === 'terms'): ?>
                    <label class="visually-hidden" for="term-status-filter">Filter by status</label>
                    <select class="form-select management-status-filter" id="term-status-filter" name="status">
                        <option value="">All statuses</option>
                        <option value="active"<?= $termStatusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                        <option value="inactive"<?= $termStatusFilter === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                    </select>
                <?php elseif ($moduleKey === 'periods'): ?>
                    <label class="visually-hidden" for="period-status-filter">Filter by evaluation period status</label>
                    <select class="form-select management-status-filter" id="period-status-filter" name="status">
                        <option value="">All statuses</option>
                        <option value="upcoming"<?= $periodStatusFilter === 'upcoming' ? ' selected' : '' ?>>Upcoming</option>
                        <option value="open"<?= $periodStatusFilter === 'open' ? ' selected' : '' ?>>Open</option>
                        <option value="closed"<?= $periodStatusFilter === 'closed' ? ' selected' : '' ?>>Closed</option>
                    </select>
                <?php elseif ($moduleKey === 'faculty'): ?>
                    <label class="visually-hidden" for="faculty-department-filter">Filter by department</label>
                    <select class="form-select management-status-filter" id="faculty-department-filter" name="department">
                        <option value="">All departments</option>
                        <?php foreach ($facultyDepartments as $department): ?>
                            <option value="<?= (int) $department['id'] ?>"<?= $facultyDepartmentFilter === (int) $department['id'] ? ' selected' : '' ?>><?= managementEscape($department['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="visually-hidden" for="faculty-status-filter">Filter by account status</label>
                    <select class="form-select management-status-filter" id="faculty-status-filter" name="status">
                        <option value="">All statuses</option>
                        <option value="active"<?= $facultyStatusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                        <option value="inactive"<?= $facultyStatusFilter === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                    </select>
                <?php elseif ($moduleKey === 'students'): ?>
                    <label class="visually-hidden" for="student-department-filter">Filter by department</label>
                    <select class="form-select management-status-filter" id="student-department-filter" name="department">
                        <option value="">All departments</option>
                        <?php foreach ($studentDepartments as $department): ?>
                            <option value="<?= (int) $department['id'] ?>"<?= $studentDepartmentFilter === (int) $department['id'] ? ' selected' : '' ?>><?= managementEscape($department['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="visually-hidden" for="student-year-level-filter">Filter by year level</label>
                    <select class="form-select management-status-filter" id="student-year-level-filter" name="year_level">
                        <option value="">All year levels</option>
                        <?php foreach ($studentYearLevels as $yearLevel): ?>
                            <option value="<?= managementEscape($yearLevel) ?>"<?= $studentYearLevelFilter === $yearLevel ? ' selected' : '' ?>><?= managementEscape($yearLevel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="visually-hidden" for="student-status-filter">Filter by account status</label>
                    <select class="form-select management-status-filter" id="student-status-filter" name="status">
                        <option value="">All statuses</option>
                        <option value="active"<?= $studentStatusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                        <option value="inactive"<?= $studentStatusFilter === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                    </select>
                <?php elseif ($moduleKey === 'enrollments'): ?>
                    <label class="visually-hidden" for="enrollment-term-filter">Filter by academic term</label>
                    <select class="form-select management-status-filter" id="enrollment-term-filter" name="term">
                        <option value="">All academic terms</option>
                        <?php foreach ($enrollmentTerms as $term): ?>
                            <option value="<?= (int) $term['id'] ?>"<?= $enrollmentTermFilter === (int) $term['id'] ? ' selected' : '' ?>><?= managementEscape($term['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="visually-hidden" for="enrollment-faculty-filter">Filter by faculty member</label>
                    <select class="form-select management-status-filter" id="enrollment-faculty-filter" name="faculty">
                        <option value="">All faculty members</option>
                        <?php foreach ($enrollmentFaculty as $facultyMember): ?>
                            <option value="<?= (int) $facultyMember['id'] ?>"<?= $enrollmentFacultyFilter === (int) $facultyMember['id'] ? ' selected' : '' ?>><?= managementEscape($facultyMember['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="visually-hidden" for="enrollment-subject-filter">Filter by subject</label>
                    <select class="form-select management-status-filter" id="enrollment-subject-filter" name="subject">
                        <option value="">All subjects</option>
                        <?php foreach ($enrollmentSubjects as $subject): ?>
                            <option value="<?= (int) $subject['id'] ?>"<?= $enrollmentSubjectFilter === (int) $subject['id'] ? ' selected' : '' ?>><?= managementEscape($subject['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button class="btn btn-outline-primary" type="submit">Search</button>
            </form>
        </div>
        <?php if ($rows === []): ?>
            <?php dashboardEmptyState($search === '' ? 'No records yet. Use the add button to create the first record.' : 'No records match your search.'); ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table portal-table management-table align-middle mb-0">
                    <thead>
                        <tr>
                            <?php foreach ($module['columns'] as $label => $_): ?><th><?= managementEscape($label) ?></th><?php endforeach; ?>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        if (isset($module['format'])) {
                            foreach ($module['format'] as $target => $sourceFields) {
                                $parts = [];
                                foreach ($sourceFields as $sourceField) {
                                    if (isset($row[$sourceField]) && $row[$sourceField] !== '') {
                                        $parts[] = $row[$sourceField];
                                    }
                                }
                                $separator = $target === 'window_label' ? ' to ' : ' · ';
                                $row[$target] = implode($separator, $parts);
                            }
                        }
                        ?>
                        <tr>
                            <?php foreach ($module['columns'] as $column => $key): ?>
                                <?php $cell = $row[$key] ?? ''; ?>
                                <td>
                                    <?php if ($key === 'status' || $key === 'is_active'): ?>
                                        <?php
                                        $active = $key === 'is_active'
                                            ? (int) $cell === 1
                                            : in_array($cell, ['active', 'open', 'completed'], true);
                                        $statusClass = $active
                                            ? 'status-complete'
                                            : ($cell === 'upcoming' ? 'status-upcoming' : 'status-closed');
                                        ?>
                                        <span class="status-badge <?= $statusClass ?>"><?= managementEscape($key === 'is_active' ? ($active ? 'Active' : 'Inactive') : ucfirst((string) $cell)) ?></span>
                                    <?php else: ?>
                                        <?= managementEscape($cell !== '' && $cell !== null ? $cell : '—') ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td>
                                <div class="management-actions">
                                    <?php if (in_array($moduleKey, ['departments', 'subjects', 'sections', 'terms', 'faculty', 'students', 'enrollments', 'periods', 'questions'], true)): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= managementEscape($queryBase . '&view=' . (int) $row['id']) ?>" aria-label="View <?= managementEscape($moduleKey === 'terms' ? 'academic term' : ($moduleKey === 'subjects' ? 'subject' : ($moduleKey === 'sections' ? 'class section' : ($moduleKey === 'faculty' ? 'faculty' : ($moduleKey === 'students' ? 'student' : ($moduleKey === 'enrollments' ? 'enrollment' : ($moduleKey === 'periods' ? 'evaluation period' : ($moduleKey === 'questions' ? 'evaluation question' : 'department')))))))) ?> details">
                                            <i class="bi bi-eye" aria-hidden="true"></i><span class="visually-hidden">View details</span>
                                        </a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= managementEscape($queryBase . '&edit=' . (int) $row['id']) ?>" aria-label="Edit record">
                                        <i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edit</span>
                                    </a>
                                    <?php if ($module['kind'] === 'profile'): ?>
                                        <form method="post" action="<?= managementEscape($formAction) ?>" data-confirm-form data-confirm-message="Change this account's active status?">
                                            <input type="hidden" name="csrf_token" value="<?= managementEscape($token) ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Toggle account status"><i class="bi bi-person-lock" aria-hidden="true"></i></button>
                                        </form>
                                        <?php if ($moduleKey === 'faculty'): ?>
                                            <form method="post" action="<?= managementEscape($formAction) ?>" data-confirm-form data-confirm-message="Delete this faculty profile and its account? Existing class assignments or evaluation records will prevent deletion. Deactivate the account if the member has historical records.">
                                                <input type="hidden" name="csrf_token" value="<?= managementEscape($token) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete faculty profile"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($moduleKey === 'students'): ?>
                                            <form method="post" action="<?= managementEscape($formAction) ?>" data-confirm-form data-confirm-message="Delete this student profile and account? Existing enrollments or evaluation history will prevent deletion. Deactivate the account to preserve historical records.">
                                                <input type="hidden" name="csrf_token" value="<?= managementEscape($token) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete student profile"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif ($moduleKey !== 'questions'): ?>
                                        <form method="post" action="<?= managementEscape($formAction) ?>" data-confirm-form data-confirm-message="<?= $moduleKey === 'sections' ? 'Delete this class section? Sections with student enrollments or evaluation records cannot be deleted.' : ($moduleKey === 'enrollments' ? 'Remove this enrollment? Enrollments with evaluation history cannot be removed.' : 'Delete this record? Records referenced by academic history cannot be deleted.') ?>">
                                            <input type="hidden" name="csrf_token" value="<?= managementEscape($token) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete record"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <nav class="management-pagination" aria-label="Records pages">
                <a class="btn btn-sm btn-outline-secondary<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= managementEscape($paginationBase . '&q=' . rawurlencode($search) . '&page=' . max(1, $page - 1)) ?>">Previous</a>
                <span>Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
                <a class="btn btn-sm btn-outline-secondary<?= $page >= $totalPages ? ' disabled' : '' ?>" href="<?= managementEscape($paginationBase . '&q=' . rawurlencode($search) . '&page=' . min($totalPages, $page + 1)) ?>">Next</a>
            </nav>
        <?php endif; ?>
    </section>
    <?php
});
