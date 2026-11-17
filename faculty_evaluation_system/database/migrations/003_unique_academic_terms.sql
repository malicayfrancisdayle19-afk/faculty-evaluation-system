-- Prevent two records for the same school year and semester.
-- Check the result first and resolve duplicates without deleting historical data.
SELECT school_year, semester, COUNT(*) AS duplicate_count
FROM academic_terms
GROUP BY school_year, semester
HAVING COUNT(*) > 1;

ALTER TABLE academic_terms
    ADD CONSTRAINT uq_academic_terms_school_year_semester
    UNIQUE (school_year, semester);
