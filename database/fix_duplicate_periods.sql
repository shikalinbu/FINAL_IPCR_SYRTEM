-- FIX DUPLICATE RATING PERIODS
-- This script helps identify and clean up duplicate period records

-- STEP 1: View all duplicate periods (same name + semester + academic_year)
SELECT 
    name, 
    semester, 
    academic_year,
    COUNT(*) as duplicate_count,
    GROUP_CONCAT(id ORDER BY id) as period_ids,
    GROUP_CONCAT(status ORDER BY id) as statuses,
    GROUP_CONCAT(submission_deadline ORDER BY id) as deadlines
FROM rating_periods
GROUP BY name, semester, academic_year
HAVING COUNT(*) > 1;

-- STEP 2: Check if duplicate periods have attached IPCR forms
-- (IMPORTANT: Don't delete periods that have forms!)
SELECT 
    rp.id,
    rp.name,
    rp.status,
    rp.submission_deadline,
    COUNT(f.id) as form_count
FROM rating_periods rp
LEFT JOIN ipcr_forms f ON f.period_id = rp.id
WHERE rp.name LIKE '%July - December 2026%'
GROUP BY rp.id
ORDER BY rp.id;

-- STEP 3: Delete duplicate periods that have NO forms attached
-- (Replace the IDs with actual duplicate period IDs from Step 2)
-- UNCOMMENT AND MODIFY THE IDs BEFORE RUNNING:
-- DELETE FROM rating_periods 
-- WHERE id IN (2, 3)  -- Replace with actual duplicate IDs that have 0 forms
-- AND id NOT IN (SELECT DISTINCT period_id FROM ipcr_forms WHERE period_id IS NOT NULL);

-- STEP 4: Add UNIQUE constraint to prevent future duplicates
-- (Optional - only if you want to enforce uniqueness)
-- ALTER TABLE rating_periods 
-- ADD UNIQUE KEY unique_period (name, semester, academic_year);

-- STEP 5: If periods have forms but are duplicates, consolidate them:
-- Option A: Merge forms to one period
-- UPDATE ipcr_forms SET period_id = [KEEP_THIS_ID] WHERE period_id = [DELETE_THIS_ID];
-- Then delete the empty duplicate period

-- Option B: Rename duplicate periods to make them unique
-- UPDATE rating_periods SET name = CONCAT(name, ' (Archived)') WHERE id = [DUPLICATE_ID];
