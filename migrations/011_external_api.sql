-- Read-only external AI access. No credentials are created by this migration.
CREATE TABLE IF NOT EXISTS external_api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_prefix VARCHAR(16) NOT NULL,
    scopes_json TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    last_used_at DATETIME NULL,
    window_at DATETIME NULL,
    request_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_external_token_hash (token_hash),
    KEY idx_external_tokens_user (user_id),
    CONSTRAINT fk_external_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
