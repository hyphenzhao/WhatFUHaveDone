-- ============================================================
-- Migration 009: 邮件正文内的文字高亮（荧光笔）
--
-- 执行：mysql -uworklog -p worklog < migrations/009_mail_text_highlights.sql
--
-- 说明：按「片段文本」存储，而不是字符偏移量。正文经过净化与 cid 重写，
--       偏移量会随之漂移；片段文本在正文不变时始终能重新定位，定位不到时
--       仍可作为「重点摘录」列出，不会丢失。
--       mail_messages.is_highlighted 继续作为"这封有高亮"的列表标记，
--       由片段的增删自动维护。
-- ============================================================

USE worklog;

CREATE TABLE IF NOT EXISTS mail_highlights (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message_id INT NOT NULL,
    snippet VARCHAR(1000) NOT NULL,             -- 被划中的原文
    note VARCHAR(500) NOT NULL DEFAULT '',      -- 为什么重要（可选，AI 常用）
    source VARCHAR(8) NOT NULL DEFAULT 'user',  -- user | ai
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mail_hl_msg (message_id),
    KEY idx_mail_hl_user (user_id),
    CONSTRAINT fk_mail_hl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_hl_msg FOREIGN KEY (message_id) REFERENCES mail_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT COUNT(*) AS ok FROM information_schema.tables
 WHERE table_schema = 'worklog' AND table_name = 'mail_highlights';
