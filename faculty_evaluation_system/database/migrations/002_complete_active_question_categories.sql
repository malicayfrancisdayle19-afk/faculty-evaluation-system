-- Add one question only when an active category has fewer than three.
-- Existing question rows and evaluation answers are not modified.
INSERT INTO evaluation_questions (category, question_text, sort_order, is_active)
SELECT 'Teaching Effectiveness',
       'The faculty provides useful examples that help explain difficult concepts.',
       COALESCE((SELECT MAX(sort_order) + 1 FROM evaluation_questions WHERE category = 'Teaching Effectiveness'), 1),
       1
WHERE (SELECT COUNT(*) FROM evaluation_questions WHERE category = 'Teaching Effectiveness' AND is_active = 1) < 3
  AND NOT EXISTS (
      SELECT 1 FROM evaluation_questions
      WHERE category = 'Teaching Effectiveness'
        AND question_text = 'The faculty provides useful examples that help explain difficult concepts.'
  );

INSERT INTO evaluation_questions (category, question_text, sort_order, is_active)
SELECT 'Classroom Management',
       'The faculty maintains a respectful and inclusive learning environment.',
       COALESCE((SELECT MAX(sort_order) + 1 FROM evaluation_questions WHERE category = 'Classroom Management'), 1),
       1
WHERE (SELECT COUNT(*) FROM evaluation_questions WHERE category = 'Classroom Management' AND is_active = 1) < 3
  AND NOT EXISTS (
      SELECT 1 FROM evaluation_questions
      WHERE category = 'Classroom Management'
        AND question_text = 'The faculty maintains a respectful and inclusive learning environment.'
  );

INSERT INTO evaluation_questions (category, question_text, sort_order, is_active)
SELECT 'Communication Skills',
       'The faculty provides timely and constructive feedback.',
       COALESCE((SELECT MAX(sort_order) + 1 FROM evaluation_questions WHERE category = 'Communication Skills'), 1),
       1
WHERE (SELECT COUNT(*) FROM evaluation_questions WHERE category = 'Communication Skills' AND is_active = 1) < 3
  AND NOT EXISTS (
      SELECT 1 FROM evaluation_questions
      WHERE category = 'Communication Skills'
        AND question_text = 'The faculty provides timely and constructive feedback.'
  );

INSERT INTO evaluation_questions (category, question_text, sort_order, is_active)
SELECT 'Professionalism',
       'The faculty is punctual and prepared for class.',
       COALESCE((SELECT MAX(sort_order) + 1 FROM evaluation_questions WHERE category = 'Professionalism'), 1),
       1
WHERE (SELECT COUNT(*) FROM evaluation_questions WHERE category = 'Professionalism' AND is_active = 1) < 3
  AND NOT EXISTS (
      SELECT 1 FROM evaluation_questions
      WHERE category = 'Professionalism'
        AND question_text = 'The faculty is punctual and prepared for class.'
  );

INSERT INTO evaluation_questions (category, question_text, sort_order, is_active)
SELECT 'Student Engagement',
       'The faculty encourages critical thinking and active participation.',
       COALESCE((SELECT MAX(sort_order) + 1 FROM evaluation_questions WHERE category = 'Student Engagement'), 1),
       1
WHERE (SELECT COUNT(*) FROM evaluation_questions WHERE category = 'Student Engagement' AND is_active = 1) < 3
  AND NOT EXISTS (
      SELECT 1 FROM evaluation_questions
      WHERE category = 'Student Engagement'
        AND question_text = 'The faculty encourages critical thinking and active participation.'
  );
