# Faculty Evaluation System

## Project overview

A PHP and MySQLi web application for managing academic records and student
evaluations of faculty. It provides separate administrator, student, and
faculty portals and is designed to run locally in XAMPP or be configured for a
PHP/MySQL shared host.

## Technologies

- PHP with MySQLi and MySQL `mysqlnd` support
- MySQL or a compatible MariaDB server with InnoDB foreign keys
- HTML, CSS, JavaScript, and Bootstrap 5
- Bootstrap Icons and Chart.js loaded from public CDNs

The local integration checks for this version used PHP 8.5.12 and MariaDB
10.11.16. The project does not declare a lower supported PHP version; use a
currently supported PHP release with `mysqli`, `mysqlnd`, and `session`
enabled.

## Features

- Responsive ISUFST-inspired sign-in page and role-based administrator,
  student, and faculty portals using the images in `assets/images/`.
- Database-backed administrator management for students, faculty, departments,
  subjects, academic terms, class sections, enrollments, evaluation periods,
  and evaluation questions.
- Department management includes searchable, paginated records, a details view
  with faculty/student profile counts, validated add/edit forms, and safe
  deletion that respects existing department relationships.
- Subject management supports validated subject codes, names, and units,
  searchable listings, details with database-backed class-section counts,
  duplicate-code prevention, and deletion protection for subjects referenced
  by class sections.
- Class section management assigns an existing subject, active faculty account,
  academic term, and section name; supports editing/reassignment, search,
  pagination, details with live enrollment/evaluation counts, duplicate
  prevention, and deletion protection when enrollment or evaluation history
  references the section. Faculty reassignment preserves these related records.
- Academic term management includes searchable and status-filtered records,
  pagination, linked-record details, validated add/edit forms, duplicate
  school-year/semester prevention, and deletion protected by existing
  relationships. The existing `academic_terms` schema has no start/end date
  columns; date ranges are stored on `evaluation_periods` and are not duplicated
  or inferred by term management.
- Faculty management creates faculty user/profile records together using a
  password hash, requires a 12-character minimum initial password that admins
  deliver through a secure channel, requires a department, supports account
  activation and deactivation, search and department/status filters, pagination,
  details, and safe deletion guarded by existing class-section and evaluation
  relationships.
- Student management creates student user/profile records together using a
  password hash, requires a 12-character minimum initial password that admins
  deliver through a secure channel, and supports account activation and
  deactivation, search, department/year-level/status filters, pagination,
  details, and safe deletion guarded by existing enrollment and evaluation
  relationships. Student names use the existing `users.full_name` field; the
  current schema does not store separate first, middle, or last names.
- Student enrollment management registers existing active students in existing
  class sections, prevents duplicate student/section pairs, and provides
  searchable, paginated listings with academic-term, faculty, and subject
  filters. Enrollment details show the related student, section, subject,
  faculty, and term. Removal is blocked when evaluation history exists for the
  student and section; evaluation records are never deleted automatically.
- Evaluation-period management supports validated date ranges, status changes,
  search and status filters, and details with section and response counts.
  Evaluation-question management supports adding, editing, activating, and
  deactivating questions, displays them in category/order sequence, and
  preserves starter questions. Question wording, category, and order cannot be
  changed after responses use a question; administrators can deactivate it and
  add a replacement instead.
- Student evaluations are restricted to sections enrolled by the authenticated
  student and periods in the section's academic term that are open and within
  their dates. Active answers are validated against the current questionnaire
  and saved with the evaluation in one transaction; duplicates are prevented
  by server validation and database uniqueness. Student identity is not included
  in faculty-facing results.
- Students now select an enrolled subject/section, confirm its assigned
  faculty, and choose a period before opening the questionnaire. Choices are
  revalidated against the authenticated student's enrollment and the database
  assignment; the current schema assigns one faculty member to each section.
  The student dashboard shows enrollment/faculty counts, available evaluation
  progress, pending/completed/closed/upcoming states, and history links.
- The student questionnaire shows answered-question progress, identifies the
  first unanswered required question, allows an answer review, and confirms
  final submission. Server-side validation remains authoritative.
- Faculty dashboards limited to the signed-in faculty member. Result averages
  and rating breakdowns are hidden until the configured response threshold
  is reached. Individual written comments are retained as academic records but
  are not shown to faculty because comment wording and submission timing can
  reveal a student's identity.
