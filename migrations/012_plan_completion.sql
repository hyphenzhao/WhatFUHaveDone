-- Preserve completed plans and their original scheduled time.
-- Existing plans remain pending; do not infer completion from task-wide work logs.
ALTER TABLE plans
    ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER plan_end_time,
    ADD COLUMN completed_at DATETIME NULL AFTER is_completed;
