-- Meetings automatically complete their scheduled plans after the meeting ends.
ALTER TABLE tasks
    ADD COLUMN task_type ENUM('general','meeting') NOT NULL DEFAULT 'general' AFTER description,
    ADD COLUMN meeting_auto_from DATE NULL AFTER task_type;
ALTER TABLE plans ADD KEY idx_plans_completion (is_completed, planned_date, task_id);