- Administrator evaluation results and reports with term, evaluation-period,
  department, faculty, subject, section, and text filters. Reports include
  response totals, enrolled section-period participation, overall and
  question/category averages, rating distributions, print layouts, and
  CSV and Excel-compatible exports. Reports do not include student identities.
- Evaluation completion monitoring on the administrator dashboard and a
  filterable section report. Expected opportunities are enrolled student ×
  section × eligible period records; upcoming periods are excluded, while
  currently open and closed/expired periods are included. Completion and
  pending values are matched to actual evaluation records.
- Faculty members have a dedicated, authenticated results page that derives
  ownership from the signed-in faculty account, supports term and evaluation
  period filters, and shows only aggregated overall, per-section, category,
  and question ratings. Per-section and rating details honor the configured
  minimum-response threshold; faculty exports contain aggregates only.
  Faculty dashboards and result exports do not include written comments or
  student identities. Faculty results include rating distribution charts and
  academic-term comparisons when each requested group meets the threshold.

All accounts, evaluation records, dashboard totals, and reports are read from
the configured MySQL database. The application does not create or alter the
database schema automatically.

## Local setup

1. Start Apache and MySQL in XAMPP.
2. Ensure the `faculty_evaluation_system` database and its application tables
   are available. This repository does not contain a complete base-schema
   creation script. For a new installation, first obtain/import a compatible
   database export; the included migrations only update parts of an existing
   schema and do not create the application tables.
3. If the integrity constraints have not already been applied, run
   `database/migrations/001_add_integrity_constraints.sql` once. To add
   questions where an evaluation category has fewer than three active
   questions, run `database/migrations/002_complete_active_question_categories.sql`.
   The question migration is safe to rerun. Before using Academic Term
   Management on an existing database, check and resolve any duplicate
   school-year/semester pairs, then run
   `database/migrations/003_unique_academic_terms.sql` once.
4. If there is no administrator account, open PowerShell in the project
   directory and run `.\database\create_admin.ps1`. Enter the administrator's
   name, email, and password when prompted. The password prompt is hidden. The
   CLI-only setup checks that no administrator exists, hashes the password,
   and never updates or deletes existing accounts.
5. Open `http://localhost/faculty_evaluation_system/`.
6. Sign in with an active account. Passwords in `users.password_hash` must be
   generated with `password_hash()`. Supported roles are `admin`, `student`,
   and `faculty`.

