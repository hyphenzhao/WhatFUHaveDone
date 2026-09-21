-- ============================================================
-- Migration 006: 邮件按“已读日期”归属，而非发送日期
--
-- 执行：mysql -uworklog -p worklog < migrations/006_mail_read_at.sql
--
-- 说明：主页「今日邮件」的归属日期改为：
--         未读  → 永远算作今天（无论何时发送）
--         已读  → 固定在 read_at（首次标记已读的时间）
--       历史数据：已读邮件回填 read_at = msg_date，保持原有位置不变。
-- ============================================================

USE worklog;

ALTER TABLE mail_messages
    ADD COLUMN read_at DATETIME NULL AFTER is_seen,
    ADD KEY idx_mail_msg_user_read (user_id, read_at);

UPDATE mail_messages SET read_at = msg_date WHERE is_seen = 1 AND read_at IS NULL;

SELECT COUNT(*) AS seen_with_read_at, SUM(read_at IS NULL) AS still_null
  FROM mail_messages WHERE is_seen = 1;
