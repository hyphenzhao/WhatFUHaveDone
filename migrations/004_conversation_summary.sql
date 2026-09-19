-- ============================================================
-- Migration 004: 对话前情提要（长对话的上下文预算）
--
-- 执行：mysql -uworklog -p worklog < migrations/004_conversation_summary.sql
--
-- 说明：全系统共用一个长期对话。超过预算时，较早的轮次被压缩进 summary_text，
--       summary_upto 记录已并入提要的消息条数；浏览器端仍保留完整记录用于显示。
-- ============================================================

USE worklog;

ALTER TABLE ai_conversations
    ADD COLUMN summary_text LONGTEXT NULL AFTER messages_json,
    ADD COLUMN summary_upto INT NOT NULL DEFAULT 0 AFTER summary_text;

SELECT COUNT(*) AS ok FROM information_schema.columns
 WHERE table_schema = 'worklog' AND table_name = 'ai_conversations' AND column_name IN ('summary_text', 'summary_upto');