The local connection defaults are intended for XAMPP only. For a separate
local database, set `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, and `DB_DATABASE`
before starting PHP/Apache, or configure them through the local PHP/Apache
environment.

Local XAMPP defaults are used only when the detected host is localhost (or
`APP_ENV=local` is explicitly set). They can be overridden with `DB_HOST`,
`DB_USERNAME`, `DB_PASSWORD`, and `DB_DATABASE`.

## Production configuration

Set `APP_ENV=production` and provide `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`,
and `DB_DATABASE` in the hosting environment. Production mode refuses to fall
back to local XAMPP credentials when configuration is missing. Keep these
values out of source control and use HTTPS so session cookies are marked
secure.

For faculty-facing dashboards and result breakdowns, the default minimum
response threshold is five. Set `MIN_EVALUATIONS_FOR_FACULTY_RESULTS` in the
hosting environment to change it.

## Database setup and backup

The database contains related user/profile, academic, enrollment, period,
evaluation, and answer tables. Preserve the existing foreign keys and unique
constraints. Do not run the migrations against production without first
backing up the database and checking the migration prerequisites; in
particular, migration 003 requires duplicate academic-term pairs to be
resolved first.

For an existing XAMPP installation, use phpMyAdmin's **Export** tab or the
MySQL/MariaDB command-line client to export the complete
`faculty_evaluation_system` database, including its table definitions,
constraints, and data. Create a database on the destination host, then use
phpMyAdmin's **Import** tab (or the command-line client, if available) to
restore that export. Check the hosting provider's upload-size and SQL-version
limits first. No database export is included in this repository. Do not
overwrite a destination containing records you need to keep.

## Roles and permissions

- **Administrator:** manages academic reference data, accounts, class
  sections, enrollments, evaluation periods/questions, and aggregate reports.
- **Student:** views their enrolled classes and may submit one evaluation for
  an eligible class section during an open, in-date period.
- **Faculty:** sees only aggregate results for their own assigned sections.
  Faculty results and exports do not contain student identifiers or individual
  records; the default minimum-response threshold is five. Individual written
  comments remain stored but are not shown to faculty.

The application rechecks the signed-in account's active status and role on
authenticated requests. Use active, correctly associated student/faculty
profiles when testing role access.

## Testing each role locally

1. Sign in as an active administrator and check the dashboard, management
   pages, evaluation-period/question pages, and administrator reports.
2. Create or use an active student account, enroll that student in a class
   section, and create an open evaluation period for the section's academic
   term. Sign in as the student and confirm only eligible, not-yet-submitted
   evaluations can be opened and submitted.
3. Create or use an active faculty account assigned to a class section. Sign
   in as that faculty member and confirm only their aggregated results are
   visible.
4. Verify that cross-role pages are denied and that a signed-out browser is
   redirected to sign-in. Do not use real student/faculty evaluation data as
   test data; use uniquely labeled temporary records and remove them after
   testing.

The final integration checks for this project were run locally against the
existing XAMPP database. They covered role-specific sign-in/access, all
administrator management-page availability, student eligibility and
submission validation, duplicate prevention, faculty ownership/privacy,
reports/exports/print behavior, CSRF rejection, inactive-account session
handling, database uniqueness/foreign-key enforcement, and desktop/mobile
layout checks. See **Local verification** below for the checks and final data
state. These targeted checks do not constitute a full CRUD regression test for
every management screen.

## Local verification

The final integration pass on the local XAMPP/MariaDB installation verified:

- All 16 project PHP files passed `php -l`.
- The sign-in page and public entry route loaded; anonymous requests to
  administrator pages redirected to sign-in. A sign-in request without a
  CSRF token was rejected.
- All administrator management screens loaded, role access was enforced, and
  student/faculty/admin dashboards and reports were exercised. Testing the
  screens confirms routing and access but was not a full create/edit/delete
  regression pass for every management entity.
- Eligible student evaluation, unauthorized enrollment denial, open/closed/
  upcoming/expired period messaging, answer validation, authenticated-student
  ownership, duplicate submission prevention, and successful transactional
  evaluation submission were tested.
- Faculty result ownership, aggregate-only output, minimum-response
  suppression, filters, CSV privacy, and report printing were checked. No
  student identities or individual comments were shown in faculty output.
- Admin and faculty report exports, zero-response handling, and print
  controls/styles were checked.
- Marked QA data produced six complete evaluations with 90 answers. Faculty
  aggregation matched the stored ratings (five responses, 75 answers, and a
  3.20 average for one faculty member); a second faculty member's single
  response remained hidden and their export was denied. The administrator's
  section-filtered monitoring matched 5/5 and 1/1 expected/completed
  evaluations, and the six-response QA result average was 3.50. CSV and
  Excel-compatible exports contained no QA student identities or comments.
- Student access checks confirmed that only enrolled sections were offered,
  mismatched subject/faculty IDs and a non-enrolled section were rejected,
  duplicate submissions were blocked, and a missing CSRF token was rejected.
  The dashboard displayed 1 of 2 available evaluations (50%) without PHP
  warnings after fixing a missing closure capture. At desktop size, the
  administrator dashboard and report fit the viewport; at mobile size, the
  student dashboard, faculty results, and administrator report had no
  document-level horizontal overflow after the report heading layout
  adjustment.
- Inactive-account session invalidation, role denial, logout CSRF, database
  uniqueness/foreign-key enforcement, and desktop/mobile overflow were checked.
- The live schema has 12 tables. After QA cleanup, one administrator, all 15
  active starter questions, and the pre-existing historical evaluation with
  its 15 answers remained. There were no marked QA records, orphaned
  evaluation/answer relationships, or duplicate enrollment/evaluation/
  answer/term groups.
- Temporary QA database rows, cookie jars, response bodies, and browser output
  files were removed after testing.

Security review also fixed two privacy/access issues: authenticated requests
now re-check current account status/role, and faculty no longer see individual
comments that could identify students. Testing found that database migration
files and PHP support files were directly reachable over HTTP; the
`config`, `database`, and `includes` directories are now denied by
`.htaccess`, and direct requests returned HTTP 403 locally.

No live InfinityFree deployment or automated test suite was run. Database
transaction rollback on an induced answer-insert failure and full CRUD
regression of every management entity were not independently exercised.

## Using the enhanced workflows

The student flow starts at **Evaluate now** (or **Evaluate Faculty** in the
sidebar): choose an enrolled subject/section, confirm its assigned faculty,
select a period, and continue only when its status is Pending. After submission,
the dashboard counters are recalculated from the database; the history page
lists the student's own submissions.

Faculty can open **Performance results** from their portal, choose a term or
period, and see aggregates only when at least the configured minimum number of
evaluations match. CSV and Excel-compatible exports apply the same ownership
and response-threshold checks.

Administrators can use **Evaluation monitoring** from the dashboard/sidebar to
filter completion by term, academic year, semester, period, department,
faculty, subject, and section. CSV and Excel-compatible exports honor those
filters. Use **Print / Save PDF** and choose the browser's **Save as PDF**
destination; this project does not generate a binary PDF file itself.

## Deployment preparation (InfinityFree or another shared host)

No upload or live-host test has been performed. Before deployment:

1. Confirm the provider's current PHP version and enablement of `mysqli`,
   `mysqlnd`, and `session`; `mysqli_stmt::get_result()` requires mysqlnd.
2. Create the database/user in the provider control panel and note the exact
   database host, name, username, and password. Hosting database hosts are
   often not `localhost`.
3. Confirm how the provider securely supplies environment variables. Set
   `APP_ENV=production`, `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, and
   `DB_DATABASE` there. Production mode intentionally fails when any required
   database setting is missing; do not put production secrets in this
   repository or commit them.
