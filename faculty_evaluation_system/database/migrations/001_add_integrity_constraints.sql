-- Data-preserving uniqueness constraints required by the enrollment and
-- evaluation workflows. No rows are removed by this migration.
--
-- Run these checks first. Resolve any returned duplicate groups manually
-- without deleting historical evaluation data before applying the ALTERs.
SELECT student_id, class_section_id, COUNT(*) AS duplicate_count
FROM enrollments
GROUP BY student_id, class_section_id
HAVING COUNT(*) > 1;

SELECT student_id, class_section_id, evaluation_period_id, COUNT(*) AS duplicate_count
FROM evaluations
GROUP BY student_id, class_section_id, evaluation_period_id
HAVING COUNT(*) > 1;

SELECT evaluation_id, question_id, COUNT(*) AS duplicate_count
FROM evaluation_answers
GROUP BY evaluation_id, question_id
HAVING COUNT(*) > 1;

SELECT subject_id, faculty_id, academic_term_id, section_name, COUNT(*) AS duplicate_count
FROM class_sections
GROUP BY subject_id, faculty_id, academic_term_id, section_name
HAVING COUNT(*) > 1;

ALTER TABLE enrollments
    ADD CONSTRAINT uq_enrollments_student_section
    UNIQUE (student_id, class_section_id);

ALTER TABLE evaluations
    ADD CONSTRAINT uq_evaluations_student_section_period
    UNIQUE (student_id, class_section_id, evaluation_period_id);

ALTER TABLE evaluation_answers
    ADD CONSTRAINT uq_evaluation_answers_evaluation_question
    UNIQUE (evaluation_id, question_id);

ALTER TABLE class_sections
    ADD CONSTRAINT uq_class_sections_assignment
    UNIQUE (subject_id, faculty_id, academic_term_id, section_name);
