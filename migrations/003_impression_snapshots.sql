-- ============================================================
-- Migration 003: AI 印象总结快照（基础 / 阶段性 / 增量，账本式压缩）
--
-- 执行：mysql -uworklog -p worklog < migrations/003_impression_snapshots.sql
-- ============================================================

USE worklog;

CREATE TABLE IF NOT EXISTS profile_impression_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    kind VARCHAR(16) NOT NULL,                  -- base | stage | incremental
    content_md LONGTEXT,
    sources_json LONGTEXT,                      -- {base_id, stage_id, incremental_id, since, counts:{...}}
    model VARCHAR(128) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_imp_snap_user (user_id, kind, id),
    CONSTRAINT fk_imp_snap_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT COUNT(*) AS ok FROM information_schema.tables WHERE table_schema = 'worklog' AND table_name = 'profile_impression_snapshots';