4. Export/import the existing complete database as described above, then
   verify the application tables, constraints, and administrator login on the
   hosting account before inviting users.
5. Upload the application files under the intended web root. The base URL is
   computed from the installation directory rather than a hard-coded
   `localhost` URL, but confirm links and redirects when installed in the
   provider's chosen subdirectory.
6. Enable HTTPS and test login/logout so secure session-cookie behavior is
   active. Keep `config`, `database`, and `includes` inaccessible to direct
   web requests; the included `.htaccess` files deny direct access on Apache.
   Verify that the host honors these files and does not return a server error.
7. Create the first administrator before deployment where practical, using
   `database/create_admin.ps1` on Windows with the intended database
   environment, then include that account in the database export. The
   initializer is CLI-only, requires a 12-character password, hashes it, and
   refuses to create another admin when one already exists. If the host
   provides secure CLI access, it can instead be run there with
   `ADMIN_SETUP_NAME`, `ADMIN_SETUP_EMAIL`, and `ADMIN_SETUP_PASSWORD` set for
   that process. Never make it callable over HTTP.
8. Bootstrap/PowerShell setup tools and SQL migrations are not needed to serve
   the application. After any required database setup, remove them from the
   public deployment or keep them blocked from HTTP as the included rules do.
   Do not remove the application's PHP files or migrations from the local
   development copy.
9. Confirm the host permits outbound CDN requests for Bootstrap, Bootstrap
   Icons, and Chart.js, or plan a separately reviewed local asset deployment.

Shared-hosting restrictions, environment-variable support, Apache `.htaccess`
support, database import limits, and CLI access vary by provider and plan.
Confirm those details in the current hosting control panel/support material
before deploying. The application has not been validated on InfinityFree.

## Known limitations

- A complete fresh-install SQL schema is not included; an existing compatible
  database export is required.
- InfinityFree/shared-host deployment, environment-variable configuration,
  database import, HTTPS, and provider-specific permissions still require
  manual verification.
- Bootstrap, Bootstrap Icons, and Chart.js use CDNs, so those interface assets
  require network access unless deliberately hosted locally.
- No automated project test suite or dependency manifest is included. The
  checks described above are targeted manual/integration checks, not a
  substitute for provider-specific staging or a full automated regression
  suite.
- `class_sections` stores one assigned faculty member. A student can evaluate
  different faculty across distinct enrolled sections, but selecting among
  multiple co-teachers within one section would require a separately designed
  schema and assignment-workflow change.

## Authentication and navigation

- `login.php` authenticates active users using a prepared statement and
  `password_verify()`.
- `database/create_admin.ps1` securely prompts for first-admin credentials and
  invokes the CLI-only `database/create_admin.php` initializer. The initializer
  refuses web requests and refuses to create another administrator once one
  exists.
- Successful sign-in regenerates the session ID and routes users to their
  role-specific dashboard.
- Each authenticated request reloads the account's current role and checks
  that the account is still active; deactivated or removed accounts are signed
  out on their next request.
- Dashboard, management, and reporting pages enforce role access.
- Faculty result queries are scoped to the faculty profile linked to the
  authenticated account; they never accept a faculty identifier from the
  request or select student account fields. Faculty result pages support
  print-friendly output and threshold-protected aggregate CSV exports.
- `logout.php` accepts only a CSRF-protected POST request.
- The password visibility control, responsive sidebar, and report print control
  are implemented in `assets/js/script.js`.
