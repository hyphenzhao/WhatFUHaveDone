-- ============================================================
-- Migration 001: Multi-user support
--
-- 执行前必须：
--   1. 备份数据库：
--      mysqldump -uworklog -p --single-transaction worklog > ~/worklog_backup_$(date +%F_%H%M).sql
--   2. 生成管理员密码哈希并替换下方 @PASSWORD_HASH@ 占位符：
--      php -r "echo password_hash('<密码>', PASSWORD_DEFAULT), PHP_EOL;"
--   3. 执行：mysql -uworklog -p worklog < 001_multiuser.sql
--
-- 说明：
--   - 所有业务表加 user_id，现有数据归属首个管理员（haifeng）。
--   - user_id 保留 DEFAULT 1（=首个管理员），使迁移与代码更新之间的
--     短暂窗口内旧代码 INSERT 不会失败；新代码总是显式写入 user_id。
--   - work_logs/result_logs/plans/worklog_notes 及各 join 表经 task_id
--     由主表隔离，不加列。
--   - ai_skills / calendar_meta / weather_cache 全局共享，不动。
-- ============================================================

USE worklog;

-- ---------- 1. users / auth_tokens ----------

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector CHAR(24) NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_selector (selector),
    KEY idx_tokens_user (user_id),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users (username, display_name, password_hash, role)
VALUES ('haifeng', '赵海丰', '@PASSWORD_HASH@', 'admin');

SET @uid = (SELECT id FROM users WHERE username = 'haifeng');

-- ---------- 2. 业务表加 user_id + 回填 + 索引/外键 ----------

-- people
ALTER TABLE people ADD COLUMN user_id INT NOT NULL DEFAULT 1 AFTER id;
UPDATE people SET user_id = @uid;
ALTER TABLE people ADD KEY idx_people_user (user_id),
    ADD CONSTRAINT fk_people_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- tags
ALTER TABLE tags ADD COLUMN user_id INT NOT NULL DEFAULT 1 AFTER id;
UPDATE tags SET user_id = @uid;
ALTER TABLE tags ADD KEY idx_tags_user (user_id),
    ADD CONSTRAINT fk_tags_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- results
ALTER TABLE results ADD COLUMN user_id INT NOT NULL DEFAULT 1 AFTER id;
UPDATE results SET user_id = @uid;
ALTER TABLE results ADD KEY idx_results_user (user_id),
    ADD CONSTRAINT fk_results_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- tasks
ALTER TABLE tasks ADD COLUMN user_id INT NOT NULL DEFAULT 1 AFTER id;
UPDATE tasks SET user_id = @uid;
ALTER TABLE tasks ADD KEY idx_tasks_user (user_id),
    ADD CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- ai_conversations
ALTER TABLE ai_conversations ADD COLUMN user_id INT NOT NULL DEFAULT 1 AFTER id;
UPDATE ai_conversations SET user_id = @uid;
ALTER TABLE ai_conversations ADD KEY idx_ai_conv_user (user_id),
    ADD CONSTRAINT fk_ai_conv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- attachments
ALTER TABLE attachments ADD COLUMN user_id INT NOT NULL DEFAULT 1 AFTER id;
UPDATE attachments SET user_id = @uid;
ALTER TABLE attachments ADD KEY idx_attachments_user (user_id),
    ADD CONSTRAINT fk_attachments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- reports（唯一键并入 user_id）
ALTER TABLE reports ADD COLUMN user_id INT NOT NULL DEFAULT 1 AFTER id;
UPDATE reports SET user_id = @uid;
ALTER TABLE reports DROP KEY uniq_report_period;
ALTER TABLE reports ADD UNIQUE KEY uniq_report_user_period (user_id, period_type, period_key),
    ADD CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- bazi_analysis（唯一键并入 user_id）
ALTER TABLE bazi_analysis ADD COLUMN user_id INT NOT NULL DEFAULT 1 AFTER id;
UPDATE bazi_analysis SET user_id = @uid;
ALTER TABLE bazi_analysis DROP KEY unique_bazi_analysis;
ALTER TABLE bazi_analysis ADD UNIQUE KEY uniq_bazi_user (user_id, date_key, type, period_label),
    ADD CONSTRAINT fk_bazi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- user_profile（单例 id=1 → 每用户一行）
ALTER TABLE user_profile MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE user_profile ADD COLUMN user_id INT NOT NULL DEFAULT 1 AFTER id;
UPDATE user_profile SET user_id = @uid;
ALTER TABLE user_profile ADD UNIQUE KEY uniq_profile_user (user_id),
    ADD CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- ai_config（单例 id=1 → 每用户一行）
ALTER TABLE ai_config ADD COLUMN user_id INT NOT NULL DEFAULT 1 AFTER id;
UPDATE ai_config SET user_id = @uid;
ALTER TABLE ai_config ADD UNIQUE KEY uniq_ai_config_user (user_id),
    ADD CONSTRAINT fk_ai_config_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- ---------- 3. 自检（total 应等于 owned） ----------

SELECT 'users' AS tbl, COUNT(*) AS total, SUM(role='admin') AS owned FROM users
UNION ALL SELECT 'people', COUNT(*), SUM(user_id=@uid) FROM people
UNION ALL SELECT 'tags', COUNT(*), SUM(user_id=@uid) FROM tags
UNION ALL SELECT 'results', COUNT(*), SUM(user_id=@uid) FROM results
UNION ALL SELECT 'tasks', COUNT(*), SUM(user_id=@uid) FROM tasks
UNION ALL SELECT 'ai_conversations', COUNT(*), SUM(user_id=@uid) FROM ai_conversations
UNION ALL SELECT 'attachments', COUNT(*), SUM(user_id=@uid) FROM attachments
UNION ALL SELECT 'reports', COUNT(*), SUM(user_id=@uid) FROM reports
UNION ALL SELECT 'bazi_analysis', COUNT(*), SUM(user_id=@uid) FROM bazi_analysis
UNION ALL SELECT 'user_profile', COUNT(*), SUM(user_id=@uid) FROM user_profile
UNION ALL SELECT 'ai_config', COUNT(*), SUM(user_id=@uid) FROM ai_config;
