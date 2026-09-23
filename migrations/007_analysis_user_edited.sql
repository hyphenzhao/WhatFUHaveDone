-- ============================================================
-- Migration 007: 标记被用户人工校正过的邮件分析
--
-- 执行：mysql -uworklog -p worklog < migrations/007_analysis_user_edited.sql
--
-- 说明：智能助手可在用户确认后修改相关度/优先级/截止时间等字段。
--       被改过的行打上 user_edited，用于界面提示，并避免被后续分析悄悄覆盖
--       （analyze 在 force=false 时本来就跳过已有分析，这里再加一道显式保护）。
-- ============================================================

USE worklog;

ALTER TABLE mail_analysis
    ADD COLUMN user_edited TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN user_edited_at DATETIME NULL AFTER user_edited;

SELECT COUNT(*) AS ok FROM information_schema.columns
 WHERE table_schema = 'worklog' AND table_name = 'mail_analysis'
   AND column_name IN ('user_edited', 'user_edited_at');
