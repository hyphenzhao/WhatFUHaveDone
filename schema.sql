-- Work Log + Schedule Planner Database Schema (multi-user)
-- MySQL 8.0
--
-- 全新安装后运行以下命令创建首个管理员：
--   php scripts/create_admin.php <username> <password> [display_name]

CREATE DATABASE IF NOT EXISTS worklog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE worklog;

-- 0. 用户 (Users)
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

-- 0b. 记住我令牌 (Remember-me tokens, selector:validator)
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

-- 1. 人物 (People)
CREATE TABLE IF NOT EXISTS people (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    relationship VARCHAR(255) DEFAULT '',
    bio TEXT,
    importance INT DEFAULT 0 CHECK (importance >= 0 AND importance <= 5),
    usefulness INT DEFAULT 0 CHECK (usefulness >= 0 AND usefulness <= 5),
    closeness INT DEFAULT 0 CHECK (closeness >= 0 AND closeness <= 5),
    is_me TINYINT(1) DEFAULT 0,
    archived TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_people_user (user_id),
    CONSTRAINT fk_people_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. 标签 (Tags)
CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#3B82F6',
    archived TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tags_user (user_id),
    CONSTRAINT fk_tags_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. 成果 (Results)
CREATE TABLE IF NOT EXISTS results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    quantity INT DEFAULT 1,
    level VARCHAR(50) DEFAULT '',
    archived TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_results_user (user_id),
    CONSTRAINT fk_results_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. 成果-标签关联 (Result-Tag many-to-many)
CREATE TABLE IF NOT EXISTS result_tags (
    result_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (result_id, tag_id),
    FOREIGN KEY (result_id) REFERENCES results(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. 任务 (Tasks)
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    stage ENUM('in_progress', 'stage_complete', 'completed', 'failed') DEFAULT 'in_progress',
    stage_number INT DEFAULT 1,
    priority INT DEFAULT 0,
    importance INT DEFAULT 3,
    necessity INT DEFAULT 3,
    deadline VARCHAR(20) DEFAULT '',
    location VARCHAR(255) DEFAULT '',
    stage_changed_at DATETIME NULL DEFAULT NULL,
    archived TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tasks_user (user_id),
    CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. 任务-受益人关联 (Task-People many-to-many)
CREATE TABLE IF NOT EXISTS task_people (
    task_id INT NOT NULL,
    people_id INT NOT NULL,
    PRIMARY KEY (task_id, people_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (people_id) REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 7. 任务-标签关联 (Task-Tag many-to-many)
CREATE TABLE IF NOT EXISTS task_tags (
    task_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (task_id, tag_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8. 任务-成果关联 (Task-Result many-to-many)
CREATE TABLE IF NOT EXISTS task_results (
    task_id INT NOT NULL,
    result_id INT NOT NULL,
    PRIMARY KEY (task_id, result_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (result_id) REFERENCES results(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 9. 每日工作量记录 (Work Logs) — 经 task_id 归属用户
CREATE TABLE IF NOT EXISTS work_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    log_date DATE NOT NULL,
    duration VARCHAR(20) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_task_date (task_id, log_date),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 10. 每日产出记录 (Result Logs) — 经 task_id 归属用户
CREATE TABLE IF NOT EXISTS result_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    result_id INT NOT NULL,
    log_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (result_id) REFERENCES results(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 11. 计划 (Plans - future tasks) — 经 task_id 归属用户
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    planned_date DATE NOT NULL,
    plan_time VARCHAR(10) DEFAULT '',
    plan_end_time VARCHAR(10) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Index for calendar queries
CREATE INDEX idx_work_logs_date ON work_logs(log_date);
CREATE INDEX idx_result_logs_date ON result_logs(log_date);
CREATE INDEX idx_plans_date ON plans(planned_date);
CREATE INDEX idx_tasks_stage ON tasks(stage);
CREATE INDEX idx_tasks_archived ON tasks(archived);

-- 12. 日历元数据 (Calendar Meta — lunar dates, solar terms, holidays) — 全局共享
CREATE TABLE IF NOT EXISTS calendar_meta (
    date DATE PRIMARY KEY,
    lunar_month VARCHAR(10) DEFAULT '',
    lunar_day VARCHAR(10) DEFAULT '',
    solar_term VARCHAR(20) DEFAULT '',
    holiday_name VARCHAR(50) DEFAULT '',
    is_holiday TINYINT(1) DEFAULT 0,
    is_workday TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. 天气缓存与本地位置设置 (Weather Cache) — 全局共享
CREATE TABLE IF NOT EXISTS weather_cache (
    date DATE NOT NULL,
    city VARCHAR(100) NOT NULL,
    data_json LONGTEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (date, city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. AI 配置 (AI Assistant Configuration) — 每用户一行，随首次访问/建用户时创建
CREATE TABLE IF NOT EXISTS ai_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'ollama',
    endpoint VARCHAR(512) NOT NULL DEFAULT 'http://localhost:11434/v1',
    api_key VARCHAR(512) NOT NULL DEFAULT '',
    model VARCHAR(128) NOT NULL DEFAULT 'qwen2.5:7b',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ai_config_user (user_id),
    CONSTRAINT fk_ai_config_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. AI 对话记录 (AI Conversations)
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL DEFAULT '新对话',
    messages_json LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ai_conv_user (user_id),
    CONSTRAINT fk_ai_conv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. 个人侧写 (User Profile) — 每用户一行
CREATE TABLE IF NOT EXISTS user_profile (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    birth_date VARCHAR(10) NOT NULL DEFAULT '',
    birth_time VARCHAR(10) NOT NULL DEFAULT '',
    birth_place VARCHAR(255) NOT NULL DEFAULT '',
    gender VARCHAR(10) NOT NULL DEFAULT '',
    resume LONGTEXT,
    goals LONGTEXT,
    bazi_year VARCHAR(20) NOT NULL DEFAULT '',
    bazi_month VARCHAR(20) NOT NULL DEFAULT '',
    bazi_day VARCHAR(20) NOT NULL DEFAULT '',
    bazi_time VARCHAR(20) NOT NULL DEFAULT '',
    shishen LONGTEXT,
    nayin LONGTEXT,
    dayun LONGTEXT,
    shengxiao VARCHAR(20) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_profile_user (user_id),
    CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 17. 八字分析缓存 (BaZi Analysis)
CREATE TABLE IF NOT EXISTS bazi_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date_key DATE NOT NULL,
    type VARCHAR(32) NOT NULL,
    period_label VARCHAR(100) NOT NULL,
    gan_zhi VARCHAR(50) NOT NULL DEFAULT '',
    shi_shen VARCHAR(100) NOT NULL DEFAULT '',
    analysis LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bazi_user (user_id, date_key, type, period_label),
    CONSTRAINT fk_bazi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 18. AI 技能 (AI Skills) — 全局共享，仅管理员可写
CREATE TABLE IF NOT EXISTS ai_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    file_path VARCHAR(512) NOT NULL DEFAULT '',
    content LONGTEXT,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ai_skill_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 19. 工作记录备注 (Worklog Notes) — 经 worklog→task 归属用户
CREATE TABLE IF NOT EXISTS worklog_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worklog_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (worklog_id) REFERENCES work_logs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 20. 附件 (Attachments — persistent uploads attached to notes/results/reports/tasks)
CREATE TABLE IF NOT EXISTS attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    entity_type VARCHAR(32) NOT NULL,     -- worklog_note | result | report | task
    entity_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,       -- original filename
    stored_name VARCHAR(255) NOT NULL,     -- unique name on disk (uploads/attachments/)
    mime VARCHAR(128) DEFAULT '',
    size INT DEFAULT 0,
    extracted_text LONGTEXT,               -- best-effort extracted text for report reference
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attachments_entity (entity_type, entity_id),
    KEY idx_attachments_user (user_id),
    CONSTRAINT fk_attachments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 21. 周期报告 (Periodic Reports — daily/weekly/monthly/yearly)
CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    period_type ENUM('daily','weekly','monthly','yearly') NOT NULL,
    period_key VARCHAR(20) NOT NULL,       -- 2026-08-01 | 2026-W31 | 2026-08 | 2026
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    title VARCHAR(200) NOT NULL DEFAULT '',
    content_md LONGTEXT,                    -- rendered markdown report
    stats_json LONGTEXT,                    -- computed stats snapshot
    model VARCHAR(128) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_report_user_period (user_id, period_type, period_key),
    CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
