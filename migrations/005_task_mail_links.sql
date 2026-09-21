-- ============================================================
-- Migration 005: 任务 ↔ 邮件关联
--
-- 执行：mysql -uworklog -p worklog < migrations/005_task_mail_links.sql
--
-- 说明：只存“匹配上的”邮件（以及用户确认/排除的结论），不存不匹配的组合。
--       tasks.mail_match_upto = 已比对到的 mail_analysis.id 水位，之后只比对新分析的邮件。
-- ============================================================

USE worklog;

ALTER TABLE tasks ADD COLUMN mail_match_upto INT NOT NULL DEFAULT 0 AFTER location;

CREATE TABLE IF NOT EXISTS task_mail_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    task_id INT NOT NULL,
    message_id INT NOT NULL,                        -- mail_messages.id
    score TINYINT UNSIGNED NOT NULL DEFAULT 0,      -- 0-100 (AI); 100 when confirmed by the user
    reason VARCHAR(300) NOT NULL DEFAULT '',
    status VARCHAR(10) NOT NULL DEFAULT 'ai',       -- ai | confirmed | rejected
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_task_mail (task_id, message_id),
    KEY idx_task_mail_user (user_id),
    KEY idx_task_mail_message (message_id),
    CONSTRAINT fk_task_mail_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_mail_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_mail_message FOREIGN KEY (message_id) REFERENCES mail_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT COUNT(*) AS ok FROM information_schema.tables WHERE table_schema = 'worklog' AND table_name = 'task_mail_links';
