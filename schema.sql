-- Work Log + Schedule Planner Database Schema
-- MySQL 8.0

CREATE DATABASE IF NOT EXISTS worklog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE worklog;

-- 1. 人物 (People)
CREATE TABLE IF NOT EXISTS people (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    relationship VARCHAR(255) DEFAULT '',
    importance INT DEFAULT 0 CHECK (importance >= 0 AND importance <= 5),
    usefulness INT DEFAULT 0 CHECK (usefulness >= 0 AND usefulness <= 5),
    closeness INT DEFAULT 0 CHECK (closeness >= 0 AND closeness <= 5),
    is_me TINYINT(1) DEFAULT 0,
    archived TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. 标签 (Tags)
CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#3B82F6',
    archived TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. 成果 (Results)
CREATE TABLE IF NOT EXISTS results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    quantity INT DEFAULT 1,
    level VARCHAR(50) DEFAULT '',
    archived TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
    name VARCHAR(255) NOT NULL,
    description TEXT,
    stage ENUM('in_progress', 'stage_complete', 'completed', 'failed') DEFAULT 'in_progress',
    stage_number INT DEFAULT 1,
    archived TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

-- 9. 每日工作量记录 (Work Logs)
CREATE TABLE IF NOT EXISTS work_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    log_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_task_date (task_id, log_date),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 10. 每日产出记录 (Result Logs)
CREATE TABLE IF NOT EXISTS result_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    result_id INT NOT NULL,
    log_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (result_id) REFERENCES results(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 11. 计划 (Plans - future tasks)
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    planned_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Index for calendar queries
CREATE INDEX idx_work_logs_date ON work_logs(log_date);
CREATE INDEX idx_result_logs_date ON result_logs(log_date);
CREATE INDEX idx_plans_date ON plans(planned_date);
CREATE INDEX idx_tasks_stage ON tasks(stage);
CREATE INDEX idx_tasks_archived ON tasks(archived);

-- 12. 日历元数据 (Calendar Meta — lunar dates, solar terms, holidays)
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

-- 13. 天气缓存与本地位置设置 (Weather Cache)
CREATE TABLE IF NOT EXISTS weather_cache (
    date DATE NOT NULL,
    city VARCHAR(100) NOT NULL,
    data_json LONGTEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (date, city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. AI 配置 (AI Assistant Configuration)
CREATE TABLE IF NOT EXISTS ai_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL DEFAULT 'ollama',
    endpoint VARCHAR(512) NOT NULL DEFAULT 'http://localhost:11434/v1',
    api_key VARCHAR(512) NOT NULL DEFAULT '',
    model VARCHAR(128) NOT NULL DEFAULT 'qwen2.5:7b',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO ai_config (id, provider, endpoint, api_key, model) VALUES (1, 'ollama', 'http://localhost:11434/v1', '', 'qwen2.5:7b');

-- 15. AI 对话记录 (AI Conversations)
CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL DEFAULT '新对话',
    messages_json LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. 个人侧写 (User Profile)
CREATE TABLE IF NOT EXISTS user_profile (
    id TINYINT UNSIGNED PRIMARY KEY,
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO user_profile (id, name) VALUES (1, '');

-- 17. 八字分析缓存 (BaZi Analysis)
CREATE TABLE IF NOT EXISTS bazi_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date_key DATE NOT NULL,
    type VARCHAR(32) NOT NULL,
    period_label VARCHAR(100) NOT NULL,
    gan_zhi VARCHAR(50) NOT NULL DEFAULT '',
    shi_shen VARCHAR(100) NOT NULL DEFAULT '',
    analysis LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_bazi_analysis (date_key, type, period_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 18. AI 技能 (AI Skills)
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

-- 19. 工作记录备注 (Worklog Notes)
CREATE TABLE IF NOT EXISTS worklog_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worklog_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (worklog_id) REFERENCES work_logs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
