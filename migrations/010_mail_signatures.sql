-- Per-account plain-text signatures. Existing accounts start with an empty signature.
ALTER TABLE mail_accounts
    ADD COLUMN signature_text TEXT NULL AFTER username,
    ADD COLUMN signature_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER signature_text;
