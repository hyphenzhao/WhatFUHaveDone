-- ============================================================
-- Migration 002: 邮箱 (Mailbox) + 用户侧写文档/AI 印象 + 共享对话
--
-- 执行前必须：
--   1. 备份数据库：
--      mysqldump -uworklog -p --single-transaction worklog > ~/worklog_backup_$(date +%F_%H%M).sql
--   2. 执行：mysql -uworklog -p worklog < migrations/002_mail_profile.sql
--   3. 安装 IMAP 扩展并重启 Apache：
--      sudo apt install -y php8.1-imap && sudo systemctl restart apache2
--   4. 生成邮箱密码加密密钥（config.local.php 已在 .gitignore）：
--      php -r "echo '<?php', PHP_EOL, 'define(\"MAIL_SECRET_KEY\", \"', base64_encode(random_bytes(32)), '\");', PHP_EOL;" > config.local.php
--
-- 说明：
--   - 所有新表按 user_id 隔离，级联删除用户数据。
--   - 邮件附件复用 attachments 表（entity_type='mail_message'），新增 content_id 列用于内嵌图片。
--   - ai_conversations.is_active：每用户至多一行为 1，实现全系统同一个对话。
-- ============================================================

USE worklog;

-- ---------- 1. 共享对话：当前活动对话 ----------
ALTER TABLE ai_conversations
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0 AFTER title,
    ADD KEY idx_ai_conv_active (user_id, is_active);

-- ---------- 2. 附件：内嵌图片 cid ----------
ALTER TABLE attachments ADD COLUMN content_id VARCHAR(255) NOT NULL DEFAULT '' AFTER mime;

-- ---------- 3. 邮箱账户 ----------
CREATE TABLE IF NOT EXISTS mail_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL DEFAULT '',
    email VARCHAR(255) NOT NULL,
    protocol VARCHAR(16) NOT NULL DEFAULT 'imap',       -- imap | graph(future) | ews(future)
    imap_host VARCHAR(255) NOT NULL DEFAULT '',
    imap_port INT NOT NULL DEFAULT 993,
    imap_ssl VARCHAR(8) NOT NULL DEFAULT 'ssl',         -- ssl | tls | none
    smtp_host VARCHAR(255) NOT NULL DEFAULT '',
    smtp_port INT NOT NULL DEFAULT 465,
    smtp_ssl VARCHAR(8) NOT NULL DEFAULT 'ssl',         -- ssl | tls | none
    username VARCHAR(255) NOT NULL DEFAULT '',
    password_enc TEXT,                                  -- base64(nonce || sodium secretbox)
    validate_cert TINYINT(1) NOT NULL DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sync_all_folders TINYINT(1) NOT NULL DEFAULT 1,
    last_sync_at DATETIME NULL,
    last_error TEXT,
    sort INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mail_account (user_id, email),
    KEY idx_mail_accounts_user (user_id),
    CONSTRAINT fk_mail_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 4. 邮件文件夹 ----------
CREATE TABLE IF NOT EXISTS mail_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT NOT NULL,
    path VARCHAR(255) NOT NULL,                         -- raw IMAP path (modified UTF-7)
    display_name VARCHAR(255) NOT NULL DEFAULT '',      -- UTF-8 decoded
    delimiter VARCHAR(4) NOT NULL DEFAULT '/',
    kind VARCHAR(16) NOT NULL DEFAULT 'other',          -- inbox|sent|drafts|trash|junk|archive|other
    uidvalidity BIGINT NOT NULL DEFAULT 0,
    last_uid BIGINT NOT NULL DEFAULT 0,                 -- highest UID synced (forward watermark)
    backfill_uid BIGINT NOT NULL DEFAULT 0,             -- lowest UID backfilled (0 = not started)
    backfill_done TINYINT(1) NOT NULL DEFAULT 0,
    message_count INT NOT NULL DEFAULT 0,
    unread_count INT NOT NULL DEFAULT 0,
    last_synced_at DATETIME NULL,
    flags_synced_at DATETIME NULL,
    deleted_checked_at DATETIME NULL,
    sort INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mail_folder (account_id, path),
    KEY idx_mail_folders_user (user_id),
    CONSTRAINT fk_mail_folders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_folders_account FOREIGN KEY (account_id) REFERENCES mail_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 5. 邮件 ----------
