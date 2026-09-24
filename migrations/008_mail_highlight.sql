-- ============================================================
-- Migration 008: 邮件高亮（本地标记，不同步到 IMAP）
--
-- 执行：mysql -uworklog -p worklog < migrations/008_mail_highlight.sql
--
-- 说明：高亮是用户与 AI 助手之间的共享指针——"看我高亮的那几封"、
--       "我把需要处理的三封高亮了"。它刻意与 IMAP 的 \Flagged 星标分开：
--       星标会同步回邮件服务器，高亮只存在本地，不污染你在其他客户端的标记。
-- ============================================================

USE worklog;

ALTER TABLE mail_messages
    ADD COLUMN is_highlighted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_flagged,
    ADD COLUMN highlighted_at DATETIME NULL AFTER is_highlighted,
    ADD KEY idx_mail_msg_highlight (user_id, is_highlighted);

SELECT COUNT(*) AS ok FROM information_schema.columns
 WHERE table_schema = 'worklog' AND table_name = 'mail_messages'
   AND column_name IN ('is_highlighted', 'highlighted_at');