CREATE TABLE IF NOT EXISTS mail_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT NOT NULL,
    folder_id INT NOT NULL,
    uid BIGINT NOT NULL,
    message_id VARCHAR(255) NOT NULL DEFAULT '',
    in_reply_to VARCHAR(255) NOT NULL DEFAULT '',
    references_txt TEXT,
    from_name VARCHAR(255) NOT NULL DEFAULT '',
    from_email VARCHAR(255) NOT NULL DEFAULT '',
    to_json LONGTEXT,                                   -- [{name,email}]
    cc_json LONGTEXT,
    subject VARCHAR(998) NOT NULL DEFAULT '',
    msg_date DATETIME NULL,                             -- local time (TIMEZONE)
    size INT NOT NULL DEFAULT 0,
    is_seen TINYINT(1) NOT NULL DEFAULT 0,
    is_flagged TINYINT(1) NOT NULL DEFAULT 0,
    is_answered TINYINT(1) NOT NULL DEFAULT 0,
    has_attachments TINYINT(1) NOT NULL DEFAULT 0,
    snippet VARCHAR(300) NOT NULL DEFAULT '',
    body_text LONGTEXT,
    body_html LONGTEXT,                                 -- sanitized server-side
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mail_msg_uid (folder_id, uid),
    KEY idx_mail_msg_user_date (user_id, msg_date),
    KEY idx_mail_msg_account_mid (account_id, message_id(191)),
    KEY idx_mail_msg_folder_date (folder_id, msg_date),
    CONSTRAINT fk_mail_msg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_msg_account FOREIGN KEY (account_id) REFERENCES mail_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_msg_folder FOREIGN KEY (folder_id) REFERENCES mail_folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 6. 邮件 AI 分析 ----------
CREATE TABLE IF NOT EXISTS mail_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message_id INT NOT NULL,                            -- mail_messages.id
    brief_title VARCHAR(200) NOT NULL DEFAULT '',
    summary TEXT,
    priority TINYINT NOT NULL DEFAULT 3,                -- 1 highest … 5 lowest
    relevance TINYINT NOT NULL DEFAULT 0,               -- 0-100
    category VARCHAR(32) NOT NULL DEFAULT 'other',      -- work|personal|notification|marketing|spam|other
    needs_reply TINYINT(1) NOT NULL DEFAULT 0,
    deadline_hint VARCHAR(100) NOT NULL DEFAULT '',
    detailed_md LONGTEXT,
    actions_json LONGTEXT,
    status VARCHAR(8) NOT NULL DEFAULT 'ok',            -- ok | error
    error TEXT,
    model VARCHAR(128) DEFAULT '',
    analyzed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mail_analysis_msg (message_id),
    KEY idx_mail_analysis_user (user_id),
    CONSTRAINT fk_mail_analysis_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_analysis_msg FOREIGN KEY (message_id) REFERENCES mail_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 7. 侧写：身份文档（权威来源） ----------
CREATE TABLE IF NOT EXISTS profile_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    kind VARCHAR(16) NOT NULL DEFAULT 'cv',             -- cv | other
    title VARCHAR(200) NOT NULL DEFAULT '',
    file_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,                  -- uploads/profile_docs/
    mime VARCHAR(128) DEFAULT '',
    size INT DEFAULT 0,
    extracted_text LONGTEXT,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_profile_docs_user (user_id),
    CONSTRAINT fk_profile_docs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 8. 侧写：AI 印象（次级来源） ----------
CREATE TABLE IF NOT EXISTS profile_impressions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    field VARCHAR(64) NOT NULL,                         -- title|position|organization|work_focus|research_area|skills|projects|preferences|observation
    value TEXT NOT NULL,
    confidence TINYINT NOT NULL DEFAULT 70,             -- 0-100
    source VARCHAR(8) NOT NULL DEFAULT 'ai',            -- ai | user
    conversation_id INT NULL,
    evidence VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_impressions_user_field (user_id, field),
    CONSTRAINT fk_impressions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 9. 自检（应返回 6） ----------
SELECT COUNT(*) AS new_tables_ok FROM information_schema.tables
 WHERE table_schema = 'worklog'
   AND table_name IN ('mail_accounts','mail_folders','mail_messages','mail_analysis','profile_documents','profile_impressions');
